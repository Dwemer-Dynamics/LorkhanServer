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
                if($keys!==['columns','kind','name','schema']||!is_string($record->schema)||!is_string($record->name))throw new RuntimeException('import_table_invalid');
                $active=$record->schema.'.'.$record->name;
                if(!isset($this->tables[$active])||isset($seen[$active])||$record->columns!==$this->tables[$active])throw new RuntimeException('import_schema_mismatch');
                $seen[$active]=true;continue;
            }
            if(($record->kind??null)!=='row'||$keys!==['data','kind','schema','table']||!is_string($record->schema)||!is_string($record->table)
                ||$active===null||$record->schema.'.'.$record->table!==$active||!$record->data instanceof \stdClass)throw new RuntimeException('import_row_invalid');
            $values=get_object_vars($record->data);$columns=array_keys($values);
            if(count($columns)!==count($this->tables[$active])||array_diff($columns,$this->tables[$active])!==[])throw new RuntimeException('import_columns_mismatch');
            foreach($values as $value)if($value!==null&&(!is_string($value)||str_contains($value,"\0")))throw new RuntimeException('import_value_invalid');
            ++$rows;
        }
        if(!feof($stream)||!$header||!$complete)throw new RuntimeException('import_stream_incomplete');
        rewind($stream);
        return ['sha256'=>hash_final($hash),'byte_count'=>$bytes,'table_count'=>count($seen),'row_count'=>$rows];
    }
}
