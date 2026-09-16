<?php
declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\DirectorPolicy;
use LorkhanServer\Application\TransferActionPolicy;
use PDO;
use RuntimeException;

/** Durable scene instructions remain tied to their source turn and require fresh client child observations. */
final class DirectorPlanningRepository
{
    public function __construct(private readonly PDO $db) {}

    private function transaction(callable $fn): mixed
    {
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        try {$value=$fn();if($own)$this->db->commit();return $value;}
        catch(\Throwable $error){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    /** Call after origin turn persistence, in the same acceptance transaction. */
    public function enqueuePlan(array $message,array $sceneContext=[]): array
    {
        if(($message['payload']['execution_mode']??'standard')!=='director'
            ||isset($message['payload']['director_instruction_id']))throw new RuntimeException('invalid_director_request');
        return $this->transaction(function()use($message,$sceneContext){
            $route=(new DirectorRoutingRepository($this->db))->route($message['installation_id']);
            if(!$route)throw new RuntimeException('director_connector_disabled');
            $q=$this->db->prepare("SELECT t.speaker,t.context,t.input_text,t.request_id FROM active_turns t JOIN sessions s USING(session_id) WHERE t.turn_id=:turn AND t.session_id=:session AND t.generation=:generation AND s.installation_id=:installation AND s.playthrough_id=:playthrough AND s.state='active' AND s.generation=t.generation FOR SHARE OF s");
            $scope=['turn'=>$message['turn_id'],'session'=>$message['session_id'],'generation'=>$message['generation'],'installation'=>$message['installation_id'],'playthrough'=>$message['playthrough_id']];
            $q->execute($scope);$row=$q->fetch();if(!$row)throw new RuntimeException('director_scope_unavailable');
            $speaker=json_decode($row['speaker'],true,32,JSON_THROW_ON_ERROR);
            if(($speaker['kind']??null)!=='player')throw new RuntimeException('director_player_required');
            $actors=DirectorPolicy::actors(['speaker'=>$speaker,'context'=>json_decode($row['context'],true,64,JSON_THROW_ON_ERROR)]);
            DirectorPolicy::schema($actors);
            $actions=$sceneContext['_director_actions']??[];unset($sceneContext['_director_actions']);
            $input=['actors'=>$actors,'actions'=>$actions,'instruction'=>$row['input_text'],'scene'=>$sceneContext];
            $encoded=json_encode($input,JSON_THROW_ON_ERROR);if(strlen($encoded)>100000)throw new RuntimeException('director_input_too_large');
            $job=Uuid::v4();$payload=['installation_id'=>$message['installation_id'],'provider_configuration_id'=>$route['configuration_id'],'provider_revision'=>$route['current_revision']];
            $q=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'director.plan',1,:key,CAST(:payload AS jsonb),1,90) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id");
            $q->execute(['job'=>$job,'key'=>'director:'.$message['turn_id'],'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            if(!$q->fetchColumn()){$q=$this->db->prepare('SELECT plan_id FROM director_plans WHERE origin_turn_id=:turn');$q->execute(['turn'=>$message['turn_id']]);return ['plan_id'=>$q->fetchColumn()];}
            $q=$this->db->prepare('INSERT INTO director_plans(plan_id,installation_id,session_id,playthrough_id,generation,origin_turn_id,request_id,input) VALUES(:job,:installation,:session,:playthrough,:generation,:turn,:request,CAST(:input AS jsonb))');
            $q->execute($scope+['job'=>$job,'request'=>$row['request_id'],'input'=>$encoded]);return ['plan_id'=>$job];
        });
    }

    public function input(string $job): array
    {
        $q=$this->db->prepare("SELECT p.input,p.state FROM director_plans p JOIN sessions s USING(session_id) JOIN active_turns t ON t.turn_id=p.origin_turn_id WHERE p.plan_id=:job AND p.state IN ('queued','delivered') AND p.expires_at>clock_timestamp() AND s.state='active' AND s.generation=p.generation AND t.state NOT IN ('cancelled','failed')");
        $q->execute(['job'=>$job]);$row=$q->fetch();if(!$row)throw new RuntimeException('director_cancelled');
        return $row['state']==='delivered'?['_delivered'=>true]:json_decode($row['input'],true,64,JSON_THROW_ON_ERROR);
    }

    /** Persist instructions and their response event atomically under the worker lease and session fence. */
    public function deliver(string $job,int $attempt,string $lease,array $output): void
    {
        $this->transaction(function()use($job,$attempt,$lease,$output){
            $q=$this->db->prepare("SELECT p.*,s.event_sequence FROM director_plans p JOIN sessions s USING(session_id) JOIN durable_jobs j ON j.job_id=p.plan_id JOIN active_turns t ON t.turn_id=p.origin_turn_id WHERE p.plan_id=:job AND s.state='active' AND s.generation=p.generation AND p.expires_at>clock_timestamp() AND t.state NOT IN ('cancelled','failed') AND j.state='leased' AND j.attempt_count=:attempt AND j.lease_token=:lease AND j.lease_expires_at>clock_timestamp() FOR UPDATE OF s,p");
            $q->execute(['job'=>$job,'attempt'=>$attempt,'lease'=>$lease]);$plan=$q->fetch();if(!$plan||$plan['state']==='cancelled')throw new RuntimeException('director_cancelled');
            if($plan['state']==='delivered')return;
            $input=json_decode($plan['input'],true,64,JSON_THROW_ON_ERROR);$output=DirectorPolicy::output($output,$input['actors'],$input['actions']??[]);$instructions=[];
            foreach($output['instructions'] as $index=>$row){
                $item=['instruction_id'=>Uuid::v4(),'actor'=>$input['actors'][$row['actor_id']],'recipient'=>$input['actors'][$row['recipient_id']], 'instruction'=>$row['instruction'],'scene_note'=>$row['scene_note']];
                $authored=['utterances'=>[['speaker'=>$item['actor'],'addressee'=>$item['recipient'],'text'=>$row['instruction']]],'action'=>null];
                if(is_array($row['action']??null)){
                    $chosen=$row['action'];$definition=null;
                    foreach($input['actions'][$row['actor_id']]??[] as $candidate)if($candidate['name']===$chosen['name'])$definition=$candidate;
                    if($definition===null)throw new RuntimeException('provider_action_not_allowed');
                    $authored['action']=['name'=>$chosen['name'],'tier'=>$definition['tier'],'actor'=>$item['actor'],
                        'target'=>$item['recipient'],'parameters'=>$chosen['parameters']];
                }
                $q=$this->db->prepare('INSERT INTO director_instructions(instruction_id,plan_id,ordinal,actor,recipient,instruction,scene_note,authored_response) VALUES(:id,:plan,:ordinal,CAST(:actor AS jsonb),CAST(:recipient AS jsonb),:instruction,:note,CAST(:authored AS jsonb))');
                $q->execute(['id'=>$item['instruction_id'],'plan'=>$job,'ordinal'=>$index+1,'actor'=>json_encode($item['actor'],JSON_THROW_ON_ERROR),'recipient'=>json_encode($item['recipient'],JSON_THROW_ON_ERROR),'instruction'=>$item['instruction'],'note'=>$item['scene_note'],'authored'=>json_encode($authored,JSON_THROW_ON_ERROR)]);$instructions[]=$item;
            }
            $event=['plan_id'=>$job,'origin_turn_id'=>$plan['origin_turn_id'],'expires_at'=>(new \DateTimeImmutable($plan['expires_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),'instructions'=>$instructions];
            $q=$this->db->prepare('UPDATE sessions SET event_sequence=event_sequence+1 WHERE session_id=:session RETURNING event_sequence');$q->execute(['session'=>$plan['session_id']]);$sequence=$q->fetchColumn();
            $q=$this->db->prepare("INSERT INTO response_events(session_id,sequence,message_id,request_id,generation,turn_id,event_type,payload,created_at) VALUES(:session,:sequence,:message,:request,:generation,:turn,'director.instructions',CAST(:payload AS jsonb),clock_timestamp())");
            $q->execute(['session'=>$plan['session_id'],'sequence'=>$sequence,'message'=>Uuid::v4(),'request'=>$plan['request_id'],'generation'=>$plan['generation'],'turn'=>$plan['origin_turn_id'],'payload'=>json_encode($event,JSON_THROW_ON_ERROR)]);
            $q=$this->db->prepare("UPDATE director_plans SET state='delivered' WHERE plan_id=:id");$q->execute(['id'=>$job]);
            // Planning has completed, without inventing NPC speech; child turns own subsequent dialogue.
            $q=$this->db->prepare("UPDATE turns SET state='complete',completed_at=clock_timestamp() WHERE turn_id=:turn AND state IN ('accepted','processing')");
            $q->execute(['turn'=>$plan['origin_turn_id']]);
            $q=$this->db->prepare('UPDATE sessions SET event_sequence=event_sequence+1 WHERE session_id=:session RETURNING event_sequence');
            $q->execute(['session'=>$plan['session_id']]);$sequence=$q->fetchColumn();
            $q=$this->db->prepare("INSERT INTO response_events(session_id,sequence,message_id,request_id,generation,turn_id,event_type,payload,created_at) VALUES(:session,:sequence,:message,:request,:generation,:turn,'turn.complete','{\"status\":\"complete\"}'::jsonb,clock_timestamp())");
            $q->execute(['session'=>$plan['session_id'],'sequence'=>$sequence,'message'=>Uuid::v4(),'request'=>$plan['request_id'],'generation'=>$plan['generation'],'turn'=>$plan['origin_turn_id']]);
        });
    }

    /** Claim in the child acceptance transaction; a retry may claim only the same child turn ID. */
    public function claimChild(array $message): array
    {
        if(!$this->db->inTransaction())throw new RuntimeException('director_claim_requires_transaction');
        $row=$this->child($message,true);
        $q=$this->db->prepare('UPDATE director_instructions SET child_turn_id=:turn WHERE instruction_id=:id');$q->execute(['turn'=>$message['turn_id'],'id'=>$row['instruction_id']]);
        return array_intersect_key($row,array_flip(['instruction','scene_note','plan_id','authored_response']));
    }

    /** Read trusted planner text for prompt assembly; acceptance must subsequently claim under lock. */
    public function prepareChild(array $message): array
    {
        return array_intersect_key($this->child($message,false),array_flip(['instruction','scene_note','plan_id','authored_response']));
    }

    private function child(array $message,bool $lock): array
    {
        if(($message['payload']['execution_mode']??'standard')!=='standard')throw new RuntimeException('invalid_director_child_mode');
        $q=$this->db->prepare("SELECT i.* FROM director_instructions i JOIN director_plans p USING(plan_id) JOIN sessions s USING(session_id) JOIN active_turns t ON t.turn_id=p.origin_turn_id WHERE i.instruction_id=:id AND p.installation_id=:installation AND p.session_id=:session AND p.playthrough_id=:playthrough AND p.generation=:generation AND p.state='delivered' AND p.expires_at>clock_timestamp() AND s.state='active' AND s.generation=p.generation AND t.state NOT IN ('cancelled','failed')".($lock?' FOR UPDATE OF s,i':''));
        $q->execute(['id'=>$message['payload']['director_instruction_id'],'installation'=>$message['installation_id'],'session'=>$message['session_id'],'playthrough'=>$message['playthrough_id'],'generation'=>$message['generation']]);$row=$q->fetch();
        if(!$row||($row['child_turn_id']!==null&&$row['child_turn_id']!==$message['turn_id']))throw new RuntimeException('director_instruction_unavailable');
        $prior=$this->db->prepare("SELECT 1 FROM director_instructions i LEFT JOIN turns t ON t.turn_id=i.child_turn_id WHERE i.plan_id=:plan AND i.ordinal<:ordinal AND (i.child_turn_id IS NULL OR t.state IS NULL OR t.state NOT IN ('complete','failed','cancelled')) LIMIT 1");
        $prior->execute(['plan'=>$row['plan_id'],'ordinal'=>$row['ordinal']]);
        if($prior->fetchColumn())throw new RuntimeException('director_previous_child_pending');
        $actor=json_decode($row['actor'],true,32,JSON_THROW_ON_ERROR);$recipient=json_decode($row['recipient'],true,32,JSON_THROW_ON_ERROR);
        if(!TransferActionPolicy::sameIdentity($actor,$message['payload']['target']??null)||!TransferActionPolicy::sameIdentity($recipient,$message['payload']['speaker']??null))throw new RuntimeException('director_actor_mismatch');
        if(is_string($row['authored_response']??null))$row['authored_response']=json_decode($row['authored_response'],true,32,JSON_THROW_ON_ERROR);
        return $row;
    }

    public function cancel(string $session,int $generation): void
    {
        $q=$this->db->prepare("UPDATE director_plans SET state='cancelled' WHERE session_id=:session AND generation=:generation AND state IN ('queued','delivered')");
        $q->execute(['session'=>$session,'generation'=>$generation]);
    }

    /** A failed planner closes its accepted origin turn while the same worker still owns the job. */
    public function fail(string $job,int $attempt,string $lease): void
    {
        $this->transaction(function()use($job,$attempt,$lease){
            $q=$this->db->prepare("SELECT p.installation_id,p.playthrough_id,p.session_id,p.generation,p.origin_turn_id AS turn_id,p.request_id,s.profile_id,t.runtime_generation FROM director_plans p JOIN sessions s USING(session_id) JOIN turns t ON t.turn_id=p.origin_turn_id JOIN durable_jobs j ON j.job_id=p.plan_id WHERE p.plan_id=:job AND p.state='queued' AND t.state IN ('accepted','processing') AND s.state='active' AND s.generation=p.generation AND j.state='leased' AND j.attempt_count=:attempt AND j.lease_token=:lease AND j.lease_expires_at>clock_timestamp() FOR UPDATE OF s,p,t");
            $q->execute(['job'=>$job,'attempt'=>$attempt,'lease'=>$lease]);$message=$q->fetch();if(!$message)return;
            $message['generation']=(int)$message['generation'];$message['runtime_generation']=(int)$message['runtime_generation'];
            (new Repository($this->db))->failTurn($message,'director_plan_failed');
            $q=$this->db->prepare("UPDATE director_plans SET state='cancelled' WHERE plan_id=:job");$q->execute(['job'=>$job]);
        });
    }

    /** Temporary scene guidance is visible only while its source plan remains on the active timeline. */
    public function sceneNotes(string $session,int $generation): array
    {
        $q=$this->db->prepare("SELECT i.scene_note,p.origin_turn_id FROM director_instructions i JOIN director_plans p USING(plan_id) JOIN active_turns t ON t.turn_id=p.origin_turn_id JOIN sessions s ON s.session_id=p.session_id WHERE p.session_id=:session AND p.generation=:generation AND p.state='delivered' AND p.expires_at>clock_timestamp() AND s.state='active' AND s.generation=p.generation AND t.state NOT IN ('cancelled','failed') AND i.scene_note<>'' ORDER BY p.expires_at DESC,i.ordinal LIMIT 9");
        $q->execute(['session'=>$session,'generation'=>$generation]);return $q->fetchAll();
    }
}
