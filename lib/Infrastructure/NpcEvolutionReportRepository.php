<?php

declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Immutable history inputs and lease-fenced report results, separate from editable NPC content. */
final class NpcEvolutionReportRepository
{
    public function __construct(private readonly PDO $db) {}

    public function profile(string $installation,string $profile):array
    {
        if(!Uuid::isValid($installation)||!\LorkhanServer\Domain\ProfileId::isValid($profile))throw new InvalidArgumentException('invalid_report_scope');
        $q=$this->db->prepare("SELECT profile_id,name,current_revision FROM profiles WHERE profile_id=:profile AND installation_id=:installation AND deleted_at IS NULL AND COALESCE(actor_identity->>'kind','actor') IN ('actor','npc','creature')");
        $q->execute(['profile'=>$profile,'installation'=>$installation]);return $q->fetch()?:throw new RuntimeException('not_found');
    }

    /** Deduplicate personality snapshots chronologically, just as the reference report does. */
    public function history(string $profile,int $revision):array
    {
        $q=$this->db->prepare("SELECT revision,created_at,content->>'biography' AS biography,content->>'personality' AS personality FROM profile_revisions WHERE profile_id=:profile AND revision<=:revision ORDER BY revision");
        $q->execute(['profile'=>$profile,'revision'=>$revision]);$seen=[];$history=[];$bytes=0;
        while($row=$q->fetch()){
            $personality=(string)($row['personality']??'');$key=hash('sha256',$personality);
            if(isset($seen[$key]))continue;$seen[$key]=true;
            $text=trim((string)($row['biography']??'')."\n".$personality);if($text==='')continue;
            $entry=['revision'=>(int)$row['revision'],'created_at'=>$row['created_at'],'text'=>$text];
            $bytes+=strlen(json_encode($entry,JSON_THROW_ON_ERROR));
            if($bytes>80000||count($history)>=400)throw new InvalidArgumentException('report_history_too_large');
            $history[]=$entry;
        }
        if($history===[])throw new InvalidArgumentException('report_history_empty');return $history;
    }

    public function enqueue(string $installation,string $profile,string $request):array
    {
        if(!Uuid::isValid($request))throw new InvalidArgumentException('invalid_report_request');
        $npc=$this->profile($installation,$profile);$key='npc-report:'.$profile.':'.$request;
        $this->db->beginTransaction();
        try{
            $existing=$this->db->prepare("SELECT j.job_id,j.state FROM durable_jobs j JOIN npc_evolution_reports r ON r.job_id=j.job_id WHERE j.job_type='profile.report' AND j.idempotency_key=:key AND r.installation_id=:installation AND r.profile_id=:profile");
            $existing->execute(['key'=>$key,'installation'=>$installation,'profile'=>$profile]);
            if($row=$existing->fetch()){$this->db->commit();return $row;}
            $products=new ProductRepository($this->db);$globals=$products->globalSettingsForInstallation($installation)['content']??[];
            if(($globals['task_availability']['background_memory']??true)!==true)throw new InvalidArgumentException('report_connector_disabled');
            $route=(string)($globals['system_routing']['background_memory_configuration_id']??'');
            if($route==='')throw new InvalidArgumentException('report_connector_disabled');
            $provider=$products->getRevisioned('provider',$route);
            if($provider['installation_id']!==$installation)throw new InvalidArgumentException('report_connector_unavailable');
            $history=$this->history($profile,(int)$npc['current_revision']);$job=Uuid::v4();
            $payload=['installation_id'=>$installation,'profile_id'=>$profile,'provider_configuration_id'=>$provider['configuration_id'],'provider_revision'=>(int)$provider['current_revision']];
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.report',1,:key,CAST(:payload AS jsonb),1,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$job,'key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);$result=$insert->fetch();
            if(!$result){$existing->execute(['key'=>$key,'installation'=>$installation,'profile'=>$profile]);$result=$existing->fetch();if(!$result)throw new RuntimeException('report_queue_failed');$this->db->commit();return $result;}
            $save=$this->db->prepare("INSERT INTO npc_evolution_reports(job_id,installation_id,profile_id,base_revision,npc_name,history) VALUES(:job,:installation,:profile,:revision,:name,CAST(:history AS jsonb))");
            $save->execute(['job'=>$job,'installation'=>$installation,'profile'=>$profile,'revision'=>$npc['current_revision'],'name'=>$npc['name'],'history'=>json_encode($history,JSON_THROW_ON_ERROR)]);
            $this->db->commit();return $result;
        }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    public function input(string $installation,string $profile,string $job):array
    {
        $this->profile($installation,$profile);
        $q=$this->db->prepare('SELECT npc_name,history FROM npc_evolution_reports WHERE job_id=:job AND installation_id=:installation AND profile_id=:profile');
        $q->execute(['job'=>$job,'installation'=>$installation,'profile'=>$profile]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('not_found');return ['name'=>$row['npc_name'],'history'=>json_decode($row['history'],true,32,JSON_THROW_ON_ERROR)];
    }

    public function status(string $installation,string $profile,string $job):array
    {
        if(!Uuid::isValid($job))throw new InvalidArgumentException('invalid_report_job');$this->profile($installation,$profile);
        $q=$this->db->prepare("SELECT j.state,r.report,r.base_revision FROM npc_evolution_reports r JOIN durable_jobs j ON j.job_id=r.job_id WHERE r.job_id=:job AND r.installation_id=:installation AND r.profile_id=:profile AND j.job_type='profile.report'");
        $q->execute(['job'=>$job,'installation'=>$installation,'profile'=>$profile]);$row=$q->fetch();if(!$row)throw new RuntimeException('not_found');
        return ['state'=>$row['state'],'base_revision'=>(int)$row['base_revision'],'report'=>$row['state']==='succeeded'?$row['report']:null];
    }

    public function save(string $job,int $attempt,string $lease,string $report):void
    {
        if(trim($report)===''||strlen($report)>8192||!mb_check_encoding($report,'UTF-8'))throw new InvalidArgumentException('invalid_report_output');
        $q=$this->db->prepare("UPDATE npc_evolution_reports r SET report=:report FROM durable_jobs j WHERE r.job_id=:job AND j.job_id=r.job_id AND j.job_type='profile.report' AND j.state='leased' AND j.attempt_count=:attempt AND j.lease_token=:lease AND j.lease_expires_at>clock_timestamp()");
        $q->execute(['report'=>$report,'job'=>$job,'attempt'=>$attempt,'lease'=>$lease]);if($q->rowCount()!==1)throw new RuntimeException('lease_lost');
    }
}
