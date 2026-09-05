<?php

declare(strict_types=1);

use LorkhanServer\Application\MorrowindVoiceCatalog;
use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\ProductRepository;

require dirname(__DIR__).'/lib/Autoload.php';

if(PHP_SAPI!=='cli'||count($argv)!==2){fwrite(STDERR,"Usage: php scripts/import-morrowind-voices.php <Morrowind Data Files>\n");exit(2);}
$gameRoot=realpath($argv[1]);
if(!is_string($gameRoot)||!is_file($gameRoot.'/Morrowind.esm')||!is_dir($gameRoot.'/Sound/Vo')){
    fwrite(STDERR,"The supplied directory is not a Morrowind Data Files installation.\n");exit(2);
}
$configFile=getenv('LORKHAN_CONFIG')?:dirname(__DIR__).'/conf/server.php';
if(!is_file($configFile))throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
$config=require$configFile;if(!is_array($config))throw new RuntimeException('Server configuration is invalid.');
$config['database_password']=getenv('LORKHAN_DATABASE_PASSWORD')?:(string)($config['database_password']??'');
$db=Connection::open($config);$products=new ProductRepository($db);$catalog=MorrowindVoiceCatalog::bundled();
$voiceRoot=(string)($config['voice_storage_path']??'/var/lib/lorkhanserver/voices');
if(!is_dir($voiceRoot)&&!mkdir($voiceRoot,0750,true)&&!is_dir($voiceRoot))throw new RuntimeException('Voice storage is unavailable.');

/** Convert one catalog-approved local game sample to the bounded PCM WAV format used by TTS Studio. */
function lorkhan_import_voice_wav(string $source,string $destination):void
{
    $temp=tempnam(dirname($destination),'.morrowind-voice-');if($temp===false)throw new RuntimeException('voice_import_failed');
    $pipes=[];$process=proc_open(['ffmpeg','-nostdin','-v','error','-y','-i',$source,'-vn','-ac','1','-ar','24000','-c:a','pcm_s16le','-f','wav',$temp],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)){@unlink($temp);throw new RuntimeException('voice_import_failed');}
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $status=proc_close($process);$size=is_file($temp)?filesize($temp):false;$header=is_file($temp)?file_get_contents($temp,false,null,0,12):false;
    if($status!==0||!is_int($size)||$size<44||$size>16_777_216||!is_string($header)
        ||substr($header,0,4)!=='RIFF'||substr($header,8,4)!=='WAVE'){
        @unlink($temp);throw new RuntimeException('voice_import_failed: '.substr(trim((string)$stderr.(string)$stdout),0,256));
    }
    if(!rename($temp,$destination)){@unlink($temp);throw new RuntimeException('voice_import_failed');}
    @chmod($destination,0640);@chown($destination,'www-data');@chgrp($destination,'www-data');
}

/** Synchronize one approved WAV with the active local cloning connector without exposing an HTTP route. */
function lorkhan_sync_imported_voice(array $connector,array $voice,string $wav):void
{
    $content=is_array($connector['content']??null)?$connector['content']:[];$driver=(string)($content['driver']??'');
    if(!in_array($driver,['omnivoice','chatterbox','xtts-fastapi','xtts','pockettts'],true))throw new RuntimeException('voice_sync_unsupported');
    $endpoint=rtrim((string)($content['endpoint']??''),'/');$parts=parse_url($endpoint);$host=strtolower((string)($parts['host']??''));
    if(!in_array($host,['127.0.0.1','::1'],true))throw new RuntimeException('voice_sync_unsupported');
    if($driver==='pockettts'&&(str_contains($endpoint,':8086')||str_ends_with($endpoint,'/v1/audio/speech'))){
        if(!is_readable($wav))throw new RuntimeException('voice_sync_failed');
        return;
    }
    $handle=curl_init($endpoint.'/upload_sample');if($handle===false)throw new RuntimeException('voice_sync_failed');
    $fields=['wavFile'=>new CURLFile($wav,'audio/wav',basename($wav)),'force'=>'true','language'=>'en',
        'speaker_name'=>$voice['voice_id'],'display_name'=>$voice['display_name'],'reference_text'=>$voice['reference_text']];
    curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_TIMEOUT_MS=>120000,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    try{$response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        if(!is_string($response)||$status<200||$status>=300||strlen($response)>1_048_576)throw new RuntimeException('voice_sync_failed');
        if($driver==='omnivoice'){$decoded=json_decode($response,true,16,JSON_THROW_ON_ERROR);$providerStatus=strtolower(trim((string)($decoded['import_status']??$decoded['status']??'')));
            if(!in_array($providerStatus,['runtime_ready','ready','ok'],true))throw new RuntimeException('voice_sync_failed');}
    }finally{curl_close($handle);}
}

$connectors=$db->query("SELECT c.configuration_id,c.installation_id,r.content FROM installation_provider_selections s "
    ."JOIN configuration_sets c ON c.configuration_id=s.configuration_id AND c.installation_id=s.installation_id "
    ."JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision "
    ."WHERE s.provider_kind='tts_provider' AND c.kind='tts_provider' AND c.deleted_at IS NULL ORDER BY c.installation_id")->fetchAll();
foreach($connectors as&$connector)$connector['content']=json_decode((string)$connector['content'],true,16,JSON_THROW_ON_ERROR);unset($connector);
$converted=0;$synced=0;$skipped=0;$now=gmdate('Y-m-d\TH:i:s\Z');
$catalogLookup=$db->prepare('SELECT 1 FROM speech_connector_voices WHERE configuration_id=:configuration AND voice_id=:voice');
$catalogUpsert=$db->prepare('INSERT INTO speech_connector_voices(configuration_id,voice_id,display_name,language,provider_status,custom_voice,discovered_at) '
    ."VALUES(:configuration,:voice,:display,'en','runtime_ready',true,:now) ON CONFLICT(configuration_id,voice_id) DO UPDATE SET "
    .'display_name=EXCLUDED.display_name,language=EXCLUDED.language,provider_status=EXCLUDED.provider_status,custom_voice=true,discovered_at=EXCLUDED.discovered_at');
foreach($catalog->voices()as$voice){
    $relative=str_replace('/',DIRECTORY_SEPARATOR,(string)$voice['sample_path']);$source=realpath($gameRoot.DIRECTORY_SEPARATOR.$relative);
    if(!is_string($source)||!str_starts_with($source,$gameRoot.DIRECTORY_SEPARATOR))throw new RuntimeException('catalog_sample_missing: '.$voice['sample_path']);
    $destination=$voiceRoot.DIRECTORY_SEPARATOR.$voice['voice_id'].'.wav';
    if(!is_file($destination)){lorkhan_import_voice_wav($source,$destination);$converted++;}
    foreach($connectors as$connector){$catalogLookup->execute(['configuration'=>$connector['configuration_id'],'voice'=>$voice['voice_id']]);
        if($catalogLookup->fetchColumn()){$skipped++;continue;}
        try{lorkhan_sync_imported_voice($connector,$voice,$destination);}catch(RuntimeException$error){
            if($error->getMessage()==='voice_sync_unsupported'){$skipped++;continue;}throw$error;}
        $catalogUpsert->execute(['configuration'=>$connector['configuration_id'],'voice'=>$voice['voice_id'],
            'display'=>$voice['display_name'],'now'=>$now]);$synced++;}
}
$backfill=$products->backfillMorrowindCatalogVoices($catalog,$now);
echo json_encode(['schema'=>'lorkhan.morrowind-voice-import.v1','catalog_voices'=>count($catalog->voices()),
    'converted'=>$converted,'synced'=>$synced,'skipped'=>$skipped,'profiles'=>$backfill],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
