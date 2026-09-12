<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\DatabaseSqlBackup;
use LorkhanServer\Infrastructure\JobRepository;
use LorkhanServer\Infrastructure\ProductRepository;
use LorkhanServer\Infrastructure\Uuid;
use PDO;
use RuntimeException;

/** Restore only a verified server-owned SQL file, with rollback and operational-state preservation. */
final class DatabaseRestoreJobHandler implements JobHandler
{
    public const TYPE='database.restore';
    public function __construct(private readonly PDO $db,private readonly array $config) {}
    public function supports(string $jobType,int $schemaVersion):bool{return $jobType===self::TYPE&&$schemaVersion===1;}

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        $job=$payload['_job']??[];unset($payload['_job']);
        if(array_keys($payload)!==['backup_id','rollback_id']||!Uuid::isValid($payload['backup_id'])||!Uuid::isValid($payload['rollback_id'])
            ||!Uuid::isValid($job['job_id']??'')||!Uuid::isValid($job['lease_token']??''))throw new \InvalidArgumentException('invalid_database_restore_job');
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,114)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('restore_runtime_busy');
        $maintenance=false;$process=null;$pipes=[];$native=null;$list=null;
        try{
            $maintenance=filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL);
            if(!$maintenance)throw new RuntimeException('maintenance_busy');
            $record=(new ProductRepository($this->db))->configurationBackupRecord($payload['backup_id']);
            $store=new DatabaseSqlBackup($this->config);$path=$store->path($payload['backup_id']);
            if(($record['scope']['kind']??'')!=='database_sql'||!is_file($path)||is_link($path)
                ||filesize($path)!==(int)$record['byte_count']||!hash_equals($record['content_sha256'],hash_file('sha256',$path)))throw new RuntimeException('backup_integrity_failed');
            if(!isset($record['scope']['archive_sha256'])||!is_file($path.'.dump')||is_link($path.'.dump')
                ||!hash_equals($record['scope']['archive_sha256'],hash_file('sha256',$path.'.dump')))throw new RuntimeException('restore_archive_unavailable');
            $native=$path.'.restore-'.$job['job_id'].'.partial';$list=$native.'.list';
            $store->archiveOutput($path.'.dump',$list,$heartbeat,['--list']);
            if(filesize($list)>4194304)throw new RuntimeException('backup_archive_too_large');
            // PostgreSQL's TOC lets us preserve administrator-owned schemas/extensions without parsing SQL.
            $lines=file($list,FILE_IGNORE_NEW_LINES);if($lines===false)throw new RuntimeException('backup_archive_failed');
            $lines=array_filter($lines,static fn(string $line):bool=>!preg_match('/^\d+;\s+\d+\s+\d+\s+(?:(?:SCHEMA|EXTENSION)\b|COMMENT\s+-\s+(?:SCHEMA|EXTENSION)\b)/',$line));
            if(file_put_contents($list,implode("\n",$lines)."\n")===false)throw new RuntimeException('backup_storage_unavailable');
            $store->archiveOutput($path.'.dump',$native,$heartbeat,['--clean','--if-exists','--no-owner','--no-privileges','--use-list',$list]);
            $store->create($this->db,$payload['rollback_id'],$heartbeat);
            $rollback=$this->db->prepare("UPDATE backup_records SET scope=scope||jsonb_build_object('rollback_for',CAST(:job AS text)) WHERE backup_id=:id");
            $rollback->execute(['job'=>$job['job_id'],'id'=>$payload['rollback_id']]);
            if(isset($record['scope']['snapshot'])){
                $snapshot=['name'=>'Before copy · '.gmdate('Y-m-d H:i:s'),'notes'=>'Automatic rollback before copying '.$record['scope']['snapshot']['name']];
                $save=$this->db->prepare("UPDATE backup_records SET scope=scope||jsonb_build_object('snapshot',CAST(:snapshot AS jsonb)) WHERE backup_id=:id");
                $save->execute(['snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'id'=>$payload['rollback_id']]);
            }
            // psql locks/recreates durable tables inside its transaction; do not heartbeat through those locks.
            if(!(new JobRepository($this->db))->heartbeat($job['job_id'],$job['lease_token'],3600))throw new RuntimeException('lease_lost');
            $root=dirname(__DIR__).'/data/restore';
            $process=proc_open(['psql','--no-password','--no-psqlrc','--single-transaction','--set','ON_ERROR_STOP=1',
                '--set','job_id='.$job['job_id'],'--set','backup_id='.$payload['backup_id'],
                '--file',$root.'/before.sql','--file',$native,'--file',$root.'/after.sql'],
                [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes,null,$store->processEnvironment(),['bypass_shell'=>true]);
            if(!is_resource($process))throw new RuntimeException('database_restore_failed');
            fclose($pipes[0]);unset($pipes[0]);stream_set_blocking($pipes[2],false);
            $deadline=hrtime(true)+600000000000;$reason='database_restore_failed';
            do{
                $diagnostic=stream_get_contents($pipes[2],8192);
                foreach(['restore_schema_mismatch','restore_installation_mismatch'] as $code)if(str_contains($diagnostic,$code))$reason=$code;
                $status=proc_get_status($process);
                if(hrtime(true)>$deadline)throw new RuntimeException('database_restore_timeout');
                if($status['running'])usleep(200000);
            }while($status['running']);
            $exit=$status['exitcode'];fclose($pipes[2]);unset($pipes[2]);proc_close($process);$process=null;
            if($exit!==0)throw new RuntimeException($reason);
        }catch(\Throwable $error){
            $code=preg_match('/^[a-z_]{1,80}$/D',$error->getMessage())?$error->getMessage():'database_restore_failed';
            error_log('Lorkhan SQL restore failed: '.$code.'.');throw $error;
        }finally{
            if(is_resource($process)){proc_terminate($process);proc_close($process);}
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);
            if($native!==null&&is_file($native))unlink($native);
            if($list!==null&&is_file($list))unlink($list);
            if($maintenance)$this->db->query('SELECT pg_advisory_unlock(7514,113)');
            $this->db->query('SELECT pg_advisory_unlock(7514,114)');
        }
    }
}
