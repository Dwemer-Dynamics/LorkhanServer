<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Verify only deployment-owned factory artifacts; never load uploaded SQL. */
final class FactoryDatabaseArchive
{
    public static function catalogFingerprint():string
    {
        $root=dirname(__DIR__,2).'/data';
        $descriptions=$root.'/descriptions/morrowind-official';$biographies=$root.'/biographies/morrowind-official';
        $oghma=$root.'/oghma/morrowind-official';$active=trim((string)file_get_contents($oghma.'/active-catalog-version.txt'));
        if(preg_match('/^[a-zA-Z0-9._-]+$/D',$active)!==1)throw new RuntimeException('invalid_factory_catalog_version');
        $catalog=$oghma.'/catalogs/'.$active;
        $sourceHash=hash_init('sha256');
        foreach([$descriptions.'/descriptions.csv',$descriptions.'/manifest.json',$descriptions.'/catalog-version.txt',
            $biographies.'/biographies.json',$biographies.'/manifest.json',$biographies.'/catalog-version.txt',
            $oghma.'/active-catalog-version.txt',$catalog.'/articles.json',$catalog.'/manifest.json',$catalog.'/catalog-version.txt'] as $source){
            if(!is_file($source))throw new RuntimeException('factory_catalog_missing');
            hash_update($sourceHash,substr($source,strlen($root))."\0".hash_file('sha256',$source)."\n");
        }
        return hash_final($sourceHash);
    }

    /** Canonical factory data digest shared by the builder and the reset transaction. */
    public static function seedState(PDO $db):array
    {
        $tables=$db->query("SELECT format('%I.%I',n.nspname,c.relname) AS name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p') AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.deptype='e') ORDER BY n.nspname,c.relname")->fetchAll(PDO::FETCH_COLUMN);
        $hash=hash_init('sha256');$rowCount=0;
        foreach($tables as $table){
            hash_update($hash,$table."\n");
            $rows=$db->query('SELECT to_jsonb(t)::text FROM '.$table.' t ORDER BY to_jsonb(t)::text COLLATE "C"');
            while(($row=$rows->fetchColumn())!==false){hash_update($hash,$row."\n");++$rowCount;}
        }
        return ['table_count'=>count($tables),'seed_row_count'=>$rowCount,'seed_sha256'=>hash_final($hash)];
    }

    public static function load(PDO $db,string $directory,string $expectedFingerprint):array
    {
        $root=realpath($directory);
        if($root===false||!is_dir($root)||(fileperms($root)&0022)!==0)throw new RuntimeException('factory_unavailable');
        foreach(['factory.json','factory.sql','factory.dump'] as $name){
            $path=$root.'/'.$name;
            if(!is_file($path)||is_link($path)||(fileperms($path)&0022)!==0)throw new RuntimeException('factory_unavailable');
        }
        if(filesize($root.'/factory.json')>8192)throw new RuntimeException('factory_manifest_invalid');
        try{$manifest=json_decode((string)file_get_contents($root.'/factory.json'),true,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new RuntimeException('factory_manifest_invalid');}
        if(!is_array($manifest)||($manifest['format_version']??null)!==2)throw new RuntimeException('factory_manifest_invalid');
        foreach(['migration_fingerprint','catalog_fingerprint','seed_sha256','archive_sha256','sql_sha256'] as $key){
            if(!is_string($manifest[$key]??null)||preg_match('/^[a-f0-9]{64}$/D',$manifest[$key])!==1)throw new RuntimeException('factory_manifest_invalid');
        }
        foreach(['migration_count','table_count','seed_row_count','archive_bytes','sql_bytes'] as $key){
            if(!is_int($manifest[$key]??null)||$manifest[$key]<1)throw new RuntimeException('factory_manifest_invalid');
        }
        if($manifest['sql_bytes']>67108864||$manifest['archive_bytes']>1073741824)throw new RuntimeException('factory_too_large');
        $runner=new MigrationRunner($db,dirname(__DIR__,2).'/data/migrations');
        if(!hash_equals($manifest['migration_fingerprint'],$runner->replayFingerprint(false))
            ||!hash_equals($manifest['catalog_fingerprint'],self::catalogFingerprint())
            ||!hash_equals(hash('sha256',$manifest['migration_fingerprint']."\0".$manifest['catalog_fingerprint']),$expectedFingerprint))throw new RuntimeException('factory_source_changed');
        if(filesize($root.'/factory.dump')!==$manifest['archive_bytes']||!hash_equals($manifest['archive_sha256'],hash_file('sha256',$root.'/factory.dump'))
            ||filesize($root.'/factory.sql')!==$manifest['sql_bytes'])throw new RuntimeException('factory_integrity_failed');
        $sql=(string)file_get_contents($root.'/factory.sql');
        if(strlen($sql)!==$manifest['sql_bytes']||!hash_equals($manifest['sql_sha256'],hash('sha256',$sql)))throw new RuntimeException('factory_integrity_failed');
        return ['manifest'=>$manifest,'sql'=>$sql];
    }
}
