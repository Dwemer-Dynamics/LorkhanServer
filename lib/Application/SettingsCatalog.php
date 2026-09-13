<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Own the typed settings, routing, and OpenMW projection contract in one place. */
final class SettingsCatalog
{
    public const CLIENT_SCHEMA = 'lorkhan.client-settings.v1';
    public const GLOBAL_SCHEMA = 'lorkhan.global-settings.v2';

    private const CLIENT_DEFAULTS = [
        'schema' => self::CLIENT_SCHEMA,
        'behavior' => [
            'auto_greeting' => false,
            'rechat' => false,
            'rechat_delay_seconds' => 45,
            'rechat_max_depth' => 2,
            'rechat_probability_percent' => 50,
            'rechat_mode' => 'random',
            'rechat_strict_targeting' => false,
            'open_rechat' => true,
            'rechat_allow_actions' => false,
            'end_conversation_cooldown_seconds' => 60,
            'boredom' => false,
            'boredom_delay_seconds' => 180,
            'combat_barks' => false,
            'combat_bark_period_seconds' => 20,
        ],
        'memory' => ['recent_turn_limit' => 20, 'knowledge_limit' => 5],
        'narrator' => [
            'enabled' => false,
            'name' => 'The Narrator',
            'context_visibility' => true,
            'inline_mode' => 'Disabled',
            'welcome_events' => false,
            'welcome_cooldown_minutes' => 10,
            'random_events' => false,
            'random_chance_percent' => 15,
            'random_cooldown_rounds' => 2,
            'bored_events' => false,
            'bored_chance_percent' => 25,
            'quest_events' => false,
            'quest_chance_percent' => 10,
            'quest_cooldown_minutes' => 3,
            'book_events' => false,
        ],
        'presentation' => ['show_status_hud' => true, 'transcript_rows' => 8, 'tts_volume_boost' => 3],
        'safety' => ['actions_enabled' => true, 'allow_hostile' => false, 'allow_creatures' => false],
    ];

    private const OGHMA_DEFAULTS = [
        'enabled' => true,
        'topic_count' => 1,
        'result_limit' => 3,
        'racial_context_enabled' => true,
        'location_context_enabled' => true,
        'extractor_fallback_enabled' => false,
        'extractor_timeout_ms' => 1500,
    ];

    private const CONTEXT_SECTION_DEFAULTS = [
        'player_narrator' => true,
        'world' => true,
        'people_present' => true,
        'nearby_actors' => true,
        'nearby_items' => true,
        'points_of_interest' => true,
        'record_descriptions' => true,
        'oghma' => true,
        'relationships' => true,
        'memories' => true,
        'narratives' => true,
        'conversation_history' => true,
        'recent_action_results' => true,
    ];

    private const CONTEXT_DETAIL_DEFAULTS = [
        'npc_summary' => true,
        'npc_groups' => true,
        'npc_group' => true,
        'npc_personality' => true,
        'npc_appearance' => true,
        'npc_occupation' => true,
        'npc_skills' => true,
        'npc_rpg_skills' => true,
        'npc_speech_style' => true,
        'npc_moods' => true,
        'npc_goals' => true,
        'npc_relationships' => true,
        'npc_notes' => true,
        'npc_race_gender' => true,
        'npc_current_state' => true,
        'npc_equipment' => true,
        'npc_inventory' => true,
        'npc_magic_effects' => false,
        'nearby_actor_summary' => true,
        'nearby_actor_personality' => true,
        'nearby_actor_appearance' => true,
        'nearby_actor_occupation' => true,
        'nearby_actor_activity' => true,
        'nearby_actor_power' => true,
        'nearby_actor_equipment' => true,
        'group_duplicate_items' => true,
        'item_descriptions' => true,
    ];

    private const EVENT_TYPES = [
        'inputtext', 'chat', 'chat_background', 'location', 'weather', 'death',
        'infoaction', 'rechat', 'narration', 'quest', 'book',
    ];

    private const CORE_ROUTING_FIELDS = [
        'prompt_configuration_id', 'llm_configuration_id', 'llm_fast_configuration_id',
        'llm_powerful_configuration_id', 'llm_experimental_configuration_id',
        'llm_fallback_configuration_id', 'diary_generation_configuration_id',
        'player_autochat_configuration_id',
        'tts_configuration_id', 'llm_randomizer_enabled', 'llm_fallback_enabled',
    ];

    private const SYSTEM_ROUTING_FIELDS = [
        'oghma_configuration_id', 'profile_generation_configuration_id', 'relationship_configuration_id', 'background_memory_configuration_id', 'scene_classifier_configuration_id',
    ];

    private const ROUTING_TYPES = [
        'prompt_configuration_id' => 'uuid_or_empty',
        'llm_configuration_id' => 'uuid_or_empty',
        'llm_fast_configuration_id' => 'uuid_or_empty',
        'llm_powerful_configuration_id' => 'uuid_or_empty',
        'llm_experimental_configuration_id' => 'uuid_or_empty',
        'llm_fallback_configuration_id' => 'uuid_or_empty',
        'oghma_configuration_id' => 'uuid_or_empty',
        'profile_generation_configuration_id' => 'uuid_or_empty',
        'relationship_configuration_id' => 'uuid_or_empty',
        'diary_generation_configuration_id' => 'uuid_or_empty',
        'player_autochat_configuration_id' => 'uuid_or_empty',
        'tts_configuration_id' => 'uuid_or_empty',
        'llm_randomizer_enabled' => 'bool',
        'llm_fallback_enabled' => 'bool',
    ];

    private const OVERRIDE_BOOLEAN_FIELDS = [
        'behavior' => ['rechat', 'rechat_strict_targeting', 'open_rechat', 'rechat_allow_actions'],
        'relationship' => ['locked'],
        'diary' => ['enabled', 'include_in_context'],
        'oghma' => ['enabled', 'racial_context_enabled', 'location_context_enabled', 'extractor_fallback_enabled'],
    ];

    private const OVERRIDE_INTEGER_FIELDS = [
        'behavior' => ['rechat_max_depth', 'rechat_probability_percent', 'end_conversation_cooldown_seconds'],
        'relationship' => ['update_chance_percent'],
        'memory' => ['recent_turn_limit'],
        'diary' => ['context_turn_limit'],
        'oghma' => ['topic_count', 'result_limit', 'extractor_timeout_ms'],
    ];

    private const RANGES = [
        'behavior.rechat_delay_seconds' => [30, 3600],
        'behavior.rechat_max_depth' => [1, 20],
        'behavior.rechat_probability_percent' => [0, 100],
        'behavior.end_conversation_cooldown_seconds' => [0, 300],
        'behavior.boredom_delay_seconds' => [30, 86400],
        'bored_event.chance_percent' => [0, 100],
        'behavior.combat_bark_period_seconds' => [5, 600],
        'narrator.welcome_cooldown_minutes' => [1, 1440],
        'narrator.random_chance_percent' => [1, 100],
        'narrator.random_cooldown_rounds' => [0, 10],
        'narrator.bored_chance_percent' => [1, 100],
        'narrator.quest_chance_percent' => [1, 100],
        'narrator.quest_cooldown_minutes' => [1, 60],
        'memory.recent_turn_limit' => [1, 100],
        'memory.knowledge_limit' => [0, 20],
        'relationship.update_chance_percent' => [0, 100],
        'presentation.transcript_rows' => [2, 20],
        'presentation.tts_volume_boost' => [1, 4],
    ];

    private const ENUMS = [
        'behavior.rechat_mode' => ['tight', 'conversational', 'group', 'random'],
        'narrator.inline_mode' => ['Disabled', 'Narrator', 'NPC', 'Text Only'],
    ];

    /** Return the strict wire document retained for OpenMW v1 compatibility. */
    public static function clientDefaults(): array
    {
        return self::CLIENT_DEFAULTS;
    }

    public static function oghmaDefaults(): array
    {
        return self::OGHMA_DEFAULTS;
    }

    /** Return the server-owned Global Settings document used by management and prompt assembly. */
    public static function globalDefaults(): array
    {
        return [
            'schema' => self::GLOBAL_SCHEMA,
            'prompt' => ['prompt_head'=>'', 'emote_moods'=>''],
            'client' => self::CLIENT_DEFAULTS,
            'profile_management' => [
                'auto_lock_profile' => true,
                'autofill_custom_profiles' => true,
                'autofill_custom_profiles_trigger' => 40,
            ],
            'rpg_comments' => ['events'=>['levelup','combat_end'],'chance_percent'=>50],
            'bored_event' => ['chance_percent'=>50],
            'quest_comments' => ['enabled'=>false,'chance_percent'=>10],
            'translation' => TranslationPolicy::defaults(),
            'oghma' => self::OGHMA_DEFAULTS + ['knowledge_tags' => '', 'extractor_enabled' => false],
            'context' => [
                'prompt_timestamp' => false,
                'power_awareness_enabled' => false,
                'hide_ambient_combat' => false,
                'ground_items_descriptions_only' => false,
                'inventory_items_descriptions_only' => false,
                'sections' => self::CONTEXT_SECTION_DEFAULTS,
                'details' => self::CONTEXT_DETAIL_DEFAULTS,
                'event_types' => self::EVENT_TYPES,
                'location_blacklist' => [],
                'item_blacklist' => [],
                'magic_effects_blacklist' => [],
            ],
            'relationship' => ['enabled' => false, 'update_chance_percent' => 0],
            'task_availability' => ['background_memory'=>true, 'profile_generation'=>true, 'scene_classifier'=>true],
            'system_routing' => [
                'oghma_configuration_id' => '',
                'profile_generation_configuration_id' => '',
                'background_memory_configuration_id' => '',
                'scene_classifier_configuration_id' => '',
                'relationship_configuration_id' => '',
            ],
        ];
    }

    public static function contextSectionDefaults(): array
    {
        return self::CONTEXT_SECTION_DEFAULTS;
    }

    public static function contextDetailDefaults(): array
    {
        return self::CONTEXT_DETAIL_DEFAULTS;
    }

    /** Preserve the old combined setting in saved documents, presets and frozen prompt snapshots. */
    public static function normalizeContextDetails(array $details):array
    {
        if(array_key_exists('npc_equipment_inventory',$details)){
            $legacy=$details['npc_equipment_inventory'];
            if(!is_bool($legacy)||array_key_exists('npc_equipment',$details)||array_key_exists('npc_inventory',$details))
                throw new \InvalidArgumentException('invalid_global_settings');
            unset($details['npc_equipment_inventory']);
            $details['npc_equipment']=$legacy;$details['npc_inventory']=$legacy;
        }
        foreach (['npc_moods_goals' => ['npc_moods', 'npc_goals'], 'npc_relationships_notes' => ['npc_relationships', 'npc_notes']] as $old => [$first, $second]) {
            if (!array_key_exists($old, $details)) continue;
            if (!is_bool($details[$old]) || array_key_exists($first, $details) || array_key_exists($second, $details))
                throw new \InvalidArgumentException('invalid_global_settings');
            $details[$first] = $details[$second] = $details[$old];
            unset($details[$old]);
        }
        // Older documents never emitted observed RPG skills; retain that omission.
        if (!array_key_exists('npc_rpg_skills', $details)) $details['npc_rpg_skills'] = false;
        if (!array_key_exists('npc_groups', $details)) $details['npc_groups'] = $details['npc_current_state'] ?? true;
        if (!array_key_exists('npc_group', $details)) $details['npc_group'] = true;
        if (!array_key_exists('nearby_actor_power', $details)) $details['nearby_actor_power'] = true;
        return $details;
    }

    public static function eventTypes(): array
    {
        return self::EVENT_TYPES;
    }

    public static function coreRoutingFields(): array
    {
        return self::CORE_ROUTING_FIELDS;
    }

    public static function systemRoutingFields(): array
    {
        return self::SYSTEM_ROUTING_FIELDS;
    }

    public static function routingTypes(): array
    {
        return self::ROUTING_TYPES;
    }

    public static function overrideBooleanFields(): array
    {
        return self::OVERRIDE_BOOLEAN_FIELDS;
    }

    public static function overrideIntegerFields(): array
    {
        return self::OVERRIDE_INTEGER_FIELDS;
    }

    public static function ranges(): array
    {
        return self::RANGES;
    }

    public static function enums(): array
    {
        return self::ENUMS;
    }

    /** NPC editor leaves with verified per-actor consumers; other settings keep their current owner. */
    public static function npcOverrideFields(): array
    {
        return [
            'bored_event' => ['chance_percent'],
            'quest_comments' => ['enabled','chance_percent'],
            'behavior' => ['rechat', 'rechat_max_depth', 'rechat_probability_percent', 'rechat_allow_actions', 'rechat_mode',
                'rechat_strict_targeting', 'open_rechat', 'end_conversation_cooldown_seconds'],
            'memory' => ['recent_turn_limit', 'short_term_enabled', 'mid_term_enabled', 'long_term_enabled', 'short_term_max_summaries'],
            'response' => ['max_words', 'core_lang', 'lang_llm_xtts'],
            'diary' => ['prompt', 'automatic_interval_seconds', 'context_turn_limit'],
            'profile_evolution' => ['history_limit'],
            'profile_management' => ['autofill_custom_profiles','autofill_custom_profiles_trigger'],
            'context' => ['prompt_timestamp','ground_items_descriptions_only','inventory_items_descriptions_only','power_awareness_enabled','hide_ambient_combat','location_blacklist','item_blacklist','magic_effects_blacklist','event_types','sections','details'],
            'prompt' => ['prompt_head','emote_moods'],
            'oghma' => ['location_context_enabled','topic_count','extractor_fallback_enabled','extractor_timeout_ms','enabled','result_limit','racial_context_enabled'],
            'relationship' => ['enabled','update_chance_percent'],
        ];
    }

    /** List the server settings projected into the OpenMW controls response. */
    public static function controlsProjectionFields(): array
    {
        return [
            'behavior' => ['auto_greeting', 'rechat', 'rechat_max_depth', 'rechat_probability_percent', 'rechat_mode',
                'rechat_strict_targeting', 'open_rechat', 'end_conversation_cooldown_seconds',
                'boredom', 'boredom_delay_seconds', 'combat_barks', 'combat_bark_period_seconds'],
            'memory' => ['recent_turn_limit'],
            'narrator' => ['enabled','name','context_visibility','inline_mode','welcome_events',
                'welcome_cooldown_minutes','random_events','random_chance_percent','random_cooldown_rounds',
                'bored_events','bored_chance_percent','quest_events','quest_chance_percent',
                'quest_cooldown_minutes','book_events'],
        ];
    }

    /** List compatibility paths that remain serialized but have no configurable runtime owner. */
    public static function compatibilityPaths(): array
    {
        return [
            'behavior.rechat_delay_seconds',
            'memory.knowledge_limit',
            'presentation.show_status_hud', 'presentation.transcript_rows', 'presentation.tts_volume_boost',
            'safety.actions_enabled', 'safety.allow_hostile', 'safety.allow_creatures',
        ];
    }
}
