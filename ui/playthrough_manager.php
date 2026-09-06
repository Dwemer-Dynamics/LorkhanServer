<?php
declare(strict_types=1);
$pageTitle='Playthrough Manager';$topNavSection='control';$BODY_CLASS='hub-page playthrough-shell';
require __DIR__.'/ui_bootstrap.php';
$installations=$uiRepository->rows('installations');
$installationIds=array_column($installations,'installation_id');
$installationId=(string)($_GET['installation_id']??($installationIds[0]??''));
if(!in_array($installationId,$installationIds,true))$installationId=(string)($installationIds[0]??'');
$rows=$installationId===''?[]:$uiRepository->rows('playthroughs',null,['installation_id'=>$installationId]);
$selected=null;$playthroughId=(string)($_GET['playthrough_id']??'');
foreach($rows as$row)if($row['playthrough_id']===$playthroughId){$selected=$row;break;}
$selected??=$rows[0]??null;
$profiles=array_values(array_filter(array_merge($uiRepository->rows('characters'),$uiRepository->rows('player')),static fn(array$row):bool=>$row['installation_id']===$installationId));
$playthroughUrl=static fn(string$id):string=>'?'.http_build_query(['installation_id'=>$installationId,'playthrough_id'=>$id,'embed'=>$embedded?'1':'0']);
$additionalStylesheets=['herika-playthroughs.css?v='.(string)filemtime(__DIR__.'/css/herika-playthroughs.css')];
include __DIR__.'/tmpl/head.html';if(!$embedded)include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/playthrough_manager.html.php';
include __DIR__.'/tmpl/footer.html';
