<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Own the typed settings, routing, and OpenMW projection contract in one place. */
final class SettingsCatalog
{
    public const CLIENT_SCHEMA = 'lorkhan.client-settings.v1';

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
            'random_events' => false,
            'quest_events' => false,
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
        'tts_configuration_id' => 'uuid_or_empty',
        'llm_randomizer_enabled' => 'bool',
        'llm_fallback_enabled' => 'bool',
    ];

    private const OVERRIDE_BOOLEAN_FIELDS = [
        'behavior' => ['rechat', 'rechat_strict_targeting', 'open_rechat'],
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
        'behavior.combat_bark_period_seconds' => [5, 300],
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

    /** List the server settings projected into the OpenMW controls response. */
    public static function controlsProjectionFields(): array
    {
        return [
            'behavior' => ['rechat', 'rechat_max_depth', 'rechat_probability_percent', 'rechat_mode',
                'rechat_strict_targeting', 'open_rechat', 'end_conversation_cooldown_seconds'],
            'memory' => ['recent_turn_limit'],
        ];
    }

    /** List compatibility paths that remain serialized but have no configurable runtime owner. */
    public static function compatibilityPaths(): array
    {
        return [
            'behavior.auto_greeting', 'behavior.rechat_delay_seconds', 'behavior.rechat_allow_actions',
            'behavior.boredom', 'behavior.boredom_delay_seconds', 'behavior.combat_barks', 'behavior.combat_bark_period_seconds',
            'memory.knowledge_limit', 'narrator.enabled', 'narrator.name', 'narrator.context_visibility', 'narrator.inline_mode',
            'narrator.welcome_events', 'narrator.random_events', 'narrator.quest_events', 'narrator.book_events',
            'presentation.show_status_hud', 'presentation.transcript_rows', 'presentation.tts_volume_boost',
            'safety.actions_enabled', 'safety.allow_hostile', 'safety.allow_creatures',
        ];
    }
}
