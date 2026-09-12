<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\DatabaseSqlBackup;
use LorkhanServer\Infrastructure\Uuid;
use PDO;
use RuntimeException;

/** Capture an explicitly queued full SQL backup without blocking the web request. */
final class DatabaseBackupJobHandler implements JobHandler
{
    public const TYPE='database.backup';
    public function __construct(private readonly PDO $db,private readonly array $config) {}
    public function supports(string $jobType,int $schemaVersion):bool{return $jobType===self::TYPE&&$schemaVersion===1;}
    public function handle(array $payload,string $idempotencyKey,callable $heartbeat):void
    {
        unset($payload['_job']);
        $snapshot=$payload['snapshot']??null;unset($payload['snapshot']);
        if($snapshot!==null)$snapshot=\LorkhanServer\Infrastructure\ManagementRepository::snapshotMetadata($snapshot);
        $automatic=$payload['automatic']??false;unset($payload['automatic']);
        if(!is_bool($automatic)||($automatic&&$snapshot!==null))throw new \InvalidArgumentException('invalid_database_backup_job');
        if(array_keys($payload)!==['backup_id']||!Uuid::isValid($payload['backup_id']))throw new \InvalidArgumentException('invalid_database_backup_job');
        if(!filter_var($this->db->query('SELECT pg_try_advisory_lock(7514,113)')->fetchColumn(),FILTER_VALIDATE_BOOL))throw new RuntimeException('maintenance_busy');
        try{
            $store=new DatabaseSqlBackup($this->config);
            // An opt-out also cancels automatic work that has not started yet.
            if($automatic && !(new \LorkhanServer\Infrastructure\ManagementRepository($this->db))->databaseBackupSettings()['enabled'])throw new RuntimeException('automatic_backup_disabled');
            $store->create($this->db,$payload['backup_id'],$heartbeat,$automatic);
            if($snapshot!==null){
                $save=$this->db->prepare("UPDATE backup_records SET scope=scope||jsonb_build_object('snapshot',CAST(:snapshot AS jsonb)) WHERE backup_id=:id");
                $save->execute(['snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'id'=>$payload['backup_id']]);
            }
            if($automatic)$store->pruneAutomatic($this->db,$payload['backup_id']);
        }
        catch(\Throwable $error){
            $reason=preg_match('/^[a-z_]{1,80}$/D',$error->getMessage())?$error->getMessage():'backup_operation_failed';
            error_log('Lorkhan SQL backup failed: '.$reason.' ('.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().').');throw $error;
        }
        finally{$this->db->query('SELECT pg_advisory_unlock(7514,113)');}
    }
}
