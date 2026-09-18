<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Resolve typed settings without collapsing explicit false, zero, or empty-string overrides. */
final class EffectiveSettingsResolver
{
    public const DYNAMIC_PROFILE_FIELDS = ['personality', 'occupation', 'skills', 'speech_style', 'goals'];

    /** Validate discovery defaults and server-only evolution history; neither changes the client contract. */
    public static function profileEvolutionDefaults(mixed $value): array
    {
        if ($value === null) return ['enabled'=>false, 'fields'=>['personality','speech_style','goals']];
        if (!is_array($value)) throw new InvalidArgumentException('invalid_profile_evolution_defaults');
        $keys=array_keys($value);
        if (array_diff($keys,['enabled','fields','history_limit','interval_days','min_events','cooldown_minutes'])!==[] || !is_bool($value['enabled']??null) || !is_array($value['fields']??null)
            || !array_is_list($value['fields']) || $value['fields']===[] || count($value['fields'])>5)
            throw new InvalidArgumentException('invalid_profile_evolution_defaults');
        foreach ($value['fields'] as $field) if (!is_string($field) || !in_array($field,self::DYNAMIC_PROFILE_FIELDS,true))
            throw new InvalidArgumentException('invalid_profile_evolution_defaults');
        if (count(array_unique($value['fields']))!==count($value['fields'])) throw new InvalidArgumentException('invalid_profile_evolution_defaults');
        if(array_key_exists('history_limit',$value)&&(!is_int($value['history_limit'])||$value['history_limit']<0||$value['history_limit']>400))throw new InvalidArgumentException('invalid_profile_evolution_defaults');
        self::validateEvolutionSchedule($value);
        return $value;
    }

    /** Validate server scheduling leaves shared by Core, NPC and Narrator overrides. */
    public static function validateEvolutionSchedule(array $value):void
    {
        foreach(['interval_days'=>[1/24,365],'min_events'=>[1,10000],'cooldown_minutes'=>[1,1440]] as $key=>[$min,$max]){
            if(!array_key_exists($key,$value))continue;
            if((!is_int($value[$key])&&($key!=='interval_days'||!is_float($value[$key])))||!is_finite((float)$value[$key])||$value[$key]<$min||$value[$key]>$max)
                throw new InvalidArgumentException('invalid_profile_evolution_defaults');
        }
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return SettingsCatalog::clientDefaults();
    }

    /** Project internal settings into the unchanged strict v1 controls contract. */
    public static function controlsProjection(array $resolved): array
    {
        $settings = SettingsCatalog::clientDefaults();
        unset($settings['schema']);
        $projectionFields = SettingsCatalog::controlsProjectionFields();
        foreach ($projectionFields as $section => $fields) {
            $allowed = array_fill_keys($fields, true);
            $settings[$section] = array_replace($settings[$section],
                array_intersect_key($resolved['settings'][$section], $allowed));
        }
        // Presentation and remaining compatibility fields stay at protocol defaults.
        // Runtime-owned behavior is projected while local presentation remains authoritative.
        $routing = array_intersect_key($resolved['routing'], array_fill_keys([
            'prompt_configuration_id', 'llm_configuration_id', 'llm_fast_configuration_id',
            'llm_powerful_configuration_id', 'llm_experimental_configuration_id',
            'llm_fallback_configuration_id', 'tts_configuration_id',
            'llm_randomizer_enabled', 'llm_fallback_enabled',
        ], true));
        $routing += ['llm_randomizer_enabled' => false, 'llm_fallback_enabled' => false];
        $sources = [];
        foreach ($projectionFields as $section => $fields) {
            foreach ($fields as $field) {
                $path = 'settings.' . $section . '.' . $field;
                $source = $resolved['sources'][$path] ?? null;
                if (in_array($source, ['default', 'global', 'core_profile', 'npc', 'narrator_profile'], true)) $sources[$path] = $source;
            }
        }
        foreach ($routing as $field => $_) {
            $path = 'routing.' . $field;
            $source = $resolved['sources'][$path] ?? 'default';
            if (in_array($source, ['default', 'global', 'core_profile', 'npc'], true)) $sources[$path] = $source;
        }
        return ['settings' => $settings, 'routing' => $routing, 'source_map' => $sources];
    }

    /**
     * @param array<string,mixed> $globalSettings
     * @param array<string,mixed> $coreProfileContent
     * @param array<string,mixed> $npcProfileContent
     * @return array{document:array<string,mixed>,settings:array<string,mixed>,routing:array<string,mixed>,sources:array<string,string>,sha256:string}
     */
    public function resolve(array $globalSettings, array $coreProfileContent, array $npcProfileContent, array $oghmaGlobal = [], bool $allowProfileTtsRouting = false, array $narratorProfileContent = []): array
    {
        $global = $globalSettings === [] ? SettingsCatalog::globalDefaults() : self::validateGlobalSettings($globalSettings);
        $settings = $global['client'];
        // Narrator automation belongs to the installation Narrator profile. Ignore the
        // legacy copy retained in Global Settings before applying that profile below.
        $settings['narrator'] = SettingsCatalog::clientDefaults()['narrator'];
        $settings['diary'] = DiaryGenerationPolicy::defaults();
        $settings['response'] = ['max_words' => 0];
        $settings['profile_evolution'] = ['history_limit' => 50,'interval_days'=>1,'min_events'=>30,'cooldown_minutes'=>5];
        $settings['profile_management'] = array_intersect_key($global['profile_management'],
            array_flip(['autofill_custom_profiles','autofill_custom_profiles_trigger']));
        $sources = [];
        $this->markLeaves($settings, $globalSettings === [] ? 'default' : 'global', 'settings', $sources);
        $this->markLeaves($settings['narrator'], 'default', 'settings.narrator', $sources);
        $this->markLeaves($settings['diary'], 'default', 'settings.diary', $sources);
        $this->markLeaves($settings['profile_evolution'], 'default', 'settings.profile_evolution', $sources);
        $settings['memory']['short_term_max_summaries'] = 10;
        $sources['settings.memory.short_term_max_summaries'] = 'default';
        $settings['quest_comments'] = $global['quest_comments'];
        foreach (array_keys($settings['quest_comments']) as $field)
            $sources['settings.quest_comments.' . $field] = $globalSettings === [] ? 'default' : 'global';
        $settings['bored_event'] = $global['bored_event'];
        $sources['settings.bored_event.chance_percent'] = $globalSettings === [] ? 'default' : 'global';
        $settings['rpg_comments'] = $global['rpg_comments'];
        foreach (array_keys($settings['rpg_comments']) as $field) {
            $sources['settings.rpg_comments.' . $field] = $globalSettings === [] ? 'default' : 'global';
        }

        $oghmaDocument = is_array($global['oghma'] ?? null) ? $global['oghma'] : [];
        if (($globalSettings['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA) {
            $oghmaDocument = array_replace($oghmaDocument, $oghmaGlobal);
        }
        $settings['memory']['oghma_knowledge_tags'] = trim((string)($oghmaDocument['knowledge_tags'] ?? ''));
        $sources['settings.memory.oghma_knowledge_tags'] = $settings['memory']['oghma_knowledge_tags'] === '' ? 'default' : 'global';
        $settings['oghma'] = array_replace(SettingsCatalog::oghmaDefaults(), array_intersect_key($oghmaDocument, SettingsCatalog::oghmaDefaults()));
        $settings['oghma']['extractor_fallback_enabled'] = ($oghmaDocument['extractor_enabled'] ?? $settings['oghma']['extractor_fallback_enabled']) === true;
        foreach ($settings['oghma'] as $field => $_) {
            $sources['settings.oghma.' . $field] = array_key_exists($field, $oghmaDocument) ? 'global' : 'default';
        }

        $relationship = $global['relationship'];
        $settings['relationship'] = [
            'enabled' => $relationship['enabled'],
            'update_chance_percent' => $relationship['enabled'] ? $relationship['update_chance_percent'] : 0,
            'locked' => false,
        ];
        $this->markLeaves($settings['relationship'], 'global', 'settings.relationship', $sources);

        $routing = [];
        foreach (SettingsCatalog::systemRoutingFields() as $field) {
            $routing[$field] = (string)$global['system_routing'][$field];
            $sources['routing.' . $field] = 'global';
        }

        if (!is_array($coreProfileContent) || ($coreProfileContent !== [] && array_is_list($coreProfileContent))) {
            throw new InvalidArgumentException('invalid_settings_layer');
        }
        $coreOverrides = self::validateSettingsOverrides($coreProfileContent['settings_overrides'] ?? []);
        $allowedOverrides = [];
        foreach (['rechat', 'rechat_max_depth', 'rechat_probability_percent', 'rechat_allow_actions', 'combat_bark_period_seconds', 'rechat_mode',
            'rechat_strict_targeting', 'open_rechat', 'end_conversation_cooldown_seconds'] as $field) {
            if (array_key_exists($field, $coreOverrides['behavior'] ?? [])) $allowedOverrides['behavior'][$field] = $coreOverrides['behavior'][$field];
        }
        foreach (['recent_turn_limit', 'short_term_enabled', 'mid_term_enabled', 'long_term_enabled', 'short_term_max_summaries', 'oghma_knowledge_tags'] as $field) {
            if (array_key_exists($field, $coreOverrides['memory'] ?? [])) $allowedOverrides['memory'][$field] = $coreOverrides['memory'][$field];
        }
        if (isset($coreOverrides['diary'])) $allowedOverrides['diary'] = $coreOverrides['diary'];
        if (isset($coreOverrides['response'])) $allowedOverrides['response'] = $coreOverrides['response'];
        if (isset($coreOverrides['quest_comments'])) $allowedOverrides['quest_comments'] = $coreOverrides['quest_comments'];
        if (isset($coreOverrides['bored_event'])) $allowedOverrides['bored_event'] = $coreOverrides['bored_event'];
        if (isset($coreOverrides['rpg_comments'])) $allowedOverrides['rpg_comments'] = $coreOverrides['rpg_comments'];
        if (isset($coreOverrides['oghma'])) $allowedOverrides['oghma'] = $coreOverrides['oghma'];
        if (isset($coreOverrides['profile_management'])) $allowedOverrides['profile_management'] = $coreOverrides['profile_management'];
        foreach(['history_limit','interval_days','min_events','cooldown_minutes'] as $leaf)
            if (isset($coreOverrides['profile_evolution'][$leaf]))$allowedOverrides['profile_evolution'][$leaf]=$coreOverrides['profile_evolution'][$leaf];
        $this->mergeSettings($settings, $allowedOverrides, 'core_profile', 'settings', $sources);

        $coreRouting = self::validateRouting($coreProfileContent['routing'] ?? []);
        if (($globalSettings['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA) {
            foreach (SettingsCatalog::systemRoutingFields() as $field) {
                if (!array_key_exists($field, $coreRouting)) continue;
                $routing[$field] = $coreRouting[$field];
                $sources['routing.' . $field] = 'core_profile_legacy';
            }
        }
        $relationshipChance=$coreOverrides['relationship']['update_chance_percent']??$relationship['update_chance_percent'];
        if (array_key_exists('enabled', $coreOverrides['relationship'] ?? [])) {
            $settings['relationship']['enabled']=$coreOverrides['relationship']['enabled'];
            $sources['settings.relationship.enabled']='core_profile';
        }
        $settings['relationship']['update_chance_percent']=$settings['relationship']['enabled']?$relationshipChance:0;
        if (array_key_exists('enabled', $coreOverrides['relationship'] ?? [])
            || array_key_exists('update_chance_percent', $coreOverrides['relationship'] ?? [])) {
            $sources['settings.relationship.update_chance_percent'] = 'core_profile';
        }
        $allowedCoreRouting = array_fill_keys(SettingsCatalog::coreRoutingFields(), true);
        foreach (array_intersect_key($coreRouting, $allowedCoreRouting) as $key => $value) {
            $routing[$key] = $value;
            $sources['routing.' . $key] = 'core_profile';
        }

        if (!is_array($npcProfileContent) || ($npcProfileContent !== [] && array_is_list($npcProfileContent))) {
            throw new InvalidArgumentException('invalid_settings_layer');
        }
        $npcOverrides = self::validateSettingsOverrides($npcProfileContent['settings_overrides'] ?? [], true);
        $npcAllowed = [];
        foreach (SettingsCatalog::npcOverrideFields() as $section => $fields) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $npcOverrides[$section] ?? []))
                    $npcAllowed[$section][$field] = $npcOverrides[$section][$field];
            }
        }
        $this->mergeSettings($settings, array_diff_key($npcAllowed, ['context'=>true,'prompt'=>true]), 'npc', 'settings', $sources);
        $relationshipChance=$npcOverrides['relationship']['update_chance_percent']??$relationshipChance;
        $settings['relationship']['update_chance_percent']=$settings['relationship']['enabled']?$relationshipChance:0;
        if (array_key_exists('enabled', $npcOverrides['relationship'] ?? [])
            || array_key_exists('update_chance_percent', $npcOverrides['relationship'] ?? [])) {
            $sources['settings.relationship.update_chance_percent'] = 'npc';
        }
        if (!$settings['oghma']['extractor_fallback_enabled']) $routing['oghma_configuration_id'] = '';
        if ($allowProfileTtsRouting) {
            $profileRouting = self::validateRouting($npcProfileContent['routing'] ?? []);
            foreach (['tts_configuration_id','player_autochat_configuration_id'] as $field) {
                if (!array_key_exists($field, $profileRouting)) continue;
                $routing[$field] = $profileRouting[$field];
                $sources['routing.' . $field] = 'npc';
            }
        }
        if (is_string($npcProfileContent['oghma_knowledge_tags'] ?? null)
            && trim($npcProfileContent['oghma_knowledge_tags']) !== '') {
            $settings['memory']['oghma_knowledge_tags'] = trim($npcProfileContent['oghma_knowledge_tags']);
            $sources['settings.memory.oghma_knowledge_tags'] = 'npc';
        }
        if (array_key_exists('diary', $npcProfileContent)) {
            $profileDiary=DiaryGenerationPolicy::validateOverrides($npcProfileContent['diary']);
            $this->mergeSettings($settings, ['diary'=>$profileDiary], 'npc', 'settings', $sources);
        }
        // The NPC relationship editor owns this leaf separately from generic metadata overrides.
        if (isset($npcProfileContent['relationship']) && is_array($npcProfileContent['relationship'])
            && array_key_exists('locked', $npcProfileContent['relationship'])) {
            $locked=$npcProfileContent['relationship']['locked'];
            if (!is_bool($locked)) throw new InvalidArgumentException('invalid_npc_relationship_override');
            $this->mergeSettings($settings, ['relationship'=>['locked'=>$locked]], 'npc', 'settings', $sources);
        }

        if (!is_array($narratorProfileContent) || ($narratorProfileContent !== [] && array_is_list($narratorProfileContent))) {
            throw new InvalidArgumentException('invalid_settings_layer');
        }
        $narratorMap = [
            'name'=>'name','enabled'=>'enabled','context_visibility'=>'context_visibility','inline_narration_mode'=>'inline_mode',
            'welcome_events'=>'welcome_events','welcome_cooldown_minutes'=>'welcome_cooldown_minutes',
            'random_events'=>'random_events','random_chance_percent'=>'random_chance_percent',
            'random_cooldown_rounds'=>'random_cooldown_rounds','bored_events'=>'bored_events',
            'bored_chance_percent'=>'bored_chance_percent','quest_events'=>'quest_events',
            'quest_chance_percent'=>'quest_chance_percent','quest_cooldown_minutes'=>'quest_cooldown_minutes',
            'book_events'=>'book_events',
        ];
        foreach ($narratorMap as $profileField => $settingsField) {
            if (!array_key_exists($profileField, $narratorProfileContent)) continue;
            $value=$narratorProfileContent[$profileField];$default=SettingsCatalog::clientDefaults()['narrator'][$settingsField];
            if(gettype($value)!==gettype($default))throw new InvalidArgumentException('invalid_settings_layer');
            if($settingsField==='name'&&(trim($value)===''||strlen($value)>256||!mb_check_encoding($value,'UTF-8')))
                throw new InvalidArgumentException('invalid_settings_layer');
            $path='narrator.'.$settingsField;$range=SettingsCatalog::ranges()[$path]??null;
            if($range!==null&&($value<$range[0]||$value>$range[1]))throw new InvalidArgumentException('invalid_settings_layer');
            if($settingsField==='inline_mode'&&!in_array($value,SettingsCatalog::enums()['narrator.inline_mode'],true))
                throw new InvalidArgumentException('invalid_settings_layer');
            $settings['narrator'][$settingsField] = $value;
            $sources['settings.narrator.' . $settingsField] = 'narrator_profile';
        }


        // Rechat is always available; legacy off flags cannot hide the configured rounds and probability.
        $settings['behavior']['rechat'] = true;
        $sources['settings.behavior.rechat'] = 'default';

        $context = array_replace($global['context'], $coreOverrides['context'] ?? [], $npcOverrides['context'] ?? []);
        $this->markLeaves($global['context'], $globalSettings === [] ? 'default' : 'global', 'context', $sources);
        $this->markLeaves($coreOverrides['context'] ?? [], 'core_profile', 'context', $sources);
        $promptSettings = array_replace($global['prompt'], $coreOverrides['prompt'] ?? [], $npcOverrides['prompt'] ?? []);
        $this->markLeaves($global['prompt'], $globalSettings === [] ? 'default' : 'global', 'prompt', $sources);
        $this->markLeaves($coreOverrides['prompt'] ?? [], 'core_profile', 'prompt', $sources);
        $this->markLeaves($npcOverrides['context'] ?? [], 'npc', 'context', $sources);
        $this->markLeaves($npcOverrides['prompt'] ?? [], 'npc', 'prompt', $sources);
        $document = ['schema' => 'lorkhan.effective-settings.v2', 'settings' => $settings, 'routing' => $routing, 'context' => $context, 'prompt'=>$promptSettings];
        return [
            'document' => $document,
            'settings' => $settings,
            'routing' => $routing,
            'context' => $context,
            'prompt' => $promptSettings,
            'sources' => $sources,
            'sha256' => hash('sha256', self::canonical($document)),
        ];
    }

    /** @param array<string,mixed> $content */
    public static function validateCoreProfile(array $content): array
    {
        $keys = array_keys($content);
        sort($keys);
        if ($keys !== ['prompt', 'routing', 'schema', 'settings_overrides']
            || ($content['schema'] ?? null) !== 'lorkhan.core-profile.v1'
            || !is_string($content['prompt'] ?? null)
            || strlen($content['prompt']) > 65_536
            || !mb_check_encoding($content['prompt'], 'UTF-8')) {
            throw new InvalidArgumentException('invalid_core_profile');
        }
        self::validateRouting($content['routing']);
        $content['settings_overrides'] = self::validateSettingsOverrides($content['settings_overrides']);
        return $content;
    }

    /** @param array<string,mixed> $content */
    public static function validateGlobalSettings(array $content): array
    {
        if (($content['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA) {
            // Early v1 documents predate Narrator event chances and cooldowns, just like early v2.
            if (is_array($content['narrator'] ?? null) && !array_is_list($content['narrator'])) {
                $content['narrator'] += SettingsCatalog::clientDefaults()['narrator'];
            }
            $content['behavior'] += ['ai_enabled'=>true];
            self::validateSettingsShape($content, SettingsCatalog::clientDefaults(), false);
            $migrated = SettingsCatalog::globalDefaults();
            $migrated['client'] = $content;
            return $migrated;
        }
        $expected = SettingsCatalog::globalDefaults();
        // Early v2 settings predate automatic profile backfill. Normalize those saved documents
        // to the current v2 defaults before enforcing the otherwise exact settings shape.
        if (($content['schema'] ?? null) === SettingsCatalog::GLOBAL_SCHEMA
            && is_array($content['profile_management'] ?? null) && !array_is_list($content['profile_management'])) {
            $content['profile_management'] += $expected['profile_management'];
            $content += ['quest_comments'=>$expected['quest_comments'], 'bored_event'=>$expected['bored_event'], 'rpg_comments'=>$expected['rpg_comments'], 'prompt'=>$expected['prompt']];
            if(is_array($content['client']['narrator']??null)&&!array_is_list($content['client']['narrator']))
                $content['client']['narrator'] += $expected['client']['narrator'];
        }
        $content += ['backup'=>$expected['backup']];
        self::assertExactKeys($content['backup'], $expected['backup'], 'invalid_global_settings');
        if(!is_int($content['backup']['dragon_break_days'])||$content['backup']['dragon_break_days']<1||$content['backup']['dragon_break_days']>365)throw new InvalidArgumentException('invalid_global_settings');
        $content += ['task_availability'=>$expected['task_availability']];
        self::assertExactKeys($content, $expected, 'invalid_global_settings');
        if (($content['schema'] ?? null) !== SettingsCatalog::GLOBAL_SCHEMA) throw new InvalidArgumentException('invalid_global_settings');
        $content['client']['behavior'] += ['ai_enabled'=>true];
        self::validateSettingsShape($content['client'], SettingsCatalog::clientDefaults(), false);
        if(!is_array($content['task_availability'])||array_is_list($content['task_availability']))throw new InvalidArgumentException('invalid_global_settings');
        $content['task_availability'] += ['scene_classifier'=>true,'director'=>true];
        self::assertExactKeys($content['task_availability'],$expected['task_availability'],'invalid_global_settings');
        foreach($content['task_availability']as$enabled)if(!is_bool($enabled))throw new InvalidArgumentException('invalid_global_settings');
        self::assertExactKeys($content['prompt'], $expected['prompt'], 'invalid_global_settings');
        foreach (['prompt_head'=>8192, 'emote_moods'=>4096] as $field=>$limit) {
            if (!is_string($content['prompt'][$field]) || strlen($content['prompt'][$field])>$limit
                || !mb_check_encoding($content['prompt'][$field], 'UTF-8')) throw new InvalidArgumentException('invalid_global_settings');
        }
        self::assertExactKeys($content['profile_management'], $expected['profile_management'], 'invalid_global_settings');
        if (!is_bool($content['profile_management']['auto_lock_profile'])
            || !is_bool($content['profile_management']['autofill_custom_profiles'])
            || !is_int($content['profile_management']['autofill_custom_profiles_trigger'])
            || $content['profile_management']['autofill_custom_profiles_trigger'] < 10
            || $content['profile_management']['autofill_custom_profiles_trigger'] > 100) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
        self::validateQuestComments($content['quest_comments']);
        self::validateBoredEvent($content['bored_event']);
        self::validateRpgComments($content['rpg_comments']);
        $content['translation'] = TranslationPolicy::validate($content['translation']);
        self::validateGlobalOghma($content['oghma']);
        $content['context'] = self::validateContextPolicy($content['context']);
        if (is_array($content['relationship'] ?? null)) $content['relationship'] += ['worst_memory_lifespan_days'=>7, 'never_clear_relationship_data'=>false];
        self::assertExactKeys($content['relationship'], $expected['relationship'], 'invalid_global_settings');
        if (!is_int($content['relationship']['worst_memory_lifespan_days']) || $content['relationship']['worst_memory_lifespan_days'] < 0 || $content['relationship']['worst_memory_lifespan_days'] > 365
            || !is_bool($content['relationship']['never_clear_relationship_data'])) throw new InvalidArgumentException('invalid_global_settings');
        if (!is_bool($content['relationship']['enabled']) || !is_int($content['relationship']['update_chance_percent'])
            || $content['relationship']['update_chance_percent'] < 0 || $content['relationship']['update_chance_percent'] > 100) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
        // New global-only task routes default to Disabled for older saved documents.
        if(is_array($content['system_routing'])&&!array_is_list($content['system_routing']))$content['system_routing'] += ['background_memory_configuration_id'=>'','scene_classifier_configuration_id'=>'','director_configuration_id'=>''];
        self::assertExactKeys($content['system_routing'], $expected['system_routing'], 'invalid_global_settings');
        foreach (SettingsCatalog::systemRoutingFields() as $field) self::validateUuidOrEmpty($content['system_routing'][$field]);
        return $content;
    }

    /** Validate the shared global/Core policy without treating explicit zero or an empty event list as inheritance. */
    private static function validateRpgComments(mixed $policy, bool $partial = false): array
    {
        if (!is_array($policy) || array_is_list($policy)
            || array_diff(array_keys($policy), ['events', 'chance_percent']) !== []
            || (!$partial && count($policy) !== 2)) {
            throw new InvalidArgumentException('invalid_rpg_comments');
        }
        if (array_key_exists('events', $policy)) {
            $events = $policy['events'];
            if (!is_array($events) || !array_is_list($events)) throw new InvalidArgumentException('invalid_rpg_comments');
            foreach ($events as $event) {
                if (!is_string($event) || !in_array($event, ['levelup', 'combat_end', 'sleep', 'wait'], true))
                    throw new InvalidArgumentException('invalid_rpg_comments');
            }
            if (count($events) !== count(array_unique($events))) throw new InvalidArgumentException('invalid_rpg_comments');
        }
        if (array_key_exists('chance_percent', $policy)
            && (!is_int($policy['chance_percent']) || $policy['chance_percent'] < 0 || $policy['chance_percent'] > 100)) {
            throw new InvalidArgumentException('invalid_rpg_comments');
        }
        return $policy;
    }

    /** Normalize sidecar-backed v1 settings into the single v2 management document. */
    public static function globalDocument(array $content, array $oghma, array $translation, bool $autoLock): array
    {
        $document = $content === [] ? SettingsCatalog::globalDefaults() : self::validateGlobalSettings($content);
        if (($content['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA || $content === []) {
            $document['profile_management']['auto_lock_profile'] = $autoLock;
            $document['translation'] = TranslationPolicy::validate($translation);
            foreach (array_keys($document['oghma']) as $field) {
                if (array_key_exists($field, $oghma)) $document['oghma'][$field] = $oghma[$field];
            }
            $document['oghma']['extractor_fallback_enabled'] = (bool)($oghma['extractor_enabled'] ?? false);
        }
        return self::validateGlobalSettings($document);
    }

    /** Keep Core quest comments separate from narrator routing and its cooldown policy. */
    private static function validateQuestComments(mixed $policy, bool $partial = false): void
    {
        if (!is_array($policy) || array_is_list($policy)
            || array_diff(array_keys($policy), ['enabled','chance_percent']) !== [])
            throw new InvalidArgumentException('invalid_quest_comments');
        if (!$partial && count($policy) !== 2) throw new InvalidArgumentException('invalid_quest_comments');
        if (array_key_exists('enabled', $policy) && !is_bool($policy['enabled']))
            throw new InvalidArgumentException('invalid_quest_comments');
        if (array_key_exists('chance_percent', $policy) && !in_array($policy['chance_percent'], [10,25,50,75,100], true))
            throw new InvalidArgumentException('invalid_quest_comments');
    }

    /** Validate the overall bored opportunity chance independently of narrator routing. */
    private static function validateBoredEvent(mixed $policy): void
    {
        if (!is_array($policy) || array_keys($policy) !== ['chance_percent']
            || !is_int($policy['chance_percent']) || $policy['chance_percent'] < 0
            || $policy['chance_percent'] > 100) throw new InvalidArgumentException('invalid_bored_event');
    }

    /** @param mixed $overrides */
    public static function validateSettingsOverrides(mixed $overrides, bool $npc = false): array
    {
        if (!is_array($overrides) || ($overrides !== [] && array_is_list($overrides)) || array_key_exists('schema', $overrides)) {
            throw new InvalidArgumentException('invalid_settings_overrides');
        }
        if (is_array($overrides['context'] ?? null)) $overrides['context'] = SettingsCatalog::normalizeEventFilter($overrides['context']);
        $validation=$overrides;
        if (array_key_exists('profile_management', $validation)) {
            $policy=$validation['profile_management'];
            if (!is_array($policy) || array_is_list($policy)
                || array_diff(array_keys($policy), ['autofill_custom_profiles','autofill_custom_profiles_trigger'])!==[])
                throw new InvalidArgumentException('invalid_settings_overrides');
            if (array_key_exists('autofill_custom_profiles',$policy) && !is_bool($policy['autofill_custom_profiles']))
                throw new InvalidArgumentException('invalid_settings_overrides');
            if (array_key_exists('autofill_custom_profiles_trigger',$policy)
                && (!is_int($policy['autofill_custom_profiles_trigger']) || $policy['autofill_custom_profiles_trigger']<10 || $policy['autofill_custom_profiles_trigger']>100))
                throw new InvalidArgumentException('invalid_settings_overrides');
            unset($validation['profile_management']);
        }
        if (array_key_exists('context', $validation)) {
            $context = $validation['context'];
            if (!is_array($context) || array_is_list($context)
                || array_diff(array_keys($context), ['prompt_timestamp','ground_items_descriptions_only','inventory_items_descriptions_only','power_awareness_enabled','transformation_detection','short_term_in_compact_chat','hide_ambient_combat','detect_magic_events','item_pickup_min_value','location_blacklist','item_blacklist','magic_effects_blacklist','event_types_excluded','sections','details']) !== [])
                throw new InvalidArgumentException('invalid_settings_overrides');
            foreach ($context as $key=>$value) {
                if (in_array($key,['location_blacklist','item_blacklist','magic_effects_blacklist'],true)) {
                    $overrides['context'][$key]=self::validateTextList($value);
                } elseif (in_array($key,['sections','details'],true)) {
                    $defaults=$key==='sections'?SettingsCatalog::contextSectionDefaults():SettingsCatalog::contextDetailDefaults();
                    if (!is_array($value)||array_is_list($value)||array_diff_key($value,$defaults)!==[]||array_diff_key($defaults,$value)!==[])
                        throw new InvalidArgumentException('invalid_settings_overrides');
                    foreach($value as $selected)if(!is_bool($selected))throw new InvalidArgumentException('invalid_settings_overrides');
                } elseif ($key==='event_types_excluded') {
                    $overrides['context'][$key] = SettingsCatalog::normalizeEventFilter([$key=>$value])[$key];
                } elseif ($key==='item_pickup_min_value') {
                    if(!is_int($value)||$value<0||$value>2147483647)throw new InvalidArgumentException('invalid_settings_overrides');
                } elseif (!is_bool($value)) throw new InvalidArgumentException('invalid_settings_overrides');
            }
            unset($validation['context']);
        }
        if (array_key_exists('prompt', $validation)) {
            $prompt = $validation['prompt'];
            if (!is_array($prompt) || array_is_list($prompt) || array_diff(array_keys($prompt),['prompt_head','emote_moods'])!==[])
                throw new InvalidArgumentException('invalid_settings_overrides');
            foreach($prompt as $field=>$value) if(!is_string($value)||strlen($value)>($field==='prompt_head'?8192:4096)||!mb_check_encoding($value,'UTF-8')) throw new InvalidArgumentException('invalid_settings_overrides');
            unset($validation['prompt']);
        }
        if (array_key_exists('quest_comments', $validation)) {
            self::validateQuestComments($validation['quest_comments'], true);
            unset($validation['quest_comments']);
        }
        if (array_key_exists('bored_event', $validation)) {
            self::validateBoredEvent($validation['bored_event']);
            unset($validation['bored_event']);
        }
        if (array_key_exists('rpg_comments', $validation)) {
            self::validateRpgComments($validation['rpg_comments'], true);
            unset($validation['rpg_comments']);
        }
        if (array_key_exists('profile_evolution', $validation)) {
            if ($npc && is_array($validation['profile_evolution']) && array_diff(array_keys($validation['profile_evolution']),['history_limit','interval_days','min_events','cooldown_minutes'])===[]) {
                $limit = $validation['profile_evolution']['history_limit']??50;
                if (!is_int($limit) || $limit < 0 || $limit > 400) throw new InvalidArgumentException('invalid_settings_overrides');
                self::validateEvolutionSchedule($validation['profile_evolution']);
            } else self::profileEvolutionDefaults($validation['profile_evolution']);
            unset($validation['profile_evolution']);
        }
        // Response length is a server prompt instruction, not an OpenMW client control.
        if (array_key_exists('response', $validation)) {
            $response = $validation['response'];
            if (!is_array($response) || $response === [] || array_diff(array_keys($response), ['max_words', 'core_lang', 'lang_llm_xtts']) !== []
                || (array_key_exists('max_words', $response) && (!is_int($response['max_words']) || $response['max_words'] < 0 || $response['max_words'] > 10000))
                || (array_key_exists('core_lang', $response) && (!is_string($response['core_lang']) || !array_key_exists($response['core_lang'], CoreProfileLanguage::LABELS)))
                || (array_key_exists('lang_llm_xtts', $response) && !is_bool($response['lang_llm_xtts'])))
                throw new InvalidArgumentException('invalid_settings_overrides');
            unset($validation['response']);
        }
        if (array_key_exists('short_term_max_summaries', $validation['memory'] ?? [])) {
            $limit = $validation['memory']['short_term_max_summaries'];
            if (!is_int($limit) || $limit < 1 || $limit > 50) throw new InvalidArgumentException('invalid_settings_overrides');
            unset($validation['memory']['short_term_max_summaries']);
        }
        // Retrieval switches are server-owned and do not enlarge the client controls contract.
        foreach (['short_term_enabled', 'mid_term_enabled', 'long_term_enabled'] as $field) {
            if (!array_key_exists($field, $validation['memory'] ?? [])) continue;
            if (!is_bool($validation['memory'][$field])) throw new InvalidArgumentException('invalid_settings_overrides');
            unset($validation['memory'][$field]);
        }
        if (($validation['memory'] ?? null) === []) unset($validation['memory']);
        if(array_key_exists('relationship',$validation)){
            if (is_array($validation['relationship']) && array_key_exists('enabled', $validation['relationship'])) {
                if (!is_bool($validation['relationship']['enabled'])) throw new InvalidArgumentException('invalid_settings_overrides');
                unset($validation['relationship']['enabled']);
            }
            if ($validation['relationship'] !== []) self::validateSettingsShape(['relationship'=>$validation['relationship']],
                ['relationship'=>['update_chance_percent'=>0,'locked'=>false]],true);
            unset($validation['relationship']);
        }
        if(array_key_exists('diary',$validation)){
            DiaryGenerationPolicy::validateOverrides($validation['diary']);
            unset($validation['diary']);
        }
        if(array_key_exists('oghma_knowledge_tags',$validation['memory']??[])){
            $value=$validation['memory']['oghma_knowledge_tags'];
            if(!is_string($value)||strlen($value)>4096||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_settings_overrides');
            unset($validation['memory']['oghma_knowledge_tags']);if($validation['memory']===[])unset($validation['memory']);
        }
        if (array_key_exists('oghma', $validation)) {
            self::validateOghmaSettings($validation['oghma'], true);
            unset($validation['oghma']);
        }
        self::validateSettingsShape($validation, SettingsCatalog::clientDefaults(), true);
        return $overrides;
    }

    /** Validate the server-only prompt-context policy and canonicalize bounded text lists. */
    private static function validateContextPolicy(mixed $context): array
    {
        $expected = SettingsCatalog::globalDefaults()['context'];
        if (!is_array($context) || array_is_list($context)) throw new InvalidArgumentException('invalid_global_settings');
        $context = SettingsCatalog::normalizeEventFilter($context);
        $context += ['prompt_timestamp' => false, 'ground_items_descriptions_only' => false, 'inventory_items_descriptions_only' => false, 'power_awareness_enabled'=>false, 'transformation_detection'=>true, 'short_term_in_compact_chat'=>true, 'hide_ambient_combat'=>false, 'detect_magic_events'=>true, 'item_pickup_min_value'=>500];
        self::assertExactKeys($context, $expected, 'invalid_global_settings');
        if(!is_int($context['item_pickup_min_value'])||$context['item_pickup_min_value']<0||$context['item_pickup_min_value']>2147483647)throw new InvalidArgumentException('invalid_global_settings');
        if (!is_bool($context['prompt_timestamp']) || !is_bool($context['ground_items_descriptions_only']) || !is_bool($context['inventory_items_descriptions_only']) || !is_bool($context['power_awareness_enabled']) || !is_bool($context['transformation_detection']) || !is_bool($context['short_term_in_compact_chat']) || !is_bool($context['hide_ambient_combat']) || !is_bool($context['detect_magic_events'])) throw new InvalidArgumentException('invalid_global_settings');
        foreach (['sections', 'details'] as $group) {
            if($group==='details'&&is_array($context[$group]))$context[$group]=SettingsCatalog::normalizeContextDetails($context[$group]);
            self::assertExactKeys($context[$group], $expected[$group], 'invalid_global_settings');
            foreach ($context[$group] as $value) if (!is_bool($value)) throw new InvalidArgumentException('invalid_global_settings');
        }
        foreach (['location_blacklist', 'item_blacklist', 'magic_effects_blacklist'] as $field) {
            $context[$field] = self::validateTextList($context[$field]);
        }
        return $context;
    }

    private static function validateGlobalOghma(mixed $settings): void
    {
        $expected = SettingsCatalog::globalDefaults()['oghma'];
        if (!is_array($settings) || array_is_list($settings)) throw new InvalidArgumentException('invalid_global_settings');
        self::assertExactKeys($settings, $expected, 'invalid_global_settings');
        foreach ($settings as $field => $value) {
            if (get_debug_type($value) !== get_debug_type($expected[$field])) throw new InvalidArgumentException('invalid_global_settings');
        }
        if ($settings['topic_count'] < 1 || $settings['topic_count'] > 3
            || $settings['result_limit'] < 1 || $settings['result_limit'] > 5
            || $settings['extractor_timeout_ms'] < 250 || $settings['extractor_timeout_ms'] > 3000
            || strlen($settings['knowledge_tags']) > 4096 || !mb_check_encoding($settings['knowledge_tags'], 'UTF-8')) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
    }

    private static function validateTextList(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 256) throw new InvalidArgumentException('invalid_global_settings');
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw new InvalidArgumentException('invalid_global_settings');
            $value = trim($value);
            if ($value === '') continue;
            if (strlen($value) > 256 || !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('invalid_global_settings');
            $normalized[mb_strtolower($value, 'UTF-8')] = $value;
        }
        return array_values($normalized);
    }

    private static function assertExactKeys(mixed $actual, array $expected, string $error): void
    {
        if (!is_array($actual) || array_is_list($actual)) throw new InvalidArgumentException($error);
        $actualKeys = array_keys($actual); sort($actualKeys);
        $expectedKeys = array_keys($expected); sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) throw new InvalidArgumentException($error);
    }

    private static function validateUuidOrEmpty(mixed $value): void
    {
        if (!is_string($value) || ($value !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1)) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
    }

    private static function validateOghmaSettings(mixed $settings, bool $partial): void
    {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))
            || array_diff(array_keys($settings), array_keys(SettingsCatalog::oghmaDefaults())) !== []) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        foreach ($settings as $field => $value) {
            if (get_debug_type($value) !== get_debug_type(SettingsCatalog::oghmaDefaults()[$field])) {
                throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
            }
            $valid = match ($field) {
                'topic_count' => $value >= 1 && $value <= 3,
                'result_limit' => $value >= 1 && $value <= 5,
                'extractor_timeout_ms' => $value >= 250 && $value <= 3000,
                default => true,
            };
            if (!$valid) throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if (!$partial && count($settings) !== count(SettingsCatalog::oghmaDefaults())) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
    }

    /** @param mixed $routing */
    public static function validateRouting(mixed $routing): array
    {
        $routingTypes = SettingsCatalog::routingTypes();
        if (!is_array($routing) || ($routing !== [] && array_is_list($routing)) || array_diff(array_keys($routing), array_keys($routingTypes)) !== []) {
            throw new InvalidArgumentException('invalid_profile_routing');
        }
        foreach ($routing as $field => $value) {
            if ($routingTypes[$field] === 'bool') {
                if (!is_bool($value)) throw new InvalidArgumentException('invalid_profile_routing');
                continue;
            }
            if (!is_string($value) || ($value !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1)) {
                throw new InvalidArgumentException('invalid_profile_routing');
            }
        }
        return $routing;
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private static function validateSettingsShape(array $actual, array $expected, bool $partial): void
    {
        foreach ($actual as $section => $values) {
            if ($section === 'schema') continue;
            if (!array_key_exists($section, $expected) || !is_array($values) || array_is_list($values)) {
                throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
            }
            foreach ($values as $field => $value) {
                if (!array_key_exists($field, $expected[$section]) || get_debug_type($value) !== get_debug_type($expected[$section][$field])) {
                    throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
                }
                self::validateSettingValue($section, $field, $value, $partial);
            }
            if (!$partial && count($values) !== count($expected[$section])) {
                throw new InvalidArgumentException('invalid_global_settings');
            }
        }
        if (!$partial && count($actual) !== count($expected)) throw new InvalidArgumentException('invalid_global_settings');
    }

    private static function validateSettingValue(string $section, string $field, mixed $value, bool $partial): void
    {
        $ranges = SettingsCatalog::ranges();
        $path = $section . '.' . $field;
        if (isset($ranges[$path]) && ($value < $ranges[$path][0] || $value > $ranges[$path][1])) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if (isset(SettingsCatalog::enums()[$path]) && !in_array($value, SettingsCatalog::enums()[$path], true)) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if ($path === 'narrator.name' && (trim($value) === '' || strlen($value) > 256 || !mb_check_encoding($value, 'UTF-8'))) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if ($path === 'memory.oghma_knowledge_tags' && (strlen($value) > 4096 || !mb_check_encoding($value, 'UTF-8'))) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $overrides @param array<string,string> $sources */
    private function mergeSettings(array &$target, array $overrides, string $source, string $prefix, array &$sources): void
    {
        foreach ($overrides as $section => $values) {
            foreach ($values as $field => $value) {
                $target[$section][$field] = $value;
                $sources[$prefix . '.' . $section . '.' . $field] = $source;
            }
        }
    }

    /** @param array<string,mixed> $value @param array<string,string> $sources */
    private function markLeaves(array $value, string $source, string $prefix, array &$sources): void
    {
        foreach ($value as $key => $child) {
            $path = $prefix . '.' . $key;
            if (is_array($child)) $this->markLeaves($child, $source, $path, $sources);
            else $sources[$path] = $source;
        }
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $child) use (&$sort): mixed {
            if (!is_array($child)) return $child;
            if (array_is_list($child)) return array_map($sort, $child);
            ksort($child, SORT_STRING);
            foreach ($child as &$item) $item = $sort($item);
            unset($item);
            return $child;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
