<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use PDO;
use InvalidArgumentException;
use RuntimeException;

/** Manual, bounded archive-file cleanup. Never delete gameplay records or schedule automatic work. */
final class BackupFileRetention
{
    public function __construct(private readonly PDO $db) {}

    public function preview(int $days,string $cutoff):array
    {
        if($days<1||$days>3650||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$cutoff))throw new InvalidArgumentException('invalid_retention_preview');
        $time=strtotime($cutoff);
        if($time===false||$time>time()+60||$time<time()-86400)throw new InvalidArgumentException('retention_preview_expired');
        $query=$this->db->prepare("SELECT b.backup_id,b.byte_count+CASE WHEN jsonb_typeof(b.scope->'archive_bytes')='number' AND b.scope->>'archive_bytes' ~ '^[0-9]{1,10}$'
            THEN LEAST((b.scope->>'archive_bytes')::bigint,1073741824) ELSE 0 END AS byte_count,b.created_at,COALESCE(b.scope#>>'{snapshot,name}','Automatic backup') AS name,
            CASE WHEN jsonb_exists(b.scope,'snapshot') THEN 'snapshot' ELSE 'automatic' END AS kind
            FROM backup_records b WHERE b.scope->>'kind'='database_sql'
            AND (jsonb_exists(b.scope,'snapshot') OR b.scope->>'automatic'='true')
            AND b.created_at<CAST(:cutoff AS timestamptz)-make_interval(days=>CAST(:days AS integer))
            AND lower(COALESCE(b.scope#>>'{snapshot,name}',''))<>'default'
            AND NOT EXISTS(SELECT 1 FROM lorkhan_internal.database_snapshot_source s WHERE s.singleton AND s.backup_id=b.backup_id)
            AND NOT EXISTS(SELECT 1 FROM durable_jobs j WHERE j.job_type='database.restore' AND j.state IN ('queued','leased') AND j.payload->>'backup_id'=b.backup_id::text)
            ORDER BY b.created_at,b.backup_id LIMIT 100");
        $query->execute(['cutoff'=>$cutoff,'days'=>$days]);$rows=$query->fetchAll(PDO::FETCH_ASSOC);
        return ['days'=>$days,'cutoff'=>$cutoff,'files'=>$rows,'bytes'=>array_sum(array_column($rows,'byte_count')),
            'token'=>hash('sha256',json_encode([$days,$cutoff,$rows],JSON_THROW_ON_ERROR))];
    }

    public function confirm(int $days,string $cutoff,string $token,array $config):array
    {
        $plan=$this->preview($days,$cutoff);
        if(!hash_equals($plan['token'],$token))throw new RuntimeException('retention_preview_changed');
        $management=new ManagementRepository($this->db);$deleted=[];$skipped=[];
        foreach($plan['files'] as$row){
            try{$management->deleteStoredDatabaseBackup($row['backup_id'],$config,$row['kind']);$deleted[]=$row['backup_id'];}
            catch(RuntimeException $error){$skipped[]=$row['backup_id'];}
        }
        return ['deleted'=>$deleted,'skipped'=>$skipped,'gameplay_records_deleted'=>0];
    }
}
