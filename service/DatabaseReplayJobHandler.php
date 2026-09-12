<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\DatabaseSqlBackup;
use LorkhanServer\Infrastructure\JobRepository;
use LorkhanServer\Infrastructure\MigrationReplayState;
use LorkhanServer\Infrastructure\MigrationRunner;
use LorkhanServer\Infrastructure\Uuid;
use PDO;
use RuntimeException;

/** Replay source-owned migrations only after a rollback backup, behind the exclusive runtime gate. */
final class DatabaseReplayJobHandler implements JobHandler
{
    public const TYPE='database.replay';
    public function __construct(private readonly PDO $db, private readonly array $config){}
    public function supports(string $type,int $version):bool{return $type===self::TYPE&&$version===1;}

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        $job=$payload['_job']??[];unset($payload['_job']);$keys=array_keys($payload);sort($keys);
        if(!is_array($job)||$keys!==['fingerprint','rollback_id','version']||!is_int($payload['version'])||$payload['version']<1
            ||!is_string($payload['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$payload['fingerprint'])!==1
            ||!is_string($payload['rollback_id'])||!Uuid::isValid($payload['rollback_id'])
            ||!is_string($job['job_id']??null)||!Uuid::isValid($job['job_id'])
            ||!is_string($job['lease_token']??null)||!Uuid::isValid($job['lease_token']))
            throw new \InvalidArgumentException('invalid_database_replay_job');
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,114)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('replay_runtime_busy');
        $maintenance=false;
        try{
            $maintenance=filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL);
            if(!$maintenance)throw new RuntimeException('maintenance_busy');
            $runner=new MigrationRunner($this->db,dirname(__DIR__).'/data/migrations');
            if(!hash_equals($runner->replayFingerprint(),$payload['fingerprint']))throw new RuntimeException('replay_plan_changed');
            $status=$runner->status();
            if(!array_filter($status,static fn(array $row):bool=>$row['version']===$payload['version']&&$row['applied']))throw new RuntimeException('invalid_replay_version');
            (new DatabaseSqlBackup($this->config))->create($this->db,$payload['rollback_id'],$heartbeat);
            $mark=$this->db->prepare("UPDATE backup_records SET scope=scope||jsonb_build_object('rollback_for',CAST(:job AS text),'replay_from',CAST(:version AS integer)) WHERE backup_id=:id");
            $mark->execute(['job'=>$job['job_id'],'version'=>$payload['version'],'id'=>$payload['rollback_id']]);
            // Replay may recreate the job tables. Reserve the bounded lease before copying that state.
            if(!(new JobRepository($this->db))->heartbeat($job['job_id'],$job['lease_token'],3600))throw new RuntimeException('lease_lost');
            $deadline=hrtime(true)+600000000000;
            $progress=function()use($deadline):void{
                $remaining=(int)(($deadline-hrtime(true))/1000000);
                if($remaining<1)throw new RuntimeException('database_replay_timeout');
                $this->db->exec('SET LOCAL statement_timeout = '.$remaining);
            };
            $state=new MigrationReplayState($this->db,$job['job_id']);
            $runner->replayFrom($payload['version'],function()use($runner,$payload,$progress,$state):void{
                $progress();$this->db->exec("SET LOCAL lock_timeout='3s'");
                if(!hash_equals($runner->replayFingerprint(),$payload['fingerprint']))throw new RuntimeException('replay_plan_changed');
                $state->capture();
            },function()use($state,$job):void{
                $state->restore();
                $this->db->exec("UPDATE lorkhan_internal.sessions SET state='ended',ended_at=clock_timestamp() WHERE state='active'");
                $this->db->exec("UPDATE lorkhan_internal.turns SET state='cancelled',completed_at=clock_timestamp() WHERE state IN ('accepted','processing')");
                $this->db->exec("UPDATE lorkhan_internal.stt_requests SET state='failed',completed_at=clock_timestamp(),provider_error_code='database_replayed' WHERE state IN ('accepted','processing')");
                $this->db->exec("UPDATE lorkhan_internal.dialogue_utterances SET delivery_state='interrupted' WHERE delivery_state='pending'");
                $this->db->exec("UPDATE lorkhan_internal.provider_attempts SET state='cancelled',finished_at=clock_timestamp(),error_code='database_replayed' WHERE state='started'");
                $cancel=$this->db->prepare("UPDATE lorkhan_internal.durable_jobs SET state='dead',last_error_code='database_replayed',last_error_detail=NULL,completed_at=clock_timestamp(),updated_at=clock_timestamp(),lease_owner=NULL,lease_token=NULL,leased_at=NULL,lease_expires_at=NULL,heartbeat_at=NULL WHERE state IN ('queued','leased') AND job_id<>:id");
                $cancel->execute(['id'=>$job['job_id']]);
                $attempts=$this->db->prepare("UPDATE lorkhan_internal.durable_job_attempts SET outcome='dead',finished_at=clock_timestamp(),error_code='database_replayed',error_detail=NULL WHERE finished_at IS NULL AND job_id<>:id");
                $attempts->execute(['id'=>$job['job_id']]);
            },$progress);
        }catch(\Throwable $error){
            $code=preg_match('/^[a-z_]{1,80}$/D',$error->getMessage())?$error->getMessage():'database_replay_failed';
            error_log('Lorkhan migration replay failed: '.$code.'.');
            throw $error;
        }finally{
            if($maintenance)$this->db->query('SELECT pg_advisory_unlock(7514,113)');
            $this->db->query('SELECT pg_advisory_unlock(7514,114)');
        }
    }
}
