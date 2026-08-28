<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\ProductRepository;
use InvalidArgumentException;

final class ProductService
{
    public function __construct(private readonly ProductRepository $repository, private readonly DeterministicClock $clock) {}

    /** @param array<string,mixed> $input */
    public function createRevisioned(string $kind, array $input): array
    {
        $allowed = ['profile', 'core_profile', 'playthrough', 'prompt', 'provider', 'tts_provider', 'stt_provider', 'action_policy', 'global_settings'];
        if (!in_array($kind, $allowed, true)) throw new InvalidArgumentException('invalid_resource_kind');
        $this->requireUuid($input, 'installation_id');
        $this->boundedString($input, 'name', 1, 256);
        if (!isset($input['content']) || !is_array($input['content'])
            || ($input['content'] !== [] && array_is_list($input['content']))) throw new InvalidArgumentException('invalid_content');
        if ($kind === 'profile' && isset($input['actor_identity'])
            && (!is_array($input['actor_identity']) || array_is_list($input['actor_identity']))) {
            throw new InvalidArgumentException('invalid_actor_identity');
        }
        if ($kind === 'profile' && isset($input['core_profile_id'])) $this->uuid((string)$input['core_profile_id']);
        if ($kind === 'core_profile') {
            if (isset($input['default_npc']) && !is_bool($input['default_npc'])) throw new InvalidArgumentException('invalid_default_npc');
            if (isset($input['slot']) && (!is_int($input['slot']) || $input['slot'] < 1 || $input['slot'] > 4)) {
                throw new InvalidArgumentException('invalid_core_profile_slot');
            }
        }
        // LLM slots use an exact typed schema: credential is a reference and max_tokens is numeric.
        if ($kind !== 'provider') $this->assertNoSecrets($input['content']);
        $input['content']=$this->validateConfiguration($kind,$input['content']);
        return $this->repository->createRevisioned($kind, $input, $this->clock->iso());
    }

    /** @param array<string,mixed> $content */
    public function revise(string $kind, string $id, array $content, string $reason): array
    {
        $this->uuid($id);
        if(!in_array($kind,['profile','core_profile','playthrough','prompt','provider','tts_provider','action_policy','global_settings'],true)||$this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        if ($reason === '' || strlen($reason) > 512) throw new InvalidArgumentException('invalid_reason');
        if ($kind !== 'provider') $this->assertNoSecrets($content);
        $content=$this->validateConfiguration($kind,$content);
        return $this->repository->revise($kind, $id, $content, $reason, $this->clock->iso());
    }

    /** Validate and atomically save the Herika-style Core Profile editor document. */
    public function reviseCoreProfile(string $id,string $label,bool $defaultNpc,?int $slot,array $content,string $reason):array
    {
        $this->uuid($id);$this->boundedString(['label'=>$label],'label',1,256);
        if($slot!==null&&($slot<1||$slot>4))throw new InvalidArgumentException('invalid_core_profile_slot');
        if($reason===''||strlen($reason)>512)throw new InvalidArgumentException('invalid_reason');
        if($this->repository->resourceKind($id)!=='core_profile')throw new InvalidArgumentException('resource_kind_mismatch');
        $this->assertNoSecrets($content);$content=$this->validateConfiguration('core_profile',$content);
        return$this->repository->reviseCoreProfile($id,$label,$defaultNpc,$slot,$content,$reason,$this->clock->iso());
    }

    public function rollback(string $kind, string $id, int $revision, string $reason): array
    {
        if ($revision < 1) throw new InvalidArgumentException('invalid_revision');
        $this->uuid($id);if(!in_array($kind,['profile','core_profile','playthrough','prompt','provider','tts_provider','action_policy','global_settings'],true)
            ||$this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        $content=$this->repository->revisionContent($kind,$id,$revision);
        if ($kind !== 'provider') $this->assertNoSecrets($content);
        $this->validateConfiguration($kind,$content);
        return $this->repository->rollback($kind, $id, $revision, $reason, $this->clock->iso());
    }

    /** Create or update one Morrowind record description used by bounded turn context. */
    public function saveItemDescription(array $input): array
    {
        $this->requireUuid($input,'installation_id');
        foreach(['content_file'=>256,'record_id'=>256,'display_name'=>256,'description'=>8192]as$field=>$limit)$this->boundedString($input,$field,1,$limit);
        return$this->repository->saveItemDescription($input,$this->clock->iso());
    }

    /** Validate and atomically store a bounded CHIM-format CSV batch as custom overrides. */
    public function importItemDescriptions(string $installationId,array $rows): array
    {
        $this->uuid($installationId);
        if($rows===[]||count($rows)>5000)throw new InvalidArgumentException('invalid_description_batch');
        $validated=[];$seen=[];
        foreach($rows as$row){
            if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('invalid_description_row');
            $input=['installation_id'=>$installationId,'content_file'=>$row['plugin']??null,'record_id'=>$row['baseid']??null,
                'display_name'=>$row['name']??null,'description'=>$row['description']??null];
            foreach(['content_file'=>256,'record_id'=>256,'display_name'=>256,'description'=>8192]as$field=>$limit)$this->boundedString($input,$field,1,$limit);
            $key=strtolower(trim((string)$input['content_file']))."\0".strtolower(trim((string)$input['record_id']));
            if(isset($seen[$key]))throw new InvalidArgumentException('duplicate_description_identity');
            $seen[$key]=true;$validated[]=$input;
        }
        return$this->repository->saveItemDescriptions($validated,$this->clock->iso());
    }

    public function resetItemDescriptions(string $installationId): int
    {
        $this->uuid($installationId);return$this->repository->resetItemDescriptions($installationId,$this->clock->iso());
    }

    public function deleteItemDescription(string $descriptionId,string $installationId): void
    {
        $this->uuid($descriptionId);$this->uuid($installationId);
        $this->repository->deleteItemDescription($descriptionId,$installationId,$this->clock->iso());
    }

    /** Soft-delete one versioned management resource without removing its audit revisions. */
    public function deleteRevisioned(string $kind,string $id):void
    {
        $this->uuid($id);
        if(!in_array($kind,['profile','core_profile','playthrough','prompt','provider','tts_provider','action_policy','global_settings'],true)
            ||$this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        $this->repository->deleteRevisioned($kind,$id,$this->clock->iso());
    }

    /** Activate one saved installation-global speech preset. */
    public function selectConnector(array $input): array
    {
        $this->requireUuid($input, 'installation_id');
        $this->requireUuid($input, 'configuration_id');
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, ['tts_provider','stt_provider'], true)) {
            throw new InvalidArgumentException('invalid_connector_kind');
        }
        return $this->repository->selectConnector($input['installation_id'], $kind, $input['configuration_id'], $this->clock->iso());
    }

    /** @param array<string,mixed> $input */
    public function createMemory(array $input): array
    {
        $this->scope($input);
        if (!in_array($input['tier'] ?? null, ['recent', 'mid', 'long'], true)) throw new InvalidArgumentException('invalid_memory_tier');
        $this->boundedString($input, 'content', 1, 16384);
        $input['provenance'] = $this->provenance($input);
        return $this->repository->createMemory($input, DeterministicRetrieval::terms($input['content']),
            DeterministicRetrieval::fakeVector($input['content']), $this->clock->iso());
    }

    /** @param array<string,mixed> $scope */
    public function searchMemory(array $scope, string $query, int $limit = 10): array
    {
        $this->scope($scope);
        $this->boundedQuery($query, $limit);
        $rows = $this->repository->memoryCandidates($scope, $this->clock->iso());
        foreach ($rows as &$row) {
            $row['score'] = DeterministicRetrieval::score($query, $row['lexical_terms'], $row['fake_vector']);
        }
        return $this->rankAndTrace('memory', $scope, $query, $rows, $limit);
    }

    /** @param array<string,mixed> $input */
    public function ingestKnowledge(array $input): array
    {
        $this->requireUuid($input, 'installation_id');
        $input=$this->knowledgeInput($input);
        foreach (['profile_id', 'playthrough_id'] as $field) if (isset($input[$field]) && $input[$field] !== null) $this->uuid((string) $input[$field]);
        $input['provenance'] = $this->provenance($input);
        return $this->repository->createKnowledge($input, DeterministicRetrieval::terms(implode(' ',[$input['topic'],$input['title'],$input['aliases'],$input['content'],$input['topic_desc_basic'],$input['tags']])), $this->clock->iso());
    }

    /** Validate every CSV row before atomically creating any Oghma documents. */
    public function importKnowledge(array $inputs):array
    {
        if($inputs===[]||count($inputs)>5000)throw new InvalidArgumentException('invalid_knowledge_import');
        $prepared=[];
        foreach($inputs as$input){
            if(!is_array($input)||array_is_list($input))throw new InvalidArgumentException('invalid_knowledge_import');
            $this->requireUuid($input,'installation_id');$input=$this->knowledgeInput($input);
            foreach(['profile_id','playthrough_id']as$field)if(isset($input[$field])&&$input[$field]!==null)$this->uuid((string)$input[$field]);
            $input['provenance']=$this->provenance($input);
            $prepared[]=['input'=>$input,'terms'=>DeterministicRetrieval::terms(implode(' ',[
                $input['topic'],$input['title'],$input['aliases'],$input['content'],$input['topic_desc_basic'],$input['tags']]))];
        }
        return$this->repository->createKnowledgeBatch($prepared,$this->clock->iso());
    }

    /** Replace one user-authored Oghma document, or save a factory edit as a custom override, keeping installation scope. */
    public function updateKnowledge(string $documentId,array $input):array
    {
        $this->uuid($documentId);$current=$this->repository->knowledge($documentId);
        $input=$this->knowledgeInput($input);$input['provenance']=$this->provenance($input);
        if(($current['provenance']['source']??null)!=='factory-oghma'){
            $terms=DeterministicRetrieval::terms(implode(' ',[$input['topic'],$input['title'],$input['aliases'],$input['content'],$input['topic_desc_basic'],$input['tags']]));
            return$this->repository->updateKnowledge($documentId,$input,$terms,$this->clock->iso());
        }
        // Factory rows stay read-only: the edit creates or updates the custom override that shadows them. Scope comes
        // from the stored factory document alone, so a browser-supplied scope can never redirect the write elsewhere.
        $input['topic']=(string)$current['topic'];
        $input['installation_id']=(string)$current['installation_id'];
        foreach(['profile_id','playthrough_id']as$field)
            $input[$field]=($current[$field]??null)===null?null:(string)$current[$field];
        $this->requireUuid($input,'installation_id');
        foreach(['profile_id','playthrough_id']as$field)if($input[$field]!==null)$this->uuid($input[$field]);
        $terms=DeterministicRetrieval::terms(implode(' ',[$input['topic'],$input['title'],$input['aliases'],$input['content'],$input['topic_desc_basic'],$input['tags']]));
        return$this->repository->createKnowledge($input,$terms,$this->clock->iso());
    }

    /** @param array<string,mixed> $scope */
    public function searchKnowledge(array $scope, string $query, int $limit = 10): array
    {
        $this->requireUuid($scope, 'installation_id');
        foreach (['profile_id', 'playthrough_id'] as $field) if (isset($scope[$field]) && $scope[$field] !== null) $this->uuid((string) $scope[$field]);
        $this->boundedQuery($query, $limit);
        $rows = $this->repository->knowledgeCandidates($scope);
        foreach ($rows as &$row) {
            $row['score'] = DeterministicRetrieval::score($query, $row['lexical_terms'], DeterministicRetrieval::fakeVector($row['content']));
        }
        return $this->rankAndTrace('knowledge', $scope, $query, $rows, $limit);
    }

    /** @param array<string,mixed> $input */
    public function setRelationship(array $input): array
    {
        $this->scope($input);
        if (!isset($input['actor_identity']) || !is_array($input['actor_identity']) || array_is_list($input['actor_identity'])) throw new InvalidArgumentException('invalid_actor_identity');
        foreach (['disposition', 'affinity'] as $field) if (!isset($input[$field]) || !is_int($input[$field]) || $input[$field] < -100 || $input[$field] > 100) throw new InvalidArgumentException('invalid_relationship_value');
        if (!in_array($input['source_mode'] ?? null, ['derived', 'manual'], true)) throw new InvalidArgumentException('invalid_source_mode');
        return $this->repository->setRelationship($input, $this->clock->iso());
    }

    /** @param array<string,mixed> $input */
    public function createNarrative(array $input): array
    {
        $this->scope($input);
        if (!in_array($input['kind'] ?? null, ['narrator', 'diary', 'summary'], true)) throw new InvalidArgumentException('invalid_narrative_kind');
        $this->boundedString($input, 'title', 1, 256);
        $this->boundedString($input, 'content', 1, 65536);
        $input['provenance'] = $this->provenance($input);
        return $this->repository->createNarrative($input, $this->clock->iso());
    }

    /** Edit one existing narrator, diary, or summary record without changing its playthrough scope. */
    public function updateNarrative(string $narrativeId,array $input):array
    {
        $this->uuid($narrativeId);
        if(!in_array($input['kind']??null,['narrator','diary','summary'],true))throw new InvalidArgumentException('invalid_narrative_kind');
        $this->boundedString($input,'title',1,256);$this->boundedString($input,'content',1,65536);
        $input['provenance']=$this->provenance($input);return$this->repository->updateNarrative($narrativeId,$input,$this->clock->iso());
    }

    /** @param array<string,mixed> $input */
    public function scheduleAutonomy(array $input): array
    {
        throw new InvalidArgumentException('feature_excluded');
    }

    /** @param array<string,mixed> $scope */
    public function dueAutonomy(array $scope): array
    {
        return [];
    }

    /** @param array<string,mixed> $scope */
    public function exportPlaythrough(array $scope): array
    {
        $this->scope($scope);
        return ['schema' => 'almsivi.playthrough-export.v1', 'exported_at' => $this->clock->iso(),
            'scope' => $scope, 'data' => $this->repository->exportScope($scope)];
    }

    /** @param array<string,mixed> $document */
    public function restorePlaythrough(array $document): array
    {
        $keys=array_keys($document);sort($keys);if($keys!==['data','exported_at','schema','scope']||($document['schema']??null)!=='almsivi.playthrough-export.v1'||!is_string($document['exported_at'])
            ||!is_array($document['scope'])||array_is_list($document['scope'])||!is_array($document['data'])||array_is_list($document['data']))throw new InvalidArgumentException('invalid_restore');
        $dataKeys=array_keys($document['data']);sort($dataKeys);if($dataKeys!==['memories','narratives','relationships'])throw new InvalidArgumentException('invalid_restore');
        foreach($document['data'] as$rows)if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['memories'] as$r)if(!is_array($r)||!in_array($r['tier']??null,['recent','mid','long'],true)||!is_string($r['content']??null)||!is_array($r['lexical_terms']??null)||!is_string($r['occurred_at']??null))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['relationships'] as$r)if(!is_array($r)||!is_array($r['actor_identity']??null)||!is_numeric($r['disposition']??null)||!is_numeric($r['affinity']??null))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['narratives'] as$r)if(!is_array($r)||!is_string($r['kind']??null)||!is_string($r['title']??null)||!is_string($r['content']??null))throw new InvalidArgumentException('invalid_restore');
        $this->scope($document['scope']);
        return $this->repository->restoreScope($document, $this->clock->iso());
    }

    /** @param array<string,mixed> $scope @param list<array<string,mixed>> $rows */
    private function rankAndTrace(string $domain, array $scope, string $query, array $rows, int $limit): array
    {
        usort($rows, static fn(array $a, array $b): int => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);
        $rows = array_slice($rows, 0, $limit);
        return $this->repository->recordRetrieval($domain, $scope, $query, $rows, $this->clock->iso());
    }

    private function provenance(array $input): array
    {
        $value = $input['provenance'] ?? null;
        if (!is_array($value) || array_is_list($value) || !is_string($value['source'] ?? null) || $value['source'] === '') throw new InvalidArgumentException('provenance_required');
        return $value;
    }

    /** Normalize the exact CHIM static-Oghma fields while accepting legacy title/content callers. */
    private function knowledgeInput(array $input):array
    {
        $input['topic']=trim((string)($input['topic']??$input['title']??''));
        $input['title']=trim((string)($input['title']??str_replace('_',' ',$input['topic'])));
        $input['content']=trim((string)($input['content']??$input['topic_desc']??''));
        $input['topic_desc_basic']=trim((string)($input['topic_desc_basic']??$input['content']));
        foreach(['topic'=>256,'title'=>256,'content'=>131072,'topic_desc_basic'=>131072]as$field=>$max)$this->boundedString($input,$field,1,$max);
        foreach(['aliases','knowledge_class','knowledge_class_basic','tags']as$field){$value=$input[$field]??'';
            if(is_array($value)){if(!array_is_list($value))throw new InvalidArgumentException('invalid_'.$field);$items=array_map(static fn(mixed$item):string=>trim((string)$item),$value);if($field==='aliases')$items=array_map(static fn(string$item):string=>preg_replace('/\s*,\s*/u','_',$item)??$item,$items);$value=implode($field==='aliases'?', ':',',$items);}
            elseif($field==='aliases'&&is_string($value))$value=preg_replace('/\s*[|;]\s*/u',', ',$value)??$value;
            if(!is_string($value)||strlen($value)>8192||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_'.$field);$input[$field]=trim($value);}
        if(in_array('common',array_map(static fn(string$value):string=>mb_strtolower(trim($value),'UTF-8'),
            preg_split('/\s*[,|;]\s*/u',$input['knowledge_class'])?:[]),true))throw new InvalidArgumentException('invalid_knowledge_class');
        $input['knowledge_class_basic']=$input['knowledge_class_basic']===''?'common':$input['knowledge_class_basic'];
        $input['category']=trim((string)($input['category']??($input['provenance']['category']??'ALMSIVI')));
        if($input['category']===''||strlen($input['category'])>128||!mb_check_encoding($input['category'],'UTF-8'))throw new InvalidArgumentException('invalid_category');
        return$input;
    }

    private function scope(array $input): void
    {
        foreach (['installation_id', 'profile_id', 'playthrough_id'] as $field) $this->requireUuid($input, $field);
    }

    private function boundedQuery(string $query, int $limit): void
    {
        if ($query === '' || strlen($query) > 4096 || $limit < 1 || $limit > 50) throw new InvalidArgumentException('invalid_query');
    }

    private function requireUuid(array $input, string $field): void { if (!isset($input[$field]) || !is_string($input[$field])) throw new InvalidArgumentException('invalid_' . $field); $this->uuid($input[$field]); }
    /** Accept canonical PostgreSQL UUIDs, including legacy deterministic Core Profile identifiers. */
    private function uuid(string $value): void { if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) !== 1) throw new InvalidArgumentException('invalid_uuid'); }
    private function boundedString(array $input, string $field, int $min, int $max): void { if (!isset($input[$field]) || !is_string($input[$field]) || strlen($input[$field]) < $min || strlen($input[$field]) > $max || !mb_check_encoding($input[$field], 'UTF-8')) throw new InvalidArgumentException('invalid_' . $field); }

    private function validateConfiguration(string $kind,array $content):array
    {
        if (in_array($kind, ['tts_provider', 'stt_provider'], true)) return ConnectorCatalog::validate($kind, $content);
        if ($kind === 'profile') return $this->validateProfile($content);
        if ($kind === 'core_profile') return EffectiveSettingsResolver::validateCoreProfile($content);
        if($kind==='prompt'){
            $encoded=json_encode($content,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if(strlen($encoded)>65_536)throw new InvalidArgumentException('invalid_prompt_content');
            return$content;
        }
        if($kind==='action_policy')return$this->validateActionPolicy($content);
        if($kind==='global_settings')return EffectiveSettingsResolver::validateGlobalSettings($content);
        if($kind!=='provider')return$content;
        return LlmConnector::validate($content);
    }

    /** Keep server-to-client settings bounded, typed, and free of executable or transport values. */
    private function validateGlobalSettings(array $content):array
    {
        $expected=['behavior','memory','narrator','presentation','safety','schema'];$keys=array_keys($content);sort($keys);
        if($keys!==$expected||($content['schema']??null)!=='almsivi.client-settings.v1')throw new InvalidArgumentException('invalid_global_settings');
        $sections=['behavior'=>[
            'auto_greeting'=>'bool','rechat'=>'bool','rechat_delay_seconds'=>[30,3600],'rechat_max_depth'=>[1,20],
            'rechat_probability_percent'=>[0,100],'rechat_mode'=>['tight','conversational','group','random'],
            'rechat_strict_targeting'=>'bool','open_rechat'=>'bool','rechat_allow_actions'=>'bool',
            'end_conversation_cooldown_seconds'=>[0,300],
            'boredom'=>'bool','boredom_delay_seconds'=>[30,86400],'combat_barks'=>'bool','combat_bark_period_seconds'=>[5,300],
        ],'memory'=>['recent_turn_limit'=>[1,100],'knowledge_limit'=>[0,20]],
        'narrator'=>[
            'enabled'=>'bool','name'=>'string','context_visibility'=>'bool','inline_mode'=>['Disabled','Narrator','NPC','Text Only'],
            'welcome_events'=>'bool','random_events'=>'bool','quest_events'=>'bool','book_events'=>'bool',
        ],'presentation'=>['show_status_hud'=>'bool','transcript_rows'=>[2,20],'tts_volume_boost'=>[1,4]],
        'safety'=>['actions_enabled'=>'bool','allow_hostile'=>'bool','allow_creatures'=>'bool']];
        $result=['schema'=>'almsivi.client-settings.v1'];
        foreach($sections as$section=>$fields){$value=$content[$section]??null;if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('invalid_global_settings');
            $sectionKeys=array_keys($value);sort($sectionKeys);$expectedKeys=array_keys($fields);sort($expectedKeys);if($sectionKeys!==$expectedKeys)throw new InvalidArgumentException('invalid_global_settings');$result[$section]=[];
            foreach($fields as$field=>$rule){$item=$value[$field];if($rule==='bool'){if(!is_bool($item))throw new InvalidArgumentException('invalid_global_settings');}
                elseif($rule==='string'){if(!is_string($item)||trim($item)===''||strlen($item)>128||!mb_check_encoding($item,'UTF-8'))throw new InvalidArgumentException('invalid_global_settings');$item=trim($item);}
                elseif(isset($rule[0])&&is_int($rule[0])){if(!is_int($item)||$item<$rule[0]||$item>$rule[1])throw new InvalidArgumentException('invalid_global_settings');}
                elseif(!is_string($item)||!in_array($item,$rule,true))throw new InvalidArgumentException('invalid_global_settings');
                $result[$section][$field]=$item;}}
        return$result;
    }

    /** Validate the editable CHIM-lineage NPC fields while preserving a compact OpenMW profile document. */
    private function validateProfile(array $content):array
    {
        if(strlen(json_encode($content,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>131_072)throw new InvalidArgumentException('invalid_profile_content');
        if(array_key_exists('management',$content)){
            $management=$content['management'];
            if(!is_array($management)||array_is_list($management)||array_diff(array_keys($management),['locked','favorite'])!==[])
                throw new InvalidArgumentException('invalid_profile_management');
            foreach(['locked','favorite']as$field)if(isset($management[$field])&&!is_bool($management[$field]))
                throw new InvalidArgumentException('invalid_profile_management');
            $content['management']=['locked'=>($management['locked']??false)===true,'favorite'=>($management['favorite']??false)===true];
        }
        if(array_key_exists('portrait',$content)){
            $portrait=$content['portrait'];$keys=is_array($portrait)?array_keys($portrait):[];sort($keys);
            if(!is_array($portrait)||array_is_list($portrait)||$keys!==['bytes','filename','height','mime','sha256','updated_at','width']
                ||!is_string($portrait['filename']??null)||preg_match('/^[0-9a-f-]{36}-[0-9a-f]{16}\.(?:png|jpg|webp)$/D',$portrait['filename'])!==1
                ||!in_array($portrait['mime']??null,['image/png','image/jpeg','image/webp'],true)
                ||!is_int($portrait['bytes']??null)||$portrait['bytes']<1||$portrait['bytes']>5_242_880
                ||!is_int($portrait['width']??null)||$portrait['width']<1||$portrait['width']>2048
                ||!is_int($portrait['height']??null)||$portrait['height']<1||$portrait['height']>2048
                ||!is_string($portrait['sha256']??null)||preg_match('/^[0-9a-f]{64}$/D',$portrait['sha256'])!==1
                ||!is_string($portrait['updated_at']??null)||strlen($portrait['updated_at'])>64)
                throw new InvalidArgumentException('invalid_profile_portrait');
        }
        if (array_key_exists('routing', $content)) {
            $content['routing'] = EffectiveSettingsResolver::validateRouting($content['routing']);
        }
        if (array_key_exists('settings_overrides', $content)) {
            $content['settings_overrides'] = EffectiveSettingsResolver::validateSettingsOverrides($content['settings_overrides']);
        }
        if(!array_key_exists('voice',$content))return$content;
        $voice=$content['voice'];if(!is_array($voice)||array_is_list($voice)||array_diff(array_keys($voice),['id','language'])!==[])
            throw new InvalidArgumentException('invalid_profile_voice');
        $id=$voice['id']??null;$language=$voice['language']??'en';
        if(!is_string($id)||$id===''||strlen($id)>512||!mb_check_encoding($id,'UTF-8')
            ||!is_string($language)||$language===''||strlen($language)>35||!mb_check_encoding($language,'UTF-8'))
            throw new InvalidArgumentException('invalid_profile_voice');
        $content['voice']=['id'=>$id,'language'=>$language];return$content;
    }

    /** Validate the same bounded action-policy document consumed by the runtime catalog gate. */
    private function validateActionPolicy(array $content):array
    {
        $known=['enabled','max_tier','allowed_actions','denied_actions','actions'];
        if(array_diff(array_keys($content),$known)!==[])throw new InvalidArgumentException('invalid_action_policy');
        if(isset($content['enabled'])&&!is_bool($content['enabled']))throw new InvalidArgumentException('invalid_action_policy');
        if(isset($content['max_tier'])&&(!is_int($content['max_tier'])||$content['max_tier']<0||$content['max_tier']>3))throw new InvalidArgumentException('invalid_action_policy');
        foreach(['allowed_actions','denied_actions']as$field){if(!array_key_exists($field,$content))continue;$values=$content[$field];
            if(!is_array($values)||!array_is_list($values)||count($values)>128)throw new InvalidArgumentException('invalid_action_policy');
            foreach($values as$value)if(!is_string($value)||$value===''||strlen($value)>128||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_action_policy');}
        if(isset($content['actions'])){if(!is_array($content['actions'])||array_is_list($content['actions'])||count($content['actions'])>128)throw new InvalidArgumentException('invalid_action_policy');
            foreach($content['actions']as$name=>$enabled)if(!is_string($name)||$name===''||strlen($name)>128||!is_bool($enabled))throw new InvalidArgumentException('invalid_action_policy');}
        return$content;
    }

    private function assertNoSecrets(array $content): void
    {
        $walk = static function (mixed $value) use (&$walk): void {
            if (!is_array($value)) return;
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/(?:secret|password|api[_-]?key|authorization|credential|token)/i', $key)) throw new InvalidArgumentException('secret_not_accepted');
                $walk($child);
            }
        };
        $walk($content);
    }
}
