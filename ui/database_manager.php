<?php
declare(strict_types=1);
$pageTitle='Database Manager'; $topNavSection='control'; $BODY_CLASS='hub-page database-manager-shell';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
// Administration routing belongs to deployment configuration, never browser input or saved NPC settings.
$databaseAdminUrl=$config['database_admin_url']??'';
$adminParts=is_string($databaseAdminUrl)&&strlen($databaseAdminUrl)<=2048&&filter_var($databaseAdminUrl,FILTER_VALIDATE_URL)!==false?parse_url($databaseAdminUrl):false;
if($adminParts===false||!isset($adminParts['host'])||isset($adminParts['user'])||isset($adminParts['pass'])||isset($adminParts['query'])||isset($adminParts['fragment'])
    ||!in_array(strtolower($adminParts['scheme']??''),['http','https'],true)
    ||(strtolower($adminParts['scheme'])==='http'&&!in_array(strtolower($adminParts['host']),['127.0.0.1','localhost','[::1]'],true)))$databaseAdminUrl='';
$maintenanceJob=(new \LorkhanServer\Infrastructure\ManagementRepository($database))->databaseMaintenanceStatus();
$sqlBackupJob=(new \LorkhanServer\Infrastructure\ManagementRepository($database))->databaseMaintenanceStatus('database.backup');
$sqlRestoreJob=(new \LorkhanServer\Infrastructure\ManagementRepository($database))->databaseMaintenanceStatus('database.restore');
$replayRepository=new \LorkhanServer\Infrastructure\ManagementRepository($database);
$replayJob=$replayRepository->databaseMaintenanceStatus('database.replay');
$factoryJob=$replayRepository->databaseMaintenanceStatus('database.factory_reset');
$factoryPlan=null;
try{$factoryPlan=$replayRepository->databaseFactoryPlan($config);}catch(\RuntimeException $error){/* Reset stays unavailable unless the private artifact matches this deployment. */}
$replayPlan=null;
try{$replayPlan=$replayRepository->databaseReplayPlan();}catch(\RuntimeException $error){/* A drifted ledger must remain inspectable, but cannot be replayed. */}
$automaticBackupSettings=(new \LorkhanServer\Infrastructure\ManagementRepository($database))->databaseBackupSettings();
$automaticBackupStats=$database->query("SELECT count(*) AS count,COALESCE(sum(byte_count+COALESCE((scope->>'archive_bytes')::bigint,0)),0) AS bytes FROM backup_records WHERE scope->>'kind'='database_sql' AND scope->>'automatic'='true'")->fetch(PDO::FETCH_ASSOC);
$sqlPage=max(1,min(100000,(int)($_GET['sql_page']??1)));
$sqlBackups=$database->query("SELECT backup_id,format_version,byte_count,state,scope->>'automatic' AS automatic,scope->>'rollback_for' AS rollback_for,to_char(created_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') AS created_utc FROM backup_records WHERE scope->>'kind'='database_sql' ORDER BY created_at DESC,backup_id DESC LIMIT 26 OFFSET ".(($sqlPage-1)*25))->fetchAll(PDO::FETCH_ASSOC);
$sqlHasNext=count($sqlBackups)>25;$sqlBackups=array_slice($sqlBackups,0,25);
$sqlCanRestore=count(array_filter($sqlBackups,static fn(array $row):bool=>(int)$row['format_version']>=2))>0;
$sqlPageUrl=static fn(int $page):string=>'?'.http_build_query(['sql_page'=>$page,'embed'=>$embedded?'1':'0']).'#sql-backups';
$installations=$uiRepository->rows('installations');
$state=lorkhan_control_state($installations);
$state['installation']=''; $state['period']='all'; $state['since']=null;
$state['query']=''; $state['state']=''; $state['limit']=25;
// Filter the backup kind before paging so unrelated records cannot hide configuration backups.
$state=lorkhan_control_query($database,$state,
    "backup_id,state,format_version,byte_count,scope->>'installation_id' AS installation_id,
        to_char(created_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') AS created_utc,
        to_char(restored_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') AS restored_utc",
    'FROM backup_records',["scope->>'kind'='configuration'"],[],'created_at DESC,backup_id DESC');
$configurationBackups=$state['rows'];
$migrations=$database->query("SELECT version,name,checksum,to_char(applied_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS') AS applied_utc
    FROM lorkhan_internal.schema_migrations ORDER BY version DESC")->fetchAll(PDO::FETCH_ASSOC);
$additionalStylesheets=['database-manager.css?v='.(string)filemtime(__DIR__.'/css/database-manager.css')];
include __DIR__.'/tmpl/head.html'; if (!$embedded) include __DIR__.'/tmpl/navbar.php';
include __DIR__.'/tmpl/database_manager.html.php';
?><script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/database-maintenance.js?v=<?= (string)filemtime(__DIR__.'/js/database-maintenance.js') ?>"></script><?php
include __DIR__.'/tmpl/footer.html';
