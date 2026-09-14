<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use DomainException;
use InvalidArgumentException;

/**
 * Validates untrusted proposed actions against DB-loaded catalog, session negotiation, and policy.
 */
final class ActionPolicyValidator
{
    /**
     * @param array<string,mixed> $proposal
     * @param array<string,mixed> $loaded Result of ActionCatalogRepository::loadForSession().
     * @return array<string,mixed> Normalized catalog-backed action.
     */
    public function validate(array $proposal, array $loaded): array
    {
        $this->exactKeys($proposal, ['actor', 'name', 'parameters', 'target', 'tier'], 'provider_invalid_action');
        if (!is_string($proposal['name']) || $proposal['name'] === '' || !is_int($proposal['tier'])
            || !is_array($proposal['parameters']) || ($proposal['parameters']!==[]&&array_is_list($proposal['parameters']))
            || !is_array($proposal['actor']) || array_is_list($proposal['actor'])
            || ($proposal['target'] !== null && (!is_array($proposal['target']) || array_is_list($proposal['target'])))) {
            throw new DomainException('provider_invalid_action');
        }

        $session = $loaded['session'] ?? null;
        $definitions = $loaded['definitions'] ?? null;
        if (!is_array($session) || !is_array($definitions)) {
            throw new InvalidArgumentException('invalid_action_context');
        }

        $definition = null;
        foreach ($definitions as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? null) === $proposal['name']) {
                $definition = $candidate;
                break;
            }
        }
        if ($definition === null) {
            throw new DomainException('action_disabled');
        }

        $capabilities = $this->stringList($session['capabilities'] ?? null, 'invalid_action_context');
        $negotiatedActions = $this->stringList($session['enabled_actions'] ?? null, 'invalid_action_context');
        if (!in_array($proposal['name'], $negotiatedActions, true)
            || !in_array((string) ($definition['client_capability'] ?? ''), $capabilities, true)) {
            throw new DomainException('action_disabled');
        }
        if (($definition['tier'] ?? null) !== $proposal['tier']) {
            throw new DomainException('action_tier_mismatch');
        }
        $actorKind = $proposal['actor']['kind'] ?? null;
        $scopeAllowed = in_array($proposal['name'],AdvancedActionPolicy::NAMES,true)
            ? $actorKind==='player' && ($definition['available_to_narrator']??false)===true
            : ($actorKind === 'narrator'
            ? ($definition['available_to_narrator'] ?? false) === true
            : in_array($actorKind, ['npc', 'creature'], true) && ($definition['available_to_npc'] ?? false) === true);
        if (!$scopeAllowed) {
            throw new DomainException('provider_action_not_allowed');
        }

        $policy = $this->normalizePolicy($loaded['policy']['content'] ?? null);
        if (!$this->policyAllows($proposal['name'], $proposal['tier'], $policy)) {
            throw new DomainException('action_disabled');
        }

        $schema = $definition['parameter_schema'] ?? null;
        if (!is_array($schema) || !$this->matchesSchema($proposal['parameters'], $schema)) {
            throw new DomainException('action_parameters_invalid');
        }
        TransferActionPolicy::validate($proposal, $loaded['turn_payload'] ?? [], $capabilities);
        ServiceActionPolicy::validate($proposal, $loaded['turn_payload'] ?? []);
        SpellActionPolicy::validate($proposal, $loaded['turn_payload'] ?? [], $capabilities);
        AdvancedActionPolicy::validate($proposal, $loaded['turn_payload'] ?? [], $capabilities);
        if(in_array($proposal['name'],AdvancedActionPolicy::NAMES,true)&&isset($loaded['continuation']))throw new DomainException('provider_action_not_allowed');

        $override = $policy['overrides'][$proposal['name']] ?? [];
        $cooldown = (int) ($override['cooldown_seconds'] ?? $definition['cooldown_seconds'] ?? 0);
        if (!$this->cooldownAllows($proposal['name'], $cooldown, $loaded['last_action_at'] ?? [])) {
            throw new DomainException('action_cooldown');
        }
        $continuation = is_array($loaded['continuation'] ?? null) ? $loaded['continuation'] : [];
        $followupDepth = min(1, max(0, (int) ($continuation['depth'] ?? 0)));
        $normalized = [
            'name' => $proposal['name'],
            'tier' => $proposal['tier'],
            'actor' => $proposal['actor'],
            'target' => $proposal['target'],
            'parameters' => $proposal['parameters'],
            'client_capability' => $definition['client_capability'],
            'policy_configuration_id' => $loaded['policy']['configuration_id'] ?? null,
            'policy_revision' => isset($loaded['policy']['revision']) ? (int) $loaded['policy']['revision'] : null,
        ];
        $displayName = (string) ($override['display_name'] ?? $definition['display_name'] ?? $proposal['name']);
        if ($displayName !== $proposal['name'] && in_array('action.confirmation', $capabilities, true)) {
            $normalized['display_name'] = $displayName;
        }
        if (in_array('action.confirmation', $capabilities, true)) {
            $mode=(string)($definition['confirmation_mode']??'optional');
            $normalized['confirmation_required'] = $mode==='required'||($mode==='optional'&&($override['confirmation_required']??false)===true);
        }
        if (in_array('action.result-followup', $capabilities, true)) {
            $normalized['followup_enabled'] = $followupDepth===0&&($definition['continuation_capable'] ?? false) === true
                && ($override['followup_enabled'] ?? $definition['followup_default'] ?? false) === true;
            $normalized['followup_actions_allowed']=$normalized['followup_enabled']
                &&($definition['followup_actions_supported']??false)===true
                &&($override['allow_followup_action']??false)===true;
            $normalized['followup_depth']=$followupDepth;
            if($normalized['followup_enabled'])$normalized['followup_prompt']=(string)
                ($override['followup_prompt']??$definition['followup_prompt']??'');
        }
        $normalized['cooldown_seconds']=$cooldown;
        if (in_array($proposal['name'], TransferActionPolicy::NAMES, true)) $normalized['confirmation_required']=true;
        if ($proposal['name']==='spell.cast'||in_array($proposal['name'],AdvancedActionPolicy::NAMES,true)) $normalized['confirmation_required']=true;
        return $normalized;
    }

    /**
     * Filters DB-loaded definitions for provider prompt/tool exposure.
     *
     * @param array<string,mixed> $loaded
     * @return list<array<string,mixed>>
     */
    public function allowedDefinitions(array $loaded): array
    {
        $session = $loaded['session'] ?? null;
        $definitions = $loaded['definitions'] ?? null;
        if (!is_array($session) || !is_array($definitions)) {
            throw new InvalidArgumentException('invalid_action_context');
        }
        $capabilities = $this->stringList($session['capabilities'] ?? null, 'invalid_action_context');
        $negotiatedActions = $this->stringList($session['enabled_actions'] ?? null, 'invalid_action_context');
        $policy = $this->normalizePolicy($loaded['policy']['content'] ?? null);
        $allowed = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition) || !is_string($definition['name'] ?? null)
                || !is_string($definition['client_capability'] ?? null) || !is_int($definition['tier'] ?? null)) {
                throw new InvalidArgumentException('invalid_action_context');
            }
            if (in_array($definition['name'], $negotiatedActions, true)
                && in_array($definition['client_capability'], $capabilities, true)
                && TransferActionPolicy::available($definition['name'], $loaded['turn_payload'] ?? [], $capabilities)
                && ServiceActionPolicy::available($definition['name'], $loaded['turn_payload'] ?? [])
                && SpellActionPolicy::available($definition['name'], $loaded['turn_payload'] ?? [], $capabilities)
                && AdvancedActionPolicy::available($definition['name'], $loaded['turn_payload'] ?? [], $capabilities)
                && $this->policyAllows($definition['name'], $definition['tier'], $policy)) {
                $override = $policy['overrides'][$definition['name']] ?? [];
                $cooldown=(int)($override['cooldown_seconds']??$definition['cooldown_seconds']??0);
                if(!$this->cooldownAllows($definition['name'],$cooldown,$loaded['last_action_at']??[]))continue;
                $displayName=(string)($override['display_name']??$definition['display_name']??$definition['name']);
                if($displayName!==$definition['name'])$definition['display_name']=$displayName;
                $definition['description'] = (string) ($override['description'] ?? ($definition['description']??''));
                if (in_array('action.confirmation', $capabilities, true)) {
                    $mode=(string)($definition['confirmation_mode']??'optional');
                    $definition['confirmation_required']=$mode==='required'||($mode==='optional'&&($override['confirmation_required']??false)===true);
                }
                if (in_array('action.result-followup', $capabilities, true)) {
                    $definition['followup_enabled'] = ($definition['continuation_capable'] ?? false) === true
                        && ($override['followup_enabled'] ?? $definition['followup_default'] ?? false) === true;
                    $definition['followup_actions_allowed']=$definition['followup_enabled']
                        &&($definition['followup_actions_supported']??false)===true
                        &&($override['allow_followup_action']??false)===true;
                    if($definition['followup_enabled'])$definition['followup_prompt']=(string)
                        ($override['followup_prompt']??$definition['followup_prompt']??'');
                }
                $definition['cooldown_seconds']=$cooldown;
                $allowed[] = $definition;
            }
        }
        return $allowed;
    }

    /** Render only server-filtered definitions; old snapshots and rechat fail closed to dialogue. */
    public function promptContract(array $turn): string
    {
        $cue=ExecutionModePolicy::promptCue($turn['payload']??[]);
        $cue=$cue===''?'':$cue."\n";
        $cue.=AdvancedActionPolicy::prompt($turn['payload']??[]);
        if(ExecutionModePolicy::mode($turn['payload']??[])==='narrator'){
            $executors=$turn['_narrator_action_executors']??[];
            if($executors===[])return $cue.'action must be null. No physical actor actions are available for this turn.';
            $rows=[];
            foreach($executors as $selector=>$executor){
                $rows[]='actor_id '.json_encode($selector,JSON_THROW_ON_ERROR).' = '
                    .json_encode($executor['actor']['display_name'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).':';
                $give=false;$cast=false;
                foreach($executor['definitions'] as $definition){
                    $rows[]='- `'.$definition['name'].$this->parameterSignature((array)$definition['parameter_schema']).'`: '.($definition['description']??'');
                    if(in_array($definition['name'],['item.give','gold.give'],true))$give=true;
                    if($definition['name']==='spell.cast')$cast=true;
                }
                if($give||$cast){
                    $physical=$turn['payload'];$physical['target']=$executor['actor'];$labels=[];
                    foreach(ObservedActionActors::recipients($physical,$cast) as $id=>$actor)
                        $labels[]=$id.' = '.json_encode($actor['display_name'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                    $rows[]='For this actor, recipient_id choices (self is only valid for spell.cast): '.implode('; ',$labels).'.';
                }
            }
            return $cue."action must be null or an object with exactly actor_id, name and parameters. actor_id is required and must use an exact selector below; never emit a full actor identity. World actions, item.give, gold.give and spell.cast may additionally specify recipient_id, outside parameters; omission targets the player. Use self only for spell.cast. Choose only actions listed for the selected actor.\n".implode("\n",$rows);
        }
        $definitions = $turn['_allowed_action_definitions'] ?? [];
        if ($definitions === []) {
            return $cue.'action must be null. No actions are available for this turn.';
        }
        $actions = [];
        foreach ($definitions as $definition) {
            $label = (string) ($definition['display_name'] ?? $definition['name']);
            $description = trim((string) ($definition['description'] ?? ''));
            $contract='- `'.(string)$definition['name'].$this->parameterSignature((array)$definition['parameter_schema']).'`';
            if($label!==$definition['name'])$contract.=' — '.$label;
            if($description!=='')$contract.=': '.$description;
            $actions[]=$contract;
        }
        $recipientHelp='';
        $castAvailable=in_array('spell.cast',array_column($definitions,'name'),true);
        foreach ($definitions as $definition) {
            if (!in_array($definition['name'],['item.give','gold.give','spell.cast'],true)) continue;
            $recipients=ObservedActionActors::recipients($turn['payload']??[],$castAvailable);
            if ($recipients!==[]) {
                $labels=[];
                foreach ($recipients as $selector=>$actor) $labels[]=$selector.' = '.json_encode($actor['display_name'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $recipientHelp="\nIn addition to world actions, item.give, gold.give and spell.cast may add a top-level recipient_id (never inside parameters). "
                    ."Omit it for the current interlocutor. For these physical actions the self selector is only valid for spell.cast. Choose exactly from: ".implode('; ',$labels).'.';
            }
            break;
        }
        return $cue."action must be null or an object with name and parameters; the server adds actor, target, and tier.\n"
            ."Use only these actions and their compact typed parameters:\n".implode("\n",$actions).$recipientHelp;
    }

    /** @return array{enabled:bool,max_tier:int,allow:?list<string>,deny:list<string>,overrides:array<string,array<string,mixed>>} */
    private function normalizePolicy(mixed $content): array
    {
        if ($content === null) {
            return ['enabled' => true, 'max_tier' => 3, 'allow' => null, 'deny' => [], 'overrides' => []];
        }
        if (!is_array($content) || array_is_list($content)) {
            throw new InvalidArgumentException('invalid_action_policy');
        }

        $known = ['enabled', 'max_tier', 'allowed_actions', 'denied_actions', 'actions'];
        foreach (array_keys($content) as $key) {
            if (!in_array($key, $known, true)) {
                throw new InvalidArgumentException('invalid_action_policy');
            }
        }
        $enabled = $content['enabled'] ?? true;
        $maxTier = $content['max_tier'] ?? 3;
        if (!is_bool($enabled) || !is_int($maxTier) || $maxTier < 0 || $maxTier > 3) {
            throw new InvalidArgumentException('invalid_action_policy');
        }
        $allow = array_key_exists('allowed_actions', $content)
            ? $this->stringList($content['allowed_actions'], 'invalid_action_policy') : null;
        $deny = array_key_exists('denied_actions', $content)
            ? $this->stringList($content['denied_actions'], 'invalid_action_policy') : [];

        $overrides = [];
        if (array_key_exists('actions', $content)) {
            if (!is_array($content['actions']) || array_is_list($content['actions'])) {
                throw new InvalidArgumentException('invalid_action_policy');
            }
            foreach ($content['actions'] as $name => $value) {
                if (!is_string($name) || $name === '') {
                    throw new InvalidArgumentException('invalid_action_policy');
                }
                $allowed = $value;
                $hasEnabled = is_bool($value);
                if (is_array($value) && !array_is_list($value)) {
                    if(array_key_exists('code_name',$value)){
                        $override=$this->herikaOverride($name,$value);$hasEnabled=true;$allowed=$override['enabled'];
                        $overrides[$name]=$override;
                    }else{
                        $knownOverride=['enabled','display_name','description','confirmation_required','followup_enabled',
                            'allow_followup_action','followup_prompt','cooldown_seconds'];
                        if(array_diff(array_keys($value),$knownOverride)!==[]
                            ||(isset($value['enabled'])&&!is_bool($value['enabled']))
                            ||(isset($value['confirmation_required'])&&!is_bool($value['confirmation_required']))
                            ||(isset($value['followup_enabled'])&&!is_bool($value['followup_enabled']))
                            ||(isset($value['allow_followup_action'])&&!is_bool($value['allow_followup_action']))
                            ||(isset($value['display_name'])&&!is_string($value['display_name']))
                            ||(isset($value['description'])&&!is_string($value['description']))
                            ||(isset($value['followup_prompt'])&&(!is_string($value['followup_prompt'])
                                ||mb_strlen($value['followup_prompt'],'UTF-8')>2048))
                            ||(isset($value['cooldown_seconds'])&&(!is_int($value['cooldown_seconds'])
                                ||$value['cooldown_seconds']<0||$value['cooldown_seconds']>86400)))
                            throw new InvalidArgumentException('invalid_action_policy');
                        $hasEnabled=array_key_exists('enabled',$value);
                        $allowed = $value['enabled']??true;
                        $overrides[$name] = $value;
                    }
                }
                if (!is_bool($allowed)) throw new InvalidArgumentException('invalid_action_policy');
                if(!$hasEnabled)continue;
                if ($allowed) {
                    $allow ??= [];
                    $allow[] = $name;
                } else {
                    $deny[] = $name;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'max_tier' => $maxTier,
            'allow' => $allow === null ? null : array_values(array_unique($allow)),
            'deny' => array_values(array_unique($deny)),
            'overrides' => $overrides,
        ];
    }

    /** Convert a complete Herika action row into the bounded runtime override fields. */
    private function herikaOverride(string $name,array $value):array
    {
        $expected=['code_name','action_name','description','return_message','available_to_npc','available_to_followers',
            'available_to_narrator','is_activated','parameters_json','metadata','game_function','import_version',
            'script_proxy_program'];
        $keys=array_keys($value);sort($keys);sort($expected);$metadata=$value['metadata']??null;
        if($keys!==$expected||($value['code_name']??null)!==$name||!is_string($value['action_name']??null)
            ||!is_string($value['description']??null)||!is_string($value['return_message']??null)
            ||!is_bool($value['available_to_npc']??null)||!is_bool($value['available_to_followers']??null)
            ||!is_bool($value['available_to_narrator']??null)||!is_bool($value['is_activated']??null)
            ||!is_array($value['parameters_json']??null)||(($value['parameters_json']??[])!==[]&&array_is_list($value['parameters_json']))
            ||!is_bool($value['game_function']??null)||!is_int($value['import_version']??null)
            ||$value['script_proxy_program']!==null||!is_array($metadata)||array_is_list($metadata))
            throw new InvalidArgumentException('invalid_action_policy');
        $config=$metadata['custom_config']??[];$cooldown=$metadata['cooldown_seconds']??0;
        if(!is_array($config)||array_is_list($config)||!is_int($cooldown)||$cooldown<0||$cooldown>86400)
            throw new InvalidArgumentException('invalid_action_policy');
        if(array_diff(array_keys($config),['confirmation_required','followup_enabled','followup_prompt','followup_use_functions_again'])!==[])
            throw new InvalidArgumentException('invalid_action_policy');
        foreach(['confirmation_required','followup_enabled','followup_use_functions_again']as$key)
            if(isset($config[$key])&&!is_bool($config[$key]))throw new InvalidArgumentException('invalid_action_policy');
        if(isset($config['followup_prompt'])&&(!is_string($config['followup_prompt'])
            ||mb_strlen($config['followup_prompt'],'UTF-8')>2048))throw new InvalidArgumentException('invalid_action_policy');
        return[
            'enabled'=>$value['is_activated'],
            'display_name'=>$value['action_name'],
            'description'=>$value['description'],
            'return_message'=>$value['return_message'],
            'confirmation_required'=>$config['confirmation_required']??false,
            'followup_enabled'=>$config['followup_enabled']??false,
            'followup_prompt'=>$config['followup_prompt']??($metadata['followup']['prompt']??''),
            'allow_followup_action'=>$config['followup_use_functions_again']??false,
            'cooldown_seconds'=>$cooldown,
        ];
    }

    /** @param array{enabled:bool,max_tier:int,allow:?list<string>,deny:list<string>,overrides:array<string,array<string,mixed>>} $policy */
    private function policyAllows(string $name, int $tier, array $policy): bool
    {
        return $policy['enabled'] && $tier <= $policy['max_tier']
            && ($policy['allow'] === null || in_array($name, $policy['allow'], true))
            && !in_array($name, $policy['deny'], true);
    }

    /** Render the bounded catalog schema as a compact Markdown signature for the model. */
    private function parameterSignature(array $schema):string
    {
        $properties=is_array($schema['properties']??null)&&!array_is_list($schema['properties'])?$schema['properties']:[];
        if($properties===[])return'()';$required=is_array($schema['required']??null)?$schema['required']:[];$parts=[];
        foreach($properties as$name=>$definition){
            if(!is_array($definition))continue;$type=(string)($definition['type']??'value');
            if(array_key_exists('const',$definition))$type=json_encode($definition['const'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            elseif(is_array($definition['enum']??null))$type=implode('|',array_map('strval',$definition['enum']));
            $parts[]=$name.(in_array($name,$required,true)?'':'?').': '.$type;
        }
        return'('.implode(', ',$parts).')';
    }

    /** Reject an action while its effective per-session cooldown remains active. */
    private function cooldownAllows(string $name,int $seconds,mixed $lastActions):bool
    {
        if($seconds<=0)return true;if(!is_array($lastActions)||!is_string($lastActions[$name]??null))return true;
        try{$last=new \DateTimeImmutable($lastActions[$name]);}catch(\Throwable){throw new InvalidArgumentException('invalid_action_context');}
        return $last->modify('+'.$seconds.' seconds')<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
    }

    /**
     * Deliberately supports the bounded JSON Schema subset stored by this service. Unknown schema
     * keywords fail closed instead of silently broadening an action contract.
     *
     * @param array<string,mixed> $schema
     */
    private function matchesSchema(mixed $value, array $schema): bool
    {
        $supported = ['type', 'properties', 'required', 'additionalProperties', 'const', 'enum', 'minimum',
            'maximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'items'];
        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, $supported, true)) {
                return false;
            }
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) return false;
        if (isset($schema['enum']) && (!is_array($schema['enum']) || !in_array($value, $schema['enum'], true))) return false;
        if (isset($schema['type']) && !$this->matchesType($value, $schema['type'])) return false;

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && (!is_int($schema['minimum']) && !is_float($schema['minimum']) || $value < $schema['minimum'])) return false;
            if (isset($schema['maximum']) && (!is_int($schema['maximum']) && !is_float($schema['maximum']) || $value > $schema['maximum'])) return false;
            if (isset($schema['multipleOf'])) {
                $multiple=$schema['multipleOf'];
                if ((!is_int($multiple)&&!is_float($multiple))||$multiple<=0
                    || abs(($value/$multiple)-round($value/$multiple))>1.0E-9) return false;
            }
        }
        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');
            if (isset($schema['minLength']) && (!is_int($schema['minLength']) || $length < $schema['minLength'])) return false;
            if (isset($schema['maxLength']) && (!is_int($schema['maxLength']) || $length > $schema['maxLength'])) return false;
            if(isset($schema['pattern'])){
                if(!is_string($schema['pattern'])||strlen($schema['pattern'])>512)return false;
                $match=@preg_match('~'.str_replace('~','\\~',$schema['pattern']).'~D',$value);
                if($match!==1)return false;
            }
        }
        if (is_array($value) && array_is_list($value)) {
            $count = count($value);
            if (isset($schema['minItems']) && (!is_int($schema['minItems']) || $count < $schema['minItems'])) return false;
            if (isset($schema['maxItems']) && (!is_int($schema['maxItems']) || $count > $schema['maxItems'])) return false;
            if (isset($schema['items'])) {
                if (!is_array($schema['items']) || array_is_list($schema['items'])) return false;
                foreach ($value as $item) if (!$this->matchesSchema($item, $schema['items'])) return false;
            }
        }
        if (is_array($value) && !array_is_list($value)) {
            $properties = $schema['properties'] ?? [];
            $required = $schema['required'] ?? [];
            if (!is_array($properties) || array_is_list($properties) || !is_array($required) || !array_is_list($required)) return false;
            foreach ($required as $name) if (!is_string($name) || !array_key_exists($name, $value)) return false;
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $name) if (!array_key_exists($name, $properties)) return false;
            } elseif (isset($schema['additionalProperties']) && !is_bool($schema['additionalProperties'])) return false;
            foreach ($value as $name => $child) {
                if (isset($properties[$name])) {
                    if (!is_array($properties[$name]) || array_is_list($properties[$name])
                        || !$this->matchesSchema($child, $properties[$name])) return false;
                }
            }
        }
        return true;
    }

    private function matchesType(mixed $value, mixed $type): bool
    {
        if (!is_string($type)) return false;
        return match ($type) {
            // PHP decodes an empty JSON object as []; treat only that empty value as either container.
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value) && mb_check_encoding($value, 'UTF-8'),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /** @param list<string> $expected */
    private function exactKeys(array $value, array $expected, string $error): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) throw new DomainException($error);
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $error): array
    {
        if (!is_array($value) || !array_is_list($value)) throw new InvalidArgumentException($error);
        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '' || strlen($entry) > 128) throw new InvalidArgumentException($error);
            $result[] = $entry;
        }
        return array_values(array_unique($result));
    }
}
