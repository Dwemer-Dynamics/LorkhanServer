#!/usr/bin/env php
<?php
declare(strict_types=1);
use LorkhanServer\Application\{CloudVoiceLibrary,CredentialStore,VoiceDesignReview,OpenAiCompatibleSpeechProvider};
use LorkhanServer\Infrastructure\{Connection,ProductRepository,ProfileScopeSql};
require dirname(__DIR__).'/lib/Autoload.php';
if(PHP_SAPI!=='cli')exit(1);
try{
    $apply=in_array('--apply',$argv,true);
    $publishedOnly=in_array('--published-only',$argv,true);
    $config=require getenv('LORKHAN_CONFIG')?:dirname(__DIR__).'/conf/server.php';
    $db=Connection::open($config);$products=new ProductRepository($db);
    $installation=$db->query('SELECT installation_id FROM sessions ORDER BY generation DESC LIMIT 1')->fetchColumn();
    if(!$installation)throw new RuntimeException('No paired installation.');
    $connector=$products->connectorForInstallation($installation,'tts_provider');
    if(($connector['content']['driver']??'')!=='inworld')throw new RuntimeException('Select Inworld first.');
    $credential=$connector['content']['credential']??'LORKHAN_TTS_INWORLD_API_KEY';
    $key=(new CredentialStore($config['credential_storage_path']))->resolve($credential);
    if($key==='')throw new RuntimeException('Missing Inworld credential.');
    $workspace=CloudVoiceLibrary::normalizeWorkspace($connector['content']['options']['workspace']??'');
    $root=$config['voice_storage_path'];$review=new VoiceDesignReview($root.'/design-review');
    $locks=[];
    foreach(['generation','decisions','application'] as $name){
        $lock=fopen($review->root.'/'.$name.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Review busy; retry later.');
        $locks[]=$lock;
    }
    $document=$review->document();$decisions=$review->decisions();$selected=[];
    if(($document['configuration_id']??null)!==$connector['configuration_id'])throw new RuntimeException('Review connector changed.');
    foreach($document['characters'] as $row){
        $decision=$decisions[$row['key']]??[];$chosen=null;
        foreach($row['candidates'] as $candidate)if($candidate['id']===($decision['candidate']??''))$chosen=$candidate;
        if(!$chosen||($decision['updated_at']??'')<($row['generated_at']??''))throw new RuntimeException('Missing current approval: '.$row['name']);
        if(!preg_match('/^[a-f0-9]{32}$/D',$chosen['id'])||!preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$chosen['voice_id']))throw new RuntimeException('Invalid candidate ID.');
        $bytes=file_get_contents($review->root.'/'.$chosen['id'].'.wav');
        if(!hash_equals($chosen['sha256'],hash('sha256',$bytes))||OpenAiCompatibleSpeechProvider::wavDurationMs($bytes)<1000)throw new RuntimeException('Invalid saved preview.');
        $row['selected']=$chosen;$row['sample']='lorkhan_'.str_replace('-','_',$row['key']).'_'.substr($chosen['id'],0,8);$selected[]=$row;
        echo $row['name'].' -> approved '.$chosen['label'].'; '.count($row['record_ids'])." actor records\n";
    }
    if(!$apply){echo "Dry run only. Pass --apply to publish, retain samples, and assign biographies/current NPCs.\n";exit;}
    $journalPath=$review->root.'/published.json';
    $journal=is_file($journalPath)?json_decode(file_get_contents($journalPath),true,32,JSON_THROW_ON_ERROR):[];
    foreach($selected as &$row){
        $candidate=$row['selected'];$id=$candidate['id'];
        $scope=hash_hmac('sha256',$workspace,$key);
        if(isset($journal[$id])&&($journal[$id]['account']??'')!==$scope)throw new RuntimeException('Publication account changed.');
        $sample=$root.'/'.$row['sample'].'.wav';
        if(is_file($sample)&&!hash_equals($candidate['sha256'],hash_file('sha256',$sample)))throw new RuntimeException('Existing sample differs.');
        if(!is_file($sample)&&!copy($review->root.'/'.$id.'.wav',$sample))throw new RuntimeException('Sample copy failed.');
        chmod($sample,0660);
        VoiceDesignReview::writeJson($root.'/'.$row['sample'].'.json',['reference_text'=>$row['preview_text'],'sha256'=>$candidate['sha256'],'character'=>$row['name']]);
        if($publishedOnly&&($journal[$id]['state']??'')!=='published'){
            echo 'Saved sample; assignment deferred for '.$row['name']."\n";continue;
        }
        if(($journal[$id]['state']??'')==='pending')throw new RuntimeException('Uncertain prior publication; verify provider before retrying: '.$row['name']);
        if(($journal[$id]['state']??'')!=='published'){
            $journal[$id]=['state'=>'pending','account'=>$scope,'draft'=>$candidate['voice_id']];VoiceDesignReview::writeJson($journalPath,$journal);
            $h=curl_init('https://api.inworld.ai/voices/v1/voices/'.rawurlencode($candidate['voice_id']).':publish');
            curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Basic '.$key],
                CURLOPT_POSTFIELDS=>json_encode(['displayName'=>'LORKHAN '.$row['name'],'description'=>$row['character'],'tags'=>['lorkhan','morrowind']],JSON_THROW_ON_ERROR)]);
            $response=curl_exec($h);$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
            if($response===false||$status!==200){
                // Definite client-side rejection can be retried explicitly after its cause is fixed.
                if($response!==false&&$status>=400&&$status<500&&$status!==408){
                    $journal[$id]['state']='rejected';$journal[$id]['http_status']=$status;
                    VoiceDesignReview::writeJson($journalPath,$journal);
                }
                throw new RuntimeException('Publication failed (HTTP '.$status.'): '.$row['name']);
            }
            $body=json_decode($response,true,32,JSON_THROW_ON_ERROR);$voice=$body['voiceId']??'';
            if(!preg_match('/^[a-zA-Z0-9_.+-]{1,512}$/D',$voice))throw new RuntimeException('Invalid published voice.');
            $journal[$id]=['state'=>'published','account'=>$scope,'voice_id'=>$voice,'sample'=>$row['sample'],'published_at'=>gmdate('c')];
            VoiceDesignReview::writeJson($journalPath,$journal);echo 'Published '.$row['name']."\n";
        }
        $row['voice_id']=$journal[$id]['voice_id'];
        $cache=$root.'/.inworld-cache';if(!is_dir($cache)&&!mkdir($cache,02770))throw new RuntimeException('Cache unavailable.');
        $cacheId=hash_hmac('sha256',($workspace===''?'':$workspace."\n").$row['sample'],$key);
        $lock=fopen($cache.'/'.$cacheId.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Voice cache busy.');
        try{VoiceDesignReview::writeJson($cache.'/'.$cacheId.'.json',['voice_id'=>$row['voice_id'],'managed'=>false]);}
        finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    unset($row);
    $selected=array_values(array_filter($selected,static fn(array $row):bool=>isset($row['voice_id'])));
    $db->beginTransaction();$backup=['created_at'=>gmdate('c'),'biographies'=>[],'profiles'=>[]];$bios=0;$profiles=0;
    foreach($selected as $row)foreach($row['record_ids'] as $record){
        $q=$db->prepare('SELECT * FROM public.combined_bio_templates WHERE lower(refid)=lower(?)');$q->execute([$record]);$existing=$q->fetchAll();
        if(count($existing)>1)throw new RuntimeException('Ambiguous biography: '.$record);
        $bio=$existing[0]??['npc_name'=>$record,'refid'=>$record,'relationships'=>'{}'];
        $custom=$db->prepare('SELECT * FROM public.bio_templates_custom WHERE npc_name=? FOR UPDATE');$custom->execute([$bio['npc_name']]);
        $backup['biographies'][]=['npc_name'=>$bio['npc_name'],'previous'=>$custom->fetch()?:null];
        $bio['voiceid']=$row['sample'];$bio['installation_id']=$installation;
        $products->saveBiographyTemplate($bio,true);$bios++;
        $q=$db->prepare("SELECT p.profile_id FROM profiles p WHERE p.installation_id=? AND p.deleted_at IS NULL AND lower(p.actor_identity->>'record_id')=lower(?) AND ".ProfileScopeSql::current('p').' FOR UPDATE');
        $q->execute([$installation,$record]);
        foreach($q->fetchAll() as $p){
            $profile=$products->getRevisioned('profile',$p['profile_id']);$content=$profile['content'];$backup['profiles'][]=$profile;
            if(($content['voice']['id']??'')===$row['sample'])continue;
            $content['voice']=['id'=>$row['sample'],'sample'=>$row['sample'],'language'=>'en','source'=>'approved_design'];
            $products->revise('profile',$p['profile_id'],$content,'approved Inworld character voice',gmdate('c'),(int)$profile['current_revision']);$profiles++;
        }
    }
    VoiceDesignReview::writeJson($review->root.'/assignment-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json',$backup);
    $db->commit();echo "Applied $bios biography entries and $profiles current NPC profiles. Samples retained in voice storage.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,$e->getMessage()."\n");exit(1);}
