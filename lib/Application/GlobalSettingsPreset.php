<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Named presets contain only editable global values, never connector bindings or service addresses. */
final class GlobalSettingsPreset
{
    private const SECTIONS = ['prompt', 'profile_management', 'rpg_comments', 'bored_event', 'quest_comments', 'oghma', 'context', 'relationship'];
    private const BEHAVIOR = ['auto_greeting', 'boredom', 'boredom_delay_seconds', 'combat_barks',
        'combat_bark_period_seconds', 'rechat_mode', 'rechat_strict_targeting', 'open_rechat',
        'rechat_allow_actions', 'end_conversation_cooldown_seconds'];
    private const TRANSLATION = ['translate_text', 'translate_audio', 'save_translated_text', 'source_language', 'target_language'];

    public static function capture(array $settings, array $summary, array $embedding, ?array $profiles = null): array
    {
        $settings = EffectiveSettingsResolver::validateGlobalSettings($settings);
        MemorySummaryPolicy::validate($summary);
        MemoryEmbeddingPolicy::validate($embedding);
        $safe = array_intersect_key($settings, array_flip(self::SECTIONS));
        $safe['client'] = ['behavior' => array_intersect_key($settings['client']['behavior'], array_flip(self::BEHAVIOR))];
        $safe['translation'] = array_intersect_key($settings['translation'], array_flip(self::TRANSLATION));
        return ($profiles === null ? [] : ['profiles' => self::profileSnapshot($profiles)]) +
            ['schema' => $profiles === null ? 'lorkhan.named-global-preset.v1' : 'lorkhan.named-global-preset.v2', 'settings' => $safe,
            'summary' => ['enabled' => $summary['enabled'], 'summary_interval' => $summary['summary_interval'] ?? 0,
                'minimum_events' => $summary['minimum_events'] ?? 4],
            'embedding' => ['enabled' => $embedding['enabled'], 'timeout_ms' => $embedding['timeout_ms']]];
    }

    /** Profile snapshots contain only portable settings keyed by native Core identity, plus a fallback. */
    public static function profileSnapshot(array $snapshot): array
    {
        $keys=array_keys($snapshot);sort($keys);
        if($keys!==['default','items'] || !is_array($snapshot['default']) || !is_array($snapshot['items'])
            || ($snapshot['items']!==[] && array_is_list($snapshot['items']))) throw new InvalidArgumentException('invalid_profile_snapshot');
        $snapshot['default']=CoreProfilePreset::validate($snapshot['default']);
        foreach($snapshot['items'] as $id=>$preset){
            if(!is_string($id)||!\LorkhanServer\Infrastructure\Uuid::isValid($id)||!is_array($preset)) throw new InvalidArgumentException('invalid_profile_snapshot');
            $snapshot['items'][$id]=CoreProfilePreset::validate($preset);
        }
        return $snapshot;
    }

    public static function defaults(): array
    {
        return self::capture(SettingsCatalog::globalDefaults(),
            ['schema' => 'lorkhan.memory-policy.v1', 'enabled' => false, 'provider_configuration_id' => ''],
            MemoryEmbeddingPolicy::defaults());
    }

    /** Apply the shared built-in global switches without replacing addresses, connector routes or user blacklists. */
    public static function applyBuiltIn(string $id,array $settings):array
    {
        if(!in_array($id,['builtin:default','builtin:local_llm'],true))throw new InvalidArgumentException('invalid_quickstart_preset');
        $settings=EffectiveSettingsResolver::validateGlobalSettings($settings);$local=$id==='builtin:local_llm';
        $settings['profile_management']['autofill_custom_profiles']=!$local;
        $settings['relationship']['enabled']=!$local;
        $settings['relationship']['update_chance_percent']=$local?0:50;
        $settings['context']['prompt_timestamp']=false;
        $settings['context']['power_awareness_enabled']=false;
        $settings['context']['transformation_detection']=false;
        $settings['context']['hide_ambient_combat']=$local;
        $settings['context']['ground_items_descriptions_only']=$local;
        $settings['context']['inventory_items_descriptions_only']=$local;
        // These options have direct native prompt consumers; do not conflate history or memory with character subsections.
        $settings['context']['sections']['points_of_interest']=!$local;
        foreach(['world','oghma','nearby_actors','nearby_items'] as $section)$settings['context']['sections'][$section]=true;
        foreach(['npc_group','npc_groups','npc_skills','npc_rpg_skills','nearby_actor_summary','nearby_actor_personality','nearby_actor_appearance',
            'nearby_actor_occupation','nearby_actor_equipment','item_descriptions'] as $detail)$settings['context']['details'][$detail]=!$local;
        $settings['context']['details']['nearby_actor_activity']=true;
        $settings['context']['details']['group_duplicate_items']=$local;
        $settings['context']['details']['npc_equipment']=true;
        $settings['context']['details']['npc_inventory']=!$local;
        return EffectiveSettingsResolver::validateGlobalSettings($settings);
    }

    /** Preserve explicit memory providers; provision only missing bindings when Default enables them. */
    public static function builtInMemory(string $id,array $summary,array $embedding,string $fallbackConnector):array
    {
        if(!in_array($id,['builtin:default','builtin:local_llm'],true))throw new InvalidArgumentException('invalid_quickstart_preset');
        $enabled=$id==='builtin:default';
        $summary['enabled']=$enabled;$embedding['enabled']=$enabled;
        if($enabled&&$summary['provider_configuration_id']==='')$summary['provider_configuration_id']=$fallbackConnector;
        if($enabled&&$embedding['endpoint']==='')$embedding['endpoint']='http://127.0.0.1:8082';
        return ['summary'=>MemorySummaryPolicy::validate($summary),'embedding'=>MemoryEmbeddingPolicy::validate($embedding)];
    }

    /** Merge against current settings so presets cannot silently reset hidden controls or routing. */
    public static function apply(array $preset, array $settings, array $summary, array $embedding): array
    {
        if (!in_array($preset['schema'] ?? null, ['lorkhan.named-global-preset.v1','lorkhan.named-global-preset.v2'], true)
            || !is_array($preset['settings'] ?? null) || !is_array($preset['summary'] ?? null)
            || !is_array($preset['embedding'] ?? null)) throw new InvalidArgumentException('invalid_named_global_preset');
        // Older presets retain the original disabled behavior for added Context controls.
        if (is_array($preset['settings']['context'] ?? null)) {
            $preset['settings']['context']=SettingsCatalog::normalizeEventFilter($preset['settings']['context']);
            $preset['settings']['context'] += ['prompt_timestamp' => false, 'ground_items_descriptions_only' => false, 'inventory_items_descriptions_only' => false];
            if(is_array($preset['settings']['context']['details']??null))$preset['settings']['context']['details']=SettingsCatalog::normalizeContextDetails($preset['settings']['context']['details']);
        }
        if (is_array($preset['settings']['relationship'] ?? null))
            $preset['settings']['relationship'] += ['worst_memory_lifespan_days'=>7,'never_clear_relationship_data'=>false];
        $candidate = array_replace($settings, array_intersect_key($preset['settings'], array_flip(self::SECTIONS)));
        $candidate['client']['behavior'] = array_replace($settings['client']['behavior'], $preset['settings']['client']['behavior'] ?? []);
        $candidate['translation'] = array_replace($settings['translation'], $preset['settings']['translation'] ?? []);
        $summary = array_replace($summary, $preset['summary']);
        $embedding = array_replace($embedding, $preset['embedding']);
        // Round-trip the allowlist as well as validating types; reject unknown or connector-bearing keys.
        $profiles=null;
        if(($preset['schema']??null)==='lorkhan.named-global-preset.v2'){
            if(!is_array($preset['profiles']??null))throw new InvalidArgumentException('invalid_profile_snapshot');
            $profiles=$preset['profiles']=self::profileSnapshot($preset['profiles']);
        }
        $captured = self::capture($candidate, $summary, $embedding, $profiles);
        if ($captured != $preset) throw new InvalidArgumentException('invalid_named_global_preset');
        return ['settings' => $candidate, 'summary' => $summary, 'embedding' => $embedding];
    }
}
