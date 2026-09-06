<?php
declare(strict_types=1);
$pageTitle='Workers & Jobs'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
if (!isset($_GET['installation_id'])) $state['installation']='';
if (!isset($_GET['period'])) { $state['period']='all'; $state['since']=null; }
$limit=(int)($_GET['limit']??50); $state['limit']=in_array($limit,[50,100,200],true)?$limit:50;
$states=['queued'=>'Queued','leased'=>'Running','succeeded'=>'Success','dead'=>'Dead Letter'];
if (!isset($states[$state['state']])) $state['state']='';
$conditions=[]; $params=[];
if ($state['installation']!=='') {
    $conditions[]="j.payload->>'installation_id'=:installation"; $params['installation']=$state['installation'];
}
if ($state['since']!==null) { $conditions[]='j.updated_at>=CAST(:since AS timestamptz)'; $params['since']=$state['since']; }
if ($state['state']!=='') { $conditions[]='j.state=:state'; $params['state']=$state['state']; }
if ($state['query']!=='') {
    $conditions[]="concat_ws(' ',j.job_id,j.job_type,j.last_error_code) ILIKE :query";
    $params['query']='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$state['query']).'%';
}
// Only monitoring metadata is selected: payloads, lease credentials and raw errors remain private.
$state=lorkhan_control_query($database,$state,
    "j.job_id,j.job_type,j.state,j.attempt_count,j.max_attempts,
        to_char(j.next_run_at AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS next_run_utc,
        to_char(j.updated_at AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS updated_utc,j.last_error_code",
    'FROM durable_jobs j',$conditions,$params,'j.updated_at DESC,j.job_id DESC');
$columns=['job_id'=>'ID','job_type'=>'Job','state'=>'Status','attempt_count'=>'Attempts','max_attempts'=>'Max Attempts',
    'next_run_utc'=>'Next Run (UTC)','updated_utc'=>'Updated (UTC)','last_error_code'=>'Error'];
$searchPlaceholder='Job type, error or ID';
$recordNote='Durable jobs with bounded retries and dead-letter state. Period filters use the last update time. Next Run is the stored scheduling time, not a promise that finished jobs will run again. Payloads, worker credentials and raw errors are excluded. Export contains the displayed page only.';
$emptyMessage='No durable jobs match these filters.';
$exportFilename='workers-jobs.csv';
require __DIR__.'/tmpl/operational_log.html.php';
