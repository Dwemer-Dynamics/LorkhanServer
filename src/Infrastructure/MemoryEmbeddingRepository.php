<?php
declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Application\MemoryEmbeddingPolicy;
use PDO;

/** Freeze opt-in embedding policy and memory revisions across MiniMe calls. */
final class MemoryEmbeddingRepository
{
    public function __construct(private readonly PDO $db) {}

    public function policy(string $installation):?array
    {
        $query=$this->db->prepare("SELECT c.configuration_id,c.current_revision,r.content FROM configuration_sets c
            JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
            WHERE c.installation_id=:installation AND c.kind='memory_embedding_policy' AND c.deleted_at IS NULL");
        $query->execute(['installation'=>$installation]);$row=$query->fetch();
        if(!$row)return null;
        $row['current_revision']=(int)$row['current_revision'];
        $row['content']=MemoryEmbeddingPolicy::validate(json_decode($row['content'],true,16,JSON_THROW_ON_ERROR));
        return$row;
    }

    /** Return the current explicit endpoint without contacting it. */
    public function runtime(string $installation):array
    {
        $policy=$this->policy($installation);
        if($policy===null)return['status'=>'unconfigured','policy'=>null];
        $content=$policy['content'];
        return['status'=>$content['enabled']?'ready':'disabled','policy'=>$policy];
    }

    /** Queue one exact active memory revision only while the current policy is enabled. */
    public function enqueue(string $installation,string $memoryId,?int $expectedRevision=null):?array
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $query=$this->db->prepare("SELECT m.memory_id,m.installation_id,m.profile_id,m.playthrough_id,m.content,
                m.current_revision AS memory_revision,
                c.configuration_id AS policy_configuration_id,c.current_revision AS policy_revision
                FROM memory_records m JOIN configuration_sets c ON c.installation_id=m.installation_id
                    AND c.kind='memory_embedding_policy' AND c.deleted_at IS NULL
                JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
                WHERE m.memory_id=:memory AND m.installation_id=:installation AND m.deleted_at IS NULL
                    AND (m.expires_at IS NULL OR m.expires_at>clock_timestamp())
                    AND r.content->'enabled'='true'::jsonb AND COALESCE(r.content->>'endpoint','')<>''
                    AND NOT EXISTS(SELECT 1 FROM memory_embeddings e WHERE e.memory_id=m.memory_id
                        AND e.memory_revision=m.current_revision AND e.policy_configuration_id=c.configuration_id
                        AND e.policy_revision=c.current_revision)
                FOR UPDATE OF m FOR SHARE OF c");
            $query->execute(['memory'=>$memoryId,'installation'=>$installation]);$payload=$query->fetch();
            if(!$payload||($expectedRevision!==null&&(int)$payload['memory_revision']!==$expectedRevision)){
                if($owns)$this->db->commit();return null;
            }
            foreach(['memory_revision','policy_revision']as$field)$payload[$field]=(int)$payload[$field];
            $payload['input_sha256']=hash('sha256',(string)$payload['content']);unset($payload['content']);
            $key='memory.embed:'.$memoryId.':'.$payload['memory_revision'].':'.$payload['policy_configuration_id']
                .':'.$payload['policy_revision'];
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority)
                VALUES(:id,'memory.embed',1,:key,CAST(:payload AS jsonb),3,24)
                ON CONFLICT(job_type,idempotency_key) DO NOTHING");
            $insert->execute(['id'=>Uuid::v4(),'key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            $created=$insert->rowCount()===1;
            $find=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='memory.embed' AND idempotency_key=:key");
            $find->execute(['key'=>$key]);$job=$find->fetch();if($job)$job['created']=$created;
            if($owns)$this->db->commit();return$job?:null;
        }catch(\Throwable$error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    /** Explicitly queue a bounded page of missing current vectors; policy Save never calls this. */
    public function enqueueBatch(string $installation,int $limit=100):array
    {
        if($limit<1||$limit>500)throw new \InvalidArgumentException('invalid_memory_embedding_limit');
        $query=$this->db->prepare("SELECT m.memory_id,m.current_revision FROM memory_records m
            JOIN configuration_sets c ON c.installation_id=m.installation_id
                AND c.kind='memory_embedding_policy' AND c.deleted_at IS NULL
            JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
            WHERE m.installation_id=:installation AND m.deleted_at IS NULL
                AND (m.expires_at IS NULL OR m.expires_at>clock_timestamp())
                AND r.content->'enabled'='true'::jsonb AND COALESCE(r.content->>'endpoint','')<>''
                AND NOT EXISTS(SELECT 1 FROM memory_embeddings e WHERE e.memory_id=m.memory_id
                    AND e.memory_revision=m.current_revision AND e.policy_configuration_id=c.configuration_id
                    AND e.policy_revision=c.current_revision)
                AND NOT EXISTS(SELECT 1 FROM durable_jobs j WHERE j.job_type='memory.embed'
                    AND j.payload->>'memory_id'=m.memory_id::text
                    AND j.payload->>'memory_revision'=m.current_revision::text
                    AND j.payload->>'policy_configuration_id'=c.configuration_id::text
                    AND j.payload->>'policy_revision'=c.current_revision::text)
            ORDER BY m.occurred_at,m.memory_id LIMIT :limit");
        $query->bindValue(':installation',$installation);$query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();
        $queued=0;foreach($query->fetchAll()as$row){
            $job=$this->enqueue($installation,(string)$row['memory_id'],(int)$row['current_revision']);
            if(($job['created']??false)===true)++$queued;
        }
        return['queued'=>$queued,'limit'=>$limit];
    }

    /** Disabled/changed policy, edited memory, completed projection or lost lease makes work stale. */
    public function input(array $payload):?array
    {
        $query=$this->db->prepare("SELECT m.content,r.content AS policy_content FROM memory_records m
            JOIN configuration_sets c ON c.configuration_id=:policy AND c.installation_id=m.installation_id
                AND c.kind='memory_embedding_policy' AND c.deleted_at IS NULL AND c.current_revision=:policy_revision
            JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
            JOIN durable_jobs j ON j.job_id=:job AND j.state='leased' AND j.lease_token=:lease
                AND j.attempt_count=:attempt AND j.lease_expires_at>clock_timestamp()
            WHERE m.memory_id=:memory AND m.current_revision=:revision AND m.installation_id=:installation
                AND m.profile_id=:profile AND m.playthrough_id=:playthrough AND m.deleted_at IS NULL
                AND (m.expires_at IS NULL OR m.expires_at>clock_timestamp())
                AND r.content->'enabled'='true'::jsonb AND COALESCE(r.content->>'endpoint','')<>''
                AND NOT EXISTS(SELECT 1 FROM memory_embeddings e WHERE e.memory_id=m.memory_id
                    AND e.memory_revision=m.current_revision AND e.policy_configuration_id=c.configuration_id
                    AND e.policy_revision=c.current_revision)
            FOR SHARE OF m,c,j");
        $query->execute($this->parameters($payload));$row=$query->fetch();
        if(!$row||!hash_equals((string)$payload['input_sha256'],hash('sha256',(string)$row['content'])))return null;
        $row['policy_content']=MemoryEmbeddingPolicy::validate(
            json_decode($row['policy_content'],true,16,JSON_THROW_ON_ERROR));
        return$row;
    }

    /** Persist only one validated provider result for the still-current memory and policy revisions. */
    public function save(array $payload,array $embedding,string $model,string $now):bool
    {
        if(!array_is_list($embedding)||count($embedding)<8||count($embedding)>1536||$model===''||strlen($model)>256)
            throw new \InvalidArgumentException('invalid_memory_embedding');
        foreach($embedding as$value)if(!is_int($value)&&!is_float($value)||!is_finite((float)$value))
            throw new \InvalidArgumentException('invalid_memory_embedding');
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            if($this->input($payload)===null){if($owns)$this->db->commit();return false;}
            $insert=$this->db->prepare("INSERT INTO memory_embeddings
                (memory_id,memory_revision,policy_configuration_id,policy_revision,dimensions,embedding,input_sha256,model,created_at)
                VALUES(:memory,:revision,:policy,:policy_revision,:dimensions,CAST(:embedding AS jsonb),:sha,:model,:now)
                ON CONFLICT DO NOTHING");
            $insert->execute(['memory'=>$payload['memory_id'],'revision'=>$payload['memory_revision'],
                'policy'=>$payload['policy_configuration_id'],'policy_revision'=>$payload['policy_revision'],
                'dimensions'=>count($embedding),'embedding'=>json_encode($embedding,JSON_THROW_ON_ERROR),
                'sha'=>$payload['input_sha256'],'model'=>$model,'now'=>$now]);
            $saved=$insert->rowCount()===1;if($owns)$this->db->commit();return$saved;
        }catch(\Throwable$error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$error;}
    }

    private function parameters(array $payload):array
    {
        return['memory'=>$payload['memory_id'],'revision'=>$payload['memory_revision'],
            'policy'=>$payload['policy_configuration_id'],'policy_revision'=>$payload['policy_revision'],
            'installation'=>$payload['installation_id'],'profile'=>$payload['profile_id'],
            'playthrough'=>$payload['playthrough_id'],
            'job'=>$payload['_job']['job_id'],'lease'=>$payload['_job']['lease_token'],
            'attempt'=>$payload['_job']['attempt']];
    }
}
