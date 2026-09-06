<?php
declare(strict_types=1);
$pageTitle='Provider Attempts'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
// Preserve the original all-installation, all-time monitoring scope, including unassigned attempts.
if (!isset($_GET['installation_id'])) $state['installation']='';
if (!isset($_GET['period'])) { $state['period']='all'; $state['since']=null; }
$limit=(int)($_GET['limit']??50); $state['limit']=in_array($limit,[50,100,200],true)?$limit:50;
$states=['started'=>'Pending','succeeded'=>'Success','failed'=>'Error','cancelled'=>'Cancelled'];
if (!isset($states[$state['state']])) $state['state']='';
$conditions=[]; $params=[];
if ($state['installation']!=='') {
    $conditions[]="COALESCE(s.installation_id::text,j.payload->>'installation_id')=:installation";
    $params['installation']=$state['installation'];
}
if ($state['since']!==null) { $conditions[]='a.started_at>=CAST(:since AS timestamptz)'; $params['since']=$state['since']; }
if ($state['state']!=='') { $conditions[]='a.state=:state'; $params['state']=$state['state']; }
if ($state['query']!=='') {
    $conditions[]="concat_ws(' ',a.provider_attempt_id,a.provider_kind,a.provider_name,a.model,a.operation,a.error_code) ILIKE :query";
    $params['query']='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$state['query']).'%';
}
$state=lorkhan_control_query($database,$state,
    "a.provider_attempt_id,to_char(a.started_at AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS time_utc,
        a.provider_kind,a.provider_name,a.model,a.operation,a.state,a.duration_ms,a.error_code",
    'FROM provider_attempts a LEFT JOIN turns t ON t.turn_id=a.turn_id
        LEFT JOIN sessions s ON s.session_id=t.session_id LEFT JOIN durable_jobs j ON j.job_id=a.job_id',
    $conditions,$params,'a.started_at DESC,a.provider_attempt_id DESC');
$columns=['provider_attempt_id'=>'ID','time_utc'=>'Time (UTC)','provider_kind'=>'Service','provider_name'=>'Connector',
    'model'=>'Model','operation'=>'Operation','state'=>'Status','duration_ms'=>'Duration (ms)','error_code'=>'Error'];
$searchPlaceholder='Service, connector, model, operation, error or ID';
$recordNote='LLM, speech-to-text and text-to-speech attempts. Durations and stable error codes are recorded; provider configuration, credentials and raw errors are excluded. Unassigned attempts appear under All Installations. Export contains the displayed page only.';
$emptyMessage='No provider attempts match these filters.';
$exportFilename='provider-attempts.csv';
require __DIR__.'/tmpl/operational_log.html.php';
