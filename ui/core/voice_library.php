<?php

declare(strict_types=1);

use LorkhanServer\Application\ConnectorCatalog;
use LorkhanServer\Application\SpeechPreviewCatalog;
use LorkhanServer\Application\CloudVoiceLibrary;
use LorkhanServer\Application\CredentialStore;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\TtsPronunciationRepository;
use LorkhanServer\Security\OutboundUrlPolicy;

$uiRootDir=dirname(__DIR__);$pageTitle='Voice Management';$topNavSection='configuration';
$embedded=($_GET['embed']??'')==='1';
$BODY_CLASS='hub-page tts-studio-page-shell'.($embedded?' embedded-page':'');
require $uiRootDir.'/ui_bootstrap.php';

$requestedStudioTab=(string)($_GET['tab']??$_POST['studio_tab']??'');

$voiceRoot=(string)($config['voice_storage_path']??'/var/lib/lorkhanserver/voices');
if(!is_dir($voiceRoot)&&!mkdir($voiceRoot,0750,true)&&!is_dir($voiceRoot))throw new RuntimeException('Voice storage is unavailable.');
$products=new ProductRepository($database);$installations=$uiRepository->rows('installations');
$installationId=(string)($installations[0]['installation_id']??'');
$activeTts=$installationId===''?null:$products->connectorForInstallation($installationId,'tts_provider');
$ttsPresets=array_values(array_filter($uiRepository->rows('tts'),static fn(array$row):bool=>$installationId!==''&&($row['installation_id']??'')===$installationId));
$ttsPresetsById=[];foreach($ttsPresets as$preset){$id=(string)($preset['configuration_id']??'');if($id!=='')$ttsPresetsById[$id]=$preset;}
$requestedConfigurationId=(string)($_GET['configuration_id']??$_POST['configuration_id']??'');$requestedPreset=$ttsPresetsById[$requestedConfigurationId]??null;
$defaultPreset=is_array($requestedPreset)?$requestedPreset:(is_array($activeTts)?$activeTts:[]);$defaultDriver=(string)($defaultPreset['content']['driver']??'');
$defaultTab=match($defaultDriver){'pockettts'=>'pockettts','omnivoice'=>'omnivoice','chatterbox'=>'chatterbox','cartesia'=>'cartesia','inworld'=>'inworld','xtts-fastapi','xtts'=>'xtts',default=>'xtts'};
$studioTab=$requestedStudioTab!==''?$requestedStudioTab:$defaultTab;
$activeTab=in_array($studioTab,['xtts','chatterbox','pockettts','omnivoice','cartesia','inworld','fallbacks','pronunciations'],true)?$studioTab:$defaultTab;
$voiceReferenceIndex=$products->voiceReferenceIndex();
$sampleUploadDrivers=array_merge(ConnectorCatalog::SAMPLE_LIBRARY_TTS_DRIVERS,['cartesia','inworld']);
$voiceDiscoveryDrivers=$sampleUploadDrivers;
$cloudLibrary=new CloudVoiceLibrary(new CredentialStore((string)$config['credential_storage_path']));
$notice=($_GET['status']??'')==='saved'?'Connector default voice saved.':'';$error='';$errorReferences=[];$discoveredVoices=[];$discoveredPreset=null;$discoverLanguage='en';$catalogLoaded=false;$selectedDiscoveryId='';
$pronunciations=new TtsPronunciationRepository($database);$pronunciationEntries=[];$pronunciationNotice='';$pronunciationError='';
// The Pronunciations tab narrows its editable list by one Oghma tag read straight from the URL.
$pronunciationFilter=trim((string)($_GET['oghma_tag']??''));
if($pronunciationFilter!==''&&(!mb_check_encoding($pronunciationFilter,'UTF-8')||mb_strlen($pronunciationFilter,'UTF-8')>64))$pronunciationFilter='';

/** Validate a user-facing voice name and map it to one bounded local WAV filename. */
function lorkhan_voice_filename(string $name):string
{
    $name=trim($name);if($name===''||strlen($name)>80||preg_match('/^[\pL\pN][\pL\pN _+.-]*$/uD',$name)!==1)throw new InvalidArgumentException('invalid_voice_name');
    return preg_replace('/\s+/u','_',$name).'.wav';
}

/** Require a PCM-compatible RIFF/WAVE upload before it enters the persistent voice library. */
function lorkhan_voice_validate_wav(string $path):void
{
    $size=filesize($path);$header=file_get_contents($path,false,null,0,12);
    if(!is_int($size)||$size<44||$size>16_777_216||$header===false||substr($header,0,4)!=='RIFF'||substr($header,8,4)!=='WAVE')
        throw new InvalidArgumentException('invalid_voice_sample');
}

/** List active profile and connector references that make a local sample unsafe to delete. */
function lorkhan_voice_references(string $voice,array $referenceIndex):array
{
    return$referenceIndex[mb_strtolower(trim($voice),'UTF-8')]??[];
}

/** Normalize the short language identifiers accepted by compatible local voice services. */
function lorkhan_voice_language(string $language):string
{
    $language=strtolower(trim($language));
    if($language===''||preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$language)!==1)
        throw new InvalidArgumentException('invalid_voice_language');
    return$language;
}

/** Exclude audio.cpp PocketTTS because its local voice directory has no upload endpoint. */
function lorkhan_voice_can_sync(array $preset):bool
{
    $content=is_array($preset['content']??null)?$preset['content']:[];$driver=(string)($content['driver']??'');
    if(in_array($driver,['cartesia','inworld'],true))return true;
    if(!in_array($driver,ConnectorCatalog::SAMPLE_LIBRARY_TTS_DRIVERS,true))return false;
    $endpoint=strtolower(rtrim((string)($content['endpoint']??''),'/'));
    return$driver!=='pockettts'||(!str_contains($endpoint,':8086')&&!str_ends_with($endpoint,'/v1/audio/speech'));
}

/** Fetch one bounded JSON document from an explicitly configured local voice service. */
function lorkhan_voice_fetch_json(string $endpoint,string $path):array
{
    $endpoint=rtrim($endpoint,'/');$parts=parse_url($endpoint);$host=is_array($parts)?strtolower((string)($parts['host']??'')):'';
    if($host==='')throw new InvalidArgumentException('voice_discovery_unsupported');
    $url=OutboundUrlPolicy::validate($endpoint.$path,[$host],true);$handle=curl_init($url);
    if($handle===false)throw new RuntimeException('voice_discovery_unavailable');
    curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>5000,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    try{$response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        if(!is_string($response)||$status<200||$status>=300||strlen($response)>1_048_576)throw new RuntimeException('voice_discovery_failed');
    }finally{curl_close($handle);}
    try{$decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('voice_discovery_failed');}
    if(!is_array($decoded))throw new RuntimeException('voice_discovery_failed');return$decoded;
}

/** Normalize XTTS-family and OmniVoice speaker payloads into safe voice cards. */
function lorkhan_voice_normalize_discovery(array $payload,string $fallbackLanguage):array
{
    if(isset($payload['speakers'])&&is_array($payload['speakers']))$payload=$payload['speakers'];
    elseif(!array_is_list($payload)){
        $flattened=[];foreach($payload as$value)if(is_array($value)&&isset($value['speakers'])&&is_array($value['speakers']))$flattened=array_merge($flattened,$value['speakers']);
        if($flattened!==[])$payload=$flattened;
    }
    $voices=[];foreach(array_slice($payload,0,512)as$item){
        if(is_string($item)){$id=trim($item);$display=$id;$language=$fallbackLanguage;$status='available';$custom=false;}
        elseif(is_array($item)){$id=trim((string)($item['voice_id']??$item['speaker']??$item['id']??$item['name']??''));
            $display=trim((string)($item['display_name']??$id));$language=trim((string)($item['language']??$fallbackLanguage));
            $status=trim((string)($item['status']??'available'));$custom=($item['custom_voice']??false)===true;}
        else continue;
        if($id===''||strlen($id)>512||!mb_check_encoding($id,'UTF-8'))continue;
        if($display===''||strlen($display)>512||!mb_check_encoding($display,'UTF-8'))$display=$id;
        try{$language=lorkhan_voice_language($language);}catch(InvalidArgumentException){$language=$fallbackLanguage;}
        if($status===''||strlen($status)>64||!mb_check_encoding($status,'UTF-8'))$status='available';
        $voices[$id]=['id'=>$id,'display'=>$display===''?$id:$display,'language'=>$language===''?$fallbackLanguage:$language,
            'status'=>$status===''?'available':$status,'custom'=>$custom];
    }
    $voices=array_values($voices);usort($voices,static fn(array$a,array$b):int=>strcasecmp($a['display'],$b['display']));return$voices;
}

/** Query only CHIM-compatible local speaker-list endpoints after an explicit browser action. */
function lorkhan_voice_discover(array $preset,string $language,?CloudVoiceLibrary $cloud=null):array
{
    $content=is_array($preset['content']??null)?$preset['content']:[];$driver=(string)($content['driver']??'');
    if(!lorkhan_voice_can_sync($preset))throw new InvalidArgumentException('voice_discovery_unsupported');
    $language=lorkhan_voice_language($language);
    if(in_array($driver,['cartesia','inworld'],true))return lorkhan_voice_normalize_discovery(
        ($cloud??throw new RuntimeException('voice_sync_unavailable'))->discover($driver),$language);
    $path=$driver==='omnivoice'?'/speakers_list_extended?language='.rawurlencode($language):'/speakers_list';
    return lorkhan_voice_normalize_discovery(lorkhan_voice_fetch_json((string)($content['endpoint']??''),$path),$language);
}

/** Import a bounded flat ZIP of WAV files without allowing traversal or partial batches. */
function lorkhan_voice_import_zip(string $archivePath,string $voiceRoot):int
{
    $archiveSize=filesize($archivePath);
    if(!is_int($archiveSize)||$archiveSize<1||$archiveSize>67_108_864||!class_exists(ZipArchive::class))
        throw new InvalidArgumentException('invalid_voice_archive');
    $zip=new ZipArchive();if($zip->open($archivePath)!==true)throw new InvalidArgumentException('invalid_voice_archive');
    $staged=[];$created=[];$seen=[];$totalBytes=0;
    try{
        if($zip->numFiles<1||$zip->numFiles>128)throw new InvalidArgumentException('invalid_voice_archive');
        for($index=0;$index<$zip->numFiles;$index++){
            $stat=$zip->statIndex($index);if(!is_array($stat))throw new InvalidArgumentException('invalid_voice_archive');
            $entry=(string)($stat['name']??'');if($entry===''||str_ends_with($entry,'/'))continue;
            if(str_contains($entry,'\\')||basename($entry)!==$entry||pathinfo($entry,PATHINFO_EXTENSION)==='')
                throw new InvalidArgumentException('invalid_voice_archive');
            if(strtolower(pathinfo($entry,PATHINFO_EXTENSION))!=='wav')continue;
            $filename=lorkhan_voice_filename(pathinfo($entry,PATHINFO_FILENAME));$key=strtolower($filename);
            if(isset($seen[$key])||is_file($voiceRoot.DIRECTORY_SEPARATOR.$filename))throw new InvalidArgumentException('voice_sample_exists');
            $seen[$key]=true;$declared=(int)($stat['size']??0);
            if($declared<44||$declared>16_777_216)throw new InvalidArgumentException('invalid_voice_sample');
            $source=$zip->getStream($entry);$temp=tempnam($voiceRoot,'.voice-import-');
            if(!is_resource($source)||$temp===false){if(is_resource($source))fclose($source);if(is_string($temp))@unlink($temp);throw new RuntimeException('voice_upload_failed');}
            $destination=fopen($temp,'wb');if($destination===false){fclose($source);@unlink($temp);throw new RuntimeException('voice_upload_failed');}
            try{$copied=stream_copy_to_stream($source,$destination,16_777_217);}finally{fclose($source);fclose($destination);}
            if(!is_int($copied)||$copied!==$declared||$copied>16_777_216){@unlink($temp);throw new InvalidArgumentException('invalid_voice_sample');}
            $totalBytes+=$copied;if($totalBytes>134_217_728){@unlink($temp);throw new InvalidArgumentException('invalid_voice_archive');}
            lorkhan_voice_validate_wav($temp);$staged[]=['temp'=>$temp,'path'=>$voiceRoot.DIRECTORY_SEPARATOR.$filename];
            if(count($staged)>64)throw new InvalidArgumentException('invalid_voice_archive');
        }
        if($staged===[])throw new InvalidArgumentException('invalid_voice_archive');
        foreach($staged as$item){if(!rename($item['temp'],$item['path']))throw new RuntimeException('voice_upload_failed');@chmod($item['path'],0640);$created[]=$item['path'];}
        return count($created);
    }catch(Throwable $error){foreach($staged as$item)if(is_file($item['temp']))@unlink($item['temp']);foreach($created as$path)if(is_file($path))@unlink($path);throw$error;}
    finally{$zip->close();}
}

/** Upload one stored sample to a configured local connector using its bounded multipart contract. */
function lorkhan_voice_sync_connector(array $preset,string $path,string $voice,string $language):void
{
    $content=is_array($preset['content']??null)?$preset['content']:[];$driver=(string)($content['driver']??'');
    if(!lorkhan_voice_can_sync($preset))throw new InvalidArgumentException('voice_sync_unsupported');
    $language=lorkhan_voice_language($language);
    $endpoint=rtrim((string)($content['endpoint']??''),'/');$parts=parse_url($endpoint);$host=is_array($parts)?strtolower((string)($parts['host']??'')):'';
    if($host==='')throw new InvalidArgumentException('voice_sync_unsupported');
    $url=OutboundUrlPolicy::validate($endpoint.'/upload_sample',[$host],true);$handle=curl_init($url);
    if($handle===false)throw new RuntimeException('voice_sync_unavailable');
    $fields=['wavFile'=>new CURLFile($path,'audio/wav',basename($path)),'force'=>'true'];
    if($driver==='omnivoice')$fields+=['language'=>$language,'speaker_name'=>$voice,'display_name'=>$voice];
    curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_TIMEOUT_MS=>30_000,
        CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    try{$response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        if(!is_string($response)||strlen($response)>1_048_576)throw new RuntimeException('voice_sync_failed');
        if($driver==='omnivoice'){
            try{$decoded=json_decode($response,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('voice_sync_failed');}
            $providerStatus=is_array($decoded)?strtolower(trim((string)($decoded['import_status']??$decoded['status']??''))):'';
            if($status<200||$status>=300||!in_array($providerStatus,['runtime_ready','ready','ok'],true))throw new RuntimeException('voice_sync_failed');
        }elseif(!(($status>=200&&$status<300)||($status===400&&stripos($response,'already exists')!==false)))throw new RuntimeException('voice_sync_failed');
    }finally{curl_close($handle);}
}

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $postedAction=(string)($_POST['action']??'');$pronunciationAction=str_starts_with($postedAction,'pronunciation_');
    try{
        if(!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new RuntimeException('unauthorized');
        $action=$postedAction;$voice=(string)($_POST['voice_name']??'');
        if($action==='fallback_save'){
            // One matrix serves every TTS connector, so the save writes the global fallback
            // store instead of revising a single connector configuration.
            (new \LorkhanServer\Infrastructure\TtsFallbackRepository($database))
                ->save(is_array($_POST['fallbacks']??null)?$_POST['fallbacks']:[]);
            $notice='Global fallback voices saved for every TTS connector.';
        }elseif($action==='pronunciation_save'){
            $idValue=trim((string)($_POST['id']??''));
            if($idValue!==''&&(!ctype_digit($idValue)||(int)$idValue<1))throw new InvalidArgumentException('invalid_pronunciation');
            $pronunciations->saveCustom($idValue===''?null:(int)$idValue,(string)($_POST['source_text']??''),
                (string)($_POST['spoken_text']??''),(string)($_POST['npc_names']??''),(string)($_POST['races']??''),
                (string)($_POST['oghma_tags']??''),($_POST['enabled']??'')==='1');
            $pronunciationNotice=$idValue===''?'Custom pronunciation added.':'Custom pronunciation saved.';
        }elseif($action==='pronunciation_toggle'){
            $idValue=(string)($_POST['id']??'');$enabled=(string)($_POST['enabled']??'');
            if(!ctype_digit($idValue)||!in_array($enabled,['0','1'],true))throw new InvalidArgumentException('invalid_pronunciation');
            $pronunciations->setEnabled((int)$idValue,$enabled==='1');
            $pronunciationNotice=$enabled==='1'?'Pronunciation enabled.':'Pronunciation disabled.';
        }elseif($action==='pronunciation_delete'){
            $idValue=(string)($_POST['id']??'');if(!ctype_digit($idValue))throw new InvalidArgumentException('invalid_pronunciation');
            $pronunciations->deleteCustom((int)$idValue);$pronunciationNotice='Custom pronunciation deleted.';
        }elseif($action==='discover'){
            $configurationId=(string)($_POST['configuration_id']??'');$preset=$ttsPresetsById[$configurationId]??null;
            if(!is_array($preset))throw new InvalidArgumentException('voice_discovery_unsupported');
            $discoverLanguage=strtolower(trim((string)($_POST['language']??'en'))?:'en');
            $discoveredVoices=lorkhan_voice_discover($preset,$discoverLanguage,$cloudLibrary);$discoveredPreset=$preset;$selectedDiscoveryId=$configurationId;$catalogLoaded=true;
            $products->replaceConnectorVoiceCatalog($configurationId,$discoveredVoices,gmdate('Y-m-d\TH:i:s\Z'));
            $notice=count($discoveredVoices).' provider voices discovered.';
        }elseif($action==='upload'){
            $upload=$_FILES['voice_sample']??null;if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??'')))throw new InvalidArgumentException('voice_upload_failed');
            $extension=strtolower(pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION));
            if($extension==='zip'){$count=lorkhan_voice_import_zip((string)$upload['tmp_name'],$voiceRoot);$notice=$count.' voice samples imported.';}
            else{$filename=lorkhan_voice_filename($voice);$path=$voiceRoot.DIRECTORY_SEPARATOR.$filename;lorkhan_voice_validate_wav((string)$upload['tmp_name']);
                if(is_file($path))throw new InvalidArgumentException('voice_sample_exists');
                if(!move_uploaded_file((string)$upload['tmp_name'],$path))throw new RuntimeException('voice_upload_failed');@chmod($path,0640);$notice='Voice sample saved.';}
        }elseif($action==='sync'){
            $filename=lorkhan_voice_filename($voice);$path=$voiceRoot.DIRECTORY_SEPARATOR.$filename;
            $configurationId=(string)($_POST['configuration_id']??'');$preset=$ttsPresetsById[$configurationId]??null;
            if(!is_array($preset)||!is_file($path))throw new InvalidArgumentException('voice_sample_not_found');
            $language=lorkhan_voice_language(trim((string)($_POST['language']??'en'))?:'en');
            if(in_array($preset['content']['driver'],['cartesia','inworld'],true)){
                if(($_POST['consent']??'')!=='1')throw new InvalidArgumentException('voice_upload_confirmation_required');
                $catalog=$products->connectorVoiceCatalog($configurationId);
                $catalog[]=$cloudLibrary->clone($preset['content']['driver'],$path,pathinfo($filename,PATHINFO_FILENAME),$language);
                $products->replaceConnectorVoiceCatalog($configurationId,$catalog,gmdate('Y-m-d\TH:i:s\Z'));
            }else lorkhan_voice_sync_connector($preset,$path,pathinfo($filename,PATHINFO_FILENAME),$language);
            $notice='Voice sample synced to '.(string)($preset['name']??'the selected connector').'.';
        }elseif($action==='batch_sync'){
            $configurationId=(string)($_POST['configuration_id']??'');$preset=$ttsPresetsById[$configurationId]??null;
            if(!is_array($preset)||!lorkhan_voice_can_sync($preset))throw new InvalidArgumentException('voice_sync_unsupported');
            if(($_POST['consent']??'')!=='1')throw new InvalidArgumentException('voice_upload_confirmation_required');
            $language=lorkhan_voice_language(trim((string)($_POST['language']??'en'))?:'en');
            $catalog=lorkhan_voice_discover($preset,$language,$cloudLibrary);
            $products->replaceConnectorVoiceCatalog($configurationId,$catalog,gmdate('Y-m-d\TH:i:s\Z'));
            $known=[];foreach($catalog as$row){$known[strtolower($row['id'])]=true;$known[strtolower($row['display'])]=true;}
            $count=0;$failed=0;$pending=0;
            // Bounded batches resume from the cached provider catalog; a failed sample cannot erase successes.
            foreach(glob($voiceRoot.DIRECTORY_SEPARATOR.'*.wav')?:[]as$path){
                $name=pathinfo($path,PATHINFO_FILENAME);if(isset($known[strtolower($name)]))continue;$pending++;if($count+$failed>=1)continue;
                try{lorkhan_voice_validate_wav($path);
                    if(in_array($preset['content']['driver'],['cartesia','inworld'],true)){
                        $catalog[]=$cloudLibrary->clone($preset['content']['driver'],$path,$name,$language);
                        $products->replaceConnectorVoiceCatalog($configurationId,$catalog,gmdate('Y-m-d\TH:i:s\Z'));
                    }else lorkhan_voice_sync_connector($preset,$path,$name,$language);
                    $count++;
                }catch(Throwable){$failed++;}
            }
            $notice=$count.' voices uploaded; '.$failed.' failed. Run again for remaining voices.';
            if(($_POST['_batch_ajax']??'')==='1'){header('Content-Type: application/json');echo json_encode(['uploaded'=>$count,'failed'=>$failed,'remaining'=>max(0,$pending-$count)],JSON_THROW_ON_ERROR);exit;}
        }elseif($action==='delete'){
            $filename=lorkhan_voice_filename($voice);$path=$voiceRoot.DIRECTORY_SEPARATOR.$filename;
            $errorReferences=lorkhan_voice_references(pathinfo($filename,PATHINFO_FILENAME),$voiceReferenceIndex);
            if($errorReferences!==[])throw new InvalidArgumentException('voice_sample_in_use');
            if(!is_file($path)||!unlink($path))throw new RuntimeException('voice_delete_failed');$notice='Local voice sample deleted.';
        }else throw new InvalidArgumentException('invalid_voice_action');
    }catch(Throwable $exception){
        if($pronunciationAction){
            $pronunciationError=match($exception->getMessage()){
                'invalid_pronunciation'=>'Enter a valid original term and spoken version.',
                'pronunciation_not_editable'=>'That built-in pronunciation cannot be edited or deleted.',
                'pronunciation_not_found'=>'That pronunciation no longer exists.',
                'unauthorized'=>'Your management session expired. Reload the page and try again.',
                default=>'The pronunciation change could not be saved. Check for a duplicate term and scope.',
            };
        }else{$error=preg_match('/^voice_provider_http_[0-9]{1,3}$/D',$exception->getMessage())?$exception->getMessage():(in_array($exception->getMessage(),['invalid_voice_fallbacks','invalid_voice_name','invalid_voice_language','invalid_voice_sample','invalid_voice_archive','voice_sample_exists','voice_sample_in_use','voice_upload_failed','voice_sample_not_found','voice_sync_unsupported','voice_sync_unavailable','voice_sync_failed','voice_discovery_unsupported','voice_discovery_unavailable','voice_discovery_failed','voice_delete_failed','voice_upload_confirmation_required','voice_credential_missing','unauthorized'],true)?$exception->getMessage():'voice_action_failed');}
    }
}

$pronunciationEntries=$pronunciations->rows();

if($discoveredPreset===null){
    $selectedDiscoveryId=(string)($_GET['configuration_id']??($activeTts['configuration_id']??''));
    if(!isset($ttsPresetsById[$selectedDiscoveryId])||!lorkhan_voice_can_sync($ttsPresetsById[$selectedDiscoveryId])){
        $selectedDiscoveryId='';foreach($ttsPresets as$preset)if(lorkhan_voice_can_sync($preset)){$selectedDiscoveryId=(string)($preset['configuration_id']??'');break;}
    }
    if($selectedDiscoveryId!==''&&isset($ttsPresetsById[$selectedDiscoveryId])){
        $cached=$products->connectorVoiceCatalog($selectedDiscoveryId);
        if($cached!==[]){$discoveredPreset=$ttsPresetsById[$selectedDiscoveryId];$discoveredVoices=$cached;$discoverLanguage=(string)($cached[0]['language']??'en');$catalogLoaded=true;}
    }
}

$samples=[];foreach(glob($voiceRoot.DIRECTORY_SEPARATOR.'*.wav')?:[]as$path){$samples[]=['name'=>pathinfo($path,PATHINFO_FILENAME),'bytes'=>(int)filesize($path),'updated_at'=>gmdate('Y-m-d H:i:s',filemtime($path)?:time()).' UTC'];}
usort($samples,static fn(array$a,array$b):int=>strcasecmp($a['name'],$b['name']));

// The pronunciation preview strip offers exactly the connectors and installed voices the
// management preview endpoint will accept, so a play control can never post an unusable pair.
// The preview opens on the narrator voice so a pronunciation can be judged in the voice LORKHAN
// actually narrates with, falling back to the connector default when that connector cannot speak it.
$narratorPreviewProfile=$installationId===''?null:$products->narratorProfileForInstallation($installationId);
$pronunciationPreview=SpeechPreviewCatalog::options($ttsPresets,$products->connectorVoiceCatalog(),$voiceRoot,
    (string)($activeTts['configuration_id']??''),
    SpeechPreviewCatalog::narratorVoice($narratorPreviewProfile),SpeechPreviewCatalog::narratorConnector($narratorPreviewProfile));
$pronunciationPreviewEndpoint=$managementBasePath.'/api/v1/tts-previews';

$additionalStylesheets=['herika-tts-studio.css?v='.(string)filemtime($uiRootDir.'/css/herika-tts-studio.css')];
require __DIR__.'/tmpl/voice_library_studio.php';
return;
