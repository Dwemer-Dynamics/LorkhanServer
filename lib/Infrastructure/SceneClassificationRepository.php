<?php

declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\SceneClassificationPolicy;
use PDO;
use RuntimeException;

/** Frozen dialogue inputs and scoped, lease-fenced scene results; no profile edits or game actions. */
final class SceneClassificationRepository
{
    public function __construct(private readonly PDO $db){}

    /** Follow the reference dedicated connector, known label, then enabled Background Tasks fallback. */
    public function route(string $installation):?array
    {
        $products=new ProductRepository($this->db);$global=$products->globalSettingsForInstallation($installation)['content']??[];
        if(($global['task_availability']['scene_classifier']??true)!==true)return null;
        $ids=[(string)($global['system_routing']['scene_classifier_configuration_id']??'')];
        $q=$this->db->prepare("SELECT configuration_id FROM configuration_sets WHERE installation_id=:installation AND kind='provider' AND deleted_at IS NULL AND lower(name) IN ('gemma 3 4b','gemma 3n e4b','scene classifier (gemma 3n e4b)','scene classifier (gemini 2.5 flash lite)') ORDER BY CASE lower(name) WHEN 'gemma 3 4b' THEN 0 WHEN 'gemma 3n e4b' THEN 1 WHEN 'scene classifier (gemma 3n e4b)' THEN 2 ELSE 3 END,configuration_id");
        $q->execute(['installation'=>$installation]);foreach($q->fetchAll(PDO::FETCH_COLUMN)as$id)$ids[]=$id;
        if(($global['task_availability']['background_memory']??true)===true)$ids[]=(string)($global['system_routing']['background_memory_configuration_id']??'');
        $q=$this->db->prepare("SELECT configuration_id,current_revision FROM configuration_sets WHERE installation_id=:installation AND configuration_id=:id AND kind='provider' AND deleted_at IS NULL");
        foreach(array_unique($ids)as$id){if(!Uuid::isValid($id))continue;$q->execute(['installation'=>$installation,'id'=>$id]);if($row=$q->fetch())return $row;}
        return null;
    }

    public function enqueue(array $turn,string $profile,array $history):array
    {
        $installation=$turn['installation_id'];$route=$this->route($installation);
        if($route===null)return ['queued'=>false,'reason'=>'scene_classifier_unavailable'];
        $lines=[];foreach($history as$exchange){
            $input=trim((string)($exchange['player_input']??''));if($input!=='')$lines[]=['speaker'=>'player','text'=>mb_strcut($input,0,2048,'UTF-8')];
            foreach($exchange['npc_responses']??[]as$text)if(is_string($text)&&trim($text)!=='')$lines[]=['speaker'=>'npc','text'=>mb_strcut(trim($text),0,2048,'UTF-8')];
        }
        $lines=array_slice($lines,-SceneClassificationPolicy::HISTORY_LINES);if($lines===[])return ['queued'=>false,'reason'=>'history_unavailable'];
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare("SELECT t.completed_at FROM active_turns t JOIN sessions s ON s.session_id=t.session_id JOIN profiles p ON p.profile_id=:profile AND p.installation_id=s.installation_id AND p.deleted_at IS NULL WHERE t.turn_id=:turn AND t.state='complete' AND s.installation_id=:installation AND s.playthrough_id=:playthrough AND ".ProfileScopeSql::matches('p','s.playthrough_id',true)." FOR SHARE OF t");
            $scope=['profile'=>$profile,'turn'=>$turn['turn_id'],'installation'=>$installation,'playthrough'=>$turn['playthrough_id']];$q->execute($scope);$observed=$q->fetchColumn();
            if(!$observed)throw new RuntimeException('scene_scope_unavailable');
            $key='scene:'.$turn['turn_id'];$job=Uuid::v4();$payload=['installation_id'=>$installation,'profile_id'=>$profile,'provider_configuration_id'=>$route['configuration_id'],'provider_revision'=>(int)$route['current_revision']];
            $q=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'scene.classify',1,:key,CAST(:payload AS jsonb),1,20) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id");
            $q->execute(['job'=>$job,'key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            if($q->fetchColumn()){
                $q=$this->db->prepare("INSERT INTO scene_classifications(job_id,installation_id,playthrough_id,profile_id,turn_id,history,observed_at) VALUES(:job,:installation,:playthrough,:profile,:turn,CAST(:history AS jsonb),:observed)");
                $q->execute($scope+['job'=>$job,'history'=>json_encode($lines,JSON_THROW_ON_ERROR),'observed'=>$observed]);
            }else{$q=$this->db->prepare("SELECT job_id FROM durable_jobs WHERE job_type='scene.classify' AND idempotency_key=:key");$q->execute(['key'=>$key]);$job=(string)$q->fetchColumn();}
            $this->db->commit();return ['queued'=>true,'job_id'=>$job];
        }catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    public function input(string $installation,string $profile,string $job):array
    {
        $q=$this->db->prepare('SELECT c.history FROM scene_classifications c JOIN profiles p ON p.profile_id=c.profile_id AND p.installation_id=c.installation_id WHERE c.installation_id=:installation AND c.profile_id=:profile AND c.job_id=:job AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','c.playthrough_id',true));
        $q->execute(['installation'=>$installation,'profile'=>$profile,'job'=>$job]);$value=$q->fetchColumn();if($value===false)throw new RuntimeException('scene_scope_unavailable');
        return ['dialogue'=>json_decode($value,true,32,JSON_THROW_ON_ERROR)];
    }

    public function save(string $job,int $attempt,string $lease,string $genre):void
    {
        $genre=SceneClassificationPolicy::output(['genre'=>$genre])['genre'];
        $q=$this->db->prepare("UPDATE scene_classifications c SET genre=:genre,classified_at=clock_timestamp() FROM durable_jobs j,profiles p WHERE p.profile_id=c.profile_id AND p.installation_id=c.installation_id AND p.deleted_at IS NULL AND ".ProfileScopeSql::matches('p','c.playthrough_id',true)." AND c.job_id=:job AND j.job_id=c.job_id AND j.job_type='scene.classify' AND j.state='leased' AND j.attempt_count=:attempt AND j.lease_token=:lease AND j.lease_expires_at>clock_timestamp()");
        $q->execute(['genre'=>$genre,'job'=>$job,'attempt'=>$attempt,'lease'=>$lease]);if($q->rowCount()!==1)throw new RuntimeException('lease_lost');
    }

    /** Only completed jobs in this actor/playthrough scope can influence a later prompt. */
    public function context(string $installation,string $playthrough,string $profile):?array
    {
        if($this->route($installation)===null)return null;
        $q=$this->db->prepare("SELECT c.job_id,c.genre,c.observed_at,c.classified_at+interval '60 seconds'>clock_timestamp() AS fresh FROM scene_classifications c JOIN durable_jobs j ON j.job_id=c.job_id AND j.state='succeeded' WHERE c.installation_id=:installation AND c.playthrough_id=:playthrough AND c.profile_id=:profile AND c.genre IS NOT NULL ORDER BY c.observed_at DESC,c.turn_id DESC LIMIT 1");
        $q->execute(['installation'=>$installation,'playthrough'=>$playthrough,'profile'=>$profile]);$row=$q->fetch();if(!$row)return null;
        return ['job_id'=>$row['job_id'],'genre'=>$row['genre'],'status'=>SceneClassificationPolicy::status($row['genre']),'note'=>$row['fresh']?SceneClassificationPolicy::note($row['genre']):''];
    }
}
