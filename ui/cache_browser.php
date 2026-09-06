<?php
declare(strict_types=1);
$pageTitle='Audio Cache';$topNavSection='control';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';require __DIR__.'/tmpl/control_reader.php';
$_GET += ['period'=>'all','limit'=>'100','state'=>'available'];
$state=lorkhan_control_state($uiRepository->rows('installations'));
$states=['available'=>'Available','expired'=>'Expired','deleted'=>'Deleted'];
if(!isset($states[$state['state']]))$state['state']='';
$conditions=[];$params=[];
if($state['installation']!==''){$conditions[]='m.installation_id=:installation';$params['installation']=$state['installation'];}
if($state['since']!==null){$conditions[]='m.created_at>=CAST(:since AS timestamptz)';$params['since']=$state['since'];}
$cacheState="CASE WHEN m.deleted_at IS NOT NULL THEN 'deleted' WHEN m.expires_at<=clock_timestamp() THEN 'expired' ELSE 'available' END";
if($state['state']!==''){$conditions[]=$cacheState.'=:state';$params['state']=$state['state'];}
$speaker="COALESCE(d.speaker->>'display_name',d.speaker->>'name',d.speaker->>'record_id',menu.actor->>'display_name',menu.actor->>'name',menu.actor->>'record_id','Unknown')";
if($state['query']!==''){$conditions[]='('.$speaker.' ILIKE :speaker_query OR m.media_id::text ILIKE :id_query)';$params['speaker_query']='%'.$state['query'].'%';$params['id_query']='%'.$state['query'].'%';}
$state=lorkhan_control_query($database,$state,'m.media_id,m.installation_id,m.codec,m.byte_count,m.duration_ms,m.expires_at,m.created_at,'.$speaker.' AS speaker,'.$cacheState.' AS cache_state',
    'FROM media_objects m LEFT JOIN dialogue_utterances d ON d.dialogue_message_id=m.dialogue_message_id LEFT JOIN menu_dialogue_tts_requests menu ON menu.message_id=m.menu_dialogue_message_id',$conditions,$params,'m.created_at DESC,m.media_id');
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/css/control-reader.css'),'audio-cache.css?v='.(string)filemtime(__DIR__.'/css/audio-cache.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/audio_cache.html.php';
?>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/control-reader.js?v=<?= filemtime(__DIR__.'/js/control-reader.js') ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
