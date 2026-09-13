<?php

declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Uploaded SQL is quarantined by opaque ID, never registered as a trusted backup archive. */
final class DatabaseImportStore
{
    public function __construct(private readonly array $config){}
    /** Operator-provided dumps stay outside the web root and separate from active import quarantine. */
    public function serverDirectory():string
    {
        $root=rtrim((string)($this->config['backup_storage_path']??'/var/lib/lorkhanserver/backups'),DIRECTORY_SEPARATOR);
        $directory=$root.'/incoming';
        if(is_link($root)||is_link($directory))throw new RuntimeException('import_storage_unavailable');
        if(!is_dir($directory)){
            $mask=umask(0007);try{$made=mkdir($directory,0770,true);}finally{umask($mask);}
            if(!$made&&!is_dir($directory))throw new RuntimeException('import_storage_unavailable');
        }
        return $directory;
    }

    /** Resolve only a regular SQL file immediately inside the dedicated import folder. */
    public function serverFile(string $name):string
    {
        if($name===''||strlen($name)>255||preg_match('/[\\\\\/\x00-\x1f\x7f]/',$name)
            ||strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='sql')throw new RuntimeException('invalid_import_file');
        $directory=$this->serverDirectory();$path=$directory.'/'.$name;$resolved=realpath($path);
        if(is_link($path)||$resolved===false||dirname($resolved)!==realpath($directory)
            ||!is_file($resolved)||!is_readable($resolved)||filesize($resolved)<1||filesize($resolved)>SqlImportData::MAX_BYTES)
            throw new RuntimeException('invalid_import_file');
        return $resolved;
    }

    /** Bound folder enumeration without reading multi-gigabyte dump contents on page loads. */
    public function serverFiles():array
    {
        $files=[];$examined=0;
        foreach(new \DirectoryIterator($this->serverDirectory())as$entry){
            if($entry->isDot())continue;
            if(++$examined>1000)throw new RuntimeException('import_folder_too_large');
            try{$path=$this->serverFile($entry->getFilename());}
            catch(RuntimeException $error){if($error->getMessage()==='invalid_import_file')continue;throw $error;}
            $files[]=['name'=>$entry->getFilename(),'bytes'=>filesize($path)];
        }
        usort($files,static fn(array $a,array $b):int=>strnatcasecmp($a['name'],$b['name']));
        return $files;
    }

    public function path(string $id):string
    {
        if(!Uuid::isValid($id))throw new RuntimeException('invalid_import_id');
        $root=rtrim((string)($this->config['backup_storage_path']??'/var/lib/lorkhanserver/backups'),DIRECTORY_SEPARATOR).'/imports';
        if(is_link($root))throw new RuntimeException('import_storage_unavailable');
        if(!is_dir($root)){ $mask=umask(0007);try{$made=mkdir($root,0770,true);}finally{umask($mask);}if(!$made&&!is_dir($root))throw new RuntimeException('import_storage_unavailable'); }
        return $root.'/import-'.$id.'.sql';
    }

    /** Queue the same confirmation once; retries cannot replace its source or start another import. */
    public function enqueue(PDO $db,string $id,string $source):string
    {
        $destination=$this->path($id);
        if(!is_file($source)||is_link($source)||filesize($source)<1||filesize($source)>SqlImportData::MAX_BYTES)throw new RuntimeException('invalid_import_file');
        $hash=hash_file('sha256',$source);if(!is_string($hash))throw new RuntimeException('invalid_import_file');
        if(!filter_var($db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('maintenance_busy');
        $written=false;$output=null;$input=null;
        try{
            $existing=$db->prepare("SELECT payload->>'source_sha256' FROM durable_jobs WHERE job_id=:id AND job_type='database.import'");$existing->execute(['id'=>$id]);$saved=$existing->fetchColumn();
            if($saved!==false){if(!hash_equals($saved,$hash))throw new RuntimeException('import_confirmation_conflict');return $id;}
            if($db->query("SELECT EXISTS(SELECT 1 FROM durable_jobs WHERE job_type IN ('database.compact','database.backup','database.restore','database.replay','database.factory_reset','database.import') AND state IN ('queued','leased'))")->fetchColumn())throw new RuntimeException('maintenance_busy');
            $used=0;foreach(glob(dirname($destination).'/import-*.sql')?:[]as$file)$used+=(int)filesize($file);
            if($used+filesize($source)>4*SqlImportData::MAX_BYTES)throw new RuntimeException('import_storage_full');
            $mask=umask(0027);try{$output=fopen($destination,'xb');}finally{umask($mask);}if($output===false)throw new RuntimeException('import_storage_unavailable');$written=true;
            $input=fopen($source,'rb');if($input===false||stream_copy_to_stream($input,$output,SqlImportData::MAX_BYTES+1)!==filesize($source)||!fflush($output))throw new RuntimeException('import_storage_unavailable');
            fclose($input);$input=null;fclose($output);$output=null;
            if(!hash_equals($hash,hash_file('sha256',$destination)))throw new RuntimeException('import_integrity_failed');
            (new JobRepository($db))->enqueue($id,'database.import',1,$id,['import_id'=>$id,'source_sha256'=>$hash,'rollback_id'=>Uuid::v4()],1);
            return $id;
        }catch(\Throwable $error){if(is_resource($input))fclose($input);if(is_resource($output))fclose($output);if($written&&is_file($destination))unlink($destination);throw $error;}
        finally{$db->query('SELECT pg_advisory_unlock(7514,113)');}
    }
}
