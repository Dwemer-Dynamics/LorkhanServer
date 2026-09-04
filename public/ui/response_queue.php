<?php
declare(strict_types=1);
$pageTitle='Response Queue';$topNavSection='control';$BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
$states=['pending'=>'Pending','emitted'=>'Emitted','played'=>'Played','failed'=>'Failed','interrupted'=>'Interrupted','expired'=>'Expired','overdue'=>'Overdue'];
if(!isset($states[$state['state']]))$state['state']='';
$conditions=[];$params=[];
if($state['installation']!==''){$conditions[]='m.installation_id=:installation';$params['installation']=$state['installation'];}
if($state['since']!==null){$conditions[]='m.created_at>=CAST(:since AS timestamptz)';$params['since']=$state['since'];}
if($state['state']==='overdue')$conditions[]="m.delivery_state IN ('emitted','pending') AND d.delivery_deadline_at<=clock_timestamp()";
elseif($state['state']!==''){$conditions[]='m.delivery_state=:state';$params['state']=$state['state'];}
if($state['query']!==''){$conditions[]='(s.speaker ILIKE :speaker_query OR s.speech ILIKE :text_query)';$params['speaker_query']='%'.$state['query'].'%';$params['text_query']='%'.$state['query'].'%';}
$state=lorkhan_control_query($database,$state,"s.rowid,COALESCE(s.speaker,'Unknown') AS speaker,s.speech AS text,COALESCE(m.delivery_state,'unknown') AS delivery_state,m.created_at,d.delivery_deadline_at,d.delivered_at,CASE WHEN m.delivery_state IN ('emitted','pending') AND d.delivery_deadline_at<=clock_timestamp() THEN 'overdue' ELSE 'current' END AS queue_health",
    'FROM public.speech s JOIN lorkhan_internal.speech_metadata m ON m.rowid=s.rowid LEFT JOIN lorkhan_internal.dialogue_utterances d ON d.dialogue_message_id=m.dialogue_message_id',$conditions,$params,'s.rowid DESC');
if(($_GET['export']??'')==='1')lorkhan_control_export($state['rows'],['rowid'=>'ID','speaker'=>'Speaker','text'=>'Response','delivery_state'=>'Delivery State','created_at'=>'Created','delivery_deadline_at'=>'Deadline','delivered_at'=>'Delivered'],'lorkhan-responses-page-'.$state['page'].'.csv');
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/css/control-reader.css')];include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
?>
<main class="control-reader"><header class="control-reader-heading"><div><h1>Response Queue</h1><p>Full recorded speech and actual playback state. Server delivery is distinct from audio played in game.</p></div><a class="control-reader-button" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['export'=>'1'])) ?>">Export This Page</a></header>
<?php lorkhan_control_filters($state,$states);lorkhan_control_pagination($state); ?>
<div class="control-reader-table-wrap"><table><thead><tr><th>ID / Speaker</th><th>Response</th><th>State</th><th>Created / Delivered</th></tr></thead><tbody>
<?php foreach($state['rows']as$row): ?><tr><td><?= (int)$row['rowid'] ?><small><?= lorkhan_ui_h($row['speaker']) ?></small></td><td class="control-reader-message"><?= lorkhan_ui_h($row['text']) ?></td><td><span class="state state-<?= lorkhan_ui_h($row['delivery_state']) ?>"><?= lorkhan_ui_h(ucfirst($row['delivery_state'])) ?></span><?php if($row['queue_health']==='overdue'): ?><small class="state state-overdue">Overdue</small><?php endif; ?></td><td><?= lorkhan_ui_h($row['created_at']) ?><small class="muted">Delivered: <?= lorkhan_ui_h($row['delivered_at']??'Not recorded') ?></small><small class="muted">Deadline: <?= lorkhan_ui_h($row['delivery_deadline_at']??'Not recorded') ?></small></td></tr><?php endforeach; ?>
<?php if($state['rows']===[]): ?><tr><td colspan="4" class="control-reader-empty">No responses match these filters.</td></tr><?php endif; ?>
</tbody></table></div><?php lorkhan_control_pagination($state); ?></main><?php include __DIR__.'/tmpl/footer.html'; ?>
