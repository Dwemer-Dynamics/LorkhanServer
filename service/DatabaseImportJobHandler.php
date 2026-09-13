<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\{DatabaseImportStore,DatabaseSqlBackup,IsolatedSqlImport,JobRepository,MigrationReplayState,SqlImportData,Uuid};
use PDO;
use RuntimeException;

/** Replace only schema-validated sandbox data, with a rollback backup and preserved local control state. */
final class DatabaseImportJobHandler implements JobHandler
{
    public const TYPE='database.import';
    public function __construct(private readonly PDO $db,private readonly array $config){}
    public function supports(string $type,int $version):bool{return $type===self::TYPE&&$version===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($idempotencyKey);$job=$payload['_job']??[];unset($payload['_job']);
        $keys=array_keys($payload);sort($keys);
        if($keys!==['import_id','rollback_id','source_sha256']||!is_string($payload['import_id'])||!is_string($payload['rollback_id'])
            ||!Uuid::isValid($payload['import_id'])||!Uuid::isValid($payload['rollback_id'])
            ||!is_string($payload['source_sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$payload['source_sha256'])
            ||!is_string($job['job_id']??null)||!is_string($job['lease_token']??null)||!Uuid::isValid($job['job_id']??'')||!Uuid::isValid($job['lease_token']??'')||$job['job_id']!==$payload['import_id'])throw new \InvalidArgumentException('invalid_import_job');
        $path=(new DatabaseImportStore($this->config))->path($payload['import_id']);$runtime=false;$maintenance=false;
        try{
            $runtime=filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,114)')->fetchColumn(),FILTER_VALIDATE_BOOL);
            if(!$runtime)throw new RuntimeException('restore_runtime_busy');
            $maintenance=filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL);
            if(!$maintenance)throw new RuntimeException('maintenance_busy');
            (new IsolatedSqlImport())->read($path,$payload['source_sha256'],$heartbeat,function($stream)use($payload,$job,$heartbeat):void{
                $data=new SqlImportData(SqlImportData::destinationTables($this->db),SqlImportData::destinationSequences($this->db));
                $validationDeadline=hrtime(true)+600000000000;$lastHeartbeat=0;
                $data->validate($stream,static function()use($heartbeat,$validationDeadline,&$lastHeartbeat):void{
                    $now=hrtime(true);if($now>$validationDeadline)throw new RuntimeException('import_validation_timeout');
                    if($now-$lastHeartbeat>1000000000){if(!$heartbeat())throw new RuntimeException('lease_lost');$lastHeartbeat=$now;}
                });
                (new DatabaseSqlBackup($this->config))->create($this->db,$payload['rollback_id'],$heartbeat);
                $q=$this->db->prepare("UPDATE backup_records SET scope=scope||jsonb_build_object('rollback_for',CAST(:job AS text)) WHERE backup_id=:backup");$q->execute(['job'=>$job['job_id'],'backup'=>$payload['rollback_id']]);
                // Table replacement cannot heartbeat through its own locks; reserve the bounded transaction lease first.
                if(!(new JobRepository($this->db))->heartbeat($job['job_id'],$job['lease_token'],3600))throw new RuntimeException('lease_lost');
                $deadline=hrtime(true)+600000000000;
                $progress=static function()use($deadline):void{if(hrtime(true)>$deadline)throw new RuntimeException('import_transaction_timeout');};
                $this->db->beginTransaction();
                try{
                    $this->db->exec("SET LOCAL statement_timeout='600s'; SET LOCAL lock_timeout='3s'");
                    $staged=$data->stage($this->db,$stream,$progress);
                    $state=new MigrationReplayState($this->db,$job['job_id']);$state->capture();
                    $data->replaceStagedRows($this->db,$staged,static function()use($state):void{$state->restore(true);},$progress);
                    $q=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_id=:job AND state='leased' AND lease_token=:lease AND lease_expires_at>clock_timestamp()");$q->execute(['job'=>$job['job_id'],'lease'=>$job['lease_token']]);if(!$q->fetchColumn())throw new RuntimeException('lease_lost');
                    // A crash after commit must not leave a successful import queued for replay.
                    $progress();(new JobRepository($this->db))->succeed($job['job_id'],$job['lease_token']);$this->db->commit();
                }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
            });
        }catch(\Throwable $error){
            $code=preg_match('/^[a-z_]{1,80}$/D',$error->getMessage())?$error->getMessage():'database_import_failed';
            error_log('Lorkhan SQL import failed: '.$code.'.');throw new RuntimeException($code,0,$error);
        }finally{
            if(is_file($path)&&!is_link($path))unlink($path);
            if($maintenance)$this->db->query('SELECT pg_advisory_unlock(7514,113)');
            if($runtime)$this->db->query('SELECT pg_advisory_unlock(7514,114)');
        }
    }
}
