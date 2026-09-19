#!/usr/bin/env php
<?php
declare(strict_types=1);
use LorkhanServer\Application\{CredentialStore,VoiceDesignReview,OpenAiCompatibleSpeechProvider};
use LorkhanServer\Infrastructure\{Connection,ProductRepository};
require dirname(__DIR__).'/lib/Autoload.php';
if(PHP_SAPI!=='cli')exit(1);
try{
    $config=require getenv('LORKHAN_CONFIG')?:dirname(__DIR__).'/conf/server.php';
    $db=Connection::open($config);
    $installation=$db->query('SELECT installation_id FROM sessions ORDER BY generation DESC LIMIT 1')->fetchColumn();
    if(!$installation)throw new RuntimeException('No paired installation.');
    $connector=(new ProductRepository($db))->connectorForInstallation($installation,'tts_provider');
    if(($connector['content']['driver']??'')!=='inworld')throw new RuntimeException('Selected TTS connector is not Inworld.');
    $key=(new CredentialStore($config['credential_storage_path']??'/var/lib/lorkhanserver/credentials/provider-keys.json'))
        ->resolve($connector['content']['credential']??'LORKHAN_TTS_INWORLD_API_KEY');
    if($key==='')throw new RuntimeException('Inworld credential is missing.');
    $root=($config['voice_storage_path']??'/var/lib/lorkhanserver/voices').'/design-review';
    if(!is_dir($root)&&!mkdir($root,0770,true))throw new RuntimeException('Review directory unavailable.');
    $lock=fopen($root.'/generation.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Generation already running.');
    $review=new VoiceDesignReview($root);$saved=$review->document();
    $source=json_decode((string)file_get_contents(dirname(__DIR__).'/data/voices/morrowind-design-review.json'),true,32,JSON_THROW_ON_ERROR);
    $refresh=in_array('--refresh',$argv,true);
    $limit=isset($argv[1])&&ctype_digit($argv[1])?(int)$argv[1]:count($source['characters']);$completed=0;
    if($refresh){
        // Preserve previews and decisions before replacing only changed creative directions.
        VoiceDesignReview::writeJson($root.'/archive-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json',
            ['document'=>$saved,'decisions'=>$review->decisions()]);
        $keys=array_column($source['characters'],'key');
        $saved['characters']=array_values(array_filter($saved['characters'],static fn(array $row):bool=>in_array($row['key'],$keys,true)));
        VoiceDesignReview::writeJson($root.'/candidates.json',$saved);
    }
    foreach($source['characters'] as $character){
        $existing=null;foreach($saved['characters'] as $index=>$row)if($row['key']===$character['key'])$existing=$index;
        if($existing!==null&&(!$refresh||($saved['characters'][$existing]['design_prompt']===$character['design_prompt']
            &&$saved['characters'][$existing]['preview_text']===$character['preview_text'])))continue;
        if($completed++ >= $limit)break;
        echo 'Generating '.$character['name']." (2 candidates)...\n";
        if($completed>1)sleep(25);
        for($attempt=0;$attempt<3;$attempt++){
        $handle=curl_init('https://api.inworld.ai/voices/v1/voices:design');$response='';
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Basic '.$key],
            CURLOPT_POSTFIELDS=>json_encode(['designPrompt'=>$character['design_prompt'],'previewText'=>$character['preview_text'],
                'languageCode'=>'en-US','voiceDesignConfig'=>['numberOfSamples'=>2]],JSON_THROW_ON_ERROR),
            CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>32_000_000)return 0;$response.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($handle);$status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        if($status!==429||$attempt===2)break;
        echo "Rate limited; waiting 45 seconds before retrying this unaccepted request.\n";sleep(45);
        }
        if($ok===false||$status!==200)throw new RuntimeException('Inworld design failed (HTTP '.$status.'); completed candidates retained.');
        $body=json_decode($response,true,32,JSON_THROW_ON_ERROR);$previews=$body['previewVoices']??[];
        if(count($previews)!==2)throw new RuntimeException('Unexpected preview count; no automatic retry.');
        $character['candidates']=[];
        foreach($previews as $index=>$preview){
            $audio=base64_decode($preview['previewAudio']??'',true);$voice=$preview['voiceId']??'';
            if(!is_string($audio)||$voice===''||strlen($audio)>16_777_216)throw new RuntimeException('Invalid preview response.');
            $duration=OpenAiCompatibleSpeechProvider::wavDurationMs($audio);
            if($duration<1000)throw new RuntimeException('Preview audio is too short.');
            $id=bin2hex(random_bytes(16));
            if(file_put_contents($root.'/'.$id.'.wav',$audio)===false)throw new RuntimeException('Preview storage failed.');
            chmod($root.'/'.$id.'.wav',0660);
            $character['candidates'][]=['id'=>$id,'label'=>$index===0?'A':'B','voice_id'=>$voice,
                'duration_ms'=>$duration,'sha256'=>hash('sha256',$audio)];
        }
        $character['generated_at']=gmdate('c');
        if($existing===null)$saved['characters'][]=$character;else $saved['characters'][$existing]=$character;
        $saved['generated_at']=gmdate('c');$saved['provider']='inworld';
        $saved['configuration_id']=$connector['configuration_id'];
        VoiceDesignReview::writeJson($root.'/candidates.json',$saved);
        echo 'Saved '.$character['name']."\n";
    }
    echo count($saved['characters'])." characters available for review. No voices published or assigned.\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
