<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\MemorySummaryPolicy;
use PDO;

/** Model projections never replace original memory content or its privacy provenance. */
final class MemorySummaryRepository
{
    public function __construct(private readonly PDO $db) {}

    public function policy(string $installation): ?array
    {
        $query=$this->db->prepare("SELECT c.configuration_id,c.current_revision,r.content FROM configuration_sets c
            JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
            WHERE c.installation_id=:installation AND c.kind='memory_policy' AND c.deleted_at IS NULL");
        $query->execute(['installation'=>$installation]);$row=$query->fetch();
        if(!$row)return null;
        $row['content']=MemorySummaryPolicy::validate(json_decode($row['content'],true,16,JSON_THROW_ON_ERROR));
        return $row;
    }

    public function assertProvider(string $installation,array $content):void
    {
        MemorySummaryPolicy::validate($content);
        if($content['provider_configuration_id']==='')return;
        $query=$this->db->prepare("SELECT 1 FROM configuration_sets WHERE configuration_id=:id
            AND installation_id=:installation AND kind='provider' AND deleted_at IS NULL FOR SHARE");
        $query->execute(['id'=>$content['provider_configuration_id'],'installation'=>$installation]);
        if(!$query->fetchColumn())throw new \InvalidArgumentException('memory_provider_installation_mismatch');
    }

    /** Freeze policy/provider revisions for one eligible memory; off never creates a job. */
    public function enqueue(string $installation,string $memoryId,?int $expectedRevision=null):?array
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $query=$this->db->prepare("SELECT m.memory_id,m.installation_id,m.profile_id,m.playthrough_id,m.current_revision,
                c.configuration_id AS policy_configuration_id,c.current_revision AS policy_revision,
                p.configuration_id AS provider_configuration_id,p.current_revision AS provider_revision
                FROM memory_records m JOIN configuration_sets c ON c.installation_id=m.installation_id
                    AND c.kind='memory_policy' AND c.deleted_at IS NULL
                JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
                JOIN configuration_sets p ON p.configuration_id::text=r.content->>'provider_configuration_id'
                    AND p.installation_id=m.installation_id AND p.kind='provider' AND p.deleted_at IS NULL
                WHERE m.memory_id=:memory AND m.installation_id=:installation AND m.deleted_at IS NULL
                    AND (m.expires_at IS NULL OR m.expires_at>clock_timestamp())
                    AND m.derivation_key IS NOT NULL AND m.tier IN ('mid','long') AND m.provenance->>'source'='memory.consolidate'
                    AND m.provenance->>'provider'='first-party' AND m.provenance->>'model'='deterministic-extractive-v1'
                    AND r.content->'enabled'='true'::jsonb
                    AND NOT EXISTS(SELECT 1 FROM memory_model_summaries s WHERE s.memory_id=m.memory_id AND s.memory_revision=m.current_revision)
                FOR UPDATE OF m FOR SHARE OF c,p");
            $query->execute(['memory'=>$memoryId,'installation'=>$installation]);$row=$query->fetch();
            if(!$row||($expectedRevision!==null&&(int)$row['current_revision']!==$expectedRevision)){
                if($owns)$this->db->commit();return null;
            }
            $payload=$row;$payload['memory_revision']=(int)$payload['current_revision'];unset($payload['current_revision']);
            foreach(['policy_revision','provider_revision']as$field)$payload[$field]=(int)$payload[$field];
            $pending=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='memory.summarize'
                AND state IN ('queued','leased') AND payload->>'memory_id'=:memory AND payload->>'memory_revision'=:revision LIMIT 1");
            $pending->execute(['memory'=>$memoryId,'revision'=>(string)$payload['memory_revision']]);$active=$pending->fetch();
            if($active){if($owns)$this->db->commit();return $active;}
            $key='memory.summary:'.$memoryId.':'.$payload['memory_revision'].':'.$payload['policy_configuration_id']
                .':'.$payload['policy_revision'].':'.$payload['provider_revision'];
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority)
                VALUES(:id,'memory.summarize',1,:key,CAST(:payload AS jsonb),3,25)
                ON CONFLICT(job_type,idempotency_key) DO NOTHING");
            $insert->execute(['id'=>Uuid::v4(),'key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            $find=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='memory.summarize' AND idempotency_key=:key");
            $find->execute(['key'=>$key]);$job=$find->fetch();
            if($owns)$this->db->commit();return $job?:null;
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
    /** Disabled policy, changed memory, completed projection, or lost lease makes the job a no-op. */
    public function input(array $payload):?array
    {
        $query=$this->db->prepare("SELECT m.content,m.tier FROM memory_records m
            JOIN configuration_sets c ON c.configuration_id=:policy AND c.installation_id=m.installation_id
                AND c.kind='memory_policy' AND c.deleted_at IS NULL
            JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
            JOIN configuration_revisions frozen ON frozen.configuration_id=c.configuration_id AND frozen.revision=:policy_revision
                AND frozen.content->'enabled'='true'::jsonb AND frozen.content->>'provider_configuration_id'=:provider
            JOIN durable_jobs j ON j.job_id=:job AND j.state='leased' AND j.lease_token=:lease
                AND j.attempt_count=:attempt AND j.lease_expires_at>clock_timestamp()
            WHERE m.memory_id=:memory AND m.current_revision=:revision AND m.installation_id=:installation
                AND m.profile_id=:profile AND m.playthrough_id=:playthrough AND m.deleted_at IS NULL
                AND (m.expires_at IS NULL OR m.expires_at>clock_timestamp())
                AND m.derivation_key IS NOT NULL AND m.tier IN ('mid','long') AND m.provenance->>'source'='memory.consolidate'
                AND m.provenance->>'provider'='first-party' AND m.provenance->>'model'='deterministic-extractive-v1'
                AND r.content->'enabled'='true'::jsonb
                AND NOT EXISTS(SELECT 1 FROM memory_model_summaries s WHERE s.memory_id=m.memory_id AND s.memory_revision=m.current_revision)
            FOR SHARE OF m,c,j");
        $query->execute($this->parameters($payload));$row=$query->fetch();
        return $row?:null;
    }

    /** Brief row locks fence the write; no provider I/O runs in this transaction. */
    public function save(array $payload,string $inputHash,string $summary,string $now):bool
    {
        $summary=MemorySummaryPolicy::summary(['summary'=>$summary]);
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $input=$this->input($payload);
            if($input===null||hash('sha256',$input['content'])!==$inputHash){
                if($owns)$this->db->commit();return false;
            }
            $insert=$this->db->prepare("INSERT INTO memory_model_summaries
                (memory_id,memory_revision,policy_configuration_id,policy_revision,provider_configuration_id,provider_revision,content,input_sha256,created_at)
                VALUES(:memory,:revision,:policy,:policy_revision,:provider,:provider_revision,:content,:sha,:now)
                ON CONFLICT(memory_id,memory_revision) DO NOTHING");
            $insert->execute(['memory'=>$payload['memory_id'],'revision'=>$payload['memory_revision'],
                'policy'=>$payload['policy_configuration_id'],'policy_revision'=>$payload['policy_revision'],
                'provider'=>$payload['provider_configuration_id'],'provider_revision'=>$payload['provider_revision'],
                'content'=>$summary,'sha'=>$inputHash,'now'=>$now]);
            $saved=$insert->rowCount()===1;if($owns)$this->db->commit();return $saved;
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    private function parameters(array $payload):array
    {
        return ['memory'=>$payload['memory_id'],'revision'=>$payload['memory_revision'],
            'policy'=>$payload['policy_configuration_id'],'installation'=>$payload['installation_id'],
            'policy_revision'=>$payload['policy_revision'],'provider'=>$payload['provider_configuration_id'],
            'profile'=>$payload['profile_id'],'playthrough'=>$payload['playthrough_id'],
            'job'=>$payload['_job']['job_id'],'lease'=>$payload['_job']['lease_token'],'attempt'=>$payload['_job']['attempt']];
    }
}
