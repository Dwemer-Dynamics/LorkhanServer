<?php
declare(strict_types=1);
$pageTitle='Backup Health'; $topNavSection='control'; $BODY_CLASS='hub-page';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
$state=lorkhan_control_state($uiRepository->rows('installations'));
if (!isset($_GET['installation_id'])) $state['installation']='';
if (!isset($_GET['period'])) { $state['period']='all'; $state['since']=null; }
$limit=(int)($_GET['limit']??50); $state['limit']=in_array($limit,[50,100,200],true)?$limit:50;
$states=['created'=>'Created','restored'=>'Restored','failed'=>'Failed'];
if (!isset($states[$state['state']])) $state['state']='';
$conditions=[]; $params=[];
if ($state['installation']!=='') { $conditions[]="scope->>'installation_id'=:installation"; $params['installation']=$state['installation']; }
if ($state['since']!==null) { $conditions[]='created_at>=CAST(:since AS timestamptz)'; $params['since']=$state['since']; }
if ($state['state']!=='') { $conditions[]='state=:state'; $params['state']=$state['state']; }
if ($state['query']!=='') {
    $conditions[]="concat_ws(' ',backup_id,scope->>'kind',scope->>'installation_id',scope->>'profile_id',scope->>'playthrough_id') ILIKE :query";
    $params['query']='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$state['query']).'%';
}
$state=lorkhan_control_query($database,$state,
    "backup_id,state,format_version,byte_count,COALESCE(scope->>'kind','Not recorded') AS kind,
        scope->>'installation_id' AS installation_id,scope->>'profile_id' AS profile_id,scope->>'playthrough_id' AS playthrough_id,
        to_char(created_at AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS created_utc,
        to_char(restored_at AT TIME ZONE 'UTC','DD-MM-YYYY HH24:MI:SS') AS restored_utc",
    'FROM backup_records',$conditions,$params,'created_at DESC,backup_id DESC');
foreach ($state['rows'] as &$row) {
    $scope=[];
    foreach (['installation_id'=>'Installation','profile_id'=>'Profile','playthrough_id'=>'Playthrough'] as $key=>$label) {
        if (($row[$key]??'')!=='') $scope[]=$label.': '.$row[$key];
    }
    $row['scope']=implode("\n",$scope);
} unset($row);
$columns=['backup_id'=>'Backup ID','kind'=>'Kind','state'=>'Status','format_version'=>'Format','byte_count'=>'Size (bytes)',
    'scope'=>'Scope','created_utc'=>'Created (UTC)','restored_utc'=>'Restored (UTC)'];
$searchPlaceholder='Backup ID, kind or scope ID';
$recordNote='Recorded backup metadata only; this page does not check file integrity. Scope shows installation, profile and playthrough IDs when recorded. Storage paths, hashes and raw payloads are excluded. Export contains the displayed page only.';
$emptyMessage='No backups match these filters.'; $exportFilename='backup-records.csv';
$operationalPanel=__DIR__.'/tmpl/backup_retention.html.php';
require __DIR__.'/tmpl/operational_log.html.php';
