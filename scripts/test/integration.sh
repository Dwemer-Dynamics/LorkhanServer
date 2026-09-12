#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lorkhan-pg.XXXXXX")
PORT=${LORKHAN_TEST_PORT:-55439}

cleanup() {
    pg_ctl -D "$TMP/data" -m immediate stop >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT HUP INT TERM

initdb -D "$TMP/data" -A trust --no-locale -E UTF8 >/dev/null
if ! pg_ctl -D "$TMP/data" -o "-h 127.0.0.1 -k $TMP -p $PORT" -l "$TMP/postgres.log" start >/dev/null; then
    cat "$TMP/postgres.log" >&2
    exit 1
fi
createdb -h 127.0.0.1 -p "$PORT" lorkhan_test
createdb -h 127.0.0.1 -p "$PORT" lorkhan_migrations_test
mkdir "$TMP/factory"
bash "$ROOT/scripts/build-factory-database.sh" "$TMP/factory"
LORKHAN_TEST_FACTORY_DIR="$TMP/factory" \
LORKHAN_TEST_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_test" \
LORKHAN_RESPONSE_CAPTURE="${LORKHAN_RESPONSE_CAPTURE:-}" php "$ROOT/tests/integration.php"
LORKHAN_SCHEMA_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_test" \
php "$ROOT/scripts/schema-inventory.php" "${LORKHAN_SCHEMA_MODE:---check}"
pg_dump -h 127.0.0.1 -p "$PORT" -Fc -f "$TMP/lorkhan.backup" lorkhan_test
createdb -h 127.0.0.1 -p "$PORT" lorkhan_restore_test
pg_restore -h 127.0.0.1 -p "$PORT" -d lorkhan_restore_test --exit-on-error "$TMP/lorkhan.backup"
RESTORED_TABLES=$(psql -h 127.0.0.1 -p "$PORT" -d lorkhan_restore_test -Atc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'lorkhan_internal' AND table_name IN ('sessions','media_objects','durable_jobs','provider_attempts')")
[ "$RESTORED_TABLES" = "4" ] || { printf 'backup restore schema check failed\n' >&2; exit 1; }
# Uploaded SQL is read only in a namespace sandbox. Its result remains untrusted data, never restore SQL.
pg_restore --no-owner --no-privileges --file="$TMP/import.sql" "$TMP/lorkhan.backup"
bash "$ROOT/scripts/import-sql-sandbox.sh" "$TMP/import.sql" > "$TMP/import.jsonl"
python3 - "$TMP/import.jsonl" <<'PY'
import json,sys
with open(sys.argv[1],encoding='utf-8') as stream:
    records=(json.loads(line) for line in stream)
    assert next(records)=={'kind':'header','format':'lorkhan.import-data.v1'}
    tables=set(); rows=0; complete=False
    for record in records:
        assert not complete
        if record['kind']=='table': tables.add((record['schema'],record['name']))
        elif record['kind']=='row':
            assert (record['schema'],record['table']) in tables
            assert all(value is None or isinstance(value,str) for value in record['data'].values())
            rows+=1
        else:
            assert record=={'kind':'complete'}
            complete=True
    assert complete and ('lorkhan_internal','installations') in tables and rows>100
print('isolated SQL data export passed')
PY
php /dev/stdin "$ROOT" "$TMP/import.jsonl" "$PORT" <<'PHP'
<?php
require $argv[1].'/lib/Autoload.php';
$db=new PDO('pgsql:host=127.0.0.1;port='.$argv[3].';dbname=lorkhan_test','factory_runtime','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=\LorkhanServer\Infrastructure\SqlImportData::destinationTables($db);
$stream=fopen($argv[2],'rb');
try{$result=(new \LorkhanServer\Infrastructure\SqlImportData($tables))->validate($stream);}finally{fclose($stream);}
if($result['table_count']!==count($tables)||$result['row_count']<100||!hash_equals($result['sha256'],hash_file('sha256',$argv[2])))throw new RuntimeException('import_validation_failed');
echo "sandbox data matches trusted destination schema\n";
$original=(int)$db->query('SELECT count(*) FROM lorkhan_internal.source_events')->fetchColumn();
$db->beginTransaction();
try{
    $stream=fopen($argv[2],'rb');
    try{$staged=(new \LorkhanServer\Infrastructure\SqlImportData($tables))->stage($db,$stream,static function():void{});}finally{fclose($stream);}
    foreach($staged['tables'] as $key=>$temporary){
        [$schema,$name]=explode('.',$key,2);
        $quote=static fn(string $value):string=>'"'.str_replace('"','""',$value).'"';
        $destination=$quote($schema).'.'.$quote($name);
        if($db->query('SELECT EXISTS((SELECT to_jsonb(t)::text FROM '.$temporary.' t EXCEPT ALL SELECT to_jsonb(t)::text FROM '.$destination.' t) UNION ALL (SELECT to_jsonb(t)::text FROM '.$destination.' t EXCEPT ALL SELECT to_jsonb(t)::text FROM '.$temporary.' t))')->fetchColumn())throw new RuntimeException('typed_import_values_changed');
    }
    if((int)$db->query('SELECT count(*) FROM lorkhan_internal.source_events')->fetchColumn()!==$original)throw new RuntimeException('staging_modified_destination');
}finally{$db->rollBack();}
if($db->query("SELECT EXISTS(SELECT 1 FROM pg_class WHERE relnamespace=pg_my_temp_schema() AND relname LIKE 'sql_import_%')")->fetchColumn())throw new RuntimeException('staging_survived_rollback');
echo "typed SQL import staging matches all source rows and rolls back cleanly\n";
$bad=fopen('php://temp','w+b');
fwrite($bad,json_encode(['kind'=>'header','format'=>'lorkhan.import-data.v1'])."\n");
foreach($tables as $key=>$columns){
    [$schema,$name]=explode('.',$key,2);
    fwrite($bad,json_encode(['kind'=>'table','schema'=>$schema,'name'=>$name,'columns'=>$columns])."\n");
    if($key==='lorkhan_internal.installations'){
        $values=array_fill_keys($columns,null);$values['installation_id']='not-a-uuid';
        fwrite($bad,json_encode(['kind'=>'row','schema'=>$schema,'table'=>$name,'data'=>$values])."\n");
    }
}
fwrite($bad,json_encode(['kind'=>'complete'])."\n");
$db->beginTransaction();$rejected=false;
try{(new \LorkhanServer\Infrastructure\SqlImportData($tables))->stage($db,$bad,static function():void{});}
catch(PDOException $error){if($error->getCode()!=='22P02')throw $error;$rejected=true;}
finally{$db->rollBack();fclose($bad);}
if(!$rejected||(int)$db->query('SELECT count(*) FROM lorkhan_internal.source_events')->fetchColumn()!==$original
    ||$db->query("SELECT EXISTS(SELECT 1 FROM pg_class WHERE relnamespace=pg_my_temp_schema() AND relname LIKE 'sql_import_%')")->fetchColumn())throw new RuntimeException('invalid_import_type_not_rolled_back');
echo "invalid imported UUID rejected without destination changes\n";
$db->beginTransaction();$cancelled=false;$ticks=0;$stream=fopen($argv[2],'rb');
try{(new \LorkhanServer\Infrastructure\SqlImportData($tables))->stage($db,$stream,static function()use(&$ticks):bool{return ++$ticks<3;});}
catch(RuntimeException $error){if($error->getMessage()!=='lease_lost')throw $error;$cancelled=true;}
finally{$db->rollBack();fclose($stream);}
if(!$cancelled||$ticks!==3||(int)$db->query('SELECT count(*) FROM lorkhan_internal.source_events')->fetchColumn()!==$original
    ||$db->query("SELECT EXISTS(SELECT 1 FROM pg_class WHERE relnamespace=pg_my_temp_schema() AND relname LIKE 'sql_import_%')")->fetchColumn())throw new RuntimeException('cancelled_import_not_rolled_back');
echo "cancelled SQL staging stops and rolls back cleanly\n";
PHP
LORKHAN_TEST_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_migrations_test" \
php "$ROOT/tests/migrations_jobs.php"
