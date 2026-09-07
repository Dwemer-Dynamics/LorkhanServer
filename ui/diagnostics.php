<?php
declare(strict_types=1);
$pageTitle='Server Health'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
$health=$productRepository->diagnostics();
$monitoringSummary=['Database'=>$health['database']['connected']?'Connected':'Unavailable',
    'PostgreSQL'=>$health['database']['version'],'Installations'=>$health['counts']['installations'],
    'Active Sessions'=>$health['counts']['active_sessions'],'Queued Jobs'=>$health['counts']['queued_jobs'],
    'Dead-Letter Jobs'=>$health['counts']['dead_jobs'],'Memory Records'=>$health['counts']['memory_records']];
$state=lorkhan_control_state($uiRepository->rows('installations'));
if (!isset($_GET['installation_id'])) $state['installation']='';
if (!isset($_GET['period'])) { $state['period']='all'; $state['since']=null; }
$limit=(int)($_GET['limit']??50); $state['limit']=in_array($limit,[50,100,200],true)?$limit:50;
$states=[]; $state['state']=''; $conditions=[]; $params=[];
if ($state['installation']!=='') {
    $conditions[]="COALESCE(scope->>'installation_id',scope->>'installation')=:installation";
    $params['installation']=$state['installation'];
}
if ($state['since']!==null) { $conditions[]='created_at>=CAST(:since AS timestamptz)'; $params['since']=$state['since']; }
if ($state['query']!=='') {
    $conditions[]="concat_ws(' ',audit_id,category,action) ILIKE :query";
    $params['query']='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$state['query']).'%';
}
// Only identifier-bearing scope fields are exposed; audit details and arbitrary scope payloads stay private.
$state=lorkhan_control_query($database,$state,
    "audit_id,category,action,to_char(created_at AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS time_utc,
        COALESCE((SELECT string_agg(s.key||': '||s.value,E'\n' ORDER BY s.key) FROM jsonb_each_text(scope) s
            WHERE s.key IN ('installation_id','installation','profile_id','playthrough_id','configuration_id',
                'narrative_id','memory_id','relationship_id','pairing_token_id','backup_id','session_id','turn_id','request_id')
                AND s.value ~ '^[0-9a-fA-F-]{36}$'),'') AS scope",
    'FROM operational_audit',$conditions,$params,'created_at DESC,audit_id DESC');
$columns=['audit_id'=>'Audit ID','time_utc'=>'Time (UTC)','category'=>'Category','action'=>'Action','scope'=>'Scope'];
$searchPlaceholder='Category, action or audit ID';
$recordNote='Operational audit records. Scope shows recorded identifiers only; audit details and credentials are excluded. Filters apply to the audit, not the server-wide snapshot. Export contains the displayed audit page only.';
$emptyMessage='No operational audit records match these filters.'; $exportFilename='operational-audit.csv';
require __DIR__.'/tmpl/operational_log.html.php';
