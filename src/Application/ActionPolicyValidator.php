<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

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

        $policy = $this->normalizePolicy($loaded['policy']['content'] ?? null);
        if (!$this->policyAllows($proposal['name'], $proposal['tier'], $policy)) {
            throw new DomainException('action_disabled');
        }

        $schema = $definition['parameter_schema'] ?? null;
        if (!is_array($schema) || !$this->matchesSchema($proposal['parameters'], $schema)) {
            throw new DomainException('action_parameters_invalid');
        }

        return [
            'name' => $proposal['name'],
            'tier' => $proposal['tier'],
            'actor' => $proposal['actor'],
            'target' => $proposal['target'],
            'parameters' => $proposal['parameters'],
            'client_capability' => $definition['client_capability'],
            'policy_configuration_id' => $loaded['policy']['configuration_id'] ?? null,
            'policy_revision' => isset($loaded['policy']['revision']) ? (int) $loaded['policy']['revision'] : null,
        ];
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
                && $this->policyAllows($definition['name'], $definition['tier'], $policy)) {
                $allowed[] = $definition;
            }
        }
        return $allowed;
    }

    /** Render only server-filtered definitions; old snapshots and rechat fail closed to dialogue. */
    public function promptContract(array $turn): string
    {
        $definitions = $turn['_allowed_action_definitions'] ?? [];
        if (($turn['payload']['ui_source'] ?? null) === 'lorkhan_rechat' || $definitions === []) {
            return 'action must be null. No actions are available for this turn.';
        }
        $actions = [];
        foreach ($definitions as $definition) {
            $actions[] = $definition['name'] . ' parameters: '
                . json_encode($definition['parameter_schema'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        return 'action must be null or an object with exactly name and parameters; the server adds actor, target, and tier. '
            . 'Only the following actions are allowed. Parameters must match the listed JSON schemas. '
            . implode('; ', $actions);
    }

    /** @return array{enabled:bool,max_tier:int,allow:?list<string>,deny:list<string>} */
    private function normalizePolicy(mixed $content): array
    {
        if ($content === null) {
            return ['enabled' => true, 'max_tier' => 3, 'allow' => null, 'deny' => []];
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

        if (array_key_exists('actions', $content)) {
            if (!is_array($content['actions']) || array_is_list($content['actions'])) {
                throw new InvalidArgumentException('invalid_action_policy');
            }
            foreach ($content['actions'] as $name => $allowed) {
                if (!is_string($name) || $name === '' || !is_bool($allowed)) {
                    throw new InvalidArgumentException('invalid_action_policy');
                }
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
        ];
    }

    /** @param array{enabled:bool,max_tier:int,allow:?list<string>,deny:list<string>} $policy */
    private function policyAllows(string $name, int $tier, array $policy): bool
    {
        return $policy['enabled'] && $tier <= $policy['max_tier']
            && ($policy['allow'] === null || in_array($name, $policy['allow'], true))
            && !in_array($name, $policy['deny'], true);
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
            'maximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'items'];
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
