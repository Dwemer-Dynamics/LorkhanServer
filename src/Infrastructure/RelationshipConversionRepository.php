<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\OperationCancelled;
use LorkhanServer\Application\RelationshipBuildPolicy;
use LorkhanServer\Application\RelationshipType;
use PDO;

/** Explicitly convert bounded profile relationship text without inventing OpenMW actors. */
final class RelationshipConversionRepository
{
    private readonly RelationshipEvaluationRepository $evaluations;
    public function __construct(private readonly PDO $db){$this->evaluations=new RelationshipEvaluationRepository($db);}

    public function enqueue(array $scope,string $requestId,string $mode):array
    {
        foreach(['installation_id','playthrough_id']as$field)
            if(!is_string($scope[$field]??null)||!Uuid::isValid($scope[$field]))throw new \InvalidArgumentException('invalid_relationship_conversion_scope');
        if(!Uuid::isValid($requestId)||!in_array($mode,['missing','rebuild'],true))
            throw new \InvalidArgumentException('invalid_relationship_conversion_request');
        $scope=['installation_id'=>$scope['installation_id'],'playthrough_id'=>$scope['playthrough_id']];
        return $this->transaction(function()use($scope,$requestId,$mode):array{
            $lock=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');
            $lock->execute(['key'=>'relationship.convert.request:'.$requestId]);
            $lock->execute(['key'=>'relationship.convert:'.$scope['installation_id'].':'.$scope['playthrough_id']]);
            $existing=$this->db->prepare("SELECT payload FROM durable_jobs WHERE job_type='relationship.convert'
                AND payload->>'request_id'=:request ORDER BY created_at LIMIT 1 FOR SHARE");
            $existing->execute(['request'=>$requestId]);$old=$existing->fetchColumn();
            if($old!==false){
                $payload=json_decode($old,true,64,JSON_THROW_ON_ERROR);
                if($payload['installation_id']!==$scope['installation_id']||$payload['playthrough_id']!==$scope['playthrough_id']
                    ||$payload['mode']!==$mode)throw new \InvalidArgumentException('relationship_conversion_request_conflict');
                return $payload['batch_summary'];
            }
            $state=$this->scopeState($scope);
            $owners=$this->owners($scope['installation_id']);$candidates=$this->candidates($scope['installation_id']);
            $summary=['queued'=>0,'skipped'=>0,'no_text'=>0,'existing'=>0,'locked'=>0,'no_connector'=>0,'no_targets'=>0,'pending'=>0];
            $prepared=[];
            foreach($owners as$owner){
                $text=$owner['relationship_text'];
                if(trim($text)===''){++$summary['no_text'];continue;}
                if(strlen($text)>32768)throw new \InvalidArgumentException('relationship_conversion_too_large');
                if($mode==='missing'&&$this->hasRelationships($scope,$owner['profile_id'])){++$summary['existing'];continue;}
                if($this->pending($scope,$owner['profile_id'])){++$summary['pending'];continue;}
                $policy=$this->evaluations->policy($scope['installation_id'],$owner['profile_id']);
                if($policy===null){++$summary['skipped'];continue;}
                if($policy['locked']){++$summary['locked'];continue;}
                if($policy['provider_configuration_id']===''){++$summary['no_connector'];continue;}
                $provider=$this->db->prepare("SELECT current_revision FROM configuration_sets WHERE configuration_id=:provider
                    AND installation_id=:installation AND kind='provider' AND deleted_at IS NULL FOR SHARE");
                $provider->execute(['provider'=>$policy['provider_configuration_id'],'installation'=>$scope['installation_id']]);
                $providerRevision=$provider->fetchColumn();if($providerRevision===false){++$summary['no_connector'];continue;}
                $targetSet=$this->targets($scope,$owner,$candidates);$targets=$targetSet['targets'];
                if($targets===[]){++$summary['no_targets'];continue;}
                if(count($targets)>20)throw new \InvalidArgumentException('relationship_conversion_too_many_targets');
                $types=$this->evaluations->typeCatalog($scope+['profile_id'=>$owner['profile_id']]);
                if(strlen(json_encode($this->model($owner,$targetSet['people'],$types['relationship_types']),JSON_THROW_ON_ERROR))>65536)
                    throw new \InvalidArgumentException('relationship_conversion_too_large');
                $prepared[]=$scope+$state+$policy+[
                    'profile_id'=>$owner['profile_id'],'profile_revision'=>$owner['profile_revision'],
                    'owner_identity'=>$owner['identity'],'source_text_sha256'=>hash('sha256',$text),'request_id'=>$requestId,
                    'mode'=>$mode,'provider_revision'=>(int)$providerRevision,'targets'=>$targets,
                ]+$types;
                if(count($prepared)>100)throw new \InvalidArgumentException('relationship_conversion_too_many_owners');
            }
            $summary['queued']=count($prepared);$summary['skipped']=count($owners)-$summary['queued'];
            foreach($prepared as$payload){
                $payload['batch_summary']=$summary;$id=Uuid::v4();
                $this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority)
                    VALUES(:id,'relationship.convert',1,:key,CAST(:payload AS jsonb),3,20)")
                    ->execute(['id'=>$id,'key'=>'relationship.convert:'.$requestId.':'.$payload['profile_id'],
                        'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            }
            return $summary;
        });
    }

    public function current(array $payload):bool
    {
        $job=$payload['_job'];
        $query=$this->db->prepare("SELECT 1 FROM durable_jobs j WHERE j.job_id=:job AND j.job_type='relationship.convert'
            AND j.state='leased' AND j.lease_token=:lease AND j.attempt_count=:attempt AND j.lease_expires_at>clock_timestamp()
            AND NOT EXISTS(SELECT 1 FROM relationship_conversion_results result WHERE result.job_id=j.job_id) FOR SHARE OF j");
        $query->execute(['job'=>$job['job_id'],'lease'=>$job['lease_token'],'attempt'=>$job['attempt']]);
        if(!$query->fetchColumn())return false;
        try{$state=$this->scopeState($payload);$owner=$this->owner($payload['installation_id'],$payload['profile_id']);}
        catch(\InvalidArgumentException){return false;}
        foreach($state as$field=>$value)if(($payload[$field]??null)!==$value)return false;
        if($owner===null||$owner['profile_revision']!==$payload['profile_revision']
            ||!hash_equals($payload['source_text_sha256'],hash('sha256',$owner['relationship_text'])))return false;
        $policy=$this->evaluations->policy($payload['installation_id'],$payload['profile_id']);
        if($policy===null||$policy['locked']||$policy['provider_configuration_id']==='')return false;
        foreach($policy as$field=>$value)if(($payload[$field]??null)!==$value)return false;
        $types=$this->evaluations->typeCatalog($payload);
        if(($payload['relationship_types']??null)!==$types['relationship_types']
            ||!hash_equals((string)($payload['relationship_types_sha256']??''),$types['relationship_types_sha256']))return false;
        return true;
    }

    public function input(array $payload):?array
    {
        if(!$this->current($payload))return null;
        $owner=$this->owner($payload['installation_id'],$payload['profile_id']);if($owner===null)return null;
        $targets=$this->targetsByPayload($payload);if($targets===null||$targets!=$payload['targets'])return null;
        $people=[];$records=[];
        foreach($targets as$key=>$target){
            $source=$payload+['target_identity'=>$target['identity']];$state=$this->evaluations->records($source);
            if($state===null||!hash_equals($target['relationship_fence'],$state['fence']))return null;
            $record=$state['record'];$records[$key]=$record;
            $people[]=['target_key'=>$key,'identity'=>$target['identity'],'disposition'=>(int)($record['disposition']??0),
                'affinity'=>(int)($record['affinity']??0),'relationship_type'=>(string)($record['relationship_type']??'neutral')];
        }
        $model=$this->model($owner,$people,$payload['relationship_types']);
        if(strlen(json_encode($model,JSON_THROW_ON_ERROR))>65536)return null;
        return ['model'=>$model,'records'=>$records,'targets'=>$targets,'source_bytes'=>strlen($owner['relationship_text'])];
    }

    public function save(array $payload,array $output,string $now):bool
    {
        $output=RelationshipBuildPolicy::output($output);
        foreach($output['relationships']as$row)if(!isset($payload['targets'][$row['target_key']]))
            throw new \InvalidArgumentException('invalid_relationship_build_target');
        return $this->transaction(function()use($payload,$output,$now):bool{
            if(!$this->current($payload))return false;
            $targets=$payload['targets'];ksort($targets);
            foreach($targets as$target)$this->evaluations->lockIdentity($payload+['target_identity'=>$target['identity']]);
            $input=$this->input($payload);if($input===null)return false;
            $changed=0;$products=new ProductRepository($this->db);
            foreach($output['relationships']as$row){
                $target=$targets[$row['target_key']];$record=$input['records'][$row['target_key']];
                $beforeType=(string)($record['relationship_type']??'neutral');
                $relationshipType=RelationshipType::model($row['relationship_type']??null,
                    $payload['relationship_types'],$row['affinity'],$row['reason'],$beforeType)??$beforeType;
                if((int)($record['disposition']??0)===$row['disposition']&&(int)($record['affinity']??0)===$row['affinity']
                    &&$relationshipType===$beforeType)continue;
                $write=array_intersect_key($payload,array_fill_keys(['installation_id','profile_id','playthrough_id'],true))
                    +['disposition'=>$row['disposition'],'affinity'=>$row['affinity'],'relationship_type'=>$relationshipType,'source_mode'=>'derived',
                        'reason'=>'Relationship text conversion: '.trim($row['reason'])];
                if($record===null)$write['actor_identity']=$target['identity'];
                else $write+=['relationship_id'=>$record['relationship_id'],'expected_revision'=>(int)$record['revision']];
                $products->setRelationship($write,$now);++$changed;
            }
            $query=$this->db->prepare("INSERT INTO relationship_conversion_results
                (job_id,batch_request_id,owner_profile_id,source_bytes,target_count,changed_count)
                SELECT job_id,:request,:profile,:bytes,:targets,:changed FROM durable_jobs
                WHERE job_id=:job AND state='leased' AND lease_token=:lease AND attempt_count=:attempt
                    AND lease_expires_at>clock_timestamp()");
            $query->execute(['request'=>$payload['request_id'],'profile'=>$payload['profile_id'],'bytes'=>$input['source_bytes'],
                'targets'=>count($targets),'changed'=>$changed,'job'=>$payload['_job']['job_id'],
                'lease'=>$payload['_job']['lease_token'],'attempt'=>$payload['_job']['attempt']]);
            if($query->rowCount()!==1)throw new OperationCancelled('lease_lost');
            return true;
        });
    }

    private function owners(string $installation):array
    {
        $query=$this->db->prepare("SELECT p.profile_id,p.current_revision,p.actor_identity,r.content FROM profiles p
            JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
            WHERE p.installation_id=:installation AND p.deleted_at IS NULL
                AND p.actor_identity->>'kind' IN ('npc','creature') ORDER BY p.profile_id LIMIT 102");
        $query->execute(['installation'=>$installation]);$rows=$query->fetchAll();
        if(count($rows)>101)throw new \InvalidArgumentException('relationship_conversion_too_many_owners');
        return array_map(fn(array$row):array=>$this->ownerRow($row),$rows);
    }

    private function owner(string $installation,string $profile):?array
    {
        $query=$this->db->prepare('SELECT p.profile_id,p.current_revision,p.actor_identity,r.content FROM profiles p
            JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
            WHERE p.installation_id=:installation AND p.profile_id=:profile AND p.deleted_at IS NULL FOR SHARE OF p,r');
        $query->execute(['installation'=>$installation,'profile'=>$profile]);$row=$query->fetch();
        return$row?$this->ownerRow($row):null;
    }

    private function ownerRow(array $row):array
    {
        $content=json_decode($row['content'],true,64,JSON_THROW_ON_ERROR);
        $text=is_string($content['relationships']??null)?$content['relationships']:'';
        return ['profile_id'=>(string)$row['profile_id'],'profile_revision'=>(int)$row['current_revision'],
            'identity'=>json_decode($row['actor_identity'],true,32,JSON_THROW_ON_ERROR),'relationship_text'=>$text];
    }

    private function candidates(string $installation):array
    {
        $query=$this->db->prepare("SELECT profile_id,current_revision,name,actor_identity FROM profiles
            WHERE installation_id=:installation AND deleted_at IS NULL
                AND actor_identity->>'kind' IN ('npc','creature','player') ORDER BY profile_id LIMIT 502");
        $query->execute(['installation'=>$installation]);$rows=$query->fetchAll();
        if(count($rows)>501)throw new \InvalidArgumentException('relationship_conversion_too_many_candidates');
        return array_map(static function(array$row):array{$row['identity']=json_decode($row['actor_identity'],true,32,JSON_THROW_ON_ERROR);return$row;},$rows);
    }

    private function targets(array $scope,array $owner,array $candidates):array
    {
        $targets=[];$people=[];$ambiguous=[];$products=new ProductRepository($this->db);
        foreach($candidates as$candidate){
            if($candidate['profile_id']===$owner['profile_id'])continue;$identity=$candidate['identity'];
            if(!isset($identity['kind'])||($identity['kind']!=='player'
                &&(!isset($identity['record_id'],$identity['content_file'])||!array_key_exists('refnum',$identity))))continue;
            if(!$this->mentioned($owner['relationship_text'],[$candidate['name'],$identity['display_name']??'',$identity['record_id']??'']))continue;
            $key=$products->actorKey($identity);$source=$scope+['profile_id'=>$owner['profile_id'],'target_identity'=>$identity];
            if(isset($ambiguous[$key]))continue;
            if(isset($targets[$key])){unset($targets[$key],$people[$key]);$ambiguous[$key]=true;continue;}
            $this->evaluations->lockIdentity($source);$state=$this->evaluations->records($source);
            if($state===null)throw new \InvalidArgumentException('relationship_conversion_ambiguous_records');
            $targets[$key]=['profile_id'=>$candidate['profile_id'],'profile_revision'=>(int)$candidate['current_revision'],
                'identity'=>$identity,'relationship_fence'=>$state['fence']];
            $record=$state['record'];$people[$key]=['target_key'=>$key,'identity'=>$identity,
                'disposition'=>(int)($record['disposition']??0),'affinity'=>(int)($record['affinity']??0),
                'relationship_type'=>(string)($record['relationship_type']??'neutral')];
        }
        ksort($targets);ksort($people);return['targets'=>$targets,'people'=>array_values($people)];
    }

    private function model(array $owner,array $people,array $types):array
    {
        return ['generation_mode'=>'relationship_text_conversion','owner'=>$owner['identity'],
            'relationship_text'=>$owner['relationship_text'],'interlocutors'=>$people,
            'available_relationship_types'=>$types];
    }

    private function targetsByPayload(array $payload):?array
    {
        $targets=[];
        foreach($payload['targets']as$key=>$target){
            $query=$this->db->prepare('SELECT current_revision,actor_identity FROM profiles
                WHERE installation_id=:installation AND profile_id=:profile AND deleted_at IS NULL FOR SHARE');
            $query->execute(['installation'=>$payload['installation_id'],'profile'=>$target['profile_id']]);$row=$query->fetch();
            if(!$row||(int)$row['current_revision']!==$target['profile_revision'])return null;
            $identity=json_decode($row['actor_identity'],true,32,JSON_THROW_ON_ERROR);
            if((new ProductRepository($this->db))->actorKey($identity)!==$key||$identity!=$target['identity'])return null;
            $targets[$key]=$target;
        }
        ksort($targets);return$targets;
    }

    private function mentioned(string $text,array $aliases):bool
    {
        foreach($aliases as$alias){
            if(!is_string($alias)||mb_strlen(trim($alias),'UTF-8')<3)continue;
            if(preg_match('/(?<![\p{L}\p{N}_])'.preg_quote(trim($alias),'/').'(?![\p{L}\p{N}_])/iu',$text)===1)return true;
        }
        return false;
    }

    private function hasRelationships(array $scope,string $profile):bool
    {
        $query=$this->db->prepare('SELECT 1 FROM relationship_records WHERE installation_id=:installation_id
            AND profile_id=:profile AND playthrough_id=:playthrough_id AND deleted_at IS NULL LIMIT 1');
        $query->execute($scope+['profile'=>$profile]);return(bool)$query->fetchColumn();
    }

    private function pending(array $scope,string $profile):bool
    {
        $query=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_type IN ('relationship.convert','relationship.build')
            AND state IN ('queued','leased') AND payload->>'installation_id'=:installation_id
            AND payload->>'playthrough_id'=:playthrough_id AND payload->>'profile_id'=:profile LIMIT 1");
        $query->execute($scope+['profile'=>$profile]);return(bool)$query->fetchColumn();
    }

    private function scopeState(array $scope):array
    {
        $query=$this->db->prepare('SELECT current_revision FROM playthroughs WHERE installation_id=:installation_id
            AND playthrough_id=:playthrough_id AND deleted_at IS NULL FOR SHARE');
        $query->execute(['installation_id'=>$scope['installation_id'],'playthrough_id'=>$scope['playthrough_id']]);$revision=$query->fetchColumn();
        if($revision===false)throw new \InvalidArgumentException('invalid_relationship_conversion_scope');
        $query=$this->db->prepare('SELECT session_id,state,generation FROM sessions WHERE installation_id=:installation_id
            ORDER BY generation DESC,session_id DESC LIMIT 1 FOR SHARE');
        $query->execute(['installation_id'=>$scope['installation_id']]);$session=$query->fetch()?:[];
        return ['playthrough_revision'=>(int)$revision,'lifecycle_fence'=>hash('sha256',json_encode($session,JSON_THROW_ON_ERROR))];
    }

    private function transaction(callable $work):mixed
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$result=$work();if($owns)$this->db->commit();return$result;}
        catch(\Throwable$error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$error;}
    }
}
