<?php
declare(strict_types=1);
$pageTitle='Playthrough Manager';$topNavSection='control';$BODY_CLASS='hub-page playthrough-shell';
require __DIR__.'/ui_bootstrap.php';
$snapshotSaveJob=$managementRepository->snapshotSaveStatus();
$snapshotRestoreJob=$managementRepository->databaseMaintenanceStatus('database.restore');
$snapshotSource=$database->query('SELECT s.backup_id,s.name,s.copied_at,EXISTS(SELECT 1 FROM backup_records b WHERE b.backup_id=s.backup_id) AS stored FROM lorkhan_internal.database_snapshot_source s WHERE singleton')->fetch(PDO::FETCH_ASSOC);
$snapshotPage=max(1,min(100000,(int)($_GET['snapshot_page']??1)));
$storedSnapshots=$database->query("SELECT backup_id,byte_count,created_at,scope#>>'{snapshot,name}' AS name,scope#>>'{snapshot,notes}' AS notes,scope->>'rollback_for' AS rollback_for FROM backup_records WHERE scope->>'kind'='database_sql' AND jsonb_exists(scope,'snapshot') ORDER BY created_at DESC,backup_id DESC LIMIT 26 OFFSET ".(($snapshotPage-1)*25))->fetchAll(PDO::FETCH_ASSOC);
$snapshotsHasNext=count($storedSnapshots)>25;$storedSnapshots=array_slice($storedSnapshots,0,25);
$snapshotPageUrl=static fn(int $number):string=>'?'.http_build_query(['snapshot_page'=>$number,'embed'=>$embedded?'1':'0']);
$liveDatabase=$uiRepository->dashboard();
$snapshotCounts=$database->query('SELECT (SELECT count(*) FROM public.eventlog) AS events,(SELECT count(*) FROM public.oghma) AS knowledge')->fetch(PDO::FETCH_ASSOC);
$installations=$uiRepository->rows('installations');
$installationIds=array_column($installations,'installation_id');
$installationId=(string)($_GET['installation_id']??($installationIds[0]??''));
if(!in_array($installationId,$installationIds,true))$installationId=(string)($installationIds[0]??'');
$page=max(1,min(100000,(int)($_GET['page']??1)));
$rows=$installationId===''?[]:$uiRepository->rows('playthroughs',null,['installation_id'=>$installationId,'page'=>$page]);
$hasNextPage=count($rows)>100;$rows=array_slice($rows,0,100);
$selected=null;$playthroughId=(string)($_GET['playthrough_id']??'');
foreach($rows as$row)if($row['playthrough_id']===$playthroughId){$selected=$row;break;}
if($selected===null&&\LorkhanServer\Infrastructure\Uuid::isValid($playthroughId)) {
    $selected=$uiRepository->rows('playthroughs',null,['installation_id'=>$installationId,'selected_id'=>$playthroughId])[0]??null;
}
$selected??=$rows[0]??null;
$profiles=array_values(array_filter(array_merge($uiRepository->rows('characters'),$uiRepository->rows('player')),static fn(array$row):bool=>$row['installation_id']===$installationId));
$playthroughUrl=static fn(string$id):string=>'?'.http_build_query(['installation_id'=>$installationId,'playthrough_id'=>$id,'page'=>$page,'embed'=>$embedded?'1':'0']);
$pageUrl=static fn(int$number):string=>'?'.http_build_query(['installation_id'=>$installationId,'playthrough_id'=>$selected['playthrough_id']??'','page'=>$number,'embed'=>$embedded?'1':'0']);
$additionalStylesheets=['herika-playthroughs.css?v='.(string)filemtime(__DIR__.'/css/herika-playthroughs.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/playthrough_manager.html.php';
include __DIR__.'/tmpl/footer.html';
