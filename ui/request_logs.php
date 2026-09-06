<?php
declare(strict_types=1);
$pageTitle='Request Logs'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
$limit=(int)($_GET['limit']??50);
$state['limit']=in_array($limit,[50,100,200],true)?$limit:50;
if (!isset($_GET['period'])) { $state['period']='all'; $state['since']=null; }
$states=['started'=>'Pending','succeeded'=>'Success','failed'=>'Error','cancelled'=>'Cancelled'];
if (!isset($states[$state['state']])) $state['state']='';
$conditions=["a.provider_kind='llm'",'NOT EXISTS (SELECT 1 FROM lorkhan_internal.request_log_hidden hidden WHERE hidden.provider_attempt_id=a.provider_attempt_id)'];
$params=[];
if ($state['installation']!=='') {
    $conditions[]="COALESCE(s.installation_id::text,j.payload->>'installation_id')=:installation";
    $params['installation']=$state['installation'];
}
if ($state['since']!==null) { $conditions[]='a.started_at>=CAST(:since AS timestamptz)'; $params['since']=$state['since']; }
if ($state['state']!=='') { $conditions[]='a.state=:state'; $params['state']=$state['state']; }
if ($state['query']!=='') {
    $conditions[]='(a.provider_name ILIKE :provider_query OR a.model ILIKE :model_query OR a.provider_attempt_id::text ILIKE :id_query)';
    foreach (['provider_query','model_query','id_query'] as $key) $params[$key]='%'.$state['query'].'%';
}
$from='FROM provider_attempts a LEFT JOIN turns t ON t.turn_id=a.turn_id
    LEFT JOIN sessions s ON s.session_id=t.session_id LEFT JOIN durable_jobs j ON j.job_id=a.job_id';
$select="a.provider_attempt_id,a.turn_id,a.provider_name,
    COALESCE(a.model,CASE WHEN jsonb_typeof(a.metadata->'model')='string' THEN a.metadata->>'model' END) AS model,
    a.operation,a.state,a.error_code,
    to_char(a.started_at AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS time_utc,a.metadata->'usage' AS usage";
$state=lorkhan_control_query($database,$state,$select,$from,$conditions,$params,'a.started_at DESC,a.provider_attempt_id DESC');
// Only complete_turn retains these safe projections. Never select provider configuration or raw errors.
$messages=$database->prepare("SELECT source_manifest#>'{message,_prompt,_messages}' FROM turn_provider_snapshots WHERE turn_id=:turn");
$coverage=$database->prepare("SELECT section_order,section_key,inclusion_reason,source_characters,estimated_tokens
    FROM prompt_trace_sections WHERE prompt_trace_id=(SELECT prompt_trace_id FROM prompt_traces
        WHERE turn_id=:turn ORDER BY created_at DESC,prompt_trace_id DESC LIMIT 1) ORDER BY section_order LIMIT 32");
$result=$database->prepare("SELECT left(string_agg(u.text,E'\n' ORDER BY u.utterance_index),131072)
    FROM dialogue_utterances u WHERE u.turn_id=:turn AND :attempt=(
        SELECT provider_attempt_id::text FROM provider_attempts
        WHERE turn_id=u.turn_id AND provider_kind='llm' AND operation='complete_turn' AND state='succeeded'
        ORDER BY started_at DESC,provider_attempt_id DESC LIMIT 1)");
foreach ($state['rows'] as &$row) {
    $row['request']=''; $row['result']=''; $row['sections']=[];
    if ($row['operation']==='complete_turn' && $row['turn_id']!==null) {
        $messages->execute(['turn'=>$row['turn_id']]);
        $safeMessages=lorkhan_control_prompt_messages($messages->fetchColumn());
        $coverage->execute(['turn'=>$row['turn_id']]); $row['sections']=$coverage->fetchAll(PDO::FETCH_ASSOC);
        if ($safeMessages!==[]) $row['request']=json_encode(['messages'=>$safeMessages],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
        if ($row['state']==='succeeded') {
            $result->execute(['turn'=>$row['turn_id'],'attempt'=>$row['provider_attempt_id']]);
            $row['result']=(string)($result->fetchColumn()?:'');
        }
    }
    $usage=is_string($row['usage'])?json_decode($row['usage'],true):[];
    $tokens=[]; $hasTokens=false;
    foreach (['prompt_tokens','completion_tokens','total_tokens'] as $key) {
        $value=is_array($usage)?($usage[$key]??null):null;
        $valid=(is_int($value)||is_float($value)) && is_finite((float)$value) && $value>=0;
        $tokens[]=$valid?number_format($value,0,'.',''):'-'; $hasTokens=$hasTokens||$valid;
    }
    $row['tokens']=$hasTokens?implode(' / ',$tokens):'';
} unset($row);
$additionalStylesheets=['control-reader.css?v='.(string)filemtime(__DIR__.'/css/control-reader.css'),
    'request-logs.css?v='.(string)filemtime(__DIR__.'/css/request-logs.css')];
include __DIR__.'/tmpl/head.html'; if (!$embedded) include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/request_logs.html.php';
?>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/request-logs.js?v=<?= filemtime(__DIR__.'/js/request-logs.js') ?>" defer></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
