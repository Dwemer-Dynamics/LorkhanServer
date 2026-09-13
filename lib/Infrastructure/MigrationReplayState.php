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
    public function __construct(private readonly PDO $db, private readonly string $jobId, private readonly bool $preserveTimeline=false)
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
        if ($this->preserveTimeline) foreach (['timeline_invalidated_turns','timeline_invalidated_sources'] as $table) {
            if ($this->db->query("SELECT to_regclass('lorkhan_internal.$table')")->fetchColumn()!==null)
                $this->db->exec("CREATE TEMP TABLE replay_$table ON COMMIT DROP AS SELECT * FROM lorkhan_internal.$table");
        }
        if((int)$this->db->query('SELECT count(*) FROM pg_temp.replay_durable_jobs')->fetchColumn()!==1)
            throw new RuntimeException('replay_job_missing');
    }

    /** Upsert exact captured rows whether the migration retained or recreated each protected table. */
    public function restore(bool $reassignAttemptIds=false):void
    {
        if(!$this->db->inTransaction())throw new RuntimeException('replay_transaction_required');
        foreach(self::TABLES as$table=>$keys){
            $columns=$this->db->query("SELECT attname FROM pg_attribute WHERE attrelid='lorkhan_internal.".$table."'::regclass AND attnum>0 AND NOT attisdropped ORDER BY attnum")->fetchAll(PDO::FETCH_COLUMN);
            $captured=$this->db->query("SELECT attname FROM pg_attribute WHERE attrelid='pg_temp.replay_".$table."'::regclass AND attnum>0 AND NOT attisdropped ORDER BY attnum")->fetchAll(PDO::FETCH_COLUMN);
            if(array_diff($columns,$captured)!==[]||array_diff($captured,$columns)!==[])throw new RuntimeException('replay_control_schema_mismatch');
            if($reassignAttemptIds&&$table==='durable_job_attempts'){
                // Imported history can already use this job's local numeric attempt IDs. Preserve logical job/attempt identity instead.
                $columns=array_values(array_diff($columns,['attempt_id']));$keys=['job_id','attempt_number'];
                $sequence=$this->db->query("SELECT pg_get_serial_sequence('lorkhan_internal.durable_job_attempts','attempt_id')")->fetchColumn();
                $next=$this->db->query('SELECT GREATEST(COALESCE((SELECT max(attempt_id)::numeric+1 FROM lorkhan_internal.durable_job_attempts),1),COALESCE((SELECT max(attempt_id)::numeric+1 FROM pg_temp.replay_durable_job_attempts),1),(SELECT last_value::numeric+CASE WHEN is_called THEN 1 ELSE 0 END FROM '.$sequence.'))::text')->fetchColumn();
                $this->db->exec('ALTER SEQUENCE '.$sequence.' RESTART WITH '.$next);
            }
            $columnList=implode(',',array_map(static fn(string $column):string=>'"'.str_replace('"','""',$column).'"',$columns));
            $updates=[];
            foreach(array_diff($columns,$keys)as$column){$quoted='"'.str_replace('"','""',$column).'"';$updates[]=$quoted.'=EXCLUDED.'.$quoted;}
            $this->db->exec('INSERT INTO lorkhan_internal.'.$table.' ('.$columnList.') SELECT '.$columnList.' FROM pg_temp.replay_'.$table.' WHERE true'
                .' ON CONFLICT ('.implode(',',$keys).') DO UPDATE SET '.implode(',',$updates).' WHERE '.$table.' IS DISTINCT FROM EXCLUDED');
            if($this->db->query('SELECT EXISTS(SELECT '.$columnList.' FROM pg_temp.replay_'.$table.' EXCEPT SELECT '.$columnList.' FROM lorkhan_internal.'.$table.')')->fetchColumn())
                throw new RuntimeException('replay_control_state_mismatch');
        }
        // A replay from 107 retains source rows; an earlier replay may deliberately recreate them.
        if ($this->preserveTimeline) foreach (['turns'=>'turn_id','sources'=>'source_event_id'] as $kind=>$id) {
            $table='timeline_invalidated_'.$kind;
            if ($this->db->query("SELECT to_regclass('pg_temp.replay_$table')")->fetchColumn()===null) continue;
            $parent=$kind==='turns'?'turns':'source_events';
            $this->db->exec("INSERT INTO lorkhan_internal.$table SELECT saved.* FROM pg_temp.replay_$table saved
                JOIN lorkhan_internal.$parent p ON p.$id=saved.$id
                JOIN lorkhan_internal.source_events load ON load.source_event_id=saved.loaded_save_id
                ON CONFLICT ($id) DO NOTHING");
        }
        if(!$reassignAttemptIds)$this->db->exec("SELECT pg_catalog.setval(pg_get_serial_sequence('lorkhan_internal.durable_job_attempts','attempt_id'), GREATEST(1,(SELECT COALESCE(max(attempt_id),0) FROM lorkhan_internal.durable_job_attempts)),true)");
    }
}
