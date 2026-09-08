<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Named presets contain only editable global values, never connector bindings or service addresses. */
final class GlobalSettingsPreset
{
    private const SECTIONS = ['prompt', 'profile_management', 'rpg_comments', 'oghma', 'context', 'relationship'];
    private const BEHAVIOR = ['auto_greeting', 'boredom', 'boredom_delay_seconds', 'combat_barks',
        'combat_bark_period_seconds', 'rechat_mode', 'rechat_strict_targeting', 'open_rechat',
        'rechat_allow_actions', 'end_conversation_cooldown_seconds'];
    private const TRANSLATION = ['translate_text', 'translate_audio', 'save_translated_text', 'source_language', 'target_language'];

    public static function capture(array $settings, array $summary, array $embedding): array
    {
        $settings = EffectiveSettingsResolver::validateGlobalSettings($settings);
        MemorySummaryPolicy::validate($summary);
        MemoryEmbeddingPolicy::validate($embedding);
        $safe = array_intersect_key($settings, array_flip(self::SECTIONS));
        $safe['client'] = ['behavior' => array_intersect_key($settings['client']['behavior'], array_flip(self::BEHAVIOR))];
        $safe['translation'] = array_intersect_key($settings['translation'], array_flip(self::TRANSLATION));
        return ['schema' => 'lorkhan.named-global-preset.v1', 'settings' => $safe,
            'summary' => ['enabled' => $summary['enabled'], 'summary_interval' => $summary['summary_interval'] ?? 0,
                'minimum_events' => $summary['minimum_events'] ?? 4],
            'embedding' => ['enabled' => $embedding['enabled'], 'timeout_ms' => $embedding['timeout_ms']]];
    }

    public static function defaults(): array
    {
        return self::capture(SettingsCatalog::globalDefaults(),
            ['schema' => 'lorkhan.memory-policy.v1', 'enabled' => false, 'provider_configuration_id' => ''],
            MemoryEmbeddingPolicy::defaults());
    }

    /** Merge against current settings so presets cannot silently reset hidden controls or routing. */
    public static function apply(array $preset, array $settings, array $summary, array $embedding): array
    {
        if (($preset['schema'] ?? null) !== 'lorkhan.named-global-preset.v1'
            || !is_array($preset['settings'] ?? null) || !is_array($preset['summary'] ?? null)
            || !is_array($preset['embedding'] ?? null)) throw new InvalidArgumentException('invalid_named_global_preset');
        // Older presets retain the original disabled behavior for added Context controls.
        if (is_array($preset['settings']['context'] ?? null)) {
            $preset['settings']['context'] += ['prompt_timestamp' => false, 'ground_items_descriptions_only' => false];
        }
        $candidate = array_replace($settings, array_intersect_key($preset['settings'], array_flip(self::SECTIONS)));
        $candidate['client']['behavior'] = array_replace($settings['client']['behavior'], $preset['settings']['client']['behavior'] ?? []);
        $candidate['translation'] = array_replace($settings['translation'], $preset['settings']['translation'] ?? []);
        $summary = array_replace($summary, $preset['summary']);
        $embedding = array_replace($embedding, $preset['embedding']);
        // Round-trip the allowlist as well as validating types; reject unknown or connector-bearing keys.
        $captured = self::capture($candidate, $summary, $embedding);
        if ($captured != $preset) throw new InvalidArgumentException('invalid_named_global_preset');
        return ['settings' => $candidate, 'summary' => $summary, 'embedding' => $embedding];
    }
}
