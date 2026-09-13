<?php

declare(strict_types=1);
namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\MemoryDigestPolicy;
use PDO;
use RuntimeException;

/** Versioned NPC canon with immutable source revisions and lease-fenced generation. */
final class NpcMemoryDigestRepository
{
    public function __construct(private readonly PDO $db){}

    /** A new scene starts a paged scan of its participating bound NPCs, never provider work on the request thread. */
    public function enqueueScan(array $memory):void
    {
        if(($memory['tier']??'')!=='mid')return;
        $globals=(new ProductRepository($this->db))->globalSettingsForInstallation($memory['installation_id'])['content']??[];
        if(($globals['task_availability']['background_memory']??true)!==true||empty($globals['system_routing']['background_memory_configuration_id']))return;
        $payload=['installation_id'=>$memory['installation_id'],'playthrough_id'=>$memory['playthrough_id'],
            'memory_id'=>$memory['memory_id'],'memory_revision'=>(int)($memory['current_revision']??1),'after_profile'=>null];
        (new JobRepository($this->db))->enqueue(Uuid::v4(),'memory.digest.scan',1,'memory.digest.scan:'.hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),$payload,3,null,19);
    }

    public function scan(array $payload,callable $heartbeat):void
    {
        $q=$this->db->prepare("SELECT provenance FROM memory_records WHERE memory_id=:memory AND installation_id=:installation AND playthrough_id=:playthrough AND current_revision=:revision AND deleted_at IS NULL AND tier='mid' AND derivation_key IS NOT NULL AND (expires_at IS NULL OR expires_at>clock_timestamp())");
        $q->execute(['memory'=>$payload['memory_id'],'installation'=>$payload['installation_id'],'playthrough'=>$payload['playthrough_id'],'revision'=>$payload['memory_revision']]);$provenance=$q->fetchColumn();if($provenance===false)return;
        $sources=json_decode($provenance,true,32,JSON_THROW_ON_ERROR)['source_event_ids']??[];
        if(!is_array($sources)||!array_is_list($sources)||count($sources)>64||$sources===[])return;
        foreach($sources as$id)if(!is_string($id)||!Uuid::isValid($id))return;
        $q=$this->db->prepare("SELECT DISTINCT b.profile_id FROM actor_profile_bindings b JOIN profiles p ON p.profile_id=b.profile_id AND p.installation_id=b.installation_id AND p.deleted_at IS NULL
            CROSS JOIN LATERAL (SELECT jsonb_strip_nulls(jsonb_build_object('kind',b.actor_identity->'kind','record_id',b.actor_identity->'record_id','content_file',b.actor_identity->'content_file','refnum',b.actor_identity->'refnum')) AS actor) k
            JOIN eventlog_metadata m ON m.installation_id=b.installation_id AND m.playthrough_id=b.playthrough_id AND m.source_event_id=ANY(CAST(:sources AS uuid[])) AND m.suppressed_at IS NULL
                AND (m.speaker @> k.actor OR m.target @> k.actor OR m.audience @> jsonb_build_array(k.actor))
            WHERE b.installation_id=:installation AND b.playthrough_id=:playthrough AND b.profile_id>CAST(:after AS uuid)
            AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('narrator','player') ORDER BY b.profile_id LIMIT 25");
        $q->execute(['sources'=>'{'.implode(',',$sources).'}','installation'=>$payload['installation_id'],'playthrough'=>$payload['playthrough_id'],'after'=>$payload['after_profile']??'00000000-0000-0000-0000-000000000000']);$profiles=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($profiles as$profile){if(!$heartbeat())throw new RuntimeException('lease_lost');$this->enqueue($payload['installation_id'],$payload['playthrough_id'],$profile);}
        if(count($profiles)===25){$next=$payload;unset($next['_job']);$next['after_profile']=$profiles[24];
            (new JobRepository($this->db))->enqueue(Uuid::v4(),'memory.digest.scan',1,'memory.digest.scan:'.hash('sha256',json_encode($next,JSON_THROW_ON_ERROR)),$next,3,null,19);}
    }

    public function latest(string $installation,string $playthrough,string $profile):?array
    {
        $scope=$this->scope($installation,$playthrough,$profile);
        $q=$this->db->prepare('SELECT * FROM npc_memory_digests WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id=:profile ORDER BY revision DESC LIMIT 1');
        $q->execute($scope);$latest=$q->fetch();return $latest?$this->visible($scope,$latest):null;
    }

    private function visible(array $scope,array $latest):?array
    {
        if($latest['cleared'])return null;
        $q=$this->db->prepare("WITH RECURSIVE chain AS (
            SELECT d.*,1 AS depth FROM npc_memory_digests d WHERE digest_id=:id
            UNION ALL SELECT parent.*,child.depth+1 FROM npc_memory_digests parent JOIN chain child ON child.previous_digest_id=parent.digest_id WHERE child.depth<1000)
            SELECT digest_id,previous_digest_id,source_revisions,depth FROM chain ORDER BY depth");
        $q->execute(['id'=>$latest['digest_id']]);$chain=$q->fetchAll();$tail=$chain[array_key_last($chain)];
        if($tail['previous_digest_id']!==null)throw new RuntimeException('digest_history_limit');
        foreach($chain as$row)if(!$this->sourcesValid($scope,json_decode($row['source_revisions'],true,32,JSON_THROW_ON_ERROR)))return null;
        return ['digest_id'=>$latest['digest_id'],'revision'=>(int)$latest['revision'],'content'=>$latest['content'],
            'cursor'=>['occurred_at'=>$latest['cursor_occurred_at'],'memory_id'=>$latest['cursor_memory_id']],
            'created_at'=>$latest['created_at'],'previous_digest_id'=>$latest['previous_digest_id'],
            'source_revisions'=>json_decode($latest['source_revisions'],true,32,JSON_THROW_ON_ERROR)];
    }

    /** Return a conflict token even when the newest entry is cleared or no longer visible. */
    public function editor(string $installation,string $playthrough,string $profile):array
    {
        $scope=$this->scope($installation,$playthrough,$profile);
        return ['revision'=>$this->revision($scope),'content'=>$this->latest($installation,$playthrough,$profile)['content']??''];
    }

    /** Replace the latest logical entry or remove it, preserving immutable audit revisions. */
    public function edit(string $installation,string $playthrough,string $profile,int $expectedRevision,string $text):void
    {
        $scope=$this->scope($installation,$playthrough,$profile);
        if(trim($text)!=='')$text=MemoryDigestPolicy::content($text);else $text='';
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $this->lockProfile($scope);$revision=$this->revision($scope);
            if($expectedRevision!==$revision)throw new RuntimeException('revision_conflict');
            $latest=$this->latest($installation,$playthrough,$profile);
            if($text===($latest['content']??'')){if($owns)$this->db->commit();return;}
            if($text===''){
                $parent=null;
                if($latest['previous_digest_id']!==null){
                    $q=$this->db->prepare('SELECT * FROM npc_memory_digests WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id=:profile AND digest_id=:id');
                    $q->execute($scope+['id'=>$latest['previous_digest_id']]);$row=$q->fetch();
                    if($row)$parent=$this->visible($scope,$row);
                }
                $latest=$parent;$text=$parent['content']??'';
            }
            $q=$this->db->prepare('INSERT INTO npc_memory_digests(digest_id,installation_id,playthrough_id,profile_id,revision,previous_digest_id,content,cleared,cursor_occurred_at,cursor_memory_id,source_revisions) VALUES(:id,:installation,:playthrough,:profile,:revision,:previous,:content,CAST(:cleared AS boolean),:occurred,:memory,CAST(:sources AS jsonb))');
            $q->execute($scope+['id'=>Uuid::v4(),'revision'=>$revision+1,'previous'=>$latest['previous_digest_id']??null,
                'content'=>$text,'cleared'=>$text===''?'true':'false','occurred'=>$latest['cursor']['occurred_at']??'1970-01-01T00:00:00Z',
                'memory'=>$latest['cursor']['memory_id']??'00000000-0000-4000-8000-000000000000',
                'sources'=>json_encode($latest['source_revisions']??[],JSON_THROW_ON_ERROR)]);
            if($owns)$this->db->commit();
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    /** Queue one frozen batch per NPC; generation is independent of whether its prompt fragment is displayed. */
    public function enqueue(string $installation,string $playthrough,string $profile):?array
    {
        $scope=$this->scope($installation,$playthrough,$profile);$owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $npc=$this->lockProfile($scope);$products=new ProductRepository($this->db);$globals=$products->globalSettingsForInstallation($installation)['content']??[];
            $route=(string)($globals['system_routing']['background_memory_configuration_id']??'');
            if(($globals['task_availability']['background_memory']??true)!==true||$route===''){if($owns)$this->db->commit();return null;}
            $q=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='memory.digest' AND state IN ('queued','leased') AND payload->>'installation_id'=:installation AND payload->>'playthrough_id'=:playthrough AND payload->>'profile_id'=:profile LIMIT 1");
            $q->execute($scope);if($pending=$q->fetch()){if($owns)$this->db->commit();return $pending;}
            $slot=$products->getRevisioned('provider',$route);if($slot['installation_id']!==$installation)throw new RuntimeException('digest_connector_unavailable');
            $previous=$this->latest($installation,$playthrough,$profile);
            $input=MemoryDigestPolicy::input($products->memoryDigestCandidates($installation,$playthrough,$profile,gmdate('c')),$previous);
            if($input===null){if($owns)$this->db->commit();return null;}
            $payload=['installation_id'=>$installation,'playthrough_id'=>$playthrough,'profile_id'=>$profile,
                'base_revision'=>$this->revision($scope),'previous_digest_id'=>$previous['digest_id']??null,'npc_name'=>$npc['name'],
                'provider_configuration_id'=>$slot['configuration_id'],'provider_revision'=>(int)$slot['current_revision'],'input'=>$input];
            $json=json_encode($payload,JSON_THROW_ON_ERROR);if(strlen($json)>2097152)throw new RuntimeException('digest_input_limit');
            $job=(new JobRepository($this->db))->enqueue(Uuid::v4(),'memory.digest',1,'memory.digest:'.$profile.':'.hash('sha256',$json),$payload,3,null,20);
            if($owns)$this->db->commit();return ['job_id'=>$job['job_id'],'state'=>$job['state']];
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    /** Revalidate prior canon and every frozen scene before provider I/O and again before saving. */
    public function input(array $payload):?array
    {
        $job=$payload['_job']??[];$frozen=$payload;unset($frozen['_job']);
        $scope=$this->scope($payload['installation_id']??'',$payload['playthrough_id']??'',$payload['profile_id']??'');
        $q=$this->db->prepare("SELECT payload FROM durable_jobs WHERE job_id=:job AND job_type='memory.digest' AND state='leased' AND lease_token=:lease AND attempt_count=:attempt AND lease_expires_at>clock_timestamp()");
        $q->execute(['job'=>$job['job_id']??null,'lease'=>$job['lease_token']??null,'attempt'=>$job['attempt']??0]);$stored=$q->fetchColumn();
        if($stored===false||json_decode($stored,true,64,JSON_THROW_ON_ERROR)!=$frozen)return null;
        $products=new ProductRepository($this->db);$globals=$products->globalSettingsForInstallation($scope['installation'])['content']??[];
        if(($globals['task_availability']['background_memory']??true)!==true||empty($globals['system_routing']['background_memory_configuration_id']))return null;
        if($this->revision($scope)!==($payload['base_revision']??null))return null;
        $previous=$this->latest($scope['installation'],$scope['playthrough'],$scope['profile']);
        if(($previous['digest_id']??null)!==($payload['previous_digest_id']??null))return null;
        $history=$payload['input']['history']??[];
        if(!$this->sourcesValid($scope,$history))return null;
        $rows=$products->memoryDigestCandidates($scope['installation'],$scope['playthrough'],$scope['profile'],gmdate('c'),array_column($history,'memory_id'));
        $input=MemoryDigestPolicy::input($rows,$previous);
        return $input!==null&&$input==$payload['input']?['generation_mode'=>'memory_digest','name'=>$payload['npc_name']]+$input:null;
    }

    public function save(array $payload,string $content):bool
    {
        $content=MemoryDigestPolicy::content($content);$scope=$this->scope($payload['installation_id'],$payload['playthrough_id'],$payload['profile_id']);
        $owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();
        try{
            $this->lockProfile($scope);if($this->input($payload)===null){if($owns)$this->db->commit();return false;}
            $sources=array_map(static fn(array $row):array=>array_diff_key($row,['content'=>true]),$payload['input']['history']);
            $q=$this->db->prepare('INSERT INTO npc_memory_digests(digest_id,installation_id,playthrough_id,profile_id,revision,previous_digest_id,job_id,content,cursor_occurred_at,cursor_memory_id,source_revisions) VALUES(:id,:installation,:playthrough,:profile,:revision,:previous,:job,:content,:occurred,:memory,CAST(:sources AS jsonb))');
            $q->execute($scope+['id'=>Uuid::v4(),'revision'=>$payload['base_revision']+1,'previous'=>$payload['previous_digest_id'],'job'=>$payload['_job']['job_id'],
                'content'=>$content,'occurred'=>$payload['input']['cursor']['occurred_at'],'memory'=>$payload['input']['cursor']['memory_id'],'sources'=>json_encode($sources,JSON_THROW_ON_ERROR)]);
            (new JobRepository($this->db))->succeed($payload['_job']['job_id'],$payload['_job']['lease_token']);
            if($owns)$this->db->commit();return true;
        }catch(\Throwable $error){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    private function sourcesValid(array $scope,array $sources):bool
    {
        if($sources===[])return true;
        $ids=array_column($sources,'memory_id');if(count($ids)!==count($sources)||count(array_unique($ids))!==count($ids))return false;
        $rows=(new ProductRepository($this->db))->memoryDigestCandidates($scope['installation'],$scope['playthrough'],$scope['profile'],gmdate('c'),$ids);
        $indexed=array_column($rows,null,'id');
        foreach($sources as$source){$row=$indexed[$source['memory_id']]??null;
            if(!$row||(int)$row['current_revision']!==($source['revision']??null)||!is_string($source['content_sha256']??null)
                ||!hash_equals($source['content_sha256'],hash('sha256',$row['content'])))return false;}
        return true;
    }

    private function revision(array $scope):int
    {
        $q=$this->db->prepare('SELECT COALESCE(max(revision),0) FROM npc_memory_digests WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id=:profile');$q->execute($scope);return (int)$q->fetchColumn();
    }

    private function lockProfile(array $scope):array
    {
        $q=$this->db->prepare("SELECT p.name FROM profiles p JOIN playthroughs t ON t.installation_id=p.installation_id WHERE p.profile_id=:profile AND p.installation_id=:installation AND t.playthrough_id=:playthrough AND p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('narrator','player') FOR UPDATE OF p");
        $q->execute($scope);return $q->fetch()?:throw new RuntimeException('digest_profile_unavailable');
    }

    private function scope(string $installation,string $playthrough,string $profile):array
    {
        foreach([$installation,$playthrough,$profile]as$id)if(!Uuid::isValid($id))throw new \InvalidArgumentException('invalid_digest_scope');
        return ['installation'=>$installation,'playthrough'=>$playthrough,'profile'=>$profile];
    }
}
