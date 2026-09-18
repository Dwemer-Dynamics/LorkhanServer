<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Named profile presets change editable settings, never identity, prompts or connector assignments. */
final class CoreProfilePreset
{
    private const FIELDS = [
        'context'=>['prompt_timestamp','ground_items_descriptions_only','inventory_items_descriptions_only','power_awareness_enabled','transformation_detection','short_term_in_compact_chat','hide_ambient_combat','detect_magic_events','item_pickup_min_value','location_blacklist','item_blacklist','magic_effects_blacklist','event_types_excluded','sections','details'],
        'prompt'=>['prompt_head','emote_moods'],
        'response'=>['max_words','core_lang','lang_llm_xtts'],
        'rpg_comments'=>['events','chance_percent'],
        'bored_event'=>['chance_percent'],
        'quest_comments'=>['enabled','chance_percent'],
        'behavior'=>['rechat','rechat_max_depth','rechat_probability_percent','rechat_allow_actions','combat_bark_period_seconds','rechat_mode','open_rechat','rechat_strict_targeting','end_conversation_cooldown_seconds'],
        'relationship'=>['enabled','update_chance_percent'],
        'memory'=>['recent_turn_limit','short_term_enabled','mid_term_enabled','long_term_enabled','short_term_max_summaries','summary_interval','oghma_knowledge_tags'],
        'oghma'=>['enabled','topic_count','result_limit','racial_context_enabled','location_context_enabled','extractor_fallback_enabled','extractor_timeout_ms'],
        'diary'=>['enabled','materialize_enabled','automatic_enabled','automatic_wait_enabled','automatic_interval_seconds','include_in_context','latest_entry_in_context','context_turn_limit','prompt'],
        'profile_evolution'=>['enabled','fields','history_limit'],
        'profile_management'=>['autofill_custom_profiles','autofill_custom_profiles_trigger'],
    ];

    /** Apply the native profile fields shared with CHIM's built-ins; never replace connector or prompt ownership. */
    public static function applyBuiltIn(string $id,array $content):array
    {
        $values=match($id){
            'builtin:default'=>[75,100,50,0,2,50,true,false,50,30,30],
            'builtin:local_llm'=>[20,20,20,60,1,50,false,false,0,30,100],
            'builtin:follower'=>[100,150,100,0,4,60,true,true,75,50,20],
            'builtin:passive'=>[75,100,50,0,1,10,false,false,20,5,120],
            default=>throw new InvalidArgumentException('invalid_builtin_core_preset'),
        };
        [$history,$diaryHistory,$evolutionHistory,$words,$depth,$probability,$actions,$automatic,$rpgChance,$boredChance,$combatCooldown]=$values;
        $evolution=EffectiveSettingsResolver::profileEvolutionDefaults($content['settings_overrides']['profile_evolution']??null);
        $evolution['enabled']=$automatic;$evolution['history_limit']=$evolutionHistory;
        $preset=['schema'=>'lorkhan.named-core-preset.v1','routing'=>['llm_randomizer_enabled'=>false],
            'settings_overrides'=>[
                'response'=>['max_words'=>$words],
                'rpg_comments'=>['chance_percent'=>$rpgChance],
                'bored_event'=>['chance_percent'=>$boredChance],
                'quest_comments'=>['enabled'=>$automatic],
                'memory'=>['recent_turn_limit'=>$history,'mid_term_enabled'=>$automatic],
                'behavior'=>['rechat'=>true,'rechat_max_depth'=>$depth,'rechat_probability_percent'=>$probability,'rechat_allow_actions'=>$actions,'combat_bark_period_seconds'=>$combatCooldown],
                'diary'=>['context_turn_limit'=>$diaryHistory,'automatic_enabled'=>$automatic,
                    'automatic_wait_enabled'=>$automatic,'materialize_enabled'=>$automatic,'latest_entry_in_context'=>$automatic],
                'profile_evolution'=>$evolution,
            ]];
        return self::apply($preset,$content);
    }

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
        $preset['settings_overrides']=EffectiveSettingsResolver::validateSettingsOverrides($preset['settings_overrides']);
        $captured=self::capture($preset);
        if($captured!=$preset) throw new InvalidArgumentException('invalid_named_core_preset');
        return $captured;
    }

    /** Preserve omitted values and all non-preset content while replacing selected field lists as a whole. */
    public static function apply(array $preset,array $content): array
    {
        $preset=self::validate($preset);
        $content['settings_overrides']=EffectiveSettingsResolver::validateSettingsOverrides($content['settings_overrides']??[]);
        foreach($preset['settings_overrides'] as $section=>$values) {
            $content['settings_overrides'][$section]=array_replace($content['settings_overrides'][$section]??[],$values);
        }
        $content['routing']=array_replace($content['routing']??[],$preset['routing']);
        return EffectiveSettingsResolver::validateCoreProfile($content);
    }
}
