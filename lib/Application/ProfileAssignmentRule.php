<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Portable advanced rule fields; identity, connector IDs and secrets cannot be overwritten. */
final class ProfileAssignmentRule
{
    public const REGEX_FIELDS = ['names','races','genders','record_ids','factions','classes'];

    /** Reference metadata keys with implemented native NPC settings consumers. */
    public const METADATA_FIELDS = [
        'PROMPT_HEAD'=>['prompt','prompt_head','string'],
        'EMOTEMOODS'=>['prompt','emote_moods','string'],
        'RECHAT_H'=>['behavior','rechat_max_depth','integer'],
        'RECHAT_P'=>['behavior','rechat_probability_percent','integer'],
        'RECHAT_ALLOW_ACTIONS'=>['behavior','rechat_allow_actions','boolean'],
        'RECHAT_MODE'=>['behavior','rechat_mode','string'],
        'ENFORCE_STRICT_RECHAT_RESPONSE'=>['behavior','rechat_strict_targeting','boolean'],
        'OPEN_RECHAT'=>['behavior','open_rechat','boolean'],
        'END_CONVERSATION_COOLDOWN'=>['behavior','end_conversation_cooldown_seconds','integer'],
        'COMBAT_BARK_COOLDOWN'=>['behavior','combat_bark_period_seconds','integer'],
        'CONTEXT_HISTORY'=>['memory','recent_turn_limit','integer'],
        'CONTEXT_HISTORY_DIARY'=>['diary','context_turn_limit','integer'],
        'CONTEXT_HISTORY_DYNAMIC_PROFILE'=>['profile_evolution','history_limit','integer'],
        'DIARY_PROMPT'=>['diary','prompt','string'],
        'DIARY_COOLDOWN'=>['diary','automatic_interval_seconds','integer'],
        'CORE_LANG'=>['response','core_lang','string'],
        'LANG_LLM_XTTS'=>['response','lang_llm_xtts','boolean'],
        'MAX_WORDS_LIMIT'=>['response','max_words','integer'],
        'BORED_EVENT'=>['bored_event','chance_percent','integer'],
        'QUEST_COMMENT'=>['quest_comments','enabled','boolean'],
        'QUEST_COMMENT_CHANCE'=>['quest_comments','chance_percent','percent'],
        'AUTOFILL_CUSTOM_PROFILES'=>['profile_management','autofill_custom_profiles','boolean'],
        'AUTOFILL_CUSTOM_PROFILES_TRIGGER'=>['profile_management','autofill_custom_profiles_trigger','integer'],
        'PROMPT_TIMESTAMP'=>['context','prompt_timestamp','boolean'],
        'GROUND_ITEMS_DESCRIPTIONS_ONLY'=>['context','ground_items_descriptions_only','boolean'],
        'INVENTORY_ITEMS_DESCRIPTIONS_ONLY'=>['context','inventory_items_descriptions_only','boolean'],
        'SHORT_TERM_MEMORY_IN_COMPACT_CHAT'=>['context','short_term_in_compact_chat','boolean'],
        'TRANSFORMATION_DETECTION'=>['context','transformation_detection','boolean'],
        'POWER_AWARENESS_ENABLED'=>['context','power_awareness_enabled','boolean'],
        'HIDE_AMBIENT_COMBAT'=>['context','hide_ambient_combat','boolean'],
        'LOCATION_BLACKLIST'=>['context','location_blacklist','list'],
        'ITEM_BLACKLIST'=>['context','item_blacklist','list'],
        'RACIAL_OGHMA'=>['oghma','racial_context_enabled','boolean'],
        'LOCATION_OGHMA'=>['oghma','location_context_enabled','boolean'],
        'OGHMA_AMOUNT'=>['oghma','topic_count','integer'],
        'OGHMA_INFINIUM'=>['oghma','enabled','boolean'],
        'RELATIONSHIP_UPDATE_CHANCE'=>['relationship','update_chance_percent','integer'],
    ];

    public static function normalize(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['regex','action'])) {
            throw new InvalidArgumentException('invalid_advanced_rule');
        }
        $regex = $value['regex'] ?? [];
        if (!is_array($regex) || ($regex !== [] && array_is_list($regex)) || array_diff(array_keys($regex), self::REGEX_FIELDS)) {
            throw new InvalidArgumentException('invalid_rule_regex');
        }
        foreach ($regex as $key => $pattern) {
            if (!is_string($pattern) || strlen($pattern) > 1024 || !mb_check_encoding($pattern, 'UTF-8') || str_contains($pattern, "\0")) {
                throw new InvalidArgumentException('invalid_rule_regex');
            }
            if (trim($pattern) === '') unset($regex[$key]);
        }
        $action = $value['action'] ?? [];
        if (!is_array($action) || ($action !== [] && array_is_list($action))) throw new InvalidArgumentException('invalid_rule_action');
        if (array_key_exists('metadata',$action)) {
            $action['settings_overrides']=self::metadataOverrides($action['metadata'],$action['settings_overrides']??[]);
            unset($action['metadata']);
        }
        // Translate the shared biography field names; other native fields retain their existing schema.
        foreach (['npc_static_bio'=>'biography','speechstyle'=>'speech_style'] as $from=>$to) {
            if (!array_key_exists($from,$action)) continue;
            if (array_key_exists($to,$action)) throw new InvalidArgumentException('duplicate_rule_action_field');
            $action[$to]=$action[$from];unset($action[$from]);
        }
        $textFields=['core','biography','appearance','personality','relationships','occupation','skills','speech_style','goals','oghma_knowledge_tags'];
        if (array_diff(array_keys($action), [...$textFields,'settings_overrides'])) throw new InvalidArgumentException('unsupported_rule_action_field');
        foreach ($textFields as $field) if (isset($action[$field]) && (!is_string($action[$field]) || strlen($action[$field])>8192 || !mb_check_encoding($action[$field],'UTF-8') || str_contains($action[$field],"\0"))) {
            throw new InvalidArgumentException('invalid_rule_action');
        }
        foreach ($action as $value) if ($value === null) throw new InvalidArgumentException('invalid_rule_action');
        if (isset($action['settings_overrides'])) {
            $action['settings_overrides']=EffectiveSettingsResolver::validateSettingsOverrides($action['settings_overrides'],true);
            if ($action['settings_overrides']===[]) unset($action['settings_overrides']);
        }
        if (strlen(json_encode($action,JSON_THROW_ON_ERROR))>24576) throw new InvalidArgumentException('invalid_rule_action');
        return ['regex'=>$regex,'action'=>$action];
    }

    /** Convert reference scalar/list formats, rejecting unknown keys or conflicting native assignments. */
    private static function metadataOverrides(mixed $metadata,mixed $native):array
    {
        if (!is_array($metadata) || ($metadata!==[]&&array_is_list($metadata))) throw new InvalidArgumentException('invalid_rule_metadata');
        $result=EffectiveSettingsResolver::validateSettingsOverrides($native,true);
        foreach ($metadata as $key=>$value) {
            if (!isset(self::METADATA_FIELDS[$key])) throw new InvalidArgumentException('unsupported_rule_metadata_key');
            [$section,$field,$type]=self::METADATA_FIELDS[$key];
            // Herika does not apply empty metadata, but false and numeric zero are meaningful.
            if ($value===null||$value===''||$value===[]) continue;
            if ($type==='boolean') {
                if (!is_bool($value)) {
                    if (!is_string($value)&&!is_int($value)) throw new InvalidArgumentException('invalid_rule_metadata_value');
                    $value=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
                    if ($value===null) throw new InvalidArgumentException('invalid_rule_metadata_value');
                }
            } elseif ($type==='integer'||$type==='percent') {
                if ($type==='percent'&&is_string($value)) $value=preg_replace('/%$/','',trim($value));
                if (is_string($value)) $value=trim($value);
                if (is_string($value)&&preg_match('/^-?\d{1,9}$/D',$value)) $value=(int)$value;
                if (!is_int($value)) throw new InvalidArgumentException('invalid_rule_metadata_value');
            } elseif ($type==='list') {
                if (is_string($value)) $value=array_values(array_filter(array_map('trim',explode(',',$value)),static fn(string $item):bool=>$item!==''));
                if (!is_array($value)||!array_is_list($value)) throw new InvalidArgumentException('invalid_rule_metadata_value');
            } elseif (!is_string($value)) throw new InvalidArgumentException('invalid_rule_metadata_value');
            if (array_key_exists($field,$result[$section]??[]) && $result[$section][$field]!==$value) throw new InvalidArgumentException('conflicting_rule_metadata');
            $result[$section][$field]=$value;
        }
        return EffectiveSettingsResolver::validateSettingsOverrides($result,true);
    }

    /** Higher-priority rules replace biography fields and merge individual typed settings overrides. */
    public static function apply(array $content, array $action): array
    {
        if (isset($action['settings_overrides'])) {
            foreach ($action['settings_overrides'] as $section=>$fields) {
                if($section==='diary'){
                    // NPC diary fields override settings_overrides in the resolver and own the editor values.
                    $content['diary']=array_replace($content['diary']??[],$fields);
                    foreach(array_keys($fields)as$field)unset($content['settings_overrides']['diary'][$field]);
                    if(($content['settings_overrides']['diary']??null)===[])unset($content['settings_overrides']['diary']);
                }else foreach ($fields as $field=>$value) $content['settings_overrides'][$section][$field]=$value;
            }
            unset($action['settings_overrides']);
        }
        return array_replace($content,$action);
    }
}
