<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Private, immutable SQL dumps from this server's configured PostgreSQL database. */
final class DatabaseSqlBackup
{
    public const MAX_BYTES = 1073741824;
    public function __construct(private readonly array $config) {}

    public function path(string $id): string
    {
        if (!Uuid::isValid($id)) throw new RuntimeException('not_found');
        $root=rtrim((string)($this->config['backup_storage_path']??'/var/lib/lorkhanserver/backups'),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'sql';
        if ($root==='' || (!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root))) throw new RuntimeException('backup_storage_unavailable');
        return $root.DIRECTORY_SEPARATOR.'sql-'.$id.'.sql';
    }

    /** Stream pg_dump to a private file while renewing the worker lease; never put credentials in argv. */
    public function create(PDO $db, string $id, callable $heartbeat, bool $automatic = false): void
    {
        $backupDeadline=hrtime(true)+600000000000;$renew=$heartbeat;
        $heartbeat=static function()use($renew,$backupDeadline):bool{
            if(hrtime(true)>$backupDeadline)throw new RuntimeException('database_backup_timeout');
            return $renew();
        };
        $path=$this->path($id); $partial=$path.'.dump.partial';$sqlPartial=$path.'.partial';
        try{$record=(new ProductRepository($db))->configurationBackupRecord($id);}
        catch(RuntimeException $error){if($error->getMessage()!=='not_found')throw $error;$record=null;}
        if($record!==null){
            if(($record['scope']['kind']??'')!=='database_sql'||!is_file($path)||filesize($path)!==(int)$record['byte_count']
                ||!hash_equals($record['content_sha256'],hash_file('sha256',$path)))throw new RuntimeException('backup_integrity_failed');
            if((int)$record['format_version']>=2&&(!is_file($path.'.dump')||is_link($path.'.dump')
                ||!hash_equals($record['scope']['archive_sha256']??'',hash_file('sha256',$path.'.dump'))))throw new RuntimeException('backup_integrity_failed');
            return;
        }
        if (file_exists($path)||file_exists($path.'.dump')||file_exists($partial)||file_exists($sqlPartial)) throw new RuntimeException('backup_already_exists');
        $used=0; foreach(glob(dirname($path).'/sql-*.sql*')?:[] as $file) $used+=(int)filesize($file);
        if ($used>=4*self::MAX_BYTES || disk_free_space(dirname($path))<64*1024*1024) throw new RuntimeException('backup_storage_full');
        $env=$this->processEnvironment();
        $oldMask=umask(0077);
        try{$output=fopen($partial,'xb');}finally{umask($oldMask);}
        if ($output===false) throw new RuntimeException('backup_storage_unavailable');
        $process=null; $pipes=[];
        try {
            if(!chmod($partial,0640)||!chgrp($partial,filegroup(dirname($path))))throw new RuntimeException('backup_storage_permissions');
            if (!$heartbeat()) throw new RuntimeException('lease_lost');
            $process=proc_open(['pg_dump','--no-password','--format=custom','--no-owner','--no-privileges'],
                [0=>['pipe','r'],1=>$output,2=>['pipe','w']],$pipes,null,$env,['bypass_shell'=>true]);
            if (!is_resource($process)) throw new RuntimeException('database_backup_failed');
            fclose($pipes[0]);unset($pipes[0]);stream_set_blocking($pipes[2],false);
            $deadline=hrtime(true)+600000000000; $lastHeartbeat=0;
            do {
                // Drain diagnostics but never persist or expose connection details from pg_dump stderr.
                stream_get_contents($pipes[2],8192);
                $status=proc_get_status($process); clearstatcache(true,$partial);
                if (filesize($partial)>self::MAX_BYTES || filesize($partial)+$used>4*self::MAX_BYTES) throw new RuntimeException('backup_too_large');
                $now=hrtime(true); if ($now>$deadline) throw new RuntimeException('database_backup_timeout');
                if ($now-$lastHeartbeat>2000000000) { if (!$heartbeat()) throw new RuntimeException('lease_lost'); $lastHeartbeat=$now; }
                if ($status['running']) usleep(200000);
            } while ($status['running']);
            $exit=$status['exitcode'];fclose($pipes[2]);unset($pipes[2]);proc_close($process);$process=null;
            if(is_resource($output)){fflush($output);fclose($output);}$output=null;
            if ($exit!==0 || !is_file($partial) || filesize($partial)<1) throw new RuntimeException('database_backup_failed');
            $this->archiveOutput($partial,$sqlPartial,$heartbeat,['--clean','--if-exists','--no-owner','--no-privileges']);
            if (!rename($partial,$path.'.dump')||!rename($sqlPartial,$path)) throw new RuntimeException('backup_storage_unavailable');
            $statement=$db->prepare("INSERT INTO backup_records(backup_id,format_version,content_sha256,byte_count,scope,state) VALUES(:id,2,:sha,:bytes,CAST(:scope AS jsonb),'created')");
            $statement->execute(['id'=>$id,'sha'=>hash_file('sha256',$path),'bytes'=>filesize($path),'scope'=>json_encode(['kind'=>'database_sql','automatic'=>$automatic,'archive_sha256'=>hash_file('sha256',$path.'.dump'),'archive_bytes'=>filesize($path.'.dump')])]);
        } finally {
            if (is_resource($process)) {proc_terminate($process);proc_close($process);}
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            if(is_resource($output))fclose($output);
            if(is_file($partial))unlink($partial);
            if(is_file($sqlPartial))unlink($sqlPartial);
        }
    }

    /** Render a PostgreSQL archive/list privately; options come only from fixed backup/restore code. */
    public function archiveOutput(string $archive,string $output,callable $heartbeat,array $options):void
    {
        $mask=umask(0077);try{$file=fopen($output,'xb');}finally{umask($mask);}
        if($file===false)throw new RuntimeException('backup_storage_unavailable');
        $process=null;$pipes=[];
        try{
            if(!chmod($output,0640)||!chgrp($output,filegroup(dirname($output))))throw new RuntimeException('backup_storage_permissions');
            $process=proc_open(array_merge(['pg_restore','--file=-'], $options,[$archive]),[0=>['pipe','r'],1=>$file,2=>['pipe','w']],$pipes,null,$this->processEnvironment(),['bypass_shell'=>true]);
            if(!is_resource($process))throw new RuntimeException('backup_archive_failed');
            fclose($pipes[0]);unset($pipes[0]);stream_set_blocking($pipes[2],false);
            $deadline=hrtime(true)+600000000000;$last=0;
            do{
                stream_get_contents($pipes[2],8192);$status=proc_get_status($process);clearstatcache();
                $used=0;foreach(glob(dirname($output).'/sql-*.sql*')?:[] as $candidate)$used+=(int)filesize($candidate);
                if(filesize($output)>self::MAX_BYTES||$used>4*self::MAX_BYTES)throw new RuntimeException('backup_too_large');
                $now=hrtime(true);if($now>$deadline)throw new RuntimeException('backup_archive_timeout');
                if($now-$last>2000000000){if(!$heartbeat())throw new RuntimeException('lease_lost');$last=$now;}
                if($status['running'])usleep(200000);
            }while($status['running']);
            $exit=$status['exitcode'];fclose($pipes[2]);unset($pipes[2]);proc_close($process);$process=null;
            if($exit!==0)throw new RuntimeException('backup_archive_failed');
        }finally{
            if(is_resource($process)){proc_terminate($process);proc_close($process);}
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            if(is_resource($file))fclose($file);
        }
    }

    /** Supply only PostgreSQL connection credentials to backup/restore subprocesses, never provider keys. */
    public function processEnvironment(): array
    {
        $dsn=(string)($this->config['database_dsn']??'');
        if (!str_starts_with($dsn,'pgsql:')) throw new RuntimeException('invalid_backup_database');
        preg_match_all('/(?:^|;)(host|port|dbname)=([^;]+)/',substr($dsn,6),$parts,PREG_SET_ORDER);
        $parameters=[];foreach($parts as $part)$parameters[$part[1]]=$part[2];
        if (empty($parameters['dbname'])) throw new RuntimeException('invalid_backup_database');
        $env=['PATH'=>getenv('PATH')?:'/usr/bin:/bin','LANG'=>'C.UTF-8','LC_ALL'=>'C.UTF-8'];
        $env['PGDATABASE']=$parameters['dbname']; $env['PGHOST']=$parameters['host']??'localhost'; $env['PGPORT']=$parameters['port']??'5432';
        $env['PGUSER']=(string)($this->config['database_user']??'');
        $env['PGPASSWORD']=(string)($this->config['database_password']??getenv('LORKHAN_DATABASE_PASSWORD')?:'');
        $env['PGCONNECT_TIMEOUT']='10';
        return $env;
    }

    /** Keep the newest automatic snapshots only after verifying the replacement; manual backups are never removed. */
    public function pruneAutomatic(PDO $db,string $replacementId): void
    {
        $replacement=(new ProductRepository($db))->configurationBackupRecord($replacementId);
        $path=$this->path($replacementId);
        if(($replacement['scope']['automatic']??false)!==true || !is_file($path) || is_link($path)
            ||filesize($path)!==(int)$replacement['byte_count']||!hash_equals($replacement['content_sha256'],hash_file('sha256',$path)))
            throw new RuntimeException('backup_integrity_failed');
        if((int)$replacement['format_version']>=2&&(!is_file($path.'.dump')||is_link($path.'.dump')
            ||!hash_equals($replacement['scope']['archive_sha256']??'',hash_file('sha256',$path.'.dump'))))throw new RuntimeException('backup_integrity_failed');
        $settings=(new ManagementRepository($db))->databaseBackupSettings();
        if(!$settings['enabled'])return;
        $old=$db->query("SELECT backup_id FROM backup_records WHERE scope->>'kind'='database_sql' AND scope->>'automatic'='true' ORDER BY created_at DESC,backup_id DESC OFFSET ".$settings['max_count'])->fetchAll(PDO::FETCH_COLUMN);
        foreach($old as $id){
            if($id===$replacementId)continue;
            $candidate=$this->path($id);
            if(is_link($candidate)||is_link($candidate.'.dump'))throw new RuntimeException('backup_integrity_failed');
            if(is_file($candidate)&&!unlink($candidate))throw new RuntimeException('backup_retention_failed');
            if(is_file($candidate.'.dump')&&!unlink($candidate.'.dump'))throw new RuntimeException('backup_retention_failed');
            $delete=$db->prepare("DELETE FROM backup_records WHERE backup_id=:id AND scope->>'kind'='database_sql' AND scope->>'automatic'='true'");
            $delete->execute(['id'=>$id]);
        }
    }
}
