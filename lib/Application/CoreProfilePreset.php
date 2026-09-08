<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Named profile presets change editable settings, never identity, prompts or connector assignments. */
final class CoreProfilePreset
{
    private const FIELDS = [
        'response'=>['max_words'],
        'behavior'=>['rechat','rechat_max_depth','rechat_probability_percent','rechat_allow_actions'],
        'memory'=>['recent_turn_limit','short_term_enabled','mid_term_enabled','long_term_enabled'],
        'diary'=>['enabled','automatic_enabled','automatic_wait_enabled','automatic_interval_seconds','include_in_context','latest_entry_in_context','context_turn_limit','prompt'],
        'profile_evolution'=>['enabled','fields','history_limit'],
    ];

    public static function capture(array $content): array
    {
        $overrides=EffectiveSettingsResolver::validateSettingsOverrides($content['settings_overrides']??[]);
        $routing=EffectiveSettingsResolver::validateRouting($content['routing']??[]);
        $safe=[];
        foreach(self::FIELDS as $section=>$fields) {
            if(isset($overrides[$section])) $safe[$section]=array_intersect_key($overrides[$section],array_flip($fields));
        }
        return ['schema'=>'lorkhan.named-core-preset.v1','settings_overrides'=>$safe,
            'routing'=>array_intersect_key($routing,array_flip(['llm_randomizer_enabled','llm_fallback_enabled']))];
    }

    public static function validate(array $preset): array
    {
        $keys=array_keys($preset);sort($keys);
        if($keys!==['routing','schema','settings_overrides'] || ($preset['schema']??null)!=='lorkhan.named-core-preset.v1'
            || !is_array($preset['settings_overrides']) || !is_array($preset['routing']))
            throw new InvalidArgumentException('invalid_named_core_preset');
        $captured=self::capture($preset);
        if($captured!=$preset) throw new InvalidArgumentException('invalid_named_core_preset');
        return $captured;
    }

    /** Preserve omitted values and all non-preset content while replacing selected field lists as a whole. */
    public static function apply(array $preset,array $content): array
    {
        $preset=self::validate($preset);
        foreach($preset['settings_overrides'] as $section=>$values) {
            $content['settings_overrides'][$section]=array_replace($content['settings_overrides'][$section]??[],$values);
        }
        $content['routing']=array_replace($content['routing']??[],$preset['routing']);
        EffectiveSettingsResolver::validateCoreProfile($content);
        return $content;
    }
}
