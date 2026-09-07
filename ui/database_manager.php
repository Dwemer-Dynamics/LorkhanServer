<?php
declare(strict_types=1);
$pageTitle='Database Manager'; $topNavSection='control'; $BODY_CLASS='hub-page database-manager-shell';
require __DIR__.'/ui_bootstrap.php';
require __DIR__.'/tmpl/control_reader.php';
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
include __DIR__.'/tmpl/footer.html';
