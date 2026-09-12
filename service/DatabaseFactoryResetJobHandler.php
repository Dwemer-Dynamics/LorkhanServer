<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\DatabaseSqlBackup;
use LorkhanServer\Infrastructure\DefaultConnectorProvisioner;
use LorkhanServer\Infrastructure\FactoryDatabaseArchive;
use LorkhanServer\Infrastructure\JobRepository;
use LorkhanServer\Infrastructure\MigrationReplayState;
use PDO;
use RuntimeException;

/** Reset from a verified deployment artifact with rollback and operational identity preservation. */
final class DatabaseFactoryResetJobHandler implements JobHandler
{
    public const TYPE='database.factory_reset';
    public function __construct(private readonly PDO $db,private readonly array $config){}
    public function supports(string $type,int $version):bool{return $type===self::TYPE&&$version===1;}

    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        $job=$payload['_job']??[];unset($payload['_job']);$keys=array_keys($payload);sort($keys);
        if(!is_array($job)||$keys!==['fingerprint','rollback_id']
            ||!is_string($payload['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$payload['fingerprint'])!==1
            ||!is_string($payload['rollback_id'])||!\LorkhanServer\Infrastructure\Uuid::isValid($payload['rollback_id'])
            ||!is_string($job['job_id']??null)||!\LorkhanServer\Infrastructure\Uuid::isValid($job['job_id'])
            ||!is_string($job['lease_token']??null)||!\LorkhanServer\Infrastructure\Uuid::isValid($job['lease_token']))throw new \InvalidArgumentException('invalid_factory_reset_job');
        if($this->db->inTransaction())throw new RuntimeException('factory_requires_own_transaction');
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,114)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('factory_runtime_busy');
        $maintenance=false;
        try{
            $maintenance=filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL);
            if(!$maintenance)throw new RuntimeException('maintenance_busy');
            $factory=FactoryDatabaseArchive::load($this->db,(string)($this->config['factory_storage_path']??'/var/lib/lorkhanserver/factory/current'),$payload['fingerprint']);
            (new DatabaseSqlBackup($this->config))->create($this->db,$payload['rollback_id'],$heartbeat);
            $mark=$this->db->prepare("UPDATE backup_records SET scope=scope||jsonb_build_object('rollback_for',CAST(:job AS text),'factory_reset',true) WHERE backup_id=:id");
            $mark->execute(['job'=>$job['job_id'],'id'=>$payload['rollback_id']]);
            if(!(new JobRepository($this->db))->heartbeat($job['job_id'],$job['lease_token'],3600))throw new RuntimeException('lease_lost');
            $deadline=hrtime(true)+600000000000;
            $this->db->beginTransaction();
            $this->db->exec("SET LOCAL statement_timeout='60s'; SET LOCAL lock_timeout='3s'");
            if(!hash_equals($factory['manifest']['migration_fingerprint'],(new \LorkhanServer\Infrastructure\MigrationRunner($this->db,dirname(__DIR__).'/data/migrations'))->replayFingerprint(false))
                ||!hash_equals($factory['manifest']['catalog_fingerprint'],FactoryDatabaseArchive::catalogFingerprint()))throw new RuntimeException('factory_source_changed');
            $state=new MigrationReplayState($this->db,$job['job_id']);$state->capture();
            $this->db->exec((string)file_get_contents(dirname(__DIR__).'/data/factory/reset.sql'));
            $this->db->exec($factory['sql']);unset($factory['sql']);
            $this->db->exec('SET LOCAL search_path TO lorkhan_internal, public, pg_temp');
            $seed=FactoryDatabaseArchive::seedState($this->db);
            foreach($seed as $key=>$value)if($factory['manifest'][$key]!==$value)throw new RuntimeException('factory_state_mismatch');
            $state->restore();
            $provisioner=new DefaultConnectorProvisioner($this->db,(string)($this->config['voice_storage_path']??'/var/lib/lorkhanserver/voices'),
                (string)($this->config['default_tts_endpoint']??'http://127.0.0.1:8086'));
            foreach($this->db->query('SELECT installation_id FROM installations WHERE revoked_at IS NULL ORDER BY installation_id')->fetchAll(PDO::FETCH_COLUMN) as $installation){
                if(hrtime(true)>$deadline)throw new RuntimeException('factory_reset_timeout');
                $provisioner->provision($installation);
            }
            if(hrtime(true)>$deadline)throw new RuntimeException('factory_reset_timeout');
            $this->db->commit();
        }catch(\Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            $code=preg_match('/^[a-z_]{1,80}$/D',$error->getMessage())?$error->getMessage():'factory_reset_failed';
            error_log('Lorkhan factory reset failed: '.$code.'.');throw $error;
        }finally{
            if($maintenance)$this->db->query('SELECT pg_advisory_unlock(7514,113)');
            $this->db->query('SELECT pg_advisory_unlock(7514,114)');
        }
    }
}
