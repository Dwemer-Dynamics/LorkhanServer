<?php
declare(strict_types=1);
namespace LORKHANserver\Infrastructure;
use PDO;
use LORKHANserver\Application\EffectiveSettingsResolver;
use LORKHANserver\Application\RelationshipEvaluationPolicy;
use LORKHANserver\Application\RelationshipType;

/** Database fences for optional evaluations of witnessed, fully played exchanges. */
final class RelationshipEvaluationRepository
{
    public function __construct(private readonly PDO $db) {}

    /** Queue once per completed response; chance zero does not load relationship state or launch work. */
    public function enqueue(string $sourceId):?array
    {
        return $this->transaction(function()use($sourceId):?array{
            $source=$this->source($sourceId,false);if($source===null)return null;
            $policy=$this->policy($source['installation_id'],$source['profile_id']);
            if($policy===null||$policy['locked']||$policy['provider_configuration_id']==='')return null;
            $key='relationship.evaluate:'.$source['turn_id'].':'.$source['profile_id'];
            if(!RelationshipEvaluationPolicy::eligible($policy['update_chance_percent'],$key))return null;
            $source=$this->source($sourceId);if($source===null)return null;
            $this->lockIdentity($source);$records=$this->records($source);if($records===null)return null;
            $provider=$this->db->prepare("SELECT current_revision FROM configuration_sets WHERE configuration_id=:provider
                AND installation_id=:installation AND kind='provider' AND deleted_at IS NULL FOR SHARE");
            $provider->execute(['provider'=>$policy['provider_configuration_id'],'installation'=>$source['installation_id']]);
            $providerRevision=$provider->fetchColumn();if($providerRevision===false)return null;
            $types=$this->typeCatalog($source);
            $payload=array_intersect_key($source,array_fill_keys(['source_event_id','turn_id','session_id','installation_id','profile_id','playthrough_id'],true))
                +$policy+['provider_revision'=>(int)$providerRevision,'relationship_fence'=>$records['fence']]
                +$types;
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority)
                VALUES(:id,'relationship.evaluate',1,:key,CAST(:payload AS jsonb),3,20) ON CONFLICT(job_type,idempotency_key) DO NOTHING");
            $insert->execute(['id'=>Uuid::v4(),'key'=>$key,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
            $query=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='relationship.evaluate' AND idempotency_key=:key");
            $query->execute(['key'=>$key]);return $query->fetch()?:null;
        });
    }

    /** Recheck source visibility, session, owner, policy, lease and manual edits before provider I/O or writes. */
    public function input(array $payload):?array
    {
        $job=$payload['_job'];
        $query=$this->db->prepare("SELECT 1 FROM durable_jobs j WHERE j.job_id=:job AND j.job_type='relationship.evaluate'
            AND j.state='leased' AND j.lease_token=:lease AND j.attempt_count=:attempt AND j.lease_expires_at>clock_timestamp()
            AND NOT EXISTS(SELECT 1 FROM relationship_evaluation_results result WHERE result.job_id=j.job_id) FOR SHARE OF j");
        $query->execute(['job'=>$job['job_id'],'lease'=>$job['lease_token'],'attempt'=>$job['attempt']]);
        if(!$query->fetchColumn())return null;
        $source=$this->source($payload['source_event_id']);if($source===null)return null;
        foreach(['installation_id','profile_id','playthrough_id','session_id','turn_id']as$field)
            if($source[$field]!==$payload[$field])return null;
        $policy=$this->policy($source['installation_id'],$source['profile_id']);
        if($policy===null||$policy['locked']||$policy['provider_configuration_id']==='')return null;
        foreach($policy as$field=>$value)if(($payload[$field]??null)!==$value)return null;
        $records=$this->records($source);
        if($records===null||!hash_equals($payload['relationship_fence'],$records['fence']))return null;
        $types=$this->typeCatalog($source);
        if(($payload['relationship_types']??null)!==$types['relationship_types']
            ||!hash_equals((string)($payload['relationship_types_sha256']??''),$types['relationship_types_sha256']))return null;
        $record=$records['record'];
        return ['source'=>$source,'record'=>$record,'model'=>[
            'generation_mode'=>'relationship_evaluation','owner'=>$source['owner_identity'],'interlocutor'=>$source['target_identity'],
            'input'=>$source['input_text'],'played_reply'=>$source['reply'],
            'disposition'=>(int)($record['disposition']??0),'affinity'=>(int)($record['affinity']??0),
            'relationship_type'=>(string)($record['relationship_type']??'neutral'),
            'available_relationship_types'=>$types['relationship_types'],
        ]];
    }

    /** Apply bounded deltas and their receipt atomically; provider work never runs inside this transaction. */
    public function save(array $payload,array $output,string $now):bool
    {
        $output=RelationshipEvaluationPolicy::output($output);
        return $this->transaction(function()use($payload,$output,$now):bool{
            $source=$this->source($payload['source_event_id']);if($source===null)return false;
            $this->lockIdentity($source);$input=$this->input($payload);if($input===null)return false;
            $record=$input['record'];$beforeDisposition=(int)($record['disposition']??0);$beforeAffinity=(int)($record['affinity']??0);
            $disposition=max(-100,min(100,$beforeDisposition+$output['disposition_delta']));
            $affinity=max(-100,min(100,$beforeAffinity+$output['affinity_delta']));
            $beforeType=(string)($record['relationship_type']??'neutral');
            $relationshipType=RelationshipType::model($output['relationship_type']??null,
                $payload['relationship_types'],$affinity,$output['reason'],$beforeType)??$beforeType;
            $relationshipId=$record['relationship_id']??null;
            if($disposition!==$beforeDisposition||$affinity!==$beforeAffinity||$relationshipType!==$beforeType){
                $write=array_intersect_key($source,array_fill_keys(['installation_id','profile_id','playthrough_id'],true))
                    +['disposition'=>$disposition,'affinity'=>$affinity,'relationship_type'=>$relationshipType,'source_mode'=>'derived',
                        'source_event_id'=>$source['source_event_id'],'reason'=>$output['reason']];
                if($record===null)$write['actor_identity']=$source['target_identity'];
                else $write+=['relationship_id'=>$relationshipId,'expected_revision'=>(int)$record['revision']];
                $saved=(new ProductRepository($this->db))->setRelationship($write,$now);$relationshipId=$saved['relationship_id'];
            }
            $query=$this->db->prepare('INSERT INTO relationship_evaluation_results '
                .'(job_id,source_event_id,relationship_id,disposition_delta,affinity_delta,reason,created_at) '
                .'SELECT job_id,:source,:relationship,:disposition,:affinity,:reason,:now FROM durable_jobs '
                ."WHERE job_id=:job AND state='leased' AND lease_token=:lease AND attempt_count=:attempt AND lease_expires_at>clock_timestamp()");
            $query->execute(['job'=>$payload['_job']['job_id'],'source'=>$source['source_event_id'],'relationship'=>$relationshipId,
                'disposition'=>$disposition-$beforeDisposition,'affinity'=>$affinity-$beforeAffinity,'reason'=>$output['reason'],'now'=>$now,
                'lease'=>$payload['_job']['lease_token'],'attempt'=>$payload['_job']['attempt']]);
            if($query->rowCount()!==1)throw new \LORKHANserver\Application\OperationCancelled('lease_lost');
            return true;
        });
    }

    /** Only the frozen NPC owner and its actual interlocutor may receive a derived relationship. */
    public function source(string $id,bool $withText=true,bool $historical=false):?array
    {
        $inputColumn=$withText?',t.input_text':'';
        $liveGuard=$historical?'':" AND s.state='active' AND NOT EXISTS(SELECT 1 FROM turns newer
            WHERE newer.session_id=t.session_id AND newer.runtime_generation>t.runtime_generation)";
        $query=$this->db->prepare("SELECT d.source_event_id,t.turn_id,t.session_id,t.generation,t.runtime_generation,
            s.installation_id,s.playthrough_id,trace.selected_profile_id AS profile_id,
            t.target AS owner_identity,t.speaker AS target_identity{$inputColumn}
            FROM dialogue_delivery_results d JOIN dialogue_utterances delivered ON delivered.dialogue_message_id=d.dialogue_message_id
            JOIN turns t ON t.turn_id=d.turn_id JOIN sessions s ON s.session_id=t.session_id
            JOIN prompt_traces trace ON trace.turn_id=t.turn_id
            JOIN eventlog_metadata input_event ON input_event.projection_key='turn:'||t.turn_id::text
                AND input_event.projection_kind='turn' AND input_event.suppressed_at IS NULL
            WHERE d.source_event_id=:source AND d.status='played' AND s.generation=t.generation {$liveGuard}
                AND t.state='complete' AND t.target->>'kind' IN ('npc','creature')
                AND NOT EXISTS(SELECT 1 FROM dialogue_utterances pending WHERE pending.turn_id=t.turn_id AND pending.delivery_state<>'played')
                AND t.speaker->>'kind' IN ('player','npc','creature') AND trace.selected_profile_id IS NOT NULL
                AND relationship_identity_key(delivered.speaker)=relationship_identity_key(t.target)
                AND (relationship_identity_key(input_event.target)=relationship_identity_key(t.target)
                    OR relationship_identity_key(input_event.speaker)=relationship_identity_key(t.target)
                    OR EXISTS(SELECT 1 FROM jsonb_array_elements(input_event.audience) witness(identity)
                        WHERE relationship_identity_key(witness.identity)=relationship_identity_key(t.target)))
            ORDER BY trace.created_at DESC LIMIT 1 FOR SHARE OF d,delivered,t,s,trace,input_event");
        $query->execute(['source'=>$id]);$source=$query->fetch();if(!$source)return null;
        foreach(['owner_identity','target_identity']as$field)$source[$field]=json_decode($source[$field],true,32,JSON_THROW_ON_ERROR);
        $products=new ProductRepository($this->db);$ownerKey=$products->actorKey($source['owner_identity']);
        if($ownerKey===$products->actorKey($source['target_identity']))return null;
        $binding=$this->db->prepare('SELECT profile_id FROM actor_profile_bindings WHERE installation_id=:installation '
            .'AND playthrough_id=:playthrough AND actor_key=:actor FOR SHARE');
        $binding->execute(['installation'=>$source['installation_id'],'playthrough'=>$source['playthrough_id'],'actor'=>$ownerKey]);
        if($binding->fetchColumn()!==$source['profile_id'])return null;
        if(!$withText)return $source;
        $query=$this->db->prepare("SELECT u.text,u.speaker,u.utterance_count FROM dialogue_utterances u
            JOIN eventlog_metadata event ON event.projection_key='dialogue:'||u.dialogue_message_id::text
                AND event.projection_kind='dialogue' AND event.suppressed_at IS NULL
            WHERE u.turn_id=:turn AND u.delivery_state='played' ORDER BY u.utterance_index LIMIT 17 FOR SHARE OF u,event");
        $query->execute(['turn'=>$source['turn_id']]);$utterances=$query->fetchAll();
        if($utterances===[]||count($utterances)>16||count($utterances)!==(int)$utterances[0]['utterance_count'])return null;
        foreach($utterances as$utterance){
            if((int)$utterance['utterance_count']!==count($utterances)
                ||$products->actorKey(json_decode($utterance['speaker'],true,32,JSON_THROW_ON_ERROR))!==$ownerKey)return null;
        }
        $source['reply']=implode("\n",array_column($utterances,'text'));
        if(!$historical&&strlen($source['input_text'])+strlen($source['reply'])>32768)return null;
        return $source;
    }

    /** Read only the two profile layers; relationship work never needs Oghma or global settings. */
    public function policy(string $installation,string $profile):?array
    {
        $query=$this->db->prepare('SELECT profile_id,core_profile_id,current_revision FROM profiles '
            .'WHERE installation_id=:installation AND profile_id=:profile AND deleted_at IS NULL FOR SHARE');
        $query->execute(['installation'=>$installation,'profile'=>$profile]);$owner=$query->fetch();
        if(!$owner)return null;
        $query=$this->db->prepare('SELECT c.core_profile_id,c.current_revision,r.content FROM core_profiles c '
            .'JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision '
            .'WHERE c.installation_id=:installation AND c.deleted_at IS NULL AND (c.core_profile_id=:core OR c.default_npc) '
            .'ORDER BY CASE WHEN c.core_profile_id=:selected_core THEN 0 ELSE 1 END,c.core_profile_id LIMIT 1 FOR SHARE OF c');
        $query->execute(['installation'=>$installation,'core'=>$owner['core_profile_id'],'selected_core'=>$owner['core_profile_id']]);
        $core=$query->fetch();
        $query=$this->db->prepare('SELECT content FROM profile_revisions WHERE profile_id=:profile AND revision=:revision');
        $query->execute(['profile'=>$profile,'revision'=>$owner['current_revision']]);
        $content=json_decode($query->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
        $resolved=(new EffectiveSettingsResolver())->resolve([],$core?json_decode($core['content'],true,32,JSON_THROW_ON_ERROR):[],$content);
        return ['profile_revision'=>(int)$owner['current_revision'],'core_profile_id'=>$core['core_profile_id']??null,
            'core_profile_revision'=>isset($core['current_revision'])?(int)$core['current_revision']:null,
            'provider_configuration_id'=>$resolved['routing']['relationship_configuration_id']??'']+$resolved['settings']['relationship'];
    }

    /** Include deleted rows in the fence so transient manual create/delete cycles cannot be overwritten. */
    public function records(array $source):?array
    {
        $query=$this->db->prepare('SELECT relationship_id,revision,deleted_at,disposition,affinity,relationship_type FROM relationship_records '
            .'WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough '
            .'AND md5(relationship_identity_key(actor_identity)::text)=md5(relationship_identity_key(CAST(:identity AS jsonb))::text) '
            .'AND relationship_identity_key(actor_identity)=relationship_identity_key(CAST(:exact_identity AS jsonb)) '
            .'ORDER BY relationship_id LIMIT 101 FOR UPDATE');
        $identity=json_encode($source['target_identity'],JSON_THROW_ON_ERROR);
        $query->execute(['installation'=>$source['installation_id'],'profile'=>$source['profile_id'],'playthrough'=>$source['playthrough_id'],
            'identity'=>$identity,'exact_identity'=>$identity]);$rows=$query->fetchAll();
        if(count($rows)>100)return null;
        $active=array_values(array_filter($rows,static fn(array $row):bool=>$row['deleted_at']===null));
        if(count($active)>1)return null;
        return ['fence'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),'record'=>$active[0]??null];
    }

    /** Freeze built-in and player-created labels across provider work for one relationship owner. */
    public function typeCatalog(array $scope):array
    {
        $query=$this->db->prepare('SELECT DISTINCT relationship_type FROM relationship_records '
            .'WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL');
        $query->execute(['installation'=>$scope['installation_id'],'profile'=>$scope['profile_id'],'playthrough'=>$scope['playthrough_id']]);
        $types=RelationshipType::available($query->fetchAll(PDO::FETCH_COLUMN));
        return ['relationship_types'=>$types,
            'relationship_types_sha256'=>hash('sha256',json_encode($types,JSON_THROW_ON_ERROR))];
    }

    public function lockIdentity(array $source):void
    {
        $key='relationship:'.$source['installation_id'].':'.$source['profile_id'].':'.$source['playthrough_id'].':'
            .(new ProductRepository($this->db))->actorKey($source['target_identity']);
        $query=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');$query->execute(['key'=>$key]);
    }

    private function transaction(callable $work):mixed
    {
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{$result=$work();if($owns)$this->db->commit();return $result;}
        catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }
}
