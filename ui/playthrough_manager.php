<?php
declare(strict_types=1);
$pageTitle='Playthrough Manager';$topNavSection='control';$BODY_CLASS='hub-page playthrough-shell';
require __DIR__.'/ui_bootstrap.php';
$installations=$uiRepository->rows('installations');
$installationIds=array_column($installations,'installation_id');
$installationId=(string)($_GET['installation_id']??($installationIds[0]??''));
if(!in_array($installationId,$installationIds,true))$installationId=(string)($installationIds[0]??'');
$characterState=$installationId===''?['bindings'=>[],'current'=>null]:(new \LorkhanServer\Infrastructure\CharacterPlaythroughRepository($database))->state($installationId);
$activePlaythroughId=$characterState['current']['playthrough_id']??null;
$backupSettings=$installationId===''?null:$productRepository->globalSettingsForInstallation($installationId);
$dragonBreakDays=$backupSettings['content']['backup']['dragon_break_days']??3;
$tablePolicy=$managementRepository->playthroughTablePolicy();
$page=max(1,min(100000,(int)($_GET['page']??1)));
$rows=$installationId===''?[]:$uiRepository->rows('playthroughs',null,['installation_id'=>$installationId,'page'=>$page]);
$hasNextPage=count($rows)>100;$rows=array_slice($rows,0,100);
$selected=null;$playthroughId=(string)($_GET['playthrough_id']??$activePlaythroughId??'');
foreach($rows as$row)if($row['playthrough_id']===$playthroughId){$selected=$row;break;}
if($selected===null&&\LorkhanServer\Infrastructure\Uuid::isValid($playthroughId)) {
    $selected=$uiRepository->rows('playthroughs',null,['installation_id'=>$installationId,'selected_id'=>$playthroughId])[0]??null;
}
$selected??=$rows[0]??null;
$profiles=array_values(array_filter(array_merge($uiRepository->rows('characters'),$uiRepository->rows('player')),static fn(array$row):bool=>$row['installation_id']===$installationId));
$playthroughUrl=static fn(string$id):string=>'?'.http_build_query(['installation_id'=>$installationId,'playthrough_id'=>$id,'page'=>$page,'embed'=>$embedded?'1':'0']);
$pageUrl=static fn(int$number):string=>'?'.http_build_query(['installation_id'=>$installationId,'playthrough_id'=>$selected['playthrough_id']??'','page'=>$number,'embed'=>$embedded?'1':'0']);
$liveDatabase=$uiRepository->dashboard();
$snapshotLiveCalendar=\LorkhanServer\Application\MorrowindCalendar::parse($liveDatabase['current']['calendar_data']??null);
$snapshotTimeline=[];
$snapshotPage=max(1,min(100000,(int)($_GET['snapshot_page']??1)));
if ($selected) {
    try {
        $managementRepository->playthroughSaves()->capture($installationId,$selected['playthrough_id'],'default',
            'Initial gameplay save.','default','default:'.$selected['playthrough_id']);
    } catch (Throwable) { $initialSaveError=true; }
}
$snapshotQuery=$database->prepare("SELECT save_id AS backup_id,name,notes,created_at,octet_length(document) AS byte_count,metadata AS game_metadata,kind='dragon_break' AS dragon_break,kind='before_copy' AS rollback_for,kind FROM playthrough_saves WHERE installation_id=:installation ORDER BY created_at DESC,save_id DESC LIMIT 26 OFFSET ".(($snapshotPage-1)*25));
$storedSnapshots=[];
if ($installationId!=='') {
    $snapshotQuery->execute(['installation'=>$installationId]);
    $storedSnapshots=$snapshotQuery->fetchAll(PDO::FETCH_ASSOC);
}
$snapshotsHasNext=count($storedSnapshots)>25;$storedSnapshots=array_slice($storedSnapshots,0,25);
foreach ($storedSnapshots as $point) {
    $metadata=json_decode($point['game_metadata'],true);
    $calendar=\LorkhanServer\Application\MorrowindCalendar::parse($metadata['calendar']??null);
    if ($calendar!==null) $snapshotTimeline[]=['name'=>$point['name'],'minute'=>$calendar['minute'],'date'=>$calendar['label'],'created'=>$point['created_at'],'bytes'=>(int)$point['byte_count'],'active'=>false];
}
$snapshotPageUrl=static fn(int $number):string=>'?'.http_build_query(['installation_id'=>$installationId,'playthrough_id'=>$playthroughId,'snapshot_page'=>$number,'embed'=>$embedded?'1':'0']);
$additionalStylesheets=['herika-playthroughs.css?v='.(string)filemtime(__DIR__.'/css/herika-playthroughs.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/playthrough_manager.html.php';
include __DIR__.'/tmpl/footer.html';
