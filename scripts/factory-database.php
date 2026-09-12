#!/usr/bin/env php
<?php
declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\MigrationRunner;

require dirname(__DIR__).'/lib/Autoload.php';
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only.');

// Called only by the isolated factory builder; never load the deployed server configuration.
$mode=$argv[1]??'';$socket=$argv[2]??'';
if(!in_array($mode,['build','verify','verify-sql'],true)||preg_match('~^/[^\x00-\x20;]+$~D',$socket)!==1){
    fwrite(STDERR,"Usage: php scripts/factory-database.php <build|verify|verify-sql> <private-unix-socket-directory>\n");exit(2);
}
$db=Connection::open(['database_dsn'=>'pgsql:host='.$socket.';port=5432;dbname=lorkhan_factory'.($mode==='verify-sql'?'_sql_verify':($mode==='verify'?'_verify':''))]);
$runner=new MigrationRunner($db,dirname(__DIR__).'/data/migrations');
if($mode==='verify-sql'){
    $sql=(string)file_get_contents($socket.'/factory.sql');
    // A late provisioning failure must restore both the old data and the absent factory schema.
    $db->exec("CREATE TABLE public.factory_atomic_sentinel(value text); INSERT INTO public.factory_atomic_sentinel VALUES('keep')");
    try{
        $db->beginTransaction();
        $db->exec("SET LOCAL statement_timeout='60s'; SET LOCAL lock_timeout='3s'; DROP TABLE public.factory_atomic_sentinel");
        $db->exec($sql);
        $db->exec("SET LOCAL search_path TO lorkhan_internal, public, pg_temp");
        $installation='00000000-0000-4000-8000-000000000001';
        $db->prepare("INSERT INTO installations(installation_id,token_fingerprint) VALUES(:id,repeat('a',64))")->execute(['id'=>$installation]);
        (new \LorkhanServer\Infrastructure\DefaultConnectorProvisioner($db,$socket.'/voices','http://127.0.0.1:1'))->provision($installation);
        if((int)$db->query('SELECT count(*) FROM lorkhan_internal.configuration_sets')->fetchColumn()<1)throw new RuntimeException('factory_provisioning_missing');
        if(!$db->inTransaction())throw new RuntimeException('factory_provisioning_escaped_transaction');
        $db->exec('SELECT 1/0');
    }catch(PDOException $error){
        if($error->getCode()!=='22012')throw $error;
        $db->rollBack();
    }
    if($db->query("SELECT value FROM public.factory_atomic_sentinel")->fetchColumn()!=='keep'
        ||$db->query("SELECT to_regclass('lorkhan_internal.schema_migrations')")->fetchColumn()!==null)throw new RuntimeException('factory_rollback_failed');
    $db->beginTransaction();
    $db->exec("SET LOCAL statement_timeout='60s'; SET LOCAL lock_timeout='3s'; DROP TABLE public.factory_atomic_sentinel");
    $db->exec($sql);
    unset($sql);
}
// Match deployment's bundled catalogs; fingerprint their exact sources before any import.
$root=dirname(__DIR__).'/data';
$descriptions=$root.'/descriptions/morrowind-official';$biographies=$root.'/biographies/morrowind-official';
$oghma=$root.'/oghma/morrowind-official';$active=trim((string)file_get_contents($oghma.'/active-catalog-version.txt'));
if(preg_match('/^[a-zA-Z0-9._-]+$/D',$active)!==1)throw new RuntimeException('invalid_factory_catalog_version');
$catalog=$oghma.'/catalogs/'.$active;
$catalogFingerprint=\LorkhanServer\Infrastructure\FactoryDatabaseArchive::catalogFingerprint();
if($mode==='build'){
    $runner->up();
    (new \LorkhanServer\Infrastructure\DescriptionCatalogImporter($db))->provision($descriptions.'/descriptions.csv',$descriptions.'/manifest.json',trim((string)file_get_contents($descriptions.'/catalog-version.txt')));
    (new \LorkhanServer\Infrastructure\BiographyCatalogImporter($db))->provision($biographies.'/biographies.json',$biographies.'/manifest.json',trim((string)file_get_contents($biographies.'/catalog-version.txt')));
    (new \LorkhanServer\Infrastructure\OghmaCatalogImporter($db))->apply($catalog.'/articles.json',$catalog.'/manifest.json',trim((string)file_get_contents($catalog.'/catalog-version.txt')));
}
$status=$runner->status(false);
if($status===[]||count(array_filter($status,static fn(array $row):bool=>!$row['applied']))!==0)throw new RuntimeException('factory_migrations_incomplete');
if((int)$db->query("SELECT count(*) FROM lorkhan_internal.action_catalog WHERE action_name='conversation.end'")->fetchColumn()!==1)throw new RuntimeException('factory_action_seed_missing');
if((int)$db->query('SELECT count(*) FROM lorkhan_internal.installations')->fetchColumn()!==0)throw new RuntimeException('factory_contains_installations');

// Hash every source-seeded row, not just row counts, so archive restoration must reproduce the factory data.
$seed=\LorkhanServer\Infrastructure\FactoryDatabaseArchive::seedState($db);
if($mode==='verify-sql')$db->commit();
echo json_encode(['format_version'=>2,'migration_fingerprint'=>$runner->replayFingerprint(false),'catalog_fingerprint'=>$catalogFingerprint,
    'migration_count'=>count($status)]+$seed,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
