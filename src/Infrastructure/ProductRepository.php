<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use ALMSIVIserver\Application\EffectiveSettingsResolver;
use ALMSIVIserver\Application\MorrowindVoiceCatalog;
use PDO;
use RuntimeException;
use Throwable;

final class ProductRepository
{
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
                $profile=$this->db->prepare("SELECT 1 FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND (r.content->'routing'->>'llm_configuration_id'=:id OR r.content->'routing'->>'llm_fast_configuration_id'=:id OR r.content->'routing'->>'llm_powerful_configuration_id'=:id OR r.content->'routing'->>'llm_experimental_configuration_id'=:id OR r.content->'routing'->>'llm_fallback_configuration_id'=:id) LIMIT 1");
                $profile->execute(['id'=>$id]);if($profile->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
                $core=$this->db->prepare("SELECT 1 FROM core_profile_revisions r JOIN core_profiles c ON c.core_profile_id=r.core_profile_id AND c.current_revision=r.revision WHERE c.deleted_at IS NULL AND (r.content->'routing'->>'llm_configuration_id'=:id OR r.content->'routing'->>'llm_fast_configuration_id'=:id OR r.content->'routing'->>'llm_powerful_configuration_id'=:id OR r.content->'routing'->>'llm_experimental_configuration_id'=:id OR r.content->'routing'->>'llm_fallback_configuration_id'=:id) LIMIT 1");
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
            'llm_experimental_configuration_id','llm_fallback_configuration_id']:['tts_configuration_id'];
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
        $resolved=(new EffectiveSettingsResolver())->resolve(
            is_array($global['content']??null)?$global['content']:[],
            is_array($core['content']??null)?$core['content']:[],
            is_array($profile['content']??null)?$profile['content']:[],
        );
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
                $existing=$this->db->prepare("SELECT profile_id FROM profiles WHERE installation_id=:installation AND deleted_at IS NULL "
                    ."AND lower(actor_identity->>'record_id')=lower(:record) AND lower(COALESCE(actor_identity->>'content_file',''))=lower(:content) "
                    ."AND COALESCE(actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY created_at,profile_id LIMIT 2");
                $existing->execute(['installation'=>$turn['installation_id'],'record'=>$target['record_id']??'',
                    'content'=>$target['content_file']??'']);$matches=$existing->fetchAll();
                if(count($matches)===1)$profileId=(string)$matches[0]['profile_id'];
                else{$template=$this->matchingBiographyTemplate((string)$turn['installation_id'],$target,$resolvedVoice);
                    $seed=is_array($template['content']??null)?$template['content']:[];unset($seed['management'],$seed['portrait']);
                    $seed['gender']=$resolvedVoice['gender'];$seed['race']=$resolvedVoice['race'];$seed['voice']=$this->catalogVoiceDocument($resolvedVoice);
                    $seed['management']=['locked'=>false,'favorite'=>false];
                    $created=$this->createRevisioned('profile',['installation_id'=>$turn['installation_id'],
                    'name'=>(string)($target['display_name']??$target['record_id']??'Morrowind NPC'),'actor_identity'=>$target,
                    'content'=>$seed,
                    'change_reason'=>'automatic Morrowind actor discovery'],$now);$profileId=(string)$created['profile_id'];}
                $this->bindActorProfile(['installation_id'=>$turn['installation_id'],'playthrough_id'=>$turn['playthrough_id']],
                    $target,$profileId,$now);
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

    /** Choose the most specific reusable biography template for a newly observed NPC. */
    private function matchingBiographyTemplate(string $installation,array $identity,array $voice):?array
    {
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
        $stmt=$this->db->prepare('SELECT memory_id AS id,tier,content,lexical_terms,fake_vector,provenance,source_event_id,occurred_at FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL AND (expires_at IS NULL OR expires_at>:now) ORDER BY occurred_at DESC LIMIT 500');$stmt->execute($this->scopeParams($scope)+['now'=>$now]);return array_map(fn($r)=>$this->decodeMemory($r),$stmt->fetchAll());
    }

    public function createKnowledge(array $input,array $terms,string $now): array
    {
        $id=Uuid::v4();$sha=hash('sha256',$input['content']);$this->db->prepare('INSERT INTO knowledge_documents (document_id,installation_id,profile_id,playthrough_id,title,content,content_sha256,lexical_terms,provenance,created_at) VALUES (:id,:installation,:profile,:playthrough,:title,:content,:sha,CAST(:terms AS text[]),CAST(:provenance AS jsonb),:now)')->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id']??null,'playthrough'=>$input['playthrough_id']??null,'title'=>$input['title'],'content'=>$input['content'],'sha'=>$sha,'terms'=>$this->pgArray($terms),'provenance'=>$this->encode($input['provenance']),'now'=>$now]);return $this->knowledge($id);
    }
    public function knowledge(string $id): array {$s=$this->db->prepare('SELECT * FROM knowledge_documents WHERE document_id=:id AND deleted_at IS NULL');$s->execute(['id'=>$id]);$r=$s->fetch();if(!$r)throw new RuntimeException('not_found');$r['id']=$r['document_id'];$r['lexical_terms']=$this->parsePgArray($r['lexical_terms']);$r['provenance']=$this->json($r['provenance']);return $r;}
    public function deleteKnowledge(string $id,string $now):void{$this->db->prepare('UPDATE knowledge_documents SET deleted_at=:now WHERE document_id=:id')->execute(['now'=>$now,'id'=>$id]);}
    public function knowledgeCandidates(array $scope):array{$sql='SELECT document_id AS id,title,content,content_sha256,lexical_terms,provenance FROM knowledge_documents WHERE installation_id=:installation AND deleted_at IS NULL AND (profile_id IS NULL OR profile_id=:profile) AND (playthrough_id IS NULL OR playthrough_id=:playthrough) ORDER BY created_at DESC LIMIT 500';$s=$this->db->prepare($sql);$s->execute(['installation'=>$scope['installation_id'],'profile'=>$scope['profile_id']??null,'playthrough'=>$scope['playthrough_id']??null]);return array_map(function($r){$r['lexical_terms']=$this->parsePgArray($r['lexical_terms']);$r['provenance']=$this->json($r['provenance']);return $r;},$s->fetchAll());}

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

    public function promptContext(array $turn, string $now): array
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
        $memories=$this->memoryCandidates($scope,$now);usort($memories,fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));
        $knowledge=$this->knowledgeCandidates($scope);usort($knowledge,fn($a,$b)=>strcmp((string)$a['id'],(string)$b['id']));
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
        $historyStatement=$this->db->prepare(<<<'SQL'
SELECT 'event:'||e.rowid::text AS id,
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
  AND (CAST(:is_rechat AS boolean)=true OR m.speaker @> CAST(:event_speaker AS jsonb)
       OR m.target @> CAST(:event_target AS jsonb)
       OR m.audience @> CAST(:event_audience AS jsonb))
ORDER BY sort_ts DESC,sort_created_at DESC,source_rank DESC,sort_id DESC
LIMIT 40
SQL);
        $historyStatement->execute([
            'installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
            'current_turn'=>$turn['turn_id']??null,
            'is_rechat'=>(($turn['payload']['ui_source']??null)==='almsivi_rechat')?'true':'false',
            'event_speaker'=>$actorJson,'event_target'=>$actorJson,'event_audience'=>$audienceJson,
        ]);
        $history=[];foreach(array_reverse($historyStatement->fetchAll())as$row)$history[]=['id'=>(string)$row['id'],
            'installation_id'=>$turn['installation_id'],'playthrough_id'=>$turn['playthrough_id'],'content'=>$this->json($row['content'])];
        return ['profile'=>$profile,'core_profile'=>$coreProfile,'selected_profile_id'=>$activeProfileId,
            'effective_settings'=>['sha256'=>$effective['sha256'],'sources'=>$effective['sources']],
            'player_profile'=>$this->playerProfileForInstallation($turn['installation_id']),
            'narrator_profile'=>$this->narratorProfileForInstallation($turn['installation_id']),
            'nearby_actor_profiles'=>$this->nearbyActorProfilesForTurn($turn),
            'item_descriptions'=>$this->itemDescriptionsForTurn($turn),
            'prompt'=>$prompt,'history'=>$history,'memory'=>array_slice($memories,0,10),'relationship'=>array_slice($relationships,0,10),'knowledge'=>array_slice($knowledge,0,10),'narrative'=>array_slice($narratives,0,10),'recent_action_results'=>$recent];
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
        return$this->transaction(function()use($input,$now):array{
            $find=$this->db->prepare('SELECT description_id FROM item_descriptions WHERE installation_id=:installation AND lower(content_file)=lower(:content_file) AND lower(record_id)=lower(:record_id) AND deleted_at IS NULL');
            $find->execute(['installation'=>$input['installation_id'],'content_file'=>$input['content_file'],'record_id'=>$input['record_id']]);$id=$find->fetchColumn();
            if($id===false){$id=Uuid::v4();$statement=$this->db->prepare('INSERT INTO item_descriptions(description_id,installation_id,content_file,record_id,display_name,description,created_at,updated_at) VALUES(:id,:installation,:content_file,:record_id,:display_name,:description,:now,:now)');}
            else{$statement=$this->db->prepare('UPDATE item_descriptions SET content_file=:content_file,record_id=:record_id,display_name=:display_name,description=:description,updated_at=:now WHERE description_id=:id AND installation_id=:installation AND deleted_at IS NULL');}
            $statement->execute(['id'=>$id,'installation'=>$input['installation_id'],'content_file'=>trim((string)$input['content_file']),'record_id'=>trim((string)$input['record_id']),'display_name'=>trim((string)$input['display_name']),'description'=>trim((string)$input['description']),'now'=>$now]);
            return['description_id'=>(string)$id,'updated_at'=>$now];
        });
    }

    public function deleteItemDescription(string $descriptionId,string $now): void
    {
        $statement=$this->db->prepare('UPDATE item_descriptions SET deleted_at=:now,updated_at=:now WHERE description_id=:id AND deleted_at IS NULL');
        $statement->execute(['id'=>$descriptionId,'now'=>$now]);if($statement->rowCount()!==1)throw new RuntimeException('not_found');
    }

    /** Select only unambiguous descriptions for inventory and nearby records in this turn. */
    public function itemDescriptionsForTurn(array $turn): array
    {
        $context=$turn['payload']['context']??[];if(!is_array($context)||array_is_list($context))return[];
        $wanted=[];
        foreach(['inventory','nearbyObjects','equipment']as$collection){$value=$context[$collection]??[];
            if(is_array($value)&&!array_is_list($value))$value=$value['items']??[];if(!is_array($value))continue;
            foreach(array_slice($value,0,64)as$item){if(!is_array($item)||array_is_list($item))continue;$record=strtolower(trim((string)($item['record_id']??'')));
                if($record===''||strlen($record)>256)continue;$content=strtolower(trim((string)($item['content_file']??'')));$wanted[$record][$content]=true;}}
        if($wanted===[])return[];
        $statement=$this->db->prepare('SELECT description_id,content_file,record_id,display_name,description FROM item_descriptions WHERE installation_id=:installation AND deleted_at IS NULL AND lower(record_id)=ANY(CAST(:records AS text[])) ORDER BY lower(record_id),lower(content_file),description_id');
        $statement->execute(['installation'=>$turn['installation_id'],'records'=>$this->pgArray(array_keys($wanted))]);$grouped=[];
        foreach($statement->fetchAll()as$row)$grouped[strtolower((string)$row['record_id'])][]=$row;
        $result=[];foreach($wanted as$record=>$contentFiles){$matches=$grouped[$record]??[];
            foreach($contentFiles as$contentFile=>$_){$selected=$contentFile===''?(count($matches)===1?$matches[0]:null):current(array_filter($matches,static fn(array$row):bool=>strtolower((string)$row['content_file'])===$contentFile));
                if(!is_array($selected))continue;$result[]=['description_id'=>(string)$selected['description_id'],'record_id'=>(string)$selected['record_id'],'content_file'=>(string)$selected['content_file'],'name'=>(string)$selected['display_name'],'description'=>(string)$selected['description']];if(count($result)>=64)break 2;}}
        return$result;
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
            foreach($trace['sources'] as $source){$this->db->prepare('INSERT INTO prompt_trace_sources (prompt_trace_id,ordinal,source_kind,source_id,included,reason,source_sha256,included_bytes,redacted_preview) VALUES (:trace,:ordinal,:kind,:source,:included,:reason,:sha,:bytes,:preview) ON CONFLICT DO NOTHING')->execute(['trace'=>$stored,'ordinal'=>$source['ordinal'],'kind'=>$source['source_kind'],'source'=>$source['source_id'],'included'=>$source['included']?'true':'false','reason'=>$source['reason'],'sha'=>$source['source_sha256'],'bytes'=>$source['included_bytes'],'preview'=>'']);}
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
