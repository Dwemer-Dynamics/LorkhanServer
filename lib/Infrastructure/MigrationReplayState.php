<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/** Keep control-plane identities and the current job inside the replay transaction, never in browser payloads. */
final class MigrationReplayState
{
    private const TABLES=[
        'installations'=>['installation_id'], 'pairing_tokens'=>['pairing_token_id'],
        'request_mac_nonces'=>['pairing_token_id','nonce'], 'browser_sessions'=>['session_hash'],
        'backup_records'=>['backup_id'], 'database_backup_settings'=>['singleton'],
        'durable_jobs'=>['job_id'], 'durable_job_attempts'=>['attempt_id'],
    ];
    public function __construct(private readonly PDO $db, private readonly string $jobId)
    {
        if(!Uuid::isValid($jobId))throw new RuntimeException('invalid_replay_job');
    }

    public function capture():void
    {
        if(!$this->db->inTransaction())throw new RuntimeException('replay_transaction_required');
        foreach(self::TABLES as$table=>$keys){
            $where=in_array($table,['durable_jobs','durable_job_attempts'],true)?' WHERE job_id='.$this->db->quote($this->jobId):'';
            $this->db->exec('CREATE TEMP TABLE replay_'.$table.' ON COMMIT DROP AS SELECT * FROM lorkhan_internal.'.$table.$where);
        }
        if((int)$this->db->query('SELECT count(*) FROM pg_temp.replay_durable_jobs')->fetchColumn()!==1)
            throw new RuntimeException('replay_job_missing');
    }

    /** Upsert exact captured rows whether the migration retained or recreated each protected table. */
    public function restore():void
    {
        if(!$this->db->inTransaction())throw new RuntimeException('replay_transaction_required');
        foreach(self::TABLES as$table=>$keys){
            $columns=$this->db->query("SELECT attname FROM pg_attribute WHERE attrelid='lorkhan_internal.".$table."'::regclass AND attnum>0 AND NOT attisdropped ORDER BY attnum")->fetchAll(PDO::FETCH_COLUMN);
            $captured=$this->db->query("SELECT attname FROM pg_attribute WHERE attrelid='pg_temp.replay_".$table."'::regclass AND attnum>0 AND NOT attisdropped ORDER BY attnum")->fetchAll(PDO::FETCH_COLUMN);
            if(array_diff($columns,$captured)!==[]||array_diff($captured,$columns)!==[])throw new RuntimeException('replay_control_schema_mismatch');
            $columnList=implode(',',array_map(static fn(string $column):string=>'"'.str_replace('"','""',$column).'"',$columns));
            $updates=[];
            foreach(array_diff($columns,$keys)as$column){$quoted='"'.str_replace('"','""',$column).'"';$updates[]=$quoted.'=EXCLUDED.'.$quoted;}
            $this->db->exec('INSERT INTO lorkhan_internal.'.$table.' ('.$columnList.') SELECT '.$columnList.' FROM pg_temp.replay_'.$table.' WHERE true'
                .' ON CONFLICT ('.implode(',',$keys).') DO UPDATE SET '.implode(',',$updates).' WHERE '.$table.' IS DISTINCT FROM EXCLUDED');
            if($this->db->query('SELECT EXISTS(SELECT '.$columnList.' FROM pg_temp.replay_'.$table.' EXCEPT SELECT '.$columnList.' FROM lorkhan_internal.'.$table.')')->fetchColumn())
                throw new RuntimeException('replay_control_state_mismatch');
        }
        $this->db->exec("SELECT pg_catalog.setval(pg_get_serial_sequence('lorkhan_internal.durable_job_attempts','attempt_id'), GREATEST(1,(SELECT COALESCE(max(attempt_id),0) FROM lorkhan_internal.durable_job_attempts)),true)");
    }
}
