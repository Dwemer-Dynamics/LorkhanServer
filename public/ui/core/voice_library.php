<?php

declare(strict_types=1);

use ALMSIVIserver\Application\ConnectorCatalog;
use ALMSIVIserver\Infrastructure\ProductRepository;
use ALMSIVIserver\Security\OutboundUrlPolicy;

$uiRootDir=dirname(__DIR__);$pageTitle='ALMSIVI TTS Studio';$topNavSection='configuration';$bodyClass='configuration-resource view-voice-library';
require $uiRootDir.'/ui_bootstrap.php';

$voiceRoot=(string)($config['voice_storage_path']??(is_dir('/var/lib/almsiviserver')?'/var/lib/almsiviserver/voices':($applicationRoot.'/storage/voices')));
if(!is_dir($voiceRoot)&&!mkdir($voiceRoot,0750,true)&&!is_dir($voiceRoot))throw new RuntimeException('Voice storage is unavailable.');
$products=new ProductRepository($database);$installations=$uiRepository->rows('installations');
$installationId=(string)($installations[0]['installation_id']??'');
$activeTts=$installationId===''?null:$products->connectorForInstallation($installationId,'tts_provider');
$ttsPresets=array_values(array_filter($uiRepository->rows('tts'),static fn(array$row):bool=>$installationId!==''&&($row['installation_id']??'')===$installationId));
$ttsPresetsById=[];foreach($ttsPresets as$preset){$id=(string)($preset['configuration_id']??'');if($id!=='')$ttsPresetsById[$id]=$preset;}
$voiceReferenceIndex=$products->voiceReferenceIndex();
$sampleUploadDrivers=['pockettts','omnivoice','chatterbox','xtts-fastapi','xtts'];
$voiceDiscoveryDrivers=['pockettts','omnivoice','chatterbox','xtts-fastapi','xtts'];
$notice='';$error='';$discoveredVoices=[];$discoveredPreset=null;$discoverLanguage='en';$catalogLoaded=false;$selectedDiscoveryId='';

/** Validate a user-facing voice name and map it to one bounded local WAV filename. */
function almsivi_voice_filename(string $name):string
{
    $name=trim($name);if($name===''||strlen($name)>80||preg_match('/^[\pL\pN][\pL\pN _+.-]*$/uD',$name)!==1)throw new InvalidArgumentException('invalid_voice_name');
    return preg_replace('/\s+/u','_',$name).'.wav';
}

/** Require a PCM-compatible RIFF/WAVE upload before it enters the persistent voice library. */
function almsivi_voice_validate_wav(string $path):void
{
    $size=filesize($path);$header=file_get_contents($path,false,null,0,12);
    if(!is_int($size)||$size<44||$size>16_777_216||$header===false||substr($header,0,4)!=='RIFF'||substr($header,8,4)!=='WAVE')
        throw new InvalidArgumentException('invalid_voice_sample');
}

/** List active profile and connector references that make a local sample unsafe to delete. */
function almsivi_voice_references(string $voice,array $referenceIndex):array
{
    return$referenceIndex[mb_strtolower(trim($voice),'UTF-8')]??[];
}

/** Normalize the short language identifiers accepted by compatible local voice services. */
function almsivi_voice_language(string $language):string
{
    $language=strtolower(trim($language));
    if($language===''||preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D',$language)!==1)
        throw new InvalidArgumentException('invalid_voice_language');
    return$language;
}

/** Exclude audio.cpp PocketTTS because its local voice directory has no upload endpoint. */
function almsivi_voice_can_sync(array $preset):bool
{
    $content=is_array($preset['content']??null)?$preset['content']:[];$driver=(string)($content['driver']??'');
    if(!in_array($driver,['pockettts','omnivoice','chatterbox','xtts-fastapi','xtts'],true))return false;
    $endpoint=strtolower(rtrim((string)($content['endpoint']??''),'/'));
    return$driver!=='pockettts'||(!str_contains($endpoint,':8086')&&!str_ends_with($endpoint,'/v1/audio/speech'));
}

/** Fetch one bounded JSON document from an explicitly configured local voice service. */
function almsivi_voice_fetch_json(string $endpoint,string $path):array
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
function almsivi_voice_normalize_discovery(array $payload,string $fallbackLanguage):array
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
        try{$language=almsivi_voice_language($language);}catch(InvalidArgumentException){$language=$fallbackLanguage;}
        if($status===''||strlen($status)>64||!mb_check_encoding($status,'UTF-8'))$status='available';
        $voices[$id]=['id'=>$id,'display'=>$display===''?$id:$display,'language'=>$language===''?$fallbackLanguage:$language,
            'status'=>$status===''?'available':$status,'custom'=>$custom];
    }
    $voices=array_values($voices);usort($voices,static fn(array$a,array$b):int=>strcasecmp($a['display'],$b['display']));return$voices;
}

/** Query only CHIM-compatible local speaker-list endpoints after an explicit browser action. */
function almsivi_voice_discover(array $preset,string $language):array
{
    $content=is_array($preset['content']??null)?$preset['content']:[];$driver=(string)($content['driver']??'');
    if(!almsivi_voice_can_sync($preset))throw new InvalidArgumentException('voice_discovery_unsupported');
    $language=almsivi_voice_language($language);
    $path=$driver==='omnivoice'?'/speakers_list_extended?language='.rawurlencode($language):'/speakers_list';
    return almsivi_voice_normalize_discovery(almsivi_voice_fetch_json((string)($content['endpoint']??''),$path),$language);
}

/** Import a bounded flat ZIP of WAV files without allowing traversal or partial batches. */
function almsivi_voice_import_zip(string $archivePath,string $voiceRoot):int
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
            $filename=almsivi_voice_filename(pathinfo($entry,PATHINFO_FILENAME));$key=strtolower($filename);
            if(isset($seen[$key])||is_file($voiceRoot.DIRECTORY_SEPARATOR.$filename))throw new InvalidArgumentException('voice_sample_exists');
            $seen[$key]=true;$declared=(int)($stat['size']??0);
            if($declared<44||$declared>16_777_216)throw new InvalidArgumentException('invalid_voice_sample');
            $source=$zip->getStream($entry);$temp=tempnam($voiceRoot,'.voice-import-');
            if(!is_resource($source)||$temp===false){if(is_resource($source))fclose($source);if(is_string($temp))@unlink($temp);throw new RuntimeException('voice_upload_failed');}
            $destination=fopen($temp,'wb');if($destination===false){fclose($source);@unlink($temp);throw new RuntimeException('voice_upload_failed');}
            try{$copied=stream_copy_to_stream($source,$destination,16_777_217);}finally{fclose($source);fclose($destination);}
            if(!is_int($copied)||$copied!==$declared||$copied>16_777_216){@unlink($temp);throw new InvalidArgumentException('invalid_voice_sample');}
            $totalBytes+=$copied;if($totalBytes>134_217_728){@unlink($temp);throw new InvalidArgumentException('invalid_voice_archive');}
            almsivi_voice_validate_wav($temp);$staged[]=['temp'=>$temp,'path'=>$voiceRoot.DIRECTORY_SEPARATOR.$filename];
            if(count($staged)>64)throw new InvalidArgumentException('invalid_voice_archive');
        }
        if($staged===[])throw new InvalidArgumentException('invalid_voice_archive');
        foreach($staged as$item){if(!rename($item['temp'],$item['path']))throw new RuntimeException('voice_upload_failed');@chmod($item['path'],0640);$created[]=$item['path'];}
        return count($created);
    }catch(Throwable $error){foreach($staged as$item)if(is_file($item['temp']))@unlink($item['temp']);foreach($created as$path)if(is_file($path))@unlink($path);throw$error;}
    finally{$zip->close();}
}

/** Upload one stored sample to a configured local connector using its bounded multipart contract. */
function almsivi_voice_sync_connector(array $preset,string $path,string $voice,string $language):void
{
    $content=is_array($preset['content']??null)?$preset['content']:[];$driver=(string)($content['driver']??'');
    if(!almsivi_voice_can_sync($preset))throw new InvalidArgumentException('voice_sync_unsupported');
    $language=almsivi_voice_language($language);
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
    try{
        if(!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new RuntimeException('unauthorized');
        $action=(string)($_POST['action']??'');$voice=(string)($_POST['voice_name']??'');
        if($action==='discover'){
            $configurationId=(string)($_POST['configuration_id']??'');$preset=$ttsPresetsById[$configurationId]??null;
            if(!is_array($preset))throw new InvalidArgumentException('voice_discovery_unsupported');
            $discoverLanguage=strtolower(trim((string)($_POST['language']??'en'))?:'en');
            $discoveredVoices=almsivi_voice_discover($preset,$discoverLanguage);$discoveredPreset=$preset;$selectedDiscoveryId=$configurationId;$catalogLoaded=true;
            $products->replaceConnectorVoiceCatalog($configurationId,$discoveredVoices,gmdate('Y-m-d\TH:i:s\Z'));
            $notice=count($discoveredVoices).' provider voices discovered.';
        }elseif($action==='upload'){
            $upload=$_FILES['voice_sample']??null;if(!is_array($upload)||($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??'')))throw new InvalidArgumentException('voice_upload_failed');
            $extension=strtolower(pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION));
            if($extension==='zip'){$count=almsivi_voice_import_zip((string)$upload['tmp_name'],$voiceRoot);$notice=$count.' voice samples imported.';}
            else{$filename=almsivi_voice_filename($voice);$path=$voiceRoot.DIRECTORY_SEPARATOR.$filename;almsivi_voice_validate_wav((string)$upload['tmp_name']);
                if(is_file($path))throw new InvalidArgumentException('voice_sample_exists');
                if(!move_uploaded_file((string)$upload['tmp_name'],$path))throw new RuntimeException('voice_upload_failed');@chmod($path,0640);$notice='Voice sample saved.';}
        }elseif($action==='sync'){
            $filename=almsivi_voice_filename($voice);$path=$voiceRoot.DIRECTORY_SEPARATOR.$filename;
            $configurationId=(string)($_POST['configuration_id']??'');$preset=$ttsPresetsById[$configurationId]??null;
            if(!is_array($preset)||!is_file($path))throw new InvalidArgumentException('voice_sample_not_found');
            almsivi_voice_sync_connector($preset,$path,pathinfo($filename,PATHINFO_FILENAME),trim((string)($_POST['language']??'en'))?:'en');
            $notice='Voice sample synced to '.(string)($preset['name']??'the selected connector').'.';
        }elseif($action==='delete'){
            $filename=almsivi_voice_filename($voice);$path=$voiceRoot.DIRECTORY_SEPARATOR.$filename;
            if(almsivi_voice_references(pathinfo($filename,PATHINFO_FILENAME),$voiceReferenceIndex)!==[])throw new InvalidArgumentException('voice_sample_in_use');
            if(!is_file($path)||!unlink($path))throw new RuntimeException('voice_delete_failed');$notice='Local voice sample deleted.';
        }else throw new InvalidArgumentException('invalid_voice_action');
    }catch(Throwable $exception){$error=in_array($exception->getMessage(),['invalid_voice_name','invalid_voice_language','invalid_voice_sample','invalid_voice_archive','voice_sample_exists','voice_sample_in_use','voice_upload_failed','voice_sample_not_found','voice_sync_unsupported','voice_sync_unavailable','voice_sync_failed','voice_discovery_unsupported','voice_discovery_unavailable','voice_discovery_failed','voice_delete_failed','unauthorized'],true)?$exception->getMessage():'voice_action_failed';}
}

if($discoveredPreset===null){
    $selectedDiscoveryId=(string)($_GET['configuration_id']??($activeTts['configuration_id']??''));
    if(!isset($ttsPresetsById[$selectedDiscoveryId])||!almsivi_voice_can_sync($ttsPresetsById[$selectedDiscoveryId])){
        $selectedDiscoveryId='';foreach($ttsPresets as$preset)if(almsivi_voice_can_sync($preset)){$selectedDiscoveryId=(string)($preset['configuration_id']??'');break;}
    }
    if($selectedDiscoveryId!==''&&isset($ttsPresetsById[$selectedDiscoveryId])){
        $cached=$products->connectorVoiceCatalog($selectedDiscoveryId);
        if($cached!==[]){$discoveredPreset=$ttsPresetsById[$selectedDiscoveryId];$discoveredVoices=$cached;$discoverLanguage=(string)($cached[0]['language']??'en');$catalogLoaded=true;}
    }
}

$samples=[];foreach(glob($voiceRoot.DIRECTORY_SEPARATOR.'*.wav')?:[]as$path){$samples[]=['name'=>pathinfo($path,PATHINFO_FILENAME),'bytes'=>(int)filesize($path),'updated_at'=>gmdate('Y-m-d H:i:s',filemtime($path)?:time()).' UTC'];}
usort($samples,static fn(array$a,array$b):int=>strcasecmp($a['name'],$b['name']));

include $uiRootDir.'/tmpl/head.html';if(!$embedded)include $uiRootDir.'/tmpl/navbar.php';
?>
<main class="management-page">
    <header class="configuration-page-header"><h1>ALMSIVI TTS Studio</h1><p>Manage persistent WAV voice references, sync compatible local services, and test every configured TTS connector. Voice files never enter profile JSON or browser cookies.</p></header>
    <?php if($notice!==''):?><p class="page-status" role="status"><?php echo almsivi_ui_h($notice);?></p><?php endif;?>
    <?php if($error!==''):?><p class="page-error" role="alert"><?php echo almsivi_ui_h($error);?></p><?php endif;?>
    <section class="widget widget-wide"><div class="widget-header"><h3>Active TTS</h3></div><div class="widget-content">
        <?php if($activeTts===null):?><p class="empty-state">Select a TTS connector before normal dialogue playback.</p>
        <?php else:$activeContent=$activeTts['content']??[];$activeDefinition=ConnectorCatalog::definition('tts_provider',(string)($activeContent['driver']??''));?>
            <dl><dt>Connector</dt><dd><?php echo almsivi_ui_h($activeDefinition['label']);?></dd><dt>Endpoint</dt><dd><code><?php echo almsivi_ui_h($activeContent['endpoint']??'');?></code></dd><dt>Default voice</dt><dd><?php echo almsivi_ui_h($activeContent['voice']??'');?></dd></dl>
        <?php endif;?>
    </div></section>
    <section class="widget widget-wide"><div class="widget-header"><h3>Configured TTS Connectors</h3></div><div class="widget-content">
        <?php if($ttsPresets===[]):?><p class="empty-state">No TTS connector presets are configured.</p><?php else:?><div class="voice-grid">
        <?php foreach($ttsPresets as$preset):$content=is_array($preset['content']??null)?$preset['content']:[];$definition=ConnectorCatalog::definition('tts_provider',(string)($content['driver']??''));?>
            <article class="voice-card"><h3><?php echo almsivi_ui_h($preset['name']??$definition['label']);?></h3><p><?php echo almsivi_ui_h($definition['label']);?><?php echo !empty($preset['active'])?' · Active installation connector':'';?></p><p><code><?php echo almsivi_ui_h($content['endpoint']??'');?></code></p></article>
        <?php endforeach;?></div><?php endif;?>
    </div></section>
    <section class="widget widget-wide"><div class="widget-header"><h3>Provider Voice Browser</h3></div><div class="widget-content">
        <p>Explicitly query an OmniVoice, Chatterbox, or XTTS speaker library. Opening TTS Studio never contacts a provider automatically.</p>
        <?php $discoverablePresets=array_values(array_filter($ttsPresets,static fn(array$p):bool=>in_array((string)($p['content']['driver']??''),$voiceDiscoveryDrivers,true)&&almsivi_voice_can_sync($p)));?>
        <?php if($discoverablePresets===[]):?><p class="empty-state">Configure a compatible local TTS connector to browse its voices.</p><?php else:?>
        <form class="management-form" method="post"><fieldset><legend>Browse provider voices</legend>
            <label for="voice-discovery-connector">TTS connector</label><select id="voice-discovery-connector" name="configuration_id"><?php foreach($discoverablePresets as$preset):?><option value="<?php echo almsivi_ui_h($preset['configuration_id']??'');?>"<?php echo $selectedDiscoveryId===($preset['configuration_id']??'')?' selected':'';?>><?php echo almsivi_ui_h($preset['name']??'TTS connector');?></option><?php endforeach;?></select>
            <label for="voice-discovery-language">Language</label><input id="voice-discovery-language" name="language" value="<?php echo almsivi_ui_h($discoverLanguage);?>" maxlength="12">
        </fieldset><input type="hidden" name="action" value="discover"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><button class="btn-base btn-primary" type="submit">Discover voices</button></form>
        <?php endif;?>
        <?php if($catalogLoaded&&is_array($discoveredPreset)):?>
            <?php if($discoveredVoices===[]):?><p class="empty-state">The provider returned no voices for this language.</p><?php else:?><div class="voice-grid">
            <?php foreach($discoveredVoices as$item):$voiceControlId=substr(hash('sha256',(string)$item['id']),0,12);?>
                <article class="voice-card"><h3><?php echo almsivi_ui_h($item['display']);?></h3><p><code><?php echo almsivi_ui_h($item['id']);?></code></p><p><?php echo almsivi_ui_h($item['language']);?> · <?php echo almsivi_ui_h($item['status']);?><?php echo !empty($item['custom'])?' · Custom voice':'';?></p><div class="connector-actions">
                    <form method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/connector-test');?>"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId);?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($discoveredPreset['configuration_id']??'');?>"><input type="hidden" name="voice_id" value="<?php echo almsivi_ui_h($item['id']);?>"><button class="btn-base" type="submit">Test voice</button></form>
                    <form method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/connector-default-voice');?>"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($discoveredPreset['configuration_id']??'');?>"><input type="hidden" name="voice_id" value="<?php echo almsivi_ui_h($item['id']);?>"><input type="hidden" name="language" value="<?php echo almsivi_ui_h($item['language']);?>"><button class="btn-base btn-primary" type="submit">Set connector default</button></form>
                </div></article>
            <?php endforeach;?></div><?php endif;?>
        <?php endif;?>
    </div></section>
    <form class="management-form" method="post" enctype="multipart/form-data">
        <fieldset><legend>Add WAV voice samples</legend>
            <label for="voice-name">Voice name for a single WAV</label><input id="voice-name" name="voice_name" maxlength="80">
            <label for="voice-sample">PCM WAV (16 MiB) or flat ZIP batch (64 WAVs, 128 MiB extracted)</label><input id="voice-sample" name="voice_sample" type="file" accept="audio/wav,.wav,application/zip,.zip" required>
        </fieldset><input type="hidden" name="action" value="upload"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><button class="btn-base btn-primary" type="submit">Import voice sample</button>
    </form>
    <section class="widget widget-wide"><div class="widget-header"><h3>Voice Library</h3></div><div class="widget-content">
        <?php if($samples===[]):?><p class="empty-state">No voice samples have been uploaded.</p><?php else:?><div class="voice-grid">
        <?php foreach($samples as$sample):$sampleControlId=substr(hash('sha256',$sample['name']),0,12);$sampleReferences=almsivi_voice_references($sample['name'],$voiceReferenceIndex);?><article class="voice-card"><h3><?php echo almsivi_ui_h($sample['name']);?></h3><p><?php echo almsivi_ui_h(number_format($sample['bytes']/1024,1));?> KiB · <?php echo almsivi_ui_h($sample['updated_at']);?></p><?php if($sampleReferences!==[]):?><p><strong>In use by:</strong> <?php echo almsivi_ui_h(implode(', ',$sampleReferences));?></p><?php endif;?><div class="connector-actions">
            <?php if($ttsPresets!==[]):?>
            <?php $syncable=array_values(array_filter($ttsPresets,static fn(array$p):bool=>in_array((string)($p['content']['driver']??''),$sampleUploadDrivers,true)&&almsivi_voice_can_sync($p)));if($syncable!==[]):?><form method="post"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="voice_name" value="<?php echo almsivi_ui_h($sample['name']);?>"><label for="sync-<?php echo $sampleControlId;?>">Sync sample to</label><select id="sync-<?php echo $sampleControlId;?>" name="configuration_id"><?php foreach($syncable as$preset):?><option value="<?php echo almsivi_ui_h($preset['configuration_id']??'');?>"><?php echo almsivi_ui_h($preset['name']??'TTS connector');?></option><?php endforeach;?></select><label for="sync-language-<?php echo $sampleControlId;?>">Language</label><input id="sync-language-<?php echo $sampleControlId;?>" name="language" value="en" maxlength="12" pattern="[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})?"><button class="btn-base btn-primary" type="submit">Sync voice sample</button></form><?php endif;?>
            <form method="post" action="<?php echo almsivi_ui_h($managementBasePath.'/forms/connector-test');?>"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId);?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="voice_id" value="<?php echo almsivi_ui_h($sample['name']);?>"><label for="test-<?php echo $sampleControlId;?>">Test sample with</label><select id="test-<?php echo $sampleControlId;?>" name="configuration_id"><?php foreach($ttsPresets as$preset):?><option value="<?php echo almsivi_ui_h($preset['configuration_id']??'');?>"><?php echo almsivi_ui_h($preset['name']??'TTS connector');?></option><?php endforeach;?></select><button class="btn-base" type="submit">Test voice</button></form><?php endif;?>
            <?php if($sampleReferences===[]):?><form method="post"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf);?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="voice_name" value="<?php echo almsivi_ui_h($sample['name']);?>"><button class="btn-base btn-danger" type="submit">Delete local sample</button></form><?php else:?><span class="management-note">Clear every listed reference before deleting this sample.</span><?php endif;?>
        </div></article><?php endforeach;?></div><?php endif;?>
    </div></section>
</main>
<?php include $uiRootDir.'/tmpl/footer.html';?>
