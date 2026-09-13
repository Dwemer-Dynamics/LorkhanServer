<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Portable advanced rule fields; identity, connector IDs and secrets cannot be overwritten. */
final class ProfileAssignmentRule
{
    public const REGEX_FIELDS = ['names','races','genders','record_ids','factions','classes'];

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
        if (isset($action['settings_overrides'])) $action['settings_overrides']=EffectiveSettingsResolver::validateSettingsOverrides($action['settings_overrides'],true);
        if (strlen(json_encode($action,JSON_THROW_ON_ERROR))>24576) throw new InvalidArgumentException('invalid_rule_action');
        return ['regex'=>$regex,'action'=>$action];
    }

    /** Higher-priority rules replace biography fields and merge individual typed settings overrides. */
    public static function apply(array $content, array $action): array
    {
        if (isset($action['settings_overrides'])) {
            foreach ($action['settings_overrides'] as $section=>$fields) {
                foreach ($fields as $field=>$value) $content['settings_overrides'][$section][$field]=$value;
            }
            unset($action['settings_overrides']);
        }
        return array_replace($content,$action);
    }
}
