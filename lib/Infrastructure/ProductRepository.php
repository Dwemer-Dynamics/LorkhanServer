<?php

declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\EffectiveSettingsResolver;
use LorkhanServer\Application\MorrowindGeographyCatalog;
use LorkhanServer\Application\MorrowindVoiceCatalog;
use LorkhanServer\Application\DeterministicRetrieval;
use LorkhanServer\Application\OghmaGroundedRetriever;
use LorkhanServer\Application\SettingsCatalog;
use LorkhanServer\Application\ProfileAssignmentRule;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ProductRepository
{
    private const PROFILE_RULE_MATCH_FIELDS=['names','races','classes','genders','factions','content_files'];
    private const MODEL_SLOTS=[
        'standard'=>['label'=>'Standard','field'=>'llm_configuration_id'],
        'fast'=>['label'=>'Fast','field'=>'llm_fast_configuration_id'],
        'powerful'=>['label'=>'Powerful','field'=>'llm_powerful_configuration_id'],
        'experimental'=>['label'=>'Experimental','field'=>'llm_experimental_configuration_id'],
    ];
    private ?MorrowindGeographyCatalog $morrowindGeography=null;
    private ?TtsPronunciationRepository $ttsPronunciations=null;

    public function __construct(private readonly PDO $db) {}

    public function characterPlaythroughState(string $installation):array
    {
        return (new CharacterPlaythroughRepository($this->db))->state($installation);
    }

    public function dynamicOghma():DynamicOghmaRepository{return new DynamicOghmaRepository($this->db);}
    public function player2Routing():Player2RoutingRepository{return new Player2RoutingRepository($this->db);}

    /** @param array<string,mixed> $input */
    public function createRevisioned(string $kind, array $input, string $now, bool $inheritCoreDefaults = true): array
    {
        return $this->transaction(function () use ($kind, $input, $now, $inheritCoreDefaults): array {
            if($kind==='global_settings')$this->db->query("SELECT pg_advisory_xact_lock(7514,120)");
            if(in_array($kind,['memory_policy','memory_embedding_policy','translation_policy'],true)){
                if(isset($input['profile_id']))throw new \InvalidArgumentException($kind.'_is_installation_scoped');
            }
            if($kind==='memory_policy'){
                (new MemorySummaryRepository($this->db))->assertProvider($input['installation_id'],$input['content']);
            }
            $id = Uuid::v4();
            $reason = (string) ($input['change_reason'] ?? 'created');
            if ($kind === 'core_profile') {
                // Serialize new profiles with installation-wide preset application.
                $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:installation FOR UPDATE')
                    ->execute(['installation'=>$input['installation_id']]);
                if($inheritCoreDefaults)$input['content']=$this->withCoreCreationDefaults($input['installation_id'],$input['content']);
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
                if (!in_array($input['actor_identity']['kind'] ?? 'actor', ['player','narrator','template'], true)) {
                    $core=$this->getRevisioned('core_profile',(string)$coreProfileId);
                    if ($core['installation_id']!==$input['installation_id']) throw new InvalidArgumentException('scope_mismatch');
                    $defaults=EffectiveSettingsResolver::profileEvolutionDefaults($core['content']['settings_overrides']['profile_evolution']??null);
                    // Explicit NPC/template choices win; subsequent Core Profile edits do not rewrite NPCs.
                    $input['content'] += ['dynamic_profile'=>$defaults['enabled'],'dynamic_profile_fields'=>$defaults['fields']];
                }
                $this->db->prepare('INSERT INTO profiles (profile_id,installation_id,playthrough_id,name,actor_identity,core_profile_id,created_at) VALUES (:id,:installation,:playthrough,:name,CAST(:identity AS jsonb),:core_profile,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'name'=>$input['name'],'identity'=>$this->encode($input['actor_identity'] ?? []),'core_profile'=>$coreProfileId,'now'=>$now,
                        'playthrough'=>in_array($input['actor_identity']['kind']??'actor',['narrator','template'],true)?null:
                            ((new ProfileOwnershipRepository($this->db))->activePlaythrough((string)$input['installation_id'])===null?null:
                                ($input['playthrough_id']??(new ProfileOwnershipRepository($this->db))->activePlaythrough((string)$input['installation_id'])))]);
                $this->revision('profile_revisions', 'profile_id', $id, 1, $input['content'], $reason, $now);
            } elseif ($kind === 'playthrough') {
                $owner=$this->db->prepare('SELECT 1 FROM profiles WHERE profile_id=:profile AND installation_id=:installation AND deleted_at IS NULL AND playthrough_id IS NULL AND NOT EXISTS(SELECT 1 FROM character_playthrough_bindings b WHERE b.installation_id=profiles.installation_id) FOR SHARE');
                $owner->execute(['profile'=>$input['profile_id'],'installation'=>$input['installation_id']]);
                if(!$owner->fetchColumn())throw new InvalidArgumentException('scope_mismatch');
                $this->db->prepare('INSERT INTO playthroughs (playthrough_id, installation_id, profile_id, name, content_fingerprint, created_at) VALUES (:id,:installation,:profile,:name,:fingerprint,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'],'name'=>$input['name'],'fingerprint'=>$input['content_fingerprint'] ?? null,'now'=>$now]);
                $this->revision('playthrough_revisions', 'playthrough_id', $id, 1, $input['content'], $reason, $now);
            } else {
                $configKind = match ($kind) {
                    'prompt', 'provider', 'tts_provider', 'stt_provider', 'action_policy', 'global_settings', 'memory_policy',
                    'memory_embedding_policy', 'translation_policy' => $kind,
                    default => throw new RuntimeException('invalid_resource_kind'),
                };
                $this->db->prepare('INSERT INTO configuration_sets (configuration_id,installation_id,profile_id,kind,name,created_at) VALUES (:id,:installation,:profile,:kind,:name,:now)')
                    ->execute(['id'=>$id,'installation'=>$input['installation_id'],'profile'=>$input['profile_id'] ?? null,'kind'=>$configKind,'name'=>$input['name'],'now'=>$now]);
                $this->revision('configuration_revisions', 'configuration_id', $id, 1, $input['content'], $reason, $now);
                if($configKind==='prompt')$this->syncPrompt($id,$input['content'],1,$now);
                if($configKind==='global_settings'&&($input['content']['client']['behavior']['ai_enabled']??true)===false)
                    (new Repository($this->db))->cancelAiOutput($input['installation_id']);
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

    /** Resolve Quickstart's owned connector by identity rather than adopting a similarly named user connector. */
    public function quickstartLocalLlmForInstallation(string $installation):?array
    {
        $query=$this->db->prepare("SELECT q.configuration_id,q.server_type,q.scope FROM quickstart_local_llm q JOIN configuration_sets c ON c.configuration_id=q.configuration_id AND c.installation_id=q.installation_id WHERE q.installation_id=:installation AND c.deleted_at IS NULL");
        $query->execute(['installation'=>$installation]);$state=$query->fetch();
        if(!$state)return null;
        return $state+['connector'=>$this->getRevisioned('provider',(string)$state['configuration_id'])];
    }

    /** Upsert the managed connector inside the caller's routing transaction, without writing or returning raw keys. */
    public function saveQuickstartLocalLlm(string $installation,array $values,int $expectedRevision,string $now):array
    {
        return $this->transaction(function()use($installation,$values,$expectedRevision,$now):array{
            $lock=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation FOR UPDATE');
            $lock->execute(['installation'=>$installation]);if(!$lock->fetchColumn())throw new RuntimeException('not_found');
            $state=$this->quickstartLocalLlmForInstallation($installation);$existing=$state['connector']??null;
            if($expectedRevision<0||$expectedRevision!==(int)($existing['current_revision']??0))throw new RuntimeException('revision_conflict');
            if($existing!==null&&($existing['content']['service']??'')!=='local')throw new RuntimeException('local_llm_connector_repurposed');
            // Omitting the reference keeps the current private badge selection; an explicit 'none' clears only the reference.
            $values['credential']??=$existing['content']['credential']??'none';
            $setup=\LorkhanServer\Application\QuickstartLocalLlm::normalize($values);
            if($existing===null){
                $connector=$this->createRevisioned('provider',['installation_id'=>$installation,'name'=>$setup['name'],'content'=>$setup['content']],$now);
            }else{
                $connector=$this->revise('provider',(string)$state['configuration_id'],$setup['content'],'Quickstart Local LLM setup',$now,$expectedRevision);
                $this->db->prepare('UPDATE configuration_sets SET name=:name WHERE configuration_id=:id')->execute(['name'=>$setup['name'],'id'=>$state['configuration_id']]);
            }
            $this->db->prepare('INSERT INTO quickstart_local_llm(installation_id,configuration_id,server_type,scope) VALUES(:installation,:id,:server,:scope) ON CONFLICT(installation_id) DO UPDATE SET configuration_id=EXCLUDED.configuration_id,server_type=EXCLUDED.server_type,scope=EXCLUDED.scope')
                ->execute(['installation'=>$installation,'id'=>$connector['configuration_id'],'server'=>$setup['server_type'],'scope'=>$setup['scope']]);
            return $this->quickstartLocalLlmForInstallation($installation)??throw new RuntimeException('local_llm_save_failed');
        });
    }

    /** Snapshot dialogue routes and every Core preset target; stale Quickstart pages cannot overwrite later edits. */
    public function quickstartLocalRoutingPlan(string $installation):array
    {
        $query=$this->db->prepare("SELECT c.core_profile_id,c.label,c.current_revision FROM core_profiles c WHERE c.installation_id=:installation AND c.deleted_at IS NULL AND (c.default_npc=true OR EXISTS(SELECT 1 FROM profiles p WHERE p.installation_id=c.installation_id AND p.core_profile_id=c.core_profile_id AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='narrator')) ORDER BY c.core_profile_id");
        $query->execute(['installation'=>$installation]);$cores=$query->fetchAll();
        if($cores===[]){
            $query=$this->db->prepare('SELECT core_profile_id,label,current_revision FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY created_at,core_profile_id LIMIT 1');
            $query->execute(['installation'=>$installation]);$cores=$query->fetchAll();
        }
        $query=$this->db->prepare("SELECT profile_id,core_profile_id,current_revision FROM profiles WHERE installation_id=:installation AND deleted_at IS NULL AND actor_identity->>'kind'='narrator' ORDER BY profile_id");
        $query->execute(['installation'=>$installation]);$narrators=$query->fetchAll();
        $managed=$this->quickstartLocalLlmForInstallation($installation);$global=$this->globalSettingsForInstallation($installation);$summary=$this->memorySummaryPolicyForInstallation($installation);
        $embedding=$this->memoryEmbeddingPolicyForInstallation($installation);
        $query=$this->db->prepare('SELECT core_profile_id,current_revision FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY core_profile_id');
        $query->execute(['installation'=>$installation]);$presetProfiles=$query->fetchAll();
        $plan=['installation_id'=>$installation,'core_profiles'=>$cores,'preset_profiles'=>$presetProfiles,'narrators'=>$narrators,
            'creation_defaults'=>$this->coreCreationPreset($installation),
            'connector_id'=>$managed['configuration_id']??null,'connector_revision'=>(int)($managed['connector']['current_revision']??0),
            'global_id'=>$global['configuration_id']??null,'global_revision'=>(int)($global['current_revision']??0),
            'summary_id'=>$summary['configuration_id']??null,'summary_revision'=>(int)($summary['current_revision']??0),
            'embedding_id'=>$embedding['configuration_id']??null,'embedding_revision'=>(int)($embedding['current_revision']??0)];
        return $plan+['fingerprint'=>hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR))];
    }

    /** Read all stored Core settings in one statement for a global preset; omit identity and connector bindings. */
    public function coreSettingsSnapshot(string $installation):array
    {
        $query=$this->db->prepare('SELECT c.core_profile_id,r.content,p.core_creation_preset FROM installations i '
            .'LEFT JOIN installation_profile_preferences p ON p.installation_id=i.installation_id '
            .'LEFT JOIN core_profiles c ON c.installation_id=i.installation_id AND c.deleted_at IS NULL '
            .'LEFT JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision '
            .'WHERE i.installation_id=:installation ORDER BY c.core_profile_id');
        $query->execute(['installation'=>$installation]);
        $rows=$query->fetchAll();$stored=$rows[0]['core_creation_preset']??null;
        $creationDefaults=$stored===null?null:\LorkhanServer\Application\CoreProfilePreset::validate($this->json($stored));
        $snapshot=['default'=>$creationDefaults??\LorkhanServer\Application\CoreProfilePreset::capture([]),'items'=>[]];
        foreach($rows as $row){
            if($row['core_profile_id']===null)continue;
            $preset=\LorkhanServer\Application\CoreProfilePreset::capture($this->json($row['content']));
            $snapshot['items'][$row['core_profile_id']]=$preset;
        }
        return $snapshot;
    }

    /** Creation defaults are installation settings, independent of the default NPC's current profile. */
    public function coreCreationPreset(string $installation):?array
    {
        $query=$this->db->prepare('SELECT core_creation_preset FROM installation_profile_preferences WHERE installation_id=:installation');
        $query->execute(['installation'=>$installation]);$value=$query->fetchColumn();
        return $value===false||$value===null?null:\LorkhanServer\Application\CoreProfilePreset::validate($this->json($value));
    }

    /** Seed missing fields only; explicit false, zero and whole lists remain the caller's choices. */
    public function withCoreCreationDefaults(string $installation,array $content):array
    {
        $preset=$this->coreCreationPreset($installation);if($preset===null)return$content;
        foreach($preset['settings_overrides'] as $section=>$values)
            $content['settings_overrides'][$section]=array_replace($values,$content['settings_overrides'][$section]??[]);
        $content['routing']=array_replace($preset['routing'],$content['routing']??[]);
        return EffectiveSettingsResolver::validateCoreProfile($content);
    }

    /** Apply built-in or saved settings to all installation Core Profiles, preserving identities and connector routes. */
    public function applyInstallationCorePreset(string $installation,string|array $preset,string $fingerprint,string $now):array
    {
        if(is_array($preset))$preset=\LorkhanServer\Application\GlobalSettingsPreset::profileSnapshot($preset);
        elseif(!in_array($preset,['builtin:default','builtin:local_llm'],true))throw new InvalidArgumentException('invalid_quickstart_preset');
        return $this->transaction(function()use($installation,$preset,$fingerprint,$now):array{
            $lock=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation FOR UPDATE');
            $lock->execute(['installation'=>$installation]);if(!$lock->fetchColumn())throw new RuntimeException('not_found');
            $lock=$this->db->prepare('SELECT core_profile_id FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY core_profile_id FOR UPDATE');
            $lock->execute(['installation'=>$installation]);$lock->fetchAll();
            $lock=$this->db->prepare("SELECT configuration_id FROM configuration_sets WHERE installation_id=:installation AND deleted_at IS NULL AND kind IN ('global_settings','memory_policy','memory_embedding_policy') ORDER BY configuration_id FOR UPDATE");
            $lock->execute(['installation'=>$installation]);$lock->fetchAll();
            $plan=$this->quickstartLocalRoutingPlan($installation);
            if(!hash_equals($plan['fingerprint'],$fingerprint))throw new RuntimeException('revision_conflict');
            if($plan['preset_profiles']===[])throw new RuntimeException('default_core_profile_required');
            foreach($plan['preset_profiles'] as $target){
                $profile=$this->getRevisioned('core_profile',$target['core_profile_id']);
                $content=is_array($preset)
                    ? \LorkhanServer\Application\CoreProfilePreset::apply($preset['items'][$target['core_profile_id']]??$preset['default'],$profile['content'])
                    : \LorkhanServer\Application\CoreProfilePreset::applyBuiltIn($preset,$profile['content']);
                $this->revise('core_profile',$target['core_profile_id'],$content,'Installation Core Profile preset',$now,(int)$target['current_revision']);
            }
            $creationDefaults=is_array($preset)?$preset['default']:\LorkhanServer\Application\CoreProfilePreset::capture(
                \LorkhanServer\Application\CoreProfilePreset::applyBuiltIn($preset,['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]]));
            $this->db->prepare('INSERT INTO installation_profile_preferences(installation_id,core_creation_preset,updated_at) VALUES(:installation,CAST(:preset AS jsonb),:now) '
                .'ON CONFLICT(installation_id) DO UPDATE SET core_creation_preset=EXCLUDED.core_creation_preset,updated_at=EXCLUDED.updated_at')
                ->execute(['installation'=>$installation,'preset'=>$this->encode($creationDefaults),'now'=>$now]);
            return $this->quickstartLocalRoutingPlan($installation);
        });
    }

    /** Apply managed connector and default dialogue/background routes atomically; leave NPC overrides and live slots alone. */
    public function applyQuickstartLocalLlm(string $installation,array $values,string $fingerprint,string $now):array
    {
        return $this->transaction(function()use($installation,$values,$fingerprint,$now):array{
            $lock=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation FOR UPDATE');
            $lock->execute(['installation'=>$installation]);if(!$lock->fetchColumn())throw new RuntimeException('not_found');
            // Lock the editable defaults and policies before checking the browser's revision snapshot.
            foreach(["SELECT core_profile_id FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY core_profile_id FOR UPDATE",
                "SELECT profile_id FROM profiles WHERE installation_id=:installation AND deleted_at IS NULL AND actor_identity->>'kind'='narrator' ORDER BY profile_id FOR UPDATE",
                "SELECT configuration_id FROM configuration_sets WHERE installation_id=:installation AND deleted_at IS NULL AND kind IN ('provider','global_settings','memory_policy') ORDER BY configuration_id FOR UPDATE"] as $sql){
                $lock=$this->db->prepare($sql);$lock->execute(['installation'=>$installation]);$lock->fetchAll();
            }
            $plan=$this->quickstartLocalRoutingPlan($installation);
            if(!hash_equals($plan['fingerprint'],$fingerprint))throw new RuntimeException('revision_conflict');
            if($plan['core_profiles']===[])throw new RuntimeException('default_core_profile_required');
            $saved=$this->saveQuickstartLocalLlm($installation,$values,$plan['connector_revision'],$now);$id=$saved['configuration_id'];
            $fields=['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id','llm_experimental_configuration_id'];
            if($saved['scope']==='all')$fields=array_merge($fields,['diary_generation_configuration_id','player_autochat_configuration_id']);
            foreach($plan['core_profiles'] as $core){
                $current=$this->getRevisioned('core_profile',(string)$core['core_profile_id']);$content=$current['content'];
                foreach($fields as $field)$content['routing'][$field]=$id;
                $this->revise('core_profile',(string)$core['core_profile_id'],$content,'Quickstart Local LLM routing',$now,(int)$core['current_revision']);
            }
            if($saved['scope']==='all'){
                $global=$this->globalSettingsForInstallation($installation);
                $content=EffectiveSettingsResolver::globalDocument($global['content']??[], $this->oghmaSettings($installation),
                    $this->translationPolicyForInstallation($installation)['content'],$this->profileAutoLockEnabled($installation));
                foreach(SettingsCatalog::systemRoutingFields() as $field)$content['system_routing'][$field]=$id;
                if($global===null)$this->createRevisioned('global_settings',['installation_id'=>$installation,'name'=>'Global Settings','content'=>$content],$now);
                else $this->revise('global_settings',(string)$global['configuration_id'],$content,'Quickstart Local LLM background routing',$now,(int)$global['current_revision']);
                $summary=$this->memorySummaryPolicyForInstallation($installation);
                $content=$summary['content']??['schema'=>'lorkhan.memory-policy.v1','enabled'=>false,'provider_configuration_id'=>''];
                $content['provider_configuration_id']=$id;
                if($summary===null)$this->createRevisioned('memory_policy',['installation_id'=>$installation,'name'=>'Memory Summary Policy','content'=>$content],$now);
                else $this->revise('memory_policy',(string)$summary['configuration_id'],$content,'Quickstart Local LLM summary routing',$now,(int)$summary['current_revision']);
            }
            return $saved+['routing_plan'=>$this->quickstartLocalRoutingPlan($installation)];
        });
    }

    /** Return the single live revisioned settings document for one installation. */
    public function globalSettingsForInstallation(string $installationId):?array
    {
        $stmt=$this->db->prepare("SELECT c.configuration_id,c.current_revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind='global_settings' AND c.deleted_at IS NULL LIMIT 1");
        $stmt->execute(['installation'=>$installationId]);$row=$stmt->fetch();if(!$row)return null;$row['content']=$this->json($row['content']);return$row;
    }

    /** Return the current server-only NPC translation policy, or its safe disabled default. */
    public function translationPolicyForInstallation(string $installationId):array
    {
        $stmt=$this->db->prepare("SELECT c.configuration_id,c.current_revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind='translation_policy' AND c.deleted_at IS NULL LIMIT 1");
        $stmt->execute(['installation'=>$installationId]);$row=$stmt->fetch();
        if(!$row)return['configuration_id'=>null,'current_revision'=>0,'content'=>\LorkhanServer\Application\TranslationPolicy::defaults()];
        $row['current_revision']=(int)$row['current_revision'];
        $row['content']=\LorkhanServer\Application\TranslationPolicy::validate($this->json($row['content']));return$row;
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
            $id=$this->deterministicUuid('lorkhan:core-profile:default:v1:'.$installationId);
            $content=['schema'=>'lorkhan.core-profile.v1','prompt'=>'','routing'=>[],'settings_overrides'=>[]];
            $content=$this->withCoreCreationDefaults($installationId,$content);
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
        $statement=$this->db->prepare('UPDATE profiles p SET core_profile_id=:core FROM core_profiles c WHERE p.profile_id=:profile AND p.installation_id=c.installation_id AND c.core_profile_id=:core AND p.deleted_at IS NULL AND '.ProfileScopeSql::current('p').' AND c.deleted_at IS NULL');
        $statement->execute(['profile'=>$profileId,'core'=>$coreProfileId]);if($statement->rowCount()!==1)throw new \InvalidArgumentException('core_profile_scope_mismatch');
    }

    /** Keep persona text and its same-installation Core Profile assignment atomic. */
    public function revisePersona(string $profileId,array $content,string $reason,string $coreProfileId,string $now):array
    {
        return$this->transaction(function()use($profileId,$content,$reason,$coreProfileId,$now):array{
            $profile=$this->getRevisioned('profile',$profileId);
            $identity=is_array($profile['actor_identity'])?$profile['actor_identity']:$this->json($profile['actor_identity']);
            if(!in_array($identity['kind']??'actor',['narrator','player'],true))throw new \InvalidArgumentException('profile_not_persona');
            if($coreProfileId!=='')$this->assignCoreProfile($profileId,$coreProfileId);
            return$this->revise('profile',$profileId,$content,$reason,$now);
        });
    }

    /** Rename only the installation's player persona; recorded source events keep their original identities. */
    public function renamePlayer(string $installation,string $name,int $expectedRevision,string $now):array
    {
        return $this->transaction(function()use($installation,$name,$expectedRevision,$now):array{
            $statement=$this->db->prepare("SELECT profile_id,current_revision,name FROM profiles WHERE installation_id=:installation AND deleted_at IS NULL AND ".ProfileScopeSql::current('profiles')." AND actor_identity->>'kind'='player' ORDER BY created_at,profile_id LIMIT 1 FOR UPDATE");
            $statement->execute(['installation'=>$installation]);$row=$statement->fetch();
            if(!$row)throw new RuntimeException('not_found');
            if((int)$row['current_revision']!==$expectedRevision)throw new RuntimeException('revision_conflict');
            $profile=$this->getRevisioned('profile',$row['profile_id']);
            if($row['name']===$name)return $profile;
            $this->db->prepare("UPDATE profiles SET name=:name,actor_identity=jsonb_set(actor_identity,'{display_name}',to_jsonb(CAST(:display AS text))) WHERE profile_id=:id")
                ->execute(['name'=>$name,'display'=>$name,'id'=>$row['profile_id']]);
            return $this->revise('profile',$row['profile_id'],$profile['content'],'Quickstart player name update',$now,$expectedRevision);
        });
    }

    /** Rename and revise the selected player atomically; immutable event identities stay untouched. */
    public function revisePlayer(string $installation,string $id,string $name,array $content,string $reason,int $expectedRevision,string $now):array
    {
        try{return $this->transaction(function()use($installation,$id,$name,$content,$reason,$expectedRevision,$now):array{
            $query=$this->db->prepare("SELECT current_revision FROM profiles WHERE profile_id=:id AND installation_id=:installation AND deleted_at IS NULL AND ".ProfileScopeSql::current('profiles')." AND actor_identity->>'kind'='player' FOR UPDATE");
            $query->execute(['id'=>$id,'installation'=>$installation]);$revision=$query->fetchColumn();
            if($revision===false)throw new RuntimeException('not_found');
            if((int)$revision!==$expectedRevision)throw new RuntimeException('revision_conflict');
            $this->db->prepare("UPDATE profiles SET name=:name,actor_identity=jsonb_set(actor_identity,'{display_name}',to_jsonb(CAST(:display AS text))) WHERE profile_id=:id")
                ->execute(['name'=>$name,'display'=>$name,'id'=>$id]);
            return $this->revise('profile',$id,$content,$reason,$now,$expectedRevision);
        });}catch(\PDOException $error){if($error->getCode()==='23505')throw new InvalidArgumentException('player_name_exists');throw $error;}
    }

    /** Rename only the Narrator display fields; stable identity and recorded events are untouched. */
    public function reviseNarrator(string $installation,string $id,string $name,array $content,string $reason,string $coreProfileId,int $expectedRevision,string $now):array
    {
        try{return $this->transaction(function()use($installation,$id,$name,$content,$reason,$coreProfileId,$expectedRevision,$now):array{
            $query=$this->db->prepare("SELECT current_revision FROM profiles WHERE profile_id=:id AND installation_id=:installation AND deleted_at IS NULL AND actor_identity->>'kind'='narrator' FOR UPDATE");
            $query->execute(['id'=>$id,'installation'=>$installation]);$revision=$query->fetchColumn();
            if($revision===false)throw new RuntimeException('not_found');
            if((int)$revision!==$expectedRevision)throw new RuntimeException('revision_conflict');
            if($coreProfileId!=='')$this->assignCoreProfile($id,$coreProfileId);
            $this->db->prepare("UPDATE profiles SET name=:name,actor_identity=jsonb_set(actor_identity,'{display_name}',to_jsonb(CAST(:display AS text))) WHERE profile_id=:id")
                ->execute(['name'=>$name,'display'=>$name,'id'=>$id]);
            return $this->revise('profile',$id,$content,$reason,$now,$expectedRevision);
        });}catch(\PDOException $error){if($error->getCode()==='23505')throw new InvalidArgumentException('narrator_name_exists');throw $error;}
    }

    /** Materialize the installation-scoped player profile before the first game turn needs it. */
    public function ensurePlayerProfile(string $installationId,string $now,?string $playthroughId=null):array
    {
        return$this->transaction(function()use($installationId,$now,$playthroughId):array{$active=(new ProfileOwnershipRepository($this->db))->activePlaythrough($installationId);$playthroughId=$active===null?null:($playthroughId??$active);$existing=$this->playerProfileForInstallation($installationId,$playthroughId);if($existing!==null)return$existing;
            $id=Uuid::v4();$this->db->prepare('INSERT INTO profiles (profile_id,installation_id,playthrough_id,name,actor_identity,created_at) VALUES (:id,:installation,:playthrough,:name,CAST(:identity AS jsonb),:now)')->execute([
                'id'=>$id,'installation'=>$installationId,'playthrough'=>$playthroughId,'name'=>'Player','identity'=>$this->encode(['kind'=>'player','display_name'=>'Player']),'now'=>$now]);
            $this->revision('profile_revisions','profile_id',$id,1,['biography'=>'','appearance'=>'','personality'=>'','speech_style'=>'','goals'=>'','notes'=>''],'created automatically on session start',$now);
            return$this->getRevisioned('profile',$id);});
    }

    /** Return every live profile, connector and global fallback reference grouped by normalized local TTS voice ID. */
    public function voiceReferenceIndex():array
    {
        $sql="SELECT voice,source,label FROM ("
            ."SELECT CASE WHEN jsonb_typeof(r.content->'voice')='string' THEN r.content->>'voice' ELSE COALESCE(r.content#>>'{voice,id}',r.content#>>'{voice,voice_id}') END AS voice,'Profile' AS source,p.name AS label "
            ."FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL "
            ."UNION ALL SELECT r.content->>'voice' AS voice,'Connector' AS source,c.name AS label FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.deleted_at IS NULL AND c.kind='tts_provider' "
            ."UNION ALL SELECT voiceid AS voice,'Global fallback' AS source,race||' '||gender AS label FROM public.core_tts_fallback) voice_refs WHERE btrim(COALESCE(voice,''))<>'' ORDER BY source,label";
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

    public function revise(string $kind, string $id, array $content, string $reason, string $now, ?int $expectedRevision = null): array
    {
        return $this->transaction(function () use ($kind,$id,$content,$reason,$now,$expectedRevision): array {
            if($kind==='global_settings')$this->db->query("SELECT pg_advisory_xact_lock(7514,120)");
            [$table,$key,$revisions] = $this->revisionMeta($kind);
            $stmt = $this->db->prepare("SELECT current_revision FROM {$table} WHERE {$key}=:id AND deleted_at IS NULL".($kind==='profile'?' AND '.ProfileScopeSql::current('profiles'):'')." FOR UPDATE");
            $stmt->execute(['id'=>$id]);
            $current = $stmt->fetchColumn();
            if ($current === false) throw new RuntimeException('not_found');
            if ($expectedRevision !== null && (int) $current !== $expectedRevision) {
                throw new RuntimeException('revision_conflict');
            }
            if($kind==='memory_policy'){
                $policy=$this->getRevisioned($kind,$id);
                (new MemorySummaryRepository($this->db))->assertProvider($policy['installation_id'],$content);
            }
            $next = (int)$current + 1;
            if($kind==='provider'&&($content['service']??'')!=='player2')
                (new Player2RoutingRepository($this->db))->assertNotActive($id);
            $this->revision($revisions, $key, $id, $next, $content, $reason, $now);
            $this->db->prepare("UPDATE {$table} SET current_revision=:revision WHERE {$key}=:id")->execute(['revision'=>$next,'id'=>$id]);
            if($kind==='prompt')$this->syncPrompt($id,$content,$next,$now);
            if($kind==='global_settings'&&($content['client']['behavior']['ai_enabled']??true)===false)
                (new Repository($this->db))->cancelAiOutput($this->getRevisioned($kind,$id)['installation_id']);
            return $this->getRevisioned($kind,$id);
        });
    }

    /** Rename and revise one connector atomically without changing its identity or any assignments. */
    public function reviseNamedConnector(string $kind,string $id,string $name,array $content,string $reason,string $now):array
    {
        if(!in_array($kind,['provider','tts_provider','stt_provider'],true))throw new InvalidArgumentException('invalid_connector_kind');
        return $this->transaction(function()use($kind,$id,$name,$content,$reason,$now):array{
            $row=$this->db->prepare('SELECT configuration_id FROM configuration_sets WHERE configuration_id=:id AND kind=:kind AND deleted_at IS NULL FOR UPDATE');
            $row->execute(['id'=>$id,'kind'=>$kind]);if($row->fetchColumn()===false)throw new RuntimeException('not_found');
            try {
                $this->db->prepare('UPDATE configuration_sets SET name=:name WHERE configuration_id=:id')->execute(['name'=>$name,'id'=>$id]);
            } catch (\PDOException $error) {
                if($error->getCode()==='23505')throw new InvalidArgumentException('connector_name_in_use');
                throw $error;
            }
            return $this->revise($kind,$id,$content,$reason,$now);
        });
    }

    /** Copy one explicitly confirmed draft setting across current Core Profiles, without replacing their other values. */
    public function copyCoreProfileSetting(array $input): array
    {
        $keys = array_keys($input); sort($keys);
        if ($keys !== ['confirm', 'core_profile_id', 'revision', 'setting', 'value']) throw new InvalidArgumentException('invalid_profile_setting_copy');
        $id = $input['core_profile_id'] ?? null;
        $setting = $input['setting'] ?? null;
        $revision = $input['revision'] ?? null;
        $allowed = ['quest_comments.enabled', 'quest_comments.chance_percent', 'bored_event.chance_percent', 'response.max_words', 'response.core_lang', 'response.lang_llm_xtts', 'behavior.rechat_max_depth', 'behavior.rechat_probability_percent',
            'profile_evolution.history_limit','profile_evolution.interval_days','profile_evolution.min_events','profile_evolution.cooldown_minutes', 'behavior.rechat_allow_actions', 'behavior.combat_bark_period_seconds', 'memory.recent_turn_limit', 'diary.context_turn_limit',
            'diary.automatic_interval_seconds', 'diary.prompt'];
        if (!is_string($id) || !Uuid::isValid($id) || !is_string($setting) || !in_array($setting, $allowed, true)
            || !is_int($revision) || $revision < 1 || !array_key_exists('value', $input)) throw new InvalidArgumentException('invalid_profile_setting_copy');
        if (($input['confirm'] ?? null) !== 'Copy to all') throw new InvalidArgumentException('confirmation_mismatch');
        [$section, $field] = explode('.', $setting, 2);
        $value = $input['value'];
        EffectiveSettingsResolver::validateSettingsOverrides([$section => ($section==='profile_evolution'?EffectiveSettingsResolver::profileEvolutionDefaults(null):[]) + [$field => $value]]);
        return $this->transaction(function () use ($id, $setting, $revision, $section, $field, $value): array {
            $source = $this->getRevisioned('core_profile', $id);
            // Stable lock order prevents concurrent bulk operations from deadlocking. Never silently copy a partial page.
            $lock = $this->db->prepare('SELECT core_profile_id,current_revision FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY core_profile_id LIMIT 1001 FOR UPDATE');
            $lock->execute(['installation' => $source['installation_id']]);
            $profiles = $lock->fetchAll();
            if (count($profiles) > 1000) throw new InvalidArgumentException('profile_copy_limit_exceeded');
            $source = $this->getRevisioned('core_profile', $id);
            if ((int)$source['current_revision'] !== $revision) throw new RuntimeException('revision_conflict');
            $changed = 0;
            $sourceRevision = $revision;
            foreach ($profiles as $profile) {
                $current = $this->getRevisioned('core_profile', $profile['core_profile_id']);
                $content = $current['content'];
                if (array_key_exists($field, $content['settings_overrides'][$section] ?? [])
                    && $content['settings_overrides'][$section][$field] === $value) continue;
                if($section==='profile_evolution')$content['settings_overrides'][$section]=EffectiveSettingsResolver::profileEvolutionDefaults($content['settings_overrides'][$section]??null);
                $content['settings_overrides'][$section][$field] = $value;
                EffectiveSettingsResolver::validateCoreProfile($content);
                $updated = $this->revise('core_profile', $profile['core_profile_id'], $content, 'Copy setting to all: ' . $setting,
                    gmdate('Y-m-d\TH:i:s\Z'), (int)$current['current_revision']);
                if ($profile['core_profile_id'] === $id) $sourceRevision = (int)$updated['current_revision'];
                $changed++;
            }
            return ['profiles_updated' => $changed, 'profiles_total' => count($profiles), 'revision' => $sourceRevision];
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

    /** Prepare optional import assignments under the caller's transaction without changing any profile content. */
    public function prepareCoreProfileImport(string $installation,string $name,?int $slot,string $now):array
    {
        $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:id FOR UPDATE')->execute(['id'=>$installation]);
        $previous=$this->defaultCoreProfileForInstallation($installation,$now,true);
        $query=$this->db->prepare('SELECT label FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL');
        $query->execute(['installation'=>$installation]);$used=[];
        foreach($query->fetchAll(PDO::FETCH_COLUMN) as $label)$used[mb_strtolower(trim($label))]=true;
        $label=$name;
        for($index=2;isset($used[mb_strtolower($label)])&&$index<5000;$index++){
            $suffix=' '.$index;$label=mb_strcut($name,0,128-strlen($suffix),'UTF-8').$suffix;
        }
        if(isset($used[mb_strtolower($label)]))throw new RuntimeException('core_profile_import_name_unavailable');
        if($slot!==null)$this->db->prepare('UPDATE core_profiles SET slot=NULL WHERE installation_id=:installation AND slot=:slot AND deleted_at IS NULL')
            ->execute(['installation'=>$installation,'slot'=>$slot]);
        return['name'=>$label,'previous_default'=>$previous['core_profile_id']];
    }

    /** Reuse an owned connector only when its settings match; a label alone must not silently replace imported settings. */
    public function matchingBundleConfiguration(string $installation,string $kind,string $name,array $content):?string
    {
        $query=$this->db->prepare('SELECT c.configuration_id,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind=:kind AND c.name=:name AND c.deleted_at IS NULL ORDER BY c.created_at,c.configuration_id');
        $query->execute(['installation'=>$installation,'kind'=>$kind,'name'=>$name]);
        foreach($query->fetchAll() as $row){
            $existing=\LorkhanServer\Application\CoreProfileBundle::portableConfiguration($kind,$this->json($row['content']));
            if($existing==$content)return(string)$row['configuration_id'];
        }
        return null;
    }

    /** Name a copied connector without colliding with entries beyond the first UI page. */
    public function importedConnectorName(string $installationId,string $base,string $kind):string
    {
        $query=$this->db->prepare("SELECT name FROM configuration_sets WHERE installation_id=:installation AND kind=:kind AND deleted_at IS NULL");
        $query->execute(['installation'=>$installationId,'kind'=>$kind]);
        $used=[];foreach($query->fetchAll(\PDO::FETCH_COLUMN) as $name)$used[mb_strtolower(trim($name))]=true;
        if(!isset($used[mb_strtolower($base)]))return$base;
        for($index=2;$index<5000;$index++){
            $suffix=' '.$index;$candidate=mb_strcut($base,0,128-strlen($suffix),'UTF-8').$suffix;
            if(!isset($used[mb_strtolower($candidate)]))return$candidate;
        }
        throw new RuntimeException('connector_import_name_unavailable');
    }

    public function listRevisioned(string $kind, string $installationId): array
    {
        [$table,$key,$revisions] = $this->revisionMeta($kind);
        $kindFilter=$table==='configuration_sets'?' AND b.kind=:kind':'';
        $profileFilter=$kind==='profile'?' AND '.ProfileScopeSql::current('b'):'';
        $nameField=$kind==='core_profile'?'label':'name';
        $stmt=$this->db->prepare("SELECT b.{$key} AS id,b.{$nameField} AS name,b.current_revision,b.created_at,r.content FROM {$table} b JOIN {$revisions} r ON r.{$key}=b.{$key} AND r.revision=b.current_revision WHERE b.installation_id=:installation{$kindFilter}{$profileFilter} AND b.deleted_at IS NULL ORDER BY b.{$nameField} LIMIT 100");
        $parameters=['installation'=>$installationId];if($table==='configuration_sets')$parameters['kind']=$kind;
        $stmt->execute($parameters);
        return array_map(fn(array $r):array=>$r+['content'=>$this->json($r['content'])],$stmt->fetchAll());
    }

    /** Browse bounded, installation-owned observations without exposing recorded context documents. */
    public function contextFilterCandidates(string $installationId, string $kind): array
    {
        if (!Uuid::isValid($installationId)) throw new InvalidArgumentException('invalid_installation_id');
        if (!in_array($kind, ['locations', 'items', 'magic', 'event_types'], true)) throw new InvalidArgumentException('invalid_filter_kind');
        $exists = $this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation');
        $exists->execute(['installation'=>$installationId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('not_found');
        if ($kind === 'event_types') {
            $query = $this->db->prepare('SELECT type,count(*) AS hits FROM (SELECT e.type FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid '
                . 'WHERE m.installation_id=:installation ORDER BY e.rowid DESC LIMIT 5000) recent GROUP BY type');
            $query->execute(['installation'=>$installationId]);
            $counts = $query->fetchAll(PDO::FETCH_KEY_PAIR);
            return ['items'=>array_map(static fn(string $type): array => ['value'=>$type,'count'=>(int)($counts[$type]??0)], SettingsCatalog::eventTypes()), 'scan_limit'=>5000];
        }
        $paths = [];
        if ($kind === 'locations') $paths = ['strict $.world.cell', 'strict $.world.region', 'strict $.cell', 'strict $.region'];
        if ($kind === 'items' || $kind === 'magic') {
            foreach (['playerState', 'targetState'] as $state) {
                foreach ($kind === 'items' ? ['equipment', 'inventory'] : ['spells', 'activeEffects', 'active_effects'] as $field) {
                    $paths[] = 'strict $.' . $state . '.' . $field . '[*]';
                    $paths[] = 'strict $.' . $state . '.' . $field . '.items[*]';
                }
            }
            if ($kind === 'items') {
                foreach (['strict $.nearbyObjects[*]', 'strict $.nearbyObjects.items[*]'] as $path) $paths[] = $path . ' ? (@.kind == "items")';
                foreach (['strict $.nearbyActors[*]', 'strict $.nearbyActors.items[*]'] as $path) {
                    $paths[] = $path . '.equipment[*]';
                    $paths[] = $path . '.equipment.items[*]';
                }
            }
        }
        $observationType=$kind==='magic'?'gamedata.spell_cast':($kind==='items'?'gamedata.item_pickup':null);
        $observationFields=$kind==='magic'?['spell_id','spell_name']:['item_record_id','item_name'];
        $observationCte=$observationType!==null ? "recent_observations AS MATERIALIZED (SELECT e.payload->'payload' AS payload FROM source_events e WHERE e.installation_id=:installation AND e.event_kind=:observation_type AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=e.source_event_id) AND EXISTS(SELECT 1 FROM sessions current_session WHERE current_session.installation_id=e.installation_id AND current_session.playthrough_id::text=e.payload->>'playthrough_id' AND current_session.state='active' AND ((e.session_id=current_session.session_id AND e.generation=current_session.generation) OR (jsonb_typeof(e.payload#>'{payload,calendar}')='object' AND EXISTS(SELECT 1 FROM source_events load WHERE load.session_id=current_session.session_id AND load.event_kind='session.init' AND jsonb_typeof(load.payload->'loaded_save')='object')))) ORDER BY e.received_at DESC,e.source_event_id DESC LIMIT 5000), " : '';
        $observationNames=$observationType!==null ? " UNION ALL SELECT btrim(observation.name) FROM recent_observations CROSS JOIN LATERAL (SELECT DISTINCT name FROM (VALUES (payload->>'".$observationFields[0]."'),(payload->>'".$observationFields[1]."')) v(name)) observation" : '';
        // JSON projection and aggregation stay in PostgreSQL; never transfer thousands of full prompts to PHP.
        $query = $this->db->prepare("WITH recent AS MATERIALIZED (SELECT t.context FROM active_turns t JOIN sessions s ON s.session_id=t.session_id "
            . "WHERE s.installation_id=:installation AND NOT s.archived ORDER BY t.accepted_at DESC LIMIT 5000), ".$observationCte."names AS ("
            . "SELECT btrim(CASE jsonb_typeof(v.value) WHEN 'string' THEN v.value#>>'{}' WHEN 'object' THEN COALESCE(v.value->>'display_name',v.value->>'name',v.value->>'record_id') END) AS name "
            . "FROM recent CROSS JOIN jsonb_array_elements_text(CAST(:paths AS jsonb)) p(path) "
            . "CROSS JOIN LATERAL jsonb_path_query(recent.context,p.path::jsonpath,'{}',true) v(value)".$observationNames.") "
            . "SELECT min(name) AS value,count(*) AS count FROM names WHERE name<>'' AND octet_length(name)<=256 AND name !~ '[[:cntrl:]]' "
            . "GROUP BY lower(name) ORDER BY count(*) DESC,lower(name) LIMIT 500");
        $query->execute(['installation'=>$installationId,'paths'=>json_encode($paths, JSON_THROW_ON_ERROR)]+($observationType!==null?['observation_type'=>$observationType]:[]));
        return ['items'=>array_map(static fn(array $row): array => ['value'=>$row['value'],'count'=>(int)$row['count']], $query->fetchAll()), 'scan_limit'=>5000];
    }

    /** Describe saved, enabled global LLM routes without returning configuration payloads or calling providers. */
    public function globalConnectorTestPlan(string $installationId):array
    {
        if(!Uuid::isValid($installationId))throw new InvalidArgumentException('invalid_installation_id');
        $exists=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation');
        $exists->execute(['installation'=>$installationId]);if(!$exists->fetchColumn())throw new RuntimeException('not_found');
        $stored=$this->globalSettingsForInstallation($installationId);
        $settings=EffectiveSettingsResolver::globalDocument($stored['content']??[], $this->oghmaSettings($installationId),
            $this->translationPolicyForInstallation($installationId)['content'],$this->profileAutoLockEnabled($installationId));
        $summary=$this->memorySummaryPolicyForInstallation($installationId)['content']??[];
        $routing=$settings['system_routing'];
        $definitions=[
            ['memory_summary_connector','Summaries',(string)($summary['provider_configuration_id']??''),($summary['enabled']??false)===true],
            ['background_memory_configuration_id','Background & Memory Tasks',$routing['background_memory_configuration_id'],$settings['task_availability']['background_memory']],
            ['director_configuration_id','Director',$routing['director_configuration_id'],$settings['task_availability']['director']],
            ['scene_classifier_configuration_id','Scene Classifier',(string)((new SceneClassificationRepository($this->db))->route($installationId)['configuration_id']??''),$settings['task_availability']['scene_classifier']],
            ['profile_generation_configuration_id','Profile Tasks',$routing['profile_generation_configuration_id'],$settings['task_availability']['profile_generation']],
            ['oghma_configuration_id','Custom Oghma LLM',$routing['oghma_configuration_id'],$settings['oghma']['enabled']&&$settings['oghma']['extractor_enabled']],
            ['relationship_configuration_id','Relationship Management',$routing['relationship_configuration_id'],
                $settings['relationship']['enabled']&&$settings['relationship']['update_chance_percent']>0],
        ];
        $query=$this->db->prepare("SELECT configuration_id,name FROM configuration_sets WHERE installation_id=:installation AND kind='provider' AND deleted_at IS NULL");
        $query->execute(['installation'=>$installationId]);$labels=$query->fetchAll(PDO::FETCH_KEY_PAIR);
        $jobs=[];$slots=[];
        foreach($definitions as[$field,$label,$id,$enabled]){
            $status='pending';$message='Waiting to test';$jobKey=null;
            if(!$enabled){$status='skipped';$message='Task disabled in saved settings';}
            elseif($id===''){$status='skipped';$message='No connector selected';}
            elseif(!isset($labels[$id])){$status='warn';$message='Selected connector is unavailable';}
            else{$jobKey='provider:'.$id;$jobs[$jobKey]=['job_key'=>$jobKey,'kind'=>'provider','configuration_id'=>$id,'label'=>$labels[$id]];}
            $slots[]=['field'=>$field,'label'=>$label,'kind'=>'provider','configuration_id'=>$id?:null,
                'connector_label'=>$id===''?'No connector selected':($labels[$id]??'Unavailable connector'),
                'job_key'=>$jobKey,'status'=>$status,'message'=>$message];
        }
        return['groups'=>[['label'=>'Global Connectors','slots'=>$slots]],'jobs'=>array_values($jobs)];
    }

    /** Build the deduplicated, read-only connector test plan shown by the Core Profiles UI. */
    public function coreProfileConnectorTestPlan(string $installationId):array
    {
        $profiles=$this->db->prepare('SELECT c.core_profile_id,c.label,c.default_npc,r.content FROM core_profiles c '
            .'JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision '
            .'WHERE c.installation_id=:installation AND c.deleted_at IS NULL ORDER BY c.default_npc DESC,lower(c.label),c.core_profile_id LIMIT 100');
        $profiles->execute(['installation'=>$installationId]);
        $configurations=$this->db->prepare("SELECT configuration_id,kind,name FROM configuration_sets WHERE installation_id=:installation AND kind IN ('provider','tts_provider') AND deleted_at IS NULL");
        $configurations->execute(['installation'=>$installationId]);$labels=[];
        foreach($configurations->fetchAll()as$row)$labels[(string)$row['kind'].':'.(string)$row['configuration_id']]=(string)$row['name'];
        $definitions=[
            ['tts_configuration_id','TTS Connector','tts_provider'],
            ['llm_configuration_id','Standard LLM','provider'],
            ['llm_fast_configuration_id','Fast LLM','provider'],
            ['llm_powerful_configuration_id','Powerful LLM','provider'],
            ['llm_experimental_configuration_id','Experimental LLM','provider'],
            ['llm_fallback_configuration_id','Fallback LLM','provider'],
            ['oghma_configuration_id','Oghma Extractor','provider'],
            ['profile_generation_configuration_id','Profile Generation LLM','provider'],
            ['relationship_configuration_id','Relationship LLM','provider'],
            ['diary_generation_configuration_id','Diary LLM','provider'],
        ];
        $jobs=[];$profileRows=[];
        foreach($profiles->fetchAll()as$profile){$content=$this->json($profile['content']);$routing=is_array($content['routing']??null)?$content['routing']:[];$slots=[];
            foreach($definitions as[$field,$label,$kind]){$configurationId=trim((string)($routing[$field]??''));
                if($configurationId===''){$slots[]=['field'=>$field,'label'=>$label,'kind'=>$kind,'configuration_id'=>null,'job_key'=>null,
                    'status'=>'skipped','message'=>'No connector selected'];continue;}
                $jobKey=$kind.':'.$configurationId;$configurationLabel=$labels[$jobKey]??'Unavailable connector';
                $jobs[$jobKey]=['job_key'=>$jobKey,'kind'=>$kind,'configuration_id'=>$configurationId,'label'=>$configurationLabel];
                $slots[]=['field'=>$field,'label'=>$label,'kind'=>$kind,'configuration_id'=>$configurationId,'job_key'=>$jobKey,
                    'status'=>'pending','message'=>'Waiting to test '.$configurationLabel];
            }
            $profileRows[]=['id'=>(string)$profile['core_profile_id'],'label'=>(string)$profile['label'],
                'default_npc'=>filter_var($profile['default_npc'],FILTER_VALIDATE_BOOL),'slots'=>$slots];
        }
        if(count($jobs)>100)throw new InvalidArgumentException('profile_connector_test_too_many_connectors');
        return['profiles'=>$profileRows,'jobs'=>array_values($jobs)];
    }

    /** Return bounded rules and observed OpenMW values without changing an NPC or contacting a provider. */
    public function profileAssignmentRulesPlan(string $installationId):array
    {
        $exists=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:installation');
        $exists->execute(['installation'=>$installationId]);if(!$exists->fetchColumn())throw new RuntimeException('not_found');
        $cores=$this->db->prepare('SELECT core_profile_id,label,default_npc FROM core_profiles '
            .'WHERE installation_id=:installation AND deleted_at IS NULL ORDER BY default_npc DESC,lower(label),core_profile_id LIMIT 100');
        $cores->execute(['installation'=>$installationId]);$coreRows=array_map(static fn(array$row):array=>[
            'core_profile_id'=>(string)$row['core_profile_id'],'label'=>(string)$row['label'],
            'default_npc'=>filter_var($row['default_npc'],FILTER_VALIDATE_BOOL)],$cores->fetchAll());
        $rules=$this->db->prepare('SELECT r.rule_id,r.description,r.core_profile_id,c.label AS core_profile_label,r.priority,r.enabled,r.matchers '
            .'FROM profile_assignment_rules r LEFT JOIN core_profiles c ON c.core_profile_id=r.core_profile_id '
            .'AND c.installation_id=r.installation_id AND c.deleted_at IS NULL WHERE r.installation_id=:installation AND (r.core_profile_id IS NULL OR c.core_profile_id IS NOT NULL) '
            .'ORDER BY r.priority DESC,r.created_at DESC,r.rule_id DESC LIMIT 100');
        $rules->execute(['installation'=>$installationId]);$ruleRows=[];$options=array_fill_keys(self::PROFILE_RULE_MATCH_FIELDS,[]);
        foreach($rules->fetchAll()as$row){$match=$this->normalizeProfileRuleMatch($this->json($row['matchers']));
            foreach(self::PROFILE_RULE_MATCH_FIELDS as$field)foreach($match[$field]as$value)$options[$field][mb_strtolower($value,'UTF-8')]=$value;
            $ruleRows[]=['rule_id'=>(string)$row['rule_id'],'description'=>(string)$row['description'],
                'core_profile_id'=>(string)$row['core_profile_id'],'core_profile_label'=>(string)$row['core_profile_label'],
                'priority'=>(int)$row['priority'],'enabled'=>filter_var($row['enabled'],FILTER_VALIDATE_BOOL),'match'=>$match];}
        $profiles=$this->db->prepare('SELECT p.name,p.actor_identity,r.content FROM profiles p JOIN profile_revisions r '
            .'ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation '
            ."AND p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') LIMIT 1000");
        $profiles->execute(['installation'=>$installationId]);
        foreach($profiles->fetchAll()as$row){$identity=$this->json($row['actor_identity']);$content=$this->json($row['content']);
            $this->addProfileRuleOption($options['names'],$identity['display_name']??$row['name']??null);
            $this->addProfileRuleOption($options['races'],$content['race']??null);
            $this->addProfileRuleOption($options['classes'],$content['class']??null);
            $this->addProfileRuleOption($options['genders'],$content['gender']??null);
            $this->addProfileRuleOption($options['content_files'],$identity['content_file']??null);}
        $turns=$this->db->prepare('SELECT t.target,t.context FROM active_turns t JOIN sessions s ON s.session_id=t.session_id '
            .'WHERE s.installation_id=:installation AND NOT s.archived AND (NOT EXISTS(SELECT 1 FROM character_playthrough_bindings cb WHERE cb.installation_id=s.installation_id) OR s.playthrough_id=(SELECT latest_scope.playthrough_id FROM sessions latest_scope WHERE latest_scope.installation_id=s.installation_id AND latest_scope.character_id IS NOT NULL ORDER BY latest_scope.generation DESC LIMIT 1)) ORDER BY t.accepted_at DESC LIMIT 1000');
        $turns->execute(['installation'=>$installationId]);
        foreach($turns->fetchAll()as$row){$target=$this->json($row['target']);$context=$this->json($row['context']);
            $observed=$this->profileRuleActorValues($target,$context);
            foreach(self::PROFILE_RULE_MATCH_FIELDS as$field)foreach($observed[$field]as$value)$this->addProfileRuleOption($options[$field],$value);}
        foreach($options as&$values){$values=array_values($values);natcasesort($values);$values=array_slice(array_values($values),0,500);}unset($values);
        return['rules'=>$ruleRows,'core_profiles'=>$coreRows,'options'=>$options];
    }

    /** Create or update an installation-owned simple or advanced assignment rule. */
    public function saveProfileAssignmentRule(array $input,string $now):array
    {
        $installation=trim((string)($input['installation_id']??''));$core=trim((string)($input['core_profile_id']??''));
        $description=trim((string)($input['description']??''));$priority=filter_var($input['priority']??null,FILTER_VALIDATE_INT);
        $ruleId=$input['rule_id']??null;$ruleId=is_string($ruleId)&&trim($ruleId)!==''?trim($ruleId):null;
        if(!Uuid::isValid($installation))throw new InvalidArgumentException('invalid_installation_id');
        if($core!==''&&!Uuid::isValid($core))throw new InvalidArgumentException('invalid_core_profile_id');
        if($ruleId!==null&&!Uuid::isValid($ruleId))throw new InvalidArgumentException('invalid_rule_id');
        if($description===''||strlen($description)>200||preg_match('/[\x00-\x1F\x7F]/',$description)===1)throw new InvalidArgumentException('invalid_rule_description');
        if($priority===false||$priority< -100000||$priority>100000)throw new InvalidArgumentException('invalid_rule_priority');
        $matchInput=$input['match']??null;
        if(isset($input['advanced'])){if(!is_array($matchInput))throw new InvalidArgumentException('invalid_rule_match');$matchInput['_advanced']=$input['advanced'];}
        $match=$this->normalizeProfileRuleMatch($matchInput,true);
        if($core===''&&empty($match['_advanced']['action']))throw new InvalidArgumentException('rule_action_or_profile_required');$enabled=($input['enabled']??false)===true;
        return$this->transaction(function()use($installation,$core,$description,$priority,$ruleId,$match,$enabled,$now):array{
            $this->profileRuleRegexRows([['rule_id'=>'validation','matchers'=>$match]],[],true);
            if($core!==''){$target=$this->db->prepare('SELECT 1 FROM core_profiles WHERE core_profile_id=:core AND installation_id=:installation AND deleted_at IS NULL FOR SHARE');
            $target->execute(['core'=>$core,'installation'=>$installation]);if(!$target->fetchColumn())throw new InvalidArgumentException('core_profile_scope_mismatch');}
            $id=$ruleId??Uuid::v4();
            if($ruleId===null){$count=$this->db->prepare('SELECT count(*) FROM profile_assignment_rules WHERE installation_id=:installation');
                $count->execute(['installation'=>$installation]);if((int)$count->fetchColumn()>=100)throw new InvalidArgumentException('profile_assignment_rule_limit');
                $this->db->prepare('INSERT INTO profile_assignment_rules '
                    .'(rule_id,installation_id,core_profile_id,description,priority,enabled,matchers,updated_at) '
                    .'VALUES(:id,:installation,:core,:description,:priority,:enabled,CAST(:matchers AS jsonb),:now)')
                    ->execute(['id'=>$id,'installation'=>$installation,'core'=>$core===''?null:$core,'description'=>$description,'priority'=>$priority,
                        'enabled'=>$enabled?'true':'false','matchers'=>$this->encode($match),'now'=>$now]);
            }else{$update=$this->db->prepare('UPDATE profile_assignment_rules SET core_profile_id=:core,description=:description,'
                    .'priority=:priority,enabled=:enabled,matchers=CAST(:matchers AS jsonb),updated_at=:now '
                    .'WHERE rule_id=:id AND installation_id=:installation');
                $update->execute(['id'=>$id,'installation'=>$installation,'core'=>$core===''?null:$core,'description'=>$description,'priority'=>$priority,
                    'enabled'=>$enabled?'true':'false','matchers'=>$this->encode($match),'now'=>$now]);
                if($update->rowCount()!==1)throw new RuntimeException('not_found');}
            return['rule_id'=>$id,'saved'=>true];
        });
    }

    /** Delete one rule without revisiting NPCs that it previously matched. */
    public function deleteProfileAssignmentRule(string $installationId,string $ruleId):void
    {
        if(!Uuid::isValid($installationId))throw new InvalidArgumentException('invalid_installation_id');
        if(!Uuid::isValid($ruleId))throw new InvalidArgumentException('invalid_rule_id');
        $delete=$this->db->prepare('DELETE FROM profile_assignment_rules WHERE rule_id=:id AND installation_id=:installation');
        $delete->execute(['id'=>$ruleId,'installation'=>$installationId]);if($delete->rowCount()!==1)throw new RuntimeException('not_found');
    }

    /** Queue one idempotent generation job for the profile's current revision. */
    public function enqueueProfileGeneration(string $profileId):array
    {
        return$this->transaction(function()use($profileId):array{
            $select=$this->db->prepare('SELECT p.installation_id,p.current_revision,p.actor_identity,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:id AND p.deleted_at IS NULL AND '.ProfileScopeSql::current('p').' FOR UPDATE OF p');
            $select->execute(['id'=>$profileId]);$row=$select->fetch();if(!$row)throw new RuntimeException('not_found');
            $identity=$this->json($row['actor_identity']);if(in_array($identity['kind']??'actor',['player','narrator'],true))throw new RuntimeException('profile_not_generatable');
            $content=$this->json($row['content']);$management=is_array($content['management']??null)?$content['management']:[];
            if(($management['locked']??false)===true)throw new \InvalidArgumentException('profile_locked');
            $revision=(int)$row['current_revision'];$key='profile:'.$profileId.':revision:'.$revision;$jobId=Uuid::v4();
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode($this->profileGenerationPayload((string)$row['installation_id'],$profileId,$revision))]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");$existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');return$job+['profile_id'=>$profileId,'base_revision'=>$revision];
        });
    }

    /** Queue at most 100 unlocked NPC profiles from one installation for revision-safe generation. */
    public function bulkEnqueueNpcProfileGeneration(string $installationId):array
    {
        $select=$this->db->prepare("SELECT p.profile_id,count(*) OVER() AS eligible FROM profiles p "
            ."JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
            ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." "
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

    /** Resolve one completed turn to its bound NPC profile before applying the backfill policy. */
    public function maybeEnqueueAutomaticProfileBackfillForTurn(array $turn):array
    {
        $installation=$turn['installation_id']??null;$playthrough=$turn['playthrough_id']??null;
        $target=$turn['payload']['target']??null;
        if(!is_string($installation)||!is_string($playthrough)||!is_array($target)||array_is_list($target))
            throw new InvalidArgumentException('invalid_profile_backfill_turn');
        $profileId=$this->selectedActorProfileId($installation,$playthrough,$target);
        if($profileId===null)return['queued'=>false,'reason'=>'profile_unbound','observed'=>0,'required'=>0];
        return$this->maybeEnqueueAutomaticProfileBackfill($profileId,$playthrough);
    }

    /** Queue one revision-fenced backfill after an unlocked empty NPC has enough completed dialogue. */
    public function maybeEnqueueAutomaticProfileBackfill(string $profileId,string $playthroughId):array
    {
        return$this->transaction(function()use($profileId,$playthroughId):array{
            $select=$this->db->prepare('SELECT p.installation_id,p.current_revision,p.actor_identity,r.content '
                .'FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision '
                .'JOIN playthroughs pt ON pt.playthrough_id=:playthrough AND pt.installation_id=p.installation_id '
                .'WHERE p.profile_id=:profile AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','pt.playthrough_id').' FOR UPDATE OF p');
            $select->execute(['profile'=>$profileId,'playthrough'=>$playthroughId]);$row=$select->fetch();
            if(!$row)throw new RuntimeException('not_found');
            if(!$this->profileTasksEnabled((string)$row['installation_id']))return['queued'=>false,'reason'=>'profile_tasks_disabled','observed'=>0,'required'=>0];
            $effective=$this->effectiveSettingsForProfile((string)$row['installation_id'],$profileId);
            if(empty($effective['routing']['profile_generation_configuration_id']))return['queued'=>false,'reason'=>'profile_generation_connector_unavailable','observed'=>0,'required'=>0];
            $policy=$effective['settings']['profile_management'];$trigger=(int)$policy['autofill_custom_profiles_trigger'];
            if(!$policy['autofill_custom_profiles'])return['queued'=>false,'reason'=>'disabled','observed'=>0,'required'=>$trigger];
            $identity=$this->json($row['actor_identity']);
            if(in_array($identity['kind']??'actor',['player','narrator','template'],true))
                return['queued'=>false,'reason'=>'profile_not_generatable','observed'=>0,'required'=>$trigger];
            $content=$this->json($row['content']);
            if(($content['management']['locked']??false)===true)
                return['queued'=>false,'reason'=>'profile_locked','observed'=>0,'required'=>$trigger];
            foreach(['appearance','biography','personality','speech_style','occupation','goals','relationships','notes']as$field)
                if(trim((string)($content[$field]??''))!=='')
                    return['queued'=>false,'reason'=>'profile_not_empty','observed'=>0,'required'=>$trigger];
            $history=$this->profileBackfillHistory((string)$row['installation_id'],$playthroughId,$identity,$trigger);
            $observed=count($history['source_turn_ids']);
            if($observed<$trigger)return['queued'=>false,'reason'=>'history_threshold','observed'=>$observed,'required'=>$trigger];
            $revision=(int)$row['current_revision'];$key='profile-backfill:'.$profileId.':revision:'.$revision;
            $payload=$this->profileGenerationPayload((string)$row['installation_id'],$profileId,$revision,'npc_profile_backfill')+[
                'playthrough_id'=>$playthroughId,'source_turn_ids'=>$history['source_turn_ids'],
                'recent_events'=>$history['recent_events']];
            $jobId=Uuid::v4();$insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode($payload)]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");
                $existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');
            return$job+['queued'=>true,'profile_id'=>$profileId,'base_revision'=>$revision,'observed'=>$observed,'required'=>$trigger];
        });
    }

    public function evolutionScheduleActive(array $payload):bool
    {
        return (new ProfileEvolutionScheduler($this->db))->active($payload);
    }

    /** Queue one calendar/event/cooldown-gated evolution for an opted-in unlocked profile. */
    public function maybeEnqueueDynamicProfileEvolution(string $profileId,string $playthroughId,string $sessionId,bool $manual=false):array
    {
        return$this->transaction(function()use($profileId,$playthroughId,$sessionId,$manual):array{
            $select=$this->db->prepare('SELECT p.installation_id,p.current_revision,p.actor_identity,r.content,s.created_at AS session_created_at '
                .'FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision '
                .'JOIN sessions s ON s.session_id=:session AND s.installation_id=p.installation_id AND s.playthrough_id=:playthrough AND s.state=\'active\' '
                .'WHERE p.profile_id=:profile AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','s.playthrough_id',true).' FOR UPDATE OF p');
            $select->execute(['profile'=>$profileId,'playthrough'=>$playthroughId,'session'=>$sessionId]);$row=$select->fetch();
            if(!$row)throw new RuntimeException('not_found');
            if(!$this->profileTasksEnabled((string)$row['installation_id']))return['queued'=>false,'reason'=>'profile_tasks_disabled','observed'=>0,'required'=>0];
            if(($this->globalSettingsForInstallation((string)$row['installation_id'])['content']['client']['behavior']['ai_enabled']??true)!==true)
                return ['queued'=>false,'reason'=>'ai_disabled','observed'=>0];
            $content=$this->json($row['content']);$management=is_array($content['management']??null)?$content['management']:[];
            if(($management['locked']??false)===true)return['queued'=>false,'reason'=>'profile_locked','observed'=>0];
            if(($content['dynamic_profile']??false)!==true)return['queued'=>false,'reason'=>'disabled','observed'=>0];
            $fields=is_array($content['dynamic_profile_fields']??null)?array_values($content['dynamic_profile_fields']):[];
            $fields=array_values(array_unique(array_filter($fields,static fn(mixed$field):bool=>is_string($field)
                &&in_array($field,EffectiveSettingsResolver::DYNAMIC_PROFILE_FIELDS,true))));
            if($fields===[])$fields=['personality','speech_style','goals'];
            $identity=$this->json($row['actor_identity']);$narrator=($identity['kind']??null)==='narrator';
            if(!$narrator&&in_array($identity['kind']??'actor',['player','template'],true))
                return['queued'=>false,'reason'=>'profile_not_generatable','observed'=>0];
            $mode=$narrator?'narrator_profile_evolution':'profile_evolution';
            $effective=$this->effectiveSettingsForProfile((string)$row['installation_id'],$profileId);
            $scheduler=new ProfileEvolutionScheduler($this->db);
            $schedule=$scheduler->prepare((string)$row['installation_id'],$profileId,$playthroughId,$identity,$effective['settings']['profile_evolution']??[],$manual);
            if(!$schedule['due'])return ['queued'=>false,'reason'=>$schedule['reason'],'observed'=>$schedule['observed']];
            $pending=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_type='profile.generate' "
                ."AND payload->>'profile_id'=:profile AND payload->>'playthrough_id'=:playthrough "
                ."AND payload->>'mode'=:mode AND state IN ('queued','leased','retry_wait') LIMIT 1");
            $pending->execute(['profile'=>$profileId,'playthrough'=>$playthroughId,'mode'=>$mode]);
            if($pending->fetchColumn())return['queued'=>false,'reason'=>'interval','observed'=>0];
            if(empty($effective['routing']['profile_generation_configuration_id']))return['queued'=>false,'reason'=>'profile_generation_connector_unavailable','observed'=>0];
            $historyLimit=(int)($effective['settings']['profile_evolution']['history_limit']??50);
            if($historyLimit===0)$historyLimit=(int)($effective['settings']['memory']['recent_turn_limit']??20);
            $history=$narrator?$this->narratorEvolutionHistory((string)$row['installation_id'],$playthroughId,$historyLimit)
                :$this->profileBackfillHistory((string)$row['installation_id'],$playthroughId,$identity,$historyLimit);
            $witnessed=$this->evolutionWitnessedEvents((string)$row['installation_id'],$playthroughId,$narrator?null:$identity,$historyLimit);
            $observed=count($history['source_turn_ids'])+count($witnessed);
            if($observed===0)return['queued'=>false,'reason'=>'history_unavailable','observed'=>0];
            $revision=(int)$row['current_revision'];
            $key='profile-evolution:'.$profileId.':'.$playthroughId.':'.Uuid::v4();
            $payload=$this->profileGenerationPayload((string)$row['installation_id'],$profileId,$revision,$mode)+[
                'playthrough_id'=>$playthroughId,'source_turn_ids'=>$history['source_turn_ids'],
                'recent_events'=>$history['recent_events'],'dynamic_fields'=>$fields,'evolution_schedule'=>$schedule];
            // Freeze editable field instructions at enqueue so later prompt edits cannot change an in-flight job.
            $definitions=\LorkhanServer\Application\NarratorEventPrompts::definitions();
            $overrides=$this->narratorEventPromptTexts((string)$row['installation_id']);
            foreach($fields as$field){$promptKey='dynamic_prompt_'.($field==='speech_style'?'speechstyle':$field);
                $payload['dynamic_field_prompts'][$field]=$overrides[$promptKey]??$definitions[$promptKey]['default_prompt'];}
            $payload['witnessed_events']=$witnessed;
            $payload['source_event_ids']=array_values(array_unique(array_column($payload['witnessed_events'],'source_event_id')));
            $jobId=Uuid::v4();$insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,:priority) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode($payload),'priority'=>$manual?60:45]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");
                $existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');
            $scheduler->attempted($profileId,$playthroughId);
            return$job+['queued'=>true,'profile_id'=>$profileId,'base_revision'=>$revision,'mode'=>$mode,
                'observed'=>$observed,'dynamic_fields'=>$fields];
        });
    }

    /** Queue a revision-safe AI regeneration only for the installation narrator profile. */
    public function enqueueNarratorProfileGeneration(string $profileId):array
    {
        return$this->transaction(function()use($profileId):array{
            $select=$this->db->prepare('SELECT p.installation_id,p.current_revision,p.actor_identity,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:id AND p.deleted_at IS NULL FOR UPDATE OF p');
            $select->execute(['id'=>$profileId]);$row=$select->fetch();if(!$row)throw new RuntimeException('not_found');
            $identity=$this->json($row['actor_identity']);if(($identity['kind']??null)!=='narrator')throw new \InvalidArgumentException('profile_not_narrator');
            $content=$this->json($row['content']);$management=is_array($content['management']??null)?$content['management']:[];
            if(($management['locked']??false)===true)throw new \InvalidArgumentException('profile_locked');
            $revision=(int)$row['current_revision'];$key='narrator-profile:'.$profileId.':revision:'.$revision;$jobId=Uuid::v4();
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode($this->profileGenerationPayload((string)$row['installation_id'],$profileId,$revision,'narrator_profile'))]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");$existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');return$job+['profile_id'=>$profileId,'base_revision'=>$revision,'mode'=>'narrator_profile'];
        });
    }

    /** Queue a revision-safe player speech-style analysis only when real player inputs exist. */
    public function enqueuePlayerSpeechStyleGeneration(string $profileId,mixed $guidance='',mixed $currentStyle=null,mixed $requestId=null):array
    {
        if(!is_string($guidance)||strlen($guidance)>4000||!mb_check_encoding($guidance,'UTF-8'))throw new \InvalidArgumentException('invalid_speech_style_guidance');
        $guidance=trim($guidance);
        if($currentStyle!==null&&(!is_string($currentStyle)||strlen($currentStyle)>8192||!mb_check_encoding($currentStyle,'UTF-8')))throw new InvalidArgumentException('invalid_current_speech_style');
        if($requestId!==null&&(!is_string($requestId)||!Uuid::isValid($requestId)))throw new InvalidArgumentException('invalid_generation_request_id');
        return$this->transaction(function()use($profileId,$guidance,$currentStyle,$requestId):array{
            $select=$this->db->prepare('SELECT p.installation_id,p.current_revision,p.actor_identity FROM profiles p WHERE p.profile_id=:id AND p.deleted_at IS NULL AND '.ProfileScopeSql::current('p').' FOR UPDATE');
            $select->execute(['id'=>$profileId]);$row=$select->fetch();if(!$row)throw new RuntimeException('not_found');
            $identity=$this->json($row['actor_identity']);if(($identity['kind']??null)!=='player')throw new \InvalidArgumentException('profile_not_player');
            if($this->recentPlayerInputs((string)$row['installation_id'],1)===[])throw new \InvalidArgumentException('player_inputs_unavailable');
            $revision=(int)$row['current_revision'];$key='player-speech-style:'.$profileId.':revision:'.$revision;$jobId=Uuid::v4();
            $payload=$this->profileGenerationPayload((string)$row['installation_id'],$profileId,$revision,'player_speech_style');
            $payload['speech_style_prompt']=$this->narratorEventPromptTexts((string)$row['installation_id'],['player_speech_style_prompt'])['player_speech_style_prompt']
                ??\LorkhanServer\Application\NarratorEventPrompts::definitions()['player_speech_style_prompt']['default_prompt'];
            if($guidance!==''){$payload['speech_style_guidance']=$guidance;$key.=':guidance:'.hash('sha256',$guidance);}
            if($currentStyle!==null){$payload['current_speech_style']=$currentStyle;$key.=':style:'.hash('sha256',$currentStyle);}
            if($requestId!==null)$key.=':request:'.$requestId;
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) VALUES(:job,'profile.generate',1,:key,CAST(:payload AS jsonb),3,60) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode($payload)]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state FROM durable_jobs WHERE job_type='profile.generate' AND idempotency_key=:key");$existing->execute(['key'=>$key]);$job=$existing->fetch();}
            if(!$job)throw new RuntimeException('profile_generation_queue_failed');return$job+['profile_id'=>$profileId,'base_revision'=>$revision,'mode'=>'player_speech_style'];
        });
    }

    /** Store a worker result for review without changing the player's saved profile. */
    public function storePlayerSpeechStyleDraft(string $jobId,int $attempt,string $profileId,int $baseRevision,string $speechStyle):void
    {
        if(trim($speechStyle)===''||strlen($speechStyle)>8192||!mb_check_encoding($speechStyle,'UTF-8'))throw new InvalidArgumentException('invalid_speech_style_draft');
        $query=$this->db->prepare("INSERT INTO lorkhan_internal.player_speech_style_drafts(job_id,profile_id,base_revision,speech_style)
            SELECT j.job_id,p.profile_id,:revision,:style FROM durable_jobs j JOIN profiles p ON p.profile_id=:profile
            WHERE j.job_id=:job AND j.state='leased' AND j.attempt_count=:attempt AND j.lease_expires_at>clock_timestamp()
            AND j.payload->>'profile_id'=p.profile_id::text AND j.payload->>'mode'='player_speech_style'
            AND (j.payload->>'base_revision')::integer=:base_revision
            AND p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." AND p.actor_identity->>'kind'='player'
            ON CONFLICT(job_id) DO UPDATE SET speech_style=EXCLUDED.speech_style");
        $query->execute(['revision'=>$baseRevision,'base_revision'=>$baseRevision,'style'=>$speechStyle,'profile'=>$profileId,'job'=>$jobId,'attempt'=>$attempt]);
        if($query->rowCount()!==1)throw new RuntimeException('lease_lost');
    }

    /** Return only one installation's player draft and safe job status, never raw job payloads. */
    public function playerSpeechStyleDraft(string $installationId,string $profileId,string $jobId):array
    {
        if(!Uuid::isValid($installationId)||!Uuid::isValid($profileId)||!Uuid::isValid($jobId))throw new InvalidArgumentException('invalid_player_draft_scope');
        $query=$this->db->prepare("SELECT j.state,j.payload->>'base_revision' AS base_revision,p.current_revision,d.speech_style
            FROM durable_jobs j JOIN profiles p ON p.profile_id::text=j.payload->>'profile_id'
            LEFT JOIN lorkhan_internal.player_speech_style_drafts d ON d.job_id=j.job_id AND d.profile_id=p.profile_id
            WHERE j.job_id=:job AND j.job_type='profile.generate' AND j.payload->>'mode'='player_speech_style'
            AND p.profile_id=:profile AND p.installation_id=:installation AND p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." AND p.actor_identity->>'kind'='player'");
        $query->execute(['job'=>$jobId,'profile'=>$profileId,'installation'=>$installationId]);$row=$query->fetch();
        if(!$row)throw new RuntimeException('not_found');
        $state=(int)$row['base_revision']!==(int)$row['current_revision']?'stale':(string)$row['state'];
        return ['state'=>$state,'speech_style'=>$state==='succeeded'?$row['speech_style']:null];
    }

    /** Schedule genre detection only after ordinary player dialogue has completed. */
    public function maybeEnqueueSceneClassification(array $turn):array
    {
        if(!in_array($turn['payload']['input']['kind']??null,['text','stt'],true)
            ||in_array($turn['payload']['target']['kind']??null,['narrator','player'],true)
            ||!empty($turn['payload']['ui_source'])&&!in_array($turn['payload']['ui_source'],['text','lorkhan_text','lorkhan_voice','lorkhan_open_mic','lorkhan_browser_speech'],true))
            return ['queued'=>false,'reason'=>'ineligible'];
        $profile=$turn['_selected_profile_id']??$this->selectedActorProfileId($turn['installation_id'],$turn['playthrough_id'],$turn['payload']['target']);
        if(!is_string($profile))return ['queued'=>false,'reason'=>'profile_unbound'];
        $scenes=new SceneClassificationRepository($this->db);
        if($scenes->route($turn['installation_id'])===null)return ['queued'=>false,'reason'=>'scene_classifier_unavailable'];
        $history=$this->profileBackfillHistory($turn['installation_id'],$turn['playthrough_id'],$turn['payload']['target'],10);
        return $scenes->enqueue($turn,$profile,$history['recent_events']);
    }

    /** Task availability is global and does not discard the selected connector. */
    public function profileTasksEnabled(string $installationId):bool
    {
        return ($this->globalSettingsForInstallation($installationId)['content']['task_availability']['profile_generation']??true)===true;
    }

    /** Freeze the inherited generation route as IDs only; no endpoint or key material enters a job payload. */
    private function profileGenerationPayload(string $installationId,string $profileId,int $revision,?string $mode=null):array
    {
        if(!$this->profileTasksEnabled($installationId))throw new InvalidArgumentException('profile_tasks_disabled');
        $payload=['profile_id'=>$profileId,'base_revision'=>$revision];if($mode!==null)$payload['mode']=$mode;
        $routing=$this->effectiveSettingsForProfile($installationId,$profileId)['routing'];
        $configurationId=(string)($routing['profile_generation_configuration_id']??'');
        if($configurationId==='')throw new InvalidArgumentException('profile_generation_connector_unavailable');
        $statement=$this->db->prepare("SELECT configuration_id,current_revision FROM configuration_sets "
            ."WHERE configuration_id=:configuration AND installation_id=:installation AND kind='provider' AND deleted_at IS NULL FOR SHARE");
        $statement->execute(['configuration'=>$configurationId,'installation'=>$installationId]);$connector=$statement->fetch();
        if(!$connector)throw new \InvalidArgumentException('profile_generation_connector_unavailable');
        return$payload+['provider_configuration_id'=>(string)$connector['configuration_id'],'provider_revision'=>(int)$connector['current_revision']];
    }

    /** Freeze a bounded chronological actor-targeted history for automatic profile generation. */
    private function profileBackfillHistory(string $installationId,string $playthroughId,array $identity,int $limit):array
    {
        $stable=array_intersect_key($identity,array_fill_keys(['kind','record_id','content_file','refnum'],true));
        $statement=$this->db->prepare("SELECT t.turn_id,CASE WHEN jsonb_exists(t.context,'director') THEN '' ELSE t.input_text END AS input_text,t.response_payload,
            (jsonb_exists(t.context,'director') OR EXISTS (SELECT 1 FROM source_events ie WHERE ie.turn_id=t.turn_id "
            ."AND ie.event_kind='turn.requested' AND ie.payload#>>'{payload,execution_mode}' IN ('injection_log','injection_chat'))) AS injected FROM active_turns t "
            .'JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=:installation '
            .'AND s.playthrough_id=:playthrough AND t.state=\'complete\' AND t.target @> CAST(:identity AS jsonb) '
            .'AND EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(t.response_payload->\'lines\',\'[]\'::jsonb)) line '
            .'WHERE line->>\'action\'=\'say\' AND line->\'speaker_identity\' @> CAST(:speaker_identity AS jsonb)) '
            .'ORDER BY t.completed_at DESC,t.turn_id DESC LIMIT :limit');
        $statement->bindValue(':installation',$installationId);$statement->bindValue(':playthrough',$playthroughId);
        $statement->bindValue(':identity',$this->encode($stable));$statement->bindValue(':speaker_identity',$this->encode($stable));
        $statement->bindValue(':limit',$limit,PDO::PARAM_INT);$statement->execute();$rows=$statement->fetchAll();
        $turnIds=[];$events=[];$bytes=2;$targetKey=$this->actorKey($stable);
        foreach(array_reverse($rows)as$row){$response=$this->json($row['response_payload']);$replies=[];
            foreach(($response['lines']??[])as$line)if(is_array($line)&&($line['action']??null)==='say'){
                $speaker=$line['speaker_identity']??null;if(!is_array($speaker)||$this->actorKey($speaker)!==$targetKey)continue;
                $text=trim((string)($line['text']??''));if($text!=='')$replies[]=$text;}
            $event=['turn_id'=>(string)$row['turn_id'],(($row['injected']===true||$row['injected']==='t')?'scene_event':'player_input')=>(string)$row['input_text'],'npc_responses'=>$replies];
            $eventBytes=strlen(json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))+($events===[]?0:1);if($bytes+$eventBytes>65_536)continue;
            $turnIds[]=(string)$row['turn_id'];$events[]=$event;$bytes+=$eventBytes;}
        return['source_turn_ids'=>$turnIds,'recent_events'=>$events];
    }

    /** Freeze recent completed dialogue for narrator evolution without treating one NPC as the owner. */
    private function narratorEvolutionHistory(string $installationId,string $playthroughId,int $limit):array
    {
        $statement=$this->db->prepare("SELECT t.turn_id,CASE WHEN jsonb_exists(t.context,'director') THEN '' ELSE t.input_text END AS input_text,t.response_payload,
            (jsonb_exists(t.context,'director') OR EXISTS (SELECT 1 FROM source_events ie WHERE ie.turn_id=t.turn_id "
            ."AND ie.event_kind='turn.requested' AND ie.payload#>>'{payload,execution_mode}' IN ('injection_log','injection_chat'))) AS injected FROM active_turns t "
            .'JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=:installation '
            .'AND s.playthrough_id=:playthrough AND t.state=\'complete\' AND t.response_payload IS NOT NULL ORDER BY t.completed_at DESC,t.turn_id DESC LIMIT :limit');
        $statement->bindValue(':installation',$installationId);$statement->bindValue(':playthrough',$playthroughId);
        $statement->bindValue(':limit',$limit,PDO::PARAM_INT);$statement->execute();$rows=$statement->fetchAll();
        $turnIds=[];$events=[];$bytes=2;
        foreach(array_reverse($rows)as$row){$response=$this->json($row['response_payload']);$lines=[];
            foreach(($response['lines']??[])as$line)if(is_array($line)&&($line['action']??null)==='say'){
                $speaker=is_array($line['speaker_identity']??null)?$line['speaker_identity']:[];
                $name=trim((string)($speaker['display_name']??$line['speaker']??'NPC'))?:'NPC';
                $text=trim((string)($line['text']??''));if($text!=='')$lines[]=$name.': '.$text;}
            if($lines===[])continue;$turnId=(string)$row['turn_id'];
            $event=['turn_id'=>$turnId,(($row['injected']===true||$row['injected']==='t')?'scene_event':'player_input')=>(string)$row['input_text'],'npc_responses'=>$lines];
            $eventBytes=strlen(json_encode($event,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))+($events===[]?0:1);if($bytes+$eventBytes>65_536)continue;
            $turnIds[]=$turnId;$events[]=$event;$bytes+=$eventBytes;}
        return['source_turn_ids'=>$turnIds,'recent_events'=>$events];
    }

    /** Freeze actor-relevant vanilla and world events, excluding diagnostics and retired save branches. */
    private function evolutionWitnessedEvents(string $installation,string $playthrough,?array $identity,int $limit):array
    {
        $parameters=['installation'=>$installation,'playthrough'=>$playthrough];
        $actor='';
        if($identity!==null){$stable=array_intersect_key($identity,array_flip(['kind','record_id','content_file','refnum']));
            $actor=' AND (m.speaker @> CAST(:speaker AS jsonb) OR m.target @> CAST(:target AS jsonb) OR m.audience @> CAST(:audience AS jsonb))';
            $parameters+=['speaker'=>$this->encode($stable),'target'=>$this->encode($stable),'audience'=>$this->encode([$stable])];}
        $query=$this->db->prepare("SELECT m.source_event_id,e.type,e.data,e.location,e.gamets FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid
            JOIN source_events se ON se.source_event_id=m.source_event_id
            WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL
            AND e.type IN ('chat','chat_background','location','weather','death','infoaction','narration','quest','book','spellcast','npcspellcast','itemfound')
            AND (e.delivery_state IS NULL OR e.delivery_state IN ('spoken','played'))
            AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=m.source_event_id)
            AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=m.turn_id)".$actor.
            ' ORDER BY e.gamets DESC,e.rowid DESC LIMIT :limit');
        foreach($parameters as$key=>$value)$query->bindValue(':'.$key,$value);
        $query->bindValue(':limit',max(1,min(100,$limit)),PDO::PARAM_INT);$query->execute();$events=[];$bytes=2;
        foreach(array_reverse($query->fetchAll())as$row){$text=trim((string)$row['data']);if($text==='')continue;
            $event=['source_event_id'=>$row['source_event_id'],'type'=>$row['type'],'text'=>mb_strcut($text,0,2048,'UTF-8'),
                'location'=>mb_strcut((string)$row['location'],0,512,'UTF-8'),'game_time'=>(string)$row['gamets']];
            $size=strlen($this->encode($event))+1;if($bytes+$size>16384)continue;$events[]=$event;$bytes+=$size;}
        return$events;
    }

    /** Load the exact revision queued for a task while enforcing installation and live-connector ownership. */
    public function providerRevisionForInstallation(string $installationId,string $configurationId,int $revision):array
    {
        $statement=$this->db->prepare("SELECT c.configuration_id,r.revision,r.content FROM configuration_sets c "
            ."JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=:revision "
            ."WHERE c.configuration_id=:configuration AND c.installation_id=:installation AND c.kind='provider' AND c.deleted_at IS NULL");
        $statement->execute(['configuration'=>$configurationId,'installation'=>$installationId,'revision'=>$revision]);$row=$statement->fetch();
        if(!$row)throw new \InvalidArgumentException('profile_generation_connector_unavailable');
        return['configuration_id'=>(string)$row['configuration_id'],'revision'=>(int)$row['revision'],
            'content'=>\LorkhanServer\Application\LlmConnector::validate($this->json($row['content']))];
    }

    /** Queue one user-requested diary from bounded witnessed context; no provider call occurs here. */
    public function enqueueDiaryGeneration(array $scope):array
    {
        foreach(['installation_id','profile_id','playthrough_id','request_id']as$field)
            if(!is_string($scope[$field]??null)||!Uuid::isValid($scope[$field]))throw new \InvalidArgumentException('invalid_diary_generation_scope');
        $automaticTrigger=$scope['automatic_trigger']??null;
        if($automaticTrigger!==null&&(!is_string($automaticTrigger)||!in_array($automaticTrigger,['timer','sleep','wait'],true)
            ||!is_string($scope['automatic_source_request_id']??null)||!Uuid::isValid($scope['automatic_source_request_id'])
            ||!is_numeric($scope['trigger_game_time']??null)||$scope['trigger_game_time']<0))
            throw new \InvalidArgumentException('invalid_diary_generation_scope');
        return$this->transaction(function()use($scope):array{
            $key='narrative.generate:'.$scope['request_id'];
            $replay=$this->db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='narrative.generate' AND idempotency_key=:key");
            $replay->execute(['key'=>$key]);
            if($job=$replay->fetch()){$payload=$this->json($job['payload']);unset($job['payload']);
                foreach(['request_id','installation_id','profile_id','playthrough_id']as$field)
                    if(($payload[$field]??null)!==$scope[$field])throw new \InvalidArgumentException('diary_generation_request_conflict');
                return$job+['request_id'=>$scope['request_id'],'narrative_id'=>$payload['narrative_id'],
                    'profile_revision'=>$payload['profile_revision'],'provider_configuration_id'=>$payload['provider_configuration_id'],
                    'provider_revision'=>$payload['provider_revision'],'source_count'=>count($payload['source_turn_ids']??[])];}
            $profileStatement=$this->db->prepare('SELECT p.current_revision,r.content,p.actor_identity,p.name FROM profiles p '
                .'JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision '
                .'WHERE p.profile_id=:profile AND p.installation_id=:installation AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p',':owner_playthrough',true).' FOR SHARE OF p');
            $profileStatement->execute(['owner_playthrough'=>$scope['playthrough_id'],'profile'=>$scope['profile_id'],'installation'=>$scope['installation_id']]);$profile=$profileStatement->fetch();
            if(!$profile)throw new \InvalidArgumentException('invalid_diary_generation_scope');
            $playthroughStatement=$this->db->prepare('SELECT playthrough_id FROM playthroughs WHERE playthrough_id=:playthrough '
                .'AND installation_id=:installation AND deleted_at IS NULL FOR SHARE');
            $playthroughStatement->execute(['playthrough'=>$scope['playthrough_id'],'installation'=>$scope['installation_id']]);
            if(!$playthroughStatement->fetchColumn())throw new \InvalidArgumentException('invalid_diary_generation_scope');
            $effective=$this->effectiveSettingsForProfile($scope['installation_id'],$scope['profile_id']);
            $diary=$effective['settings']['diary']??[];
            if(($diary['enabled']??false)!==true)throw new \InvalidArgumentException('diary_generation_disabled');
            $configurationId=(string)($effective['routing']['diary_generation_configuration_id']??'');
            if($configurationId==='')throw new \InvalidArgumentException('diary_generation_connector_unavailable');
            $providerStatement=$this->db->prepare("SELECT configuration_id,current_revision FROM configuration_sets WHERE configuration_id=:configuration "
                ."AND installation_id=:installation AND kind='provider' AND deleted_at IS NULL FOR SHARE");
            $providerStatement->execute(['configuration'=>$configurationId,'installation'=>$scope['installation_id']]);$provider=$providerStatement->fetch();
            if(!$provider)throw new \InvalidArgumentException('diary_generation_connector_unavailable');
            $identity=$this->json($profile['actor_identity']);$actor=[];
            foreach(['kind','record_id','content_file','refnum']as$field)if(array_key_exists($field,$identity))$actor[$field]=$identity[$field];
            if(!isset($actor['kind']))$actor['kind']='actor';
            $actorJson=$this->encode($actor);$audienceJson=$this->encode([$actor]);
            $limit=(int)($diary['context_turn_limit']??20);
            if($limit===0)$limit=(int)($effective['settings']['memory']['recent_turn_limit']??20);
            $candidateLimit=min(1600,max(20,$limit*4));
            $historyStatement=$this->db->prepare("SELECT m.turn_id,m.created_at,e.type,e.data,e.people,e.location,e.gamets,m.speaker,m.target "
                ."FROM eventlog e JOIN eventlog_metadata m ON m.rowid=e.rowid WHERE m.installation_id=:installation "
                ."AND m.playthrough_id=:playthrough AND m.suppressed_at IS NULL AND e.type IN ('inputtext','chat','location','weather','death','infoaction','rechat','narration','quest','book') "
                ."AND (e.type<>'chat' OR e.delivery_state IN ('emitted','spoken','played')) "
                ."AND (m.speaker @> CAST(:speaker AS jsonb) OR m.target @> CAST(:target AS jsonb) OR m.audience @> CAST(:audience AS jsonb)) "
                ."ORDER BY m.created_at DESC,e.rowid DESC LIMIT :limit");
            $historyStatement->bindValue(':installation',$scope['installation_id']);$historyStatement->bindValue(':playthrough',$scope['playthrough_id']);
            $historyStatement->bindValue(':speaker',$actorJson);$historyStatement->bindValue(':target',$actorJson);$historyStatement->bindValue(':audience',$audienceJson);
            $historyStatement->bindValue(':limit',$candidateLimit,\PDO::PARAM_INT);$historyStatement->execute();
            $context=[];$turns=[];$sourceIds=[];$bytes=0;
            foreach($historyStatement->fetchAll()as$row){$turnId=(string)($row['turn_id']??'');$turnKey=$turnId!==''?$turnId:'event:'.count($context);
                if(!isset($turns[$turnKey])&&count($turns)>=$limit)continue;
                $speaker=$this->json($row['speaker']);$target=$this->json($row['target']);$item=array_filter([
                    'turn_id'=>$turnId===''?null:$turnId,'at'=>(string)$row['created_at'],'type'=>(string)$row['type'],
                    'speaker'=>$speaker['display_name']??$speaker['record_id']??null,'target'=>$target['display_name']??$target['record_id']??null,
                    'content'=>mb_strcut(trim((string)$row['data']),0,4096,'UTF-8'),'location'=>trim((string)($row['location']??''))?:null,
                    'game_time'=>(int)($row['gamets']??0)?:null,'people'=>trim((string)($row['people']??''))?:null,
                ],static fn(mixed$value):bool=>$value!==null&&$value!=='');$encoded=$this->encode($item);
                if($bytes+strlen($encoded)>65_536)continue;
                $turns[$turnKey]=true;if($turnId!==''&&Uuid::isValid($turnId))$sourceIds[$turnId]=true;
                $bytes+=strlen($encoded);$context[]=$item;}
            if($context===[])throw new \InvalidArgumentException('diary_generation_no_context');$context=array_reverse($context);
            $profileContent=$this->json($profile['content']);$profileInput=[];
            foreach(['prompt_head','core','appearance','biography','personality','speech_style','occupation','skills','goals','relationships','gender','race']as$field)
                if(is_string($profileContent[$field]??null)&&trim($profileContent[$field])!=='')$profileInput[$field]=mb_strcut(trim($profileContent[$field]),0,8192,'UTF-8');
            $input=['generation_mode'=>'diary_generation','name'=>(string)$profile['name'],'actor_identity'=>$actor,
                'profile'=>$profileInput,'witnessed_context'=>$context,'instruction'=>(string)$diary['prompt']];
            if(strlen($this->encode($input))>131_072)throw new \InvalidArgumentException('diary_generation_too_large');
            $payload=['request_id'=>$scope['request_id'],'narrative_id'=>Uuid::v4(),'installation_id'=>$scope['installation_id'],
                'profile_id'=>$scope['profile_id'],'playthrough_id'=>$scope['playthrough_id'],'profile_revision'=>(int)$profile['current_revision'],
                'provider_configuration_id'=>(string)$provider['configuration_id'],'provider_revision'=>(int)$provider['current_revision'],
                'source_turn_ids'=>array_keys($sourceIds),'input'=>$input];
            if(isset($scope['automatic_trigger']))$payload+=['automatic_trigger'=>$scope['automatic_trigger'],
                'automatic_source_request_id'=>$scope['automatic_source_request_id'],
                'trigger_game_time'=>(float)$scope['trigger_game_time']];
            $jobId=Uuid::v4();
            $insert=$this->db->prepare("INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority) "
                ."VALUES(:job,'narrative.generate',1,:key,CAST(:payload AS jsonb),3,55) ON CONFLICT(job_type,idempotency_key) DO NOTHING RETURNING job_id,state");
            $insert->execute(['job'=>$jobId,'key'=>$key,'payload'=>$this->encode($payload)]);$job=$insert->fetch();
            if(!$job){$existing=$this->db->prepare("SELECT job_id,state,payload FROM durable_jobs WHERE job_type='narrative.generate' AND idempotency_key=:key");
                $existing->execute(['key'=>$key]);$job=$existing->fetch();if(!$job)throw new RuntimeException('diary_generation_queue_failed');
                $existingPayload=$this->json($job['payload']);unset($job['payload']);
                foreach(['request_id','installation_id','profile_id','playthrough_id']as$field)
                    if(($existingPayload[$field]??null)!==$scope[$field])throw new \InvalidArgumentException('diary_generation_request_conflict');
                $payload=$existingPayload;}
            return$job+['request_id'=>$scope['request_id'],'narrative_id'=>$payload['narrative_id'],'profile_revision'=>$payload['profile_revision'],
                'provider_configuration_id'=>$payload['provider_configuration_id'],'provider_revision'=>$payload['provider_revision'],
                'source_count'=>count($payload['source_turn_ids']??[])];
        });
    }

    /** Queue eligible Player, Narrator, and nearby NPC diaries for one typed game event. */
    public function enqueueAutomaticDiaries(array $message):array
    {
        foreach(['installation_id','playthrough_id','request_id']as$field)
            if(!is_string($message[$field]??null)||!Uuid::isValid($message[$field]))
                throw new \InvalidArgumentException('invalid_automatic_diary_scope');
        $payload=$message['payload']??null;$trigger=is_array($payload)?($payload['trigger']??null):null;
        $gameTime=is_array($payload)?($payload['game_time']??null):null;$actors=is_array($payload)?($payload['actors']??null):null;
        if(!in_array($trigger,['timer','sleep','wait'],true)||!is_numeric($gameTime)||$gameTime<0
            ||!is_array($actors)||!array_is_list($actors)||count($actors)>12)
            throw new \InvalidArgumentException('invalid_automatic_diary_scope');
        $profileIds=[];
        foreach([$this->playerProfileForInstallation($message['installation_id'],$message['playthrough_id']),
            $this->narratorProfileForInstallation($message['installation_id'])]as$profile)
            if(is_array($profile)&&is_string($profile['profile_id']??null))$profileIds[$profile['profile_id']]=true;
        foreach($actors as$identity){if(!is_array($identity)||array_is_list($identity))continue;
            try{$profileId=$this->selectedActorProfileId($message['installation_id'],$message['playthrough_id'],$identity);
            }catch(Throwable){continue;}if($profileId!==null)$profileIds[$profileId]=true;}
        $result=['trigger'=>$trigger,'considered'=>count($profileIds),'queued'=>0,'skipped'=>[]];
        foreach(array_keys($profileIds)as$profileId){$effective=$this->effectiveSettingsForProfile($message['installation_id'],$profileId);
            $settings=$effective['settings']['diary']??[];
            if(($settings['enabled']??false)!==true||($settings['automatic_enabled']??false)!==true){$result['skipped'][$profileId]='disabled';continue;}
            if($trigger==='wait'&&($settings['automatic_wait_enabled']??false)!==true){$result['skipped'][$profileId]='wait_disabled';continue;}
            $seconds=max(10,min(86400,(int)($settings['automatic_interval_seconds']??120)));
            $recent=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_type='narrative.generate' "
                ."AND payload->>'profile_id'=:profile AND payload->>'playthrough_id'=:playthrough "
                ."AND jsonb_exists(payload,'automatic_trigger') AND created_at>clock_timestamp()-(:seconds||' seconds')::interval LIMIT 1");
            $recent->execute(['profile'=>$profileId,'playthrough'=>$message['playthrough_id'],'seconds'=>(string)$seconds]);
            if($recent->fetchColumn()!==false){$result['skipped'][$profileId]='cooldown';continue;}
            $requestId=$this->deterministicUuid('automatic-diary:'.$message['request_id'].':'.$profileId);
            try{$this->enqueueDiaryGeneration(['installation_id'=>$message['installation_id'],'profile_id'=>$profileId,
                    'playthrough_id'=>$message['playthrough_id'],'request_id'=>$requestId,'automatic_trigger'=>$trigger,
                    'automatic_source_request_id'=>$message['request_id'],'trigger_game_time'=>(float)$gameTime]);
                $result['queued']++;
            }catch(\InvalidArgumentException$error){$result['skipped'][$profileId]=$error->getMessage();}
        }
        return$result;
    }

    /** Queue generation only for the profile currently bound to this active session target. */
    public function enqueueBoundProfileGeneration(array $session,array $target,string $profileId):array
    {
        $selected=$this->selectedActorProfileId((string)$session['installation_id'],
            (string)$session['playthrough_id'],$target);
        if($selected===null||!hash_equals($selected,$profileId))throw new \OutOfBoundsException('profile_not_bound');
        return$this->enqueueProfileGeneration($profileId);
    }

    /** Keep a durable, non-secret receipt when generation is skipped or its output is not applied. */
    public function recordProfileGenerationOutcome(string $jobId,int $attempt,string $outcome):void
    {
        if(!in_array($outcome,['applied','draft_saved','schedule_changed','revision_conflict','profile_locked','commit_fence_changed'],true))
            throw new InvalidArgumentException('invalid_profile_generation_outcome');
        $query=$this->db->prepare("UPDATE durable_jobs SET payload=jsonb_set(payload,'{generation_outcome}',to_jsonb(CAST(:outcome AS text)))
            WHERE job_id=:job AND job_type='profile.generate' AND state='leased' AND attempt_count=:attempt AND lease_expires_at>clock_timestamp()");
        $query->execute(['job'=>$jobId,'attempt'=>$attempt,'outcome'=>$outcome]);
    }

    /** Commit generated fields only while the queued base revision is still current. */
    public function reviseGeneratedProfileIfCurrent(string $profileId,int $baseRevision,array $content,string $reason,string $now,array $sourceTurnIds=[],?string $jobId=null,?int $attempt=null):bool
    {
        return$this->transaction(function()use($profileId,$baseRevision,$content,$reason,$now,$sourceTurnIds,$jobId,$attempt):bool{
            $installation=$this->db->prepare('SELECT i.installation_id FROM installations i JOIN profiles p ON p.installation_id=i.installation_id WHERE p.profile_id=:profile FOR SHARE OF i');
            $installation->execute(['profile'=>$profileId]);
            $installationId=$installation->fetchColumn();if($installationId===false)return false;
            $provenance=[];
            if($jobId!==null){
                $job=$this->db->prepare("SELECT payload FROM durable_jobs WHERE job_id=:job AND job_type='profile.generate'
                    AND state='leased' AND attempt_count=:attempt AND lease_expires_at>clock_timestamp() FOR SHARE");
                $job->execute(['job'=>$jobId,'attempt'=>$attempt]);$stored=$job->fetchColumn();if($stored===false)return false;
                $payload=$this->json($stored);
                if(($payload['profile_id']??null)!==$profileId||($payload['base_revision']??null)!==$baseRevision)return false;
                if(!(new ProfileEvolutionScheduler($this->db))->active($payload))return false;
                if(in_array($payload['mode']??null,['npc_profile_backfill','profile_evolution','narrator_profile_evolution'],true)){
                    $sources=$payload['source_turn_ids']??[];$playthrough=$payload['playthrough_id']??null;
                    if(!is_string($playthrough)||!Uuid::isValid($playthrough)||$sources!==$sourceTurnIds
                        ||($sources!==[]&&!(new LoadedSaveTimeline($this->db))->sourcesBelongTo($sources,(string)$installationId,$playthrough)))return false;
                    $eventSources=$payload['source_event_ids']??[];
                    if(($sources===[]&&$eventSources===[])||!(new LoadedSaveTimeline($this->db))->eventSourcesActive($eventSources,(string)$installationId,$playthrough))return false;
                    $provenance=['source_event_ids'=>$eventSources,'kind'=>'automatic_profile','mode'=>$payload['mode'],'playthrough_id'=>$playthrough,
                        'base_revision'=>$baseRevision,'source_turn_ids'=>$sources];
                }
            }
            if (!(new LoadedSaveTimeline($this->db))->sourcesActive($sourceTurnIds)) return false;
            $select=$this->db->prepare('SELECT current_revision FROM profiles WHERE profile_id=:id AND deleted_at IS NULL AND '.ProfileScopeSql::current('profiles').' FOR UPDATE');
            $select->execute(['id'=>$profileId]);$current=$select->fetchColumn();if($current===false||(int)$current!==$baseRevision)return false;
            $next=$baseRevision+1;
            $insert=$this->db->prepare('INSERT INTO profile_revisions(profile_id,revision,content,change_reason,created_at,provenance)
                VALUES(:profile,:revision,CAST(:content AS jsonb),:reason,:now,CAST(:provenance AS jsonb))');
            $insert->execute(['profile'=>$profileId,'revision'=>$next,'content'=>json_encode($content,JSON_THROW_ON_ERROR),
                'reason'=>$reason,'now'=>$now,'provenance'=>json_encode($provenance===[]?(object)[]:$provenance,JSON_THROW_ON_ERROR)]);
            $this->db->prepare('UPDATE profiles SET current_revision=:revision WHERE profile_id=:id')->execute(['revision'=>$next,'id'=>$profileId]);
            if(isset($payload))(new ProfileEvolutionScheduler($this->db))->complete($payload);
            return true;
        });
    }

    /** Create unlocked revisions for every locked NPC profile in one installation. */
    public function bulkUnlockNpcProfiles(string $installationId,string $now):int
    {
        return$this->transaction(function()use($installationId,$now):int{
            $select=$this->db->prepare("SELECT p.profile_id,p.current_revision,r.content FROM profiles p "
                ."JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
                ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." "
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

    /** Return the persisted CHIM-style semantic model slot, defaulting to Standard. */
    public function selectedModelSlot(string $installationId):string
    {
        $statement=$this->db->prepare('SELECT llm_model_slot FROM installation_profile_preferences WHERE installation_id=:installation');
        $statement->execute(['installation'=>$installationId]);$slot=$statement->fetchColumn();
        return is_string($slot)&&isset(self::MODEL_SLOTS[$slot])?$slot:'standard';
    }

    /** Persist one allowlisted semantic model slot for this installation. */
    public function selectModelSlot(array $session,string $slot,string $now):void
    {
        if(!isset(self::MODEL_SLOTS[$slot]))throw new \InvalidArgumentException('invalid_model_slot');
        $this->transaction(function()use($session,$slot,$now):void{
            $active=$this->db->prepare("SELECT 1 FROM sessions WHERE session_id=:session AND generation=:generation AND state='active' FOR SHARE");
            $active->execute(['session'=>$session['session_id'],'generation'=>$session['generation']]);
            if(!$active->fetchColumn())throw new \OutOfBoundsException('unknown_session');
            $statement=$this->db->prepare('INSERT INTO installation_profile_preferences(installation_id,llm_model_slot,updated_at) '
                .'VALUES(:installation,:slot,:now) ON CONFLICT(installation_id) DO UPDATE SET '
                .'llm_model_slot=EXCLUDED.llm_model_slot,updated_at=EXCLUDED.updated_at');
            $statement->execute(['installation'=>$session['installation_id'],'slot'=>$slot,'now'=>$now]);
        });
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
                ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." "
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

    /** Reassign NPC Core Profiles without moving identities, actor bindings or per-NPC overrides. */
    public function bulkSwitchNpcCoreProfiles(string $installationId,string $sourceProfileId,string $targetProfileId,bool $includeLocked,bool $includeUnassigned=false):array
    {
        if(hash_equals($sourceProfileId,$targetProfileId))throw new \InvalidArgumentException('profiles_must_differ');
        return$this->transaction(function()use($installationId,$sourceProfileId,$targetProfileId,$includeLocked,$includeUnassigned):array{
            $cores=$this->db->prepare('SELECT core_profile_id FROM core_profiles WHERE installation_id=:installation '
                .'AND core_profile_id IN(:source,:target) AND deleted_at IS NULL ORDER BY core_profile_id FOR SHARE');
            $cores->execute(['installation'=>$installationId,'source'=>$sourceProfileId,'target'=>$targetProfileId]);
            if(count($cores->fetchAll())!==2)throw new \InvalidArgumentException('core_profile_scope_mismatch');
            $select=$this->db->prepare("SELECT p.profile_id,r.content FROM profiles p JOIN profile_revisions r "
                ."ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation "
                ."AND (p.core_profile_id=:source".($includeUnassigned?' OR p.core_profile_id IS NULL':'').") AND p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." "
                ."AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') "
                ."ORDER BY p.profile_id FOR UPDATE OF p");
            $select->execute(['installation'=>$installationId,'source'=>$sourceProfileId]);$rows=$select->fetchAll();
            $updated=0;$skipped=0;
            foreach($rows as$row){
                $content=$this->json($row['content']);
                if(($content['management']['locked']??false)===true&&!$includeLocked){$skipped++;continue;}
                $this->assignCoreProfile((string)$row['profile_id'],$targetProfileId);$updated++;
            }
            return['updated'=>$updated,'total_matched'=>count($rows),'skipped_locked'=>$skipped];
        });
    }

    /** Soft-delete a revisioned management resource and clear runtime selections that could still reference it. */
    public function deleteRevisioned(string $kind,string $id,string $now):void
    {
        $this->transaction(function()use($kind,$id,$now):void{
            [$table,$key]=$this->revisionMeta($kind);
            if($kind==='profile'){
                $owned=$this->db->prepare('SELECT 1 FROM profiles WHERE profile_id=:id AND '.ProfileScopeSql::current('profiles').' FOR UPDATE');
                $owned->execute(['id'=>$id]);if(!$owned->fetchColumn())throw new RuntimeException('not_found');
            }
            if($kind==='profile')$this->db->prepare('DELETE FROM actor_profile_bindings WHERE profile_id=:id')->execute(['id'=>$id]);
            if($kind==='core_profile'){
                $usage=$this->db->prepare('SELECT c.default_npc,'
                    .'(SELECT count(*) FROM profiles p WHERE p.core_profile_id=c.core_profile_id AND p.deleted_at IS NULL) AS profiles,'
                    .'(SELECT count(*) FROM profile_assignment_rules r WHERE r.core_profile_id=c.core_profile_id) AS assignment_rules '
                    .'FROM core_profiles c WHERE c.core_profile_id=:id AND c.deleted_at IS NULL FOR UPDATE');
                $usage->execute(['id'=>$id]);$row=$usage->fetch();if(!$row)throw new RuntimeException('not_found');
                if(filter_var($row['default_npc'],FILTER_VALIDATE_BOOL)||(int)$row['profiles']>0||(int)$row['assignment_rules']>0)
                    throw new \InvalidArgumentException('core_profile_in_use');
            }
            if($kind==='provider'){
                $lock=$this->db->prepare("SELECT configuration_id FROM configuration_sets WHERE configuration_id=:id AND deleted_at IS NULL FOR UPDATE");
                $lock->execute(['id'=>$id]);if(!$lock->fetchColumn())throw new RuntimeException('not_found');
                (new Player2RoutingRepository($this->db))->assertNotActive($id);
                $queued=$this->db->prepare("SELECT 1 FROM durable_jobs WHERE job_type IN ('profile.generate','profile.report','scene.classify','memory.digest','memory.summarize','relationship.evaluate','relationship.build','relationship.convert','narrative.generate') AND state IN ('queued','leased') AND payload->>'provider_configuration_id'=:id LIMIT 1");
                $queued->execute(['id'=>$id]);if($queued->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
                $policy=$this->db->prepare("SELECT 1 FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
                    WHERE c.kind='memory_policy' AND c.deleted_at IS NULL AND r.content->>'provider_configuration_id'=:id LIMIT 1");
                $policy->execute(['id'=>$id]);if($policy->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
                $global=$this->db->prepare("SELECT 1 FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='global_settings' AND c.deleted_at IS NULL AND :id IN (r.content#>>'{system_routing,oghma_configuration_id}',r.content#>>'{system_routing,profile_generation_configuration_id}',r.content#>>'{system_routing,background_memory_configuration_id}',r.content#>>'{system_routing,scene_classifier_configuration_id}',r.content#>>'{system_routing,director_configuration_id}',r.content#>>'{system_routing,relationship_configuration_id}') LIMIT 1");
                $global->execute(['id'=>$id]);if($global->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
                $profile=$this->db->prepare("SELECT 1 FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND (r.content->'routing'->>'llm_configuration_id'=:id OR r.content->'routing'->>'llm_fast_configuration_id'=:id OR r.content->'routing'->>'llm_powerful_configuration_id'=:id OR r.content->'routing'->>'llm_experimental_configuration_id'=:id OR r.content->'routing'->>'llm_fallback_configuration_id'=:id OR r.content->'routing'->>'oghma_configuration_id'=:id OR r.content->'routing'->>'profile_generation_configuration_id'=:id OR r.content->'routing'->>'relationship_configuration_id'=:id OR r.content->'routing'->>'diary_generation_configuration_id'=:id OR r.content->'routing'->>'player_autochat_configuration_id'=:id) LIMIT 1");
                $profile->execute(['id'=>$id]);if($profile->fetchColumn())throw new \InvalidArgumentException('provider_in_use');
                $core=$this->db->prepare("SELECT 1 FROM core_profile_revisions r JOIN core_profiles c ON c.core_profile_id=r.core_profile_id AND c.current_revision=r.revision WHERE c.deleted_at IS NULL AND (r.content->'routing'->>'llm_configuration_id'=:id OR r.content->'routing'->>'llm_fast_configuration_id'=:id OR r.content->'routing'->>'llm_powerful_configuration_id'=:id OR r.content->'routing'->>'llm_experimental_configuration_id'=:id OR r.content->'routing'->>'llm_fallback_configuration_id'=:id OR r.content->'routing'->>'oghma_configuration_id'=:id OR r.content->'routing'->>'profile_generation_configuration_id'=:id OR r.content->'routing'->>'relationship_configuration_id'=:id OR r.content->'routing'->>'diary_generation_configuration_id'=:id OR r.content->'routing'->>'player_autochat_configuration_id'=:id) LIMIT 1");
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

    /** Quickstart can choose a service before a connector exists; reuse saved settings without rewriting them. */
    public function ensureQuickstartSpeechConnector(string $installationId,string $kind,string $driver,string $now):string
    {
        $label=\LorkhanServer\Application\ConnectorCatalog::QUICKSTART_SPEECH_DRIVERS[$kind][$driver]??null;
        if($label===null)throw new InvalidArgumentException('invalid_quickstart_service');
        return $this->transaction(function()use($installationId,$kind,$driver,$label,$now):string{
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')
                ->execute(['key'=>'default-connectors:'.$installationId]);
            $find=$this->db->prepare('SELECT c.configuration_id FROM configuration_sets c '
                .'JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision '
                .'LEFT JOIN installation_provider_selections s ON s.configuration_id=c.configuration_id AND s.installation_id=c.installation_id AND s.provider_kind=c.kind '
                ."WHERE c.installation_id=:installation AND c.kind=:kind AND c.deleted_at IS NULL AND r.content->>'driver'=:driver "
                .'ORDER BY (s.configuration_id IS NOT NULL) DESC,c.created_at,c.configuration_id LIMIT 1');
            $find->execute(['installation'=>$installationId,'kind'=>$kind,'driver'=>$driver]);
            $existing=$find->fetchColumn();if($existing!==false)return (string)$existing;
            $definition=\LorkhanServer\Application\ConnectorCatalog::definition($kind,$driver);
            $content=\LorkhanServer\Application\ConnectorCatalog::validate($kind,
                ['driver'=>$driver,'credential'=>$definition['credential_environment']?:'none']
                +\LorkhanServer\Application\ConnectorCatalog::defaults($kind,$driver));
            $created=$this->createRevisioned($kind,['installation_id'=>$installationId,'name'=>$label,'content'=>$content],$now);
            return (string)$created['configuration_id'];
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
        $statement=$this->db->prepare('SELECT c.configuration_id,c.name,c.current_revision AS revision,r.content FROM installation_provider_selections s JOIN configuration_sets c ON c.configuration_id=s.configuration_id AND c.installation_id=s.installation_id AND c.kind=s.provider_kind JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE s.installation_id=:installation AND s.provider_kind=:kind AND c.deleted_at IS NULL');
        $statement->execute(['installation'=>$installationId,'kind'=>$kind]);$row=$statement->fetch();
        if(!$row)return null;$row['revision']=(int)$row['revision'];$row['content']=$this->json($row['content']);return$row;
    }

    /** Resolve an actor profile's CHIM-style connector routing while enforcing installation ownership. */
    public function connectorForActor(string $installationId,string $playthroughId,array $identity,string $kind,?string $routingField=null):?array
    {
        if(!in_array($kind,['provider','tts_provider'],true))throw new RuntimeException('invalid_connector_kind');
        $routing=$this->routingForActor($installationId,$playthroughId,$identity);
        $allowed=$kind==='provider'?['llm_configuration_id','llm_fast_configuration_id','llm_powerful_configuration_id',
            'llm_experimental_configuration_id','llm_fallback_configuration_id','oghma_configuration_id',
            'player_autochat_configuration_id']:['tts_configuration_id'];
        $field=$routingField??$allowed[0];if(!in_array($field,$allowed,true))throw new RuntimeException('invalid_connector_route');
        $configurationId=trim((string)($routing[$field]??''));
        if($configurationId==='')return null;
        $statement=$this->db->prepare('SELECT c.configuration_id,c.name,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.configuration_id=:configuration AND c.installation_id=:installation AND c.kind=:kind AND c.deleted_at IS NULL');
        $statement->execute(['configuration'=>$configurationId,'installation'=>$installationId,'kind'=>$kind]);$row=$statement->fetch();
        if(!$row)return null;$row['revision']=(int)$row['revision'];$row['content']=$this->json($row['content']);return$row;
    }

    /** Resolve the revisioned Global -> Core Profile -> NPC layers for one stable actor identity. */
    public function effectiveSettingsForActor(string $installationId,string $playthroughId,array $identity):array
    {
        $profileId=match($identity['kind']??null){
            'player'=>$this->playerProfileForInstallation($installationId,$playthroughId)['profile_id']??null,
            'narrator'=>$this->narratorProfileForInstallation($installationId)['profile_id']??null,
            default=>$this->selectedActorProfileId($installationId,$playthroughId,$identity),
        };
        return$this->effectiveSettingsForProfile($installationId,is_string($profileId)?$profileId:null);
    }

    /** Fill only missing NPC inventory from accepted observations in this exact active session. */
    public function enrichTurnInventory(array $turn):array
    {
        $target=$turn['payload']['target']??[];$state=$turn['payload']['context']['targetState']??[];
        if(!is_array($target)||!is_array($state)||array_key_exists('inventory',$state))return $turn;
        $observation=$this->latestInventoryObservation($turn,$target);
        if($observation!==null){
            $turn['payload']['context']['targetState']['inventory']=$observation['inventory'];
            $turn['_inventory_observation']=array_intersect_key($observation,['source_event_id'=>true,'received_at'=>true]);
        }
        return $turn;
    }

    /** Undated inventory cannot cross a save/session replacement; receipt order owns freshness. */
    private function latestInventoryObservation(array $scope,array $identity):?array
    {
        $kind=strtolower((string)($identity['kind']??''));if($kind==='actor')$kind='npc';
        if(!in_array($kind,['npc','creature'],true))return null;
        $statement=$this->db->prepare(<<<SQL
SELECT e.payload#>'{payload,items}' AS items,e.received_at,e.source_event_id
FROM source_events e JOIN sessions s ON s.session_id=e.session_id
WHERE e.installation_id=:installation AND s.installation_id=e.installation_id
  AND s.session_id=:session AND s.playthrough_id=:playthrough AND s.state='active'
  AND s.generation=:generation AND e.generation=s.generation AND e.event_kind='gamedata.inventory'
  AND CASE lower(e.payload#>>'{payload,owner,kind}') WHEN 'actor' THEN 'npc' ELSE lower(e.payload#>>'{payload,owner,kind}') END=:kind
  AND lower(e.payload#>>'{payload,owner,record_id}')=lower(:record)
  AND lower(e.payload#>>'{payload,owner,content_file}')=lower(:content)
  AND e.payload#>'{payload,owner,refnum}' IS NOT DISTINCT FROM CAST(:refnum AS jsonb)
  AND jsonb_typeof(e.payload#>'{payload,items}')='array'
  AND NOT EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=e.source_event_id)
ORDER BY e.received_at DESC,e.source_event_id DESC LIMIT 1
SQL);
        $statement->execute(['installation'=>$scope['installation_id'],'session'=>$scope['session_id'],
            'playthrough'=>$scope['playthrough_id'],'generation'=>$scope['generation'],'kind'=>$kind,
            'record'=>$identity['record_id']??'','content'=>$identity['content_file']??'',
            'refnum'=>isset($identity['refnum'])?$this->encode($identity['refnum']):null]);
        $row=$statement->fetch();if(!$row)return null;$items=$this->json($row['items']);$safe=[];$invalid=false;
        foreach(array_slice($items,0,512)as$item){
            if(!is_array($item)||!is_string($item['record_id']??null)
                ||!is_string($item['name']??null)||!is_int($item['count']??null)||$item['count']<1
                ||(isset($item['content_file'])&&!is_string($item['content_file']))){$invalid=true;continue;}
            $entry=['record_id'=>$item['record_id'],'display_name'=>$item['name'],'count'=>$item['count']];
            if(is_string($item['content_file']??null))$entry['content_file']=$item['content_file'];
            // Stack condition/equipment can differ, but the Info/prompt inventory lists unique records.
            $key=$this->encode([strtolower($entry['record_id']),strtolower($entry['content_file']??'')]);
            if(isset($safe[$key]))$safe[$key]['count']+=$entry['count'];else $safe[$key]=$entry;
        }
        return ['source_event_id'=>$row['source_event_id'],'received_at'=>$row['received_at'],'inventory'=>['items'=>array_values($safe),'total'=>count($safe),
            'truncated'=>count($items)>=512||$invalid]];
    }

    /** Read the latest exact-actor observation; never substitute player inventory or raw context. */
    public function npcObservedState(string $installationId, string $profileId): array
    {
        $profileScope=ProfileScopeSql::current('p').' AND '.ProfileScopeSql::matches('p','s.playthrough_id');
        $statement=$this->db->prepare(<<<SQL
SELECT t.context->'targetState' AS state,t.accepted_at,s.playthrough_id,pt.name AS playthrough_name
FROM profiles p JOIN sessions s ON s.installation_id=p.installation_id
JOIN active_turns t ON t.session_id=s.session_id
JOIN playthroughs pt ON pt.playthrough_id=s.playthrough_id AND pt.installation_id=s.installation_id
WHERE p.installation_id=:installation AND p.profile_id=:profile AND p.deleted_at IS NULL
  AND {$profileScope}
  AND p.actor_identity->>'kind' IN ('actor','npc','creature')
  AND t.target->>'kind'=CASE WHEN p.actor_identity->>'kind'='actor' THEN 'npc' ELSE p.actor_identity->>'kind' END
  AND t.target->>'record_id'=p.actor_identity->>'record_id'
  AND t.target->>'content_file'=p.actor_identity->>'content_file'
  AND t.target->'refnum' IS NOT DISTINCT FROM p.actor_identity->'refnum'
  AND jsonb_typeof(t.context->'targetState')='object' AND t.context->'targetState'<>'{}'::jsonb
ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT 1
SQL);
        $statement->execute(['installation'=>$installationId,'profile'=>$profileId]);$row=$statement->fetch();
        $active=$this->db->prepare("SELECT p.actor_identity,s.session_id,s.generation,s.playthrough_id,pt.name AS playthrough_name "
            ."FROM profiles p JOIN sessions s ON s.installation_id=p.installation_id AND s.state='active' "
            ."JOIN playthroughs pt ON pt.playthrough_id=s.playthrough_id AND pt.installation_id=s.installation_id "
            ."WHERE p.installation_id=:installation AND p.profile_id=:profile AND p.deleted_at IS NULL AND ".$profileScope);
        $active->execute(['installation'=>$installationId,'profile'=>$profileId]);$activeSession=$active->fetch();
        $inventory=$activeSession?$this->latestInventoryObservation($activeSession+['installation_id'=>$installationId],$this->json($activeSession['actor_identity'])):null;
        if(!$row&&$inventory===null)return [];
        // Do not attach fresh inventory to another playthrough's historical actor stats.
        if($inventory!==null&&$row&&$row['playthrough_id']!==$activeSession['playthrough_id'])$row=false;
        $state=$row?$this->json($row['state']):[];$safe=[];$inventorySource=[];
        if($inventory!==null&&(!$row||new \DateTimeImmutable($inventory['received_at'])>new \DateTimeImmutable($row['accepted_at']))){
            $state['inventory']=$inventory['inventory'];
            $inventorySource=['inventory_source_event_id'=>$inventory['source_event_id'],'inventory_observed_at'=>$inventory['received_at']];
            if(!$row)$row=['accepted_at'=>$inventory['received_at'],'playthrough_name'=>$activeSession['playthrough_name']];
        }

        $number=static fn(mixed $value):bool=>(is_int($value)||is_float($value))&&is_finite((float)$value);
        foreach(['skills'=>['block','armorer','mediumarmor','heavyarmor','bluntweapon','longblade','axe','spear','athletics','enchant',
            'destruction','alteration','illusion','conjuration','mysticism','restoration','alchemy','unarmored','security','sneak','acrobatics',
            'lightarmor','shortblade','marksman','mercantile','speechcraft','handtohand'],
            'attributes'=>['strength','intelligence','willpower','agility','speed','endurance','personality','luck']] as $section=>$keys){
            foreach($keys as$key){$values=$state[$section][$key]??null;if(!is_array($values))continue;
                foreach(['base','modified','damage','modifier']as$field)if($number($values[$field]??null))$safe[$section][$key][$field]=$values[$field];}
        }
        foreach(['level','encumbrance','capacity']as$key)if($number($state['stats'][$key]??null))$safe['stats'][$key]=$state['stats'][$key];
        if(is_bool($state['stats']['dead']??null))$safe['stats']['dead']=$state['stats']['dead'];
        foreach(['health','magicka','fatigue']as$key)foreach(['current','base','modifier']as$field)
            if($number($state['stats'][$key][$field]??null))$safe['stats'][$key][$field]=$state['stats'][$key][$field];
        foreach(['race','class','gender','primary_faction']as$key)if(is_string($state['identity'][$key]??null))
            $safe['identity'][$key]=mb_substr($state['identity'][$key],0,256);
        foreach(['equipment','inventory','spells']as$section){
            $items=$state[$section]['items']??$state[$section]??[];if(!is_array($items)||!array_is_list($items))continue;
            if(array_key_exists($section,$state))$safe[$section]=[];
            foreach(array_slice($items,0,128)as$item){if(!is_array($item))continue;$entry=[];
                foreach($section==='spells'?['id','name','record_id','display_name']:['slot','record_id','display_name']as$key)
                    if(is_string($item[$key]??null)&&$item[$key]!=='')$entry[$key]=mb_substr($item[$key],0,512);
                if($section!=='spells'&&$number($item['count']??null))$entry['count']=$item['count'];
                if($entry!==[])$safe[$section][]=$entry;
            }
        }
        if(isset($safe['inventory'])&&is_array($state['inventory']??null)){
            $total=$state['inventory']['total']??count($safe['inventory']);
            if(!is_int($total)||$total<count($safe['inventory']))$total=count($safe['inventory']);
            $safe['inventory_observation']=['total'=>$total,
                'truncated'=>($state['inventory']['truncated']??false)===true||$total>count($safe['inventory'])];
            usort($safe['inventory'],static fn(array $a,array $b):int=>strcmp($a['display_name']??$a['record_id']??'', $b['display_name']??$b['record_id']??''));
        }
        return ['observed_at'=>$row['accepted_at'],'playthrough_name'=>$row['playthrough_name'],'state'=>$safe]+$inventorySource;
    }

    /** Resolve a profile's assigned Core Profile while retaining source revisions for prompt traces. */
    public function effectiveSettingsForProfile(string $installationId,?string $profileId):array
    {
        $global=$this->globalSettingsForInstallation($installationId);
        $profile=null;
        if($profileId!==null&&$profileId!==''){
            $statement=$this->db->prepare('SELECT p.profile_id,p.core_profile_id,p.actor_identity,p.current_revision AS revision,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:profile AND p.installation_id=:installation AND p.deleted_at IS NULL');
            $statement->execute(['profile'=>$profileId,'installation'=>$installationId]);$profile=$statement->fetch();
            if($profile){$profile['revision']=(int)$profile['revision'];$profile['content']=$this->json($profile['content']);$profile['actor_identity']=$this->json($profile['actor_identity']);}
        }
        $core=null;$coreId=is_array($profile)?($profile['core_profile_id']??null):null;
        if(is_string($coreId)&&$coreId!==''){
            $statement=$this->db->prepare('SELECT c.core_profile_id,c.installation_id,c.label,c.default_npc,c.slot,c.current_revision AS revision,r.content FROM core_profiles c JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision WHERE c.core_profile_id=:core AND c.installation_id=:installation AND c.deleted_at IS NULL');
            $statement->execute(['core'=>$coreId,'installation'=>$installationId]);$core=$statement->fetch();
            if($core){$core['revision']=(int)$core['revision'];$core['content']=$this->json($core['content']);}
        }
        if(!$core)$core=$this->defaultCoreProfileForInstallation($installationId);
        $installationOghma=$this->oghmaSettings($installationId);
        $narratorProfile=$this->narratorProfileForInstallation($installationId);
        $narratorContent=is_array($narratorProfile['content']??null)?$narratorProfile['content']:[];
        if(is_string($narratorProfile['name']??null)&&trim($narratorProfile['name'])!=='')
            $narratorContent['name']=(string)$narratorProfile['name'];
        $profileKind=is_array($profile['actor_identity']??null)?($profile['actor_identity']['kind']??'actor'):'actor';
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
            in_array($profileKind,['player','narrator'],true),
            $narratorContent,
        );
        $resolved['routing']=(new Player2RoutingRepository($this->db))->apply($installationId,$resolved['routing']);
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

    /** Return immutable action contracts used by management policy validation and the editor API. */
    public function actionCatalogDefinitions():array
    {
        return (new ActionCatalogRepository($this->db))->enabledDefinitions();
    }

    /** Return deterministic installation, optional NPC, and merged effective action policies. */
    public function actionPoliciesForEditor(string $installationId,?string $profileId):array
    {
        $catalog=new ActionCatalogRepository($this->db);$installation=$catalog->policyAtScope($installationId,null);
        $profile=$profileId===null?null:$catalog->policyAtScope($installationId,$profileId);
        $effective=$catalog->currentPolicy($installationId,$profileId);
        return['installation_policy'=>$installation,'profile_policy'=>$profile,'effective_policy'=>$effective];
    }

    /** Return a newest-first bounded sample of typed or transcribed player turns for style analysis. */
    public function recentPlayerInputs(string $installationId,int $limit=200,?string $playthroughId=null):array
    {
        $playthroughId??=(new ProfileOwnershipRepository($this->db))->activePlaythrough($installationId);
        $limit=max(1,min(200,$limit));$statement=$this->db->prepare("SELECT t.input_text FROM active_turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=:installation AND (CAST(:playthrough AS uuid) IS NULL OR s.playthrough_id=CAST(:playthrough AS uuid)) AND t.speaker->>'kind'='player' AND btrim(t.input_text)<>'' AND NOT jsonb_exists(t.context,'director') AND NOT EXISTS (SELECT 1 FROM source_events e WHERE e.turn_id=t.turn_id AND (e.payload#>>'{payload,execution_mode}' IN ('injection_log','injection_chat','director') OR e.payload#>>'{payload,ui_source}' IN ('lorkhan_rpg_event','lorkhan_quest_event','lorkhan_rechat','lorkhan_action_followup','lorkhan_director_child') OR e.payload#>>'{payload,ui_source}' LIKE 'lorkhan_auto_%' OR e.payload#>>'{payload,ui_source}' LIKE 'lorkhan_narrator_%')) ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT :limit");
        $statement->bindValue(':installation',$installationId);$statement->bindValue(':playthrough',$playthroughId);$statement->bindValue(':limit',$limit,\PDO::PARAM_INT);$statement->execute();
        return array_map(static fn(array$row):string=>(string)$row['input_text'],$statement->fetchAll());
    }

    /** Resolve an actor profile's voice without exposing the rest of its roleplay document to a connector. */
    public function speechContext(string $installationId,string $playthroughId,array $identity,?array $connector=null):array
    {
        $profileId=match($identity['kind']??null){
            'player'=>$this->playerProfileForInstallation($installationId,$playthroughId)['profile_id']??null,
            'narrator'=>$this->narratorProfileForInstallation($installationId)['profile_id']??null,
            default=>$this->selectedActorProfileId($installationId,$playthroughId,$identity),
        };
        $profile=is_string($profileId)&&$profileId!==''?$this->getRevisioned('profile',$profileId):null;
        return $this->speechContextFromProfile($profile,$identity,$connector);
    }

    /** Share voice resolution with saved narrative authors without consulting current actor bindings. */
    public function speechContextFromProfile(?array $profile,array $identity,?array $connector):array
    {
        $content=$profile['content']??[];$voice=$content['voice']??null;
        if($voice===null)$voice=[];elseif(is_string($voice))$voice=['id'=>$voice];
        if(!is_array($voice)||($voice!==[]&&array_is_list($voice)))return[];
        $result=[];$filter=\LorkhanServer\Application\TtsFilterPresets::validate($content['tts_filter_preset']??'none');
        if($filter!=='none'){$result['tts_filter_preset']=$filter;$result['tts_filter_version']=\LorkhanServer\Application\TtsFilterPresets::VERSION;}
        $id=trim((string)($voice['id']??$voice['voice_id']??''));$language=trim((string)($voice['language']??''));
        if($id!==''&&in_array($connector['content']['driver']??'',['cartesia','inworld'],true)){
            $lookup=$this->db->prepare('SELECT voice_id FROM speech_connector_voices WHERE configuration_id=:configuration AND (voice_id=:voice OR lower(display_name)=lower(:voice)) ORDER BY (voice_id=:voice) DESC LIMIT 2');
            $lookup->execute(['configuration'=>$connector['configuration_id'],'voice'=>$id]);$matches=$lookup->fetchAll(PDO::FETCH_COLUMN);
            if(count($matches)===1||($matches[0]??null)===$id)$id=(string)$matches[0];
        }
        // Sample-capable adapters resolve/register the local WAV at synthesis time, like Herika.
        if($id!==''&&!\LorkhanServer\Application\ConnectorCatalog::usesLocalVoiceSamples((string)($connector['content']['driver']??''))
            &&str_starts_with((string)($voice['source']??''),'morrowind_')){
            $configurationId=trim((string)($connector['configuration_id']??''));
            if($configurationId===''||!$this->connectorHasVoice($configurationId,$id))$id='';
        }
        if($id===''){$gender=strtolower(trim((string)($content['gender']??'')));
            if($gender==='')$gender=strtolower(trim((string)($identity['gender']??'')));
            $connectorContent=$connector['content']??null;
            $options=is_array($connectorContent)&&is_array($connectorContent['options']??null)&&!array_is_list($connectorContent['options'])?$connectorContent['options']:[];
            $race=trim((string)($content['race']??''));if($race==='')$race=(string)($identity['race']??'');
            $id=(new TtsFallbackRepository($this->db))->voice($race,$gender);
            // Stock-voice services cannot consume the bundled game WAVs; retain their own fallback.
            if($id!==''&&!\LorkhanServer\Application\ConnectorCatalog::usesLocalVoiceSamples((string)($connectorContent['driver']??''))
                &&in_array($id,array_column(\LorkhanServer\Application\MorrowindVoiceCatalog::bundled()->voices(),'voice_id'),true)
                &&((string)($connector['configuration_id']??'')===''||!$this->connectorHasVoice((string)$connector['configuration_id'],$id)))$id='';
            $fallbackField=match($gender){'male'=>'fallback_male','female'=>'fallback_female',default=>null};
            if($id===''&&$fallbackField!==null)$id=trim((string)($options[$fallbackField]??''));
        }
        if($id!==''&&strlen($id)<=512)$result['voice']=$id;if($language!==''&&strlen($language)<=35)$result['language']=$language;
        if(($identity['kind']??'')==='player'&&isset($content['player_elevenlabs']))
            $result['player_elevenlabs']=\LorkhanServer\Application\CloudSpeechConnectorProvider::validatePlayerOverrides($content['player_elevenlabs']);
        return$result;
    }

    /** Transform provider-only speech text while retaining the original subtitle and history text. */
    public function applyTtsPronunciation(string $text,array $context=[]):string
    {
        return($this->ttsPronunciations??=new TtsPronunciationRepository($this->db))->apply($text,$context);
    }

    /** Resolve only the actor fields used by optional CHIM-style pronunciation scopes. */
    public function ttsPronunciationContext(string $installationId,string $playthroughId,array $identity):array
    {
        $scope=['npc_name'=>trim((string)($identity['display_name']??$identity['record_id']??'')),
            'race'=>trim((string)($identity['race']??'')),'oghma_tags'=>[]];
        $profileId=match($identity['kind']??null){
            'player'=>$this->playerProfileForInstallation($installationId,$playthroughId)['profile_id']??null,
            'narrator'=>$this->narratorProfileForInstallation($installationId)['profile_id']??null,
            default=>$this->selectedActorProfileId($installationId,$playthroughId,$identity),
        };
        if(!is_string($profileId)||$profileId==='')return['pronunciation_scope'=>$scope];
        return $this->pronunciationContextFromProfile($this->getRevisioned('profile',$profileId),$identity);
    }

    /** Use the same pronunciation scope for live speech and an explicitly recorded diary author. */
    private function pronunciationContextFromProfile(array $profile,array $identity):array
    {
        $scope=['npc_name'=>trim((string)($identity['display_name']??$identity['record_id']??'')),
            'race'=>trim((string)($identity['race']??'')),'oghma_tags'=>[]];
        $content=is_array($profile['content']??null)?$profile['content']:[];
        $scope['npc_name']=trim((string)($profile['name']??$scope['npc_name']));
        $scope['race']=trim((string)($content['race']??$scope['race']));
        $scope['oghma_tags']=$content['oghma_knowledge_tags']??$content['oghma_tags']??[];
        return['pronunciation_scope'=>$scope];
    }

    /** Resolve a saved diary author and speech inputs without generating audio or changing profile bindings. */
    public function diarySpeechPlan(string $installationId,string $narrativeId):array
    {
        $statement=$this->db->prepare("SELECT n.narrative_id,n.profile_id,n.playthrough_id,n.content,n.updated_at FROM narrative_records n JOIN profiles p ON p.profile_id=n.profile_id AND p.installation_id=n.installation_id WHERE n.narrative_id=:id AND n.installation_id=:installation AND n.kind='diary' AND n.deleted_at IS NULL AND p.deleted_at IS NULL");
        $statement->execute(['id'=>$narrativeId,'installation'=>$installationId]);$entry=$statement->fetch();
        if(!$entry)throw new RuntimeException('diary_audio_entry_not_found');
        $text=trim((string)$entry['content']);
        if($text==='')throw new RuntimeException('diary_audio_empty_entry');
        $profile=$this->getRevisioned('profile',(string)$entry['profile_id']);
        $effective=$this->effectiveSettingsForProfile($installationId,(string)$entry['profile_id']);
        $configurationId=trim((string)($effective['routing']['tts_configuration_id']??''));
        if($configurationId==='')throw new RuntimeException('diary_audio_connector_not_configured');
        $query=$this->db->prepare("SELECT c.configuration_id,c.name,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.configuration_id=:id AND c.installation_id=:installation AND c.kind='tts_provider' AND c.deleted_at IS NULL");
        $query->execute(['id'=>$configurationId,'installation'=>$installationId]);$connector=$query->fetch();
        if(!$connector)throw new RuntimeException('diary_audio_connector_not_configured');
        $connector['content']=$this->json($connector['content']);$connector['revision']=(int)$connector['revision'];
        $identity=$this->json($profile['actor_identity']??[]);
        $context=$this->speechContextFromProfile($profile,$identity,$connector);
        if(trim((string)($context['voice']??''))==='')throw new RuntimeException('diary_audio_voice_not_configured');
        $context+=$this->pronunciationContextFromProfile($profile,$identity);
        return ['entry'=>$entry,'author'=>(string)$profile['name'],'profile_id'=>(string)$entry['profile_id'],
            'connector'=>$connector,'context'=>$context,'text'=>$this->applyTtsPronunciation($text,$context)];
    }

    /** Create and bind an actor profile before its first prompt, even when a creature has no catalog voice. */
    public function ensureMorrowindActorProfile(array $turn,?array $resolvedVoice,string $now):string
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
                    ."AND ".ProfileScopeSql::matches('profiles',':playthrough')." AND lower(actor_identity->>'record_id')=lower(:record) AND lower(COALESCE(actor_identity->>'content_file',''))=lower(:content) "
                    ."AND actor_identity->'refnum'->>'index'=:ref_index AND actor_identity->'refnum'->>'content_file'=:ref_content "
                    ."AND COALESCE(actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY created_at,profile_id LIMIT 2");
                $existing->execute(['playthrough'=>$turn['playthrough_id'],'installation'=>$turn['installation_id'],'record'=>$target['record_id']??'',
                    'content'=>$target['content_file']??'','ref_index'=>(string)($refnum['index']??''),
                    'ref_content'=>(string)($refnum['content_file']??'')]);$matches=$existing->fetchAll();
                if(count($matches)===1)$profileId=(string)$matches[0]['profile_id'];
                else{$targetIdentity=(array)($turn['payload']['context']['targetState']['identity']??[]);
                    $profileTraits=$resolvedVoice??['race'=>(string)($targetIdentity['race']??''),
                        'gender'=>(string)($targetIdentity['gender']??'')];
                    $template=$this->matchingBiographyTemplate((string)$turn['installation_id'],$target,$profileTraits);
                    $seed=is_array($template['content']??null)?$template['content']:[];unset($seed['management'],$seed['portrait']);
                    if(trim((string)($profileTraits['gender']??''))!=='')$seed['gender']=$profileTraits['gender'];
                    if(trim((string)($profileTraits['race']??''))!=='')$seed['race']=$profileTraits['race'];
                    if($resolvedVoice!==null)$seed['voice']=$this->catalogVoiceDocument($resolvedVoice);
                    $seed=$this->morrowindLocalityContent($seed,$target,(string)$turn['installation_id']);
                    $seed['management']=['locked'=>false,'favorite'=>false];
                    $name=trim((string)($target['display_name']??$target['record_id']??'Morrowind NPC'));
                    $name=$name===''?'Morrowind NPC':mb_substr($name,0,256);
                    $nameExists=$this->db->prepare('SELECT 1 FROM profiles WHERE installation_id=:installation AND name=:name AND deleted_at IS NULL AND '.ProfileScopeSql::matches('profiles',':playthrough'));
                    $nameExists->execute(['playthrough'=>$turn['playthrough_id'],'installation'=>$turn['installation_id'],'name'=>$name]);
                    if($nameExists->fetchColumn()){$suffix=' [Ref '.(string)($refnum['content_file']??'?').':'.(string)($refnum['index']??'?').']';
                        $name=mb_substr($name,0,max(0,256-mb_strlen($suffix))).$suffix;}
                    $assignment=$this->matchingProfileRulesForTurn($turn,$target);
                    $coreProfileId=$assignment['core_profile_id'];
                    foreach($assignment['actions']as$action)$seed=ProfileAssignmentRule::apply($seed,$action);
                    $createInput=['installation_id'=>$turn['installation_id'],'playthrough_id'=>$turn['playthrough_id'],'name'=>$name,'actor_identity'=>$target,
                        'content'=>$seed,'change_reason'=>'automatic Morrowind actor discovery'];
                    if($coreProfileId!==null)$createInput['core_profile_id']=$coreProfileId;
                    $created=$this->createRevisioned('profile',$createInput,$now);$profileId=(string)$created['profile_id'];}
                $this->bindActorProfile(['installation_id'=>$turn['installation_id'],'playthrough_id'=>$turn['playthrough_id']],
                    $target,$profileId,$now);
                $this->applyMorrowindCatalogLocality($profileId,$target,(string)$turn['installation_id'],$now);
            }
            if($resolvedVoice!==null)$this->applyMorrowindCatalogVoice($profileId,$target,$resolvedVoice,$now,false);
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
    public function saveBiographyTemplate(array $input,bool $allowCreate=false):array
    {
        return$this->transaction(function()use($input,$allowCreate):array{
            if($allowCreate){
                $installation=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:id');
                $installation->execute(['id'=>$input['installation_id']??null]);
                if(!$installation->fetchColumn())throw new \InvalidArgumentException('invalid_installation_id');
            }
            $name=trim((string)($input['npc_name']??''));
            if($name===''||strlen($name)>128||str_contains($name,"\0"))throw new RuntimeException('invalid_biography_template_name');
            $profileId=trim((string)($input['profile_id']??''));$profile=null;
            if($profileId!==''){
                if(!Uuid::isValid($profileId))throw new RuntimeException('invalid_profile_id');
                $profile=$this->getRevisioned('profile',$profileId);
                $identity=is_string($profile['actor_identity'])?$this->json($profile['actor_identity']):$profile['actor_identity'];
                if(($identity['kind']??'')!=='template'||$profile['installation_id']!==($input['installation_id']??'')||$profile['name']!==$name)
                    throw new RuntimeException('biography_template_not_found');
            }elseif(!$allowCreate){
                $exists=$this->db->prepare('SELECT 1 FROM public.combined_bio_templates WHERE npc_name=:name');
                $exists->execute(['name'=>$name]);
                if($exists->fetchColumn()===false)throw new RuntimeException('biography_template_not_found');
            }
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
            if($profile!==null){
                if(($identity['record_id']??'')!==($values['refid']??''))throw new RuntimeException('invalid_biography_identity');
                $content=$profile['content'];
                foreach(['core'=>'core','npc_static_bio'=>'biography','appearance'=>'appearance','personality'=>'personality',
                    'relationships'=>'relationships','occupation'=>'occupation','skills'=>'skills','speechstyle'=>'speech_style',
                    'goals'=>'goals','oghma_knowledge_tags'=>'oghma_knowledge_tags','gender'=>'gender','race'=>'race'] as $field=>$key)
                    $content[$key]=$values[$field]??'';
                $content['voice']=array_replace(is_array($content['voice']??null)?$content['voice']:[],['id'=>$values['voiceid']??'']);
                $expected=filter_var($input['expected_revision']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($expected===false)throw new RuntimeException('invalid_expected_revision');
                $this->revise('profile',$profileId,$content,'biography template edit',gmdate('c'),$expected);
                return ['npc_name'=>$name,'source'=>'installation','profile_id'=>$profileId];
            }
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

    /** Reapply non-empty biography fields without changing actor identity, voice, routing or history. */
    public function biographyResetContent(array $profile):array
    {
        $identity=is_string($profile['actor_identity'])?$this->json($profile['actor_identity']):$profile['actor_identity'];
        $content=$profile['content'];
        if(!in_array($identity['kind']??'',['actor','npc','creature'],true)||trim((string)($identity['record_id']??''))===''
            ||trim((string)($identity['content_file']??''))==='')throw new \InvalidArgumentException('profile_not_editable');
        $template=$this->matchingBiographyTemplate((string)$profile['installation_id'],$identity,$content);
        if($template===null)throw new \InvalidArgumentException('biography_template_not_found');
        $changed=false;
        foreach(['core','biography','appearance','personality','occupation','skills','speech_style','goals',
            'race','gender','oghma_knowledge_tags','relationships']as$field){
            $value=$template['content'][$field]??null;
            if(!is_string($value)||trim($value)===''||($field==='relationships'&&trim($value)==='{}'))continue;
            $content[$field]=$value;$changed=true;
        }
        if(!$changed)throw new \InvalidArgumentException('biography_template_empty');
        return$content;
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
            ."ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND ".ProfileScopeSql::current('p')." "
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
        $rows=$this->db->query("SELECT profile_id,installation_id,actor_identity FROM profiles WHERE deleted_at IS NULL AND ".ProfileScopeSql::current('profiles')." "
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
            .'ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:profile AND p.deleted_at IS NULL AND '.ProfileScopeSql::current('p').' FOR UPDATE OF p');
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
            .'ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.profile_id=:profile AND p.deleted_at IS NULL AND '.ProfileScopeSql::current('p').' FOR UPDATE OF p');
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
        $memory=$this->memory($id);
        (new MemoryEmbeddingRepository($this->db))->enqueue($input['installation_id'],$id,(int)$memory['current_revision']);
        return$memory;
    }

    public function memory(string $id): array
    {
        $stmt=$this->db->prepare('SELECT * FROM memory_records WHERE memory_id=:id AND deleted_at IS NULL');$stmt->execute(['id'=>$id]);$row=$stmt->fetch();if(!$row)throw new RuntimeException('not_found');return $this->decodeMemory($row);
    }

    public function memorySummaryPolicyForInstallation(string $installation):?array
    {
        return (new MemorySummaryRepository($this->db))->policy($installation);
    }

    public function memoryEmbeddingPolicyForInstallation(string $installation):?array
    {
        return (new MemoryEmbeddingRepository($this->db))->policy($installation);
    }

    public function memoryEmbeddingRuntime(string $installation):array
    {
        return (new MemoryEmbeddingRepository($this->db))->runtime($installation);
    }

    public function enqueueMemoryEmbeddings(string $installation,int $limit=100):array
    {
        return (new MemoryEmbeddingRepository($this->db))->enqueueBatch($installation,$limit);
    }

    public function enqueueRelationshipBuild(array $scope,string $requestId,int $limit,string $direction='',bool $preview=false):array
    {
        return (new RelationshipBuildRepository($this->db))->enqueue($scope,$requestId,$limit,$direction,$preview);
    }

    /** Keep preview polling behind the same scoped repository boundary as generation. */
    public function relationshipBuildPreviewStatus(array $scope,string $jobId):array
    {
        return (new RelationshipBuildRepository($this->db))->previewStatus($scope,$jobId);
    }

    public function enqueueRelationshipConversion(array $scope,string $requestId,string $mode):array
    {
        return (new RelationshipConversionRepository($this->db))->enqueue($scope,$requestId,$mode);
    }

    public function enqueueMemorySummary(string $installation,string $memoryId,int $revision):array
    {
        if($revision<1)throw new \InvalidArgumentException('invalid_memory_revision');
        return (new MemorySummaryRepository($this->db))->enqueue($installation,$memoryId,$revision)
            ??throw new \InvalidArgumentException('memory_summary_unavailable');
    }

    public function updateMemory(string $id,string $content,array $terms,array $vector,string $now): array
    {
        $this->db->prepare('UPDATE memory_records SET content=:content,lexical_terms=CAST(:terms AS text[]),fake_vector=CAST(:vector AS jsonb),updated_at=:now WHERE memory_id=:id AND deleted_at IS NULL')
            ->execute(['content'=>$content,'terms'=>$this->pgArray($terms),'vector'=>$this->encode($vector),'now'=>$now,'id'=>$id]);
        $memory=$this->memory($id);
        (new MemoryEmbeddingRepository($this->db))->enqueue((string)$memory['installation_id'],$id,(int)$memory['current_revision']);
        return$memory;
    }

    public function deleteMemory(string $id,string $now): void {$this->db->prepare('UPDATE memory_records SET deleted_at=:now WHERE memory_id=:id')->execute(['now'=>$now,'id'=>$id]);}

    public function rebuildMemories(array $scope,string $now): int
    {
        $stmt=$this->db->prepare('SELECT memory_id,content FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL');$stmt->execute($this->scopeParams($scope));$count=0;
        foreach($stmt->fetchAll() as $row){$terms=\LorkhanServer\Application\DeterministicRetrieval::terms($row['content']);$vector=\LorkhanServer\Application\DeterministicRetrieval::fakeVector($row['content']);$this->updateMemory($row['memory_id'],$row['content'],$terms,$vector,$now);++$count;}return $count;
    }

    public function enforceMemoryRetention(array $scope,array $days,string $now): int
    {
        $count=0;foreach(['recent','mid','long'] as $tier){$retention=(int)($days[$tier]??0);if($retention<1)continue;$stmt=$this->db->prepare("UPDATE memory_records SET deleted_at=:now WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND tier=:tier AND deleted_at IS NULL AND occurred_at < CAST(:now AS timestamptz) - (:days || ' days')::interval");$stmt->execute($this->scopeParams($scope)+['tier'=>$tier,'now'=>$now,'days'=>(string)$retention]);$count+=$stmt->rowCount();}return $count;
    }

    public function memoryCandidates(array $scope,string $now): array
    {
        $stmt=$this->db->prepare('SELECT memory_id AS id,tier,content,lexical_terms,fake_vector,provenance,source_event_id,occurred_at,updated_at,current_revision FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL AND (expires_at IS NULL OR expires_at>:now) ORDER BY occurred_at DESC LIMIT 500');$stmt->execute($this->scopeParams($scope)+['now'=>$now]);return array_map(fn($r)=>$this->decodeMemory($r),$stmt->fetchAll());
    }

    /** Reuse prompt privacy/witness checks for a digest, across session profiles in the same playthrough. */
    public function memoryDigestCandidates(string $installation,string $playthrough,string $profile,string $now,?array $memoryIds=null):array
    {
        foreach([$installation,$playthrough,$profile]as$id)if(!Uuid::isValid($id))throw new \InvalidArgumentException('invalid_digest_scope');
        $q=$this->db->prepare("SELECT COALESCE((SELECT b.actor_identity FROM actor_profile_bindings b WHERE b.installation_id=p.installation_id
                AND b.playthrough_id=t.playthrough_id AND b.profile_id=p.profile_id
                AND (SELECT count(*) FROM actor_profile_bindings all_b WHERE all_b.installation_id=p.installation_id AND all_b.playthrough_id=t.playthrough_id AND all_b.profile_id=p.profile_id)=1),p.actor_identity) AS actor_identity
            FROM profiles p JOIN playthroughs t ON t.installation_id=p.installation_id
            WHERE p.profile_id=:profile AND p.installation_id=:installation AND t.playthrough_id=:playthrough
            AND p.deleted_at IS NULL AND ".ProfileScopeSql::matches('p','t.playthrough_id')."");
        $q->execute(['profile'=>$profile,'installation'=>$installation,'playthrough'=>$playthrough]);$identity=$q->fetchColumn();
        if($identity===false)return [];$actor=$this->json($identity);$key=[];
        if(!in_array($actor['kind']??'', ['actor','npc','creature'],true))return [];
        if($memoryIds!==null){if(count($memoryIds)>100)throw new \InvalidArgumentException('digest_source_limit');foreach($memoryIds as$id)if(!is_string($id)||!Uuid::isValid($id))throw new \InvalidArgumentException('invalid_digest_source');if($memoryIds===[])return [];}
        foreach(['kind','record_id','content_file']as$field)if(is_string($actor[$field]??null)&&$actor[$field]!=='')$key[$field]=$actor[$field];
        if(!isset($key['record_id'],$key['content_file']))return [];
        if(is_array($actor['refnum']??null))$key['refnum']=$actor['refnum'];
        $scope=['installation_id'=>$installation,'playthrough_id'=>$playthrough,'profile_id'=>$profile];
        $rows=[];$offset=0;$deadline=hrtime(true)+10000000000;
        do{
            $scanned=0;$batch=$this->promptMemoryCandidates($scope,$key,$profile,true,$now,[],true,$offset,$memoryIds,$scanned);
            foreach($batch as$memory)if($memory['_source_event_ids']!==[])$rows[]=$memory;
            $offset+=$scanned;
            if(hrtime(true)>$deadline)throw new RuntimeException('digest_scan_timeout');
        }while($memoryIds===null&&$scanned===500&&count($rows)<100);
        return array_slice($rows,0,100);
    }

    /** Keep manual memories with their NPC profile and require witnessed provenance for derived rows. */
    private function promptMemoryCandidates(array $turn,array $actorKey,string $activeProfileId,bool $ownsProfile,
        string $now,array $semantic=[],bool $allSessionProfiles=false,int $offset=0,?array $memoryIds=null,?int &$scanned=null):array
    {
        $statement = $this->db->prepare("SELECT m.memory_id AS id,m.profile_id,m.tier,m.content,m.lexical_terms,m.fake_vector,
            m.provenance,m.source_event_id,m.derivation_key,m.occurred_at,m.updated_at,m.current_revision,
            summary.content AS model_summary,summary.provider_configuration_id,summary.provider_revision,summary.input_sha256,
            summary.policy_configuration_id,summary.policy_revision,embedding.embedding AS semantic_embedding,
            embedding.model AS semantic_model
            FROM memory_records m LEFT JOIN memory_model_summaries summary
                ON summary.memory_id=m.memory_id AND summary.memory_revision=m.current_revision
                AND EXISTS(SELECT 1 FROM configuration_sets c JOIN configuration_revisions r
                    ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
                    WHERE c.configuration_id=summary.policy_configuration_id AND c.installation_id=m.installation_id
                        AND c.kind='memory_policy' AND c.deleted_at IS NULL AND r.content->'enabled'='true'::jsonb)
            LEFT JOIN memory_embeddings embedding ON embedding.memory_id=m.memory_id
                AND embedding.memory_revision=m.current_revision
                AND embedding.policy_configuration_id=CAST(:embedding_policy AS uuid)
                AND embedding.policy_revision=:embedding_policy_revision
            WHERE m.installation_id=:installation AND m.playthrough_id=:playthrough
                AND (CAST(:all_session_profiles AS boolean) OR m.profile_id IN (:session_profile,:actor_profile)) AND m.deleted_at IS NULL
                AND (NOT CAST(:all_session_profiles AS boolean) OR (m.tier='mid' AND m.derivation_key IS NOT NULL
                    AND m.provenance->>'source'='memory.consolidate' AND m.provenance->>'provider'='first-party'
                    AND m.provenance->>'model'='deterministic-extractive-v1'))
                AND (CAST(:memory_ids AS uuid[]) IS NULL OR m.memory_id=ANY(CAST(:memory_ids AS uuid[])))
                AND (m.expires_at IS NULL OR m.expires_at>:now) ORDER BY m.occurred_at DESC,CASE WHEN CAST(:all_session_profiles AS boolean) THEN m.memory_id END DESC,m.memory_id LIMIT 500 OFFSET :offset");
        $statement->execute(['installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
            'session_profile'=>$turn['profile_id'],'actor_profile'=>$activeProfileId,'now'=>$now,'all_session_profiles'=>$allSessionProfiles?'true':'false','offset'=>$offset,'memory_ids'=>$memoryIds===null?null:$this->pgArray($memoryIds),
            'embedding_policy'=>is_array($semantic['embedding']??null)&&array_is_list($semantic['embedding'])
                &&is_string($semantic['policy_configuration_id']??null)
                ?$semantic['policy_configuration_id']:'00000000-0000-0000-0000-000000000000',
            'embedding_policy_revision'=>is_array($semantic['embedding']??null)&&array_is_list($semantic['embedding'])
                &&is_int($semantic['policy_revision']??null)?$semantic['policy_revision']:0]);
        $candidates = [];
        $sourceIds = [];
        $selectedRows=$statement->fetchAll();$scanned=count($selectedRows);
        foreach ($selectedRows as $row) {
            $memory = $this->decodeMemory($row);
            if(is_string($row['semantic_embedding']??null)){
                $memory['_semantic_embedding']=$this->json($row['semantic_embedding']);
                $memory['_semantic_model']=(string)$row['semantic_model'];
            }
            if(is_string($row['model_summary'])){
                $memory['content']=$row['model_summary'];
                $memory['_model_summary']=['memory_revision'=>(int)$row['current_revision'],
                    'provider_configuration_id'=>$row['provider_configuration_id'],'provider_revision'=>(int)$row['provider_revision'],
                    'policy_configuration_id'=>$row['policy_configuration_id'],'policy_revision'=>(int)$row['policy_revision'],
                    'input_sha256'=>$row['input_sha256']];
            }
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
        $sql=$this->effectiveKnowledgeSql('document_id AS id,title,content,content_sha256,lexical_terms,provenance,topic,aliases,topic_desc_basic,knowledge_class,knowledge_class_basic,tags,category,(profile_id IS NOT NULL AND playthrough_id IS NOT NULL) AS story_profile_override',$loadedContentFiles!==null);
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
        $profileStatement=$this->db->prepare('SELECT p.name,p.actor_identity,p.playthrough_id FROM profiles p WHERE p.profile_id=:profile AND p.installation_id=:installation AND p.deleted_at IS NULL AND '.ProfileScopeSql::current('p'));
        $profileStatement->execute(['profile'=>$profileId,'installation'=>$installationId]);$profile=$profileStatement->fetch();if(!$profile)throw new RuntimeException('not_found');
        $effective=$this->effectiveSettingsForProfile($installationId,$profileId);$tags=$this->knowledgeValues((string)($effective['settings']['memory']['oghma_knowledge_tags']??''));
        $profile['actor_identity']=$this->json($profile['actor_identity']);
        $playthroughId=trim((string)($filters['playthrough_id']??''));
        if($profile['playthrough_id']!==null){
            if($playthroughId!==''&&$playthroughId!==$profile['playthrough_id'])throw new RuntimeException('not_found');
            $playthroughId=(string)$profile['playthrough_id'];
        }
        if($playthroughId!==''){
            if(!Uuid::isValid($playthroughId))throw new RuntimeException('not_found');
            $story=$this->db->prepare('SELECT playthrough_id,name FROM playthroughs WHERE installation_id=:installation AND playthrough_id=:playthrough AND deleted_at IS NULL');
            $story->execute(['installation'=>$installationId,'playthrough'=>$playthroughId]);
            $playthrough=$story->fetch();if(!$playthrough)throw new RuntimeException('not_found');
        }else{
            // Match the active game first, retaining the last played story when the game is closed.
            $story=$this->db->prepare("SELECT p.playthrough_id,p.name FROM sessions s JOIN playthroughs p ON p.playthrough_id=s.playthrough_id AND p.installation_id=s.installation_id WHERE s.installation_id=:installation AND NOT s.archived AND p.deleted_at IS NULL ORDER BY (s.state='active') DESC,s.created_at DESC,s.session_id DESC LIMIT 1");
            $story->execute(['installation'=>$installationId]);$playthrough=$story->fetch()?:null;
        }
        return ['profile'=>$profile+['profile_id'=>$profileId],'playthrough'=>$playthrough]+$this->oghmaKnowledgeForTags(['installation_id'=>$installationId,'profile_id'=>$profileId,'playthrough_id'=>$playthrough['playthrough_id']??null],$tags,$filters,true);
    }

    /** Preview a biography's own tags against the installation catalog without activating an NPC. */
    public function oghmaKnowledgeForBiography(string $installationId,string $tags,array $filters=[]):array
    {
        if(!Uuid::isValid($installationId))throw new RuntimeException('not_found');
        $exists=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:id');$exists->execute(['id'=>$installationId]);
        if(!$exists->fetchColumn())throw new RuntimeException('not_found');
        $result=$this->oghmaKnowledgeForTags(['installation_id'=>$installationId,'profile_id'=>null,'playthrough_id'=>null],$this->knowledgeValues($tags),$filters,true);
        // Return only the permitted description; basic previews must not contain the advanced text.
        $result['items']=array_map(static fn(array $row):array=>[
            'topic'=>$row['topic'],'level'=>ucfirst($row['access_level']),
            'description'=>html_entity_decode(strip_tags($row['effective_content']),ENT_QUOTES|ENT_HTML5,'UTF-8'),
            'category'=>$row['category'],'tags'=>$row['tags'],
            'knowledge_class'=>$row['access_level']==='advanced'?$row['knowledge_class']:'',
            'knowledge_class_basic'=>$row['access_level']==='basic'?$row['knowledge_class_basic']:'',
        ],$result['items']);
        return $result;
    }

    /** Share runtime access decisions, override resolution and pagination between NPC and biography viewers. */
    private function oghmaKnowledgeForTags(array $scope,array $tags,array $filters,bool $searchDescriptions=false):array
    {
        $search=mb_strtolower(mb_strcut(trim((string)($filters['search']??'')),0,100,'UTF-8'),'UTF-8');$category=trim((string)($filters['category']??''));
        $accessFilter=strtolower(trim((string)($filters['access']??'all')));if(!in_array($accessFilter,['all','advanced','basic'],true))$accessFilter='all';
        $items=[];$counts=['advanced'=>0,'basic'=>0,'denied'=>0];$categories=[];
        foreach($this->knowledgeCandidates($scope)as$row){
            $decision=OghmaGroundedRetriever::accessDecision($row+['topic_desc'=>$row['content']],$tags);$access=$decision['level'];if($access==='denied'){$counts['denied']++;continue;}$counts[$access]++;
            $row['access_level']=$access;$row['effective_content']=$access==='advanced'?(string)$row['content']:(string)$row['topic_desc_basic'];
            $categories[(string)$row['category']]=true;if($category!==''&&!hash_equals((string)$row['category'],$category))continue;
            if($accessFilter!=='all'&&$access!==$accessFilter)continue;
            if($search!==''&&!str_contains(mb_strtolower(implode(' ',[(string)$row['topic'],(string)$row['title'],(string)$row['aliases'],(string)$row['tags'],$searchDescriptions?$row['effective_content']:'']),'UTF-8'),$search))continue;
            $items[]=$row;
        }
        usort($items,static fn(array$a,array$b):int=>strnatcasecmp((string)$a['topic'],(string)$b['topic'])?:strcmp((string)$a['id'],(string)$b['id']));
        $page=max(1,(int)($filters['page']??1));$pageSize=50;$total=count($items);$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);
        $categoryList=array_keys($categories);natcasesort($categoryList);
        return['items'=>array_slice($items,($page-1)*$pageSize,$pageSize),'total'=>$total,
            'page'=>$page,'pages'=>$pages,'counts'=>$counts,'knowledge_tags'=>$tags,'categories'=>array_values($categoryList),
            'filters'=>['search'=>$search,'category'=>$category,'access'=>$accessFilter]];
    }

    public function recordRetrieval(string $domain,array $scope,string $query,array $rows,string $now):array
    {
        $id=Uuid::v4();$ids=array_column($rows,'id');$scores=[];foreach($rows as $r)$scores[$r['id']]=$r['score'];$this->db->prepare('INSERT INTO retrieval_traces (retrieval_trace_id,installation_id,profile_id,playthrough_id,domain,query,result_ids,scores,algorithm,created_at) VALUES (:id,:installation,:profile,:playthrough,:domain,:query,CAST(:ids AS uuid[]),CAST(:scores AS jsonb),:algorithm,:now)')->execute(['id'=>$id,'installation'=>$scope['installation_id'],'profile'=>$scope['profile_id']??null,'playthrough'=>$scope['playthrough_id']??null,'domain'=>$domain,'query'=>$query,'ids'=>$this->pgArray($ids),'scores'=>$this->encode($scores),'algorithm'=>'lexical-0.75+fake-vector-0.25-v1','now'=>$now]);return ['trace_id'=>$id,'algorithm'=>'lexical-0.75+fake-vector-0.25-v1','results'=>$rows];
    }

    /** Create once by stable identity; updates address a specific row and its observed revision. */
    public function setRelationship(array $input,string $now):array
    {
        // Derived writers cannot replace player text, even if a provider invents this field.
        if(($input['source_mode']??null)!=='manual')unset($input['custom_info']);
        elseif(array_key_exists('custom_info',$input))
            $input['custom_info']=\LorkhanServer\Application\RelationshipCustomInfo::validate($input['custom_info']);
        if(array_key_exists('relationship_type',$input))
            $input['relationship_type']=\LorkhanServer\Application\RelationshipType::manual($input['relationship_type']);
        if(array_key_exists('details',$input))$input['details']=\LorkhanServer\Application\RelationshipDetails::validate($input['details']);
        return $this->transaction(function()use($input,$now):array{
            $scope=$this->scopeParams($input);
            $owner=$this->db->prepare('SELECT 1 FROM profiles p JOIN playthroughs t ON t.installation_id=p.installation_id '
                .'WHERE p.profile_id=:profile AND p.installation_id=:installation AND t.playthrough_id=:playthrough AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','t.playthrough_id').' AND t.deleted_at IS NULL');
            $owner->execute($scope);if(!$owner->fetchColumn())throw new \InvalidArgumentException('invalid_relationship_scope');
            if(isset($input['source_event_id'])){
                $source=$this->db->prepare('SELECT 1 FROM source_events e JOIN sessions s ON s.session_id=e.session_id '
                    .'WHERE e.source_event_id=:source AND e.installation_id=:installation AND s.playthrough_id=:playthrough');
                $source->execute(['source'=>$input['source_event_id'],'installation'=>$scope['installation'],'playthrough'=>$scope['playthrough']]);
                if(!$source->fetchColumn())throw new \InvalidArgumentException('invalid_relationship_source');
            }
            $before=false;
            if(isset($input['relationship_id'])){
                $find=$this->db->prepare('SELECT * FROM relationship_records WHERE relationship_id=:id AND installation_id=:installation '
                    .'AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL FOR UPDATE');
                $find->execute($scope+['id'=>$input['relationship_id']]);$before=$find->fetch();
                if(!$before)throw new RuntimeException('not_found');
                if((int)$before['revision']!==($input['expected_revision']??null))throw new RuntimeException('relationship_revision_conflict');
                if(isset($input['actor_identity'])&&$this->actorKey($input['actor_identity'])!==$this->actorKey($this->json($before['actor_identity'])))
                    throw new \InvalidArgumentException('relationship_identity_immutable');
                $id=(string)$before['relationship_id'];
                $save=$this->db->prepare('UPDATE relationship_records SET disposition=:disposition,affinity=:affinity,relationship_type=:type,source_mode=:mode,'
                    .'source_event_id=:source,custom_info=:custom,details=CAST(:details AS jsonb),updated_at=:now WHERE relationship_id=:id AND revision=:revision RETURNING revision');
                $params=['id'=>$id,'revision'=>$input['expected_revision']];
            }else{
                $identity=$this->encode($input['actor_identity']);
                // Serialize absent-row creation; an ordinary row lock cannot protect a missing identity.
                $lock=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');
                $lock->execute(['key'=>'relationship:'.implode(':',$scope).':'.$this->actorKey($input['actor_identity'])]);
                $find=$this->db->prepare('SELECT relationship_id FROM relationship_records WHERE installation_id=:installation '
                    .'AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL '
                    .'AND md5(relationship_identity_key(actor_identity)::text)=md5(relationship_identity_key(CAST(:identity AS jsonb))::text) '
                    .'AND relationship_identity_key(actor_identity)=relationship_identity_key(CAST(:exact_identity AS jsonb)) LIMIT 1');
                $find->execute($scope+['identity'=>$identity,'exact_identity'=>$identity]);
                if($find->fetchColumn())throw new RuntimeException('relationship_already_exists');
                $id=Uuid::v4();
                $save=$this->db->prepare('INSERT INTO relationship_records (relationship_id,installation_id,profile_id,playthrough_id,actor_identity,'
                    .'disposition,affinity,relationship_type,source_mode,source_event_id,custom_info,details,updated_at) VALUES (:id,:installation,:profile,:playthrough,CAST(:identity AS jsonb),'
                    .':disposition,:affinity,:type,:mode,:source,:custom,CAST(:details AS jsonb),:now) RETURNING revision');
                $params=$scope+['id'=>$id,'identity'=>$identity];
            }
            $timeline=new RelationshipTimelineRepository($this->db);
            if($before)$timeline->snapshot($before);
            $customInfo=$input['custom_info']??($before['custom_info']??'');
            $details=$input['details']??$this->json($before['details']??'{}');
            $relationshipType=$input['relationship_type']??($before['relationship_type']??'neutral');
            $targetIdentity=$input['actor_identity']??($before?$this->json($before['actor_identity']):[]);
            if(($targetIdentity['kind']??'')==='player'){
                $mirror=$this->db->prepare('SELECT g.disposition FROM game_dispositions g JOIN sessions live ON live.session_id=g.session_id AND live.generation=g.generation AND live.state=\'active\' JOIN actor_profile_bindings b ON b.installation_id=g.installation_id AND b.playthrough_id=g.playthrough_id AND b.actor_key=g.actor_key WHERE g.installation_id=:installation AND g.playthrough_id=:playthrough AND b.profile_id=:profile AND g.player_key=:player');
                $mirror->execute($scope+['player'=>$this->actorKey($targetIdentity)]);$score=$mirror->fetchColumn();
                // Unknown legacy values are not converted; prompt readers mark them unknown.
                if($score!==false)$input['disposition']=(int)$score;
            }
            $save->execute($params+['details'=>$this->encode($details),'custom'=>$customInfo,'disposition'=>$input['disposition'],'affinity'=>$input['affinity'],'type'=>$relationshipType,'mode'=>$input['source_mode'],
                'source'=>$input['source_event_id']??null,'now'=>$now]);
            $revision=$save->fetchColumn();if($revision===false)throw new RuntimeException('relationship_revision_conflict');
            $after=['disposition'=>$input['disposition'],'affinity'=>$input['affinity'],'relationship_type'=>$relationshipType,'revision'=>(int)$revision];
            if($customInfo!==($before['custom_info']??''))$after['custom_info_changed']=true;
            if($details!=$this->json($before['details']??'{}'))$after['details']=$details;
            $this->db->prepare('INSERT INTO relationship_audit (audit_id,relationship_id,mode,before_value,after_value,reason,source_event_id,created_at) '
                .'VALUES (:audit,:id,:mode,CAST(:before AS jsonb),CAST(:after AS jsonb),:reason,:source,:now)')->execute([
                    'audit'=>Uuid::v4(),'id'=>$id,'mode'=>$input['source_mode'],
                    'before'=>$this->encode($before?['disposition'=>(int)$before['disposition'],'affinity'=>(int)$before['affinity'],
                        'relationship_type'=>(string)$before['relationship_type'],'revision'=>(int)$before['revision']]:[]),
                    'after'=>$this->encode($after),'reason'=>$input['reason']??'updated','source'=>$input['source_event_id']??null,'now'=>$now]);
            $timeline->recordWrite($id,$before?(int)$before['revision']:0,$input['_relationship_job']??null);
            return ['relationship_id'=>$id]+$after+['source_mode'=>$input['source_mode']];
        });
    }

    /** Soft-delete exactly the revision shown to the editor, keeping its audit history. */
    public function deleteRelationship(string $id,string $now,int $expectedRevision,array $scope=[]):void
    {
        $this->transaction(function()use($id,$now,$expectedRevision,$scope):void{
            $filter=$scope===[]?'':' AND installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough';
            $find=$this->db->prepare('SELECT disposition,affinity,relationship_type,revision FROM relationship_records WHERE relationship_id=:id AND deleted_at IS NULL'.$filter.' FOR UPDATE');
            $find->execute(['id'=>$id]+($scope===[]?[]:$this->scopeParams($scope)));$before=$find->fetch();if(!$before)throw new RuntimeException('not_found');
            if((int)$before['revision']!==$expectedRevision)throw new RuntimeException('relationship_revision_conflict');
            $save=$this->db->prepare('UPDATE relationship_records SET deleted_at=:now,updated_at=:now WHERE relationship_id=:id AND revision=:revision RETURNING revision');
            $save->execute(['now'=>$now,'id'=>$id,'revision'=>$expectedRevision]);$revision=$save->fetchColumn();
            if($revision===false)throw new RuntimeException('relationship_revision_conflict');
            $this->db->prepare('INSERT INTO relationship_audit (audit_id,relationship_id,mode,before_value,after_value,reason,created_at) '
                .'VALUES (:audit,:id,:mode,CAST(:before AS jsonb),CAST(:after AS jsonb),:reason,:now)')->execute([
                    'audit'=>Uuid::v4(),'id'=>$id,'mode'=>'manual',
                    'before'=>$this->encode(['disposition'=>(int)$before['disposition'],'affinity'=>(int)$before['affinity'],
                        'relationship_type'=>(string)$before['relationship_type'],'revision'=>(int)$before['revision']]),
                    'after'=>$this->encode(['deleted'=>true,'revision'=>(int)$revision]),'reason'=>'management delete','now'=>$now]);
        });
    }

    /** A compact concurrency token covers every active row, not just the bounded editor table. */
    public function relationshipClearSnapshot(array $scope):array
    {
        $query=$this->db->prepare("SELECT count(*)::int AS count,md5(COALESCE(string_agg(relationship_id::text||':'||revision::text,',' ORDER BY relationship_id),'')) AS token
            FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL");
        $query->execute($this->scopeParams($scope));return$query->fetch();
    }

    /** Clear only the confirmed snapshot atomically; concurrent edits cause a complete rollback. */
    public function clearRelationships(array $scope,string $expectedToken,string $now):int
    {
        if(preg_match('/^[0-9a-f]{32}$/D',$expectedToken)!==1)throw new \InvalidArgumentException('invalid_relationship_snapshot');
        return $this->transaction(function()use($scope,$expectedToken,$now):int{
            $query=$this->db->prepare('SELECT relationship_id,revision FROM relationship_records WHERE installation_id=:installation '
                .'AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY relationship_id FOR UPDATE');
            $query->execute($this->scopeParams($scope));$rows=$query->fetchAll();
            $token=md5(implode(',',array_map(static fn(array$row):string=>$row['relationship_id'].':'.$row['revision'],$rows)));
            if(!hash_equals($token,$expectedToken))throw new RuntimeException('relationship_revision_conflict');
            // New actors inserted after this snapshot are deliberately not included in the deletion.
            foreach($rows as$row)$this->deleteRelationship($row['relationship_id'],$now,(int)$row['revision']);
            return count($rows);
        });
    }

    /** Runtime readers never load Custom Info; only management rows and explicit exports may expose it. */
    public function relationships(array $scope):array
    {
        $s=$this->db->prepare('SELECT relationship_id,installation_id,profile_id,playthrough_id,actor_identity,disposition,affinity,relationship_type,'
            .'details,source_mode,source_event_id,updated_at,deleted_at,revision FROM relationship_records WHERE installation_id=:installation '
            .'AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 100');
        $s->execute($this->scopeParams($scope));
        return array_map(function($r){$r['actor_identity']=$this->json($r['actor_identity']);$r['details']=$this->json($r['details']);return $r;},$s->fetchAll());
    }

    public function createNarrative(array $input,string $now):array{$id=Uuid::v4();$this->db->prepare('INSERT INTO narrative_records (narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) VALUES (:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now)')->execute($this->scopeParams($input)+['id'=>$id,'kind'=>$input['kind'],'title'=>$input['title'],'content'=>$input['content'],'provenance'=>$this->encode($input['provenance']),'now'=>$now]);return ['narrative_id'=>$id]+$input;}
    // Manual edits preserve generation sources and recorded calendar context.
    public function updateNarrative(string $id,array $input,string $now):array{$statement=$this->db->prepare('UPDATE narrative_records SET kind=:kind,title=:title,content=:content,provenance=provenance || CAST(:provenance AS jsonb),updated_at=:now WHERE narrative_id=:id AND deleted_at IS NULL RETURNING narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,updated_at');
        $statement->execute(['id'=>$id,'kind'=>$input['kind'],'title'=>$input['title'],'content'=>$input['content'],'provenance'=>$this->encode($input['provenance']),'now'=>$now]);$row=$statement->fetch();if(!$row)throw new RuntimeException('not_found');$row['provenance']=$this->json($row['provenance']);return$row;}
    public function deleteNarrative(string $id,string $now):void{$this->db->prepare('UPDATE narrative_records SET deleted_at=:now,updated_at=:now WHERE narrative_id=:id')->execute(['now'=>$now,'id'=>$id]);}
    public function narratives(array $scope):array{$s=$this->db->prepare('SELECT * FROM narrative_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY created_at DESC,narrative_id LIMIT 100');$s->execute($this->scopeParams($scope));return array_map(function($r){$r['provenance']=$this->json($r['provenance']);return $r;},$s->fetchAll());}

    public function exportScope(array $scope):array
    {
        $queries=[
            'memories'=>'SELECT memory_id AS id,tier,content,lexical_terms,fake_vector,provenance,source_event_id,occurred_at,expires_at FROM memory_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY occurred_at DESC',
            'relationships'=>'SELECT * FROM relationship_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY updated_at DESC',
            'narratives'=>'SELECT * FROM narrative_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND deleted_at IS NULL ORDER BY created_at DESC'];
        $result=[];foreach($queries as$name=>$sql){$s=$this->db->prepare($sql);$s->execute($this->scopeParams($scope));$rows=$s->fetchAll();if($name==='memories')$rows=array_map(fn($r)=>$this->decodeMemory($r),$rows);elseif($name==='relationships')$rows=array_map(function($r){$r['actor_identity']=$this->json($r['actor_identity']);$r['details']=$this->json($r['details']);return$r;},$rows);else$rows=array_map(function($r){$r['provenance']=$this->json($r['provenance']);return$r;},$rows);$result[$name]=$rows;}return$result;
    }
    public function restoreScope(array $document,string $now):array
    {
        return$this->transaction(function()use($document,$now):array{$counts=['memories'=>0,'relationships'=>0,'narratives'=>0];$scope=$document['scope'];
            $owner=$this->db->prepare('SELECT 1 FROM profiles p JOIN playthroughs t ON t.installation_id=p.installation_id WHERE p.profile_id=:profile AND p.installation_id=:installation AND t.playthrough_id=:playthrough AND p.deleted_at IS NULL AND t.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','t.playthrough_id').' FOR SHARE OF p,t');
            $owner->execute($this->scopeParams($scope));if(!$owner->fetchColumn())throw new RuntimeException('backup_scope_conflict');
            $key=hash('sha256',$this->encode($document));
            foreach($document['data']['memories'] as$i=>$r){$id=$this->deterministicUuid('restore:memory:'.$key.':'.$i);$s=$this->db->prepare('SELECT 1 FROM memory_records WHERE memory_id=:id');$s->execute(['id'=>$id]);if(!$s->fetchColumn()){$this->db->prepare('INSERT INTO memory_records(memory_id,installation_id,profile_id,playthrough_id,tier,content,lexical_terms,fake_vector,source_event_id,provenance,occurred_at,expires_at,created_at,updated_at) VALUES(:id,:installation,:profile,:playthrough,:tier,:content,CAST(:terms AS text[]),CAST(:vector AS jsonb),:source,CAST(:provenance AS jsonb),:occurred,:expires,:now,:now)')->execute($this->scopeParams($scope)+['id'=>$id,'tier'=>$r['tier'],'content'=>$r['content'],'terms'=>$this->pgArray($r['lexical_terms']),'vector'=>$this->encode(\LorkhanServer\Application\DeterministicRetrieval::fakeVector($r['content'])),'source'=>$r['source_event_id']??null,'provenance'=>$this->encode($r['provenance']??['source'=>'restore','key'=>$key]),'occurred'=>$r['occurred_at'],'expires'=>$r['expires_at']??null,'now'=>$now]);}$counts['memories']++;}
            $relationships=$document['data']['relationships'];
            usort($relationships,fn(array$a,array$b):int=>$this->restoreRelationshipKey($a)<=>$this->restoreRelationshipKey($b));
            $previous=null;foreach($relationships as$r){
                $restoreKey=$this->restoreRelationshipKey($r);
                if($restoreKey===$previous)throw new RuntimeException('relationship_restore_conflict');
                $this->restoreRelationship($scope,$r,$now);$counts['relationships']++;$previous=$restoreKey;
            }
            foreach($document['data']['narratives'] as$i=>$r){$id=$this->deterministicUuid('restore:narrative:'.$key.':'.$i);$s=$this->db->prepare('SELECT 1 FROM narrative_records WHERE narrative_id=:id');$s->execute(['id'=>$id]);if(!$s->fetchColumn())$this->db->prepare('INSERT INTO narrative_records(narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at,updated_at) VALUES(:id,:installation,:profile,:playthrough,:kind,:title,:content,CAST(:provenance AS jsonb),:now,:now)')->execute($this->scopeParams($scope)+['id'=>$id,'kind'=>$r['kind'],'title'=>$r['title'],'content'=>$r['content'],'provenance'=>$this->encode($r['provenance']??['source'=>'restore','key'=>$key]),'now'=>$now]);$counts['narratives']++;}return$counts;});
    }

    private function restoreRelationshipKey(array $row):string
    {
        $identity=$row['actor_identity'];
        return isset($identity['kind'],$identity['record_id'],$identity['content_file'],$identity['refnum'])
            ?'stable:'.$this->actorKey($identity):'legacy:'.$row['relationship_id'];
    }

    /** Reconcile one imported relationship without overwriting a local edit or resurrecting a deletion. */
    private function restoreRelationship(array $scope,array $row,string $now):void
    {
        $identity=$row['actor_identity'];$identityJson=$this->encode($identity);
        $custom=array_key_exists('custom_info',$row)
            ?\LorkhanServer\Application\RelationshipCustomInfo::validate($row['custom_info']):null;
        $relationshipType=array_key_exists('relationship_type',$row)
            ?\LorkhanServer\Application\RelationshipType::manual($row['relationship_type']):null;
        $details=array_key_exists('details',$row)?\LorkhanServer\Application\RelationshipDetails::validate($row['details']):null;
        $stable=isset($identity['kind'],$identity['record_id'],$identity['content_file'],$identity['refnum']);
        $existing=[];
        if($stable){
            $id=Uuid::v4();$lockKey='relationship:'.implode(':',$this->scopeParams($scope)).':'.$this->actorKey($identity);
            $lock=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');$lock->execute(['key'=>$lockKey]);
            $query=$this->db->prepare('SELECT relationship_id,disposition,affinity,relationship_type,custom_info,details,deleted_at FROM relationship_records '
                .'WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough '
                .'AND md5(relationship_identity_key(actor_identity)::text)=md5(relationship_identity_key(CAST(:identity AS jsonb))::text) '
                .'AND relationship_identity_key(actor_identity)=relationship_identity_key(CAST(:exact_identity AS jsonb)) '
                .'ORDER BY relationship_id LIMIT 101 FOR UPDATE');
            $query->execute($this->scopeParams($scope)+['identity'=>$identityJson,'exact_identity'=>$identityJson]);
            $existing=$query->fetchAll();
        }else{
            $id=$this->deterministicUuid('restore:relationship:'.implode(':',$this->scopeParams($scope)).':'.$row['relationship_id']);
            $lock=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');
            $lock->execute(['key'=>'restore-relationship:'.$id]);
            $query=$this->db->prepare('SELECT relationship_id,disposition,affinity,relationship_type,custom_info,details,deleted_at FROM relationship_records '
                .'WHERE relationship_id=:id FOR UPDATE');$query->execute(['id'=>$id]);$existing=$query->fetchAll();
        }
        $active=array_values(array_filter($existing,static fn(array$value):bool=>$value['deleted_at']===null));
        if(count($active)>1||($existing!==[]&&$active===[]))throw new RuntimeException('relationship_restore_conflict');
        if($active!==[]){
            $saved=$active[0];
            if((int)$saved['disposition']!==(int)$row['disposition']||(int)$saved['affinity']!==(int)$row['affinity']
                ||($relationshipType!==null&&(string)$saved['relationship_type']!==$relationshipType)
                ||($custom!==null&&(string)$saved['custom_info']!==$custom)
                ||($details!==null&&\LorkhanServer\Application\RelationshipDetails::validate($this->json($saved['details']))!==$details))throw new RuntimeException('relationship_restore_conflict');
            return;
        }
        $this->db->prepare('INSERT INTO relationship_records(relationship_id,installation_id,profile_id,playthrough_id,actor_identity,'
            .'disposition,affinity,relationship_type,source_mode,custom_info,details,updated_at) VALUES(:id,:installation,:profile,:playthrough,CAST(:identity AS jsonb),'
            .':disposition,:affinity,:type,\'manual\',:custom,CAST(:details AS jsonb),:now)')->execute($this->scopeParams($scope)+[
                'id'=>$id,'identity'=>$identityJson,'disposition'=>$row['disposition'],'affinity'=>$row['affinity'],
                'type'=>$relationshipType??'neutral','custom'=>$custom??'','details'=>$this->encode($details??[]),'now'=>$now]);
        $after=['disposition'=>$row['disposition'],'affinity'=>$row['affinity'],
            'relationship_type'=>$relationshipType??'neutral','revision'=>1];
        if($custom!==null&&$custom!=='')$after['custom_info_changed']=true;
        $this->db->prepare('INSERT INTO relationship_audit(audit_id,relationship_id,mode,before_value,after_value,reason,created_at) '
            .'VALUES(:audit,:id,\'manual\',\'{}\'::jsonb,CAST(:after AS jsonb),\'Manual backup restore\',:now)')->execute([
                'audit'=>Uuid::v4(),'id'=>$id,'after'=>$this->encode($after),'now'=>$now]);
    }

    /** Return only safe, displayable in-game controls for this active session and actor. */
    public function sessionControls(array $session, array $target): array
    {
        $profiles=$this->db->prepare("SELECT profile_id,name,current_revision FROM profiles "
            ."WHERE installation_id=:installation AND deleted_at IS NULL AND ".ProfileScopeSql::visible('profiles',':profile_playthrough')." "
            ."AND COALESCE(actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY name,profile_id LIMIT 100");
        $profiles->execute(['installation'=>$session['installation_id'],'profile_playthrough'=>$session['playthrough_id']]);
        $profileRows=array_map(static fn(array$row):array=>['profile_id'=>(string)$row['profile_id'],
            'name'=>(string)$row['name'],'revision'=>(int)$row['current_revision']],$profiles->fetchAll());
        $narrator=$this->narratorProfileForInstallation((string)$session['installation_id']);
        $effective=$this->effectiveSettingsForActor((string)$session['installation_id'],(string)$session['playthrough_id'],$target);
        $profile=is_array($effective['npc_profile']??null)?$effective['npc_profile']:null;
        $core=is_array($effective['core_profile']??null)?$effective['core_profile']:null;
        $routing=is_array($effective['routing']??null)?$effective['routing']:[];
        $configurationIds=[];
        foreach(self::MODEL_SLOTS as$definition){$id=trim((string)($routing[$definition['field']]??''));if($id!=='')$configurationIds[$id]=true;}
        $providerRows=[];
        if($configurationIds!==[]){
            $placeholders=[];$parameters=['installation'=>$session['installation_id']];$index=0;
            foreach(array_keys($configurationIds)as$id){$key='model_'.$index++;$placeholders[]=':'.$key;$parameters[$key]=$id;}
            $providers=$this->db->prepare("SELECT c.configuration_id,c.name,c.current_revision,r.content FROM configuration_sets c "
                ."JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision "
                ."WHERE c.installation_id=:installation AND c.kind='provider' AND c.deleted_at IS NULL "
                ."AND c.configuration_id IN(".implode(',',$placeholders).')');
            $providers->execute($parameters);foreach($providers->fetchAll()as$row)$providerRows[(string)$row['configuration_id']]=$row;
        }
        $modelSlots=[];$configuredKeys=[];
        foreach(self::MODEL_SLOTS as$key=>$definition){$id=trim((string)($routing[$definition['field']]??''));$row=$id===''?null:($providerRows[$id]??null);
            if($row!==null){$content=$this->json($row['content']);$configuredKeys[]=$key;$modelSlots[]=[
                'key'=>$key,'label'=>$definition['label'],'available'=>true,'configuration_id'=>(string)$row['configuration_id'],
                'configuration_name'=>(string)$row['name'],'revision'=>(int)$row['current_revision'],
                'driver'=>($content['driver']??'mock')==='mock'?'mock':'configured','model'=>(string)($content['model']??'deterministic-mock-v1')];
            }else{$modelSlots[]=['key'=>$key,'label'=>$definition['label'],'available'=>false,'configuration_id'=>null,
                'configuration_name'=>null,'revision'=>null,'driver'=>null,'model'=>null];}}
        $selectedModel=$this->selectedModelSlot((string)$session['installation_id']);
        $resolvedModel=$this->routingFlag($routing,'llm_randomizer_enabled')?null:
            (in_array($selectedModel,$configuredKeys,true)?$selectedModel:($configuredKeys[0]??null));
        $effectiveSettings=[
            'schema'=>'lorkhan.effective-settings.v1',
            'profile_id'=>$profile===null?null:(string)$profile['profile_id'],
            'profile_revision'=>$profile===null?null:(int)($profile['revision']??$profile['current_revision']??0),
            'core_profile_id'=>$core===null?null:(string)$core['core_profile_id'],
            'core_profile_revision'=>$core===null?null:(int)($core['revision']??$core['current_revision']??0),
        ]+EffectiveSettingsResolver::controlsProjection($effective);
        $effectiveSettings['change_token']=hash('sha256',$this->encodeCanonical($effectiveSettings));
        return ['model_slots'=>$modelSlots,'profiles'=>$profileRows,
            'selected_model_slot_key'=>$selectedModel,'resolved_model_slot_key'=>$resolvedModel,
            'narrator_profile_id'=>$narrator===null?null:(string)$narrator['profile_id'],
            'selected_profile_id'=>$this->selectedActorProfileId((string)$session['installation_id'],
                (string)$session['playthrough_id'],$target),
            'effective_settings'=>$effectiveSettings,
            'settings_editor'=>$this->inGameSettingsState($session,$target,$effective)['editor']];
    }

    /** Share only editable fields with the client, retaining full documents on the server for fenced edits. */
    private function inGameSettingsState(array $session,array $target,?array $effective=null,bool $lockSlots=false):array
    {
        $effective??=$this->effectiveSettingsForActor((string)$session['installation_id'],(string)$session['playthrough_id'],$target);
        $global=$this->globalSettingsForInstallation((string)$session['installation_id']);
        $documents=['global'=>$global??['content'=>\LorkhanServer\Application\SettingsCatalog::globalDefaults()]];
        if(is_array($effective['core_profile']??null))$documents['core_profile']=$effective['core_profile'];
        if(is_array($effective['npc_profile']??null))$documents['npc']=$effective['npc_profile'];
        // Slot labels and assignments participate in the menu token, including edits made on the web.
        $query=$this->db->prepare('SELECT core_profile_id,label,slot,current_revision FROM core_profiles WHERE installation_id=:installation AND deleted_at IS NULL AND slot BETWEEN 1 AND 4 ORDER BY slot'.($lockSlots?' FOR SHARE':''));
        $query->execute(['installation'=>$session['installation_id']]);$slots=$query->fetchAll();
        $sections=[];
        foreach($documents as$scope=>$document){
            $content=$document['content'];
            if($scope==='global')$content=EffectiveSettingsResolver::validateGlobalSettings($content);
            $documents[$scope]['content']=$content;
            $displayContent=$content;
            if($scope==='core_profile'){
                $coreEffective=(new EffectiveSettingsResolver())->resolve($documents['global']['content'],$content,[]);
                $displayContent['settings_overrides']=$coreEffective['settings'];
            }
            $fields=\LorkhanServer\Application\InGameSettings::fields($scope,$displayContent);
            foreach($fields as&$field)unset($field['_path']);unset($field);
            if($scope==='npc'&&$slots!==[]&&in_array($target['kind']??'',['npc','actor','creature'],true)){
                $choices=[];$selected='current';
                foreach($slots as$slot){$value=(string)$slot['slot'];
                    $choices[]=['value'=>$value,'label'=>'Slot '.$value.': '.mb_substr($slot['label'],0,110,'UTF-8')];
                    if($slot['core_profile_id']===($effective['core_profile']['core_profile_id']??null))$selected=$value;}
                if($selected==='current')array_unshift($choices,['value'=>'current','label'=>'Current (not in a quick slot)']);
                array_unshift($fields,['key'=>'management.core_profile_slot','label'=>'Assign Core Profile to this NPC',
                    'kind'=>'choice','value'=>$selected,'choices'=>$choices]);
            }
            $sections[]=['scope'=>$scope,'label'=>match($scope){'global'=>'Global Settings','core_profile'=>'Core Profile',default=>'NPC Settings'},'fields'=>$fields];
        }
        $token=hash('sha256',$this->encodeCanonical([$session['session_id']??'',$session['generation'],$target,$documents,$slots]));
        return ['documents'=>$documents,'slots'=>$slots,'editor'=>['change_token'=>$token,'sections'=>$sections]];
    }

    /** Apply a user-selected setting to the same session, target and revision that supplied the menu. */
    public function selectInGameSetting(array $session,array $target,array $selection,string $now):void
    {
        $this->transaction(function()use($session,$target,$selection,$now):void{
            if(($selection['scope']??'')==='global')$this->db->query("SELECT pg_advisory_xact_lock(7514,120)");
            $lock=$this->db->prepare("SELECT generation FROM sessions WHERE session_id=:session AND state='active' FOR UPDATE");
            $lock->execute(['session'=>$session['session_id']]);
            if((int)$lock->fetchColumn()!==(int)$session['generation'])throw new \DomainException('stale_generation');
            $slotSelection=($selection['scope']??'')==='npc'&&($selection['key']??'')==='management.core_profile_slot';
            if($slotSelection){
                $binding=$this->db->prepare('SELECT p.profile_id FROM actor_profile_bindings b JOIN profiles p ON p.profile_id=b.profile_id WHERE b.installation_id=:installation AND b.playthrough_id=:playthrough AND b.actor_key=:key AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','b.playthrough_id').' FOR UPDATE OF b,p');
                $binding->execute(['installation'=>$session['installation_id'],'playthrough'=>$session['playthrough_id'],'key'=>$this->actorKey($target)]);
                if(!$binding->fetchColumn())throw new \InvalidArgumentException('invalid_settings_scope');
            }
            $state=$this->inGameSettingsState($session,$target,null,$slotSelection);
            if(!hash_equals($state['editor']['change_token'],(string)($selection['change_token']??'')))throw new \DomainException('revision_conflict');
            $scope=(string)($selection['scope']??'');$document=$state['documents'][$scope]??null;
            if($document===null)throw new \InvalidArgumentException('invalid_settings_scope');
            if($slotSelection){
                $field=null;foreach($state['editor']['sections']as$section)if($section['scope']==='npc')
                    foreach($section['fields']as$candidate)if($candidate['key']==='management.core_profile_slot')$field=$candidate;
                $value=(string)($selection['value']??'');
                if($field===null||!in_array($value,array_column($field['choices'],'value'),true))throw new \InvalidArgumentException('invalid_setting');
                if($value==='current')return;
                foreach($state['slots']as$slot)if((string)$slot['slot']===$value){
                    $this->assignCoreProfile((string)$document['profile_id'],(string)$slot['core_profile_id']);return;}
                throw new \InvalidArgumentException('invalid_setting');
            }
            $content=\LorkhanServer\Application\InGameSettings::apply($scope,$document['content'],
                (string)($selection['key']??''),(string)($selection['value']??''));
            $kind=match($scope){'global'=>'global_settings','core_profile'=>'core_profile','npc'=>'profile'};
            $id=(string)($document['configuration_id']??$document['core_profile_id']??$document['profile_id']??'');
            if($id==='')$this->createRevisioned($kind,['installation_id'=>$session['installation_id'],'name'=>'Global Settings','content'=>$content],$now);
            else $this->revise($kind,$id,$content,'In-game Interact settings',$now,(int)($document['current_revision']??$document['revision']));
            if($scope==='global'&&($selection['key']??'')==='profile_management.auto_lock_profile')
                $this->setProfileAutoLock((string)$session['installation_id'],(bool)$content['profile_management']['auto_lock_profile'],$now);
        });
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

        $profiles=$this->db->prepare('SELECT p.profile_id,p.playthrough_id,p.core_profile_id,p.name,p.actor_identity,r.content FROM profiles p '
            .'JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision '
            .'WHERE p.installation_id=:installation AND p.deleted_at IS NULL ORDER BY p.name,p.profile_id LIMIT 2000');
        $profiles->execute(['installation'=>$installationId]);$profileRows=[];
        foreach($profiles->fetchAll() as$row){$content=$this->json($row['content']);unset($content['portrait']);$profileRows[]=[
            'profile_id'=>(string)$row['profile_id'],'playthrough_id'=>$row['playthrough_id'],'core_profile_id'=>$row['core_profile_id']===null?null:(string)$row['core_profile_id'],
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
                $find=$this->db->prepare('SELECT installation_id,playthrough_id,current_revision FROM profiles WHERE profile_id=:id FOR UPDATE');
                $find->execute(['id'=>$id]);$existing=$find->fetch();
                if($existing&&$existing['installation_id']!==$installation)throw new RuntimeException('backup_scope_conflict');
                $playthrough=$row['playthrough_id']??($existing['playthrough_id']??null);
                if($existing&&array_key_exists('playthrough_id',$row)&&$row['playthrough_id']!==$existing['playthrough_id'])throw new RuntimeException('backup_scope_conflict');
                $shared=in_array($row['actor_identity']['kind']??'actor',['narrator','template'],true);
                if($shared&&$playthrough!==null)throw new RuntimeException('backup_scope_conflict');
                if($playthrough!==null){
                    $scope=$this->db->prepare('SELECT 1 FROM playthroughs WHERE playthrough_id=:playthrough AND installation_id=:installation AND deleted_at IS NULL FOR SHARE');
                    $scope->execute(['playthrough'=>$playthrough,'installation'=>$installation]);
                    if(!$scope->fetchColumn())throw new RuntimeException('backup_scope_conflict');
                }elseif(!$shared&&!$existing){
                    $scoped=$this->db->prepare('SELECT 1 FROM character_playthrough_bindings WHERE installation_id=:installation LIMIT 1');
                    $scoped->execute(['installation'=>$installation]);
                    if($scoped->fetchColumn())throw new RuntimeException('backup_scope_conflict');
                }
                if($existing){$next=(int)$existing['current_revision']+1;
                    $this->db->prepare('UPDATE profiles SET core_profile_id=:core,name=:name,actor_identity=CAST(:identity AS jsonb),current_revision=:revision,deleted_at=NULL WHERE profile_id=:id')
                        ->execute(['core'=>$coreProfileId,'name'=>$row['name'],'identity'=>$this->encode($row['actor_identity']),'revision'=>$next,'id'=>$id]);
                }else{$next=1;$this->db->prepare('INSERT INTO profiles(profile_id,installation_id,playthrough_id,core_profile_id,name,actor_identity,current_revision,created_at) '
                    .'VALUES(:id,:installation,:playthrough,:core,:name,CAST(:identity AS jsonb),1,:now)')->execute(['id'=>$id,'installation'=>$installation,'playthrough'=>$playthrough,
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

            foreach($document['data']['configurations']as$row)if(in_array($row['kind'],['memory_policy','memory_embedding_policy','translation_policy'],true)){
                if($row['profile_id']!==null)throw new \InvalidArgumentException($row['kind'].'_is_installation_scoped');
                if($row['kind']==='memory_policy')
                    (new MemorySummaryRepository($this->db))->assertProvider($installation,$row['content']);
                elseif($row['kind']==='memory_embedding_policy')\LorkhanServer\Application\MemoryEmbeddingPolicy::validate($row['content']);
                else \LorkhanServer\Application\TranslationPolicy::validate($row['content']);
            }
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

    /** Assign or clear a roleplay profile for one stable OpenMW actor identity. */
    public function bindActorProfile(array $session, array $target, ?string $profileId, string $now): void
    {
        $key=$this->actorKey($target);
        $this->transaction(function()use($session,$target,$profileId,$now,$key):void{
            if($profileId===null){$delete=$this->db->prepare('DELETE FROM actor_profile_bindings WHERE installation_id=:installation '
                .'AND playthrough_id=:playthrough AND actor_key=:key');$delete->execute(['installation'=>$session['installation_id'],
                    'playthrough'=>$session['playthrough_id'],'key'=>$key]);return;}
            $profile=$this->db->prepare('SELECT 1 FROM profiles WHERE profile_id=:profile AND installation_id=:installation AND deleted_at IS NULL AND '.ProfileScopeSql::matches('profiles',':playthrough'));
            $profile->execute(['playthrough'=>$session['playthrough_id'],'profile'=>$profileId,'installation'=>$session['installation_id']]);
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
        $target=$turn['payload']['target']??null;if(!is_array($target)||array_is_list($target))return null;
        $routing=$this->routingForActor((string)$turn['installation_id'],(string)$turn['playthrough_id'],$target);
        $fields=array_column(self::MODEL_SLOTS,'field');
        $configured=array_values(array_filter($fields,static fn(string$field):bool=>trim((string)($routing[$field]??''))!==''));
        if($configured===[])return null;$field=self::MODEL_SLOTS[$this->selectedModelSlot((string)$turn['installation_id'])]['field'];
        if(!in_array($field,$configured,true))$field=$configured[0];
        if($this->routingFlag($routing,'llm_randomizer_enabled')&&count($configured)>1){
            $turnId=(string)($turn['turn_id']??'');$index=(int)(hexdec(substr(hash('sha256',$turnId),0,8))%count($configured));$field=$configured[$index];
        }
        $selected=$this->connectorForActor((string)$turn['installation_id'],(string)$turn['playthrough_id'],$target,'provider',$field);
        if($selected!==null)return$selected;
        foreach($configured as$fallbackField){$fallback=$this->connectorForActor((string)$turn['installation_id'],
            (string)$turn['playthrough_id'],$target,'provider',$fallbackField);if($fallback!==null)return$fallback;}
        return null;
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
        if(($effective['context']['sections']['oghma']??true)!==true)return['settings'=>$settings,'connector'=>null,'context'=>'','grounding_text'=>'','status'=>'disabled'];
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
        $statement=$this->db->prepare($this->effectiveKnowledgeSql('topic,aliases,tags,category,(profile_id IS NOT NULL AND playthrough_id IS NOT NULL) AS story_profile_override',true));
        $statement->execute(['installation'=>(string)$turn['installation_id'],
            'profile'=>$selected??(string)$turn['profile_id'],'playthrough'=>(string)$turn['playthrough_id'],
            'loaded_content_files'=>$this->pgArray(array_keys($this->contentFilesForTurn($turn)))]);
        return DynamicOghmaRepository::overlay($statement->fetchAll(),$turn['_dynamic_oghma_plan']??[]);
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

    public function promptContext(array $turn,string $now,array $oghmaExtraction=[],array $semanticMemory=[]): array
    {
        $scope = ['installation_id'=>$turn['installation_id'],'profile_id'=>$turn['profile_id'],'playthrough_id'=>$turn['playthrough_id']];
        $selectedProfileId=($turn['payload']['target']['kind']??null)==='narrator'
            ?($this->narratorProfileForInstallation((string)$turn['installation_id'])['profile_id']??null)
            :$this->selectedActorProfileId($turn['installation_id'],$turn['playthrough_id'],$turn['payload']['target']);
        $activeProfileId=$selectedProfileId??$turn['profile_id'];
        $profile = $this->getRevisioned('profile', $activeProfileId);
        $effective=$this->effectiveSettingsForProfile((string)$turn['installation_id'],$activeProfileId);
        $contextPolicy=$effective['context'];$contextSections=$contextPolicy['sections'];
        $routing=$effective['routing'];
        $selectedPrompt=(string)($routing['prompt_configuration_id']??'');$prompt=false;
        if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$selectedPrompt)===1){
            $promptStmt=$this->db->prepare("SELECT c.configuration_id,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.configuration_id=:prompt AND c.installation_id=:installation AND c.kind='prompt' AND c.deleted_at IS NULL AND COALESCE(r.content->>'purpose','')<>'narrator_event'");
            $promptStmt->execute(['prompt'=>$selectedPrompt,'installation'=>$turn['installation_id']]);$prompt=$promptStmt->fetch();
        }
        if(!$prompt){$promptStmt = $this->db->prepare("SELECT c.configuration_id,c.current_revision AS revision,r.content FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.installation_id=:installation AND c.kind='prompt' AND c.deleted_at IS NULL AND COALESCE(r.content->>'purpose','')<>'narrator_event' AND (c.profile_id=:actor_profile OR c.profile_id=:session_profile OR c.profile_id IS NULL) ORDER BY CASE WHEN c.profile_id=:actor_profile THEN 0 WHEN c.profile_id=:session_profile THEN 1 ELSE 2 END,c.name,c.configuration_id LIMIT 1");
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
        $knowledgeSelection=$contextSections['oghma']?$this->selectPromptKnowledge($turn,$profile,$knowledgeScope,
            DynamicOghmaRepository::overlay($this->knowledgeCandidates($knowledgeScope,array_keys($this->contentFilesForTurn($turn))),$turn['_dynamic_oghma_plan']??[]),
            (string)($effective['settings']['memory']['oghma_knowledge_tags']??''),
            (int)($effective['settings']['oghma']['result_limit']??3),(array)($effective['settings']['oghma']??[]),$oghmaExtraction,$now)
            :['rows'=>[],'trace'=>['status'=>'disabled','reason'=>'disabled_by_global_context','result_ids'=>[]]];
        $knowledgeSelection['trace']['settings']=$effective['settings']['oghma']??[];
        $knowledgeSelection['trace']['settings_sources']=array_filter($effective['sources'],static fn(string$key):bool=>
            str_starts_with($key,'settings.oghma.')||$key==='settings.memory.oghma_knowledge_tags'||$key==='routing.oghma_configuration_id',ARRAY_FILTER_USE_KEY);
        $knowledge=$knowledgeSelection['rows'];
        $narratives=$contextSections['narratives']?$this->narratives($scope):[];
        if($contextSections['narratives']&&$activeProfileId!==$scope['profile_id']){
            $narrativeScope=$scope;$narrativeScope['profile_id']=$activeProfileId;
            foreach($this->narratives($narrativeScope)as$row)$narratives[$row['narrative_id']]=$row;
            $narratives=array_values($narratives);usort($narratives,static fn(array$a,array$b):int=>
                strcmp((string)$b['created_at'],(string)$a['created_at'])?:strcmp((string)$a['narrative_id'],(string)$b['narrative_id']));
            $narratives=array_slice($narratives,0,100);
        }
        // Narrator diary access spans NPC authors only within this installation and playthrough.
        if($contextSections['narratives']&&($turn['payload']['target']['kind']??null)==='narrator'&&$selectedProfileId!==null){
            $narratives=array_values(array_filter($narratives,static fn(array$row):bool=>$row['kind']!=='diary'));
            $diaryStatement=$this->db->prepare("SELECT n.* FROM narrative_records n JOIN profiles p ON p.profile_id=n.profile_id
                AND p.installation_id=n.installation_id WHERE n.installation_id=:installation AND n.playthrough_id=:playthrough
                AND n.kind='diary' AND n.deleted_at IS NULL AND p.deleted_at IS NULL
                AND (n.profile_id=:narrator OR (NOT CAST(:only_own AS boolean) AND COALESCE(p.actor_identity->>'kind','actor') IN ('actor','npc','creature')))
                ORDER BY n.created_at DESC,n.narrative_id LIMIT 100");
            $diaryStatement->execute(['installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
                'narrator'=>$selectedProfileId,'only_own'=>($profile['content']['only_diary_access']??false)===true?'true':'false']);
            foreach($diaryStatement->fetchAll()as$row){$row['provenance']=$this->json($row['provenance']);$narratives[]=$row;}
            usort($narratives,static fn(array$a,array$b):int=>strcmp((string)$b['created_at'],(string)$a['created_at'])?:strcmp($a['narrative_id'],$b['narrative_id']));
            $narratives=array_slice($narratives,0,100);
        }
        if(($effective['settings']['diary']['include_in_context']??true)!==true)
            $narratives=array_values(array_filter($narratives,static fn(array$row):bool=>($row['kind']??null)!=='diary'));
        $recent=[];
        if($contextSections['recent_action_results']){$actions=$this->db->prepare('SELECT r.action_id,r.status,r.reason_code,r.observed,r.completed_at FROM action_results r JOIN action_intents a ON a.action_id=r.action_id WHERE a.session_id=:session ORDER BY r.completed_at DESC,r.action_id LIMIT 16');
            $actions->execute(['session'=>$turn['session_id']]);$recent=array_map(function($r){$r['observed']=$this->json($r['observed']);return$r;},$actions->fetchAll());}
        $actor=(array)$turn['payload']['target'];
        $actorKey=[];
        foreach(['kind','record_id','content_file']as$field){if(is_string($actor[$field]??null)&&$actor[$field]!=='')$actorKey[$field]=$actor[$field];}
        if(is_array($actor['refnum']??null)&&!array_is_list($actor['refnum'])){
            $refnum=[];foreach(['index','content_file']as$field)if(is_int($actor['refnum'][$field]??null))$refnum[$field]=$actor['refnum'][$field];
            if($refnum!==[])$actorKey['refnum']=$refnum;
        }
        if(!isset($actorKey['record_id'],$actorKey['content_file']))throw new RuntimeException('invalid_actor_identity');
        $actorJson=$this->encode($actorKey);$audienceJson=$this->encode([$actorKey]);
        $ownsProfile=$selectedProfileId!==null || $this->actorKey($this->json($profile['actor_identity']))===$this->actorKey($actor);
        // Relationship records describe their owning NPC, never a shared session or witness pool.
        $latestDiary=[];
        if($ownsProfile&&($effective['settings']['diary']['latest_entry_in_context']??false)===true){
            // Select the author's newest entry independently of the mixed narrative list and its limit.
            $latestDiaryStatement=$this->db->prepare("SELECT * FROM narrative_records WHERE installation_id=:installation AND profile_id=:profile AND playthrough_id=:playthrough AND kind='diary' AND deleted_at IS NULL ORDER BY created_at DESC,narrative_id DESC LIMIT 1");
            $latestDiaryStatement->execute(['installation'=>$turn['installation_id'],'profile'=>$activeProfileId,'playthrough'=>$turn['playthrough_id']]);
            $latestDiaryRow=$latestDiaryStatement->fetch();
            if($latestDiaryRow&&trim((string)$latestDiaryRow['content'])!==''){
                $latestDiaryRow['provenance']=$this->json($latestDiaryRow['provenance']);$latestDiary=[$latestDiaryRow];
                // Keep one copy of this entry when general narrative recall is also enabled.
                $narratives=array_values(array_filter($narratives,static fn(array$row):bool=>$row['narrative_id']!==$latestDiaryRow['narrative_id']));
            }
        }
        $relationshipScope=$scope;$relationshipScope['profile_id']=$activeProfileId;
        $relationships=$ownsProfile&&$contextSections['relationships']?$this->relationships($relationshipScope):[];
        // Native NPC-to-player disposition overrides legacy AI scores; unknown stays unknown.
        if(($turn['payload']['target']['kind']??'')==='npc'){
            $gameDisposition=new GameDispositionRepository($this->db);
            foreach($relationships as &$relationship){
                if(($relationship['actor_identity']['kind']??'')!=='player')continue;
                $snapshot=$gameDisposition->snapshot($turn,$turn['payload']['target'],$relationship['actor_identity']);
                $current=$snapshot!==null&&$snapshot['session_id']===$turn['session_id']&&(int)$snapshot['generation']===(int)$turn['generation'];
                $relationship['disposition']=$current?$snapshot['disposition']:null;
                $relationship['details']['disposition_authority']='Morrowind game value, 0 to 100; null means unknown. Affinity is separate long-term trust.';
            }unset($relationship);
        }
        usort($relationships,fn($a,$b)=>strcmp((string)$a['relationship_id'],(string)$b['relationship_id']));
        $memorySelection=$contextSections['memories']?$this->selectPromptMemories($turn,$scope,
            $this->promptMemoryCandidates($turn,$actorKey,$activeProfileId,$ownsProfile,$now,$semanticMemory),$now,$semanticMemory)
            :['rows'=>[],'candidates'=>[],'trace'=>['status'=>'disabled','reason'=>'disabled_by_global_context','result_ids'=>[]]];
        $memories=$memorySelection['rows'];
        $memoryDigest=[];
        if($ownsProfile&&$contextSections['memories']&&($effective['settings']['memory']['mid_term_enabled']??true)===true
            &&($turn['payload']['target']['kind']??'')!=='narrator'){
            try{$digest=(new NpcMemoryDigestRepository($this->db))->latest($turn['installation_id'],$turn['playthrough_id'],$activeProfileId);}
            catch(RuntimeException $error){if(!in_array($error->getMessage(),['digest_history_limit','digest_scan_timeout'],true))throw $error;$digest=null;}
            if($digest!==null)$memoryDigest=[$digest+['installation_id'=>$turn['installation_id'],'playthrough_id'=>$turn['playthrough_id'],'profile_id'=>$activeProfileId]];
        }

        $recentTurnLimit=(int)($effective['settings']['memory']['recent_turn_limit']??20);
        $history=[];
        if($contextSections['conversation_history']&&$contextPolicy['event_types']!==[]){$typeParameters=[];$historyParameters=[];
        // Successful casts share the existing action-event switch; older saved settings need no new category.
        $historyEventTypes=$contextPolicy['event_types'];
        $resurrectionSql=in_array('infoaction',$historyEventTypes,true)
            ? " OR (e.type='info' AND m.projection_key LIKE 'resurrection:%')" : '';
        if(in_array('infoaction',$historyEventTypes,true))$historyEventTypes[]='itemfound';
        if(($contextPolicy['detect_magic_events']??true)&&in_array('infoaction',$historyEventTypes,true))$historyEventTypes=array_merge($historyEventTypes,['spellcast','npcspellcast']);
        foreach($historyEventTypes as$index=>$eventType){$name='event_type_'.$index;$typeParameters[]=':'.$name;$historyParameters[$name]=$eventType;}
        $isNarratorTarget=($turn['payload']['target']['kind']??'')==='narrator';
        $hideNarratorDialogue=!$isNarratorTarget
            &&($this->narratorProfileForInstallation((string)$turn['installation_id'])['content']['hide_from_context']??true)===true;
        $eventTypeSql=implode(',',$typeParameters);$historyStatement=$this->db->prepare(<<<SQL
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
                   WHEN e.type='info' AND m.projection_key LIKE 'resurrection:%' THEN m.payload
                   WHEN e.type='location' THEN jsonb_strip_nulls(jsonb_build_object('location',e.location,'game_time',NULLIF(e.gamets,0)))
                   WHEN e.type='weather' THEN jsonb_strip_nulls(jsonb_build_object('weather',COALESCE(m.payload->>'weather',replace(e.data,'Weather changed to ',''))))
                    WHEN e.type IN ('quest','book','death','infoaction','narration','chat_background','spellcast','npcspellcast','itemfound') THEN m.payload
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
  AND (e.type IN ($eventTypeSql)$resurrectionSql)
  AND (e.type<>'itemfound' OR (lower(btrim(COALESCE(m.payload->>'item_record_id','')))<>ALL(CAST(:item_blacklist AS text[]))
       AND lower(btrim(COALESCE(m.payload->>'item_name','')))<>ALL(CAST(:item_blacklist AS text[]))))
  AND (e.type NOT IN ('spellcast','npcspellcast') OR (lower(btrim(COALESCE(m.payload->>'spell_id','')))<>ALL(CAST(:magic_blacklist AS text[]))
       AND lower(btrim(COALESCE(m.payload->>'spell_name','')))<>ALL(CAST(:magic_blacklist AS text[]))))
  AND (e.type<>'itemfound' OR CASE WHEN jsonb_typeof(m.payload->'count')='number' AND jsonb_typeof(m.payload->'unit_value')='number'
       THEN (m.payload->>'count')::numeric*(m.payload->>'unit_value')::numeric>=CAST(:pickup_min_value AS numeric) ELSE false END)
  AND ((e.type NOT IN ('spellcast','npcspellcast','itemfound') AND COALESCE(m.projection_key,'') NOT LIKE 'resurrection:%') OR (
      NOT EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=m.source_event_id)
      AND (EXISTS(SELECT 1 FROM source_events observation WHERE observation.source_event_id=m.source_event_id AND observation.session_id=:context_session AND observation.generation=COALESCE(CAST(:context_generation AS bigint),(SELECT generation FROM sessions WHERE session_id=:context_session)))
          OR (jsonb_typeof(m.payload->'calendar')='object' AND EXISTS(SELECT 1 FROM source_events load WHERE load.session_id=:context_session AND load.event_kind='session.init' AND jsonb_typeof(load.payload->'loaded_save')='object')))))
  AND (CAST(:is_narrator_target AS boolean) OR e.type<>'inputtext' OR COALESCE(m.target->>'kind','')<>'narrator')
  AND (NOT CAST(:hide_narrator_dialogue AS boolean) OR e.type<>'chat' OR COALESCE(m.speaker->>'kind','')<>'narrator')
  AND (e.type<>'chat' OR e.delivery_state IN ('emitted','pending','spoken','played'))
  AND (m.speaker @> CAST(:event_speaker AS jsonb)
       OR m.target @> CAST(:event_target AS jsonb)
       OR m.audience @> CAST(:event_audience AS jsonb))
ORDER BY sort_ts DESC,sort_created_at DESC,source_rank DESC,sort_id DESC
LIMIT :candidate_limit
SQL);
        $historyStatement->execute($historyParameters+[
            'installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id'],
            'current_turn'=>$turn['turn_id']??null,
            'context_session'=>$turn['session_id'],'context_generation'=>$turn['generation']??null,
            'pickup_min_value'=>$contextPolicy['item_pickup_min_value']??500,
            'item_blacklist'=>$this->pgArray(array_map(static fn(string $value):string=>mb_strtolower(trim($value),'UTF-8'),$contextPolicy['item_blacklist'])),
            'magic_blacklist'=>$this->pgArray(array_map(static fn(string $value):string=>mb_strtolower(trim($value),'UTF-8'),$contextPolicy['magic_effects_blacklist'])),
            'event_speaker'=>$actorJson,'event_target'=>$actorJson,'event_audience'=>$audienceJson,
            'hide_narrator_dialogue'=>$hideNarratorDialogue?'true':'false',
            'is_narrator_target'=>$isNarratorTarget?'true':'false',
            'candidate_limit'=>min(500,max(40,$recentTurnLimit*5)),
        ]);
        // Count conversation turns, not individual input, response, and world-event rows.
        $historyTurns=[];$locationBlacklist=[];foreach($contextPolicy['location_blacklist']as$location)$locationBlacklist[mb_strtolower(trim((string)$location),'UTF-8')]=true;
        $magicBlacklist=[];foreach($contextPolicy['magic_effects_blacklist']as$spell)$magicBlacklist[mb_strtolower(trim((string)$spell),'UTF-8')]=true;
        $itemBlacklist=[];foreach($contextPolicy['item_blacklist']as$item)$itemBlacklist[mb_strtolower(trim((string)$item),'UTF-8')]=true;
        foreach($historyStatement->fetchAll()as$row){
            $content=$this->json($row['content']);$location=mb_strtolower(trim((string)($content['location']??$content['details']['location']??'')),'UTF-8');
            if($location!==''&&isset($locationBlacklist[$location]))continue;
            if(in_array($content['type']??null,['spellcast','npcspellcast'],true)
                &&(isset($magicBlacklist[mb_strtolower(trim((string)($content['details']['spell_id']??'')),'UTF-8')])
                    ||isset($magicBlacklist[mb_strtolower(trim((string)($content['details']['spell_name']??'')),'UTF-8')])))continue;
            if(($content['type']??null)==='itemfound'){
                $pickup=$content['details']??[];
                if(!is_int($pickup['count']??null)||!is_int($pickup['unit_value']??null)
                    ||$pickup['count']*$pickup['unit_value']<($contextPolicy['item_pickup_min_value']??500)
                    ||isset($itemBlacklist[mb_strtolower(trim((string)($pickup['item_record_id']??'')),'UTF-8')])
                    ||isset($itemBlacklist[mb_strtolower(trim((string)($pickup['item_name']??'')),'UTF-8')]))continue;
            }
            $turnKey=(string)($row['turn_id']??$row['id']);
            if(!isset($historyTurns[$turnKey])&&count($historyTurns)>=$recentTurnLimit)continue;
            $historyTurns[$turnKey]=true;
            $history[]=['id'=>(string)$row['id'],'installation_id'=>$turn['installation_id'],
                'playthrough_id'=>$turn['playthrough_id'],'created_at'=>(string)$row['sort_created_at'],
                'content'=>$content];
        }
        $history=array_reverse($history);
        }
        $speechConnector=$this->connectorForActor((string)$turn['installation_id'],(string)$turn['playthrough_id'],
            (array)$turn['payload']['target'],'tts_provider')??$this->connectorForInstallation((string)$turn['installation_id'],'tts_provider');
        // Include only public style options and provenance, never endpoint or credential references.
        $speechStyle=$speechConnector===null?[]:['installation_id'=>$turn['installation_id'],
            'configuration_id'=>$speechConnector['configuration_id'],'revision'=>$speechConnector['revision'],
            'driver'=>$speechConnector['content']['driver']??'',
            'options'=>array_intersect_key((array)($speechConnector['content']['options']??[]),array_flip([
                'paralinguistic_tags_enabled','paralinguistic_tags_prompt','paralinguistic_tags_list','dynamic_tones']))];
        $narratorProfile=$this->narratorProfileForInstallation($turn['installation_id']);
        $promptKeys=[];
        $eventKey=\LorkhanServer\Application\NarratorEventPrompts::SOURCES[$turn['payload']['ui_source']??'']??null;
        if($eventKey!==null)$promptKeys[]=$eventKey;
        $inlineMode=($narratorProfile['content']['enabled']??false)===true?($narratorProfile['content']['inline_narration_mode']??'Disabled'):'Disabled';
        if(($turn['payload']['target']['kind']??'')!=='narrator'&&in_array($inlineMode,['Narrator','NPC','Text Only'],true)){
            $suffix=$inlineMode==='Narrator'?'narrator':'npc';
            $promptKeys[]='dialogue_line_inline_response_'.$suffix;$promptKeys[]='inline_narration_prompt_'.$suffix;
        }
        return ['profile'=>$profile,'core_profile'=>$coreProfile,'selected_profile_id'=>$activeProfileId,'speech_style'=>$speechStyle,
            'effective_settings'=>['sha256'=>$effective['sha256'],'sources'=>$effective['sources'],'context'=>$contextPolicy,'prompt'=>$effective['prompt'],
                'settings'=>array_intersect_key($effective['settings'], ['memory'=>true,'response'=>true])],
            'scene_classification'=>$contextSections['world']?(new SceneClassificationRepository($this->db))->context($turn['installation_id'],$turn['playthrough_id'],$activeProfileId):null,
            'player_profile'=>$this->playerProfileForInstallation($turn['installation_id'],$turn['playthrough_id']),
            'narrator_profile'=>$narratorProfile,
            'narrator_event_prompts'=>$promptKeys===[]?[]:$this->narratorEventPromptTexts($turn['installation_id'],$promptKeys),
            'nearby_actor_profiles'=>$contextSections['nearby_actors']?$this->nearbyActorProfilesForTurn($turn):[],
            'power_observations'=>($contextPolicy['power_awareness_enabled']??false)&&$contextSections['nearby_actors']&&($contextPolicy['details']['nearby_actor_power']??true)
                ?$this->powerObservationsForTurn($turn):[],
            'item_descriptions'=>($contextSections['record_descriptions']||($contextPolicy['ground_items_descriptions_only']??false)||($contextPolicy['inventory_items_descriptions_only']??false))?$this->itemDescriptionsForTurn($turn):[],
            'prompt'=>$prompt,'history'=>$history,'memory'=>array_slice($memories,0,10),
            'memory_candidates'=>$memorySelection['candidates'],'memory_retrieval'=>$memorySelection['trace'],'memory_digest'=>$memoryDigest,
            'relationship'=>array_slice($relationships,0,10),'knowledge'=>$knowledge,'knowledge_retrieval'=>$knowledgeSelection['trace'],
            'latest_diary'=>$latestDiary,'narrative'=>array_slice($narratives,0,10),'recent_action_results'=>$recent];
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
                foreach(array_slice($ranked,0,10)as$row){$id=(string)$row['id'];$score=(float)$row['_prompt_score'];$scores[$id]=max((float)($scores[$id]??0),$score);$accessDecision=OghmaGroundedRetriever::accessDecision($row+['topic_desc'=>$row['content']],$knowledgeTags);$access=$accessDecision['level'];$rank++;
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
        if($query===''&&($turn['payload']['ui_source']??null)==='lorkhan_action_followup')
            $query='action result follow-up';
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

    /** Apply every matching rule in reference priority order; the last profile assignment wins. */
    private function matchingProfileRulesForTurn(array $turn,array $target):array
    {
        $context=is_array($turn['payload']['context']??null)&&!array_is_list($turn['payload']['context'])
            ?$turn['payload']['context']:[];
        $actor=$this->profileRuleActorValues($target,$context);$normalized=[];
        foreach(self::PROFILE_RULE_MATCH_FIELDS as$field)$normalized[$field]=array_map(
            static fn(string$value):string=>mb_strtolower($value,'UTF-8'),$actor[$field]);
        $rules=$this->db->prepare('SELECT r.rule_id,r.core_profile_id,r.matchers FROM profile_assignment_rules r LEFT JOIN core_profiles c '
            .'ON c.core_profile_id=r.core_profile_id AND c.installation_id=r.installation_id AND c.deleted_at IS NULL '
            .'WHERE r.installation_id=:installation AND r.enabled=true AND (r.core_profile_id IS NULL OR c.core_profile_id IS NOT NULL) ORDER BY r.priority ASC,r.created_at ASC,r.rule_id ASC LIMIT 100');
        $rules->execute(['installation'=>$turn['installation_id']]);
        $rows=$rules->fetchAll();
        foreach($rows as&$row)$row['matchers']=$this->json($row['matchers']);unset($row);
        $actor['record_ids']=[(string)($target['record_id']??'')];
        foreach(['names','races','genders','classes']as$field)if($actor[$field]===[])$actor[$field]=[''];
        $eligible=$this->profileRuleRegexRows($rows,$actor);
        $result=['core_profile_id'=>null,'actions'=>[]];
        foreach($rows as$rule){if(!isset($eligible[$rule['rule_id']]))continue;try{$match=$this->normalizeProfileRuleMatch($rule['matchers'],true);}
            catch(InvalidArgumentException){continue;}$matches=true;
            foreach(self::PROFILE_RULE_MATCH_FIELDS as$field){if($match[$field]===[])continue;
                $wanted=array_map(static fn(string$value):string=>mb_strtolower($value,'UTF-8'),$match[$field]);
                if(($field==='content_files'&&isset($match['_advanced']))?array_diff($wanted,$normalized[$field])!==[]:array_intersect($wanted,$normalized[$field])===[]){$matches=false;break;}}
            if($matches){if($rule['core_profile_id']!==null)$result['core_profile_id']=(string)$rule['core_profile_id'];
                if(!empty($match['_advanced']['action']))$result['actions'][]=$match['_advanced']['action'];}
        }
        return $result;
    }

    /** Use Herika's PostgreSQL regex engine with a bounded query and an isolated failure savepoint. */
    private function profileRuleRegexRows(array $rows,array $actor,bool $validation=false):array
    {
        $input=[];$simple=[];
        foreach($rows as$row){$regex=$row['matchers']['_advanced']['regex']??[];
            $input[]=['rule_id'=>$row['rule_id'],'regex'=>(object)$regex];
            if($regex===[])$simple[(string)$row['rule_id']]=true;}
        if(count($simple)===count($rows))return$simple;
        $own=!$this->db->inTransaction();if($own)$this->db->beginTransaction();
        $this->db->exec('SAVEPOINT profile_rule_regex');
        try{
            $timeout=(string)$this->db->query("SELECT current_setting('statement_timeout')")->fetchColumn();
            $this->db->exec("SET LOCAL statement_timeout='250ms'");
            $sql=$validation
                ? "SELECT count(*) FILTER (WHERE '' ~ pattern.value) FROM jsonb_array_elements(CAST(:rules AS jsonb)) r CROSS JOIN LATERAL jsonb_each_text(r.value->'regex') pattern"
                : "SELECT r.value->>'rule_id' FROM jsonb_array_elements(CAST(:rules AS jsonb)) r WHERE NOT EXISTS (SELECT 1 FROM jsonb_each_text(r.value->'regex') pattern WHERE NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(COALESCE(CAST(:actor AS jsonb)->pattern.key,'[\"\"]'::jsonb)) observed WHERE observed.value ~ pattern.value))";
            $query=$this->db->prepare($sql);$parameters=['rules'=>$this->encode($input)];
            if(!$validation)$parameters['actor']=json_encode((object)$actor,JSON_THROW_ON_ERROR);
            $query->execute($parameters);$matched=array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN),true);
            $this->db->prepare("SELECT set_config('statement_timeout',:timeout,true)")->execute(['timeout'=>$timeout]);
            $this->db->exec('RELEASE SAVEPOINT profile_rule_regex');if($own)$this->db->commit();
            return$validation?$simple:$matched;
        }catch(\PDOException $error){
            $this->db->exec('ROLLBACK TO SAVEPOINT profile_rule_regex');$this->db->exec('RELEASE SAVEPOINT profile_rule_regex');
            if($own)$this->db->rollBack();
            if($validation)throw new InvalidArgumentException('invalid_or_expensive_rule_regex',0,$error);
            // Invalid/expensive administrator regex must not block an otherwise valid NPC conversation.
            error_log('Lorkhan profile assignment: advanced regex query rejected; exact rules retained.');
            return$simple;
        }
    }

    /** Extract only the bounded actor fields that assignment rules are allowed to inspect. */
    private function profileRuleActorValues(array $target,array $context):array
    {
        $values=array_fill_keys(self::PROFILE_RULE_MATCH_FIELDS,[]);
        $this->addProfileRuleOption($values['names'],$target['display_name']??null);
        $state=is_array($context['targetState']??null)&&!array_is_list($context['targetState'])?$context['targetState']:[];
        // RefNum/content_file describes the placed reference, not the NPC record's override chain.
        $provenance=$state['recordProvenance']??null;
        if(is_array($provenance)&&in_array($provenance['state']??null,['complete','truncated'],true)
            &&is_string($provenance['record_id']??null)&&is_string($target['record_id']??null)
            &&strcasecmp($provenance['record_id'],$target['record_id'])===0
            &&is_array($provenance['files']??null)&&array_is_list($provenance['files'])&&count($provenance['files'])<=128){
            // Truncation may omit contributors, but each retained contributor is positive evidence.
            foreach([...$provenance['files'],$provenance['winning_file']??null]as$file)
                if(is_string($file)&&!str_contains($file,'/')&&!str_contains($file,'\\'))
                    $this->addProfileRuleOption($values['content_files'],$file);
        }
        $identity=is_array($state['identity']??null)&&!array_is_list($state['identity'])?$state['identity']:[];
        $this->addProfileRuleOption($values['races'],$identity['race']??$state['race']??null);
        $this->addProfileRuleOption($values['classes'],$identity['class']??$state['class']??null);
        $this->addProfileRuleOption($values['genders'],$identity['gender']??$state['gender']??null);
        $factions=$state['factions']??[];if(is_array($factions))foreach(array_slice($factions,0,64)as$faction){
            if(is_string($faction))$this->addProfileRuleOption($values['factions'],$faction);
            elseif(is_array($faction)&&!array_is_list($faction)&&(!isset($faction['rank'])||(int)$faction['rank']>=0))
                $this->addProfileRuleOption($values['factions'],$faction['id']??null);
        }
        foreach($values as&$field)$field=array_values($field);unset($field);return$values;
    }

    /** Validate the stable rule document and normalize case-insensitive duplicates. */
    private function normalizeProfileRuleMatch(mixed $value,bool $requirePopulated=false):array
    {
        if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('invalid_rule_match');
        $advanced=null;if(array_key_exists('_advanced',$value)){$advanced=ProfileAssignmentRule::normalize($value['_advanced']);unset($value['_advanced']);}
        $keys=array_keys($value);sort($keys,SORT_STRING);$expected=self::PROFILE_RULE_MATCH_FIELDS;sort($expected,SORT_STRING);
        if($keys!==$expected)throw new InvalidArgumentException('invalid_rule_match');
        $result=[];$total=0;
        foreach(self::PROFILE_RULE_MATCH_FIELDS as$field){$items=$value[$field];
            if(!is_array($items)||!array_is_list($items)||count($items)>32)throw new InvalidArgumentException('invalid_rule_match');
            $clean=[];foreach($items as$item){if(!is_string($item))throw new InvalidArgumentException('invalid_rule_match');
                $item=trim($item);if($item===''||strlen($item)>256||preg_match('/[\x00-\x1F\x7F]/',$item)===1)
                    throw new InvalidArgumentException('invalid_rule_match');
                $key=mb_strtolower($item,'UTF-8');if(!isset($clean[$key]))$clean[$key]=$item;}
            $result[$field]=array_values($clean);$total+=count($result[$field]);}
        if($requirePopulated&&$total===0&&$advanced===null)throw new InvalidArgumentException('profile_assignment_rule_match_required');
        if($advanced!==null)$result['_advanced']=$advanced;
        return$result;
    }

    /** Add one safe display option keyed by its case-insensitive exact value. */
    private function addProfileRuleOption(array &$options,mixed $value):void
    {
        if(!is_string($value))return;$value=trim($value);
        if($value===''||strlen($value)>256||preg_match('/[\x00-\x1F\x7F]/',$value)===1)return;
        $key=mb_strtolower($value,'UTF-8');if(!isset($options[$key]))$options[$key]=$value;
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
    private function selectPromptMemories(array $turn,array $scope,array $memories,string $now,array $semantic=[]):array
    {
        $query=\LorkhanServer\Application\MemoryEmbeddingPolicy::queryText($turn);
        $queryEmbedding=is_array($semantic['embedding']??null)&&array_is_list($semantic['embedding'])
            ?$semantic['embedding']:null;
        foreach($memories as&$memory){
            $score=DeterministicRetrieval::promptScore($query,$memory['lexical_terms'],$memory['fake_vector'],
                $queryEmbedding,is_array($memory['_semantic_embedding']??null)?$memory['_semantic_embedding']:null);
            $tierBoost=match($memory['tier']??null){'recent'=>0.15,'mid'=>0.08,'long'=>0.03,default=>0.0};
            $memory['_prompt_score']=$score['score']+$tierBoost;$memory['_retrieval_score']=$score;
        }
        unset($memory);
        usort($memories,static fn(array$a,array$b):int=>($b['_prompt_score']<=>$a['_prompt_score'])
            ?:strcmp((string)($b['occurred_at']??''),(string)($a['occurred_at']??''))
            ?:strcmp((string)$a['id'],(string)$b['id']));
        $selected=array_slice($memories,0,10);
        $scores=[];$reasons=[];
        foreach($selected as$rank=>&$memory){
            $scores[$memory['id']]=$memory['_prompt_score'];
            $signal=$memory['_retrieval_score'];$reasons[$memory['id']]=['rank'=>$rank+1,'tier'=>$memory['tier'],
                'lexical_score'=>$signal['lexical_score'],'semantic_score'=>$signal['semantic_score'],
                'semantic_source'=>$signal['source'],'reason'=>'bounded relevance plus tier recency'];
            unset($memory['_prompt_score'],$memory['_retrieval_score'],$memory['_semantic_embedding'],$memory['_semantic_model']);
        }
        unset($memory);
        foreach($memories as&$memory)unset($memory['_retrieval_score'],$memory['_semantic_embedding'],$memory['_semantic_model']);
        unset($memory);
        if(($semantic['status']??'unconfigured')!=='unconfigured')$reasons['_semantic']=[
            'status'=>(string)$semantic['status'],'policy_configuration_id'=>$semantic['policy_configuration_id']??null,
            'policy_revision'=>$semantic['policy_revision']??null,'model'=>$semantic['model']??null];
        return['rows'=>$selected,'candidates'=>$memories,'trace'=>['domain'=>'memory','query'=>$query,'result_ids'=>array_keys($scores),
            'scores'=>$scores,'reasons'=>$reasons,'algorithm'=>$queryEmbedding===null
                ?'prompt-memory-lexical-0.75+fake-vector-0.25+tier-v1'
                :'prompt-memory-lexical-0.75+minime-0.25+deterministic-fallback+tier-v1',
            'created_at'=>$now,'prompt_section'=>'memory_context','scope'=>$scope]];
    }

    /** Fetch only known levels for exact actors in this playthrough; never infer them from profile prose. */
    public function powerObservationsForTurn(array $turn): array
    {
        $context=$turn['payload']['context']??[];$nearby=$context['nearbyActors']??[];
        if(is_array($nearby)&&!array_is_list($nearby))$nearby=$nearby['items']??[];
        $target=$turn['payload']['target']??[];$identities=[];
        foreach(array_merge([$target],array_slice(is_array($nearby)?$nearby:[],0,12)) as $identity){
            if(!is_array($identity)||!in_array($identity['kind']??'', ['npc','creature'],true)
                ||!is_string($identity['record_id']??null)||!is_string($identity['content_file']??null))continue;
            $identities[$this->actorKey($identity)]=$identity;
        }
        if($identities===[])return[];
        $statement=$this->db->prepare(<<<SQL
SELECT c.identity::text AS identity,o.level::text AS level
FROM jsonb_array_elements(CAST(:identities AS jsonb)) AS c(identity)
JOIN LATERAL (
 SELECT t.context#>'{targetState,stats,level}' AS level
 FROM active_turns t JOIN sessions s ON s.session_id=t.session_id
 WHERE s.installation_id=:installation AND s.playthrough_id=:playthrough
   AND t.target->>'kind'=c.identity->>'kind' AND t.target->>'record_id'=c.identity->>'record_id'
   AND t.target->>'content_file'=c.identity->>'content_file'
   AND t.target->'refnum' IS NOT DISTINCT FROM c.identity->'refnum'
   AND NOT jsonb_exists(t.context,'rechat')
   AND jsonb_typeof(t.context#>'{targetState,stats,level}')='number'
 ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT 1
) o ON true
SQL);
        $statement->execute(['identities'=>json_encode(array_values($identities),JSON_THROW_ON_ERROR),
            'installation'=>$turn['installation_id'],'playthrough'=>$turn['playthrough_id']]);
        $levels=[];
        foreach($statement->fetchAll() as $row){
            $identity=$this->json($row['identity']);$level=json_decode($row['level'],true,8,JSON_THROW_ON_ERROR);
            if(\LorkhanServer\Application\PowerAwareness::describe($level,$level)!=='')
                $levels[$this->actorKey($identity)]=['actor_identity'=>$identity,'level'=>$level];
        }
        // Rechat may reroute the responder after capture, so its targetState cannot identify the new assessor.
        $current=$context['targetState']['stats']['level']??null;
        if(!isset($context['rechat'])&&($turn['payload']['ui_source']??'')!=='lorkhan_rechat'
            &&isset($identities[$this->actorKey($target)])&&\LorkhanServer\Application\PowerAwareness::describe($current,$current)!=='')
            $levels[$this->actorKey($target)]=['actor_identity'=>$target,'level'=>$current];
        return array_values($levels);
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
            .'FROM actor_profile_bindings b JOIN profiles p ON p.profile_id=b.profile_id AND p.deleted_at IS NULL AND '.ProfileScopeSql::matches('p','b.playthrough_id').' '
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
    public function playerProfileForInstallation(string $installationId,?string $playthroughId=null): ?array
    {
        $playthroughId??=(new ProfileOwnershipRepository($this->db))->activePlaythrough($installationId);
        $statement=$this->db->prepare("SELECT p.profile_id,p.name,p.actor_identity,p.current_revision AS revision,r.content "
            ."FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision "
            ."WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='player' "
            ."AND ".ProfileScopeSql::matches('p',':playthrough')." ORDER BY p.created_at,p.profile_id LIMIT 1");
        $statement->execute(['installation'=>$installationId,'playthrough'=>$playthroughId]);$row=$statement->fetch();
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

    /** Atomically create or revise installation-scoped biography templates by stable OpenMW identity. */
    public function saveBiographyTemplates(string $installationId,array $inputs,string $now):array
    {
        return$this->transaction(function()use($installationId,$inputs,$now):array{
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')
                ->execute(['key'=>'biography-import:'.$installationId]);
            $defaultCore=(string)($this->defaultCoreProfileForInstallation($installationId,$now,true)['core_profile_id']
                ??throw new RuntimeException('default_core_profile_required'));
            $find=$this->db->prepare("SELECT p.profile_id,p.current_revision,p.name,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='template' AND lower(COALESCE(p.actor_identity->>'content_file',''))=lower(:content_file) AND lower(COALESCE(p.actor_identity->>'record_id',''))=lower(:record_id) ORDER BY p.created_at,p.profile_id FOR UPDATE OF p");
            $nameConflict=$this->db->prepare('SELECT profile_id FROM profiles WHERE installation_id=:installation AND name=:name AND deleted_at IS NULL AND playthrough_id IS NULL FOR UPDATE');
            $saved=[];$portable=['core','biography','appearance','personality','relationships','occupation','skills','speech_style','goals','oghma_tags','oghma_knowledge_tags','gender','race','voice'];
            foreach($inputs as$input){
                $find->execute(['installation'=>$installationId,'content_file'=>$input['content_file'],'record_id'=>$input['record_id']]);
                $matches=$find->fetchAll();if(count($matches)>1)throw new RuntimeException('ambiguous_biography_template');
                $existing=$matches[0]??null;$nameConflict->execute(['installation'=>$installationId,'name'=>$input['name']]);
                $named=$nameConflict->fetchColumn();
                if($named!==false&&($existing===null||(string)$named!==(string)$existing['profile_id']))
                    throw new \InvalidArgumentException('biography_template_name_conflict');
                $identity=['kind'=>'template','display_name'=>$input['name'],'content_file'=>$input['content_file'],'record_id'=>$input['record_id']];
                $content=$existing===null?[]:$this->json($existing['content']);
                foreach($portable as$field)unset($content[$field]);
                $content=array_replace($content,$input['content']);
                if(strlen($this->encode($content))>131_072)throw new \InvalidArgumentException('invalid_profile_content');
                if($existing===null){
                    $id=Uuid::v4();
                    $this->db->prepare('INSERT INTO profiles(profile_id,installation_id,name,actor_identity,core_profile_id,created_at) VALUES(:id,:installation,:name,CAST(:identity AS jsonb),:core_profile,:now)')
                        ->execute(['id'=>$id,'installation'=>$installationId,'name'=>$input['name'],'identity'=>$this->encode($identity),'core_profile'=>$defaultCore,'now'=>$now]);
                    $revision=1;
                }else{
                    $id=(string)$existing['profile_id'];$revision=(int)$existing['current_revision']+1;
                    $this->db->prepare('UPDATE profiles SET name=:name,actor_identity=CAST(:identity AS jsonb) WHERE profile_id=:id')
                        ->execute(['name'=>$input['name'],'identity'=>$this->encode($identity),'id'=>$id]);
                }
                $this->revision('profile_revisions','profile_id',$id,$revision,$content,'biography CSV import',$now);
                if($existing!==null)$this->db->prepare('UPDATE profiles SET current_revision=:revision WHERE profile_id=:id')
                    ->execute(['revision'=>$revision,'id'=>$id]);
                $saved[]=['profile_id'=>$id,'revision'=>$revision,'created'=>$existing===null];
            }
            return$saved;
        });
    }

    /** Export every global override plus this installation's templates, with explicit round-trip ownership. */
    public function customBiographyTemplates(string $installationId):array
    {
        $installation=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:id');
        $installation->execute(['id'=>$installationId]);if(!$installation->fetchColumn())throw new \InvalidArgumentException('invalid_installation_id');
        $statement=$this->db->prepare("SELECT p.actor_identity->>'content_file' AS content_file,p.actor_identity->>'record_id' AS record_id,p.name,r.content FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.installation_id=:installation AND p.deleted_at IS NULL AND p.actor_identity->>'kind'='template' AND btrim(COALESCE(p.actor_identity->>'content_file',''))<>'' AND btrim(COALESCE(p.actor_identity->>'record_id',''))<>'' ORDER BY lower(p.actor_identity->>'content_file'),lower(p.actor_identity->>'record_id'),p.created_at,p.profile_id");
        $statement->execute(['installation'=>$installationId]);$rows=[];
        foreach($statement->fetchAll()as$row){
            $content=$this->json($row['content']);$voice=$content['voice']??[];
            $relationships=$content['relationships']??'{}';if(is_array($relationships))$relationships=$this->encode($relationships);
            $tags=$content['oghma_knowledge_tags']??($content['oghma_tags']??'');if(is_array($tags))$tags=implode(', ',array_map('strval',$tags));
            $rows[]=['scope'=>'installation','content_file'=>(string)$row['content_file'],'record_id'=>(string)$row['record_id'],'name'=>(string)$row['name'],
                'core'=>(string)($content['core']??''),'biography'=>(string)($content['biography']??''),'appearance'=>(string)($content['appearance']??''),
                'personality'=>(string)($content['personality']??''),'relationships'=>(string)$relationships,'occupation'=>(string)($content['occupation']??''),
                'skills'=>(string)($content['skills']??''),'speech_style'=>(string)($content['speech_style']??''),'goals'=>(string)($content['goals']??''),
                'oghma_tags'=>(string)$tags,'voice_id'=>is_array($voice)?(string)($voice['id']??''):(string)$voice,
                'gender'=>(string)($content['gender']??''),'race'=>(string)($content['race']??'')];
        }
        foreach($this->db->query('SELECT npc_name,refid,core,npc_static_bio,appearance,personality,relationships,occupation,skills,speechstyle,goals,oghma_knowledge_tags,voiceid,gender,race FROM public.bio_templates_custom ORDER BY npc_name')->fetchAll()as$row){
            $export=['scope'=>'global','content_file'=>''];
            foreach(['npc_name'=>'name','refid'=>'record_id','core'=>'core','npc_static_bio'=>'biography',
                'appearance'=>'appearance','personality'=>'personality','relationships'=>'relationships','occupation'=>'occupation',
                'skills'=>'skills','speechstyle'=>'speech_style','goals'=>'goals','oghma_knowledge_tags'=>'oghma_tags',
                'voiceid'=>'voice_id','gender'=>'gender','race'=>'race']as$from=>$to)$export[$to]=(string)($row[$from]??'');
            $rows[]=$export;
        }
        return$rows;
    }

    /** Reset reusable overrides only; instantiated NPCs, revisions, factory rows and other installations survive. */
    public function resetBiographyTemplates(string $installationId,string $now):int
    {
        return$this->transaction(function()use($installationId,$now):int{
            $installation=$this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:id');
            $installation->execute(['id'=>$installationId]);if(!$installation->fetchColumn())throw new \InvalidArgumentException('invalid_installation_id');
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>'biography-import:'.$installationId]);
            $global=$this->db->exec('DELETE FROM public.bio_templates_custom');
            $scoped=$this->db->prepare("UPDATE profiles SET deleted_at=:now WHERE installation_id=:installation AND deleted_at IS NULL AND actor_identity->>'kind'='template' AND btrim(COALESCE(actor_identity->>'content_file',''))<>'' AND btrim(COALESCE(actor_identity->>'record_id',''))<>''");
            $scoped->execute(['now'=>$now,'installation'=>$installationId]);
            return$global+$scoped->rowCount();
        });
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
            $append($value['equipment']??[]);$append($value['held_items']??[]);$append($value['inventory']??[]);}}
        $actors=$context['nearbyActors']??[];if(is_array($actors)&&!array_is_list($actors))$actors=$actors['items']??[];
        if(is_array($actors))foreach(array_slice($actors,0,12)as$actor)if(is_array($actor)&&!array_is_list($actor)){
            $append($actor['equipment']??[]);$append($actor['held_items']??[]);$append($actor['inventory']??[]);}
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

    /** Return active sessions and whether their negotiated client supports typed debug commands. */
    public function debugCommandSessions():array
    {
        $rows=$this->db->query("SELECT s.session_id,s.installation_id,s.generation,s.created_at,COALESCE(p.name,s.session_id::text) AS label,"
            ."('debug.commands.v1'=ANY(s.capabilities)) AS supported,"
            ."('debug.commands.v1'=ANY(s.capabilities) AND 'speech.browser.v1'=ANY(s.capabilities)) AS browser_speech_supported "
            ."FROM sessions s LEFT JOIN playthroughs p ON p.playthrough_id=s.playthrough_id "
            ."WHERE s.state='active' ORDER BY s.created_at DESC LIMIT 50")->fetchAll();
        foreach($rows as&$row){$row['generation']=(int)$row['generation'];$row['supported']=filter_var($row['supported'],FILTER_VALIDATE_BOOL);
            $row['browser_speech_supported']=filter_var($row['browser_speech_supported'],FILTER_VALIDATE_BOOL);}unset($row);
        return$rows;
    }

    /** Keep operator actor parameters within the closed common identity contract. */
    private function npcManagerActor(array $identity):array
    {
        $fields=['kind','record_id','content_file','refnum','cell','display_name'];
        $actor=array_intersect_key($identity,array_flip($fields));
        $actor['kind']=($actor['kind']??null)==='actor'?'npc':($actor['kind']??null);
        if(count($actor)!==6||!in_array($actor['kind'],['npc','creature'],true))throw new InvalidArgumentException('invalid_debug_parameters');
        foreach(['record_id','content_file','display_name']as$field){
            if(!is_string($actor[$field])||$actor[$field]===''||strlen($actor[$field])>256
                ||!mb_check_encoding($actor[$field],'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$actor[$field]))
                throw new InvalidArgumentException('invalid_debug_parameters');
        }
        $ref=$actor['refnum'];
        if(!is_array($ref)||count($ref)!==2||!is_int($ref['index']??null)||!is_int($ref['content_file']??null)
            ||$ref['index']<0||$ref['index']>4294967295||$ref['content_file']<0||$ref['content_file']>2147483647)
            throw new InvalidArgumentException('invalid_debug_parameters');
        $cell=$actor['cell'];
        if(!is_array($cell))throw new InvalidArgumentException('invalid_debug_parameters');
        if(($cell['kind']??null)==='interior'){
            if(count($cell)!==2||!is_string($cell['name']??null)||$cell['name']===''||strlen($cell['name'])>256
                ||!mb_check_encoding($cell['name'],'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$cell['name']))
                throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif(($cell['kind']??null)==='exterior'){
            if(count($cell)!==3||!is_int($cell['grid_x']??null)||!is_int($cell['grid_y']??null)
                ||abs($cell['grid_x'])>2147483647||abs($cell['grid_y'])>2147483647)
                throw new InvalidArgumentException('invalid_debug_parameters');
        }else throw new InvalidArgumentException('invalid_debug_parameters');
        return$actor;
    }

    /** Resolve only the edited profile's own actor in the currently loaded playthrough. */
    private function npcManagerScope(string $profileId,bool $lock=false):array
    {
        $query=$this->db->prepare('SELECT installation_id,actor_identity FROM profiles WHERE profile_id=:profile AND deleted_at IS NULL AND '.ProfileScopeSql::current('profiles').'');
        $query->execute(['profile'=>$profileId]);$profile=$query->fetch();
        if(!$profile)throw new \OutOfBoundsException('not_found');
        $scope=['profile_id'=>$profileId,'supported'=>false,'reason_code'=>null,'session_id'=>null,'generation'=>null,'actor'=>null];
        $identity=$this->json($profile['actor_identity']);
        if(!in_array($identity['kind']??null,['npc','creature','actor'],true)||!is_array($identity['refnum']??null)
            ||!is_string($identity['record_id']??null)||!is_string($identity['content_file']??null)){
            $scope['reason_code']='npc_manager_exact_actor_required';return$scope;
        }
        $query=$this->db->prepare("SELECT session_id,playthrough_id,generation,capabilities FROM sessions WHERE installation_id=:installation AND state='active' ORDER BY created_at DESC LIMIT 2".($lock?' FOR UPDATE':''));
        $query->execute(['installation'=>$profile['installation_id']]);$sessions=$query->fetchAll();
        if(count($sessions)!==1){$scope['reason_code']=count($sessions)===0?'npc_manager_no_active_session':'npc_manager_ambiguous_session';return$scope;}
        if($lock){
            $query=$this->db->prepare('SELECT actor_identity FROM profiles WHERE profile_id=:profile AND deleted_at IS NULL AND '.ProfileScopeSql::current('profiles').' FOR SHARE');
            $query->execute(['profile'=>$profileId]);$locked=$query->fetchColumn();
            if($locked===false||$this->json($locked)!=$identity)throw new InvalidArgumentException('npc_manager_profile_changed');
        }
        $session=$sessions[0];$scope['session_id']=$session['session_id'];$scope['generation']=(int)$session['generation'];
        $binding=$this->db->prepare('SELECT 1 FROM actor_profile_bindings WHERE installation_id=:installation AND playthrough_id=:playthrough AND profile_id=:profile AND actor_key=:key'.($lock?' FOR SHARE':''));
        $binding->execute(['installation'=>$profile['installation_id'],'playthrough'=>$session['playthrough_id'],'profile'=>$profileId,'key'=>$this->actorKey($identity)]);
        if(!$binding->fetchColumn()){$scope['reason_code']='npc_manager_profile_not_bound';return$scope;}
        {
            $observed=$this->db->prepare("SELECT target FROM active_turns WHERE session_id=:session AND generation=:generation AND target->>'record_id'=:record AND target->>'content_file'=:content AND target->'refnum'=CAST(:refnum AS jsonb) AND target->>'kind'=:kind ORDER BY accepted_at DESC,turn_id DESC LIMIT 1");
            $observed->execute(['session'=>$session['session_id'],'generation'=>$session['generation'],'kind'=>$identity['kind']==='actor'?'npc':$identity['kind'],'record'=>$identity['record_id'],'content'=>$identity['content_file'],'refnum'=>$this->encode($identity['refnum'])]);
            $target=$observed->fetchColumn();$target=$target===false?[]:$this->json($target);
            foreach(['cell','display_name']as$field)if(isset($target[$field]))$identity[$field]=$target[$field];
        }
        try{$scope['actor']=$this->npcManagerActor($identity);}catch(InvalidArgumentException){$scope['reason_code']='npc_manager_exact_actor_required';return$scope;}
        $capabilities=$this->parsePgArray((string)$session['capabilities']);
        if(!in_array('debug.commands.v1',$capabilities,true)||!in_array('debug.npc_manager.v1',$capabilities,true)){
            $scope['reason_code']='npc_manager_unsupported';return$scope;
        }
        $scope['supported']=true;return$scope;
    }

    /** Operator status is unknown until a terminal client receipt reports save-backed state. */
    public function npcManagerStatus(string $profileId):array
    {
        $scope=$this->npcManagerScope($profileId);$scope+=['items'=>[],'observed'=>null,'observed_at'=>null];
        if(!$scope['supported'])return$scope;
        $this->db->prepare("UPDATE debug_commands SET state='expired',completed_at=clock_timestamp(),reason_code='command_expired' WHERE session_id=:session AND generation=:generation AND state IN ('queued','delivered') AND expires_at<=clock_timestamp()")
            ->execute(['session'=>$scope['session_id'],'generation'=>$scope['generation']]);
        $query=$this->db->prepare("SELECT command_id,command_name AS name,state,reason_code,observed,created_at,completed_at,expires_at FROM debug_commands WHERE session_id=:session AND generation=:generation AND command_name IN ('npc.status','npc.visit','npc.teleport','npc.return') AND parameters->'actor' @> CAST(:actor AS jsonb) ORDER BY created_at DESC,command_id DESC LIMIT 20");
        $query->execute(['session'=>$scope['session_id'],'generation'=>$scope['generation'],'actor'=>$this->encode(array_intersect_key($scope['actor'],array_flip(['kind','record_id','content_file','refnum'])))]);
        foreach($query->fetchAll()as$row){
            $row['observed']=$row['observed']===null?null:$this->json($row['observed']);$scope['items'][]=$row;
            if(in_array($row['state'],['succeeded','failed','rejected'],true)&&is_bool($row['observed']['return_available']??null)
                &&($scope['observed_at']===null||new \DateTimeImmutable($row['completed_at'])>new \DateTimeImmutable($scope['observed_at']))){
                $scope['observed']=$row['observed'];$scope['observed_at']=$row['completed_at'];
            }
        }
        return$scope;
    }

    /** Browser callers choose a profile and a closed operation, never an actor or live target. */
    public function queueNpcManagerCommand(string $profileId,string $operation):array
    {
        if(!in_array($operation,['status','visit','teleport','return'],true))throw new InvalidArgumentException('invalid_npc_manager_operation');
        return$this->transaction(function()use($profileId,$operation):array{
            $scope=$this->npcManagerScope($profileId,true);
            if(!$scope['supported'])throw new InvalidArgumentException($scope['reason_code']);
            return$this->queueDebugCommand($scope['session_id'],'npc.'.$operation,['actor'=>$scope['actor']]);
        });
    }

    /** Return the bounded recent operator debug-command audit for one active session. */
    public function debugCommands(string $sessionId):array
    {
        $this->db->prepare("UPDATE debug_commands SET state='expired',completed_at=clock_timestamp(),reason_code='command_expired' "
            ."WHERE session_id=:session AND state IN ('queued','delivered') AND expires_at<=clock_timestamp()")
            ->execute(['session'=>$sessionId]);
        $stmt=$this->db->prepare('SELECT command_id,session_id,generation,command_name AS name,parameters,state,reason_code,observed,'
            .'created_at,delivered_at,completed_at,expires_at FROM debug_commands WHERE session_id=:session ORDER BY created_at DESC LIMIT 50');
        $stmt->execute(['session'=>$sessionId]);$rows=$stmt->fetchAll();
        foreach($rows as&$row){$row['generation']=(int)$row['generation'];$row['parameters']=$this->json($row['parameters']);
            $row['observed']=$row['observed']===null?null:$this->json($row['observed']);}unset($row);
        return$rows;
    }

    /** Queue one fixed, validated debug operation for the selected live game session. */
    public function queueDebugCommand(string $sessionId,string $name,array $parameters,?string $commandId=null):array
    {
        if($commandId!==null&&($name!=='player.dialogue.submit'||!Uuid::isValid($commandId)))throw new InvalidArgumentException('invalid_browser_speech_request');
        $empty=['status.snapshot','shaders.reload','player.vitals.restore','target.actor.kill','target.actor.restore','target.teleport.to_player'];
        $enabled=['god_mode.set','collision.set','ai.set','mwscript.set','shader_hot_reload.set'];
        $renderModes=['collision','wireframe','pathgrid','water','scene','navmesh','actors_paths','recast_mesh'];
        $hasKeys=static function(array $value,array $expected):bool{$actual=array_keys($value);sort($actual);sort($expected);return $actual===$expected;};
        $attributes=['strength','intelligence','willpower','agility','speed','endurance','personality','luck'];
        $skills=['block','armorer','mediumarmor','heavyarmor','bluntweapon','longblade','axe','spear','athletics','enchant',
            'destruction','alteration','illusion','conjuration','mysticism','restoration','alchemy','unarmored','security',
            'sneak','acrobatics','lightarmor','shortblade','marksman','mercantile','speechcraft','handtohand'];
        $recordId=static fn(mixed $value):bool=>is_string($value)&&$value!==''&&strlen($value)<=256
            &&preg_match('/[\\\\\/\r\n\t]/',$value)!==1;
        $number=static fn(mixed $value,float $minimum,float $maximum):bool=>(is_int($value)||is_float($value))
            &&is_finite((float)$value)&&(float)$value>=$minimum&&(float)$value<=$maximum;
        if($name==='player.dialogue.submit'){
            if(!$hasKeys($parameters,['text','language'])||!is_string($parameters['text']??null)
                ||trim($parameters['text'])===''||strlen($parameters['text'])>2048||!mb_check_encoding($parameters['text'],'UTF-8')
                ||preg_match('/[\x00-\x1f\x7f]/',$parameters['text'])||!is_string($parameters['language']??null)
                ||strlen($parameters['language'])>16||preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',$parameters['language'])!==1)
                throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif(in_array($name,['npc.status','npc.visit','npc.teleport','npc.return'],true)){
            if(!$hasKeys($parameters,['actor'])||!is_array($parameters['actor'])
                ||$this->npcManagerActor($parameters['actor'])!==$parameters['actor'])throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif(in_array($name,$empty,true)){if($parameters!==[])throw new InvalidArgumentException('invalid_debug_parameters');}
        elseif(in_array($name,$enabled,true)){if(!$hasKeys($parameters,['enabled'])||!is_bool($parameters['enabled']))throw new InvalidArgumentException('invalid_debug_parameters');}
        elseif($name==='render_mode.toggle'){
            if(!$hasKeys($parameters,['mode'])||!is_string($parameters['mode'])||!in_array($parameters['mode'],$renderModes,true))
                throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif(in_array($name,['player.inventory.add','player.inventory.remove'],true)){
            if(!$hasKeys($parameters,['record_id','count'])||!$recordId($parameters['record_id'])||!is_int($parameters['count'])
                ||$parameters['count']<1||$parameters['count']>10000)throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif(in_array($name,['player.spell.add','player.spell.remove'],true)){
            if(!$hasKeys($parameters,['record_id'])||!$recordId($parameters['record_id']))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif($name==='player.stat.set'){
            if(!$hasKeys($parameters,['stat','value'])||!in_array($parameters['stat']??null,['health','magicka','fatigue'],true)
                ||!$number($parameters['value']??null,0,1000000))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif($name==='player.attribute.set'){
            if(!$hasKeys($parameters,['attribute','value'])||!in_array($parameters['attribute']??null,$attributes,true)
                ||!$number($parameters['value']??null,0,1000))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif($name==='player.skill.set'){
            if(!$hasKeys($parameters,['skill','value'])||!in_array($parameters['skill']??null,$skills,true)
                ||!$number($parameters['value']??null,0,1000))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif(in_array($name,['player.level.set','player.bounty.set'],true)){
            $minimum=$name==='player.level.set'?1:0;$maximum=$name==='player.level.set'?1000:1000000000;
            if(!$hasKeys($parameters,['value'])||!is_int($parameters['value'])||$parameters['value']<$minimum
                ||$parameters['value']>$maximum)throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif(in_array($name,['player.scale.set','target.scale.set'],true)){
            if(!$hasKeys($parameters,['value'])||!$number($parameters['value'],0.01,100))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif($name==='world.timescale.set'){
            if(!$hasKeys($parameters,['value'])||!$number($parameters['value'],0,10000))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif($name==='world.time.advance'){
            if(!$hasKeys($parameters,['value'])||!$number($parameters['value'],0,8760))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif($name==='player.teleport'){
            if(!$hasKeys($parameters,['cell','x','y','z'])||!is_string($parameters['cell'])||$parameters['cell']===''
                ||strlen($parameters['cell'])>300||preg_match('/[\\\\\/\r\n\t]/',$parameters['cell'])===1
                ||!$number($parameters['x'],-100000000,100000000)||!$number($parameters['y'],-100000000,100000000)
                ||!$number($parameters['z'],-100000000,100000000))throw new InvalidArgumentException('invalid_debug_parameters');
        }elseif($name==='world.weather.set'){
            $weathers=['clear','cloudy','foggy','overcast','rain','thunderstorm','ashstorm','blight','snow','blizzard'];
            if(!$hasKeys($parameters,['region_id','weather'])||!$recordId($parameters['region_id'])
                ||!in_array($parameters['weather']??null,$weathers,true))throw new InvalidArgumentException('invalid_debug_parameters');
        }else throw new InvalidArgumentException('invalid_debug_command');
        return$this->transaction(function()use($sessionId,$name,$parameters,$commandId):array{
            $session=$this->db->prepare("SELECT installation_id,generation,capabilities FROM sessions WHERE session_id=:session AND state='active' FOR UPDATE");
            $session->execute(['session'=>$sessionId]);$row=$session->fetch();if(!$row)throw new InvalidArgumentException('invalid_session_id');
            $capabilities=$this->parsePgArray((string)$row['capabilities']);
            if(!in_array('debug.commands.v1',$capabilities,true))throw new InvalidArgumentException('debug_commands_unsupported');
            if(str_starts_with($name,'npc.')&&!in_array('debug.npc_manager.v1',$capabilities,true))throw new InvalidArgumentException('npc_manager_unsupported');
            if($name==='player.dialogue.submit'&&!in_array('speech.browser.v1',$capabilities,true))
                throw new InvalidArgumentException('browser_speech_unsupported');
            if($commandId!==null){
                $existing=$this->db->prepare('SELECT * FROM debug_commands WHERE command_id=:id');$existing->execute(['id'=>$commandId]);$duplicate=$existing->fetch();
                if($duplicate){
                    if($duplicate['session_id']!==$sessionId||$duplicate['command_name']!==$name||$this->json($duplicate['parameters'])!=$parameters)
                        throw new InvalidArgumentException('browser_speech_request_conflict');
                    return ['command_id'=>$commandId,'session_id'=>$sessionId,'generation'=>(int)$duplicate['generation'],'name'=>$name,
                        'parameters'=>$parameters,'state'=>$duplicate['state'],'created_at'=>$duplicate['created_at'],'expires_at'=>$duplicate['expires_at']];
                }
            }
            $this->db->prepare("UPDATE debug_commands SET state='expired',completed_at=clock_timestamp(),reason_code='command_expired' "
                ."WHERE session_id=:session AND state IN ('queued','delivered') AND expires_at<=clock_timestamp()")
                ->execute(['session'=>$sessionId]);
            if(in_array($name,['npc.visit','npc.teleport','npc.return'],true)){
                $pendingActor=$this->db->prepare("SELECT 1 FROM debug_commands WHERE session_id=:session AND state IN ('queued','delivered') AND command_name IN ('npc.visit','npc.teleport','npc.return') AND parameters->'actor' @> CAST(:actor AS jsonb) LIMIT 1");
                $pendingActor->execute(['session'=>$sessionId,'actor'=>$this->encode(array_intersect_key($parameters['actor'],array_flip(['kind','record_id','content_file','refnum'])))]);
                if($pendingActor->fetchColumn())throw new InvalidArgumentException('npc_manager_command_pending');
            }
            $pending=$this->db->prepare("SELECT count(*) FROM debug_commands WHERE session_id=:session AND state IN ('queued','delivered')");
            $pending->execute(['session'=>$sessionId]);if((int)$pending->fetchColumn()>=16)throw new InvalidArgumentException('debug_command_queue_full');
            $id=$commandId??Uuid::v4();$insert=$this->db->prepare('INSERT INTO debug_commands '
                .'(command_id,installation_id,session_id,generation,command_name,parameters,expires_at) '
                ."VALUES (:id,:installation,:session,:generation,:name,CAST(:parameters AS jsonb),clock_timestamp()+interval '30 seconds') RETURNING *");
            $insert->execute(['id'=>$id,'installation'=>$row['installation_id'],'session'=>$sessionId,'generation'=>$row['generation'],
                'name'=>$name,'parameters'=>$this->encode($parameters)]);$created=$insert->fetch();
            return['command_id'=>$id,'session_id'=>$sessionId,'generation'=>(int)$row['generation'],'name'=>$name,
                'parameters'=>$parameters,'state'=>'queued','created_at'=>$created['created_at'],'expires_at'=>$created['expires_at']];
        });
    }

    public function prune(int $days,string $now):array{$result=[];$queries=['rate_limits'=>"DELETE FROM rate_limit_buckets WHERE window_started_at < CAST(:now AS timestamptz) - interval '1 day'",'idempotency'=>"DELETE FROM idempotency_requests WHERE created_at < CAST(:now AS timestamptz) - (:days || ' days')::interval",'browser_sessions'=>'DELETE FROM browser_sessions WHERE expires_at<:now OR revoked_at IS NOT NULL'];foreach($queries as $key=>$sql){$s=$this->db->prepare($sql);$s->execute(['now'=>$now]+(str_contains($sql,':days')?['days'=>(string)$days]:[]));$result[$key]=$s->rowCount();}return $result;}

    private function revision(string $table,string $key,string $id,int $revision,array $content,string $reason,string $now):void{$this->db->prepare("INSERT INTO {$table} ({$key},revision,content,change_reason,created_at) VALUES (:id,:revision,CAST(:content AS jsonb),:reason,:now)")->execute(['id'=>$id,'revision'=>$revision,'content'=>$this->encode($content),'reason'=>$reason,'now'=>$now]);}
    /** Load only known event overrides for the selected installation before the turn is frozen. */
    public function narratorEventPromptTexts(string $installationId, ?array $keys = null): array
    {
        $query = $this->db->prepare('SELECT prompt_key,custom_prompt FROM prompts WHERE installation_id=:installation');
        $query->execute(['installation' => $installationId]);
        $known = \LorkhanServer\Application\NarratorEventPrompts::definitions();
        if($keys!==null)$known=array_intersect_key($known,array_flip($keys));
        $result = [];
        foreach ($query->fetchAll() as $row) {
            if (isset($known[$row['prompt_key']]) && is_string($row['custom_prompt']) && trim($row['custom_prompt']) !== '')
                $result[$row['prompt_key']] = $row['custom_prompt'];
        }
        return $result;
    }

    /** Import only named narration prompts; the caller can join this to the profile transaction. */
    public function importNarratorEventPrompts(string $installationId,array $prompts):void
    {
        $known=\LorkhanServer\Application\NarratorEventPrompts::definitions();
        if(($prompts!==[]&&array_is_list($prompts))||array_diff_key($prompts,$known)!==[])throw new InvalidArgumentException('invalid_narrator_prompt');
        foreach($prompts as$text)if(!is_string($text)||strlen($text)>32768||!mb_check_encoding($text,'UTF-8'))throw new InvalidArgumentException('invalid_narrator_prompt');
        ksort($prompts);
        $this->transaction(function()use($installationId,$prompts):void{
            foreach($prompts as$key=>$text){
                $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')->execute(['key'=>'narrator-prompt:'.$installationId.':'.$key]);
                $q=$this->db->prepare('SELECT source_revision FROM prompts WHERE installation_id=:installation AND prompt_key=:key');
                $q->execute(['installation'=>$installationId,'key'=>$key]);
                $this->saveNarratorEventPrompt($installationId,$key,$text,(int)($q->fetchColumn()?:0));
            }
        });
    }

    /** Serialize first saves as well as revisions, preserving other prompt document fields. */
    public function saveNarratorEventPrompt(string $installationId, string $key, string $custom, int $expectedRevision): array
    {
        $definition = \LorkhanServer\Application\NarratorEventPrompts::definitions()[$key] ?? null;
        if ($definition === null || $expectedRevision < 0 || strlen($custom) > (str_starts_with($key,'dynamic_prompt_')?8192:32768) || !mb_check_encoding($custom, 'UTF-8'))
            throw new InvalidArgumentException('invalid_narrator_prompt');
        return $this->transaction(function () use ($installationId, $key, $custom, $expectedRevision, $definition): array {
            $lock = $this->db->prepare('SELECT installation_id FROM installations WHERE installation_id=:installation');
            $lock->execute(['installation' => $installationId]);
            if ($lock->fetchColumn() === false) throw new RuntimeException('not_found');
            $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))')
                ->execute(['key' => 'narrator-prompt:'.$installationId.':'.$key]);
            $query = $this->db->prepare('SELECT source_configuration_id,source_revision FROM prompts WHERE installation_id=:installation AND prompt_key=:key');
            $query->execute(['installation' => $installationId, 'key' => $key]);
            $row = $query->fetch();
            if ((int)($row['source_revision'] ?? 0) !== $expectedRevision) throw new RuntimeException('revision_conflict');
            $current = $row ? $this->getRevisioned('prompt', $row['source_configuration_id']) : null;
            $content = array_replace($current['content'] ?? [], $definition);
            $content['purpose'] = 'narrator_event';
            $content['custom_prompt'] = trim($custom) === '' ? null : $custom;
            $content['instruction'] = $content['custom_prompt'] ?? $definition['default_prompt'];
            $service = new \LorkhanServer\Application\ProductService($this, new \LorkhanServer\Application\DeterministicClock());
            return $row ? $service->revise('prompt', $row['source_configuration_id'], $content, 'Narrator event prompt update', $expectedRevision)
                : $service->createRevisioned('prompt', ['installation_id' => $installationId, 'name' => $key, 'content' => $content]);
        });
    }

    /** Read the stored prompt baseline; browser edits cannot replace it with a submitted default. */
    public function promptText(string $configurationId):array
    {
        $query=$this->db->prepare('SELECT default_prompt,custom_prompt,description FROM prompts WHERE source_configuration_id=:id');
        $query->execute(['id'=>$configurationId]);$row=$query->fetch();
        if(!$row)throw new RuntimeException('not_found');
        return$row;
    }

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
    private function revisionMeta(string $kind):array{return match($kind){'profile'=>['profiles','profile_id','profile_revisions'],'core_profile'=>['core_profiles','core_profile_id','core_profile_revisions'],'playthrough'=>['playthroughs','playthrough_id','playthrough_revisions'],'prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy','memory_embedding_policy','translation_policy'=>['configuration_sets','configuration_id','configuration_revisions'],default=>throw new RuntimeException('invalid_resource_kind')};}
    /** Apply memory edits within the same transaction as the NPC Save All operation. */
    public function editNpcMemoryDigest(string $installation,string $playthrough,string $profile,int $revision,string $content):void
    {
        (new NpcMemoryDigestRepository($this->db))->edit($installation,$playthrough,$profile,$revision,$content);
    }

    public function transaction(callable $callback):mixed{$owns=!$this->db->inTransaction();if($owns)$this->db->beginTransaction();try{$v=$callback();if($owns)$this->db->commit();return$v;}catch(Throwable $e){if($owns&&$this->db->inTransaction())$this->db->rollBack();throw$e;}}
    private function deterministicUuid(string $value):string{$h=md5($value);return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);}
    private function selectedActorProfileId(string $installation,string $playthrough,array $identity):?string{$s=$this->db->prepare('SELECT b.profile_id FROM actor_profile_bindings b JOIN profiles p ON p.profile_id=b.profile_id AND p.installation_id=b.installation_id AND p.deleted_at IS NULL WHERE b.installation_id=:installation AND b.playthrough_id=:playthrough AND b.actor_key=:key AND '.ProfileScopeSql::matches('p','b.playthrough_id'));$s->execute(['installation'=>$installation,'playthrough'=>$playthrough,'key'=>$this->actorKey($identity)]);$value=$s->fetchColumn();return$value===false?null:(string)$value;}
    public function actorKey(array $identity):string{return hash('sha256',$this->encodeCanonical(['kind'=>$identity['kind']??null,'record_id'=>$identity['record_id']??null,'content_file'=>$identity['content_file']??null,'refnum'=>$identity['refnum']??null]));}
    private function encodeCanonical(mixed $value):string{$sort=static function(mixed $item)use(&$sort):mixed{if(!is_array($item))return$item;if(array_is_list($item))return array_map($sort,$item);ksort($item,SORT_STRING);foreach($item as&$child)$child=$sort($child);return$item;};return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function withoutSecrets(array $value):array{foreach($value as$key=>&$item){if(is_string($key)&&preg_match('/(?:api[_-]?key|secret|password|authorization|access[_-]?token|refresh[_-]?token)/i',$key)===1){unset($value[$key]);continue;}if(is_array($item))$item=$this->withoutSecrets($item);}unset($item);return$value;}
    private function encode(array $v):string{return json_encode($v===[]?(object)[]:$v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
    private function json(mixed $v):array{return is_array($v)?$v:json_decode((string)$v,true,64,JSON_THROW_ON_ERROR);}
    private function scopeParams(array $v):array{return ['installation'=>$v['installation_id'],'profile'=>$v['profile_id'],'playthrough'=>$v['playthrough_id']];}
    private function pgArray(array $v):string{return '{'.implode(',',array_map(fn($x)=>'"'.addcslashes((string)$x,'"\\').'"',$v)).'}';}
    private function parsePgArray(string $v):array{return $v==='{}'?[]:str_getcsv(trim($v,'{}'),',','"','\\');}
    private function decodeMemory(array $r):array{$r['lexical_terms']=$this->parsePgArray((string)$r['lexical_terms']);$r['fake_vector']=$this->json($r['fake_vector']);$r['provenance']=$this->json($r['provenance']);return$r;}
}
