<?php
declare(strict_types=1);
$pageTitle='Audio Cache';$topNavSection='control';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
$states=['available'=>'Available','expired'=>'Expired','deleted'=>'Deleted'];
if(!isset($states[$state['state']]))$state['state']='';
$conditions=[];$params=[];
if($state['installation']!==''){$conditions[]='m.installation_id=:installation';$params['installation']=$state['installation'];}
if($state['since']!==null){$conditions[]='m.created_at>=CAST(:since AS timestamptz)';$params['since']=$state['since'];}
$cacheState="CASE WHEN m.deleted_at IS NOT NULL THEN 'deleted' WHEN m.expires_at<=clock_timestamp() THEN 'expired' ELSE 'available' END";
if($state['state']!==''){$conditions[]=$cacheState.'=:state';$params['state']=$state['state'];}
$speaker="COALESCE(d.speaker->>'display_name',d.speaker->>'name',d.speaker->>'record_id','Unknown')";
if($state['query']!==''){$conditions[]='('.$speaker.' ILIKE :speaker_query OR m.media_id::text ILIKE :id_query)';$params['speaker_query']='%'.$state['query'].'%';$params['id_query']='%'.$state['query'].'%';}
$state=lorkhan_control_query($database,$state,'m.media_id,m.installation_id,m.codec,m.byte_count,m.duration_ms,m.expires_at,m.created_at,'.$speaker.' AS speaker,'.$cacheState.' AS cache_state',
    'FROM media_objects m LEFT JOIN dialogue_utterances d ON d.dialogue_message_id=m.dialogue_message_id',$conditions,$params,'m.created_at DESC,m.media_id');
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/css/control-reader.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="control-reader">
<header class="control-reader-heading"><div><h1>Audio Cache</h1><p>Listen to cached speech. Expired and deleted entries remain visible as records but cannot be played.</p></div></header>
<?php lorkhan_control_filters($state,$states);lorkhan_control_pagination($state); ?>
<div class="control-reader-table-wrap"><table><thead><tr><th>Speaker</th><th>Audio</th><th>Created / Expires</th><th>Status</th><th>Listen</th></tr></thead><tbody>
<?php foreach($state['rows']as$row): ?>
<tr><td><?= lorkhan_ui_h($row['speaker']) ?><small><code><?= lorkhan_ui_h($row['media_id']) ?></code></small></td><td><?= lorkhan_ui_h(strtoupper($row['codec'])) ?> · <?= number_format((int)$row['byte_count']/1024,1) ?> KiB<small><?= number_format((int)$row['duration_ms']/1000,1) ?> seconds</small></td><td><?= lorkhan_ui_h($row['created_at']) ?><small class="muted">Expires <?= lorkhan_ui_h($row['expires_at']) ?></small></td><td><span class="state state-<?= lorkhan_ui_h($row['cache_state']) ?>"><?= lorkhan_ui_h(ucfirst($row['cache_state'])) ?></span></td><td><?php if($row['cache_state']==='available'): ?><audio controls preload="none" aria-label="Cached speech by <?= lorkhan_ui_h($row['speaker']) ?>" src="<?= lorkhan_ui_h($webRoot.'/ui/cache_audio.php?'.http_build_query(['media_id'=>$row['media_id'],'installation_id'=>$row['installation_id']])) ?>"></audio><small data-cache-status role="status"></small><?php else: ?><span class="muted">No playable audio</span><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if($state['rows']===[]): ?><tr><td colspan="5" class="control-reader-empty">No cached speech matches these filters.</td></tr><?php endif; ?>
</tbody></table></div><?php lorkhan_control_pagination($state); ?>
</main>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/control-reader.js?v=<?= filemtime(__DIR__.'/js/control-reader.js') ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
