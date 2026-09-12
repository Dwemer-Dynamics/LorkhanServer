<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\ProductRepository;
use InvalidArgumentException;

final class ProductService
{
    public function __construct(private readonly ProductRepository $repository, private readonly DeterministicClock $clock) {}

    /** @param array<string,mixed> $input */
    public function createRevisioned(string $kind, array $input): array
    {
        $allowed = ['profile', 'core_profile', 'playthrough', 'prompt', 'provider', 'tts_provider', 'stt_provider', 'action_policy', 'global_settings', 'memory_policy', 'memory_embedding_policy', 'translation_policy'];
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
        // LLM and speech credential references have exact typed validation; nested secret keys remain forbidden.
        if ($kind !== 'provider') $this->assertNoSecrets(in_array($kind, ['tts_provider','stt_provider'], true) ? array_diff_key($input['content'], ['credential'=>true]) : $input['content']);
        $input['content']=$this->validateConfiguration($kind,$input['content']);
        return $this->repository->createRevisioned($kind, $input, $this->clock->iso());
    }

    /** @param array<string,mixed> $content */
    public function revise(string $kind, string $id, array $content, string $reason, ?int $expectedRevision = null): array
    {
        $this->uuid($id);
        if(!in_array($kind,['profile','core_profile','playthrough','prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy','memory_embedding_policy','translation_policy'],true)||$this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        if ($reason === '' || strlen($reason) > 512) throw new InvalidArgumentException('invalid_reason');
        if ($kind !== 'provider') $this->assertNoSecrets(in_array($kind, ['tts_provider','stt_provider'], true) ? array_diff_key($content, ['credential'=>true]) : $content);
        $content=$this->validateConfiguration($kind,$content);
        if ($expectedRevision !== null && $expectedRevision < 1) throw new InvalidArgumentException('invalid_expected_revision');
        return $this->repository->revise($kind, $id, $content, $reason, $this->clock->iso(), $expectedRevision);
    }

    /** Validate the Quickstart player label without changing the persona's other saved settings. */
    public function renamePlayer(string $installation,string $name,int $expectedRevision):array
    {
        $this->uuid($installation);$name=trim($name);$this->boundedString(['name'=>$name],'name',1,256);
        if(preg_match('/[\x00-\x1f\x7f]/',$name)||$expectedRevision<1)throw new InvalidArgumentException('invalid_player_name');
        return $this->repository->renamePlayer($installation,$name,$expectedRevision,$this->clock->iso());
    }

    /** Save the Player editor's name and content as one validated revision. */
    public function revisePlayer(string $installation,string $id,string $name,array $content,string $reason,int $expectedRevision):array
    {
        $this->uuid($installation);$this->uuid($id);$name=trim($name);$this->boundedString(['name'=>$name],'name',1,256);
        if(preg_match('/[\x00-\x1f\x7f]/',$name)||$expectedRevision<1)throw new InvalidArgumentException('invalid_player_name');
        if($reason===''||strlen($reason)>512)throw new InvalidArgumentException('invalid_reason');
        $this->assertNoSecrets($content);$content=$this->validateConfiguration('profile',$content);
        return $this->repository->revisePlayer($installation,$id,$name,$content,$reason,$expectedRevision,$this->clock->iso());
    }

    /** Save the Narrator display name, persona and Core Profile as one validated revision. */
    public function reviseNarrator(string $installation,string $id,string $name,array $content,string $reason,string $coreProfileId,int $expectedRevision):array
    {
        $this->uuid($installation);$this->uuid($id);if($coreProfileId!=='')$this->uuid($coreProfileId);
        $name=trim($name);$this->boundedString(['name'=>$name],'name',1,256);
        if(preg_match('/[\x00-\x1f\x7f]/',$name)||$expectedRevision<1)throw new InvalidArgumentException('invalid_narrator_name');
        if($reason===''||strlen($reason)>512)throw new InvalidArgumentException('invalid_reason');
        $this->assertNoSecrets($content);$content=$this->validateConfiguration('profile',$content);
        return $this->repository->reviseNarrator($installation,$id,$name,$content,$reason,$coreProfileId,$expectedRevision,$this->clock->iso());
    }

    /** Validate an editable connector label together with the same typed settings used by normal revisions. */
    public function reviseNamedConnector(string $kind,string $id,string $name,array $content,string $reason):array
    {
        $this->uuid($id);$name=trim($name);$this->boundedString(['name'=>$name],'name',1,128);
        if(!in_array($kind,['provider','tts_provider','stt_provider'],true)||$this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        if($reason===''||strlen($reason)>512)throw new InvalidArgumentException('invalid_reason');
        if($kind!=='provider')$this->assertNoSecrets(in_array($kind,['tts_provider','stt_provider'],true)?array_diff_key($content,['credential'=>true]):$content);
        $content=$this->validateConfiguration($kind,$content);
        return $this->repository->reviseNamedConnector($kind,$id,$name,$content,$reason,$this->clock->iso());
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

    /** Save a persona and its Core Profile together so a failed edit cannot change routing. */
    public function revisePersona(string $id,array $content,string $reason,string $coreProfileId=''):array
    {
        $this->uuid($id);if($coreProfileId!=='')$this->uuid($coreProfileId);
        if($this->repository->resourceKind($id)!=='profile')throw new InvalidArgumentException('resource_kind_mismatch');
        if($reason===''||strlen($reason)>512)throw new InvalidArgumentException('invalid_reason');
        $this->assertNoSecrets($content);$content=$this->validateConfiguration('profile',$content);
        return$this->repository->revisePersona($id,$content,$reason,$coreProfileId,$this->clock->iso());
    }

    public function rollback(string $kind, string $id, int $revision, string $reason): array
    {
        if ($revision < 1) throw new InvalidArgumentException('invalid_revision');
        $this->uuid($id);if(!in_array($kind,['profile','core_profile','playthrough','prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy','memory_embedding_policy','translation_policy'],true)
            ||$this->repository->resourceKind($id)!==$kind)throw new InvalidArgumentException('resource_kind_mismatch');
        $content=$this->repository->revisionContent($kind,$id,$revision);
        if ($kind !== 'provider') $this->assertNoSecrets(in_array($kind, ['tts_provider','stt_provider'], true) ? array_diff_key($content, ['credential'=>true]) : $content);
        $this->validateConfiguration($kind,$content);
        return $this->repository->rollback($kind, $id, $revision, $reason, $this->clock->iso());
    }

    /** Create or update one Morrowind record description used by bounded turn context. */
    public function saveItemDescription(array $input): array
    {
        $this->requireUuid($input,'installation_id');
        foreach(['content_file'=>256,'record_id'=>256,'display_name'=>256,'description'=>8192]as$field=>$limit)$this->boundedString($input,$field,in_array($field,['display_name','description'],true)?0:1,$limit);
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
            foreach(['content_file'=>256,'record_id'=>256,'display_name'=>256,'description'=>8192]as$field=>$limit)$this->boundedString($input,$field,in_array($field,['display_name','description'],true)?0:1,$limit);
            $key=strtolower(trim((string)$input['content_file']))."\0".strtolower(trim((string)$input['record_id']));
            if(isset($seen[$key]))throw new InvalidArgumentException('duplicate_description_identity');
            $seen[$key]=true;$validated[]=$input;
        }
        return$this->repository->saveItemDescriptions($validated,$this->clock->iso());
    }

    /** Validate portable OpenMW biography rows before atomically revising reusable NPC templates. */
    public function importBiographyTemplates(string $installationId,array $rows):array
    {
        $this->uuid($installationId);
        if($rows===[]||count($rows)>1000)throw new InvalidArgumentException('invalid_biography_batch');
        $validated=[];$global=[];$seen=[];
        foreach($rows as$row){
            if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('invalid_biography_row');
            $scope=$row['scope']??'installation';
            if(!in_array($scope,['installation','global'],true))throw new InvalidArgumentException('invalid_biography_scope');
            foreach(['content_file'=>256,'record_id'=>256,'name'=>256,'core'=>16384]as$field=>$limit){
                $optional=$field==='core'||($scope==='global'&&in_array($field,['content_file','record_id'],true));
                $this->boundedString($row,$field,$optional?0:1,$limit);
                if((!$optional&&trim($row[$field])==='')||str_contains($row[$field],"\0"))throw new InvalidArgumentException('invalid_'.$field);
            }
            if($scope==='global'&&trim($row['content_file'])!=='')throw new InvalidArgumentException('invalid_global_biography_identity');
            foreach(['biography','appearance','personality','relationships','occupation','skills','speech_style','goals']as$field){
                $value=$row[$field]??null;
                if(!is_string($value)||strlen($value)>16384||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0"))
                    throw new InvalidArgumentException('invalid_biography_'.$field);
            }
            foreach(['oghma_tags'=>4096,'voice_id'=>512,'gender'=>256,'race'=>256]as$field=>$limit){
                $value=$row[$field]??null;
                if(!is_string($value)||strlen($value)>$limit||!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0"))
                    throw new InvalidArgumentException('invalid_biography_'.$field);
            }
            $relationships=trim($row['relationships']);
            if($relationships==='')$relationships='{}';
            try{$relationshipObject=json_decode($relationships,false,64,JSON_THROW_ON_ERROR);}
            catch(\JsonException){throw new InvalidArgumentException('invalid_biography_relationships');}
            if(!$relationshipObject instanceof \stdClass||count(get_object_vars($relationshipObject))>16)
                throw new InvalidArgumentException('invalid_biography_relationships');
            $row['relationships']=json_encode($relationshipObject,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $row['oghma_tags']=$this->normalizeProfileTags($row['oghma_tags']);
            $key=$scope==='global'?'global:'.trim($row['name']):'installation:'.mb_strtolower(trim($row['content_file']),'UTF-8')."\0".mb_strtolower(trim($row['record_id']),'UTF-8');
            if(isset($seen[$key]))throw new InvalidArgumentException('duplicate_biography_identity');
            $seen[$key]=true;
            if($scope==='global'){
                $values=[];
                foreach(['name'=>'npc_name','record_id'=>'refid','core'=>'core','biography'=>'npc_static_bio',
                    'appearance'=>'appearance','personality'=>'personality','relationships'=>'relationships',
                    'occupation'=>'occupation','skills'=>'skills','speech_style'=>'speechstyle','goals'=>'goals',
                    'oghma_tags'=>'oghma_knowledge_tags','voice_id'=>'voiceid','gender'=>'gender','race'=>'race']as$from=>$to)$values[$to]=$row[$from];
                $global[]=$values;continue;
            }
            $content=[];
            foreach(['core','biography','appearance','personality','relationships','occupation','skills','speech_style','goals','gender','race']as$field){
                $value=trim($row[$field]);if($value!=='')$content[$field]=$value;
            }
            if($row['oghma_tags']!=='')$content['oghma_knowledge_tags']=$row['oghma_tags'];
            $voice=trim($row['voice_id']);if($voice!=='')$content['voice']=['id'=>$voice,'language'=>'en'];
            $this->validateProfile($content);
            $validated[]=['content_file'=>trim($row['content_file']),'record_id'=>trim($row['record_id']),
                'name'=>trim($row['name']),'content'=>$content];
        }
        return$this->repository->transaction(function()use($installationId,$validated,$global):array{
            $saved=$validated===[]?[]:$this->repository->saveBiographyTemplates($installationId,$validated,$this->clock->iso());
            foreach($global as$row)$saved[]=$this->repository->saveBiographyTemplate($row+['installation_id'=>$installationId],true);
            return$saved;
        });
    }

    public function resetItemDescriptions(string $installationId): int
    {
        $this->uuid($installationId);return$this->repository->resetItemDescriptions($installationId,$this->clock->iso());
    }

    /** Normalize comma-delimited Oghma access tags while excluding article-only catalog markers. */
    private function normalizeProfileTags(string $value):string
    {
        $tags=[];
        foreach(preg_split('/\s*[,|;]\s*/u',trim($value))?:[]as$tag){
            $tag=trim($tag);
            if($tag===''||in_array(mb_strtolower($tag,'UTF-8'),['common','esoteric'],true)||in_array($tag,$tags,true))continue;
            $tags[]=$tag;
        }
        return implode(', ',$tags);
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
        if(!in_array($kind,['profile','core_profile','playthrough','prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy','memory_embedding_policy','translation_policy'],true)
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
        if (isset($input['relationship_id'])) {
            $this->uuid((string)$input['relationship_id']);
            if (!is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 1) throw new InvalidArgumentException('invalid_relationship_revision');
            if (isset($input['actor_identity']) && (!is_array($input['actor_identity']) || array_is_list($input['actor_identity']))) throw new InvalidArgumentException('invalid_actor_identity');
        } else {
            $input['actor_identity']=RelationshipIdentity::validate($input['actor_identity']??null);
        }
        foreach (['disposition', 'affinity'] as $field) if (!isset($input[$field]) || !is_int($input[$field]) || $input[$field] < -100 || $input[$field] > 100) throw new InvalidArgumentException('invalid_relationship_value');
        if (!in_array($input['source_mode'] ?? null, ['derived', 'manual'], true)) throw new InvalidArgumentException('invalid_source_mode');
        if (array_key_exists('relationship_type', $input)) $input['relationship_type']=RelationshipType::manual($input['relationship_type']);
        if (isset($input['source_event_id'])) $this->uuid((string)$input['source_event_id']);
        $input['reason']=$input['reason']??'updated';
        $this->boundedString($input,'reason',1,1024);
        return $this->repository->setRelationship($input, $this->clock->iso());
    }

    public function deleteRelationship(string $id,int $expectedRevision):void
    {
        $this->uuid($id);
        if($expectedRevision<1)throw new InvalidArgumentException('invalid_relationship_revision');
        $this->repository->deleteRelationship($id,$this->clock->iso(),$expectedRevision);
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
        return ['schema' => 'lorkhan.playthrough-export.v1', 'exported_at' => $this->clock->iso(),
            'scope' => $scope, 'data' => $this->repository->exportScope($scope)];
    }

    /** @param array<string,mixed> $document */
    public function restorePlaythrough(array $document): array
    {
        $keys=array_keys($document);sort($keys);if($keys!==['data','exported_at','schema','scope']||($document['schema']??null)!=='lorkhan.playthrough-export.v1'||!is_string($document['exported_at'])
            ||!is_array($document['scope'])||array_is_list($document['scope'])||!is_array($document['data'])||array_is_list($document['data']))throw new InvalidArgumentException('invalid_restore');
        $dataKeys=array_keys($document['data']);sort($dataKeys);if($dataKeys!==['memories','narratives','relationships'])throw new InvalidArgumentException('invalid_restore');
        foreach($document['data'] as$rows)if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['memories'] as$r)if(!is_array($r)||!in_array($r['tier']??null,['recent','mid','long'],true)||!is_string($r['content']??null)||!is_array($r['lexical_terms']??null)||!is_string($r['occurred_at']??null))throw new InvalidArgumentException('invalid_restore');
        foreach($document['data']['relationships'] as$r){
            if(!is_array($r)||!is_int($r['disposition']??null)||!is_int($r['affinity']??null)
                ||$r['disposition']<-100||$r['disposition']>100||$r['affinity']<-100||$r['affinity']>100)
                throw new InvalidArgumentException('invalid_restore');
            RelationshipIdentity::validate($r['actor_identity']??null,true);
            RelationshipCustomInfo::validate(array_key_exists('custom_info',$r)?$r['custom_info']:'');
            if(!isset($r['actor_identity']['refnum'])){
                if(!is_string($r['relationship_id']??null))throw new InvalidArgumentException('invalid_restore');
                $this->uuid($r['relationship_id']);
            }
        }
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
        foreach(['topic'=>256,'title'=>256,'content'=>131072,'topic_desc_basic'=>131072]as$field=>$max)$this->boundedString($input,$field,$field==='topic_desc_basic'?0:1,$max);
        foreach(['aliases','knowledge_class','knowledge_class_basic','tags']as$field){$value=$input[$field]??'';
            if(is_array($value)){if(!array_is_list($value))throw new InvalidArgumentException('invalid_'.$field);$items=array_map(static fn(mixed$item):string=>trim((string)$item),$value);$value=implode($field==='aliases'?' | ':',',$items);}
            elseif($field==='aliases'&&is_string($value))$value=preg_replace('/\s*[|;]\s*/u',' | ',$value)??$value;
            if(!is_string($value)||strlen($value)>8192||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_'.$field);$input[$field]=trim($value);}
        if(in_array('common',array_map(static fn(string$value):string=>mb_strtolower(trim($value),'UTF-8'),
            preg_split('/\s*[,|;]\s*/u',$input['knowledge_class'])?:[]),true))throw new InvalidArgumentException('invalid_knowledge_class');
        $input['knowledge_class_basic']=$input['knowledge_class_basic']===''?'common':$input['knowledge_class_basic'];
        $input['category']=trim((string)($input['category']??($input['provenance']['category']??'LORKHAN')));
        if(strlen($input['category'])>128||!mb_check_encoding($input['category'],'UTF-8'))throw new InvalidArgumentException('invalid_category');
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
            unset($content['format']);
            if(array_key_exists('player_mood_prompts',$content))
                $content['player_mood_prompts']=PlayerMoodPolicy::validateTemplates($content['player_mood_prompts']);
            $encoded=json_encode($content,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if(strlen($encoded)>65_536)throw new InvalidArgumentException('invalid_prompt_content');
            return$content;
        }
        if($kind==='action_policy')return$this->validateActionPolicy($content);
        if($kind==='global_settings')return EffectiveSettingsResolver::validateGlobalSettings($content);
        if($kind==='memory_policy')return MemorySummaryPolicy::validate($content);
        if($kind==='memory_embedding_policy')return MemoryEmbeddingPolicy::validate($content);
        if($kind==='translation_policy')return TranslationPolicy::validate($content);
        if($kind!=='provider')return$content;
        return LlmConnector::validate($content);
    }

    /** Validate the editable CHIM-lineage NPC fields while preserving a compact OpenMW profile document. */
    private function validateProfile(array $content):array
    {
        if(array_key_exists('only_diary_access',$content)&&!is_bool($content['only_diary_access']))throw new InvalidArgumentException('invalid_narrator_diary_access');
        if(array_key_exists('hide_from_context',$content)&&!is_bool($content['hide_from_context']))throw new InvalidArgumentException('invalid_narrator_visibility');
        if(array_key_exists('narration_filters',$content))$content['narration_filters']=NarrationTextPolicy::validate($content['narration_filters']);
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
            $content['settings_overrides'] = EffectiveSettingsResolver::validateSettingsOverrides($content['settings_overrides'], true);
        }
        if(array_key_exists('player_elevenlabs',$content))$content['player_elevenlabs']=CloudSpeechConnectorProvider::validatePlayerOverrides($content['player_elevenlabs']);
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
        $definitions=[];foreach($this->repository->actionCatalogDefinitions()as$definition)$definitions[$definition['name']]=$definition;
        foreach(['allowed_actions','denied_actions']as$field){if(!array_key_exists($field,$content))continue;$values=$content[$field];
            if(!is_array($values)||!array_is_list($values)||count($values)>128)throw new InvalidArgumentException('invalid_action_policy');
            foreach($values as$value)if(!is_string($value)||$value===''||strlen($value)>128||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_action_policy');}
        if(isset($content['actions'])){if(!is_array($content['actions'])||array_is_list($content['actions'])||count($content['actions'])>128)throw new InvalidArgumentException('invalid_action_policy');
            foreach($content['actions']as$name=>$value){
                if(!is_string($name)||!isset($definitions[$name]))throw new InvalidArgumentException('invalid_action_policy');
                if(is_bool($value))continue;
                if(!is_array($value)||array_is_list($value))throw new InvalidArgumentException('invalid_action_policy');
                if(array_key_exists('code_name',$value)){
                    $content['actions'][$name]=$this->validateHerikaActionOverride($name,$value,$definitions[$name]);
                    continue;
                }
                $knownOverride=['enabled','display_name','description','confirmation_required','followup_enabled',
                    'allow_followup_action','followup_prompt','cooldown_seconds'];
                if(array_diff(array_keys($value),$knownOverride)!==[]||$value===[]
                    ||(isset($value['enabled'])&&!is_bool($value['enabled']))
                    ||(isset($value['confirmation_required'])&&!is_bool($value['confirmation_required']))
                    ||(isset($value['followup_enabled'])&&!is_bool($value['followup_enabled']))
                    ||(isset($value['allow_followup_action'])&&!is_bool($value['allow_followup_action']))
                    ||(isset($value['display_name'])&&(!is_string($value['display_name'])||trim($value['display_name'])===''
                        ||mb_strlen($value['display_name'],'UTF-8')>128||!mb_check_encoding($value['display_name'],'UTF-8')))
                    ||(isset($value['description'])&&(!is_string($value['description'])||trim($value['description'])===''
                        ||mb_strlen($value['description'],'UTF-8')>2048||!mb_check_encoding($value['description'],'UTF-8')))
                    ||(isset($value['followup_prompt'])&&(!is_string($value['followup_prompt'])
                        ||mb_strlen($value['followup_prompt'],'UTF-8')>2048||!mb_check_encoding($value['followup_prompt'],'UTF-8')))
                    ||(isset($value['cooldown_seconds'])&&(!is_int($value['cooldown_seconds'])
                        ||$value['cooldown_seconds']<0||$value['cooldown_seconds']>86400))
                    ||(($value['followup_enabled']??false)&&($definitions[$name]['continuation_capable']??false)!==true)
                    ||(($value['allow_followup_action']??false)&&($definitions[$name]['followup_actions_supported']??false)!==true)
                    ||(($definitions[$name]['confirmation_mode']??'optional')==='required'
                        &&array_key_exists('confirmation_required',$value)&&$value['confirmation_required']!==true)
                    ||(($definitions[$name]['confirmation_mode']??'optional')==='none'
                        &&array_key_exists('confirmation_required',$value)&&$value['confirmation_required']!==false))
                    throw new InvalidArgumentException('invalid_action_policy');
                $normalized=[];foreach($value as$key=>$item)$normalized[$key]=is_string($item)?trim($item):$item;
                $content['actions'][$name]=$normalized;
            }}
        return$content;
    }

    /** Validate one complete Herika-shaped override without allowing it to broaden the OpenMW contract. */
    private function validateHerikaActionOverride(string $name,array $value,array $definition):array
    {
        $expected=['code_name','action_name','description','return_message','available_to_npc','available_to_followers',
            'available_to_narrator','is_activated','parameters_json','metadata','game_function','import_version',
            'script_proxy_program'];
        $keys=array_keys($value);sort($keys);sort($expected);
        if($keys!==$expected||$value['code_name']!==$name
            ||!is_string($value['action_name'])||trim($value['action_name'])===''||mb_strlen($value['action_name'],'UTF-8')>128
            ||!mb_check_encoding($value['action_name'],'UTF-8')
            ||!is_string($value['description'])||mb_strlen($value['description'],'UTF-8')>2048||!mb_check_encoding($value['description'],'UTF-8')
            ||!is_string($value['return_message'])||mb_strlen($value['return_message'],'UTF-8')>2048||!mb_check_encoding($value['return_message'],'UTF-8')
            ||!is_bool($value['available_to_npc'])||!is_bool($value['available_to_followers'])
            ||!is_bool($value['available_to_narrator'])||!is_bool($value['is_activated'])||!is_bool($value['game_function'])
            ||!is_array($value['parameters_json'])||($value['parameters_json']!==[]&&array_is_list($value['parameters_json']))
            ||!is_array($value['metadata'])||array_is_list($value['metadata'])
            ||!is_int($value['import_version'])||$value['script_proxy_program']!==null)
            throw new InvalidArgumentException('invalid_action_policy');

        foreach(['available_to_npc','available_to_followers','available_to_narrator','game_function','import_version']as$field)
            if($value[$field]!==$definition[$field])throw new InvalidArgumentException('invalid_action_policy');
        if($value['parameters_json']!=$definition['parameters_json'])throw new InvalidArgumentException('invalid_action_policy');

        $metadata=$value['metadata'];$base=$definition['metadata'];
        $metadataKeys=array_keys($metadata);$baseKeys=array_keys($base);sort($metadataKeys);sort($baseKeys);
        if($metadataKeys!==$baseKeys)throw new InvalidArgumentException('invalid_action_policy');
        foreach($base as$key=>$baseValue){
            if(in_array($key,['custom_config','cooldown_seconds'],true))continue;
            if($metadata[$key]!=$baseValue)throw new InvalidArgumentException('invalid_action_policy');
        }
        $cooldown=$metadata['cooldown_seconds'];$config=$metadata['custom_config'];
        if(!is_int($cooldown)||$cooldown<0||$cooldown>86400||!is_array($config)||array_is_list($config))
            throw new InvalidArgumentException('invalid_action_policy');
        $configKeys=['confirmation_required','followup_enabled','followup_prompt','followup_use_functions_again'];
        if(array_diff(array_keys($config),$configKeys)!==[])throw new InvalidArgumentException('invalid_action_policy');
        foreach(['confirmation_required','followup_enabled','followup_use_functions_again']as$key)
            if(isset($config[$key])&&!is_bool($config[$key]))throw new InvalidArgumentException('invalid_action_policy');
        if(isset($config['followup_prompt'])&&(!is_string($config['followup_prompt'])
            ||mb_strlen($config['followup_prompt'],'UTF-8')>2048||!mb_check_encoding($config['followup_prompt'],'UTF-8')))
            throw new InvalidArgumentException('invalid_action_policy');
        $mode=(string)$definition['confirmation_mode'];
        if(($mode==='required'&&array_key_exists('confirmation_required',$config)&&$config['confirmation_required']!==true)
            ||($mode==='none'&&array_key_exists('confirmation_required',$config)&&$config['confirmation_required']!==false)
            ||(($config['followup_enabled']??false)&&$definition['continuation_capable']!==true)
            ||(($config['followup_enabled']??false)&&trim((string)($config['followup_prompt']??$base['followup']['prompt']??''))==='')
            ||(($config['followup_use_functions_again']??false)&&$definition['followup_actions_supported']!==true))
            throw new InvalidArgumentException('invalid_action_policy');

        $value['action_name']=trim($value['action_name']);
        return$value;
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
