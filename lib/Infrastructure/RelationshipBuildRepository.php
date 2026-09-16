<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\OperationCancelled;
use LorkhanServer\Application\RelationshipBuildPolicy;
use LorkhanServer\Application\RelationshipType;
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
    public function enqueue(array $scope,string $requestId,int $limit=100,string $direction='',bool $preview=false):array
    {
        foreach(['installation_id','profile_id','playthrough_id'] as $field)
            if(!is_string($scope[$field]??null)||!Uuid::isValid($scope[$field]))
                throw new \InvalidArgumentException('invalid_relationship_scope');
        if(!Uuid::isValid($requestId)||$limit<1||$limit>100)throw new \InvalidArgumentException('invalid_relationship_build_request');
        $direction=RelationshipBuildPolicy::direction($direction);
        $scope=['installation_id'=>$scope['installation_id'],'profile_id'=>$scope['profile_id'],'playthrough_id'=>$scope['playthrough_id']];
        return $this->transaction(function()use($scope,$requestId,$limit,$direction,$preview):array{
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
                if($payload['history_limit']!==$limit||($payload['direction']??'')!==$direction||($payload['preview']??false)!==$preview)
                    throw new \InvalidArgumentException('relationship_build_request_conflict');
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
            $query=$this->db->prepare("SELECT delivery.source_event_id FROM active_turns t JOIN sessions s ON s.session_id=t.session_id
                JOIN LATERAL (SELECT d.source_event_id FROM dialogue_delivery_results d WHERE d.turn_id=t.turn_id AND d.status='played'
                    ORDER BY d.completed_at DESC,d.source_event_id DESC LIMIT 1) delivery ON true
                WHERE s.installation_id=:installation_id AND s.playthrough_id=:playthrough_id AND t.state='complete'
                    AND EXISTS(SELECT 1 FROM prompt_traces trace WHERE trace.turn_id=t.turn_id AND trace.selected_profile_id=:profile_id)
                ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT :limit");
            foreach($scope as $field=>$value)$query->bindValue(':'.$field,$value);
            $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();
            $snapshot=$this->snapshot($scope,array_reverse($query->fetchAll(PDO::FETCH_COLUMN)),true,$direction);
            if($snapshot===null)throw new \InvalidArgumentException('relationship_build_no_history');
            $payload=$scope+$state+$policy+['request_id'=>$requestId,'history_limit'=>$limit,'direction'=>$direction,
                'provider_revision'=>(int)$providerRevision,'source_ids'=>$snapshot['source_ids'],
                'source_hashes'=>$snapshot['source_hashes'],'targets'=>$snapshot['targets'],
                'relationship_types'=>$snapshot['relationship_types'],
                'relationship_types_sha256'=>$snapshot['relationship_types_sha256']];
            $payload['preview']=$preview;
            $payload['profile_revision']=(int)(new ProductRepository($this->db))->getRevisioned('profile',$scope['profile_id'])['current_revision'];
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
        try{$snapshot=$this->snapshot($payload,$payload['source_ids'],false,RelationshipBuildPolicy::direction($payload['direction']??''));}catch(\InvalidArgumentException){return null;}
        if($snapshot===null||$snapshot['source_ids']!==$payload['source_ids']
            ||$snapshot['source_hashes']!=$payload['source_hashes']||$snapshot['targets']!=$payload['targets']
            ||$snapshot['relationship_types']!==($payload['relationship_types']??null)
            ||!hash_equals($snapshot['relationship_types_sha256'],(string)($payload['relationship_types_sha256']??'')))return null;
        return $snapshot;
    }

    /** Persist either a review draft or atomic score writes with a retry receipt; omitted targets remain unchanged. */
    public function save(array $payload,array $output,string $now,?string $attemptId=null):bool
    {
        $output=RelationshipBuildPolicy::output($output);
        foreach($output['relationships'] as $row)if(!isset($payload['targets'][$row['target_key']]))
            throw new \InvalidArgumentException('invalid_relationship_build_target');
        return $this->transaction(function()use($payload,$output,$now,$attemptId):bool{
            if(!$this->current($payload))return false;
            $targets=$payload['targets'];ksort($targets);
            foreach($targets as $target)$this->evaluations->lockIdentity($payload+['target_identity'=>$target['identity']]);
            $input=$this->input($payload);if($input===null)return false;
            $changed=0;$applied=[];$draft=[];$preview=($payload['preview']??false)===true;$products=new ProductRepository($this->db);
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
                if($preview){$draft[]=$write+['target_key'=>$row['target_key']];continue;}
                $products->setRelationship($write,$now);++$changed;
                $applied[]=['target'=>(string)($target['identity']['display_name']??$target['identity']['record_id']??'Unknown interlocutor'),
                    'affinity_delta'=>$row['affinity']-(int)($record['affinity']??0),'disposition_delta'=>$row['disposition']-(int)($record['disposition']??0),
                    'old_type'=>$beforeType,'type'=>$relationshipType,'reason'=>trim($row['reason'])];
            }
            $query=$this->db->prepare("INSERT INTO relationship_build_results(job_id,source_count,target_count,changed_count,draft)
                SELECT job_id,:sources,:targets,:changed,CAST(:draft AS jsonb) FROM durable_jobs WHERE job_id=:job AND state='leased'
                    AND lease_token=:lease AND attempt_count=:attempt AND lease_expires_at>clock_timestamp()");
            $query->execute(['sources'=>count($payload['source_ids']),'targets'=>count($targets),'changed'=>$changed,
                'draft'=>$preview?json_encode($draft,JSON_THROW_ON_ERROR):null,
                'job'=>$payload['_job']['job_id'],'lease'=>$payload['_job']['lease_token'],'attempt'=>$payload['_job']['attempt']]);
            if($query->rowCount()!==1)throw new OperationCancelled('lease_lost');
            if(!$preview&&$attemptId!==null)(new ProviderAttemptRepository($this->db))->recordRelationshipApplied($attemptId,$payload['_job']['job_id'],$applied);
            return true;
        });
    }

    /** Scoped metadata only; visiting the audit page never scans dialogue or enqueues work. */
    public function recentJobs(array $scope):array
    {
        if(count(array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)))!==3)return [];
        $query=$this->db->prepare("SELECT j.job_id,j.created_at,j.state,j.payload->'preview' AS preview,r.changed_count,jsonb_array_length(r.draft) AS draft_count,
            jsonb_array_length(j.payload->'source_ids') AS source_count,
            CASE WHEN r.draft IS NOT NULL THEN 'draft_ready' WHEN r.job_id IS NOT NULL THEN 'succeeded' WHEN j.state='succeeded' THEN 'stale' ELSE j.state END AS outcome
            FROM durable_jobs j LEFT JOIN relationship_build_results r ON r.job_id=j.job_id
            WHERE j.job_type='relationship.build' AND j.payload->>'installation_id'=:installation_id
                AND j.payload->>'profile_id'=:profile_id AND j.payload->>'playthrough_id'=:playthrough_id
            ORDER BY j.created_at DESC,j.job_id DESC LIMIT 10");
        $query->execute(array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)));
        return $query->fetchAll();
    }

    /** Return only a completed review draft belonging to this exact editor scope. */
    public function draft(array $scope,string $jobId):?array
    {
        foreach(['installation_id','profile_id','playthrough_id'] as $field)
            if(!is_string($scope[$field]??null)||!Uuid::isValid($scope[$field]))return null;
        if(!Uuid::isValid($jobId))return null;
        $query=$this->db->prepare("SELECT r.draft,j.payload,j.payload->>'profile_revision' AS profile_revision
            FROM relationship_build_results r JOIN durable_jobs j ON j.job_id=r.job_id
            JOIN profiles p ON p.profile_id=CAST(j.payload->>'profile_id' AS uuid) AND p.deleted_at IS NULL
            JOIN playthroughs t ON t.playthrough_id=CAST(j.payload->>'playthrough_id' AS uuid) AND t.deleted_at IS NULL
            WHERE j.job_id=:job AND j.job_type='relationship.build' AND r.draft IS NOT NULL
                AND j.payload->>'installation_id'=:installation_id AND j.payload->>'profile_id'=:profile_id
                AND j.payload->>'playthrough_id'=:playthrough_id AND ".ProfileScopeSql::matches('p','t.playthrough_id')."");
        $query->execute(['job'=>$jobId]+array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)));
        $row=$query->fetch();
        if(!$row)return null;
        $payload=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        // A reviewed proposal must not resurrect suppressed history or overwrite a newer editor revision.
        try{
            if((int)(new ProductRepository($this->db))->getRevisioned('profile',$scope['profile_id'])['current_revision']!==(int)$row['profile_revision'])return null;
            foreach($this->scopeState($scope) as $field=>$value)if(($payload[$field]??null)!==$value)return null;
            $policy=$this->evaluations->policy($scope['installation_id'],$scope['profile_id']);
            if($policy===null||$policy['locked']||$policy['provider_configuration_id']==='')return null;
            foreach($policy as $field=>$value)if(($payload[$field]??null)!==$value)return null;
            $snapshot=$this->snapshot($scope,$payload['source_ids'],false,RelationshipBuildPolicy::direction($payload['direction']??''));
        }catch(\InvalidArgumentException|\RuntimeException){return null;}
        if($snapshot===null||$snapshot['source_ids']!==$payload['source_ids']
            ||$snapshot['source_hashes']!=$payload['source_hashes']||$snapshot['targets']!=$payload['targets']
            ||$snapshot['relationship_types']!==($payload['relationship_types']??null)
            ||!hash_equals($snapshot['relationship_types_sha256'],(string)($payload['relationship_types_sha256']??'')))return null;
        return ['job_id'=>$jobId,'profile_revision'=>(int)$row['profile_revision'],
            'relationships'=>json_decode($row['draft'],true,32,JSON_THROW_ON_ERROR)];
    }

    /** Poll one preview request without exposing job payloads or unrelated jobs. */
    public function previewStatus(array $scope,string $jobId):array
    {
        foreach(['installation_id','profile_id','playthrough_id'] as $field)
            if(!is_string($scope[$field]??null)||!Uuid::isValid($scope[$field]))throw new \RuntimeException('not_found');
        if(!Uuid::isValid($jobId))throw new \RuntimeException('not_found');
        $query=$this->db->prepare("SELECT j.state,r.draft IS NOT NULL AS has_draft
            FROM durable_jobs j LEFT JOIN relationship_build_results r ON r.job_id=j.job_id
            WHERE j.job_id=:job AND j.job_type='relationship.build' AND j.payload->'preview'='true'::jsonb
                AND j.payload->>'installation_id'=:installation_id AND j.payload->>'profile_id'=:profile_id
                AND j.payload->>'playthrough_id'=:playthrough_id");
        $query->execute(['job'=>$jobId]+array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)));
        $row=$query->fetch();if(!$row)throw new \RuntimeException('not_found');
        if(filter_var($row['has_draft'],FILTER_VALIDATE_BOOL)){
            $draft=$this->draft($scope,$jobId);
            if($draft===null)return ['job_id'=>$jobId,'state'=>'stale'];
            $ids=array_values(array_column($draft['relationships'],'relationship_id'));
            $editorRows=$ids===[]?[]:(new ManagementUiRepository($this->db))->rows('relationships',$scope['installation_id'],
                ['profile_id'=>$scope['profile_id'],'playthrough_id'=>$scope['playthrough_id'],'relationship_ids'=>$ids]);
            if(count($editorRows)!==count($ids))return ['job_id'=>$jobId,'state'=>'stale'];
            foreach($draft['relationships'] as $candidate)if(isset($candidate['relationship_id'])){
                $saved=array_values(array_filter($editorRows,static fn(array $row):bool=>$row['relationship_id']===$candidate['relationship_id']))[0];
                if((int)$saved['revision']!==$candidate['expected_revision'])return ['job_id'=>$jobId,'state'=>'stale'];
            }
            // These saved editor fields are for the authenticated user only, never the model or draft receipt.
            return ['state'=>'ready','editor_rows'=>$editorRows]+$draft;
        }
        return ['job_id'=>$jobId,'state'=>match($row['state']){'queued'=>'queued','leased'=>'building','dead'=>'failed',default=>'stale'}];
    }

    /** Lock installation/session boundaries so a concurrent load or halt cannot race the final write. */
    private function scopeState(array $scope):array
    {
        $query=$this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR SHARE');
        $query->execute(['id'=>$scope['installation_id']]);if(!$query->fetchColumn())throw new \InvalidArgumentException('invalid_relationship_scope');
        $query=$this->db->prepare('SELECT s.session_id,s.state,s.generation,
            (SELECT COALESCE(max(t.runtime_generation),0) FROM turns t WHERE t.session_id=s.session_id) AS runtime_generation
            FROM sessions s WHERE s.installation_id=:installation AND NOT s.archived ORDER BY s.generation DESC LIMIT 1 FOR SHARE OF s');
        $query->execute(['installation'=>$scope['installation_id']]);$session=$query->fetch()?:[];
        $query=$this->db->prepare('SELECT t.current_revision FROM playthroughs t JOIN profiles p ON p.installation_id=t.installation_id
            WHERE t.playthrough_id=:playthrough_id AND p.profile_id=:profile_id AND t.installation_id=:installation_id
                AND t.deleted_at IS NULL AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','t.playthrough_id').' FOR SHARE OF t,p');
        $query->execute(array_intersect_key($scope,array_fill_keys(['installation_id','profile_id','playthrough_id'],true)));
        $revision=$query->fetchColumn();if($revision===false)throw new \InvalidArgumentException('invalid_relationship_scope');
        return ['playthrough_revision'=>(int)$revision,'lifecycle_fence'=>hash('sha256',json_encode($session,JSON_THROW_ON_ERROR))];
    }

    /** Bound recent source reads and reject ambiguous actors or oversized input instead of silently truncating. */
    private function snapshot(array $scope,array $ids,bool $skipIneligible=false,string $direction=''):?array
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
            'relationship_type'=>(string)($records[$key]['relationship_type']??'neutral'),
            'details'=>json_decode($records[$key]['details']??'{}',true,16,JSON_THROW_ON_ERROR)];
        $types=$this->evaluations->typeCatalog($scope);
        $model=['generation_mode'=>'relationship_build','owner'=>$owner,'interlocutors'=>$people,
            'available_relationship_types'=>$types['relationship_types'],'exchanges'=>$exchanges];
        if($direction!=='')$model['user_direction']=$direction;
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
