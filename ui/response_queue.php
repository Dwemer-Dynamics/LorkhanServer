<?php
declare(strict_types=1);
$pageTitle='Response Queue'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
if (!isset($_GET['period'])) { $state['period']='all'; $state['since']=null; }
if (!isset($_GET['limit'])) $state['limit']=100;
$states=['queued'=>'Queued','sent'=>'Sent','played'=>'Played','failed'=>'Failed','interrupted'=>'Interrupted','expired'=>'Expired','overdue'=>'Overdue'];
if (!isset($states[$state['state']])) $state['state']='';
$conditions=[]; $params=[];
if ($state['installation']!=='') { $conditions[]='m.installation_id=:installation'; $params['installation']=$state['installation']; }
if ($state['since']!==null) { $conditions[]='m.created_at>=CAST(:since AS timestamptz)'; $params['since']=$state['since']; }
if ($state['state']==='queued') $conditions[]='r.sent=0';
elseif ($state['state']==='sent') $conditions[]='r.sent=1';
elseif ($state['state']==='overdue') $conditions[]="d.delivery_state='pending' AND d.delivery_deadline_at<=clock_timestamp()";
elseif ($state['state']!=='') { $conditions[]='d.delivery_state=:state'; $params['state']=$state['state']; }
if ($state['query']!=='') {
    $conditions[]='(r.actor ILIKE :actor_query OR r.text ILIKE :text_query OR r.action ILIKE :action_query OR r.tag ILIKE :tag_query)';
    foreach (['actor_query','text_query','action_query','tag_query'] as $key) $params[$key]='%'.$state['query'].'%';
}
$state=lorkhan_control_query($database,$state,
    'r.localts,r.sent,r.actor,r.text,r.action,r.tag,r.rowid,m.installation_id,d.delivery_state,d.delivery_deadline_at,d.delivered_at,('
        .\LorkhanServer\Infrastructure\ManagementRepository::RESPONSE_QUEUE_REMOVABLE.') AS can_remove',
    'FROM public.responselog r JOIN lorkhan_internal.responselog_metadata m ON m.rowid=r.rowid
        LEFT JOIN sessions s ON s.session_id=m.session_id LEFT JOIN turns t ON t.turn_id=m.turn_id
        LEFT JOIN dialogue_utterances d ON d.dialogue_message_id=m.response_message_id',
    $conditions,$params,'r.rowid ASC');
if (($_GET['export']??'')==='1') lorkhan_control_export($state['rows'],
    ['localts'=>'localts','sent'=>'sent','actor'=>'actor','text'=>'text','action'=>'action','tag'=>'tag','rowid'=>'rowid'],
    'lorkhan-response-queue-page-'.$state['page'].'.csv');
$additionalStylesheets=['control-reader.css?v='.filemtime(__DIR__.'/css/control-reader.css'),
    'response-queue.css?v='.filemtime(__DIR__.'/css/response-queue.css')];
include __DIR__.'/tmpl/head.html'; if (!$embedded) include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/response_queue.html.php';
?>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/response-queue.js?v=<?= filemtime(__DIR__.'/js/response-queue.js') ?>" defer></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
