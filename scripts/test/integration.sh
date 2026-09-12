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
# A deleted allocation can leave the exported sequence far above every surviving row.
psql -h 127.0.0.1 -p "$PORT" -d lorkhan_test -v ON_ERROR_STOP=1 -Atc "SELECT setval(pg_get_serial_sequence('lorkhan_internal.durable_job_attempts','attempt_id'),9007199254740993,true)" >/dev/null
pg_dump -h 127.0.0.1 -p "$PORT" -Fc -f "$TMP/lorkhan.backup" lorkhan_test
createdb -h 127.0.0.1 -p "$PORT" lorkhan_restore_test
pg_restore -h 127.0.0.1 -p "$PORT" -d lorkhan_restore_test --exit-on-error "$TMP/lorkhan.backup"
RESTORED_TABLES=$(psql -h 127.0.0.1 -p "$PORT" -d lorkhan_restore_test -Atc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'lorkhan_internal' AND table_name IN ('sessions','media_objects','durable_jobs','provider_attempts')")
[ "$RESTORED_TABLES" = "4" ] || { printf 'backup restore schema check failed\n' >&2; exit 1; }
# Uploaded SQL is read only in a namespace sandbox. Its result remains untrusted data, never restore SQL.
pg_restore --no-owner --no-privileges --file="$TMP/import.sql" "$TMP/lorkhan.backup"
python3 "$ROOT/scripts/sql-import-capture.py" "$TMP/import.sql" "$TMP/import.jsonl"
python3 - "$ROOT" "$TMP" <<'PY'
import importlib.util,os,pathlib,subprocess,sys,time
sys.dont_write_bytecode=True
spec=importlib.util.spec_from_file_location('import_capture',sys.argv[1]+'/scripts/sql-import-capture.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
root=pathlib.Path(sys.argv[2]);target=root/'capture-probe.jsonl'
assert module.capture([sys.executable,'-c','import os;os.write(1,b"exact")'],target,max_bytes=5)==5
assert target.read_bytes()==b'exact' and target.stat().st_mode&0o777==0o400
try: module.capture([sys.executable,'-c','raise SystemExit(99)'],target)
except FileExistsError: pass
else: raise AssertionError('capture overwrote an existing destination')
assert target.read_bytes()==b'exact';target.unlink()
for source,settings,expected in [
    ('import os;os.write(1,b"123456")',{'max_bytes':5},'import_capture_output_limit'),
    ('import os;os.write(2,b"123456")',{'max_stderr':5},'import_capture_diagnostic_limit'),
    ('import os;os.write(1,b"partial");raise SystemExit(1)',{},'import_capture_failed'),
    ('pass',{},'import_capture_empty'),
    ('import time;time.sleep(60)',{'timeout':0.1},'import_capture_timeout'),
    ('import os,time;os.close(1);os.close(2);time.sleep(60)',{'timeout':0.1},'import_capture_timeout'),
    ('import os,time;pid=os.fork();os._exit(0) if pid else time.sleep(60)',{'timeout':0.2},'import_capture_timeout'),
]:
    try: module.capture([sys.executable,'-c',source],target,**settings)
    except RuntimeError as error: assert str(error)==expected,(str(error),expected)
    else: raise AssertionError(expected)
    assert not target.exists() and not list(root.glob('.sql-import-*.partial'))
print('bounded SQL capture rejects overflow, failed/empty output and hung process trees without partial publication')
# Exercise the actual CLI signal handler; InterruptedError is swallowed by selectors.
source=root/'capture-cancel.sql';source.write_text('SELECT pg_sleep(60);\n')
process=subprocess.Popen([sys.executable,sys.argv[1]+'/scripts/sql-import-capture.py',str(source),str(target)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
try:
    deadline=time.monotonic()+10
    while not list(root.glob('.sql-import-*.partial')):
        assert process.poll() is None and time.monotonic()<deadline,'capture did not start'
        time.sleep(.05)
    time.sleep(.5);assert process.poll() is None
    process.terminate();output,diagnostic=process.communicate(timeout=10)
    assert process.returncode==1 and output==b'' and diagnostic==b'isolated_sql_capture_failed\n'
    assert not target.exists() and not list(root.glob('.sql-import-*.partial'))
    time.sleep(.2)
    for item in pathlib.Path('/proc').glob('[0-9]*/cmdline'):
        try: command=item.read_bytes()
        except (PermissionError,FileNotFoundError,ProcessLookupError): continue
        assert str(source).encode() not in command,'sandbox survived cancellation'
finally:
    if process.poll() is None: process.kill();process.wait()
    source.unlink()
print('actual SQL capture cancellation removes partial output and the sandbox process tree')
PY
python3 - "$TMP/import.jsonl" <<'PY'
import json,sys
with open(sys.argv[1],encoding='utf-8') as stream:
    records=(json.loads(line) for line in stream)
    assert next(records)=={'kind':'header','format':'lorkhan.import-data.v2'}
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
$sequences=\LorkhanServer\Infrastructure\SqlImportData::destinationSequences($db);
$stream=fopen($argv[2],'rb');
try{$result=(new \LorkhanServer\Infrastructure\SqlImportData($tables,$sequences))->validate($stream);}finally{fclose($stream);}
if($result['table_count']!==count($tables)||$result['row_count']<100||!hash_equals($result['sha256'],hash_file('sha256',$argv[2])))throw new RuntimeException('import_validation_failed');
if(($result['sequence_states']['lorkhan_internal.durable_job_attempts']['attempt_id']??null)!==['last_value'=>'9007199254740993','is_called'=>true])throw new RuntimeException('import_sequence_precision_lost');
$db->exec("SELECT setval(pg_get_serial_sequence('lorkhan_internal.durable_job_attempts','attempt_id'),GREATEST(1,(SELECT max(attempt_id)+1 FROM lorkhan_internal.durable_job_attempts)),false)");
echo "sandbox data matches trusted destination schema\n";
$original=(int)$db->query('SELECT count(*) FROM lorkhan_internal.source_events')->fetchColumn();
$db->beginTransaction();
try{
    $stream=fopen($argv[2],'rb');
    try{$staged=(new \LorkhanServer\Infrastructure\SqlImportData($tables,$sequences))->stage($db,$stream,static function():void{});}finally{fclose($stream);}
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
fwrite($bad,json_encode(['kind'=>'header','format'=>'lorkhan.import-data.v2'])."\n");
foreach($tables as $key=>$columns){
    [$schema,$name]=explode('.',$key,2);
    $states=[];foreach($sequences[$key]??[] as $column)$states[$column]=['last_value'=>'1','is_called'=>false];
    fwrite($bad,json_encode(['kind'=>'table','schema'=>$schema,'name'=>$name,'columns'=>$columns,'sequences'=>(object)$states])."\n");
    if($key==='lorkhan_internal.installations'){
        $values=array_fill_keys($columns,null);$values['installation_id']='not-a-uuid';
        fwrite($bad,json_encode(['kind'=>'row','schema'=>$schema,'table'=>$name,'data'=>$values])."\n");
    }
}
fwrite($bad,json_encode(['kind'=>'complete'])."\n");
$db->beginTransaction();$rejected=false;
try{(new \LorkhanServer\Infrastructure\SqlImportData($tables,$sequences))->stage($db,$bad,static function():void{});}
catch(PDOException $error){if($error->getCode()!=='22P02')throw $error;$rejected=true;}
finally{$db->rollBack();fclose($bad);}
if(!$rejected||(int)$db->query('SELECT count(*) FROM lorkhan_internal.source_events')->fetchColumn()!==$original
    ||$db->query("SELECT EXISTS(SELECT 1 FROM pg_class WHERE relnamespace=pg_my_temp_schema() AND relname LIKE 'sql_import_%')")->fetchColumn())throw new RuntimeException('invalid_import_type_not_rolled_back');
echo "invalid imported UUID rejected without destination changes\n";
$db->beginTransaction();$cancelled=false;$ticks=0;$stream=fopen($argv[2],'rb');
try{(new \LorkhanServer\Infrastructure\SqlImportData($tables,$sequences))->stage($db,$stream,static function()use(&$ticks):bool{return ++$ticks<3;});}
catch(RuntimeException $error){if($error->getMessage()!=='lease_lost')throw $error;$cancelled=true;}
finally{$db->rollBack();fclose($stream);}
if(!$cancelled||$ticks!==3||(int)$db->query('SELECT count(*) FROM lorkhan_internal.source_events')->fetchColumn()!==$original
    ||$db->query("SELECT EXISTS(SELECT 1 FROM pg_class WHERE relnamespace=pg_my_temp_schema() AND relname LIKE 'sql_import_%')")->fetchColumn())throw new RuntimeException('cancelled_import_not_rolled_back');
echo "cancelled SQL staging stops and rolls back cleanly\n";
$db->exec('SET search_path TO lorkhan_internal,public,pg_temp');
$importJob=\LorkhanServer\Infrastructure\Uuid::v4();$jobs=new \LorkhanServer\Infrastructure\JobRepository($db);
$jobs->enqueue($importJob,'database.import',1,$importJob,[],1);$claimed=$jobs->claim('import-apply-fixture',1,3600,['database.import']);
if(count($claimed)!==1||$claimed[0]['job_id']!==$importJob)throw new RuntimeException('import_job_fixture_missing');
$bioHash=static fn():string=>(string)$db->query("SELECT md5(COALESCE(string_agg(md5(to_jsonb(t)::text),'' ORDER BY to_jsonb(t)::text COLLATE \"C\"),'')) FROM public.bio_templates t")->fetchColumn();
$controlHash=static fn():string=>(string)$db->query("SELECT md5(COALESCE((SELECT jsonb_agg(to_jsonb(t) ORDER BY installation_id)::text FROM installations t),'')||COALESCE((SELECT jsonb_agg(to_jsonb(t) ORDER BY pairing_token_id)::text FROM pairing_tokens t),'')||COALESCE((SELECT jsonb_agg(to_jsonb(t) ORDER BY session_hash)::text FROM browser_sessions t),''))")->fetchColumn();
$originalBio=$bioHash();$originalControl=$controlHash();
$db->exec("UPDATE public.bio_templates SET core='post-export import fixture' WHERE npc_name=(SELECT npc_name FROM public.bio_templates ORDER BY npc_name LIMIT 1)");
$changedBio=$bioHash();if($changedBio===$originalBio)throw new RuntimeException('import_fixture_not_changed');
$attemptSequence=$db->query("SELECT pg_get_serial_sequence('lorkhan_internal.durable_job_attempts','attempt_id')")->fetchColumn();
$sequenceState=$db->query('SELECT last_value,is_called FROM '.$attemptSequence)->fetch(PDO::FETCH_ASSOC);
$currentAttempt=$db->query('SELECT attempt_id FROM durable_job_attempts WHERE job_id='.$db->quote($importJob))->fetchColumn();
foreach(['schema','foreign_key','sequence','late','success'] as $phase){
    $db->beginTransaction();$stream=fopen($argv[2],'rb');
    try{
        $data=new \LorkhanServer\Infrastructure\SqlImportData($tables,$sequences);$staged=$data->stage($db,$stream,static function():void{});
        if($phase==='schema')$db->exec('UPDATE '.$staged['tables']['lorkhan_internal.schema_migrations']." SET checksum=repeat('0',64) WHERE version=(SELECT max(version) FROM ".$staged['tables']['lorkhan_internal.schema_migrations'].')');
        if($phase==='foreign_key')$db->exec('UPDATE '.$staged['tables']['lorkhan_internal.profiles']." SET installation_id='00000000-0000-4000-8000-000000000999'");
        if($phase==='sequence')$staged['summary']['sequence_states']['lorkhan_internal.durable_job_attempts']['attempt_id']['last_value']='9223372036854775808';
        $history=$db->query('SELECT job_id,attempt_number FROM '.$staged['tables']['lorkhan_internal.durable_job_attempts'].' ORDER BY attempt_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if(!$history)throw new RuntimeException('import_history_fixture_missing');
        $db->exec('UPDATE '.$staged['tables']['lorkhan_internal.durable_jobs']." SET state='queued',completed_at=NULL,lease_owner=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,heartbeat_at=NULL WHERE job_id=".$db->quote($history['job_id']));
        $db->exec('UPDATE '.$staged['tables']['lorkhan_internal.durable_job_attempts'].' SET attempt_id='.(int)$currentAttempt.' WHERE attempt_id=(SELECT min(attempt_id) FROM '.$staged['tables']['lorkhan_internal.durable_job_attempts'].')');
        $state=new \LorkhanServer\Infrastructure\MigrationReplayState($db,$importJob);$state->capture();
        $data->replaceStagedRows($db,$staged,static function()use($state):void{$state->restore(true);},static function():void{});
        if($bioHash()!==$originalBio||$controlHash()!==$originalControl)throw new RuntimeException('import_replacement_or_control_failed');
        $historyCheck=$db->prepare('SELECT count(*) FROM durable_job_attempts WHERE job_id=:job AND attempt_number=:attempt');
        $historyCheck->execute(['job'=>$history['job_id'],'attempt'=>$history['attempt_number']]);
        if((int)$historyCheck->fetchColumn()!==1||(int)$db->query('SELECT attempt_id FROM durable_job_attempts WHERE job_id='.$db->quote($importJob))->fetchColumn()===(int)$currentAttempt)throw new RuntimeException('import_attempt_history_overwritten');
        if($db->query('SELECT state FROM durable_jobs WHERE job_id='.$db->quote($history['job_id']))->fetchColumn()!=='dead')throw new RuntimeException('import_resumed_historical_work');
        if($db->query('SELECT last_value::text FROM '.$attemptSequence)->fetchColumn()!=='9007199254740994')throw new RuntimeException('import_sequence_floor_lost');
        if($phase==='late')$db->exec('SELECT 1/0');
        if($phase!=='success')throw new RuntimeException('import_failure_not_reached');
        $db->commit();
    }catch(RuntimeException $error){
        $expectedError=['schema'=>'import_compatibility_mismatch','foreign_key'=>'23503','sequence'=>'import_sequence_exhausted','late'=>'22012'][$phase]??'';
        if(($error instanceof PDOException?$error->getCode():$error->getMessage())!==$expectedError)throw $error;
        $db->rollBack();
    }finally{if($db->inTransaction())$db->rollBack();fclose($stream);}
    if($bioHash()!==($phase==='success'?$originalBio:$changedBio)||$controlHash()!==$originalControl)throw new RuntimeException('import_atomicity_failed');
    if($phase!=='success'&&$db->query('SELECT last_value,is_called FROM '.$attemptSequence)->fetch(PDO::FETCH_ASSOC)!==$sequenceState)throw new RuntimeException('import_rollback_changed_sequence');
}
$jobs->succeed($importJob,$claimed[0]['lease_token']);
echo "SQL data replacement preserves control state and rolls back late failures\n";
PHP
LORKHAN_SCHEMA_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_test" \
LORKHAN_TEST_DB_USER=factory_runtime php "$ROOT/scripts/schema-inventory.php" --check
LORKHAN_TEST_DSN="pgsql:host=127.0.0.1;port=$PORT;dbname=lorkhan_migrations_test" \
php "$ROOT/tests/migrations_jobs.php"
