<?php

declare(strict_types=1);

// Sandbox-only entrypoint: no deployment configuration, host DSN or credentials are loaded.
require '/SchemaEpochMismatch.php';
require '/SchemaEpoch.php';
require '/MigrationRunner.php';
require '/PlaythroughTablePolicy.php';
$db=new PDO('pgsql:host=/scratch;port=5432;dbname=imported',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
// Clean or future-epoch dumps are rejected before the legacy ledger is read or upgraded.
\LorkhanServer\Infrastructure\SchemaEpoch::assertLegacySchemaPath($db);
$internal=$db->query("SELECT to_regclass('lorkhan_internal.schema_migrations')")->fetchColumn();
$legacy=$db->query("SELECT to_regclass('public.schema_migrations')")->fetchColumn();
if($internal&&$legacy)throw new RuntimeException('ambiguous_import_migration_ledger');
if(!$internal&&!$legacy)exit(0); // The parent still rejects foreign tables and schema mismatches.
$ledger=$internal?'lorkhan_internal.schema_migrations':'public.schema_migrations';
if((int)$db->query('SELECT count(*) FROM '.$ledger)->fetchColumn()===0)
    throw new RuntimeException('empty_import_migration_ledger');
(new \LorkhanServer\Infrastructure\MigrationRunner($db,'/migrations'))->up();
