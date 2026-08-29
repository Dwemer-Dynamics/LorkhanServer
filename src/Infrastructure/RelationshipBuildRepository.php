<?php
declare(strict_types=1);
namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Application\OperationCancelled;
use ALMSIVIserver\Application\RelationshipBuildPolicy;
use ALMSIVIserver\Application\RelationshipType;
use PDO;

/** Explicit history builds preserve source, scope, lifecycle and manual-edit ownership. */
final class RelationshipBuildRepository
{
    private readonly RelationshipEvaluationRepository $evaluations;
    public function __construct(private readonly PDO $db)
    {
        $this->evaluations=new RelationshipEvaluationRepository($db);
    }

    /** Queue one bounded analysis, never a history scan from page viewing or automatic chance. */
    public function enqueue(array $scope,string $requestId,int $limit=100):array
    {
        foreach(['installation_id','profile_id','playthrough_id'] as $field)
            if(!is_string($scope[$field]??null)||!Uuid::isValid($scope[$field]))
                throw new \InvalidArgumentException('invalid_relationship_scope');
        if(!Uuid::isValid($requestId)||$limit<1||$limit>100)throw new \InvalidArgumentException('invalid_relationship_build_request');
        $scope=['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id'],'playthrough_id'=>$scope['playthrough_id']];
        return $this->transaction(function()use($scope,$requestId,$limit):array{
            $state=$this->scopeState($scope);
            $lock=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');
            $lock->execute(['key'=>'relationship.build:'.implode(':',$scope)]);
            $key='relationship.build:'.$requestId;
            $query=$this->db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='relationship.build' AND idempotency_key=:key");
            $query->execute(['key'=>$key]);$existing=$query->fetch();
            if($existing){
                $payload=json_decode($existing['payload'],true,64,JSON_THROW_ON_ERROR);
                foreach($scope as $field=>$value)if(($payload[$field]??null)!==$value)
                    throw new \InvalidArgumentException('relationship_build_request_conflict');
                if($payload['history_limit']!==$limit)throw new \InvalidArgumentException('relationship_build_request_conflict');
                return ['job_id'=>$existing['job_id'],'state'=>$existing['state']];
            }
            $pending=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_type='relationship.build' AND state IN ('queued','leased')
                AND payload->>'installation_id'=:installation_id AND payload->>'profile_id'=:profile_id AND payload->>'playthrough_id'=:playthrough_id LIMIT 1");
            $pending->execute($scope);if($pending->fetchColumn())throw new \InvalidArgumentException('relationship_build_pending');
            $policy=$this->evaluations->policy($scope['installation_id'],$scope['profile_id']);
            if($policy===null)throw new \InvalidArgumentException('invalid_relationship_scope');
            if($policy['locked'])throw new \InvalidArgumentException('relationship_build_locked');
            if($policy['provider_configuration_id']==='')throw new \InvalidArgumentException('relationship_build_no_connector');
            $provider=$this->db->prepare("SELECT current_revision FROM configuration_sets WHERE configuration_id=:provider
                AND installation_id=:installation AND kind='provider' AND deleted_at IS NULL FOR SHARE");
            $provider->execute(['provider'=>$policy['provider_configuration_id'],'installation'=>$scope['installation_id']]);
            $providerRevision=$provider->fetchColumn();
            if($providerRevision===false)throw new \InvalidArgumentException('relationship_build_no_connector');
            $query=$this->db->prepare("SELECT delivery.source_event_id FROM turns t JOIN sessions s ON s.session_id=t.session_id
                JOIN LATERAL (SELECT d.source_event_id FROM dialogue_delivery_results d WHERE d.turn_id=t.turn_id AND d.status='played'
                    ORDER BY d.completed_at DESC,d.source_event_id DESC LIMIT 1) delivery ON true
                WHERE s.installation_id=:installation_id AND s.playthrough_id=:playthrough_id AND t.state='complete'
                    AND EXISTS(SELECT 1 FROM prompt_traces trace WHERE trace.turn_id=t.turn_id AND trace.selected_profile_id=:profile_id)
                ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT :limit");
            foreach($scope as $field=>$value)$query->bindValue(':'.$field,$value);
            $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();
            $snapshot=$this->snapshot($scope,array_reverse($query->fetchAll(PDO::FETCH_COLUMN)),true);
            if($snapshot===null)throw new \InvalidArgumentException('relationship_build_no_history');
            $payload=$scope+$state+$policy+['request_id'=>$requestId,'history_limit'=>$limit,
                'provider_revision'=>(int)$providerRevision,'source_ids'=>$snapshot['source_ids'],
                'source_hashes'=>$snapshot['source_hashes'],'targets'=>$snapshot['targets'],
                'relationship_types'=>$snapshot['relationship_types'],
                'relationship_types_sha256'=>$snapshot['relationship_types_sha256']];
            $id=Uuid::v4();
            $this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority)
                VALUES(:id,'relationship.build',1,:key,CAST(:payload AS jsonb),3,20)")
                ->execute(['id'=>$id,'key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            return ['job_id'=>$id,'state'=>'queued'];
        });
    }

    /** Cheap cancellation checks do not reread the transcript on every provider heartbeat. */
    public function current(array $payload):bool
    {
        $job=$payload['_job'];
        $query=$this->db->prepare("SELECT 1 FROM durable_jobs j WHERE j.job_id=:job AND j.job_type='relationship.build'
            AND j.state='leased' AND j.lease_token=:lease AND j.attempt_count=:attempt AND j.lease_expires_at>clock_timestamp()
            AND NOT EXISTS(SELECT 1 FROM relationship_build_results r WHERE r.job_id=j.job_id) FOR SHARE OF j");
        $query->execute(['job'=>$job['job_id'],'lease'=>$job['lease_token'],'attempt'=>$job['attempt']]);
        if(!$query->fetchColumn())return false;
        try{$state=$this->scopeState($payload);}catch(\InvalidArgumentException){return false;}
        foreach($state as $field=>$value)if(($payload[$field]??null)!==$value)return false;
        $policy=$this->evaluations->policy($payload['installation_id'],$payload['profile_id']);
        if($policy===null||$policy['locked']||$policy['provider_configuration_id']==='')return false;
        foreach($policy as $field=>$value)if(($payload[$field]??null)!==$value)return false;
        return true;
    }

    /** Reconstruct exactly the frozen sources, never replacing them with newer conversation text. */
    public function input(array $payload):?array
    {
        if(!$this->current($payload))return null;
        try{$snapshot=$this->snapshot($payload,$payload['source_ids']);}catch(\InvalidArgumentException){return null;}
        if($snapshot===null||$snapshot['source_ids']!==$payload['source_ids']
            ||$snapshot['source_hashes']!=$payload['source_hashes']||$snapshot['targets']!=$payload['targets']
            ||$snapshot['relationship_types']!==($payload['relationship_types']??null)
            ||!hash_equals($snapshot['relationship_types_sha256'],(string)($payload['relationship_types_sha256']??'')))return null;
        return $snapshot;
    }

    /** Score writes and the retry receipt form one transaction; missing output targets remain unchanged. */
    public function save(array $payload,array $output,string $now):bool
    {
        $output=RelationshipBuildPolicy::output($output);
        foreach($output['relationships'] as $row)if(!isset($payload['targets'][$row['target_key']]))
            throw new \InvalidArgumentException('invalid_relationship_build_target');
        return $this->transaction(function()use($payload,$output,$now):bool{
            if(!$this->current($payload))return false;
            $targets=$payload['targets'];ksort($targets);
            foreach($targets as $target)$this->evaluations->lockIdentity($payload+['target_identity'=>$target['identity']]);
            $input=$this->input($payload);if($input===null)return false;
            $changed=0;$products=new ProductRepository($this->db);
            foreach($output['relationships'] as $row){
                $target=$targets[$row['target_key']];$record=$input['records'][$row['target_key']];
                $beforeType=(string)($record['relationship_type']??'neutral');
                $relationshipType=RelationshipType::model($row['relationship_type']??null,
                    $payload['relationship_types'],$row['affinity'],$row['reason'],$beforeType)??$beforeType;
                if((int)($record['disposition']??0)===$row['disposition']&&(int)($record['affinity']??0)===$row['affinity']
                    &&$relationshipType===$beforeType)continue;
                $write=array_intersect_key($payload,array_fill_keys(['installation_id','profile_id','playthrough_id'],true))
                    +['disposition'=>$row['disposition'],'affinity'=>$row['affinity'],'relationship_type'=>$relationshipType,'source_mode'=>'derived',
                        'source_event_id'=>$target['source_event_id'],'reason'=>'Manual history build: '.trim($row['reason'])];
                if($record===null)$write['actor_identity']=$target['identity'];
                else $write+=['relationship_id'=>$record['relationship_id'],'expected_revision'=>(int)$record['revision']];
                $products->setRelationship($write,$now);++$changed;
            }
            $query=$this->db->prepare("INSERT INTO relationship_build_results(job_id,source_count,target_count,changed_count)
                SELECT job_id,:sources,:targets,:changed FROM durable_jobs WHERE job_id=:job AND state='leased'
                    AND lease_token=:lease AND attempt_count=:attempt AND lease_expires_at>clock_timestamp()");
            $query->execute(['sources'=>count($payload['source_ids']),'targets'=>count($targets),'changed'=>$changed,
                'job'=>$payload['_job']['job_id'],'lease'=>$payload['_job']['lease_token'],'attempt'=>$payload['_job']['attempt']]);
            if($query->rowCount()!==1)throw new OperationCancelled('lease_lost');
            return true;
        });
    }

    /** Scoped metadata only; visiting the audit page never scans dialogue or enqueues work. */
    public function recentJobs(array $scope):array
    {
        if(count(array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)))!==3)return [];
        $query=$this->db->prepare("SELECT j.job_id,j.created_at,j.state,r.changed_count,
            jsonb_array_length(j.payload->'source_ids') AS source_count,
            CASE WHEN r.job_id IS NOT NULL THEN 'succeeded' WHEN j.state='succeeded' THEN 'stale' ELSE j.state END AS outcome
            FROM durable_jobs j LEFT JOIN relationship_build_results r ON r.job_id=j.job_id
            WHERE j.job_type='relationship.build' AND j.payload->>'installation_id'=:installation_id
                AND j.payload->>'profile_id'=:profile_id AND j.payload->>'playthrough_id'=:playthrough_id
            ORDER BY j.created_at DESC,j.job_id DESC LIMIT 10");
        $query->execute(array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)));
        return $query->fetchAll();
    }

    /** Lock installation/session boundaries so a concurrent load or halt cannot race the final write. */
    private function scopeState(array $scope):array
    {
        $query=$this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR SHARE');
        $query->execute(['id'=>$scope['installation_id']]);if(!$query->fetchColumn())throw new \InvalidArgumentException('invalid_relationship_scope');
        $query=$this->db->prepare('SELECT s.session_id,s.state,s.generation,
            (SELECT COALESCE(max(t.runtime_generation),0) FROM turns t WHERE t.session_id=s.session_id) AS runtime_generation
            FROM sessions s WHERE s.installation_id=:installation ORDER BY s.generation DESC LIMIT 1 FOR SHARE OF s');
        $query->execute(['installation'=>$scope['installation_id']]);$session=$query->fetch()?:[];
        $query=$this->db->prepare('SELECT t.current_revision FROM playthroughs t JOIN profiles p ON p.installation_id=t.installation_id
            WHERE t.playthrough_id=:playthrough_id AND p.profile_id=:profile_id AND t.installation_id=:installation_id
                AND t.deleted_at IS NULL AND p.deleted_at IS NULL FOR SHARE OF t,p');
        $query->execute(array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)));
        $revision=$query->fetchColumn();if($revision===false)throw new \InvalidArgumentException('invalid_relationship_scope');
        return ['playthrough_revision'=>(int)$revision,'lifecycle_fence'=>hash('sha256',json_encode($session,JSON_THROW_ON_ERROR))];
    }

    /** Bound recent source reads and reject ambiguous actors or oversized input instead of silently truncating. */
    private function snapshot(array $scope,array $ids,bool $skipIneligible=false):?array
    {
        if($ids===[]||count($ids)>100)return null;
        $products=new ProductRepository($this->db);$hashes=[];$targets=[];$records=[];$exchanges=[];$owner=null;$ownerKey=null;
        foreach($ids as $id){
            $source=$this->evaluations->source($id,true,true);
            if($source===null){if($skipIneligible)continue;return null;}
            foreach(['installation_id','profile_id','playthrough_id'] as $field)if($source[$field]!==$scope[$field])return null;
            $nextOwner=$products->actorKey($source['owner_identity']);
            if($ownerKey!==null&&$ownerKey!==$nextOwner)throw new \InvalidArgumentException('relationship_build_ambiguous_owner');
            $ownerKey=$nextOwner;$owner=$source['owner_identity'];$key=$products->actorKey($source['target_identity']);
            $hashes[$id]=hash('sha256',json_encode($source,JSON_THROW_ON_ERROR));
            if(!isset($targets[$key])){
                $state=$this->evaluations->records($source);
                if($state===null)throw new \InvalidArgumentException('relationship_build_ambiguous_records');
                $targets[$key]=['identity'=>$source['target_identity'],'fence'=>$state['fence'],'source_event_id'=>$id];
                $records[$key]=$state['record'];
            }
            $targets[$key]['source_event_id']=$id;
            if(count($targets)>20)throw new \InvalidArgumentException('relationship_build_too_large');
            $exchanges[]=['target_key'=>$key,'input'=>$source['input_text'],'played_reply'=>$source['reply']];
        }
        if($exchanges===[])return null;
        $people=[];
        foreach($targets as $key=>$target)$people[]=['target_key'=>$key,'identity'=>$target['identity'],
            'disposition'=>(int)($records[$key]['disposition']??0),'affinity'=>(int)($records[$key]['affinity']??0),
            'relationship_type'=>(string)($records[$key]['relationship_type']??'neutral')];
        $types=$this->evaluations->typeCatalog($scope);
        $model=['generation_mode'=>'relationship_build','owner'=>$owner,'interlocutors'=>$people,
            'available_relationship_types'=>$types['relationship_types'],'exchanges'=>$exchanges];
        if(strlen(json_encode($model,JSON_THROW_ON_ERROR))>65536)throw new \InvalidArgumentException('relationship_build_too_large');
        return ['source_ids'=>array_keys($hashes),'source_hashes'=>$hashes,'targets'=>$targets,'records'=>$records,'model'=>$model]+$types;
    }

    private function transaction(callable $work):mixed
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$result=$work();if($owns)$this->db->commit();return $result;}
        catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
}
