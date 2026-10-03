<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Protocol\PluginContract;
use LorkhanServer\Protocol\ValidationException;
use LorkhanServer\Protocol\Validator;

/**
 * Model exposure of registered addon actions. The model picks one fixed (plugin, action) tuple, request-local actor
 * selectors and typed parameters; it never sees or returns identities, free text, code, paths or URLs. The registry and
 * the terminal-turn revalidation remain authoritative.
 */
final class PluginActionPolicy
{
    /** Per-turn addon tool budget, in plugin ID and manifest order, on top of the built-in contract. */
    public const MAX_PROMPT_ACTIONS = 12;

    /**
     * Observed active turn actors by request-local selector: the addressed actor, its audience and the speaker.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function observedActors(array $payload): array
    {
        $candidates = ['target' => $payload['target'] ?? null, 'speaker' => $payload['speaker'] ?? null];
        foreach (array_values(is_array($payload['audience'] ?? null) ? $payload['audience'] : []) as $index => $actor) {
            $candidates['audience:' . ($index + 1)] = $actor;
        }
        $validator = new Validator();
        $actors = [];
        foreach ($candidates as $selector => $actor) {
            if (!is_array($actor) || !in_array($actor['kind'] ?? null, ['npc', 'creature', 'player'], true)) continue;
            try {
                $validator->identity($actor);
            } catch (ValidationException) {
                continue;
            }
            foreach ($actors as $known) if (PluginContract::sameActor($known, $actor)) continue 2;
            $actors[$selector] = $actor;
        }
        return $actors;
    }

    /**
     * Registry actions the model may propose this turn: allowed by policy, with an observed executor of a registered kind
     * and scope, and every required actor slot fillable from observed actors.
     *
     * @param list<array<string,mixed>> $registered PluginRegistry::actions()
     * @param callable(string,string,int):bool $allowed Effective action policy for (plugin ID, action, tier).
     * @return list<array<string,mixed>>
     */
    public static function definitions(array $registered, array $payload, callable $allowed): array
    {
        $observed = self::observedActors($payload);
        $definitions = [];
        foreach ($registered as $action) {
            if (count($definitions) >= self::MAX_PROMPT_ACTIONS) break;
            $executors = [];
            foreach (self::selectors($observed, $action['executor_kinds']) as $selector) {
                if (!$allowed($action['plugin_id'], $action['name'], $action['tier'], $observed[$selector])) continue;
                $scoped = !isset($action['actors']);
                foreach ($action['actors'] ?? [] as $actor) $scoped = $scoped || PluginContract::sameActor($actor, $observed[$selector]);
                if ($scoped) $executors[] = $selector;
            }
            $targets = $action['target'] === 'none' ? [] : self::selectors($observed, $action['target_kinds']);
            if ($executors === [] || ($action['target'] === 'required' && $targets === [])) continue;
            $actorParameters = [];
            foreach ($action['parameters'] as $spec) {
                if ($spec['type'] !== 'actor') continue;
                $choices = self::selectors($observed, $spec['actor_kinds']);
                if ($choices === [] && $spec['required']) continue 2;
                if ($choices !== []) $actorParameters[$spec['name']] = $choices;
            }
            $definitions[] = ['plugin_id' => $action['plugin_id'], 'plugin_version' => $action['plugin_version'],
                'name' => $action['name'], 'display_name' => $action['display_name'], 'description' => $action['description'],
                'tier' => $action['tier'], 'parameters' => $action['parameters'], 'actor_ids' => $executors,
                'target' => $action['target'], 'target_ids' => $targets, 'actor_parameters' => $actorParameters];
        }
        return $definitions;
    }

    /** @param list<array<string,mixed>> $definitions @return list<array<string,mixed>> Strict structured-output variants. */
    public static function responseSchemas(array $definitions): array
    {
        $variants = [];
        foreach ($definitions as $definition) {
            $properties = [];
            $required = [];
            foreach ($definition['parameters'] as $spec) {
                $schema = match ($spec['type']) {
                    'integer', 'number' => ['type' => $spec['type'], 'minimum' => $spec['minimum'], 'maximum' => $spec['maximum']],
                    'boolean' => ['type' => 'boolean'],
                    'enum' => ['type' => 'string', 'enum' => $spec['values']],
                    default => isset($definition['actor_parameters'][$spec['name']])
                        ? ['type' => 'string', 'enum' => $definition['actor_parameters'][$spec['name']]] : null,
                };
                if ($schema === null) continue;
                $properties[$spec['name']] = $schema;
                if ($spec['required']) $required[] = $spec['name'];
            }
            $base = ['plugin' => ['type' => 'string', 'enum' => [$definition['plugin_id']]],
                'name' => ['type' => 'string', 'enum' => [$definition['name']]],
                'actor_id' => ['type' => 'string', 'enum' => $definition['actor_ids']],
                'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required,
                    'additionalProperties' => false]];
            if ($definition['target'] !== 'required') $variants[] = LlmConnector::objectSchema($base);
            if ($definition['target_ids'] !== []) {
                $variants[] = LlmConnector::objectSchema($base + ['target_id' => ['type' => 'string', 'enum' => $definition['target_ids']]]);
            }
        }
        return $variants;
    }

    /** Compact prompt contract for the exposed addon actions, or an empty string when none are available. */
    public static function contract(array $turn): string
    {
        $definitions = is_array($turn['_plugin_action_definitions'] ?? null) ? $turn['_plugin_action_definitions'] : [];
        if ($definitions === []) return '';
        $observed = self::observedActors(is_array($turn['payload'] ?? null) ? $turn['payload'] : []);
        $labels = [];
        foreach ($observed as $selector => $actor) $labels[] = $selector . ' = ' . self::quoted((string) ($actor['display_name'] ?? $actor['record_id']));
        $rows = [];
        foreach ($definitions as $definition) {
            $signature = [];
            foreach ($definition['parameters'] as $spec) {
                $type = match ($spec['type']) {
                    'integer', 'number' => $spec['type'] . ' ' . $spec['minimum'] . '..' . $spec['maximum'],
                    'enum' => implode('|', $spec['values']),
                    'actor' => implode('|', $definition['actor_parameters'][$spec['name']] ?? []),
                    default => $spec['type'],
                };
                if ($spec['type'] !== 'actor' || isset($definition['actor_parameters'][$spec['name']])) {
                    $signature[] = $spec['name'] . ($spec['required'] ? '' : '?') . ': ' . $type;
                }
            }
            $row = '- plugin ' . self::quoted($definition['plugin_id']) . ' name `' . $definition['name'] . '(' . implode(', ', $signature) . ')` — '
                . self::quoted($definition['display_name']) . ': ' . self::quoted($definition['description'])
                . '; actor_id ' . implode('|', $definition['actor_ids']);
            if ($definition['target_ids'] !== []) {
                $row .= '; target_id ' . ($definition['target'] === 'required' ? 'required' : 'optional') . ' ' . implode('|', $definition['target_ids']);
            }
            $rows[] = $row;
        }
        return "Addon actions: action may instead be an object with exactly plugin, name, actor_id and parameters, plus target_id only where listed. "
            . "Use only the selectors listed for that action, never an actor identity. An addon action is only a request and may be declined or fail; never claim it happened.\n"
            . 'Selectors: ' . implode('; ', $labels) . ".\n" . implode("\n", $rows);
    }

    /**
     * Map one compact model proposal through the exposed definitions and request-local selectors.
     *
     * @return array{plugin_id:string,action:string,actor:array<string,mixed>,target:?array<string,mixed>,parameters:array<string,mixed>}|null
     */
    public static function proposal(array $action, array $turn): ?array
    {
        $keys = array_keys($action);
        sort($keys);
        if (!in_array($keys, [['actor_id', 'name', 'parameters', 'plugin'], ['actor_id', 'name', 'parameters', 'plugin', 'target_id']], true)
            || !is_array($action['parameters']) || ($action['parameters'] !== [] && array_is_list($action['parameters']))) return null;
        $observed = self::observedActors(is_array($turn['payload'] ?? null) ? $turn['payload'] : []);
        foreach (is_array($turn['_plugin_action_definitions'] ?? null) ? $turn['_plugin_action_definitions'] : [] as $definition) {
            if ($definition['plugin_id'] !== $action['plugin'] || $definition['name'] !== $action['name']) continue;
            if (!in_array($action['actor_id'], $definition['actor_ids'], true)) return null;
            $target = null;
            if (array_key_exists('target_id', $action)) {
                if (!in_array($action['target_id'], $definition['target_ids'], true)) return null;
                $target = $observed[$action['target_id']];
            }
            $parameters = $action['parameters'];
            foreach ($definition['parameters'] as $spec) {
                if ($spec['type'] !== 'actor' || !array_key_exists($spec['name'], $parameters)) continue;
                if (!in_array($parameters[$spec['name']], $definition['actor_parameters'][$spec['name']] ?? [], true)) return null;
                $parameters[$spec['name']] = $observed[$parameters[$spec['name']]];
            }
            return ['plugin_id' => $definition['plugin_id'], 'action' => $definition['name'], 'actor' => $observed[$action['actor_id']],
                'target' => $target, 'parameters' => $parameters];
        }
        return null;
    }

    /** True when every actor in a proposal is one of the definition's current observed selectors. */
    public static function observedProposal(array $definition, array $proposal, array $payload): bool
    {
        $observed = self::observedActors($payload);
        $member = static function (mixed $actor, array $selectors) use ($observed): bool {
            if (!is_array($actor)) return false;
            foreach ($selectors as $selector) if (PluginContract::sameActor($observed[$selector], $actor)) return true;
            return false;
        };
        if (!$member($proposal['actor'] ?? null, $definition['actor_ids'])) return false;
        if (($proposal['target'] ?? null) !== null && !$member($proposal['target'], $definition['target_ids'])) return false;
        foreach ($definition['parameters'] as $spec) {
            if ($spec['type'] !== 'actor' || !array_key_exists($spec['name'], (array) ($proposal['parameters'] ?? []))) continue;
            if (!$member($proposal['parameters'][$spec['name']], $definition['actor_parameters'][$spec['name']] ?? [])) return false;
        }
        return true;
    }

    /** @param array<string,array<string,mixed>> $observed @param list<string> $kinds @return list<string> */
    private static function selectors(array $observed, array $kinds): array
    {
        return array_keys(array_filter($observed, static fn(array $actor): bool => in_array($actor['kind'], $kinds, true)));
    }

    /** One-line JSON string so third-party manifest text cannot add prompt structure. */
    private static function quoted(string $text): string
    {
        return json_encode(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '', JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
