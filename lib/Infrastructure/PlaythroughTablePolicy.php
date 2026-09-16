<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Explicit portable-backup policy; PostgreSQL comments describe it but never grant inclusion. */
final class PlaythroughTablePolicy
{
    public static function tables():array
    {
        $data=json_decode(file_get_contents(dirname(__DIR__,2).'/data/playthrough-table-policy.json'),true,32,JSON_THROW_ON_ERROR);
        $descriptions=[
            'shared'=>'Shared settings, templates or catalogues. Not replaced by a character import.',
            'playthrough'=>'Character-owned records. Only the selected playthrough is eligible.',
            'mixed'=>'Shared and character-owned rows. Only explicitly scoped rows are eligible.',
            'operational'=>'Runtime, security or maintenance state. Never restored as live work.',
            'derived'=>'Derived projections or diagnostic/cache records; not an independent portable source.',
        ];
        $result=[];
        foreach($descriptions as$category=>$description){
            foreach($data[$category]??[] as$table){
                if(!is_string($table)||preg_match('/^(public|lorkhan_internal)\.[a-z_][a-z0-9_]*$/D',$table)!==1||isset($result[$table]))throw new RuntimeException('invalid_playthrough_table_policy');
                $portable=in_array($table,$data['portable']??[],true);
                if($portable&&!in_array($category,['playthrough','mixed'],true))throw new RuntimeException('unsafe_playthrough_table_policy');
                $result[$table]=['category'=>$category,'description'=>$description,'portable'=>$portable];
            }
        }
        foreach($data['portable']??[] as$table)if(!isset($result[$table]))throw new RuntimeException('unknown_portable_table');
        ksort($result,SORT_STRING);
        return$result;
    }

    public static function synchronize(PDO $db):void
    {
        $query=$db->prepare('SELECT lorkhan_internal.sync_playthrough_table_policy(CAST(:policy AS jsonb))');
        $query->execute(['policy'=>json_encode(self::tables(),JSON_THROW_ON_ERROR)]);
    }

    /** Include unclassified future tables in the report without silently admitting them to exports. */
    public static function inventory(PDO $db):array
    {
        $policy=self::tables();$result=[];
        $query=$db->query("SELECT n.nspname||'.'||c.relname AS name,obj_description(c.oid,'pg_class') AS comment FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p') ORDER BY 1");
        foreach($query->fetchAll(PDO::FETCH_ASSOC)as$row)$result[]=['table'=>$row['name'],'comment'=>$row['comment']]+($policy[$row['name']]??[
            'category'=>'unclassified','portable'=>false,'description'=>'Not reviewed. Excluded from portable export and deletion.']);
        return$result;
    }
}
