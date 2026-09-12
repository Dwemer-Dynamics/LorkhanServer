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

    public function __construct(private readonly array $tables) {}

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
        $hash=hash_init('sha256');$bytes=0;$rows=0;$seen=[];$active=null;$header=false;$complete=false;
        while(($line=fgets($stream,self::MAX_LINE_BYTES+1))!==false){
            $bytes+=strlen($line);
            if($bytes>self::MAX_BYTES||strlen($line)>self::MAX_LINE_BYTES||!str_ends_with($line,"\n"))throw new RuntimeException('import_stream_limit');
            hash_update($hash,$line);
            try{$record=json_decode($line,false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new RuntimeException('import_record_invalid');}
            if(!$record instanceof \stdClass||$complete)throw new RuntimeException('import_record_invalid');
            $keys=array_keys(get_object_vars($record));sort($keys);
            if(!$header){
                if($keys!==['format','kind']||$record->kind!=='header'||$record->format!=='lorkhan.import-data.v1')throw new RuntimeException('import_header_invalid');
                $header=true;continue;
            }
            if(($record->kind??null)==='complete'){
                if($keys!==['kind']||count($seen)!==count($this->tables))throw new RuntimeException('import_tables_incomplete');
                $complete=true;continue;
            }
            if(($record->kind??null)==='table'){
                if($keys!==['columns','kind','name','schema']||!is_string($record->schema)||!is_string($record->name)
                    ||!in_array($record->schema,['public','lorkhan_internal'],true))throw new RuntimeException('import_table_invalid');
                $active=$record->schema.'.'.$record->name;
                $activeSchema=$record->schema;$activeName=$record->name;
                if(!isset($this->tables[$active])||isset($seen[$active])||$record->columns!==$this->tables[$active])throw new RuntimeException('import_schema_mismatch');
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
        return ['sha256'=>hash_final($hash),'byte_count'=>$bytes,'table_count'=>count($seen),'row_count'=>$rows];
    }

    /** Stage typed values inside the caller's transaction, without changing destination rows or executing imported DDL. */
    public function stage(PDO $db,$stream,callable $progress):array
    {
        if(!$db->inTransaction())throw new RuntimeException('import_transaction_required');
        $expected=$this->validate($stream);
        if(self::destinationTables($db)!==$this->tables)throw new RuntimeException('import_destination_changed');
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
}
