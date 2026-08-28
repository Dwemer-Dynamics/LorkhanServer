<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Application\EffectiveSettingsResolver;
use ALMSIVIserver\Application\MorrowindGeographyCatalog;
use ALMSIVIserver\Application\MorrowindVoiceCatalog;
use ALMSIVIserver\Application\DeterministicRetrieval;
use ALMSIVIserver\Application\OghmaGroundedRetriever;
use PDO;
use RuntimeException;
use Throwable;

final class ProductRepository
{
    private ?MorrowindGeographyCatalog $morrowindGeography=null;

    public function __construct(private readonly PDO $db) {}

    /** @param array<string,mixed> $input */
    public function createRevisioned(string $kind, array $input, string $now): array
    {
        return $this->transaction(function () use ($kind, $input, $now): array {
            $id = Uuid::v4();
            $reason = (string) ($input['change_reason'] ?? 'created');
            if ($kind === 'core_profile') {
                $defaultNpc = ($input['default_npc'] ?? false) === true;
                if ($defaultNpc) {
                    $this->db->prepare('UPDATE core_profiles SET default_npc=false WHERE installation_id=:installation AND default_npc=true')
                        ->execute(['installation'=>$input['installation_id']]);
                }
                $this->db->prepare('INSERT INTO core_profiles (core_profile_id,installation_id,label,default_npc,slot,created_at) VALUES (:id,:installation,:label,:default_npc,:slot,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'label'=>$input['name'],
                        'default_npc'=>$defaultNpc?'true':'false','slot'=>$input['slot']??null,'now'=>$now]);
                $this->revision('core_profile_revisions', 'core_profile_id', $id, 1, $input['content'], $reason, $now);
            } elseif ($kind === 'profile') {
                $coreProfileId = $input['core_profile_id'] ?? $this->defaultCoreProfileForInstallation((string)$input['installation_id'], $now, true)['core_profile_id'];
                $this->db->prepare('INSERT INTO profiles (profile_id,installation_id,name,actor_identity,core_profile_id,created_at) VALUES (:id,:installation,:name,CAST(:identity AS jsonb),:core_profile,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'name'=>$input['name'],'identity'=>$this->encode($input['actor_identity'] ?? []),'core_profile'=>$coreProfileId,'now'=>$now]);
                $this->revision('profile_revisions', 'profile_id', $id, 1, $input['content'], $reason, $now);
            } elseif ($kind === 'playthrough') {
                $this->db->prepare('INSERT INTO playthroughs (playthrough_id, installation_id, profile_id, name, content_fingerprint, created_at) VALUES (:id,:installation,:profile,:name,:fingerprint,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'],'name'=>$input['name'],'fingerprint'=>$input['content_fingerprint'] ?? null,'now'=>$now]);
                $this->revision('playthrough_revisions', 'playthrough_id', $id, 1, $input['content'], $reason, $now);
            } else {
                $configKind = match ($kind) {
                    'prompt', 'provider', 'tts_provider', 'stt_provider', 'action_policy', 'global_settings' => $kind,
                    default => throw new RuntimeException('invalid_resource_kind'),
                };
                $this->db->prepare('INSERT INTO configuration_sets (configuration_id,installation_id,profile_id,kind,name,created_at) VALUES (:id,:installation,:profile,:kind,:name,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'] ?? null,'kind'=>$configKind,'name'=>$input['name'],'now'=>$now]);
                $this->revision('configuration_revisions', 'configuration_id', $id, 1, $input['content'], $reason, $now);
                if($configKind==='prompt')$this->syncPrompt($id,$input['content'],1,$now);
            }
            return $this->getRevisioned($kind, $id);
        });
    }

    public function resourceKind(string $id):string
    {
        foreach([['profiles','profile_id','profile'],['core_profiles','core_profile_id','core_profile'],['playthroughs','playthrough_id','playthrough']] as[$table,$key,$kind]){$s=$this->db->prepare("SELECT 1 FROM {$table} WHERE {$key}=:id");$s->execute(['id'=>$id]);if($s->fetchColumn())return$kind;}
        $s=$this->db->prepare('SELECT kind FROM configuration_sets WHERE configuration_id=:id');$s->execute(['id'=>$id]);$kind=$s->fetchColumn();if($kind===false)throw new RuntimeException('not_found');return$kind==='action_policy'?'action_policy':(string)$kind;
    }
    public function revisionContent(string $kind,string $id,int $revision):array{[, $key,$table]=$this->revisionMeta($kind);$s=$this->db->prepare("SELECT content FROM {$table} WHERE {$key}=:id AND revision=:revision");$s->execute(['id'=>$id,'revision'=>$revision]);$v=$s->fetchColumn();if($v===false)throw new RuntimeException('revision_not_found');return$this->json($v);}

    /** Return the single live revisioned settings document for one installation. */
    public function globalSettingsForInstallation(string $installationId):?array
    {
        $stmt=$this->db->prepare("SELECT c.configuration_id,c.current_revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind='global_settings' AND c.deleted_at IS NULL LIMIT 1");
        $stmt->execute(['installation'=>$installationId]);$row=$stmt->fetch();if(!$row)return null;$row['content']=$this->json($row['content']);return$row;
    }

    /** Return or create the single installation default used when an NPC has no explicit Core Profile. */
    public function defaultCoreProfileForInstallation(string $installationId, ?string $now = null, bool $create = false): ?array
    {
        $find = function () use ($installationId): ?array {
            $statement=$this->db->prepare('SELECT c.*,r.content,r.change_reason,r.created_at AS revision_created_at FROM core_profiles c JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.default_npc=true AND c.deleted_at IS NULL LIMIT 1');
            $statement->execute(['installation'=>$installationId]);$row=$statement->fetch();
            if(!$row)return null;$row['content']=$this->json($row['content']);$row['revision']=(int)$row['current_revision'];return$row;
        };
        $existing=$find();
        if($existing!==null||!$create)return$existing;
        if($now===null)throw new RuntimeException('core_profile_create_time_required');
        return$this->transaction(function()use($installationId,$now,$find):array{
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>'core-profile:'.$installationId]);
            $existing=$find();if($existing!==null)return$existing;
            $first=$this->db->prepare('SELECT core_profile_id FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY created_at,core_profile_id LIMIT 1');
            $first->execute(['installation'=>$installationId]);$firstId=$first->fetchColumn();
            if($firstId!==false){$this->db->prepare('UPDATE core_profiles SET default_npc=true WHERE core_profile_id=:id')->execute(['id'=>$firstId]);return$find()??throw new RuntimeException('core_profile_default_failed');}
            $id=$this->deterministicUuid('almsivi:core-profile:default:v1:'.$installationId);
            $content=['schema'=>'almsivi.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]];
            $this->db->prepare('INSERT INTO core_profiles(core_profile_id,installation_id,label,default_npc,slot,created_at) VALUES(:id,:installation,\'Default\',true,1,:now)')
                ->execute(['id'=>$id,'installation'=>$installationId,'now'=>$now]);
            $this->revision('core_profile_revisions','core_profile_id',$id,1,$content,'default core profile created',$now);
            return$find()??throw new RuntimeException('core_profile_default_failed');
        });
    }

    /** Update Core Profile identity fields separately from its immutable content revisions. */
    public function updateCoreProfileMetadata(string $coreProfileId,string $label,bool $defaultNpc,?int $slot,string $now):array
    {
        return$this->transaction(function()use($coreProfileId,$label,$defaultNpc,$slot,$now):array{
            $statement=$this->db->prepare('SELECT installation_id FROM core_profiles WHERE core_profile_id=:id AND deleted_at IS NULL FOR UPDATE');
            $statement->execute(['id'=>$coreProfileId]);$installation=$statement->fetchColumn();if($installation===false)throw new RuntimeException('not_found');
            if($defaultNpc)$this->db->prepare('UPDATE core_profiles SET default_npc=false WHERE installation_id=:installation AND core_profile_id<>:id AND default_npc=true')
                ->execute(['installation'=>$installation,'id'=>$coreProfileId]);
            $this->db->prepare('UPDATE core_profiles SET label=:label,default_npc=:default_npc,slot=:slot WHERE core_profile_id=:id')
                ->execute(['label'=>$label,'default_npc'=>$defaultNpc?'true':'false','slot'=>$slot,'id'=>$coreProfileId]);
            if(!$defaultNpc&&$this->defaultCoreProfileForInstallation((string)$installation)===null){
                throw new \InvalidArgumentException('default_core_profile_required');
            }
            return$this->getRevisioned('core_profile',$coreProfileId);
        });
    }

    /** Save Core Profile identity metadata and its next immutable content revision in one transaction. */
    public function reviseCoreProfile(string $coreProfileId,string $label,bool $defaultNpc,?int $slot,array $content,string $reason,string $now):array
    {
        return$this->transaction(function()use($coreProfileId,$label,$defaultNpc,$slot,$content,$reason,$now):array{
            $statement=$this->db->prepare('SELECT installation_id,current_revision,default_npc FROM core_profiles WHERE core_profile_id=:id AND deleted_at IS NULL FOR UPDATE');
            $statement->execute(['id'=>$coreProfileId]);$profile=$statement->fetch();if(!$profile)throw new RuntimeException('not_found');
            $installation=(string)$profile['installation_id'];
            if($slot!==null){
                $occupied=$this->db->prepare('SELECT 1 FROM core_profiles WHERE installation_id=:installation AND slot=:slot AND core_profile_id<>:id AND deleted_at IS NULL LIMIT 1');
                $occupied->execute(['installation'=>$installation,'slot'=>$slot,'id'=>$coreProfileId]);
                if($occupied->fetchColumn()!==false)throw new \InvalidArgumentException('core_profile_slot_in_use');
            }
            if($defaultNpc){
                $this->db->prepare('UPDATE core_profiles SET default_npc=false WHERE installation_id=:installation AND core_profile_id<>:id AND default_npc=true')
                    ->execute(['installation'=>$installation,'id'=>$coreProfileId]);
            }elseif(filter_var($profile['default_npc']??false,FILTER_VALIDATE_BOOL)){
                throw new \InvalidArgumentException('default_core_profile_required');
            }
            $this->db->prepare('UPDATE core_profiles SET label=:label,default_npc=:default_npc,slot=:slot WHERE core_profile_id=:id')
                ->execute(['label'=>$label,'default_npc'=>$defaultNpc?'true':'false','slot'=>$slot,'id'=>$coreProfileId]);
            $next=(int)$profile['current_revision']+1;
            $this->revision('core_profile_revisions','core_profile_id',$coreProfileId,$next,$content,$reason,$now);
            $this->db->prepare('UPDATE core_profiles SET current_revision=:revision WHERE core_profile_id=:id')->execute(['revision'=>$next,'id'=>$coreProfileId]);
            return$this->getRevisioned('core_profile',$coreProfileId);
        });
    }

    /** Assign one same-installation Core Profile to an NPC/persona profile. */
    public function assignCoreProfile(string $profileId,string $coreProfileId):void
    {
        $statement=$this->db->prepare('UPDATE profiles p SET core_profile_id=:core FROM core_profiles c WHERE p.profile_id=:profile AND p.installation_id=c.installation_id AND c.core_profile_id=:core AND p.deleted_at IS NULL AND c.deleted_at IS NULL');
        $statement->execute(['profile'=>$profileId,'core'=>$coreProfileId]);if($statement->rowCount()!==1)throw new \InvalidArgumentException('core_profile_scope_mismatch');
    }

    /** Materialize the installation-scoped player profile before the first game turn needs it. */
    public function ensurePlayerProfile(string $installationId,string $now):array
    {
        return$this->transaction(function()use($installationId,$now):array{$existing=$this->playerProfileForInstallation($installationId);if($existing!==null)return$existing;
            $id=Uuid::v4();$this->db->prepare('INSERT INTO profiles (profile_id,installation_id,name,actor_identity,created_at) VALUES (:id,:installation,:name,CAST(:identity AS jsonb),:now)')->execute([
                'id'=>$id,'installation'=>$installationId,'name'=>'Player','identity'=>$this->encode(['kind'=>'player','display_name'=>'Player']),'now'=>$now]);
            $this->revision('profile_revisions','profile_id',$id,1,['biography'=>'','appearance'=>'','personality'=>'','speech_style'=>'','goals'=>'','notes'=>''],'created automatically on session start',$now);
            return$this->getRevisioned('profile',$id);});
    }

    /** Return every live profile/connector reference grouped by normalized local TTS voice ID. */
    public function voiceReferenceIndex():array
    {
        $sql="SELECT voice,source,label FROM ("
            ."SELECT CASE WHEN jsonb_typeof(r.content->'voice')='string' THEN r.content->>'voice' ELSE COALESCE(r.content#>>'{voice,id}',r.content#>>'{voice,voice_id}') END AS voice,'Profile' AS source,p.name AS label "
            ."FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL "
            ."UNION ALL SELECT r.content->>'voice' AS voice,'Connector' AS source,c.name AS label FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.deleted_at IS NULL AND c.kind='tts_provider') voice_refs WHERE btrim(COALESCE(voice,''))<>'' ORDER BY source,label";
        $rows=$this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);$result=[];
        foreach($rows as$row){$key=mb_strtolower(trim((string)$row['voice']),'UTF-8');if($key==='')continue;$result[$key][]=(string)$row['source'].': '.(string)$row['label'];}
        foreach($result as$key=>$labels)$result[$key]=array_values(array_unique($labels));
        return$result;
    }

    /** Replace one connector's explicitly refreshed provider-voice catalog atomically. */
    public function replaceConnectorVoiceCatalog(string $configurationId,array $voices,string $now):int
    {
        if(count($voices)>512)throw new RuntimeException('voice_catalog_too_large');
        return$this->transaction(function()use($configurationId,$voices,$now):int{
            $scope=$this->db->prepare("SELECT 1 FROM configuration_sets WHERE configuration_id=:configuration AND kind='tts_provider' AND deleted_at IS NULL FOR UPDATE");
            $scope->execute(['configuration'=>$configurationId]);if(!$scope->fetchColumn())throw new RuntimeException('not_found');
            $this->db->prepare('DELETE FROM speech_connector_voices WHERE configuration_id=:configuration')->execute(['configuration'=>$configurationId]);
            $insert=$this->db->prepare('INSERT INTO speech_connector_voices (configuration_id,voice_id,display_name,language,provider_status,custom_voice,discovered_at) VALUES (:configuration,:voice,:display,:language,:status,:custom,:discovered)');
            $seen=[];$count=0;
            foreach($voices as$voice){
                if(!is_array($voice))throw new RuntimeException('invalid_voice_catalog');
                $id=trim((string)($voice['id']??''));$display=trim((string)($voice['display']??''));$language=trim((string)($voice['language']??''));$status=trim((string)($voice['status']??''));
                if($id===''||isset($seen[$id])||strlen($id)>512||$display===''||strlen($display)>512||strlen($language)<2||strlen($language)>35||$status===''||strlen($status)>64)
                    throw new RuntimeException('invalid_voice_catalog');
                $seen[$id]=true;$insert->execute(['configuration'=>$configurationId,'voice'=>$id,'display'=>$display,'language'=>$language,'status'=>$status,'custom'=>!empty($voice['custom'])?'true':'false','discovered'=>$now]);$count++;
            }
            return$count;
        });
    }

    /** Return the bounded durable voice catalog populated only by explicit provider discovery. */
    public function connectorVoiceCatalog(?string $configurationId=null):array
    {
        $where=$configurationId===null?'': ' AND v.configuration_id=:configuration';
        $statement=$this->db->prepare("SELECT v.configuration_id,v.voice_id AS id,v.display_name AS display,v.language,v.provider_status AS status,v.custom_voice AS custom,v.discovered_at,c.installation_id,c.name AS connector_name FROM speech_connector_voices v JOIN configuration_sets c ON c.configuration_id=v.configuration_id WHERE c.kind='tts_provider' AND c.deleted_at IS NULL{$where} ORDER BY c.name,v.display_name,v.voice_id LIMIT 1024");
        $statement->execute($configurationId===null?[]:['configuration'=>$configurationId]);$rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as&$row)$row['custom']=filter_var($row['custom']??false,FILTER_VALIDATE_BOOL);unset($row);
        return$rows;
    }

    public function revise(string $kind, string $id, array $content, string $reason, string $now): array
    {
        return $this->transaction(function () use ($kind,$id,$content,$reason,$now): array {
            [$table,$key,$revisions] = $this->revisionMeta($kind);
            $stmt = $this->db->prepare("SELECT current_revision FROM {$table} WHERE {$key}=:id AND deleted_at IS NULL FOR UPDATE");
            $stmt->execute(['id'=>$id]);
            $current = $stmt->fetchColumn();
            if ($current === false) throw new RuntimeException('not_found');
            $next = (int)$current + 1;
            $this->revision($revisions, $key, $id, $next, $content, $reason, $now);
            $this->db->prepare("UPDATE {$table} SET current_revision=:revision WHERE {$key}=:id")->execute(['revision'=>$next,'id'=>$id]);
            if($kind==='prompt')$this->syncPrompt($id,$content,$next,$now);
            return $this->getRevisioned($kind,$id);
        });
    }

    public function rollback(string $kind, string $id, int $revision, string $reason, string $now): array
    {
        [$table,$key,$revisions] = $this->revisionMeta($kind);
        $stmt = $this->db->prepare("SELECT content FROM {$revisions} WHERE {$key}=:id AND revision=:revision");
        $stmt->execute(['id'=>$id,'revision'=>$revision]);
        $content = $stmt->fetchColumn();
        if ($content === false) throw new RuntimeException('revision_not_found');
        return $this->revise($kind,$id,$this->json($content),'rollback:'.$revision.' '.$reason,$now);
    }

    public function getRevisioned(string $kind, string $id): array
    {
        [$table,$key,$revisions] = $this->revisionMeta($kind);
        $nameAlias=$kind==='core_profile'?', b.label AS name':'';
        $stmt = $this->db->prepare("SELECT b.*{$nameAlias}, r.content, r.change_reason, r.created_at AS revision_created_at FROM {$table} b JOIN {$revisions} r ON r.{$key}=b.{$key} AND r.revision=b.current_revision WHERE b.{$key}=:id AND b.deleted_at IS NULL");
        $stmt->execute(['id'=>$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('not_found');
        $row['content']=$this->json($row['content']);
        return $row;
    }

    public function listRevisioned(string $kind, string $installationId): array
    {
        [$table,$key,$revisions] = $this->revisionMeta($kind);
        $kindFilter=$table==='configuration_sets'?' AND b.kind=:kind':'';
        $nameField=$kind==='core_profile'?'label':'name';
        $stmt=$this->db->prepare("SELECT b.{$key} AS id,b.{$nameField} AS name,b.current_revision,b.created_at,r.content FROM {$table} b JOIN {$revisions} r ON r.{$key}=b.{$key} AND r.revision=b.current_revision WHERE b.installation_id=:installation{$kindFilter} AND b.deleted_at IS NULL ORDER BY b.{$nameField} LIMIT 100");
        $parameters=['installation'=>$installationId];if($kindFilter!=='')$parameters['kind']=$kind;
        $stmt->execute($parameters);
        return array_map(fn(array $r):array=>$r+['content'=>$this->json($r['content'])],$stmt->fetchAll());
    }

    /** Queue one idempotent generation job for the profile's current revision. */
    public function enqueueProfileGeneration(string $profileId):array
    {
        return$this->transaction(function()use($profileId):array{
            $select=$this->db->prepare('SELECT p.current_revision,p.actor_identity,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:id AND p.deleted_at IS NULL FOR UPDATE OF p');
            $select->execute(['id'=>$profileId]);$row=$select->fetch();if(!$row)throw new RuntimeException('not_found');
            $identity=$this->json($row['actor_identity']);if(in_array($identity['kind']??'actor',['player','narrator'],true))throw new RuntimeException('profile_not_generatable');
            $content=$this->json($row['content']);$management=is_array($content['management']??null)?$content['management']:[];
            if(($management['locked']??false)===true)throw new \InvalidArgumentException('profile_locked');
            $revision=(int)$row['current_revision'];$key='profile:'.$profileId.':revision:'.$revision;$jobId=Uuid::v4();
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode(['profile_id'=>$profileId,'base_revision'=>$revision])]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");$existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');return$job+['profile_id'=>$profileId,'base_revision'=>$revision];
        });
    }

    /** Queue at most 100 unlocked NPC profiles from one installation for revision-safe generation. */
    public function bulkEnqueueNpcProfileGeneration(string $installationId):array
    {
        $select=$this->db->prepare("SELECT p.profile_id,count(*) OVER() AS eligible FROM profiles p "
            ."JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
            ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL "
            ."AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') "
            ."AND COALESCE(r.content->'management'->>'locked','false')<>'true' "
            ."ORDER BY p.created_at,p.profile_id LIMIT 100");
        $select->execute(['installation'=>$installationId]);$rows=$select->fetchAll();$queued=0;
        foreach($rows as$row){
            try{$this->enqueueProfileGeneration((string)$row['profile_id']);$queued++;}
            catch(\InvalidArgumentException $error){if($error->getMessage()!=='profile_locked')throw$error;}
        }
        $eligible=$rows===[]?0:(int)$rows[0]['eligible'];
        return['queued'=>$queued,'eligible'=>$eligible,'truncated'=>max(0,$eligible-count($rows))];
    }

    /** Queue a revision-safe AI regeneration only for the installation narrator profile. */
    public function enqueueNarratorProfileGeneration(string $profileId):array
    {
        return$this->transaction(function()use($profileId):array{
            $select=$this->db->prepare('SELECT p.current_revision,p.actor_identity,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:id AND p.deleted_at IS NULL FOR UPDATE OF p');
            $select->execute(['id'=>$profileId]);$row=$select->fetch();if(!$row)throw new RuntimeException('not_found');
            $identity=$this->json($row['actor_identity']);if(($identity['kind']??null)!=='narrator')throw new \InvalidArgumentException('profile_not_narrator');
            $content=$this->json($row['content']);$management=is_array($content['management']??null)?$content['management']:[];
            if(($management['locked']??false)===true)throw new \InvalidArgumentException('profile_locked');
            $revision=(int)$row['current_revision'];$key='narrator-profile:'.$profileId.':revision:'.$revision;$jobId=Uuid::v4();
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode(['profile_id'=>$profileId,'base_revision'=>$revision,'mode'=>'narrator_profile'])]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");$existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');return$job+['profile_id'=>$profileId,'base_revision'=>$revision,'mode'=>'narrator_profile'];
        });
    }

    /** Queue a revision-safe player speech-style analysis only when real player inputs exist. */
    public function enqueuePlayerSpeechStyleGeneration(string $profileId):array
    {
        return$this->transaction(function()use($profileId):array{
            $select=$this->db->prepare('SELECT p.installation_id,p.current_revision,p.actor_identity FROM profiles p WHERE p.profile_id=:id AND p.deleted_at IS NULL FOR UPDATE');
            $select->execute(['id'=>$profileId]);$row=$select->fetch();if(!$row)throw new RuntimeException('not_found');
            $identity=$this->json($row['actor_identity']);if(($identity['kind']??null)!=='player')throw new \InvalidArgumentException('profile_not_player');
            if($this->recentPlayerInputs((string)$row['installation_id'],1)===[])throw new \InvalidArgumentException('player_inputs_unavailable');
            $revision=(int)$row['current_revision'];$key='player-speech-style:'.$profileId.':revision:'.$revision;$jobId=Uuid::v4();
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode(['profile_id'=>$profileId,'base_revision'=>$revision,'mode'=>'player_speech_style'])]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");$existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');return$job+['profile_id'=>$profileId,'base_revision'=>$revision,'mode'=>'player_speech_style'];
        });
    }

    /** Queue generation only for the profile currently bound to this active session target. */
    public function enqueueBoundProfileGeneration(array $session,array $target,string $profileId):array
    {
        $selected=$this->selectedActorProfileId((string)$session['installation_id'],
            (string)$session['playthrough_id'],$target);
        if($selected===null||!hash_equals($selected,$profileId))throw new \OutOfBoundsException('profile_not_bound');
        return$this->enqueueProfileGeneration($profileId);
    }

    /** Commit generated fields only while the queued base revision is still current. */
    public function reviseGeneratedProfileIfCurrent(string $profileId,int $baseRevision,array $content,string $reason,string $now):bool
    {
        return$this->transaction(function()use($profileId,$baseRevision,$content,$reason,$now):bool{
            $select=$this->db->prepare('SELECT current_revision FROM profiles WHERE profile_id=:id AND deleted_at IS NULL FOR UPDATE');
            $select->execute(['id'=>$profileId]);$current=$select->fetchColumn();if($current===false||(int)$current!==$baseRevision)return false;
            $next=$baseRevision+1;$this->revision('profile_revisions','profile_id',$profileId,$next,$content,$reason,$now);
            $this->db->prepare('UPDATE profiles SET current_revision=:revision WHERE profile_id=:id')->execute(['revision'=>$next,'id'=>$profileId]);return true;
        });
    }

    /** Create unlocked revisions for every locked NPC profile in one installation. */
    public function bulkUnlockNpcProfiles(string $installationId,string $now):int
    {
        return$this->transaction(function()use($installationId,$now):int{
            $select=$this->db->prepare("SELECT p.profile_id,p.current_revision,r.content FROM profiles p "
                ."JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
                ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL "
                ."AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') "
                ."AND r.content->'management'->>'locked'='true' FOR UPDATE OF p");
            $select->execute(['installation'=>$installationId]);$rows=$select->fetchAll();
            foreach($rows as$row){$content=$this->json($row['content']);
                $management=is_array($content['management']??null)?$content['management']:[];$management['locked']=false;
                $content['management']=$management;$current=(int)$row['current_revision'];$next=$current+1;
                $this->revision('profile_revisions','profile_id',(string)$row['profile_id'],$next,$content,'bulk unlock',$now);
                $this->db->prepare('UPDATE profiles SET current_revision=:revision WHERE profile_id=:id')->execute(['revision'=>$next,'id'=>$row['profile_id']]);}
            return count($rows);
        });
    }

    /** Read the CHIM-compatible auto-lock preference, defaulting on until explicitly disabled. */
    public function profileAutoLockEnabled(string $installationId):bool
    {
        $statement=$this->db->prepare("SELECT CASE WHEN auto_lock_on_edit THEN '1' ELSE '0' END FROM installation_profile_preferences WHERE installation_id=:installation");
        $statement->execute(['installation'=>$installationId]);$value=$statement->fetchColumn();
        return$value===false||$value==='1';
    }

    public function setProfileAutoLock(string $installationId,bool $enabled,string $now):void
    {
        $statement=$this->db->prepare('INSERT INTO installation_profile_preferences(installation_id,auto_lock_on_edit,updated_at) '
            .'VALUES(:installation,:enabled,:now) ON CONFLICT(installation_id) DO UPDATE SET auto_lock_on_edit=EXCLUDED.auto_lock_on_edit,updated_at=EXCLUDED.updated_at');
        $statement->execute(['installation'=>$installationId,'enabled'=>$enabled?'true':'false','now'=>$now]);
    }

    /** Return installation-global Oghma retrieval controls with stable first-run defaults. */
    public function oghmaSettings(string $installationId):array
    {
        $statement=$this->db->prepare('SELECT enabled,knowledge_tags,racial_context_enabled,location_context_enabled,topic_count,result_limit,extractor_enabled,extractor_timeout_ms FROM oghma_installation_settings WHERE installation_id=:installation');
        $statement->execute(['installation'=>$installationId]);$row=$statement->fetch();
        if(!$row)return['enabled'=>true,'knowledge_tags'=>'','racial_context_enabled'=>true,'location_context_enabled'=>true,'topic_count'=>1,'result_limit'=>3,'extractor_enabled'=>false,'extractor_timeout_ms'=>1500];
        return['enabled'=>filter_var($row['enabled'],FILTER_VALIDATE_BOOL),'knowledge_tags'=>(string)$row['knowledge_tags'],
            'racial_context_enabled'=>filter_var($row['racial_context_enabled'],FILTER_VALIDATE_BOOL),
            'location_context_enabled'=>filter_var($row['location_context_enabled'],FILTER_VALIDATE_BOOL),
            'topic_count'=>(int)$row['topic_count'],'result_limit'=>(int)$row['result_limit'],
            'extractor_enabled'=>filter_var($row['extractor_enabled'],FILTER_VALIDATE_BOOL),'extractor_timeout_ms'=>(int)$row['extractor_timeout_ms']];
    }

    public function oghmaKnowledgeTags(string $installationId):string{return(string)$this->oghmaSettings($installationId)['knowledge_tags'];}

    /** Persist all installation-global Oghma controls in one validated write. */
    public function setOghmaSettings(string $installationId,array $settings,string $now):void
    {
        $tags=$this->npcKnowledgeTags($settings['knowledge_tags']??'');$topicCount=filter_var($settings['topic_count']??1,FILTER_VALIDATE_INT);
        $resultLimit=filter_var($settings['result_limit']??3,FILTER_VALIDATE_INT);$timeout=filter_var($settings['extractor_timeout_ms']??1500,FILTER_VALIDATE_INT);
        if(strlen($tags)>4096||!mb_check_encoding($tags,'UTF-8'))throw new \InvalidArgumentException('invalid_oghma_knowledge_tags');
        if($topicCount===false||$topicCount<1||$topicCount>3)throw new \InvalidArgumentException('invalid_oghma_topic_count');
        if($resultLimit===false||$resultLimit<1||$resultLimit>5)throw new \InvalidArgumentException('invalid_oghma_result_limit');
        if($timeout===false||$timeout<250||$timeout>3000)throw new \InvalidArgumentException('invalid_oghma_extractor_timeout');
        $statement=$this->db->prepare('INSERT INTO oghma_installation_settings '
            .'(installation_id,enabled,knowledge_tags,racial_context_enabled,location_context_enabled,topic_count,result_limit,extractor_enabled,extractor_timeout_ms,updated_at) '
            .'VALUES(:installation,:enabled,:tags,:racial,:location,:count,:result_limit,:extractor,:timeout,:now) ON CONFLICT(installation_id) DO UPDATE SET '
            .'enabled=EXCLUDED.enabled,knowledge_tags=EXCLUDED.knowledge_tags,racial_context_enabled=EXCLUDED.racial_context_enabled,'
            .'location_context_enabled=EXCLUDED.location_context_enabled,topic_count=EXCLUDED.topic_count,result_limit=EXCLUDED.result_limit,'
            .'extractor_enabled=EXCLUDED.extractor_enabled,extractor_timeout_ms=EXCLUDED.extractor_timeout_ms,updated_at=EXCLUDED.updated_at');
        $statement->execute(['installation'=>$installationId,'enabled'=>($settings['enabled']??true)?'true':'false','tags'=>$tags,
            'racial'=>($settings['racial_context_enabled']??false)?'true':'false',
            'location'=>($settings['location_context_enabled']??false)?'true':'false',
            'count'=>$topicCount,'result_limit'=>$resultLimit,'extractor'=>($settings['extractor_enabled']??false)?'true':'false','timeout'=>$timeout,'now'=>$now]);
    }

    public function setOghmaKnowledgeTags(string $installationId,string $tags,string $now):void
    {
        $settings=$this->oghmaSettings($installationId);$settings['knowledge_tags']=$tags;$this->setOghmaSettings($installationId,$settings,$now);
    }

    /** Soft-delete only unlocked NPC profiles in one installation and clear their actor bindings. */
    public function bulkDeleteUnlockedNpcProfiles(string $installationId,string $now):int
    {
        return$this->transaction(function()use($installationId,$now):int{
            $select=$this->db->prepare("SELECT p.profile_id FROM profiles p JOIN profile_revisions r "
                ."ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
                ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL "
                ."AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') "
                ."AND COALESCE(r.content->'management'->>'locked','false')<>'true' FOR UPDATE OF p");
            $select->execute(['installation'=>$installationId]);$ids=array_column($select->fetchAll(),'profile_id');
            $deleteBindings=$this->db->prepare('DELETE FROM actor_profile_bindings WHERE installation_id=:installation AND profile_id=:id');
            $deleteProfile=$this->db->prepare('UPDATE profiles SET deleted_at=:now WHERE installation_id=:installation AND profile_id=:id AND deleted_at IS NULL');
            foreach($ids as$id){$parameters=['installation'=>$installationId,'id'=>$id];$deleteBindings->execute($parameters);
                $deleteProfile->execute($parameters+['now'=>$now]);}
            return count($ids);
        });
    }

    /** Move actor bindings between two same-installation NPC profiles, respecting the source lock by default. */
    public function bulkSwitchNpcProfileBindings(string $installationId,string $sourceProfileId,string $targetProfileId,bool $includeLocked,string $now):array
    {
        if(hash_equals($sourceProfileId,$targetProfileId))throw new \InvalidArgumentException('profiles_must_differ');
        return$this->transaction(function()use($installationId,$sourceProfileId,$targetProfileId,$includeLocked,$now):array{
            $select=$this->db->prepare("SELECT p.profile_id,p.actor_identity,r.content FROM profiles p JOIN profile_revisions r "
                ."ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation "
                ."AND p.profile_id IN(:source,:target) AND p.deleted_at IS NULL FOR UPDATE OF p");
            $select->execute(['installation'=>$installationId,'source'=>$sourceProfileId,'target'=>$targetProfileId]);$profiles=[];
            foreach($select->fetchAll()as$row)$profiles[(string)$row['profile_id']]=$row;
            if(!isset($profiles[$sourceProfileId],$profiles[$targetProfileId]))throw new \InvalidArgumentException('profile_installation_mismatch');
            foreach([$profiles[$sourceProfileId],$profiles[$targetProfileId]]as$row){$identity=$this->json($row['actor_identity']);
                if(in_array($identity['kind']??'actor',['player','narrator'],true))throw new \InvalidArgumentException('profile_not_switchable');}
            $count=$this->db->prepare('SELECT count(*) FROM actor_profile_bindings WHERE installation_id=:installation AND profile_id=:source');
            $count->execute(['installation'=>$installationId,'source'=>$sourceProfileId]);$matched=(int)$count->fetchColumn();
            $sourceContent=$this->json($profiles[$sourceProfileId]['content']);$management=is_array($sourceContent['management']??null)?$sourceContent['management']:[];
            if(($management['locked']??false)===true&&!$includeLocked)return['updated'=>0,'skipped_locked'=>$matched];
            $update=$this->db->prepare('UPDATE actor_profile_bindings SET profile_id=:target,updated_at=:now WHERE installation_id=:installation AND profile_id=:source');
            $update->execute(['target'=>$targetProfileId,'now'=>$now,'installation'=>$installationId,'source'=>$sourceProfileId]);
            return['updated'=>$update->rowCount(),'skipped_locked'=>0];
        });
    }

    /** Soft-delete a revisioned management resource and clear runtime selections that could still reference it. */
    public function deleteRevisioned(string $kind,string $id,string $now):void
    {
        $this->transaction(function()use($kind,$id,$now):void{
            [$table,$key]=$this->revisionMeta($kind);
            if($kind==='profile')$this->db->prepare('DELETE FROM actor_profile_bindings WHERE profile_id=:id')->execute(['id'=>$id]);
            if($kind==='core_profile'){
                $usage=$this->db->prepare('SELECT c.default_npc,(SELECT count(*) FROM profiles p WHERE p.core_profile_id=c.core_profile_id AND p.deleted_at IS NULL) AS profiles FROM core_profiles c WHERE c.core_profile_id=:id AND c.deleted_at IS NULL FOR UPDATE');
                $usage->execute(['id'=>$id]);$row=$usage->fetch();if(!$row)throw new RuntimeException('not_found');
                if(filter_var($row['default_npc'],FILTER_VALIDATE_BOOL)||(int)$row['profiles']>0)throw new \InvalidArgumentException('core_profile_in_use');
            }
            if($kind==='provider'){
                $session=$this->db->prepare("SELECT 1 FROM sessions WHERE provider_configuration_id=:id AND state='active' LIMIT 1");
                $session->execute(['id'=>$id]);if($session->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
                $profile=$this->db->prepare("SELECT 1 FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND (r.content->'routing'->>'llm_configuration_id'=:id OR r.content->'routing'->>'llm_fast_configuration_id'=:id OR r.content->'routing'->>'llm_powerful_configuration_id'=:id OR r.content->'routing'->>'llm_experimental_configuration_id'=:id OR r.content->'routing'->>'llm_fallback_configuration_id'=:id OR r.content->'routing'->>'oghma_configuration_id'=:id) LIMIT 1");
                $profile->execute(['id'=>$id]);if($profile->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
                $core=$this->db->prepare("SELECT 1 FROM core_profile_revisions r JOIN core_profiles c ON c.core_profile_id=r.core_profile_id AND c.current_revision=r.revision WHERE c.deleted_at IS NULL AND (r.content->'routing'->>'llm_configuration_id'=:id OR r.content->'routing'->>'llm_fast_configuration_id'=:id OR r.content->'routing'->>'llm_powerful_configuration_id'=:id OR r.content->'routing'->>'llm_experimental_configuration_id'=:id OR r.content->'routing'->>'llm_fallback_configuration_id'=:id OR r.content->'routing'->>'oghma_configuration_id'=:id) LIMIT 1");
                $core->execute(['id'=>$id]);if($core->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
            }
            if($kind==='prompt'){
                $profile=$this->db->prepare("SELECT 1 FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND r.content->'routing'->>'prompt_configuration_id'=:id LIMIT 1");
                $profile->execute(['id'=>$id]);if($profile->fetchColumn())throw new \InvalidArgumentException('prompt_in_use');
                $core=$this->db->prepare("SELECT 1 FROM core_profile_revisions r JOIN core_profiles c ON c.core_profile_id=r.core_profile_id AND c.current_revision=r.revision WHERE c.deleted_at IS NULL AND r.content->'routing'->>'prompt_configuration_id'=:id LIMIT 1");
                $core->execute(['id'=>$id]);if($core->fetchColumn())throw new \InvalidArgumentException('prompt_in_use');
            }
            if(in_array($kind,['tts_provider','stt_provider'],true)){
                $selection=$this->db->prepare('SELECT 1 FROM installation_provider_selections WHERE configuration_id=:id LIMIT 1');
                $selection->execute(['id'=>$id]);if($selection->fetchColumn())throw new \InvalidArgumentException('connector_in_use');
                if($kind==='tts_provider'){$profile=$this->db->prepare("SELECT 1 FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND r.content->'routing'->>'tts_configuration_id'=:id LIMIT 1");
                    $profile->execute(['id'=>$id]);if($profile->fetchColumn())throw new \InvalidArgumentException('connector_in_use');
                    $core=$this->db->prepare("SELECT 1 FROM core_profile_revisions r JOIN core_profiles c ON c.core_profile_id=r.core_profile_id AND c.current_revision=r.revision WHERE c.deleted_at IS NULL AND r.content->'routing'->>'tts_configuration_id'=:id LIMIT 1");
                    $core->execute(['id'=>$id]);if($core->fetchColumn())throw new \InvalidArgumentException('connector_in_use');}
            }
            $statement=$this->db->prepare("UPDATE {$table} SET deleted_at=:now WHERE {$key}=:id AND deleted_at IS NULL");
            $statement->execute(['now'=>$now,'id'=>$id]);if($statement->rowCount()!==1)throw new RuntimeException('not_found');
        });
    }

    /** Select one installation-owned speech preset and return its redacted public snapshot. */
    public function selectConnector(string $installationId,string $kind,string $configurationId,string $now):array
    {
        return $this->transaction(function()use($installationId,$kind,$configurationId,$now):array{
            $find=$this->db->prepare('SELECT c.configuration_id,c.name,c.current_revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.configuration_id=:configuration AND c.installation_id=:installation AND c.kind=:kind AND c.deleted_at IS NULL');
            $find->execute(['configuration'=>$configurationId,'installation'=>$installationId,'kind'=>$kind]);
            $row=$find->fetch();if(!$row)throw new RuntimeException('not_found');
            $this->db->prepare('INSERT INTO installation_provider_selections (installation_id,provider_kind,configuration_id,updated_at) VALUES (:installation,:kind,:configuration,:now) ON CONFLICT (installation_id,provider_kind) DO UPDATE SET configuration_id=EXCLUDED.configuration_id,updated_at=EXCLUDED.updated_at')
                ->execute(['installation'=>$installationId,'kind'=>$kind,'configuration'=>$configurationId,'now'=>$now]);
            $row['content']=$this->json($row['content']);$row['kind']=$kind;$row['updated_at']=$now;return$row;
        });
    }

    public function connectorSelections(string $installationId):array
    {
        $statement=$this->db->prepare('SELECT s.provider_kind AS kind,s.configuration_id,c.name,c.current_revision,r.content,s.updated_at FROM installation_provider_selections s JOIN configuration_sets c ON c.configuration_id=s.configuration_id AND c.installation_id=s.installation_id JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE s.installation_id=:installation AND c.deleted_at IS NULL ORDER BY s.provider_kind');
        $statement->execute(['installation'=>$installationId]);
        return array_map(function(array$row):array{$row['content']=$this->json($row['content']);return$row;},$statement->fetchAll());
    }

    public function connectorForInstallation(string $installationId,string $kind):?array
    {
        $statement=$this->db->prepare('SELECT c.configuration_id,c.current_revision AS revision,r.content FROM installation_provider_selections s JOIN configuration_sets c ON c.configuration_id=s.configuration_id AND c.installation_id=s.installation_id AND c.kind=s.provider_kind JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE s.installation_id=:installation AND s.provider_kind=:kind AND c.deleted_at IS NULL');
        $statement->execute(['installation'=>$installationId,'kind'=>$kind]);$row=$statement->fetch();
        if(!$row)return null;$row['revision']=(int)$row['revision'];$row['content']=$this->json($row['content']);return$row;
    }

    /** Resolve an actor profile's CHIM-style connector routing while enforcing installation ownership. */
    public function connectorForActor(string $installationId,string $playthroughId,array $identity,string $kind,?string $routingField=null):?array
    {
        if(!in_array($kind,['provider','tts_provider'],true))throw new RuntimeException('invalid_connector_kind');
        $routing=$this->routingForActor($installationId,$playthroughId,$identity);
        $allowed=$kind==='provider'?['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id',
            'llm_experimental_configuration_id','llm_fallback_configuration_id','oghma_configuration_id']:['tts_configuration_id'];
        $field=$routingField??$allowed[0];if(!in_array($field,$allowed,true))throw new RuntimeException('invalid_connector_route');
        $configurationId=trim((string)($routing[$field]??''));
        if($configurationId==='')return null;
        $statement=$this->db->prepare('SELECT c.configuration_id,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.configuration_id=:configuration AND c.installation_id=:installation AND c.kind=:kind AND c.deleted_at IS NULL');
        $statement->execute(['configuration'=>$configurationId,'installation'=>$installationId,'kind'=>$kind]);$row=$statement->fetch();
        if(!$row)return null;$row['revision']=(int)$row['revision'];$row['content']=$this->json($row['content']);return$row;
    }

    /** Resolve the revisioned Global -> Core Profile -> NPC layers for one stable actor identity. */
    public function effectiveSettingsForActor(string $installationId,string $playthroughId,array $identity):array
    {
        $profileId=($identity['kind']??null)==='narrator'
            ?($this->narratorProfileForInstallation($installationId)['profile_id']??null)
            :$this->selectedActorProfileId($installationId,$playthroughId,$identity);
        return$this->effectiveSettingsForProfile($installationId,is_string($profileId)?$profileId:null);
    }

    /** Resolve a profile's assigned Core Profile while retaining source revisions for prompt traces. */
    public function effectiveSettingsForProfile(string $installationId,?string $profileId):array
    {
        $global=$this->globalSettingsForInstallation($installationId);
        $profile=null;
        if($profileId!==null&&$profileId!==''){
            $statement=$this->db->prepare('SELECT p.profile_id,p.core_profile_id,p.current_revision AS revision,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:profile AND p.installation_id=:installation AND p.deleted_at IS NULL');
            $statement->execute(['profile'=>$profileId,'installation'=>$installationId]);$profile=$statement->fetch();
            if($profile){$profile['revision']=(int)$profile['revision'];$profile['content']=$this->json($profile['content']);}
        }
        $core=null;$coreId=is_array($profile)?($profile['core_profile_id']??null):null;
        if(is_string($coreId)&&$coreId!==''){
            $statement=$this->db->prepare('SELECT c.core_profile_id,c.installation_id,c.label,c.default_npc,c.slot,c.current_revision AS revision,r.content FROM core_profiles c JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision WHERE c.core_profile_id=:core AND c.installation_id=:installation AND c.deleted_at IS NULL');
            $statement->execute(['core'=>$coreId,'installation'=>$installationId]);$core=$statement->fetch();
            if($core){$core['revision']=(int)$core['revision'];$core['content']=$this->json($core['content']);}
        }
        if(!$core)$core=$this->defaultCoreProfileForInstallation($installationId);
        $installationOghma=$this->oghmaSettings($installationId);
        $resolved=(new EffectiveSettingsResolver())->resolve(
            is_array($global['content']??null)?$global['content']:[],
            is_array($core['content']??null)?$core['content']:[],
            is_array($profile['content']??null)?$profile['content']:[],
            [
                'enabled'=>$installationOghma['enabled'],
                'topic_count'=>$installationOghma['topic_count'],
                'result_limit'=>$installationOghma['result_limit'],
                'racial_context_enabled'=>$installationOghma['racial_context_enabled'],
                'location_context_enabled'=>$installationOghma['location_context_enabled'],
                'extractor_fallback_enabled'=>$installationOghma['extractor_enabled'],
                'extractor_timeout_ms'=>$installationOghma['extractor_timeout_ms'],
            ],
        );
        $globalTags=(string)$installationOghma['knowledge_tags'];
        if(($resolved['sources']['settings.memory.oghma_knowledge_tags']??'')==='server_default'){
            $resolved['settings']['memory']['oghma_knowledge_tags']=$globalTags;
            $resolved['document']['settings']['memory']['oghma_knowledge_tags']=$globalTags;
            $resolved['sources']['settings.memory.oghma_knowledge_tags']='global';
            $resolved['sha256']=hash('sha256',$this->encodeCanonical($resolved['document']));
        }
        return$resolved+['global_settings'=>$global,'core_profile'=>$core,'npc_profile'=>$profile];
    }

    /** Return only the effective routing document for one stable actor identity. */
    private function routingForActor(string $installationId,string $playthroughId,array $identity):array
    {
        return$this->effectiveSettingsForActor($installationId,$playthroughId,$identity)['routing'];
    }

    /** Read a boolean profile-routing flag without treating arbitrary non-empty strings as enabled. */
    private function routingFlag(array $routing,string $field):bool
    {
        $value=$routing[$field]??false;if(is_bool($value))return$value;
        return is_int($value)?$value===1:in_array(strtolower(trim((string)$value)),['1','true','yes','on'],true);
    }

    public function connectorForSession(string $sessionId,string $kind):?array
    {
        $statement=$this->db->prepare('SELECT installation_id FROM sessions WHERE session_id=:session');$statement->execute(['session'=>$sessionId]);
        $installation=$statement->fetchColumn();return$installation===false?null:$this->connectorForInstallation((string)$installation,$kind);
    }

    /** Return the immutable server-owned action names accepted by labelled policy controls. */
    public function actionCatalogNames():array
    {
        $statement=$this->db->query('SELECT action_name FROM action_catalog WHERE enabled=true ORDER BY tier,action_name');
        return array_map(static fn(array$row):string=>(string)$row['action_name'],$statement->fetchAll());
    }

    /** Return a newest-first bounded sample of typed or transcribed player turns for style analysis. */
    public function recentPlayerInputs(string $installationId,int $limit=200):array
    {
        $limit=max(1,min(200,$limit));$statement=$this->db->prepare("SELECT t.input_text FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=:installation AND t.speaker->>'kind'='player' AND btrim(t.input_text)<>'' ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT :limit");
        $statement->bindValue(':installation',$installationId);$statement->bindValue(':limit',$limit,\PDO::PARAM_INT);$statement->execute();
        return array_map(static fn(array$row):string=>(string)$row['input_text'],$statement->fetchAll());
    }

    /** Resolve an actor profile's voice without exposing the rest of its roleplay document to a connector. */
    public function speechContext(string $installationId,string $playthroughId,array $identity,?array $connector=null):array
    {
        $profileId=($identity['kind']??null)==='narrator'
            ?($this->narratorProfileForInstallation($installationId)['profile_id']??null)
            :$this->selectedActorProfileId($installationId,$playthroughId,$identity);
        if(!is_string($profileId)||$profileId==='')return[];
        $profile=$this->getRevisioned('profile',$profileId);$content=$profile['content']??[];$voice=$content['voice']??null;
        if($voice===null)$voice=[];elseif(is_string($voice))$voice=['id'=>$voice];
        if(!is_array($voice)||($voice!==[]&&array_is_list($voice)))return[];
        $result=[];$id=trim((string)($voice['id']??$voice['voice_id']??''));$language=trim((string)($voice['language']??''));
        if($id!==''&&str_starts_with((string)($voice['source']??''),'morrowind_')){
            $configurationId=trim((string)($connector['configuration_id']??''));
            if($configurationId===''||!$this->connectorHasVoice($configurationId,$id))$id='';
        }
        if($id===''){$gender=strtolower(trim((string)($content['gender']??'')));$connectorContent=$connector['content']??null;
            $options=is_array($connectorContent)&&is_array($connectorContent['options']??null)&&!array_is_list($connectorContent['options'])?$connectorContent['options']:[];
            $fallbackField=match($gender){'male'=>'fallback_male','female'=>'fallback_female',default=>null};
            if($fallbackField!==null)$id=trim((string)($options[$fallbackField]??''));
        }
        if($id!==''&&strlen($id)<=512)$result['voice']=$id;if($language!==''&&strlen($language)<=35)$result['language']=$language;
        return$result;
    }

    /** Create and bind an NPC profile from trusted current-session metadata before its first prompt is assembled. */
    public function ensureMorrowindActorProfile(array $turn,array $resolvedVoice,string $now):string
    {
        $target=$turn['payload']['target']??null;
        if(!is_array($target)||array_is_list($target))throw new RuntimeException('invalid_actor_identity');
        return$this->transaction(function()use($turn,$target,$resolvedVoice,$now):string{
            $scope=$this->db->prepare("SELECT 1 FROM sessions WHERE session_id=:session AND generation=:generation AND state='active' "
                ."AND installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough FOR UPDATE");
            $scope->execute(['session'=>$turn['session_id'],'generation'=>$turn['generation'],'installation'=>$turn['installation_id'],
                'profile'=>$turn['profile_id'],'playthrough'=>$turn['playthrough_id']]);
            if(!$scope->fetchColumn())throw new \OutOfBoundsException('unknown_session');
            $key=$this->actorKey($target);
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>$key]);
            $profileId=$this->selectedActorProfileId((string)$turn['installation_id'],(string)$turn['playthrough_id'],$target);
            if($profileId===null){
                $refnum=is_array($target['refnum']??null)?$target['refnum']:[];
                $existing=$this->db->prepare("SELECT profile_id FROM profiles WHERE installation_id=:installation AND deleted_at IS NULL "
                    ."AND lower(actor_identity->>'record_id')=lower(:record) AND lower(COALESCE(actor_identity->>'content_file',''))=lower(:content) "
                    ."AND actor_identity->'refnum'->>'index'=:ref_index AND actor_identity->'refnum'->>'content_file'=:ref_content "
                    ."AND COALESCE(actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY created_at,profile_id LIMIT 2");
                $existing->execute(['installation'=>$turn['installation_id'],'record'=>$target['record_id']??'',
                    'content'=>$target['content_file']??'','ref_index'=>(string)($refnum['index']??''),
                    'ref_content'=>(string)($refnum['content_file']??'')]);$matches=$existing->fetchAll();
                if(count($matches)===1)$profileId=(string)$matches[0]['profile_id'];
                else{$template=$this->matchingBiographyTemplate((string)$turn['installation_id'],$target,$resolvedVoice);
                    $seed=is_array($template['content']??null)?$template['content']:[];unset($seed['management'],$seed['portrait']);
                    $seed['gender']=$resolvedVoice['gender'];$seed['race']=$resolvedVoice['race'];$seed['voice']=$this->catalogVoiceDocument($resolvedVoice);
                    $seed=$this->morrowindLocalityContent($seed,$target,(string)$turn['installation_id']);
                    $seed['management']=['locked'=>false,'favorite'=>false];
                    $name=trim((string)($target['display_name']??$target['record_id']??'Morrowind NPC'));
                    $name=$name===''?'Morrowind NPC':mb_substr($name,0,256);
                    $nameExists=$this->db->prepare('SELECT 1 FROM profiles WHERE installation_id=:installation AND name=:name AND deleted_at IS NULL');
                    $nameExists->execute(['installation'=>$turn['installation_id'],'name'=>$name]);
                    if($nameExists->fetchColumn()){$suffix=' [Ref '.(string)($refnum['content_file']??'?').':'.(string)($refnum['index']??'?').']';
                        $name=mb_substr($name,0,max(0,256-mb_strlen($suffix))).$suffix;}
                    $created=$this->createRevisioned('profile',['installation_id'=>$turn['installation_id'],
                    'name'=>$name,'actor_identity'=>$target,
                    'content'=>$seed,
                    'change_reason'=>'automatic Morrowind actor discovery'],$now);$profileId=(string)$created['profile_id'];}
                $this->bindActorProfile(['installation_id'=>$turn['installation_id'],'playthrough_id'=>$turn['playthrough_id']],
                    $target,$profileId,$now);
                $this->applyMorrowindCatalogLocality($profileId,$target,(string)$turn['installation_id'],$now);
            }
            $this->applyMorrowindCatalogVoice($profileId,$target,$resolvedVoice,$now,false);
            return$profileId;
        });
    }

    /** Prefer an exact actor voice exposed by the installation's active TTS provider over a generic catalog fallback. */
    public function preferExactProviderActorVoice(string $installationId,array $identity,array $fallback):array
    {
        $recordId=trim((string)($identity['record_id']??''));
        $actorKey=preg_replace('/[^a-z0-9]+/','',strtolower($recordId));
        if($actorKey==='')return$fallback;
        $statement=$this->db->prepare("SELECT v.voice_id,v.display_name,v.language FROM installation_provider_selections s "
            ."JOIN configuration_sets c ON c.configuration_id=s.configuration_id AND c.installation_id=s.installation_id "
            ."AND c.kind='tts_provider' AND c.deleted_at IS NULL JOIN speech_connector_voices v ON v.configuration_id=c.configuration_id "
            ."WHERE s.installation_id=:installation AND s.provider_kind='tts_provider' ORDER BY v.voice_id LIMIT 1024");
        $statement->execute(['installation'=>$installationId]);
        foreach($statement->fetchAll(PDO::FETCH_ASSOC)as$voice){
            $voiceKey=preg_replace('/[^a-z0-9]+/','',strtolower(trim((string)$voice['voice_id'])));
            if($voiceKey!==$actorKey)continue;
            return array_replace($fallback,['id'=>(string)$voice['voice_id'],'key'=>'actor:'.$recordId,
                'display_name'=>(string)$voice['display_name'],'language'=>(string)$voice['language'],
                'source'=>'actor_provider_catalog','confidence'=>'exact']);
        }
        return$fallback;
    }

    /** Save a complete custom override while retaining the immutable factory biography underneath. */
    public function saveBiographyTemplate(array $input):array
    {
        return$this->transaction(function()use($input):array{
            $name=trim((string)($input['npc_name']??''));
            if($name===''||strlen($name)>128||str_contains($name,"\0"))throw new RuntimeException('invalid_biography_template_name');
            $exists=$this->db->prepare('SELECT 1 FROM public.combined_bio_templates WHERE npc_name=:name');
            $exists->execute(['name'=>$name]);
            if($exists->fetchColumn()===false)throw new RuntimeException('biography_template_not_found');
            $relationships=trim((string)($input['relationships']??''));
            if($relationships==='')$relationships='{}';
            if(strlen($relationships)>16384||str_contains($relationships,"\0"))throw new RuntimeException('invalid_biography_relationships');
            try{$relationshipObject=json_decode($relationships,false,64,JSON_THROW_ON_ERROR);}
            catch(Throwable){throw new RuntimeException('invalid_biography_relationships');}
            if(!$relationshipObject instanceof \stdClass||count(get_object_vars($relationshipObject))>16)throw new RuntimeException('invalid_biography_relationships');
            $relationships=json_encode($relationshipObject,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $values=['npc_name'=>$name,'relationships'=>$relationships];
            foreach(['oghma_knowledge_tags'=>4096,'core'=>16384,'npc_static_bio'=>16384,'appearance'=>16384,
                'personality'=>16384,'occupation'=>16384,'skills'=>16384,'speechstyle'=>16384,'goals'=>16384,
                'voiceid'=>256,'gender'=>256,'race'=>256,'refid'=>256]as$field=>$limit){
                $value=trim((string)($input[$field]??''));
                if(strlen($value)>$limit||str_contains($value,"\0"))throw new RuntimeException('invalid_biography_template_field');
                $values[$field]=$value===''?null:$value;
            }
            $values['oghma_knowledge_tags']=$this->npcKnowledgeTags($values['oghma_knowledge_tags']??'');
            if($values['core']===null)throw new RuntimeException('invalid_biography_template_core');
            $this->db->prepare('INSERT INTO public.bio_templates_custom '
                .'(npc_name,oghma_knowledge_tags,core,npc_static_bio,appearance,personality,relationships,occupation,skills,speechstyle,goals,voiceid,gender,race,refid) '
                .'VALUES(:npc_name,:oghma_knowledge_tags,:core,:npc_static_bio,:appearance,:personality,:relationships,:occupation,:skills,:speechstyle,:goals,:voiceid,:gender,:race,:refid) '
                .'ON CONFLICT(npc_name) DO UPDATE SET oghma_knowledge_tags=EXCLUDED.oghma_knowledge_tags,core=EXCLUDED.core,npc_static_bio=EXCLUDED.npc_static_bio,'
                .'appearance=EXCLUDED.appearance,personality=EXCLUDED.personality,relationships=EXCLUDED.relationships,occupation=EXCLUDED.occupation,skills=EXCLUDED.skills,'
                .'speechstyle=EXCLUDED.speechstyle,goals=EXCLUDED.goals,voiceid=EXCLUDED.voiceid,gender=EXCLUDED.gender,race=EXCLUDED.race,refid=EXCLUDED.refid')
                ->execute($values);
            return['npc_name'=>$name,'source'=>'custom'];
        });
    }

    /** Choose the most specific reusable biography template for a newly observed NPC. */
    private function matchingBiographyTemplate(string $installation,array $identity,array $voice):?array
    {
        $recordId=trim((string)($identity['record_id']??''));$contentFile=trim((string)($identity['content_file']??''));
        $exact=$this->db->prepare("SELECT r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='template' AND lower(COALESCE(p.actor_identity->>'record_id',''))=lower(:record) AND lower(COALESCE(p.actor_identity->>'content_file',''))=lower(:content_file) ORDER BY p.created_at,p.profile_id LIMIT 1");
        $exact->execute(['installation'=>$installation,'record'=>$recordId,'content_file'=>$contentFile]);$exactValue=$exact->fetchColumn();
        if($exactValue!==false)return['content'=>$this->json($exactValue)];
        if($recordId!==''&&$contentFile!==''){
            $factory=$this->db->prepare("SELECT "
                ."CASE WHEN custom.npc_name IS NULL THEN entry.oghma_knowledge_tags ELSE custom.oghma_knowledge_tags END AS oghma_knowledge_tags,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.core ELSE custom.core END AS core,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.npc_static_bio ELSE custom.npc_static_bio END AS npc_static_bio,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.appearance ELSE custom.appearance END AS appearance,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.personality ELSE custom.personality END AS personality,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.relationships ELSE custom.relationships END AS relationships,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.occupation ELSE custom.occupation END AS occupation,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.skills ELSE custom.skills END AS skills,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.speechstyle ELSE custom.speechstyle END AS speechstyle,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.goals ELSE custom.goals END AS goals,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.voiceid ELSE custom.voiceid END AS voiceid,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.gender ELSE custom.gender END AS gender,"
                ."CASE WHEN custom.npc_name IS NULL THEN entry.race ELSE custom.race END AS race "
                ."FROM biography_catalog_entries entry JOIN biography_catalogs catalog ON catalog.catalog_id=entry.catalog_id AND catalog.state='active' "
                ."LEFT JOIN public.bio_templates_custom custom ON custom.npc_name=entry.npc_name "
                ."WHERE lower(entry.record_id)=lower(:record) AND lower(COALESCE(entry.content_file,''))=lower(:content_file) LIMIT 2");
            $factory->execute(['record'=>$recordId,'content_file'=>$contentFile]);$rows=$factory->fetchAll();
            if(count($rows)===1){$row=$rows[0];return['content'=>[
                'oghma_knowledge_tags'=>(string)($row['oghma_knowledge_tags']??''),'core'=>(string)($row['core']??''),
                'biography'=>(string)($row['npc_static_bio']??''),'appearance'=>(string)($row['appearance']??''),
                'personality'=>(string)($row['personality']??''),'relationships'=>(string)($row['relationships']??'{}'),
                'occupation'=>(string)($row['occupation']??''),'skills'=>(string)($row['skills']??''),
                'speech_style'=>(string)($row['speechstyle']??''),'goals'=>(string)($row['goals']??''),
                'gender'=>(string)($row['gender']??''),'race'=>(string)($row['race']??''),
            ]];}
        }
        $stmt=$this->db->prepare("SELECT r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='template' AND (COALESCE(p.actor_identity->>'record_id','')='' OR lower(p.actor_identity->>'record_id')=lower(:record)) AND (COALESCE(p.actor_identity->>'content_file','')='' OR lower(p.actor_identity->>'content_file')=lower(:content_file)) AND (COALESCE(r.content->>'race','')='' OR lower(r.content->>'race')=lower(:race)) AND (COALESCE(r.content->>'gender','')='' OR lower(r.content->>'gender')=lower(:gender)) ORDER BY (COALESCE(p.actor_identity->>'record_id','')<>'') DESC,(COALESCE(p.actor_identity->>'content_file','')<>'') DESC,(COALESCE(r.content->>'race','')<>'') DESC,(COALESCE(r.content->>'gender','')<>'') DESC,p.created_at,p.profile_id LIMIT 1");
        $stmt->execute(['installation'=>$installation,'record'=>(string)($identity['record_id']??''),'content_file'=>(string)($identity['content_file']??''),'race'=>(string)($voice['race']??''),'gender'=>(string)($voice['gender']??'')]);
        $value=$stmt->fetchColumn();return$value===false?null:['content'=>$this->json($value)];
    }

    /** Repair legacy automatic actor-name voices while preserving unrelated manual and locked choices. */
    public function backfillMorrowindCatalogVoices(MorrowindVoiceCatalog $catalog,string $now):array
    {
        $rows=$this->db->query("SELECT p.profile_id,p.installation_id,p.actor_identity,r.content FROM profiles p JOIN profile_revisions r "
            ."ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL "
            ."AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY p.profile_id")->fetchAll();
        $updated=0;$resolved=0;
        foreach($rows as$row){$identity=$this->json($row['actor_identity']);$content=$this->json($row['content']);
            $resolveIdentity=$identity;if(trim((string)($resolveIdentity['kind']??''))==='')$resolveIdentity['kind']='actor';
            $voice=$catalog->resolve($resolveIdentity,['targetState'=>['identity'=>['race'=>$content['race']??'',
                'gender'=>$content['gender']??'']]]);if($voice===null)continue;
            $voice=$this->preferExactProviderActorVoice((string)$row['installation_id'],$identity,$voice);$resolved++;
            if($this->applyMorrowindCatalogVoice((string)$row['profile_id'],$identity,$voice,$now,true))$updated++;}
        return['resolved'=>$resolved,'updated'=>$updated];
    }

    /** Add deterministic home locality only to unlocked automatically managed Morrowind actor profiles. */
    public function backfillMorrowindCatalogLocalities(string $now):array
    {
        $rows=$this->db->query("SELECT profile_id,installation_id,actor_identity FROM profiles WHERE deleted_at IS NULL "
            ."AND COALESCE(actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY profile_id")->fetchAll();
        $updated=0;
        foreach($rows as$row){$identity=$this->json($row['actor_identity']);
            if($this->applyMorrowindCatalogLocality((string)$row['profile_id'],$identity,
                (string)$row['installation_id'],$now))$updated++;}
        return['examined'=>count($rows),'updated'=>$updated];
    }

    private function applyMorrowindCatalogLocality(string $profileId,array $identity,string $installationId,string $now):bool
    {
        $select=$this->db->prepare('SELECT p.current_revision,r.content,r.change_reason FROM profiles p JOIN profile_revisions r '
            .'ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:profile AND p.deleted_at IS NULL FOR UPDATE OF p');
        $select->execute(['profile'=>$profileId]);$row=$select->fetch();if(!$row)return false;
        $content=$this->json($row['content']);
        if((bool)($content['management']['locked']??false)||isset($content['oghma_locality'])
            ||!str_starts_with((string)$row['change_reason'],'automatic Morrowind'))return false;
        $revised=$this->morrowindLocalityContent($content,$identity,$installationId);
        if($revised===$content)return false;
        $revision=(int)$row['current_revision']+1;
        $this->revision('profile_revisions','profile_id',$profileId,$revision,$revised,
            'automatic Morrowind geography catalog',$now);
        $this->db->prepare('UPDATE profiles SET current_revision=:revision WHERE profile_id=:profile')
            ->execute(['revision'=>$revision,'profile'=>$profileId]);
        return true;
    }

    private function morrowindLocalityContent(array $content,array $identity,string $installationId):array
    {
        if(isset($content['oghma_locality']))return$content;
        $referenceContentFile=$this->referenceContentFile($installationId,$identity);
        $resolved=$this->morrowindGeography()->resolve($identity,$referenceContentFile);
        if($resolved===null)return$content;
        $raw=trim((string)($content['oghma_knowledge_tags']??''));
        $tags=$raw===''?[]:array_values(array_filter(preg_split('/\s*[,|;]\s*/',$raw)?:[],
            static fn(string $tag):bool=>!in_array(mb_strtolower(trim($tag),'UTF-8'),['common','esoteric'],true)));
        $localityClasses=$this->morrowindGeography()->localityClasses();
        $tags=array_values(array_filter($tags,static fn(string $tag):bool=>!in_array($tag,$localityClasses,true)));
        foreach($resolved['tags']as$tag)if(!in_array($tag,$tags,true))$tags[]=$tag;
        $content['oghma_knowledge_tags']=implode(', ',$tags);
        $content['oghma_locality']=['source'=>$resolved['source'],'region'=>$resolved['region'],'tags'=>$resolved['tags']];
        return$content;
    }

    private function referenceContentFile(string $installationId,array $identity):?string
    {
        $refnum=is_array($identity['refnum']??null)?$identity['refnum']:[];
        if(!is_int($refnum['content_file']??null))return null;
        $statement=$this->db->prepare('SELECT content_file FROM content_manifest_files '
            .'WHERE installation_id=:installation AND load_order=:load_order AND active LIMIT 1');
        $statement->execute(['installation'=>$installationId,'load_order'=>$refnum['content_file']]);
        $value=$statement->fetchColumn();return$value===false?null:(string)$value;
    }

    private function morrowindGeography():MorrowindGeographyCatalog
    {
        return$this->morrowindGeography??=MorrowindGeographyCatalog::bundled();
    }

    /** Remove article-only markers before access permissions are stored on an installation or NPC. */
    private function npcKnowledgeTags(mixed $value):string
    {
        $tags=[];foreach(preg_split('/\s*[,|;]\s*/u',trim((string)$value))?:[]as$tag){$tag=trim($tag);
            if($tag===''||in_array(mb_strtolower($tag,'UTF-8'),['common','esoteric'],true)||in_array($tag,$tags,true))continue;
            $tags[]=$tag;}
        return implode(', ',$tags);
    }

    private function applyMorrowindCatalogVoice(string $profileId,array $identity,array $voice,string $now,bool $allowLegacyLocked):bool
    {
        $select=$this->db->prepare('SELECT p.current_revision,r.content FROM profiles p JOIN profile_revisions r '
            .'ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:profile AND p.deleted_at IS NULL FOR UPDATE OF p');
        $select->execute(['profile'=>$profileId]);$row=$select->fetch();if(!$row)return false;$content=$this->json($row['content']);
        $current=$content['voice']??[];if(is_string($current))$current=['id'=>$current];if(!is_array($current))$current=[];
        $currentId=trim((string)($current['id']??$current['voice_id']??''));$recordId=trim((string)($identity['record_id']??''));
        $catalogManaged=str_starts_with((string)($current['source']??''),'morrowind_');
        $legacyAutomatic=$allowLegacyLocked&&$currentId!==''&&$recordId!==''&&strcasecmp($currentId,$recordId)===0;
        $locked=(bool)($content['management']['locked']??false);
        if($currentId!==''&&!$catalogManaged&&!$legacyAutomatic)return false;
        if($locked&&!$catalogManaged&&!$legacyAutomatic)return false;
        $nextVoice=$this->catalogVoiceDocument($voice);
        $changed=$current!=$nextVoice||trim((string)($content['gender']??''))===''||trim((string)($content['race']??''))==='';
        if(!$changed)return false;$content['gender']=$voice['gender'];$content['race']=$voice['race'];$content['voice']=$nextVoice;
        $revision=(int)$row['current_revision']+1;$this->revision('profile_revisions','profile_id',$profileId,$revision,$content,
            'automatic Morrowind voice catalog',$now);
        $this->db->prepare('UPDATE profiles SET current_revision=:revision WHERE profile_id=:profile')->execute(['revision'=>$revision,'profile'=>$profileId]);
        return true;
    }

    private function catalogVoiceDocument(array $voice):array
    {
        return['id'=>$voice['id'],'language'=>$voice['language'],'source'=>'morrowind_'.$voice['source'],
            'catalog_key'=>$voice['key'],'confidence'=>$voice['confidence']];
    }

    private function connectorHasVoice(string $configurationId,string $voiceId):bool
    {
        $statement=$this->db->prepare('SELECT 1 FROM speech_connector_voices WHERE configuration_id=:configuration AND voice_id=:voice');
        $statement->execute(['configuration'=>$configurationId,'voice'=>$voiceId]);return(bool)$statement->fetchColumn();
    }

    public function createMemory(array $input,array $terms,array $vector,string $now): array
    {
        $id=Uuid::v4();
        $this->db->prepare('INSERT INTO memory_records (memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at) VALUES (:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),:source,CAST(:provenance AS jsonb),:occurred,:expires,:now,:now)')
            ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'],'playthrough'=>$input['playthrough_id'],'tier'=>$input['tier'],'content'=>$input['content'],'terms'=>$this->pgArray($terms),'vector'=>$this->encode($vector),'source'=>$input['source_event_id'] ?? null,'provenance'=>$this->encode($input['provenance']),'occurred'=>$input['occurred_at'] ?? $now,'expires'=>$input['expires_at'] ?? null,'now'=>$now]);
        return $this->memory($id);
    }

    public function memory(string $id): array
    {
        $stmt=$this->db->prepare('SELECT * FROM memory_records WHERE memory_id=:id AND deleted_at IS NULL');$stmt->execute(['id'=>$id]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('not_found');return $this->decodeMemory($row);
    }

    public function updateMemory(string $id,string $content,array $terms,array $vector,string $now): array
    {
        $this->db->prepare('UPDATE memory_records SET content=:content,lexical_terms=CAST(:terms AS text[]),fake_vector=CAST(:vector AS jsonb),updated_at=:now WHERE memory_id=:id AND deleted_at IS NULL')
            ->execute(['content'=>$content,'terms'=>$this->pgArray($terms),'vector'=>$this->encode($vector),'now'=>$now,'id'=>$id]);
        return $this->memory($id);
    }

    public function deleteMemory(string $id,string $now): void {$this->db->prepare('UPDATE memory_records SET deleted_at=:now WHERE memory_id=:id')->execute(['now'=>$now,'id'=>$id]);}

    public function rebuildMemories(array $scope,string $now): int
    {
        $stmt=$this->db->prepare('SELECT memory_id,content FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL');$stmt->execute($this->scopeParams($scope));$count=0;
        foreach($stmt->fetchAll() as $row){$terms=\ALMSIVIserver\Application\DeterministicRetrieval::terms($row['content']);$vector=\ALMSIVIserver\Application\DeterministicRetrieval::fakeVector($row['content']);$this->updateMemory($row['memory_id'],$row['content'],$terms,$vector,$now);++$count;}return $count;
    }

    public function enforceMemoryRetention(array $scope,array $days,string $now): int
    {
        $count=0;foreach(['recent','mid','long'] as $tier){$retention=(int)($days[$tier]??0);if($retention<1)continue;$stmt=$this->db->prepare("UPDATE memory_records SET deleted_at=:now WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND tier=:tier AND deleted_at IS NULL AND occurred_at < CAST(:now AS timestamptz) - (:days || ' days')::interval");$stmt->execute($this->scopeParams($scope)+['tier'=>$tier,'now'=>$now,'days'=>(string)$retention]);$count+=$stmt->rowCount();}return $count;
    }

    public function memoryCandidates(array $scope,string $now): array
    {
        $stmt=$this->db->prepare('SELECT memory_id AS id,tier,content,lexical_terms,fake_vector,provenance,source_event_id,occurred_at,updated_at,current_revision FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL AND (expires_at IS NULL OR expires_at>:now) ORDER BY occurred_at DESC LIMIT 500');$stmt->execute($this->scopeParams($scope)+['now'=>$now]);return array_map(fn($r)=>$this->decodeMemory($r),$stmt->fetchAll());
    }

    /** Keep manual memories with their NPC profile and require witnessed provenance for derived rows. */
    private function promptMemoryCandidates(array $turn, array $actorKey, string $activeProfileId, bool $ownsProfile, string $now): array
    {
        $statement = $this->db->prepare('SELECT memory_id AS id,profile_id,tier,content,lexical_terms,fake_vector,'
            . 'provenance,source_event_id,derivation_key,occurred_at,updated_at,current_revision FROM memory_records '
            . 'WHERE installation_id=:installation AND playthrough_id=:playthrough '
            . 'AND profile_id IN (:session_profile,:actor_profile) AND deleted_at IS NULL '
            . 'AND (expires_at IS NULL OR expires_at>:now) ORDER BY occurred_at DESC,memory_id LIMIT 500');
        $statement->execute(['installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
            'session_profile'=>$turn['profile_id'],'actor_profile'=>$activeProfileId,'now'=>$now]);
        $candidates = [];
        $sourceIds = [];
        foreach ($statement->fetchAll() as $row) {
            $memory = $this->decodeMemory($row);
            $ids = $this->memorySourceIds($memory);
            if ($ids === null) continue;
            if ($ids === [] && (!$ownsProfile || $memory['profile_id'] !== $activeProfileId
                || $memory['derivation_key'] !== null
                || in_array($memory['provenance']['source'] ?? null, ['dialogue.delivery','memory.consolidate'], true))) continue;
            $memory['_source_event_ids'] = $ids;
            $candidates[] = $memory;
            foreach ($ids as $id) $sourceIds[$id] = true;
        }
        if ($sourceIds === []) return $candidates;

        // One bounded lookup for all candidate sources; use only the principal event projection,
        // so hiding a conversation cannot be bypassed through its weather/location side records.
        $sources = $this->db->prepare(<<<'SQL'
SELECT se.source_event_id
FROM source_events se
LEFT JOIN dialogue_delivery_results d ON d.source_event_id=se.source_event_id
JOIN eventlog_metadata m ON m.projection_key=CASE se.event_kind
    WHEN 'dialogue.delivery' THEN 'dialogue:'||d.dialogue_message_id::text
    WHEN 'turn.requested' THEN 'turn:'||COALESCE(se.turn_id,se.source_event_id)::text
    WHEN 'action.result' THEN 'action-result:'||se.source_event_id::text
    WHEN 'location' THEN 'location:'||se.source_event_id::text
    WHEN 'death' THEN 'death:'||se.source_event_id::text
    WHEN 'narration' THEN 'narration:'||se.source_event_id::text END
  AND m.projection_kind=CASE se.event_kind
    WHEN 'dialogue.delivery' THEN 'dialogue' WHEN 'turn.requested' THEN 'turn'
    WHEN 'action.result' THEN 'action' ELSE 'world' END
WHERE se.source_event_id=ANY(CAST(:sources AS uuid[])) AND se.installation_id=:installation
  AND m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL
  AND m.turn_id IS DISTINCT FROM :current_turn
  AND (se.event_kind<>'dialogue.delivery' OR d.status='played')
  AND (m.speaker @> CAST(:actor AS jsonb) OR m.target @> CAST(:actor AS jsonb)
       OR m.audience @> CAST(:audience AS jsonb))
SQL);
        $sources->execute(['sources'=>$this->pgArray(array_keys($sourceIds)),'installation'=>$turn['installation_id'],
            'playthrough'=>$turn['playthrough_id'],'current_turn'=>$turn['turn_id']??null,
            'actor'=>$this->encode($actorKey),'audience'=>$this->encode([$actorKey])]);
        $witnessed = array_fill_keys($sources->fetchAll(PDO::FETCH_COLUMN), true);
        return array_values(array_filter($candidates, static function(array $memory) use ($witnessed): bool {
            foreach ($memory['_source_event_ids'] as $id) if (!isset($witnessed[$id])) return false;
            return true;
        }));
    }

    /** Flatten and bound source references; malformed provenance never grants prompt access. */
    private function memorySourceIds(array $memory): ?array
    {
        $ids = $memory['provenance']['source_event_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 64) return null;
        if ($memory['source_event_id'] !== null) $ids[] = $memory['source_event_id'];
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) return null;
        }
        return array_values(array_unique($ids));
    }

    public function createKnowledge(array $input,array $terms,string $now): array
    {
        $id=Uuid::v4();$sha=hash('sha256',$input['content']);$statement=$this->db->prepare("INSERT INTO knowledge_documents (document_id,installation_id,profile_id,playthrough_id,title,content,content_sha256,lexical_terms,provenance,created_at,topic,aliases,topic_desc_basic,knowledge_class,knowledge_class_basic,tags,category) VALUES (:id,:installation,:profile,:playthrough,:title,:content,:sha,CAST(:terms AS text[]),CAST(:provenance AS jsonb),:now,:topic,:aliases,:basic,:advanced_class,:basic_class,:tags,:category) ON CONFLICT (installation_id,(COALESCE(profile_id,'00000000-0000-0000-0000-000000000000'::uuid)),(COALESCE(playthrough_id,'00000000-0000-0000-0000-000000000000'::uuid)),(lower(topic))) WHERE deleted_at IS NULL AND provenance->>'source' IS DISTINCT FROM 'factory-oghma' DO UPDATE SET title=EXCLUDED.title,content=EXCLUDED.content,content_sha256=EXCLUDED.content_sha256,lexical_terms=EXCLUDED.lexical_terms,provenance=EXCLUDED.provenance,created_at=EXCLUDED.created_at,aliases=EXCLUDED.aliases,topic_desc_basic=EXCLUDED.topic_desc_basic,knowledge_class=EXCLUDED.knowledge_class,knowledge_class_basic=EXCLUDED.knowledge_class_basic,tags=EXCLUDED.tags,category=EXCLUDED.category RETURNING document_id");$statement->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id']??null,'playthrough'=>$input['playthrough_id']??null,'title'=>$input['title'],'content'=>$input['content'],'sha'=>$sha,'terms'=>$this->pgArray($terms),'provenance'=>$this->encode($input['provenance']),'now'=>$now,'topic'=>$input['topic'],'aliases'=>$input['aliases'],'basic'=>$input['topic_desc_basic'],'advanced_class'=>$input['knowledge_class'],'basic_class'=>$input['knowledge_class_basic'],'tags'=>$input['tags'],'category'=>$input['category']]);$savedId=$statement->fetchColumn();if(!is_string($savedId)||$savedId==='')throw new RuntimeException('knowledge_save_failed');return $this->knowledge($savedId);
    }
    /** Insert a fully validated Oghma import as one transaction. */
    public function createKnowledgeBatch(array $prepared,string $now):array
    {
        return$this->transaction(function()use($prepared,$now):array{$saved=[];foreach($prepared as$row)$saved[]=$this->createKnowledge($row['input'],$row['terms'],$now);return$saved;});
    }
    public function updateKnowledge(string $id,array $input,array $terms,string $now):array{$sha=hash('sha256',$input['content']);$statement=$this->db->prepare('UPDATE knowledge_documents SET title=:title,content=:content,content_sha256=:sha,lexical_terms=CAST(:terms AS text[]),provenance=CAST(:provenance AS jsonb),topic=:topic,aliases=:aliases,topic_desc_basic=:basic,knowledge_class=:advanced_class,knowledge_class_basic=:basic_class,tags=:tags,category=:category WHERE document_id=:id AND deleted_at IS NULL');$statement->execute(['id'=>$id,'title'=>$input['title'],'content'=>$input['content'],'sha'=>$sha,'terms'=>$this->pgArray($terms),'provenance'=>$this->encode($input['provenance']),'topic'=>$input['topic'],'aliases'=>$input['aliases'],'basic'=>$input['topic_desc_basic'],'advanced_class'=>$input['knowledge_class'],'basic_class'=>$input['knowledge_class_basic'],'tags'=>$input['tags'],'category'=>$input['category']]);if($statement->rowCount()!==1)throw new RuntimeException('not_found');return$this->knowledge($id);}
    public function knowledge(string $id): array {$s=$this->db->prepare('SELECT * FROM knowledge_documents WHERE document_id=:id AND deleted_at IS NULL');$s->execute(['id'=>$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('not_found');$r['id']=$r['document_id'];$r['lexical_terms']=$this->parsePgArray($r['lexical_terms']);$r['provenance']=$this->json($r['provenance']);return $r;}
    public function deleteKnowledge(string $id,string $now):void{$row=$this->knowledge($id);if(($row['provenance']['source']??null)==='factory-oghma')throw new \InvalidArgumentException('factory_knowledge_read_only');$this->db->prepare('UPDATE knowledge_documents SET deleted_at=:now WHERE document_id=:id')->execute(['now'=>$now,'id'=>$id]);}
    public function knowledgeCandidates(array $scope,?array $loadedContentFiles=null):array
    {
        $sql=$this->effectiveKnowledgeSql('document_id AS id,title,content,content_sha256,lexical_terms,provenance,topic,aliases,topic_desc_basic,knowledge_class,knowledge_class_basic,tags,category',$loadedContentFiles!==null);
        $parameters=['installation'=>$scope['installation_id'],'profile'=>$scope['profile_id']??null,'playthrough'=>$scope['playthrough_id']??null];
        if($loadedContentFiles!==null){$normalized=[];foreach(array_slice($loadedContentFiles,0,256)as$file){
            if(!is_string($file))continue;$file=strtolower(trim($file));if($file!=='')$normalized[$file]=true;
        }$parameters['loaded_content_files']=$this->pgArray(array_keys($normalized));}
        $s=$this->db->prepare($sql);$s->execute($parameters);
        return array_map(function($r){$r['lexical_terms']=$this->parsePgArray($r['lexical_terms']);$r['provenance']=$this->json($r['provenance']);return $r;},$s->fetchAll());
    }

    /** Return the complete effective Oghma catalog visible to one NPC profile. */
    public function oghmaKnowledgeForProfile(string $installationId,string $profileId,array $filters=[]):array
    {
        $profileStatement=$this->db->prepare('SELECT p.name,p.actor_identity FROM profiles p WHERE p.profile_id=:profile AND p.installation_id=:installation AND p.deleted_at IS NULL');
        $profileStatement->execute(['profile'=>$profileId,'installation'=>$installationId]);$profile=$profileStatement->fetch();if(!$profile)throw new RuntimeException('not_found');
        $effective=$this->effectiveSettingsForProfile($installationId,$profileId);$tags=$this->knowledgeValues((string)($effective['settings']['memory']['oghma_knowledge_tags']??''));
        $search=mb_strtolower(mb_strcut(trim((string)($filters['search']??'')),0,100,'UTF-8'),'UTF-8');$category=trim((string)($filters['category']??''));
        $accessFilter=strtolower(trim((string)($filters['access']??'all')));if(!in_array($accessFilter,['all','advanced','basic'],true))$accessFilter='all';
        $items=[];$counts=['advanced'=>0,'basic'=>0,'denied'=>0];$categories=[];
        foreach($this->knowledgeCandidates(['installation_id'=>$installationId,'profile_id'=>$profileId,'playthrough_id'=>null])as$row){
            $decision=OghmaGroundedRetriever::accessDecision($row,$tags);$access=$decision['level'];if($access==='denied'){$counts['denied']++;continue;}$counts[$access]++;
            $row['access_level']=$access;$row['effective_content']=$access==='advanced'?(string)$row['content']:(string)$row['topic_desc_basic'];
            $categories[(string)$row['category']]=true;if($category!==''&&!hash_equals((string)$row['category'],$category))continue;
            if($accessFilter!=='all'&&$access!==$accessFilter)continue;
            if($search!==''&&!str_contains(mb_strtolower(implode(' ',[(string)$row['topic'],(string)$row['title'],(string)$row['aliases'],(string)$row['tags']]),'UTF-8'),$search))continue;
            $items[]=$row;
        }
        usort($items,static fn(array$a,array$b):int=>strnatcasecmp((string)$a['topic'],(string)$b['topic'])?:strcmp((string)$a['id'],(string)$b['id']));
        $page=max(1,(int)($filters['page']??1));$pageSize=50;$total=count($items);$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);
        $profile['actor_identity']=$this->json($profile['actor_identity']);$categoryList=array_keys($categories);natcasesort($categoryList);
        return['profile'=>$profile+['profile_id'=>$profileId],'items'=>array_slice($items,($page-1)*$pageSize,$pageSize),'total'=>$total,
            'page'=>$page,'pages'=>$pages,'counts'=>$counts,'knowledge_tags'=>$tags,'categories'=>array_values($categoryList),
            'filters'=>['search'=>$search,'category'=>$category,'access'=>$accessFilter]];
    }

    public function recordRetrieval(string $domain,array $scope,string $query,array $rows,string $now):array
    {
        $id=Uuid::v4();$ids=array_column($rows,'id');$scores=[];foreach($rows as $r)$scores[$r['id']]=$r['score'];$this->db->prepare('INSERT INTO retrieval_traces (retrieval_trace_id,installation_id,profile_id,playthrough_id,domain,query,result_ids,scores,algorithm,created_at) VALUES (:id,:installation,:profile,:playthrough,:domain,:query,CAST(:ids AS uuid[]),CAST(:scores AS jsonb),:algorithm,:now)')->execute(['id'=>$id,'installation'=>$scope['installation_id'],'profile'=>$scope['profile_id']??null,'playthrough'=>$scope['playthrough_id']??null,'domain'=>$domain,'query'=>$query,'ids'=>$this->pgArray($ids),'scores'=>$this->encode($scores),'algorithm'=>'lexical-0.75+fake-vector-0.25-v1','now'=>$now]);return ['trace_id'=>$id,'algorithm'=>'lexical-0.75+fake-vector-0.25-v1','results'=>$rows];
    }

    public function setRelationship(array $input,string $now):array
    {
        $identity=$this->encode($input['actor_identity']);return $this->transaction(function()use($input,$now,$identity){$find=$this->db->prepare('SELECT * FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND actor_identity=CAST(:identity AS jsonb) AND deleted_at IS NULL FOR UPDATE');$find->execute($this->scopeParams($input)+['identity'=>$identity]);$before=$find->fetch();$id=$before['relationship_id']??Uuid::v4();if($before){$this->db->prepare('UPDATE relationship_records SET disposition=:disposition,affinity=:affinity,source_mode=:mode,source_event_id=:source,updated_at=:now WHERE relationship_id=:id')->execute(['disposition'=>$input['disposition'],'affinity'=>$input['affinity'],'mode'=>$input['source_mode'],'source'=>$input['source_event_id']??null,'now'=>$now,'id'=>$id]);}else{$this->db->prepare('INSERT INTO relationship_records (relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,source_mode,source_event_id,updated_at) VALUES (:id,:installation,:profile,:playthrough,CAST(:identity AS jsonb),:disposition,:affinity,:mode,:source,:now)')->execute($this->scopeParams($input)+['id'=>$id,'identity'=>$this->encode($input['actor_identity']),'disposition'=>$input['disposition'],'affinity'=>$input['affinity'],'mode'=>$input['source_mode'],'source'=>$input['source_event_id']??null,'now'=>$now]);}$after=['disposition'=>$input['disposition'],'affinity'=>$input['affinity']];$this->db->prepare('INSERT INTO relationship_audit (audit_id,relationship_id,mode,before_value,after_value,reason,source_event_id,created_at) VALUES (:audit,:id,:mode,CAST(:before AS jsonb),CAST(:after AS jsonb),:reason,:source,:now)')->execute(['audit'=>Uuid::v4(),'id'=>$id,'mode'=>$input['source_mode'],'before'=>$this->encode($before?['disposition'=>(int)$before['disposition'],'affinity'=>(int)$before['affinity']]:[]),'after'=>$this->encode($after),'reason'=>$input['reason']??'updated','source'=>$input['source_event_id']??null,'now'=>$now]);return ['relationship_id'=>$id]+$after+['source_mode'=>$input['source_mode']];});
    }

    /** Soft-delete one relationship and preserve the state change in its audit history. */
    public function deleteRelationship(string $id,string $now):void
    {
        $this->transaction(function()use($id,$now):void{$find=$this->db->prepare('SELECT disposition,affinity FROM relationship_records WHERE relationship_id=:id AND deleted_at IS NULL FOR UPDATE');
            $find->execute(['id'=>$id]);$before=$find->fetch();if(!$before)throw new RuntimeException('not_found');
            $this->db->prepare('UPDATE relationship_records SET deleted_at=:now,updated_at=:now WHERE relationship_id=:id')->execute(['now'=>$now,'id'=>$id]);
            $this->db->prepare('INSERT INTO relationship_audit (audit_id,relationship_id,mode,before_value,after_value,reason,created_at) VALUES (:audit,:id,:mode,CAST(:before AS jsonb),CAST(:after AS jsonb),:reason,:now)')
                ->execute(['audit'=>Uuid::v4(),'id'=>$id,'mode'=>'manual','before'=>$this->encode(['disposition'=>(int)$before['disposition'],'affinity'=>(int)$before['affinity']]),'after'=>$this->encode(['deleted'=>true]),'reason'=>'management delete','now'=>$now]);});
    }

    public function relationships(array $scope):array{$s=$this->db->prepare('SELECT * FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 100');$s->execute($this->scopeParams($scope));return array_map(function($r){$r['actor_identity']=$this->json($r['actor_identity']);return $r;},$s->fetchAll());}

    public function createNarrative(array $input,string $now):array{$id=Uuid::v4();$this->db->prepare('INSERT INTO narrative_records (narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) VALUES (:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now)')->execute($this->scopeParams($input)+['id'=>$id,'kind'=>$input['kind'],'title'=>$input['title'],'content'=>$input['content'],'provenance'=>$this->encode($input['provenance']),'now'=>$now]);return ['narrative_id'=>$id]+$input;}
    public function updateNarrative(string $id,array $input,string $now):array{$statement=$this->db->prepare('UPDATE narrative_records SET kind=:kind,title=:title,content=:content,provenance=CAST(:provenance AS jsonb),updated_at=:now WHERE narrative_id=:id AND deleted_at IS NULL RETURNING narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,updated_at');
        $statement->execute(['id'=>$id,'kind'=>$input['kind'],'title'=>$input['title'],'content'=>$input['content'],'provenance'=>$this->encode($input['provenance']),'now'=>$now]);$row=$statement->fetch();if(!$row)throw new RuntimeException('not_found');$row['provenance']=$this->json($row['provenance']);return$row;}
    public function deleteNarrative(string $id,string $now):void{$this->db->prepare('UPDATE narrative_records SET deleted_at=:now,updated_at=:now WHERE narrative_id=:id')->execute(['now'=>$now,'id'=>$id]);}
    public function narratives(array $scope):array{$s=$this->db->prepare('SELECT * FROM narrative_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100');$s->execute($this->scopeParams($scope));return array_map(function($r){$r['provenance']=$this->json($r['provenance']);return $r;},$s->fetchAll());}

    public function exportScope(array $scope):array
    {
        $queries=[
            'memories'=>'SELECT memory_id AS id,tier,content,lexical_terms,fake_vector,provenance,source_event_id,occurred_at,expires_at FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY occurred_at DESC',
            'relationships'=>'SELECT * FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY updated_at DESC',
            'narratives'=>'SELECT * FROM narrative_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY created_at DESC'];
        $result=[];foreach($queries as$name=>$sql){$s=$this->db->prepare($sql);$s->execute($this->scopeParams($scope));$rows=$s->fetchAll();if($name==='memories')$rows=array_map(fn($r)=>$this->decodeMemory($r),$rows);elseif($name==='relationships')$rows=array_map(function($r){$r['actor_identity']=$this->json($r['actor_identity']);return$r;},$rows);else$rows=array_map(function($r){$r['provenance']=$this->json($r['provenance']);return$r;},$rows);$result[$name]=$rows;}return$result;
    }
    public function restoreScope(array $document,string $now):array
    {
        return$this->transaction(function()use($document,$now):array{$counts=['memories'=>0,'relationships'=>0,'narratives'=>0];$scope=$document['scope'];$key=hash('sha256',$this->encode($document));
            foreach($document['data']['memories'] as$i=>$r){$id=$this->deterministicUuid('restore:memory:'.$key.':'.$i);$s=$this->db->prepare('SELECT 1 FROM memory_records WHERE memory_id=:id');$s->execute(['id'=>$id]);if(!$s->fetchColumn()){$this->db->prepare('INSERT INTO memory_records(memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at) VALUES(:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),:source,CAST(:provenance AS jsonb),:occurred,:expires,:now,:now)')->execute($this->scopeParams($scope)+['id'=>$id,'tier'=>$r['tier'],'content'=>$r['content'],'terms'=>$this->pgArray($r['lexical_terms']),'vector'=>$this->encode(\ALMSIVIserver\Application\DeterministicRetrieval::fakeVector($r['content'])),'source'=>$r['source_event_id']??null,'provenance'=>$this->encode($r['provenance']??['source'=>'restore','key'=>$key]),'occurred'=>$r['occurred_at'],'expires'=>$r['expires_at']??null,'now'=>$now]);}$counts['memories']++;}
            foreach($document['data']['relationships'] as$i=>$r){$id=$this->deterministicUuid('restore:relationship:'.$key.':'.$i);$this->db->prepare('INSERT INTO relationship_records(relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,source_mode,source_event_id,updated_at) VALUES(:id,:installation,:profile,:playthrough,CAST(:identity AS jsonb),:disposition,:affinity,:mode,:source,:now) ON CONFLICT(relationship_id) DO NOTHING')->execute($this->scopeParams($scope)+['id'=>$id,'identity'=>$this->encode($r['actor_identity']),'disposition'=>(int)$r['disposition'],'affinity'=>(int)$r['affinity'],'mode'=>$r['source_mode']??'manual','source'=>$r['source_event_id']??null,'now'=>$now]);$counts['relationships']++;}
            foreach($document['data']['narratives'] as$i=>$r){$id=$this->deterministicUuid('restore:narrative:'.$key.':'.$i);$s=$this->db->prepare('SELECT 1 FROM narrative_records WHERE narrative_id=:id');$s->execute(['id'=>$id]);if(!$s->fetchColumn())$this->db->prepare('INSERT INTO narrative_records(narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) VALUES(:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now)')->execute($this->scopeParams($scope)+['id'=>$id,'kind'=>$r['kind'],'title'=>$r['title'],'content'=>$r['content'],'provenance'=>$this->encode($r['provenance']??['source'=>'restore','key'=>$key]),'now'=>$now]);$counts['narratives']++;}return$counts;});
    }

    /** Return only safe, displayable in-game controls for this active session and actor. */
    public function sessionControls(array $session, array $target): array
    {
        $providers=$this->db->prepare("SELECT c.configuration_id,c.name,c.current_revision,r.content FROM configuration_sets c "
            ."JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision "
            ."WHERE c.installation_id=:installation AND c.kind='provider' AND c.deleted_at IS NULL ORDER BY c.name,c.configuration_id LIMIT 32");
        $providers->execute(['installation'=>$session['installation_id']]);
        $modelSlots=[];
        foreach($providers->fetchAll() as$row){$content=$this->json($row['content']);$modelSlots[]=[
            'configuration_id'=>(string)$row['configuration_id'],'name'=>(string)$row['name'],
            'revision'=>(int)$row['current_revision'],'driver'=>(string)($content['driver']??'mock'),
            'model'=>(string)($content['model']??'deterministic-mock-v1')];}
        $profiles=$this->db->prepare("SELECT profile_id,name,current_revision FROM profiles "
            ."WHERE installation_id=:installation AND deleted_at IS NULL "
            ."AND COALESCE(actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY name,profile_id LIMIT 100");
        $profiles->execute(['installation'=>$session['installation_id']]);
        $profileRows=array_map(static fn(array$row):array=>['profile_id'=>(string)$row['profile_id'],
            'name'=>(string)$row['name'],'revision'=>(int)$row['current_revision']],$profiles->fetchAll());
        $selectedModel=$session['provider_configuration_id']!==null?(string)$session['provider_configuration_id']:null;
        if($selectedModel!==null&&!in_array($selectedModel,array_column($modelSlots,'configuration_id'),true))$selectedModel=null;
        $narrator=$this->narratorProfileForInstallation((string)$session['installation_id']);
        $effective=$this->effectiveSettingsForActor((string)$session['installation_id'],(string)$session['playthrough_id'],$target);
        $profile=is_array($effective['npc_profile']??null)?$effective['npc_profile']:null;
        $core=is_array($effective['core_profile']??null)?$effective['core_profile']:null;
        $sourceMap=array_filter($effective['sources']??[],static fn(mixed $source,string $path):bool=>is_string($source)
            &&(str_starts_with($path,'settings.memory.')||str_starts_with($path,'settings.narrator.')
                ||str_starts_with($path,'settings.safety.')||str_starts_with($path,'routing.')),ARRAY_FILTER_USE_BOTH);
        $routing=$effective['routing'];
        foreach(['llm_randomizer_enabled','llm_fallback_enabled'] as $flag){
            if(!array_key_exists($flag,$routing))$routing[$flag]=false;
            if(!array_key_exists('routing.'.$flag,$sourceMap))$sourceMap['routing.'.$flag]='default';
        }
        $effectiveSettings=[
            'schema'=>'almsivi.effective-settings.v1',
            'profile_id'=>$profile===null?null:(string)$profile['profile_id'],
            'profile_revision'=>$profile===null?null:(int)($profile['revision']??$profile['current_revision']??0),
            'core_profile_id'=>$core===null?null:(string)$core['core_profile_id'],
            'core_profile_revision'=>$core===null?null:(int)($core['revision']??$core['current_revision']??0),
            'settings'=>[
                'memory'=>$effective['settings']['memory'],
                'narrator'=>$effective['settings']['narrator'],
                'safety'=>$effective['settings']['safety'],
            ],
            'routing'=>$routing,
            'source_map'=>$sourceMap,
        ];
        $effectiveSettings['change_token']=hash('sha256',$this->encodeCanonical($effectiveSettings));
        return ['model_slots'=>$modelSlots,'profiles'=>$profileRows,
            'selected_model_slot_id'=>$selectedModel,
            'narrator_profile_id'=>$narrator===null?null:(string)$narrator['profile_id'],
            'selected_profile_id'=>$this->selectedActorProfileId((string)$session['installation_id'],
                (string)$session['playthrough_id'],$target),
            'effective_settings'=>$effectiveSettings];
    }

    /** Build a secret-free snapshot of revisioned installation configuration. */
    public function configurationBackupState(string $installationId):array
    {
        $active=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation AND revoked_at IS NULL');
        $active->execute(['installation'=>$installationId]);if(!$active->fetchColumn())throw new RuntimeException('not_found');

        // Ensure newly registered installations have the neutral inheritance layer before export.
        $this->defaultCoreProfileForInstallation($installationId, gmdate('Y-m-d\TH:i:s\Z'), true);

        $cores=$this->db->prepare('SELECT c.core_profile_id,c.label,c.default_npc,c.slot,r.content FROM core_profiles c '
            .'JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision '
            .'WHERE c.installation_id=:installation AND c.deleted_at IS NULL ORDER BY c.default_npc DESC,c.slot NULLS LAST,c.label,c.core_profile_id LIMIT 100');
        $cores->execute(['installation'=>$installationId]);$coreRows=[];
        foreach($cores->fetchAll() as$row)$coreRows[]=[
            'core_profile_id'=>(string)$row['core_profile_id'],'label'=>(string)$row['label'],
            'default_npc'=>in_array($row['default_npc'],[true,1,'1','t','true'],true),'slot'=>$row['slot']===null?null:(int)$row['slot'],
            'content'=>$this->withoutSecrets($this->json($row['content']))];

        $profiles=$this->db->prepare('SELECT p.profile_id,p.core_profile_id,p.name,p.actor_identity,r.content FROM profiles p '
            .'JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision '
            .'WHERE p.installation_id=:installation AND p.deleted_at IS NULL ORDER BY p.name,p.profile_id LIMIT 2000');
        $profiles->execute(['installation'=>$installationId]);$profileRows=[];
        foreach($profiles->fetchAll() as$row){$content=$this->json($row['content']);unset($content['portrait']);$profileRows[]=[
            'profile_id'=>(string)$row['profile_id'],'core_profile_id'=>$row['core_profile_id']===null?null:(string)$row['core_profile_id'],
            'name'=>(string)$row['name'],'actor_identity'=>$this->json($row['actor_identity']),
            'content'=>$this->withoutSecrets($content)];}

        $configurations=$this->db->prepare('SELECT c.configuration_id,c.profile_id,c.kind,c.name,r.content FROM configuration_sets c '
            .'JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision '
            .'WHERE c.installation_id=:installation AND c.deleted_at IS NULL ORDER BY c.kind,c.name,c.configuration_id LIMIT 2000');
        $configurations->execute(['installation'=>$installationId]);$configurationRows=[];
        foreach($configurations->fetchAll() as$row)$configurationRows[]=[
            'configuration_id'=>(string)$row['configuration_id'],'profile_id'=>$row['profile_id']===null?null:(string)$row['profile_id'],
            'kind'=>(string)$row['kind'],'name'=>(string)$row['name'],'content'=>$this->withoutSecrets($this->json($row['content']))];

        $selections=$this->db->prepare('SELECT provider_kind,configuration_id FROM installation_provider_selections '
            .'WHERE installation_id=:installation ORDER BY provider_kind');
        $selections->execute(['installation'=>$installationId]);
        return['core_profiles'=>$coreRows,'profiles'=>$profileRows,'configurations'=>$configurationRows,'connector_selections'=>$selections->fetchAll(),
            'preferences'=>['auto_lock_on_edit'=>$this->profileAutoLockEnabled($installationId)]];
    }

    /** Record the immutable file identity used by authenticated backup downloads and restores. */
    public function recordConfigurationBackup(string $backupId,string $sha256,int $bytes,string $installationId,string $now,int $formatVersion=2):void
    {
        $this->db->prepare('INSERT INTO backup_records(backup_id,format_version,content_sha256,byte_count,scope,state,created_at) '
            ."VALUES(:id,:format,:sha,:bytes,CAST(:scope AS jsonb),'created',:now)")
            ->execute(['id'=>$backupId,'format'=>$formatVersion,'sha'=>$sha256,'bytes'=>$bytes,
                'scope'=>$this->encode(['kind'=>'configuration','installation_id'=>$installationId]),'now'=>$now]);
    }

    public function configurationBackupRecord(string $backupId):array
    {
        $statement=$this->db->prepare('SELECT backup_id,format_version,content_sha256,byte_count,scope,state,created_at,restored_at '
            .'FROM backup_records WHERE backup_id=:id');$statement->execute(['id'=>$backupId]);$row=$statement->fetch();
        if(!$row)throw new RuntimeException('not_found');$row['scope']=$this->json($row['scope']);return$row;
    }

    /** Restore one validated server-generated configuration snapshot as new auditable revisions. */
    public function restoreConfigurationBackup(array $document,string $now):array
    {
        return$this->transaction(function()use($document,$now):array{
            $installation=(string)$document['installation_id'];$active=$this->db->prepare(
                'SELECT 1 FROM installations WHERE installation_id=:installation AND revoked_at IS NULL FOR UPDATE');
            $active->execute(['installation'=>$installation]);if(!$active->fetchColumn())throw new RuntimeException('not_found');
            $counts=['core_profiles'=>0,'profiles'=>0,'configurations'=>0,'connector_selections'=>0];

            $coreRows=$document['data']['core_profiles']??[];
            if($coreRows!==[]){
                $this->db->prepare('UPDATE core_profiles SET default_npc=false,slot=NULL WHERE installation_id=:installation')
                    ->execute(['installation'=>$installation]);
                foreach($coreRows as$row){$id=(string)$row['core_profile_id'];
                    $find=$this->db->prepare('SELECT installation_id,current_revision FROM core_profiles WHERE core_profile_id=:id FOR UPDATE');
                    $find->execute(['id'=>$id]);$existing=$find->fetch();
                    if($existing&&$existing['installation_id']!==$installation)throw new RuntimeException('backup_scope_conflict');
                    if($existing){$next=(int)$existing['current_revision']+1;
                        $this->db->prepare('UPDATE core_profiles SET label=:label,default_npc=:default,slot=:slot,current_revision=:revision,deleted_at=NULL WHERE core_profile_id=:id')
                            ->execute(['label'=>$row['label'],'default'=>$row['default_npc']?'true':'false','slot'=>$row['slot'],'revision'=>$next,'id'=>$id]);
                    }else{$next=1;$this->db->prepare('INSERT INTO core_profiles(core_profile_id,installation_id,label,default_npc,slot,current_revision,created_at) '
                        .'VALUES(:id,:installation,:label,:default,:slot,1,:now)')->execute(['id'=>$id,'installation'=>$installation,
                            'label'=>$row['label'],'default'=>$row['default_npc']?'true':'false','slot'=>$row['slot'],'now'=>$now]);}
                    $this->revision('core_profile_revisions','core_profile_id',$id,$next,$row['content'],'configuration backup restore',$now);$counts['core_profiles']++;}
            }
            $legacyDefault=$coreRows===[]?$this->defaultCoreProfileForInstallation($installation):null;

            foreach($document['data']['profiles'] as$row){$id=(string)$row['profile_id'];
                $coreProfileId=$row['core_profile_id']??($legacyDefault['core_profile_id']??null);
                $find=$this->db->prepare('SELECT installation_id,current_revision FROM profiles WHERE profile_id=:id FOR UPDATE');
                $find->execute(['id'=>$id]);$existing=$find->fetch();
                if($existing&&$existing['installation_id']!==$installation)throw new RuntimeException('backup_scope_conflict');
                if($existing){$next=(int)$existing['current_revision']+1;
                    $this->db->prepare('UPDATE profiles SET core_profile_id=:core,name=:name,actor_identity=CAST(:identity AS jsonb),current_revision=:revision,deleted_at=NULL WHERE profile_id=:id')
                        ->execute(['core'=>$coreProfileId,'name'=>$row['name'],'identity'=>$this->encode($row['actor_identity']),'revision'=>$next,'id'=>$id]);
                }else{$next=1;$this->db->prepare('INSERT INTO profiles(profile_id,installation_id,core_profile_id,name,actor_identity,current_revision,created_at) '
                    .'VALUES(:id,:installation,:core,:name,CAST(:identity AS jsonb),1,:now)')->execute(['id'=>$id,'installation'=>$installation,
                        'core'=>$coreProfileId,'name'=>$row['name'],'identity'=>$this->encode($row['actor_identity']),'now'=>$now]);}
                $this->revision('profile_revisions','profile_id',$id,$next,$row['content'],'configuration backup restore',$now);$counts['profiles']++;}

            foreach($document['data']['configurations'] as$row){$id=(string)$row['configuration_id'];
                $find=$this->db->prepare('SELECT installation_id,current_revision FROM configuration_sets WHERE configuration_id=:id FOR UPDATE');
                $find->execute(['id'=>$id]);$existing=$find->fetch();
                if($existing&&$existing['installation_id']!==$installation)throw new RuntimeException('backup_scope_conflict');
                if($existing){$next=(int)$existing['current_revision']+1;
                    $this->db->prepare('UPDATE configuration_sets SET profile_id=:profile,kind=:kind,name=:name,current_revision=:revision,deleted_at=NULL WHERE configuration_id=:id')
                        ->execute(['profile'=>$row['profile_id'],'kind'=>$row['kind'],'name'=>$row['name'],'revision'=>$next,'id'=>$id]);
                }else{$next=1;$this->db->prepare('INSERT INTO configuration_sets(configuration_id,installation_id,profile_id,kind,name,current_revision,created_at) '
                    .'VALUES(:id,:installation,:profile,:kind,:name,1,:now)')->execute(['id'=>$id,'installation'=>$installation,
                        'profile'=>$row['profile_id'],'kind'=>$row['kind'],'name'=>$row['name'],'now'=>$now]);}
                $this->revision('configuration_revisions','configuration_id',$id,$next,$row['content'],'configuration backup restore',$now);$counts['configurations']++;}

            $this->db->prepare('DELETE FROM installation_provider_selections WHERE installation_id=:installation')
                ->execute(['installation'=>$installation]);
            $insert=$this->db->prepare('INSERT INTO installation_provider_selections(installation_id,provider_kind,configuration_id,updated_at) '
                .'VALUES(:installation,:kind,:configuration,:now)');
            foreach($document['data']['connector_selections'] as$row){$insert->execute(['installation'=>$installation,
                'kind'=>$row['provider_kind'],'configuration'=>$row['configuration_id'],'now'=>$now]);$counts['connector_selections']++;}
            $this->setProfileAutoLock($installation,(bool)$document['data']['preferences']['auto_lock_on_edit'],$now);
            return$counts;
        });
    }

    public function markConfigurationBackupRestored(string $backupId,string $now):void
    {
        $statement=$this->db->prepare("UPDATE backup_records SET state='restored',restored_at=:now WHERE backup_id=:id AND state IN ('created','restored')");
        $statement->execute(['now'=>$now,'id'=>$backupId]);if($statement->rowCount()!==1)throw new RuntimeException('not_found');
    }

    /** Select a server-owned model slot for future turns in the active session. */
    public function selectSessionProvider(array $session, ?string $configurationId): void
    {
        $this->transaction(function()use($session,$configurationId):void{
            if($configurationId!==null){$slot=$this->db->prepare("SELECT 1 FROM configuration_sets WHERE configuration_id=:id "
                ."AND installation_id=:installation AND kind='provider' AND deleted_at IS NULL");
                $slot->execute(['id'=>$configurationId,'installation'=>$session['installation_id']]);
                if(!$slot->fetchColumn())throw new \OutOfBoundsException('not_found');}
            $update=$this->db->prepare("UPDATE sessions SET provider_configuration_id=:provider WHERE session_id=:session "
                ."AND generation=:generation AND state='active'");
            $update->execute(['provider'=>$configurationId,'session'=>$session['session_id'],'generation'=>$session['generation']]);
            if($update->rowCount()!==1)throw new \OutOfBoundsException('unknown_session');
        });
    }

    /** Assign or clear a roleplay profile for one stable OpenMW actor identity. */
    public function bindActorProfile(array $session, array $target, ?string $profileId, string $now): void
    {
        $key=$this->actorKey($target);
        $this->transaction(function()use($session,$target,$profileId,$now,$key):void{
            if($profileId===null){$delete=$this->db->prepare('DELETE FROM actor_profile_bindings WHERE installation_id=:installation '
                .'AND playthrough_id=:playthrough AND actor_key=:key');$delete->execute(['installation'=>$session['installation_id'],
                    'playthrough'=>$session['playthrough_id'],'key'=>$key]);return;}
            $profile=$this->db->prepare('SELECT 1 FROM profiles WHERE profile_id=:profile AND installation_id=:installation AND deleted_at IS NULL');
            $profile->execute(['profile'=>$profileId,'installation'=>$session['installation_id']]);
            if(!$profile->fetchColumn())throw new \OutOfBoundsException('not_found');
            $upsert=$this->db->prepare('INSERT INTO actor_profile_bindings '
                .'(installation_id,playthrough_id,actor_key,actor_identity,profile_id,created_at,updated_at) '
                .'VALUES (:installation,:playthrough,:key,CAST(:identity AS jsonb),:profile,:now,:now) '
                .'ON CONFLICT (installation_id,playthrough_id,actor_key) DO UPDATE SET actor_identity=EXCLUDED.actor_identity,'
                .'profile_id=EXCLUDED.profile_id,updated_at=EXCLUDED.updated_at');
            $upsert->execute(['installation'=>$session['installation_id'],'playthrough'=>$session['playthrough_id'],
                'key'=>$key,'identity'=>$this->encode($target),'profile'=>$profileId,'now'=>$now]);
        });
    }

    /** Snapshot the selected model slot without returning any server credential material. */
    public function providerContext(array $turn): ?array
    {
        $statement=$this->db->prepare("SELECT c.configuration_id,c.current_revision,r.content FROM sessions s "
            ."JOIN configuration_sets c ON c.configuration_id=s.provider_configuration_id AND c.installation_id=s.installation_id "
            ."JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision "
            ."WHERE s.session_id=:session AND s.generation=:generation AND s.state='active' "
            ."AND c.kind='provider' AND c.deleted_at IS NULL");
        $statement->execute(['session'=>$turn['session_id'],'generation'=>$turn['generation']]);$row=$statement->fetch();
        if($row)return ['configuration_id'=>(string)$row['configuration_id'],'revision'=>(int)$row['current_revision'],
            'content'=>$this->json($row['content'])];
        $target=$turn['payload']['target']??null;if(!is_array($target)||array_is_list($target))return null;
        $routing=$this->routingForActor((string)$turn['installation_id'],(string)$turn['playthrough_id'],$target);
        $fields=['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id'];
        $configured=array_values(array_filter($fields,static fn(string$field):bool=>trim((string)($routing[$field]??''))!==''));
        if($configured===[])return null;$field=$configured[0];
        if($this->routingFlag($routing,'llm_randomizer_enabled')&&count($configured)>1){
            $turnId=(string)($turn['turn_id']??'');$index=(int)(hexdec(substr(hash('sha256',$turnId),0,8))%count($configured));$field=$configured[$index];
        }
        return$this->connectorForActor((string)$turn['installation_id'],(string)$turn['playthrough_id'],$target,'provider',$field);
    }

    /** Resolve an enabled profile fallback that differs from the already selected primary connector. */
    public function fallbackProviderContext(array $turn,?string $primaryConfigurationId):?array
    {
        $target=$turn['payload']['target']??null;if(!is_array($target)||array_is_list($target))return null;
        $routing=$this->routingForActor((string)$turn['installation_id'],(string)$turn['playthrough_id'],$target);
        if(!$this->routingFlag($routing,'llm_fallback_enabled'))return null;
        $fallback=$this->connectorForActor((string)$turn['installation_id'],(string)$turn['playthrough_id'],$target,
            'provider','llm_fallback_configuration_id');
        return$fallback!==null&&($fallback['configuration_id']??null)!==$primaryConfigurationId?$fallback:null;
    }

    /** Resolve installation controls, inherited extractor route, and bounded conversation input for one turn. */
    public function oghmaRuntime(array $turn):array
    {
        $installation=(string)$turn['installation_id'];$target=$turn['payload']['target']??null;
        $effective=is_array($target)&&!array_is_list($target)
            ?$this->effectiveSettingsForActor($installation,(string)$turn['playthrough_id'],$target)
            :$this->effectiveSettingsForProfile($installation,null);
        $settings=(array)($effective['settings']['oghma']??[]);
        $parts=[];$input=trim((string)($turn['payload']['input']['text']??''));if($input!=='')$parts[]='Current player input: '.$input;
        $origin=$turn['payload']['rechat']['origin_line']??$turn['payload']['origin_line']??null;
        if(is_string($origin)&&trim($origin)!=='')$parts[]='Conversation origin: '.trim($origin);
        $history=$this->db->prepare("SELECT e.type,e.data FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL AND m.turn_id IS DISTINCT FROM :turn AND e.type IN ('inputtext','chat','rechat') ORDER BY e.rowid DESC LIMIT 8");
        $history->execute(['installation'=>$installation,'playthrough'=>$turn['playthrough_id'],'turn'=>$turn['turn_id']??null]);
        $historyRows=$history->fetchAll();
        foreach(array_reverse($historyRows)as$row){$text=trim((string)$row['data']);if($text!=='')$parts[]=(string)$row['type'].': '.$text;}
        $context=implode("\n",$parts);if(strlen($context)>16_384)$context=mb_strcut($context,0,16_384,'UTF-8');
        $groundingText=$input!==''?$input:(is_string($origin)?trim($origin):'');
        $result=['settings'=>$settings,'connector'=>null,'context'=>$context,'grounding_text'=>$groundingText,
            'status'=>($settings['extractor_fallback_enabled']??false)?'unconfigured':'disabled'];
        if(!$settings['enabled'])return array_merge($result,['status'=>'disabled']);
        if(!($settings['extractor_fallback_enabled']??false))return$result;
        if(!is_array($target)||array_is_list($target))return$result;
        $routing=$effective['routing'];
        $field=array_key_exists('oghma_configuration_id',$routing)?'oghma_configuration_id':'llm_fast_configuration_id';
        if(trim((string)($routing[$field]??''))==='')return$result;
        $connector=$this->connectorForActor($installation,(string)$turn['playthrough_id'],$target,'provider',$field);
        if($connector===null)return$result;
        return array_merge($result,['connector'=>$connector,'status'=>'ready']);
    }

    /** Ground the current turn locally before any optional Oghma provider fallback is considered. */
    public function groundedOghmaExtraction(array $turn):array
    {
        $runtime=$this->oghmaRuntime($turn);
        if(!($runtime['settings']['enabled']??true))return['status'=>'disabled','topics'=>[],'matches'=>[],'rejected'=>[],
            'tag_decisions'=>[],'fallback_eligible'=>false,'request_eligible'=>false,'runtime'=>$runtime];
        if(!OghmaGroundedRetriever::isEligibleTurn($turn))return['status'=>'ineligible','topics'=>[],'matches'=>[],'rejected'=>[],
            'tag_decisions'=>[],'fallback_eligible'=>false,'request_eligible'=>false,'runtime'=>$runtime];
        $catalog=$this->oghmaCatalogForTurn($turn);$retriever=new OghmaGroundedRetriever($catalog);
        $groundingText=(string)($runtime['grounding_text']??'');
        $result=$retriever->extract($groundingText,[],(int)($runtime['settings']['topic_count']??1));
        $contextFallback=['eligible'=>$result['topics']===[]&&OghmaGroundedRetriever::shouldUsePreviousExchange($groundingText),
            'attempted'=>false,'used'=>false];
        $previousExchange=$contextFallback['eligible']?$this->previousOghmaExchange($turn):'';
        if($contextFallback['eligible']&&$previousExchange!==''){$contextFallback['attempted']=true;
            $previousResult=$retriever->extract($previousExchange,[],1);
            if($previousResult['topics']!==[]){foreach($previousResult['matches']as&$match)$match['context_source']='previous_exchange';unset($match);
                $result=$previousResult;$contextFallback['used']=true;}}
        return array_merge($result,['status'=>$result['topics']===[]?'no_match':'grounded','request_eligible'=>true,
            'context_fallback'=>$contextFallback,'runtime'=>$runtime]);
    }

    /** Return the previous two dialogue lines only when they belong to the current actor. */
    private function previousOghmaExchange(array $turn):string
    {
        $actor=$turn['payload']['target']??null;if(!is_array($actor)||array_is_list($actor))return'';
        $actorKey=[];foreach(['kind','record_id','content_file']as$field){if(is_string($actor[$field]??null)&&$actor[$field]!=='')$actorKey[$field]=$actor[$field];}
        if(is_array($actor['refnum']??null)&&!array_is_list($actor['refnum'])){$refnum=[];
            foreach(['index','content_file']as$field)if(is_int($actor['refnum'][$field]??null))$refnum[$field]=$actor['refnum'][$field];
            if($refnum!==[])$actorKey['refnum']=$refnum;}
        if(!isset($actorKey['record_id'],$actorKey['content_file']))return'';
        $actorJson=$this->encode($actorKey);$audienceJson=$this->encode([$actorKey]);
        $statement=$this->db->prepare("SELECT e.data FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid "
            ."WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL "
            ."AND m.turn_id IS DISTINCT FROM :turn AND e.type IN ('inputtext','chat','rechat') "
            ."AND (m.speaker @> CAST(:actor AS jsonb) OR m.target @> CAST(:actor AS jsonb) OR m.audience @> CAST(:audience AS jsonb)) "
            ."ORDER BY e.rowid DESC LIMIT 6");
        $statement->execute(['installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
            'turn'=>$turn['turn_id']??null,'actor'=>$actorJson,'audience'=>$audienceJson]);
        $parts=[];$seen=[];$currentText=trim((string)($turn['payload']['input']['text']??''));
        $current=mb_strtolower(preg_replace('/\s+/u',' ',$currentText)??$currentText,'UTF-8');
        foreach($statement->fetchAll()as$row){$text=trim((string)$row['data']);$key=mb_strtolower(preg_replace('/\s+/u',' ',$text)??$text,'UTF-8');
            if($text===''||$key===$current||isset($seen[$key]))continue;$seen[$key]=true;$parts[]=$text;if(count($parts)>=2)break;}
        return mb_strcut(implode("\n",array_reverse($parts)),0,4096,'UTF-8');
    }

    /** Resolve fallback connector output against the exact catalog visible to the current NPC. */
    public function resolveOghmaSuggestions(array $turn,array $suggestions,int $limit):array
    {
        return(new OghmaGroundedRetriever($this->oghmaCatalogForTurn($turn)))->resolveSuggestions($suggestions,[],$limit);
    }

    /** Return the effective catalog rows visible to the actor selected for this turn. */
    private function oghmaCatalogForTurn(array $turn):array
    {
        $selected=$this->selectedActorProfileId((string)$turn['installation_id'],(string)$turn['playthrough_id'],
            (array)($turn['payload']['target']??[]));
        $statement=$this->db->prepare($this->effectiveKnowledgeSql('topic,aliases,tags,category',true));
        $statement->execute(['installation'=>(string)$turn['installation_id'],
            'profile'=>$selected??(string)$turn['profile_id'],'playthrough'=>(string)$turn['playthrough_id'],
            'loaded_content_files'=>$this->pgArray(array_keys($this->contentFilesForTurn($turn)))]);
        return$statement->fetchAll();
    }

    /** Select one effective row per canonical topic, preferring the most specific custom override. */
    private function effectiveKnowledgeSql(string $columns,bool $filterModSources=false):string
    {
        $modFilter=$filterModSources
            ?" AND (d.provenance->>'source' IS DISTINCT FROM 'factory-oghma' OR COALESCE(d.provenance->>'mod_source','')='' OR lower(d.provenance->>'mod_source')=ANY(CAST(:loaded_content_files AS text[])))"
            :'';
        return'SELECT '.$columns.' FROM (SELECT d.*,ROW_NUMBER() OVER (PARTITION BY lower(d.topic) ORDER BY '
            ."CASE WHEN d.provenance->>'source'='factory-oghma' THEN 0 ELSE 1 END DESC,"
            .'CASE WHEN d.playthrough_id IS NULL THEN 0 ELSE 1 END DESC,'
            .'CASE WHEN d.profile_id IS NULL THEN 0 ELSE 1 END DESC,d.created_at DESC,d.document_id DESC) AS effective_rank '
            .'FROM knowledge_documents d WHERE d.installation_id=:installation AND d.deleted_at IS NULL '
            .'AND (d.profile_id IS NULL OR d.profile_id=:profile) AND (d.playthrough_id IS NULL OR d.playthrough_id=:playthrough)'
            .$modFilter.') effective '
            .'WHERE effective_rank=1 ORDER BY created_at DESC,document_id';
    }

    public function promptContext(array $turn,string $now,array $oghmaExtraction=[]): array
    {
        $scope = ['installation_id'=>$turn['installation_id'],'profile_id'=>$turn['profile_id'],'playthrough_id'=>$turn['playthrough_id']];
        $selectedProfileId=$this->selectedActorProfileId($turn['installation_id'],$turn['playthrough_id'],$turn['payload']['target']);
        $activeProfileId=$selectedProfileId??$turn['profile_id'];
        $profile = $this->getRevisioned('profile', $activeProfileId);
        $effective=$this->effectiveSettingsForProfile((string)$turn['installation_id'],$activeProfileId);
        $routing=$effective['routing'];
        $selectedPrompt=(string)($routing['prompt_configuration_id']??'');$prompt=false;
        if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$selectedPrompt)===1){
            $promptStmt=$this->db->prepare("SELECT c.configuration_id,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.configuration_id=:prompt AND c.installation_id=:installation AND c.kind='prompt' AND c.deleted_at IS NULL");
            $promptStmt->execute(['prompt'=>$selectedPrompt,'installation'=>$turn['installation_id']]);$prompt=$promptStmt->fetch();
        }
        if(!$prompt){$promptStmt = $this->db->prepare("SELECT c.configuration_id,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind='prompt' AND c.deleted_at IS NULL AND (c.profile_id=:actor_profile OR c.profile_id=:session_profile OR c.profile_id IS NULL) ORDER BY CASE WHEN c.profile_id=:actor_profile THEN 0 WHEN c.profile_id=:session_profile THEN 1 ELSE 2 END,c.name,c.configuration_id LIMIT 1");
            $promptStmt->execute(['installation'=>$turn['installation_id'],'actor_profile'=>$activeProfileId,'session_profile'=>$turn['profile_id']]);$prompt = $promptStmt->fetch();}
        if (!$prompt) {
            $prompt = ['configuration_id'=>$activeProfileId,'revision'=>(int)$profile['current_revision'],'content'=>['instruction'=>'Respond in character using only scoped context.']];
        } else {
            $prompt['revision']=(int)$prompt['revision'];$prompt['content']=$this->json($prompt['content']);
        }
        $promptOverride=$this->db->prepare('SELECT default_prompt,custom_prompt,description FROM prompts WHERE installation_id=:installation AND source_configuration_id=:configuration');
        $promptOverride->execute(['installation'=>$turn['installation_id'],'configuration'=>$prompt['configuration_id']]);
        if($override=$promptOverride->fetch()){
            $effectivePrompt=trim((string)($override['custom_prompt']??''));
            if($effectivePrompt==='')$effectivePrompt=(string)$override['default_prompt'];
            if($effectivePrompt!=='')$prompt['content']['instruction']=$effectivePrompt;
            $prompt['content']['description']=(string)$override['description'];
        }
        $profile['revision']=(int)$profile['current_revision'];
        $coreProfile=$effective['core_profile'];
        if(is_array($coreProfile)){
            $coreContent=is_array($coreProfile['content']??null)?$coreProfile['content']:[];
            $coreProfile['content']=['prompt'=>(string)($coreContent['prompt']??'')];
        }
        $knowledgeScope=$scope;$knowledgeScope['profile_id']=$activeProfileId;
        $knowledgeSelection=$this->selectPromptKnowledge($turn,$profile,$knowledgeScope,
            $this->knowledgeCandidates($knowledgeScope,array_keys($this->contentFilesForTurn($turn))),
            (string)($effective['settings']['memory']['oghma_knowledge_tags']??''),
            (int)($effective['settings']['oghma']['result_limit']??3),(array)($effective['settings']['oghma']??[]),$oghmaExtraction,$now);
        $knowledgeSelection['trace']['settings']=$effective['settings']['oghma']??[];
        $knowledgeSelection['trace']['settings_sources']=array_filter($effective['sources'],static fn(string$key):bool=>
            str_starts_with($key,'settings.oghma.')||$key==='settings.memory.oghma_knowledge_tags'||$key==='routing.oghma_configuration_id',ARRAY_FILTER_USE_KEY);
        $knowledge=$knowledgeSelection['rows'];
        $relationships=$this->relationships($scope);usort($relationships,fn($a,$b)=>strcmp((string)$a['relationship_id'],(string)$b['relationship_id']));
        $narratives=$this->narratives($scope);usort($narratives,fn($a,$b)=>strcmp((string)$a['narrative_id'],(string)$b['narrative_id']));
        $actions=$this->db->prepare('SELECT r.action_id,r.status,r.reason_code,r.observed,r.completed_at FROM action_results r JOIN action_intents a ON a.action_id=r.action_id WHERE a.session_id=:session ORDER BY r.completed_at DESC,r.action_id LIMIT 16');
        $actions->execute(['session'=>$turn['session_id']]);
        $recent=array_map(function($r){$r['observed']=$this->json($r['observed']);return$r;},$actions->fetchAll());
        $actor=(array)$turn['payload']['target'];
        $actorKey=[];
        foreach(['kind','record_id','content_file']as$field){if(is_string($actor[$field]??null)&&$actor[$field]!=='')$actorKey[$field]=$actor[$field];}
        if(is_array($actor['refnum']??null)&&!array_is_list($actor['refnum'])){
            $refnum=[];foreach(['index','content_file']as$field)if(is_int($actor['refnum'][$field]??null))$refnum[$field]=$actor['refnum'][$field];
            if($refnum!==[])$actorKey['refnum']=$refnum;
        }
        if(!isset($actorKey['record_id'],$actorKey['content_file']))throw new RuntimeException('invalid_actor_identity');
        $actorJson=$this->encode($actorKey);$audienceJson=$this->encode([$actorKey]);
        $ownsProfile=$selectedProfileId!==null || $this->actorKey((array)$profile['actor_identity'])===$this->actorKey($actor);
        $memorySelection=$this->selectPromptMemories($turn,$scope,
            $this->promptMemoryCandidates($turn,$actorKey,$activeProfileId,$ownsProfile,$now),$now);
        $memories=$memorySelection['rows'];
        $recentTurnLimit=(int)($effective['settings']['memory']['recent_turn_limit']??20);
        $historyStatement=$this->db->prepare(<<<'SQL'
SELECT 'event:'||e.rowid::text AS id,
       m.turn_id,
       COALESCE(e.ts,NULLIF(e.gamets,0),(extract(epoch FROM m.created_at)*1000)::bigint) AS sort_ts,
       m.created_at AS sort_created_at,CASE WHEN e.type='chat' THEN 1 ELSE 0 END AS source_rank,e.rowid AS sort_id,
       CASE WHEN e.type='chat' THEN
           jsonb_strip_nulls(jsonb_build_object(
               'kind','speech','turn_id',m.turn_id,
               'speaker',COALESCE(m.speaker->>'display_name',m.speaker->>'record_id'),
               'listener',COALESCE(m.target->>'display_name',m.target->>'record_id'),
               'text',COALESCE(m.payload->>'text',e.data),
               'speaker_identity',CASE WHEN m.speaker='{}'::jsonb THEN NULL ELSE m.speaker END,
               'listener_identity',CASE WHEN m.target='{}'::jsonb THEN NULL ELSE m.target END,
               'audience',CASE WHEN jsonb_array_length(m.audience)=0 THEN NULL ELSE m.audience END,
               'location',e.location,'game_time',NULLIF(e.gamets,0),'event_time',e.ts,
               'delivery_state',e.delivery_state))
       ELSE
           jsonb_strip_nulls(jsonb_build_object(
               'kind','event','type',e.type,'turn_id',m.turn_id,
               'input',CASE WHEN e.type IN ('inputtext','rechat') THEN m.payload->'input' END,
               'details',CASE
                   WHEN e.type='location' THEN jsonb_strip_nulls(jsonb_build_object('location',e.location,'game_time',NULLIF(e.gamets,0)))
                   WHEN e.type='weather' THEN jsonb_strip_nulls(jsonb_build_object('weather',COALESCE(m.payload->>'weather',replace(e.data,'Weather changed to ',''))))
                   WHEN e.type IN ('quest','book','death','infoaction','narration') THEN m.payload
                   ELSE NULL END,
               'speaker',CASE WHEN m.speaker='{}'::jsonb THEN NULL ELSE m.speaker END,
               'target',CASE WHEN m.target='{}'::jsonb THEN NULL ELSE m.target END,
               'audience',CASE WHEN jsonb_array_length(m.audience)=0 THEN NULL ELSE m.audience END,
               'location',e.location,'game_time',NULLIF(e.gamets,0),'event_time',e.ts))
       END AS content
FROM eventlog e
JOIN eventlog_metadata m ON m.rowid=e.rowid
WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL
  AND m.turn_id IS DISTINCT FROM :current_turn
  AND e.type IN ('inputtext','chat','location','weather','death','infoaction','rechat','narration','quest','book')
  AND (e.type<>'chat' OR e.delivery_state IN ('emitted','pending','spoken','played'))
  AND (m.speaker @> CAST(:event_speaker AS jsonb)
       OR m.target @> CAST(:event_target AS jsonb)
       OR m.audience @> CAST(:event_audience AS jsonb))
ORDER BY sort_ts DESC,sort_created_at DESC,source_rank DESC,sort_id DESC
LIMIT :candidate_limit
SQL);
        $historyStatement->execute([
            'installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
            'current_turn'=>$turn['turn_id']??null,
            'event_speaker'=>$actorJson,'event_target'=>$actorJson,'event_audience'=>$audienceJson,
            'candidate_limit'=>min(500,max(40,$recentTurnLimit*5)),
        ]);
        // Count conversation turns, not individual input, response, and world-event rows.
        $history=[];$historyTurns=[];
        foreach($historyStatement->fetchAll()as$row){
            $turnKey=(string)($row['turn_id']??$row['id']);
            if(!isset($historyTurns[$turnKey])&&count($historyTurns)>=$recentTurnLimit)continue;
            $historyTurns[$turnKey]=true;
            $history[]=['id'=>(string)$row['id'],'installation_id'=>$turn['installation_id'],
                'playthrough_id'=>$turn['playthrough_id'],'created_at'=>(string)$row['sort_created_at'],
                'content'=>$this->json($row['content'])];
        }
        $history=array_reverse($history);
        return ['profile'=>$profile,'core_profile'=>$coreProfile,'selected_profile_id'=>$activeProfileId,
            'effective_settings'=>['sha256'=>$effective['sha256'],'sources'=>$effective['sources']],
            'player_profile'=>$this->playerProfileForInstallation($turn['installation_id']),
            'narrator_profile'=>$this->narratorProfileForInstallation($turn['installation_id']),
            'nearby_actor_profiles'=>$this->nearbyActorProfilesForTurn($turn),
            'item_descriptions'=>$this->itemDescriptionsForTurn($turn),
            'prompt'=>$prompt,'history'=>$history,'memory'=>array_slice($memories,0,10),'memory_retrieval'=>$memorySelection['trace'],
            'relationship'=>array_slice($relationships,0,10),'knowledge'=>$knowledge,'knowledge_retrieval'=>$knowledgeSelection['trace'],
            'narrative'=>array_slice($narratives,0,10),'recent_action_results'=>$recent];
    }

    /** Rank extracted and forced Oghma topics, enforce access classes, and retain every bounded decision. */
    private function selectPromptKnowledge(array $turn,array $profile,array $scope,array $rows,string $tagList,int $limit,array $settings,array $extraction,string $now):array
    {
        $topicCount=max(1,min(3,(int)($settings['topic_count']??1)));
        $status=(string)($extraction['status']??'not_run');
        $active=($settings['enabled']??true)===true&&!in_array($status,['disabled','ineligible','unavailable'],true);
        $topics=[];if($active)foreach(($extraction['topics']??[])as$topic)if(is_string($topic)&&trim($topic)!==''&&mb_strlen(trim($topic),'UTF-8')<=128)$topics[]=trim($topic);
        $topics=array_slice(array_values(array_unique($topics)),0,$topicCount);$conversation=$topics;
        $signals=$active?$this->forcedKnowledgeSignals($turn,$profile,$settings):['race'=>[],'location'=>[]];
        $knowledgeTags=$this->knowledgeValues($tagList);$limit=max(0,min(5,$limit));
        $selected=[];$selectedIds=[];$scores=[];$reasons=[];$rank=0;
        foreach([['conversation',$conversation,0.01],['location',$signals['location'],0.95],['race',$signals['race'],0.95]]as[$source,$sourceSignals,$minimum]){
            if(count($selected)>=$limit)break;
            foreach($sourceSignals as$signal){$ranked=[];foreach($rows as$row){$score=$this->knowledgeRelevance($signal,$row);if($score<$minimum)continue;$row['_prompt_score']=$score;$ranked[]=$row;}
                usort($ranked,static fn(array$a,array$b):int=>($b['_prompt_score']<=>$a['_prompt_score'])?:strcmp((string)$a['topic'],(string)$b['topic'])?:strcmp((string)$a['id'],(string)$b['id']));
                if(($ranked[0]['_prompt_score']??0.0)>=0.95)$ranked=array_values(array_filter($ranked,static fn(array$row):bool=>$row['_prompt_score']>=0.95));
                foreach(array_slice($ranked,0,10)as$row){$id=(string)$row['id'];$score=(float)$row['_prompt_score'];$scores[$id]=max((float)($scores[$id]??0),$score);$accessDecision=OghmaGroundedRetriever::accessDecision($row,$knowledgeTags);$access=$accessDecision['level'];$rank++;
                    if(isset($selectedIds[$id])){$reasons[$id]['additional_signals'][]=['signal'=>$signal,'source'=>$source,'score'=>$score];continue;}
                    if($access==='denied'){$reasons[$id]=['rank'=>$rank,'topic'=>$row['topic'],'signal'=>$signal,'source'=>$source,'selected'=>false,'access_level'=>'denied','score'=>$scores[$id],'reason'=>$accessDecision['reason']];
                        if(count($selected)<$limit){$row['content']='';$row['access_level']='denied';$row['source']=$source;$row['access_reason']=$accessDecision['reason'];unset($row['_prompt_score']);
                            $selected[]=$row;$selectedIds[$id]='denied';$reasons[$id]['selected']=true;$reasons[$id]['reason']='denied topic included as structured prompt context';break;}
                        continue;}
                    if(count($selected)>=$limit){$reasons[$id]=['rank'=>$rank,'topic'=>$row['topic'],'signal'=>$signal,'source'=>$source,'selected'=>false,'access_level'=>$access,'score'=>$scores[$id],'reason'=>'knowledge result limit'];break;}
                    $row['content']=$access==='advanced'?(string)$row['content']:(string)$row['topic_desc_basic'];$row['access_level']=$access;$row['source']=$source;$row['access_reason']=$accessDecision['reason'];unset($row['_prompt_score']);
                    $selected[]=$row;$selectedIds[$id]=$access;$reasons[$id]=['rank'=>$rank,'topic'=>$row['topic'],'signal'=>$signal,'source'=>$source,'selected'=>true,'access_level'=>$access,'score'=>$scores[$id],'reason'=>$access.' knowledge class authorized'];break;
                }
            }
        }
        $query=mb_strcut(implode(' | ',array_merge($conversation,$signals['location'],$signals['race'])),0,4096,'UTF-8');
        $deniedTopics=[];foreach($selected as$row)if(($row['access_level']??null)==='denied')$deniedTopics[]=(string)$row['topic'];
        $reasons['_context']=['algorithm_version'=>OghmaGroundedRetriever::VERSION,'master_enabled'=>($settings['enabled']??true)===true,
            'request_eligible'=>($extraction['request_eligible']??false)===true,'topic_count'=>$topicCount,'knowledge_limit'=>$limit,
            'extracted_topics'=>$topics,'denied_topics'=>$deniedTopics,
            'extractor_status'=>(string)($extraction['status']??(($settings['extractor_fallback_enabled']??false)?'not_run':'disabled')),
            'extractor_configuration_id'=>$extraction['configuration_id']??null,'conversation_signals'=>$conversation,
            'grounded_matches'=>$extraction['matches']??[],'grounded_rejections'=>$extraction['rejected']??[],
            'tag_decisions'=>$extraction['tag_decisions']??[],
            'context_fallback'=>$extraction['context_fallback']??['eligible'=>false,'attempted'=>false,'used'=>false],
            'fallback_eligible'=>($extraction['fallback_eligible']??false)===true,'suggested_topics'=>$extraction['suggested_topics']??[],
            'race_signals'=>$signals['race'],'location_signals'=>$signals['location'],
            'racial_context_enabled'=>($settings['racial_context_enabled']??false)===true,'location_context_enabled'=>($settings['location_context_enabled']??false)===true];
        $promptStatus=$selected===[]?$status:($status==='fallback_succeeded'?'fallback_succeeded':'grounded');
        return['rows'=>$selected,'trace'=>['domain'=>'knowledge','status'=>$promptStatus,'query'=>$query,'result_ids'=>array_column($selected,'id'),
            'scores'=>$scores,'reasons'=>$reasons,'algorithm'=>OghmaGroundedRetriever::VERSION,'created_at'=>$now,
            'prompt_section'=>'oghma_context','scope'=>$scope,'effective_knowledge_tags'=>$knowledgeTags]];
    }

    /** Extract canonical race names and stable named locations already present in trusted turn context. */
    private function forcedKnowledgeSignals(array $turn,array $profile,array $settings):array
    {
        $races=[];$locations=[];$regions=[];$add=static function(array&$values,mixed$value):void{
            if(!is_string($value))return;$value=trim($value);if($value!==''&&mb_strlen($value,'UTF-8')<=256&&!in_array($value,$values,true))$values[]=$value;};
        if(($settings['racial_context_enabled']??false)===true){
            $content=is_array($profile['content']??null)?$profile['content']:[];$target=$turn['payload']['target']??[];$context=$turn['payload']['context']??[];
            $race=$content['race']??(is_array($target)?($target['race']??null):null);$add($races,$this->canonicalRace($race));
            if(is_array($context)&&!array_is_list($context)){
                $targetState=$context['targetState']??[];if(is_array($targetState))$add($races,$this->canonicalRace($targetState['race']??($targetState['identity']['race']??null)));
                $nearby=$context['nearbyActors']??[];if(is_array($nearby)&&!array_is_list($nearby))$nearby=$nearby['items']??[];
                if(is_array($nearby))foreach(array_slice($nearby,0,12)as$actor)if(is_array($actor))$add($races,$this->canonicalRace($actor['race']??($actor['identity']['race']??null)));
            }
            foreach($this->nearbyActorProfilesForTurn($turn)as$nearbyProfile){$nearbyContent=$nearbyProfile['content']??[];if(is_array($nearbyContent))$add($races,$this->canonicalRace($nearbyContent['race']??null));}
        }
        if(($settings['location_context_enabled']??false)===true){$context=$turn['payload']['context']??[];
            if(is_array($context)&&!array_is_list($context)){
                $world=$context['world']??[];if(is_array($world)){$add($locations,$world['cell']??null);$add($locations,$world['location']??null);$add($regions,$world['region']??null);}
                $location=$context['location']??null;if(is_array($location)){$add($locations,$location['name']??null);$add($locations,$location['cell']??null);$add($regions,$location['region']??null);}else$add($locations,$location);
                $targetState=$context['targetState']??[];if(is_array($targetState)){$cell=$targetState['cell']??($targetState['identity']['cell']??null);if(is_array($cell))$add($locations,$cell['name']??null);else$add($locations,$cell);}
            }
            $target=$turn['payload']['target']??[];if(is_array($target)){ $cell=$target['cell']??null;if(is_array($cell))$add($locations,$cell['name']??null);}
        }
        return['race'=>$races,'location'=>array_values(array_unique(array_merge($locations,$regions)))];
    }

    private function canonicalRace(mixed $race):?string
    {
        if(!is_string($race)||trim($race)==='')return null;$value=trim($race);$key=mb_strtolower($value,'UTF-8');
        return['dark elf'=>'Dunmer','high elf'=>'Altmer','wood elf'=>'Bosmer','orc'=>'Orsimer'][$key]??$value;
    }

    private function knowledgeRelevance(string $query,array $row):float
    {
        $normalize=static fn(string$value):string=>trim((string)preg_replace('/\s+/u',' ',(string)preg_replace('/[^\p{L}\p{N}]+/u',' ',mb_strtolower(str_replace('_',' ',$value),'UTF-8'))));
        $queryNormalized=$normalize($query);if($queryNormalized==='')return 0.0;
        $labels=array_merge([(string)$row['topic'],(string)$row['title']],$this->knowledgeValues((string)$row['aliases']));
        $score=0.0;foreach($labels as$label){$label=$normalize($label);if($label==='')continue;if($queryNormalized===$label)$score=max($score,1.0);elseif(str_contains(' '.$queryNormalized.' ',' '.$label.' '))$score=max($score,0.95);}
        $queryTerms=array_values(array_diff(DeterministicRetrieval::terms($queryNormalized),[
            'about','and','are','could','for','from','how','into','me','of','please','tell','that','the','their','there',
            'these','they','this','what','when','where','which','who','with','would',
        ]));
        if($queryTerms===[])return$score;
        $signals=implode(' ',array_merge($labels,$this->knowledgeValues((string)$row['tags'])));$signalTerms=DeterministicRetrieval::terms($normalize($signals));
        $overlap=count(array_intersect($queryTerms,$signalTerms));if($overlap>0)$score=max($score,min(0.9,0.45+0.15*$overlap));
        return round($score,8);
    }

    private function knowledgeValues(string $value):array
    {
        $separator=str_contains($value,'|')?'/\s*\|\s*/u':'/\s*[,;]\s*/u';$values=preg_split($separator,$value)?:[];$result=[];foreach($values as$item){$item=trim($item);if($item!==''&&!in_array($item,$result,true))$result[]=$item;}return$result;
    }

    /** Rank turn memories deterministically and persist why each prompt source was selected. */
    private function selectPromptMemories(array $turn,array $scope,array $memories,string $now):array
    {
        $query=trim((string)($turn['payload']['input']['text']??''));
        if($query===''){
            $target=$turn['payload']['target']??[];
            $name=is_array($target)?trim((string)($target['display_name']??$target['record_id']??'')):'';
            $query='Continue the current conversation'.($name===''?'':' with '.$name);
        }
        $query=mb_strcut($query,0,4096,'UTF-8');
        foreach($memories as&$memory){
            $base=DeterministicRetrieval::score($query,$memory['lexical_terms'],$memory['fake_vector']);
            $tierBoost=match($memory['tier']??null){'recent'=>0.15,'mid'=>0.08,'long'=>0.03,default=>0.0};
            $memory['_prompt_score']=$base+$tierBoost;
        }
        unset($memory);
        usort($memories,static fn(array$a,array$b):int=>($b['_prompt_score']<=>$a['_prompt_score'])
            ?:strcmp((string)($b['occurred_at']??''),(string)($a['occurred_at']??''))
            ?:strcmp((string)$a['id'],(string)$b['id']));
        $selected=array_slice($memories,0,10);
        $scores=[];$reasons=[];
        foreach($selected as$rank=>&$memory){
            $scores[$memory['id']]=$memory['_prompt_score'];
            $reasons[$memory['id']]=['rank'=>$rank+1,'tier'=>$memory['tier'],'reason'=>'deterministic relevance plus tier recency'];
            unset($memory['_prompt_score']);
        }
        unset($memory);
        return['rows'=>$selected,'trace'=>['domain'=>'memory','query'=>$query,'result_ids'=>array_keys($scores),
            'scores'=>$scores,'reasons'=>$reasons,'algorithm'=>'prompt-memory-lexical-0.75+fake-vector-0.25+tier-v1',
            'created_at'=>$now,'prompt_section'=>'memory_context','scope'=>$scope]];
    }

    /** Batch-load only profiles already bound to actors in the bounded current-turn context. */
    public function nearbyActorProfilesForTurn(array $turn): array
    {
        $context=$turn['payload']['context']??[];
        if(!is_array($context)||array_is_list($context))return[];
        $nearby=$context['nearbyActors']??[];
        if(is_array($nearby)&&!array_is_list($nearby))$nearby=$nearby['items']??[];
        if(!is_array($nearby)||!array_is_list($nearby))return[];
        $keys=[];
        foreach(array_slice($nearby,0,12)as$actor){
            if(!is_array($actor)||array_is_list($actor))continue;
            try{$keys[$this->actorKey($actor)]=true;}catch(\Throwable){}
        }
        if($keys===[])return[];
        $statement=$this->db->prepare('SELECT b.actor_key,p.profile_id,p.name,p.actor_identity,p.current_revision AS revision,r.content '
            .'FROM actor_profile_bindings b JOIN profiles p ON p.profile_id=b.profile_id AND p.deleted_at IS NULL '
            .'JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision '
            .'WHERE b.installation_id=:installation AND b.playthrough_id=:playthrough '
            .'AND b.actor_key=ANY(CAST(:keys AS text[])) ORDER BY p.name,p.profile_id LIMIT 12');
        $statement->execute(['installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
            'keys'=>$this->pgArray(array_keys($keys))]);
        $rows=[];foreach($statement->fetchAll()as$row){$row['revision']=(int)$row['revision'];
            $row['actor_identity']=$this->json($row['actor_identity']);$row['content']=$this->json($row['content']);$rows[]=$row;}
        return$rows;
    }

    /** Return the single current player roleplay profile for an installation, if configured. */
    public function playerProfileForInstallation(string $installationId): ?array
    {
        $statement=$this->db->prepare("SELECT p.profile_id,p.name,p.actor_identity,p.current_revision AS revision,r.content "
            ."FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
            ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='player' "
            ."ORDER BY p.created_at,p.profile_id LIMIT 1");
        $statement->execute(['installation'=>$installationId]);$row=$statement->fetch();
        if(!$row)return null;
        $row['revision']=(int)$row['revision'];$row['actor_identity']=$this->json($row['actor_identity']);
        $row['content']=$this->json($row['content']);
        return$row;
    }

    /** Return the single current narrator persona and routing document for an installation. */
    public function narratorProfileForInstallation(string $installationId): ?array
    {
        $statement=$this->db->prepare("SELECT p.profile_id,p.name,p.actor_identity,p.current_revision AS revision,r.content "
            ."FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
            ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='narrator' "
            ."ORDER BY p.created_at,p.profile_id LIMIT 1");
        $statement->execute(['installation'=>$installationId]);$row=$statement->fetch();if(!$row)return null;
        $row['revision']=(int)$row['revision'];$row['actor_identity']=$this->json($row['actor_identity']);
        $row['content']=$this->json($row['content']);return$row;
    }

    /** Upsert one active description by installation, content file, and record ID. */
    public function saveItemDescription(array $input,string $now): array
    {
        return $this->saveItemDescriptions([$input],$now)[0];
    }

    /** Atomically upsert one bounded batch of installation-scoped item-description overrides. */
    public function saveItemDescriptions(array $inputs,string $now): array
    {
        return$this->transaction(function()use($inputs,$now):array{
            $saved=[];
            foreach($inputs as$input){
            $find=$this->db->prepare('SELECT description_id FROM item_descriptions WHERE installation_id=:installation AND lower(content_file)=lower(:content_file) AND lower(record_id)=lower(:record_id) AND deleted_at IS NULL');
            $find->execute(['installation'=>$input['installation_id'],'content_file'=>$input['content_file'],'record_id'=>$input['record_id']]);$id=$find->fetchColumn();
            if($id===false){$id=Uuid::v4();$statement=$this->db->prepare('INSERT INTO item_descriptions(description_id,installation_id,content_file,record_id,display_name,description,created_at,updated_at) VALUES(:id,:installation,:content_file,:record_id,:display_name,:description,:now,:now)');}
            else{$statement=$this->db->prepare('UPDATE item_descriptions SET content_file=:content_file,record_id=:record_id,display_name=:display_name,description=:description,updated_at=:now WHERE description_id=:id AND installation_id=:installation AND deleted_at IS NULL');}
            $statement->execute(['id'=>$id,'installation'=>$input['installation_id'],'content_file'=>trim((string)$input['content_file']),'record_id'=>trim((string)$input['record_id']),'display_name'=>trim((string)$input['display_name']),'description'=>trim((string)$input['description']),'now'=>$now]);
            $saved[]=['description_id'=>(string)$id,'updated_at'=>$now];
            }
            return$saved;
        });
    }

    public function deleteItemDescription(string $descriptionId,string $installationId,string $now): void
    {
        $statement=$this->db->prepare('UPDATE item_descriptions SET deleted_at=:now,updated_at=:now WHERE description_id=:id AND installation_id=:installation AND deleted_at IS NULL');
        $statement->execute(['id'=>$descriptionId,'installation'=>$installationId,'now'=>$now]);if($statement->rowCount()!==1)throw new RuntimeException('not_found');
    }

    /** Return custom description overrides in the exact CHIM CSV field order. */
    public function customItemDescriptions(string $installationId): array
    {
        $statement=$this->db->prepare('SELECT content_file AS plugin,record_id AS baseid,display_name AS name,description FROM item_descriptions WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY lower(content_file),lower(record_id)');
        $statement->execute(['installation'=>$installationId]);return$statement->fetchAll();
    }

    /** Soft-delete every active override for one installation so factory defaults become effective again. */
    public function resetItemDescriptions(string $installationId,string $now): int
    {
        return$this->transaction(function()use($installationId,$now):int{
            $statement=$this->db->prepare('UPDATE item_descriptions SET deleted_at=:now,updated_at=:now WHERE installation_id=:installation AND deleted_at IS NULL');
            $statement->execute(['installation'=>$installationId,'now'=>$now]);return$statement->rowCount();
        });
    }

    /** Resolve custom then shipped descriptions for canonical item identities in the active OpenMW manifest. */
    public function itemDescriptionsForTurn(array $turn): array
    {
        $context=$turn['payload']['context']??[];if(!is_array($context)||array_is_list($context))return[];
        $active=$this->contentFilesForTurn($turn);
        $wanted=[];foreach($this->turnItemRows($context)as$item){$record=strtolower(trim((string)($item['record_id']??'')));
            if($record===''||strlen($record)>256)continue;$content=strtolower(trim((string)($item['content_file']??'')));
            if(strlen($content)>256)continue;$wanted[$record][$content]=true;}
        if($wanted===[])return[];
        $statement=$this->db->prepare(<<<'SQL'
SELECT 'custom' AS description_source,d.description_id::text,d.content_file,d.record_id,d.display_name,d.description
FROM item_descriptions d
WHERE d.installation_id=:installation AND d.deleted_at IS NULL
 AND lower(d.record_id)=ANY(CAST(:records AS text[]))
UNION ALL
SELECT 'default',NULL,d.plugin,d.baseid,d.name,d.description
FROM public.descriptions d
WHERE lower(d.baseid)=ANY(CAST(:records AS text[]))
ORDER BY record_id,description_source DESC,content_file
SQL);
        $statement->execute(['installation'=>$turn['installation_id'],'records'=>$this->pgArray(array_keys($wanted))]);$grouped=[];
        foreach($statement->fetchAll()as$row){$content=strtolower((string)$row['content_file']);
            if($active!==[]&&!array_key_exists($content,$active))continue;$row['load_order']=$active[$content]??-1;
            $grouped[strtolower((string)$row['record_id'])][]=$row;}
        foreach($grouped as&$matches)usort($matches,static fn(array$left,array$right):int=>(int)$right['load_order']<=>(int)$left['load_order']);unset($matches);
        $result=[];foreach($wanted as$record=>$contentFiles){$matches=$grouped[$record]??[];
            foreach($contentFiles as$contentFile=>$_){$candidates=$contentFile===''?$matches:array_values(array_filter($matches,
                    static fn(array$row):bool=>strtolower((string)$row['content_file'])===$contentFile));
                if($candidates===[])continue;$selected=$candidates[0];foreach($candidates as$candidate){
                    if($candidate['description_source']==='custom'){$selected=$candidate;break;}}
                $descriptionId=$selected['description_id']??null;if(!is_string($descriptionId)||$descriptionId==='')
                    $descriptionId='default:'.hash('sha256',strtolower((string)$selected['content_file'])."\0".strtolower((string)$selected['record_id']));
                $result[]=['description_id'=>$descriptionId,'source'=>(string)$selected['description_source'],
                    'record_id'=>(string)$selected['record_id'],'content_file'=>(string)$selected['content_file'],
                    'name'=>(string)$selected['display_name'],'description'=>(string)$selected['description']];
                if(count($result)>=64)break 2;}}
        return$result;
    }

    /** Normalize the bounded OpenMW load order supplied with the current turn. */
    private function contentFilesForTurn(array $turn):array
    {
        $context=$turn['payload']['context']??[];if(!is_array($context)||array_is_list($context))return[];
        $files=$context['contentFiles']??[];if(is_array($files)&&!array_is_list($files))$files=$files['items']??[];
        $active=[];if(is_array($files))foreach(array_slice($files,0,256)as$order=>$file){
            if(!is_string($file))continue;$file=strtolower(trim($file));if($file==='')continue;$active[$file]=(int)$order;
        }
        return$active;
    }

    /** Flatten every bounded item-bearing context lane without treating display names as identity. */
    private function turnItemRows(array $context): array
    {
        $rows=[];$append=static function(mixed$value)use(&$rows):void{if(is_array($value)&&!array_is_list($value))$value=$value['items']??[];
            if(!is_array($value))return;foreach(array_slice($value,0,64)as$item)if(is_array($item)&&!array_is_list($item))$rows[]=$item;};
        $append($context['inventory']??[]);$append($context['nearbyObjects']??[]);$append($context['equipment']??[]);
        foreach(['playerState','targetState']as$state){$value=$context[$state]??[];if(is_array($value)&&!array_is_list($value)){
            $append($value['equipment']??[]);$append($value['held_items']??[]);}}
        $actors=$context['nearbyActors']??[];if(is_array($actors)&&!array_is_list($actors))$actors=$actors['items']??[];
        if(is_array($actors))foreach(array_slice($actors,0,12)as$actor)if(is_array($actor)&&!array_is_list($actor)){
            $append($actor['equipment']??[]);$append($actor['held_items']??[]);}
        return array_slice($rows,0,256);
    }

    public function recordPromptTrace(array $turn, array $trace, string $now): string
    {
        return $this->transaction(function() use($turn,$trace,$now):string{
            $id=Uuid::v4();
            // Older trace producers identify the profile-backed fallback prompt only by the turn profile.
            $selectedProfile=(string)($trace['profile_id']??$turn['profile_id']);
            $selectedRevision=isset($trace['profile_revision'])?(int)$trace['profile_revision']:null;
            $promptConfiguration=$trace['prompt_configuration_id']===$selectedProfile
                ?null:$trace['prompt_configuration_id'];
            $this->db->prepare('INSERT INTO prompt_traces (prompt_trace_id,installation_id,profile_id,playthrough_id,session_id,turn_id,request_id,prompt_configuration_id,prompt_revision,selected_profile_id,selected_profile_revision,core_profile_id,core_profile_revision,effective_settings_sha256,settings_sources,algorithm,input_sha256,input_bytes,truncated,created_at) VALUES (:id,:installation,:profile,:playthrough,:session,:turn,:request,:config,:revision,:selected_profile,:selected_revision,:core_profile,:core_revision,:settings_sha,CAST(:settings_sources AS jsonb),:algorithm,:sha,:bytes,:truncated,:now) ON CONFLICT (turn_id) DO NOTHING')->execute([
                'id'=>$id,'installation'=>$turn['installation_id'],'profile'=>$turn['profile_id'],
                'playthrough'=>$turn['playthrough_id'],'session'=>$turn['session_id'],'turn'=>$turn['turn_id'],
                'request'=>$turn['request_id'],'config'=>$promptConfiguration,'revision'=>$trace['prompt_revision'],
                'selected_profile'=>$selectedProfile,'selected_revision'=>$selectedRevision,'core_profile'=>$trace['core_profile_id']??null,
                'core_revision'=>$trace['core_profile_revision']??null,'settings_sha'=>$trace['effective_settings_sha256']??null,
                'settings_sources'=>$this->encode($trace['settings_sources']??[]),'algorithm'=>$trace['algorithm'],
                'sha'=>$trace['input_sha256'],'bytes'=>$trace['input_bytes'],'truncated'=>$trace['truncated']?'true':'false','now'=>$now,
            ]);
            $find=$this->db->prepare('SELECT prompt_trace_id FROM prompt_traces WHERE turn_id=:turn');
            $find->execute(['turn'=>$turn['turn_id']]);$stored=(string)$find->fetchColumn();
            foreach($trace['sources'] as $source){
                $kind=(string)$source['source_kind'];
                $section=match($kind){'profile','core_profile','prompt'=>'npc_context','knowledge'=>'oghma_context','narrative'=>'morrowind_context',
                    'relationship'=>'relationships_factions','memory'=>'memory_context','history'=>'conversation_context',
                    'action_result','action_catalog'=>'negotiated_actions',default=>'current_turn'};
                $sectionOrder=['npc_context'=>2,'morrowind_context'=>4,'oghma_context'=>5,'relationships_factions'=>6,'memory_context'=>7,
                    'conversation_context'=>8,'negotiated_actions'=>10,'current_turn'=>11][$section];
                $table=match($kind){'profile'=>'profile_revisions','core_profile'=>'core_profile_revisions','prompt'=>'configuration_revisions',
                    'history'=>'eventlog','memory'=>'memory_records','relationship'=>'relationship_records','knowledge'=>'knowledge_documents',
                    'narrative'=>'narrative_records','action_result'=>'action_results','action_catalog'=>'action_catalog',default=>'turns'};
                $characters=(int)($source['source_characters']??$source['included_bytes']);
                $this->db->prepare('INSERT INTO prompt_trace_sources '
                    . '(prompt_trace_id,ordinal,source_kind,source_id,included,reason,source_sha256,included_bytes,redacted_preview,'
                    . 'section_key,section_order,source_table,source_revision,source_occurred_at,playthrough_id,source_characters,estimated_tokens) '
                    . 'VALUES (:trace,:ordinal,:kind,:source,:included,:reason,:sha,:bytes,:preview,:section,:section_order,:source_table,'
                    . ':source_revision,:source_occurred_at,:playthrough,:characters,:tokens) ON CONFLICT DO NOTHING')->execute([
                        'trace'=>$stored,'ordinal'=>$source['ordinal'],'kind'=>$kind,'source'=>$source['source_id'],
                        'included'=>$source['included']?'true':'false','reason'=>$source['reason'],'sha'=>$source['source_sha256'],
                        'bytes'=>$source['included_bytes'],'preview'=>$source['redacted_preview']??'',
                        'section'=>$source['section_key']??$section,'section_order'=>$source['section_order']??$sectionOrder,
                        'source_table'=>$source['source_table']??$table,'source_revision'=>$source['source_revision']??$source['revision']??null,
                        'source_occurred_at'=>$source['source_occurred_at']??null,'playthrough'=>$turn['playthrough_id'],
                        'characters'=>$characters,'tokens'=>$source['estimated_tokens']??($characters===0?0:(int)ceil($characters/4))]);
            }
            foreach($trace['sections']??[] as$section){$this->db->prepare('INSERT INTO prompt_trace_sections '
                . '(prompt_trace_id,section_order,section_key,source_refs,inclusion_reason,source_occurred_at,playthrough_id,'
                . 'source_characters,estimated_tokens,redacted_preview,source_sha256) '
                . 'VALUES (:trace,:section_order,:section_key,CAST(:source_refs AS jsonb),:reason,:occurred,:playthrough,'
                . ':characters,:tokens,:preview,:sha) ON CONFLICT DO NOTHING')->execute([
                    'trace'=>$stored,'section_order'=>$section['section_order'],'section_key'=>$section['section_key'],
                    'source_refs'=>json_encode($section['source_refs'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),'reason'=>$section['inclusion_reason'],
                    'occurred'=>$section['source_occurred_at'],'playthrough'=>$turn['playthrough_id'],
                    'characters'=>$section['source_characters'],'tokens'=>$section['estimated_tokens'],
                    'preview'=>$section['redacted_preview'],'sha'=>$section['source_sha256']]);}
            foreach(['memory_retrieval'=>'memory','knowledge_retrieval'=>'knowledge']as$traceField=>$domain){
                $retrieval=$trace[$traceField]??null;if(!is_array($retrieval)||array_is_list($retrieval))continue;
                $retrievalScope=is_array($retrieval['scope']??null)?$retrieval['scope']:[];
                $this->db->prepare('INSERT INTO retrieval_traces '
                    .'(retrieval_trace_id,installation_id,profile_id,playthrough_id,domain,query,result_ids,scores,algorithm,created_at,'
                    .'turn_id,prompt_section,reasons) VALUES (:id,:installation,:profile,:playthrough,:domain,:query,'
                    .'CAST(:ids AS uuid[]),CAST(:scores AS jsonb),:algorithm,:created,:turn,:section,CAST(:reasons AS jsonb))')
                    ->execute(['id'=>Uuid::v4(),'installation'=>$turn['installation_id'],
                        'profile'=>$retrievalScope['profile_id']??$turn['profile_id'],'playthrough'=>$turn['playthrough_id'],
                        'domain'=>$domain,'query'=>$retrieval['query'],'ids'=>$this->pgArray($retrieval['result_ids']),
                        'scores'=>$this->encode($retrieval['scores']),'algorithm'=>$retrieval['algorithm'],
                        'created'=>$retrieval['created_at'],'turn'=>$turn['turn_id'],'section'=>$retrieval['prompt_section'],
                        'reasons'=>$this->encode($retrieval['reasons'])]);
            }
            return$stored;
        });
    }

    public function searchTraces(string $installation,string $query,int $limit=50):array{$needle='%'.$query.'%';$stmt=$this->db->prepare('SELECT source_event_id AS id,event_kind AS type,received_at AS created_at,request_id,turn_id,payload FROM source_events WHERE installation_id=:installation AND (event_kind ILIKE :query OR payload::text ILIKE :query) ORDER BY received_at DESC,source_event_id DESC LIMIT :limit');$stmt->bindValue(':installation',$installation);$stmt->bindValue(':query',$needle);$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->execute();return array_map(function($r){$r['payload']=$this->json($r['payload']);return$r;},$stmt->fetchAll());}
    public function traceDetail(string $id):array{$s=$this->db->prepare('SELECT * FROM source_events WHERE source_event_id=:id');$s->execute(['id'=>$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('not_found');$r['payload']=$this->json($r['payload']);return$r;}

    public function diagnostics():array{return ['database'=>['connected'=>true,'version'=>(string)$this->db->query('SHOW server_version')->fetchColumn()],'counts'=>['installations'=>(int)$this->db->query('SELECT count(*) FROM installations WHERE revoked_at IS NULL')->fetchColumn(),'active_sessions'=>(int)$this->db->query("SELECT count(*) FROM sessions WHERE state='active'")->fetchColumn(),'queued_jobs'=>(int)$this->db->query("SELECT count(*) FROM durable_jobs WHERE state='queued'")->fetchColumn(),'dead_jobs'=>(int)$this->db->query("SELECT count(*) FROM durable_jobs WHERE state='dead'")->fetchColumn(),'memory_records'=>(int)$this->db->query('SELECT count(*) FROM memory_records WHERE deleted_at IS NULL')->fetchColumn()]];}

    public function prune(int $days,string $now):array{$result=[];$queries=['rate_limits'=>"DELETE FROM rate_limit_buckets WHERE window_started_at < CAST(:now AS timestamptz) - interval '1 day'",'idempotency'=>"DELETE FROM idempotency_requests WHERE created_at < CAST(:now AS timestamptz) - (:days || ' days')::interval",'browser_sessions'=>'DELETE FROM browser_sessions WHERE expires_at<:now OR revoked_at IS NOT NULL'];foreach($queries as $key=>$sql){$s=$this->db->prepare($sql);$s->execute(['now'=>$now]+(str_contains($sql,':days')?['days'=>(string)$days]:[]));$result[$key]=$s->rowCount();}return $result;}

    private function revision(string $table,string $key,string $id,int $revision,array $content,string $reason,string $now):void{$this->db->prepare("INSERT INTO {$table} ({$key},revision,content,change_reason,created_at) VALUES (:id,:revision,CAST(:content AS jsonb),:reason,:now)")->execute(['id'=>$id,'revision'=>$revision,'content'=>$this->encode($content),'reason'=>$reason,'now'=>$now]);}
    /** Mirror a revisioned Prompt Manager document into the CHIM-compatible prompt override table. */
    private function syncPrompt(string $configurationId,array $content,int $revision,string $now):void
    {
        $owner=$this->db->prepare("SELECT installation_id,name FROM configuration_sets WHERE configuration_id=:id AND kind='prompt'");
        $owner->execute(['id'=>$configurationId]);$row=$owner->fetch();if(!$row)throw new RuntimeException('not_found');
        $key=substr((string)preg_replace('/[^a-z0-9_.-]+/','_',strtolower((string)$row['name'])),0,128);
        $instruction=(string)($content['instruction']??'');
        $default=(string)($content['default_prompt']??$instruction);
        $custom=array_key_exists('custom_prompt',$content)?$content['custom_prompt']:$instruction;
        $custom=is_string($custom)&&trim($custom)!==''?$custom:null;
        $description=(string)($content['description']??$row['name']);
        $this->db->prepare('INSERT INTO prompts (installation_id,prompt_key,default_prompt,custom_prompt,description,source_configuration_id,source_revision,created_at,updated_at) '
            . 'VALUES (:installation,:key,:default,:custom,:description,:configuration,:revision,:now,:now) '
            . 'ON CONFLICT (installation_id,prompt_key) DO UPDATE SET custom_prompt=EXCLUDED.custom_prompt,description=EXCLUDED.description,'
            . 'source_configuration_id=EXCLUDED.source_configuration_id,source_revision=EXCLUDED.source_revision,updated_at=EXCLUDED.updated_at')
            ->execute(['installation'=>$row['installation_id'],'key'=>$key,'default'=>$default,'custom'=>$custom,
                'description'=>$description,'configuration'=>$configurationId,'revision'=>$revision,'now'=>$now]);
    }
    private function revisionMeta(string $kind):array{return match($kind){'profile'=>['profiles','profile_id','profile_revisions'],'core_profile'=>['core_profiles','core_profile_id','core_profile_revisions'],'playthrough'=>['playthroughs','playthrough_id','playthrough_revisions'],'prompt','provider','tts_provider','stt_provider','action_policy','global_settings'=>['configuration_sets','configuration_id','configuration_revisions'],default=>throw new RuntimeException('invalid_resource_kind')};}
    private function transaction(callable $callback):mixed{$owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();try{$v=$callback();if($owns)$this->db->commit();return$v;}catch(Throwable $e){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$e;}}
    private function deterministicUuid(string $value):string{$h=md5($value);return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);}
    private function selectedActorProfileId(string $installation,string $playthrough,array $identity):?string{$s=$this->db->prepare('SELECT b.profile_id FROM actor_profile_bindings b JOIN profiles p ON p.profile_id=b.profile_id AND p.installation_id=b.installation_id AND p.deleted_at IS NULL WHERE b.installation_id=:installation AND b.playthrough_id=:playthrough AND b.actor_key=:key');$s->execute(['installation'=>$installation,'playthrough'=>$playthrough,'key'=>$this->actorKey($identity)]);$value=$s->fetchColumn();return$value===false?null:(string)$value;}
    private function actorKey(array $identity):string{return hash('sha256',$this->encodeCanonical(['kind'=>$identity['kind']??null,'record_id'=>$identity['record_id']??null,'content_file'=>$identity['content_file']??null,'refnum'=>$identity['refnum']??null]));}
    private function encodeCanonical(mixed $value):string{$sort=static function(mixed $item)use(&$sort):mixed{if(!is_array($item))return$item;if(array_is_list($item))return array_map($sort,$item);ksort($item,SORT_STRING);foreach($item as&$child)$child=$sort($child);return$item;};return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function withoutSecrets(array $value):array{foreach($value as$key=>&$item){if(is_string($key)&&preg_match('/(?:api[_-]?key|secret|password|authorization|access[_-]?token|refresh[_-]?token)/i',$key)===1){unset($value[$key]);continue;}if(is_array($item))$item=$this->withoutSecrets($item);}unset($item);return$value;}
    private function encode(array $v):string{return json_encode($v===[]?(object)[]:$v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
    private function json(mixed $v):array{return is_array($v)?$v:json_decode((string)$v,true,64,JSON_THROW_ON_ERROR);}
    private function scopeParams(array $v):array{return ['installation'=>$v['installation_id'],'profile'=>$v['profile_id'],'playthrough'=>$v['playthrough_id']];}
    private function pgArray(array $v):string{return '{'.implode(',',array_map(fn($x)=>'"'.addcslashes((string)$x,'"\\').'"',$v)).'}';}
    private function parsePgArray(string $v):array{return $v==='{}'?[]:str_getcsv(trim($v,'{}'),',','"','\\');}
    private function decodeMemory(array $r):array{$r['lexical_terms']=$this->parsePgArray((string)$r['lexical_terms']);$r['fake_vector']=$this->json($r['fake_vector']);$r['provenance']=$this->json($r['provenance']);return$r;}
}
