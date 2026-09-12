<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Validate sandbox output as data only; this class never executes or constructs uploaded SQL. */
final class SqlImportData
{
    public const MAX_BYTES=1073741824;
    public const MAX_LINE_BYTES=8388608;

    public function __construct(private readonly array $tables,private readonly array $sequences=[]) {}

    /** Identify sequence-owning columns from the destination, never from uploaded identifiers. */
    public static function destinationSequences(PDO $db):array
    {
        $rows=$db->query("SELECT n.nspname||'.'||r.relname AS relation,a.attname
            FROM pg_class r JOIN pg_namespace n ON n.oid=r.relnamespace JOIN pg_attribute a ON a.attrelid=r.oid AND a.attnum>0 AND NOT a.attisdropped
            WHERE n.nspname IN ('public','lorkhan_internal') AND r.relkind IN ('r','p') AND pg_get_serial_sequence(format('%I.%I',n.nspname,r.relname),a.attname) IS NOT NULL
            AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=r.oid AND d.deptype='e') ORDER BY n.nspname,r.relname,a.attname")->fetchAll(PDO::FETCH_ASSOC);
        $result=[];
        foreach($rows as $row)$result[$row['relation']][]=$row['attname'];
        return $result;
    }

    /** Read the destination's trusted base-table columns before examining any imported data. */
    public static function destinationTables(PDO $db):array
    {
        $rows=$db->query("SELECT n.nspname AS schema,c.relname AS name,jsonb_agg(a.attname ORDER BY a.attnum)::text AS columns
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            JOIN pg_attribute a ON a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped
            WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p')
            AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.deptype='e')
            GROUP BY n.nspname,c.relname ORDER BY n.nspname,c.relname")->fetchAll(PDO::FETCH_ASSOC);
        $tables=[];
        foreach($rows as $row)$tables[$row['schema'].'.'.$row['name']]=json_decode($row['columns'],true,32,JSON_THROW_ON_ERROR);
        return $tables;
    }

    /** Inspect the entire seekable quarantine stream before a caller begins any database mutation. */
    public function validate($stream):array
    {
        if(!is_resource($stream)||get_resource_type($stream)!=='stream'||!stream_get_meta_data($stream)['seekable']||!rewind($stream))
            throw new RuntimeException('import_stream_invalid');
        $hash=hash_init('sha256');$bytes=0;$rows=0;$seen=[];$sequenceStates=[];$active=null;$header=false;$complete=false;
        while(($line=fgets($stream,self::MAX_LINE_BYTES+1))!==false){
            $bytes+=strlen($line);
            if($bytes>self::MAX_BYTES||strlen($line)>self::MAX_LINE_BYTES||!str_ends_with($line,"\n"))throw new RuntimeException('import_stream_limit');
            hash_update($hash,$line);
            try{$record=json_decode($line,false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('import_record_invalid');}
            if(!$record instanceof \stdClass||$complete)throw new RuntimeException('import_record_invalid');
            $keys=array_keys(get_object_vars($record));sort($keys);
            if(!$header){
                if($keys!==['format','kind']||$record->kind!=='header'||$record->format!=='lorkhan.import-data.v2')throw new RuntimeException('import_header_invalid');
                $header=true;continue;
            }
            if(($record->kind??null)==='complete'){
                if($keys!==['kind']||count($seen)!==count($this->tables))throw new RuntimeException('import_tables_incomplete');
                $complete=true;continue;
            }
            if(($record->kind??null)==='table'){
                if($keys!==['columns','kind','name','schema','sequences']||!is_string($record->schema)||!is_string($record->name)
                    ||!in_array($record->schema,['public','lorkhan_internal'],true))throw new RuntimeException('import_table_invalid');
                $active=$record->schema.'.'.$record->name;
                $activeSchema=$record->schema;$activeName=$record->name;
                if(!isset($this->tables[$active])||isset($seen[$active])||$record->columns!==$this->tables[$active])throw new RuntimeException('import_schema_mismatch');
                if(!$record->sequences instanceof \stdClass)throw new RuntimeException('import_sequence_invalid');
                $states=get_object_vars($record->sequences);$sequenceColumns=array_keys($states);sort($sequenceColumns);
                if($sequenceColumns!==($this->sequences[$active]??[]))throw new RuntimeException('import_sequence_mismatch');
                foreach($states as $column=>$state){
                    if(!$state instanceof \stdClass)throw new RuntimeException('import_sequence_invalid');
                    $stateKeys=array_keys(get_object_vars($state));sort($stateKeys);
                    if($stateKeys!==['is_called','last_value']||!is_bool($state->is_called)||!is_string($state->last_value)
                        ||preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D',$state->last_value)!==1)throw new RuntimeException('import_sequence_invalid');
                    $sequenceStates[$active][$column]=['last_value'=>$state->last_value,'is_called'=>$state->is_called];
                }
                $seen[$active]=true;continue;
            }
            if(($record->kind??null)!=='row'||$keys!==['data','kind','schema','table']||!is_string($record->schema)||!is_string($record->table)
                ||$active===null||$record->schema!==$activeSchema||$record->table!==$activeName||!$record->data instanceof \stdClass)throw new RuntimeException('import_row_invalid');
            $values=get_object_vars($record->data);$columns=array_keys($values);
            if(count($columns)!==count($this->tables[$active])||array_diff($columns,$this->tables[$active])!==[])throw new RuntimeException('import_columns_mismatch');
            foreach($values as $value)if($value!==null&&(!is_string($value)||str_contains($value,"\0")))throw new RuntimeException('import_value_invalid');
            ++$rows;
        }
        if(!feof($stream)||!$header||!$complete)throw new RuntimeException('import_stream_incomplete');
        rewind($stream);
        return ['sha256'=>hash_final($hash),'byte_count'=>$bytes,'table_count'=>count($seen),'row_count'=>$rows,'sequence_states'=>$sequenceStates];
    }

    /** Stage typed values inside the caller's transaction, without changing destination rows or executing imported DDL. */
    public function stage(PDO $db,$stream,callable $progress):array
    {
        if(!$db->inTransaction())throw new RuntimeException('import_transaction_required');
        $expected=$this->validate($stream);
        if(self::destinationTables($db)!==$this->tables)throw new RuntimeException('import_destination_changed');
        if(self::destinationSequences($db)!==$this->sequences)throw new RuntimeException('import_destination_changed');
        $tick=static function()use($progress):void{if($progress()===false)throw new RuntimeException('lease_lost');};
        $quote=static fn(string $name):string=>'"'.str_replace('"','""',$name).'"';
        $staged=[];$insert=null;$columns=[];$rows=0;$hash=hash_init('sha256');
        while(($line=fgets($stream,self::MAX_LINE_BYTES+1))!==false){
            hash_update($hash,$line);
            $record=json_decode($line,true,32,JSON_THROW_ON_ERROR);
            if($record['kind']==='table'){
                $tick();
                $key=$record['schema'].'.'.$record['name'];
                // Resolve identifiers only through the trusted destination map, never by interpolating row content.
                if(!isset($this->tables[$key])||isset($staged[$key]))throw new RuntimeException('import_schema_mismatch');
                [$schema,$name]=explode('.',$key,2);
                $temporary='sql_import_'.substr(hash('sha256',$key),0,32);
                $columns=$this->tables[$key];$columnSql=implode(',',array_map($quote,$columns));
                $db->exec('CREATE TEMP TABLE '.$quote($temporary).' (LIKE '.$quote($schema).'.'.$quote($name).' INCLUDING DEFAULTS INCLUDING CONSTRAINTS) ON COMMIT DROP');
                $staged[$key]='pg_temp.'.$quote($temporary);
                $insert=$db->prepare('INSERT INTO '.$staged[$key].' ('.$columnSql.') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
            }elseif($record['kind']==='row'){
                if($insert===null||!isset($key)||$record['schema']!==$schema||$record['table']!==$name)throw new RuntimeException('import_row_invalid');
                foreach($columns as $index=>$column){
                    if(!array_key_exists($column,$record['data'])||($record['data'][$column]!==null&&!is_string($record['data'][$column])))throw new RuntimeException('import_value_invalid');
                    $insert->bindValue($index+1,$record['data'][$column],$record['data'][$column]===null?PDO::PARAM_NULL:PDO::PARAM_STR);
                }
                $insert->execute();
                if(++$rows%1024===0)$tick();
            }
        }
        // A changed quarantine file cannot be committed even if its replacement values happened to type-check.
        if(!feof($stream)||$rows!==$expected['row_count']||!hash_equals($expected['sha256'],hash_final($hash)))throw new RuntimeException('import_stream_changed');
        $tick();
        return ['tables'=>$staged,'summary'=>$expected];
    }

    /** Replace staged data in the caller's backed-up transaction; the callback restores captured control state before constraints return. */
    public function replaceStagedRows(PDO $db,array $staged,callable $restoreControlState,callable $progress):void
    {
        if(!$db->inTransaction())throw new RuntimeException('import_transaction_required');
        if(self::destinationTables($db)!==$this->tables)throw new RuntimeException('import_destination_changed');
        $quote=static fn(string $name):string=>'"'.str_replace('"','""',$name).'"';
        $tick=static function()use($progress):void{if($progress()===false)throw new RuntimeException('lease_lost');};
        $expected=[];$destinations=[];
        foreach($this->tables as $key=>$columns){
            [$schema,$name]=explode('.',$key,2);
            $destinations[$key]=$quote($schema).'.'.$quote($name);
            $expected[$key]='pg_temp.'.$quote('sql_import_'.substr(hash('sha256',$key),0,32));
        }
        if(($staged['tables']??null)!==$expected)throw new RuntimeException('import_staging_mismatch');
        foreach(['lorkhan_internal.schema_migrations'=>'version,name,checksum','lorkhan_internal.installations'=>'installation_id'] as $key=>$columns){
            if($db->query('SELECT EXISTS((SELECT '.$columns.' FROM '.$expected[$key].' EXCEPT SELECT '.$columns.' FROM '.$destinations[$key].') UNION ALL (SELECT '.$columns.' FROM '.$destinations[$key].' EXCEPT SELECT '.$columns.' FROM '.$expected[$key].'))')->fetchColumn())throw new RuntimeException('import_compatibility_mismatch');
        }
        // Definitions and identifiers below come only from the destination catalog, not the imported dump.
        $constraints=$db->query("SELECT format('%I.%I',n.nspname,r.relname) AS relation,c.conname,pg_get_constraintdef(c.oid) AS definition
            FROM pg_constraint c JOIN pg_class r ON r.oid=c.conrelid JOIN pg_namespace n ON n.oid=r.relnamespace
            WHERE c.contype='f' AND n.nspname IN ('public','lorkhan_internal')
            AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=r.oid AND d.deptype='e') ORDER BY n.nspname,r.relname,c.conname")->fetchAll(PDO::FETCH_ASSOC);
        $triggers=$db->query("SELECT format('%I.%I',n.nspname,r.relname) AS relation,t.tgname,t.tgenabled
            FROM pg_trigger t JOIN pg_class r ON r.oid=t.tgrelid JOIN pg_namespace n ON n.oid=r.relnamespace
            WHERE NOT t.tgisinternal AND n.nspname IN ('public','lorkhan_internal')
            AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=r.oid AND d.deptype='e') ORDER BY n.nspname,r.relname,t.tgname")->fetchAll(PDO::FETCH_ASSOC);
        foreach($triggers as $trigger){$tick();$db->exec('ALTER TABLE '.$trigger['relation'].' DISABLE TRIGGER '.$quote($trigger['tgname']));}
        foreach($constraints as $constraint){$tick();$db->exec('ALTER TABLE '.$constraint['relation'].' DROP CONSTRAINT '.$quote($constraint['conname']));}
        $tick();$db->exec('TRUNCATE TABLE '.implode(',',$destinations));
        $preserved=['lorkhan_internal.installations','lorkhan_internal.pairing_tokens','lorkhan_internal.request_mac_nonces',
            'lorkhan_internal.browser_sessions','lorkhan_internal.backup_records','lorkhan_internal.database_backup_settings'];
        foreach($destinations as $key=>$destination){
            if(in_array($key,$preserved,true))continue;
            $tick();
            $statement=$db->prepare('SELECT attname FROM pg_attribute WHERE attrelid=CAST(:relation AS regclass) AND attnum>0 AND NOT attisdropped AND attgenerated=\'\' ORDER BY attnum');
            $statement->execute(['relation'=>$destination]);
            $columns=implode(',',array_map($quote,$statement->fetchAll(PDO::FETCH_COLUMN)));
            $db->exec('INSERT INTO '.$destination.' ('.$columns.') OVERRIDING SYSTEM VALUE SELECT '.$columns.' FROM '.$expected[$key]);
        }
        // Retain restored history, but never restart queued work from the uploaded snapshot.
        $db->exec("UPDATE lorkhan_internal.sessions SET state='ended',ended_at=clock_timestamp() WHERE state='active';
            UPDATE lorkhan_internal.turns SET state='cancelled',completed_at=clock_timestamp() WHERE state IN ('accepted','processing');
            UPDATE lorkhan_internal.stt_requests SET state='failed',completed_at=clock_timestamp(),provider_error_code='database_restored' WHERE state IN ('accepted','processing');
            UPDATE lorkhan_internal.dialogue_utterances SET delivery_state='interrupted' WHERE delivery_state='pending';
            UPDATE lorkhan_internal.provider_attempts SET state='cancelled',finished_at=clock_timestamp(),error_code='database_restored' WHERE state='started';
            UPDATE lorkhan_internal.durable_jobs SET state='dead',last_error_code='database_restored',last_error_detail=NULL,completed_at=clock_timestamp(),updated_at=clock_timestamp(),lease_owner=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,heartbeat_at=NULL WHERE state IN ('queued','leased');
            UPDATE lorkhan_internal.durable_job_attempts SET outcome='dead',finished_at=clock_timestamp(),error_code='database_restored',error_detail=NULL WHERE finished_at IS NULL");
        $restoreControlState();$tick();
        foreach($constraints as $constraint){$tick();$db->exec('ALTER TABLE '.$constraint['relation'].' ADD CONSTRAINT '.$quote($constraint['conname']).' '.$constraint['definition']);}
        foreach($triggers as $trigger){
            $mode=match($trigger['tgenabled']){'O'=>'ENABLE','R'=>'ENABLE REPLICA','A'=>'ENABLE ALWAYS','D'=>'DISABLE'};
            $db->exec('ALTER TABLE '.$trigger['relation'].' '.$mode.' TRIGGER '.$quote($trigger['tgname']));
        }
        // ALTER SEQUENCE RESTART is transactional, unlike setval; failed imports must not rewind live counters.
        $sequences=$db->query("SELECT n.nspname||'.'||r.relname AS key,format('%I.%I',n.nspname,r.relname) AS relation,a.attname,pg_get_serial_sequence(format('%I.%I',n.nspname,r.relname),a.attname) AS sequence
            FROM pg_class r JOIN pg_namespace n ON n.oid=r.relnamespace JOIN pg_attribute a ON a.attrelid=r.oid AND a.attnum>0 AND NOT a.attisdropped
            WHERE n.nspname IN ('public','lorkhan_internal') AND r.relkind IN ('r','p') AND pg_get_serial_sequence(format('%I.%I',n.nspname,r.relname),a.attname) IS NOT NULL
            AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=r.oid AND d.deptype='e')")->fetchAll(PDO::FETCH_ASSOC);
        foreach($sequences as $sequence){
            $tick();$definition=$db->prepare('SELECT seqincrement,seqmin,seqmax,seqstart FROM pg_sequence WHERE seqrelid=CAST(:sequence AS regclass)');
            $definition->execute(['sequence'=>$sequence['sequence']]);$limits=$definition->fetch(PDO::FETCH_ASSOC);
            if((int)$limits['seqincrement']!==1)throw new RuntimeException('import_sequence_unsupported');
            $imported=$staged['summary']['sequence_states'][$sequence['key']][$sequence['attname']]??null;
            if(!is_array($imported))throw new RuntimeException('import_sequence_mismatch');
            $importRange=$db->prepare('SELECT CAST(:last AS numeric) BETWEEN CAST(:minimum AS numeric) AND CAST(:maximum AS numeric)');
            $importRange->execute(['last'=>$imported['last_value'],'minimum'=>$limits['seqmin'],'maximum'=>$limits['seqmax']]);
            if(!$importRange->fetchColumn())throw new RuntimeException('import_sequence_exhausted');
            $importFloor=$db->quote($imported['last_value']).'::numeric+'.($imported['is_called']?'1':'0');
            $next=$db->query('SELECT GREATEST('.(int)$limits['seqstart'].',COALESCE(max('.$quote($sequence['attname']).')::numeric+1,'.(int)$limits['seqstart'].'),'
                .$importFloor.',(SELECT last_value::numeric+CASE WHEN is_called THEN 1 ELSE 0 END FROM '.$sequence['sequence'].'))::text FROM '.$sequence['relation'])->fetchColumn();
            if(!is_string($next)||preg_match('/^-?[0-9]+$/D',$next)!==1)throw new RuntimeException('import_sequence_exhausted');
            $range=$db->prepare('SELECT CAST(:next AS numeric) BETWEEN CAST(:minimum AS numeric) AND CAST(:maximum AS numeric)');
            $range->execute(['next'=>$next,'minimum'=>$limits['seqmin'],'maximum'=>$limits['seqmax']]);
            if(!$range->fetchColumn())throw new RuntimeException('import_sequence_exhausted');
            $db->exec('ALTER SEQUENCE '.$sequence['sequence'].' RESTART WITH '.$next);
        }
        $tick();
    }
}
