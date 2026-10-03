<?php

declare(strict_types=1);

namespace LorkhanServer\Protocol;

/**
 * Strict lorkhan.plugin.*.v1 validation for third-party addons.
 *
 * Addons declare bounded typed actions, events and prompt slots. Parameter values are numbers,
 * booleans, declared enum tokens or exact TES3 identities; the model can never supply code,
 * console commands, paths or URLs through this contract.
 */
final class PluginContract
{
    public const CAPABILITY = 'plugin.contract.v1';
    public const API_VERSION = 1;
    public const MANIFEST = 'lorkhan.plugin.manifest.v1';
    public const REGISTRATION = 'lorkhan.plugin.registration.v1';
    public const REGISTRATION_ACCEPTED = 'lorkhan.plugin.registration.accepted.v1';
    public const ACTION_INTENT = 'lorkhan.plugin.action-intent.v1';
    public const EVENT = 'lorkhan.plugin.event.v1';
    public const EVENT_ACCEPTED = 'lorkhan.plugin.event.accepted.v1';
    /** events.v1 discriminator for the variant carrying one ACTION_INTENT (emitted from Stage 3B only). */
    public const INTENT_EVENT_TYPE = 'plugin.action.intent';
    public const PROMPT_SLOTS = ['actor_state', 'player_state', 'scene_notes', 'world_state'];
    public const RESERVED_NAMESPACES = ['builtin', 'core', 'lorkhan', 'morrowind', 'openmw', 'tes3'];
    public const MAX_PLUGINS = 16;
    /** Pinned OpenMW Lua API revision; manifests requiring newer revisions are incompatible until negotiated. */
    public const LUA_API_REVISION = 129;
    public const MAX_MANIFEST_BYTES = 65_536;
    public const MAX_EVENT_BYTES = 16_384;
    private const PLUGIN_ID = '/^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]{1,47}$/D';
    private const VERSION = '/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D';
    private const NAME = '/^[a-z][a-z0-9_]{0,31}$/D';
    private const TOKEN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/D';
    private const REASON = '/^[a-z][a-z0-9_]{0,63}$/D';
    private const SHA256 = '/^[0-9a-f]{64}$/D';
    private const MAX_INTEGER = 2_147_483_647;
    private const MAX_NUMBER = 1_000_000_000;

    public function __construct(private readonly Validator $validator = new Validator())
    {
    }

    /** @param array<string,mixed> $message */
    public function validate(array $message, string $schema): void
    {
        match ($schema) {
            self::MANIFEST => $this->manifest($message),
            self::REGISTRATION => $this->registration($message),
            self::REGISTRATION_ACCEPTED => $this->registrationAccepted($message),
            self::ACTION_INTENT => $this->actionIntent($message),
            self::EVENT => $this->event($message),
            self::EVENT_ACCEPTED => $this->eventAccepted($message),
            default => throw new ValidationException('invalid_schema'),
        };
    }

    /**
     * One events.v1 plugin.action.intent entry: closed envelope plus an intent bound to the same turn/session/generation.
     *
     * @param array<string,mixed> $event
     */
    public function intentEvent(array $event): void
    {
        $this->keys($event, ['message_id', 'request_id', 'turn_id', 'session_id', 'generation', 'sequence', 'created_at', 'type', 'payload']);
        if ($event['type'] !== self::INTENT_EVENT_TYPE || !is_array($event['payload']) || !is_int($event['sequence']) || $event['sequence'] < 0) $this->fail();
        foreach (['message_id', 'request_id', 'turn_id', 'session_id'] as $field) $this->validator->uuid($event[$field]);
        $this->generation($event['generation']);
        $this->validator->timestamp($event['created_at']);
        $this->actionIntent($event['payload']);
        foreach (['turn_id', 'session_id', 'generation'] as $field) if ($event['payload'][$field] !== $event[$field]) $this->fail();
    }

    /** Compare two validated MAJOR.MINOR.PATCH versions numerically. */
    public static function compareVersions(string $left, string $right): int
    {
        return array_map('intval', explode('.', $left)) <=> array_map('intval', explode('.', $right));
    }

    /** True when the version satisfies an inclusive minimum and optional exclusive maximum. */
    public static function versionInRange(string $version, string $minimum, ?string $maximumExclusive): bool
    {
        return self::compareVersions($version, $minimum) >= 0
            && ($maximumExclusive === null || self::compareVersions($version, $maximumExclusive) < 0);
    }

    /** Exact TES3 actor identity: kind, record, content source and file-local RefNum; never display name or cell. */
    public static function sameActor(array $left, array $right): bool
    {
        return ($left['kind'] ?? null) === ($right['kind'] ?? null)
            && strcasecmp((string) ($left['record_id'] ?? ''), (string) ($right['record_id'] ?? '')) === 0
            && strcasecmp((string) ($left['content_file'] ?? ''), (string) ($right['content_file'] ?? '')) === 0
            && ($left['refnum'] ?? null) == ($right['refnum'] ?? null);
    }

    /**
     * Validate supplied values against declared parameter/field specifications.
     *
     * @param list<array<string,mixed>> $specs
     */
    public function values(array $specs, mixed $values): void
    {
        if (!is_array($values) || ($values !== [] && array_is_list($values)) || count($values) > 8) $this->fail();
        $declared = [];
        foreach ($specs as $spec) $declared[$spec['name']] = $spec;
        foreach ($values as $name => $value) {
            if (!isset($declared[$name])) $this->fail();
            $this->value($declared[$name], $value);
        }
        foreach ($declared as $name => $spec) {
            if ($spec['required'] && !array_key_exists($name, $values)) $this->fail();
        }
    }

    /** @param array<string,mixed> $message */
    private function manifest(array $message): void
    {
        $this->keys($message, ['schema', 'plugin_id', 'version', 'api_version', 'display_name', 'description', 'author',
            'compatibility', 'dependencies', 'default_enabled', 'actions', 'events', 'prompt_contributions']);
        if ($message['schema'] !== self::MANIFEST || $message['api_version'] !== self::API_VERSION
            || !is_bool($message['default_enabled'])
            || strlen(json_encode($message, JSON_THROW_ON_ERROR)) > self::MAX_MANIFEST_BYTES) $this->fail();
        $this->pluginId($message['plugin_id']);
        $this->version($message['version']);
        $this->text($message['display_name'], 64);
        $this->text($message['description'], 256);
        $this->text($message['author'], 64);
        $compatibility = $message['compatibility'];
        if (!is_array($compatibility)) $this->fail();
        $this->keys($compatibility, ['product', 'game', 'min_client_version', 'min_server_version', 'lua_api_revision']);
        if ($compatibility['product'] !== 'lorkhan' || $compatibility['game'] !== 'tes3'
            || !is_int($compatibility['lua_api_revision']) || $compatibility['lua_api_revision'] < 129
            || $compatibility['lua_api_revision'] > 1_000_000) $this->fail();
        $this->version($compatibility['min_client_version']);
        $this->version($compatibility['min_server_version']);
        $seen = [];
        foreach ($this->list($message['dependencies'], 8) as $dependency) {
            if (!is_array($dependency)) $this->fail();
            $keys = ['plugin_id', 'min_version'];
            if (array_key_exists('max_version_exclusive', $dependency)) $keys[] = 'max_version_exclusive';
            $this->keys($dependency, $keys);
            $this->pluginId($dependency['plugin_id']);
            $this->version($dependency['min_version']);
            if (isset($dependency['max_version_exclusive'])) {
                $this->version($dependency['max_version_exclusive']);
                if (self::compareVersions($dependency['max_version_exclusive'], $dependency['min_version']) <= 0) $this->fail();
            }
            if ($dependency['plugin_id'] === $message['plugin_id'] || isset($seen[$dependency['plugin_id']])) $this->fail();
            $seen[$dependency['plugin_id']] = true;
        }
        $this->unique($this->list($message['actions'], 16), function (mixed $action): string {
            $this->actionSpec($action);
            return $action['name'];
        });
        $this->unique($this->list($message['events'], 16), function (mixed $event): string {
            if (!is_array($event)) $this->fail();
            $this->keys($event, ['name', 'description', 'max_per_minute', 'fields']);
            $this->name($event['name']);
            $this->text($event['description'], 256);
            if (!is_int($event['max_per_minute']) || $event['max_per_minute'] < 1 || $event['max_per_minute'] > 120) $this->fail();
            $this->unique($this->list($event['fields'], 8), fn(mixed $field): string => $this->spec($field, true));
            return $event['name'];
        });
        $this->unique($this->list($message['prompt_contributions'], 4), function (mixed $slot): string {
            if (!is_array($slot)) $this->fail();
            $this->keys($slot, ['slot', 'max_chars']);
            if (!in_array($slot['slot'], self::PROMPT_SLOTS, true) || !is_int($slot['max_chars'])
                || $slot['max_chars'] < 1 || $slot['max_chars'] > 1024) $this->fail();
            return $slot['slot'];
        });
    }

    private function actionSpec(mixed $action): void
    {
        if (!is_array($action)) $this->fail();
        $this->keys($action, ['name', 'display_name', 'description', 'tier', 'confirmation', 'executor_kinds', 'target',
            'target_kinds', 'timeout_seconds', 'cancellable', 'parameters']);
        $this->name($action['name']);
        $this->text($action['display_name'], 64);
        $this->text($action['description'], 256);
        $this->tierConfirmation($action['tier'], $action['confirmation']);
        $this->kinds($action['executor_kinds'], ['creature', 'npc'], 1);
        $this->kinds($action['target_kinds'], ['creature', 'npc', 'player'], 0);
        if (!in_array($action['target'], ['none', 'optional', 'required'], true)
            || ($action['target'] === 'none') !== ($action['target_kinds'] === [])
            || !is_int($action['timeout_seconds']) || $action['timeout_seconds'] < 1 || $action['timeout_seconds'] > 300
            || !is_bool($action['cancellable'])) $this->fail();
        $this->unique($this->list($action['parameters'], 8), fn(mixed $parameter): string => $this->spec($parameter, false));
    }

    /** Validate one declarative specification and return its name. Free text exists only for client-observed event fields. */
    private function spec(mixed $spec, bool $field): string
    {
        if (!is_array($spec) || !is_string($spec['type'] ?? null)) $this->fail();
        $extra = match ($spec['type']) {
            'integer', 'number' => ['minimum', 'maximum'],
            'boolean' => [],
            'enum' => ['values'],
            'actor' => ['actor_kinds'],
            'text' => $field ? ['max_length'] : $this->fail(),
            default => $this->fail(),
        };
        $this->keys($spec, ['name', 'type', 'required', ...$extra]);
        $this->name($spec['name']);
        if (!is_bool($spec['required'])) $this->fail();
        if ($spec['type'] === 'integer' && (!is_int($spec['minimum']) || !is_int($spec['maximum'])
            || abs($spec['minimum']) > self::MAX_INTEGER || abs($spec['maximum']) > self::MAX_INTEGER)) $this->fail();
        if ($spec['type'] === 'number' && (!$this->finite($spec['minimum'], self::MAX_NUMBER)
            || !$this->finite($spec['maximum'], self::MAX_NUMBER))) $this->fail();
        if (in_array($spec['type'], ['integer', 'number'], true) && $spec['minimum'] > $spec['maximum']) $this->fail();
        if ($spec['type'] === 'enum') {
            $values = $this->list($spec['values'], 32);
            if ($values === [] || count(array_unique($values, SORT_REGULAR)) !== count($values)) $this->fail();
            foreach ($values as $value) $this->token($value);
        }
        if ($spec['type'] === 'actor') $this->kinds($spec['actor_kinds'], ['creature', 'npc', 'player'], 1);
        if ($spec['type'] === 'text' && (!is_int($spec['max_length']) || $spec['max_length'] < 1 || $spec['max_length'] > 512)) $this->fail();
        return $spec['name'];
    }

    /** @param array<string,mixed> $spec */
    private function value(array $spec, mixed $value): void
    {
        $valid = match ($spec['type']) {
            'integer' => is_int($value) && $value >= $spec['minimum'] && $value <= $spec['maximum'],
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value)
                && $value >= $spec['minimum'] && $value <= $spec['maximum'],
            'boolean' => is_bool($value),
            'enum' => is_string($value) && in_array($value, $spec['values'], true),
            'actor' => is_array($value) && in_array($value['kind'] ?? null, $spec['actor_kinds'], true),
            'text' => is_string($value) && $value !== '' && mb_check_encoding($value, 'UTF-8')
                && mb_strlen($value, 'UTF-8') <= $spec['max_length'] && preg_match('/\p{Cc}/u', $value) !== 1,
            default => false,
        };
        if (!$valid) $this->fail();
        if ($spec['type'] === 'actor') $this->validator->identity($value);
    }

    /** @param array<string,mixed> $message */
    private function registration(array $message): void
    {
        $this->envelope($message, self::REGISTRATION, ['created_at', 'operation', 'plugins']);
        $this->validator->timestamp($message['created_at']);
        $register = $message['operation'] === 'register';
        if (!$register && $message['operation'] !== 'unregister') $this->fail();
        $plugins = $this->list($message['plugins'], self::MAX_PLUGINS);
        if ($plugins === []) $this->fail();
        $this->unique($plugins, function (mixed $plugin) use ($register): string {
            if (!is_array($plugin)) $this->fail();
            $this->keys($plugin, $register
                ? ['plugin_id', 'version', 'manifest_sha256', 'actions', 'events', 'prompt_slots'] : ['plugin_id', 'version']);
            $this->pluginId($plugin['plugin_id']);
            $this->version($plugin['version']);
            if (!$register) return $plugin['plugin_id'];
            if (!is_string($plugin['manifest_sha256']) || preg_match(self::SHA256, $plugin['manifest_sha256']) !== 1) $this->fail();
            $this->unique($this->list($plugin['actions'], 16), function (mixed $action): string {
                if (!is_array($action)) $this->fail();
                $this->keys($action, array_merge(['name', 'tier', 'confirmation', 'executor_kinds'],
                    array_key_exists('actors', $action) ? ['actors'] : []));
                $this->name($action['name']);
                $this->tierConfirmation($action['tier'], $action['confirmation']);
                $this->kinds($action['executor_kinds'], ['creature', 'npc'], 1);
                if (array_key_exists('actors', $action)) {
                    $actors = $this->list($action['actors'], 12);
                    if ($actors === []) $this->fail();
                    $this->unique($actors, function (mixed $actor) use ($action): string {
                        $this->validator->identity($actor);
                        if (!in_array($actor['kind'], $action['executor_kinds'], true)) $this->fail();
                        return strtolower($actor['content_file']) . '|' . $actor['refnum']['index'];
                    });
                }
                return $action['name'];
            });
            $this->unique($this->list($plugin['events'], 16), function (mixed $event): string {
                $this->name($event);
                return $event;
            });
            $this->unique($this->list($plugin['prompt_slots'], 4), function (mixed $slot): string {
                if (!in_array($slot, self::PROMPT_SLOTS, true)) $this->fail();
                return $slot;
            });
            return $plugin['plugin_id'];
        });
    }

    /** @param array<string,mixed> $message */
    private function registrationAccepted(array $message): void
    {
        $this->envelope($message, self::REGISTRATION_ACCEPTED, ['plugins']);
        $this->unique($this->list($message['plugins'], self::MAX_PLUGINS * 2), function (mixed $plugin): string {
            if (!is_array($plugin)) $this->fail();
            $this->keys($plugin, ['plugin_id', 'version', 'state', 'reason_code']);
            $this->pluginId($plugin['plugin_id']);
            $this->version($plugin['version']);
            if (!in_array($plugin['state'], ['active', 'disabled', 'rejected', 'unregistered'], true)
                || !is_string($plugin['reason_code']) || preg_match(self::REASON, $plugin['reason_code']) !== 1) $this->fail();
            return $plugin['plugin_id'];
        });
    }

    /** @param array<string,mixed> $message */
    private function actionIntent(array $message): void
    {
        $this->keys($message, ['schema', 'action_id', 'turn_id', 'session_id', 'generation', 'plugin_id', 'plugin_version',
            'action', 'tier', 'confirmation_required', 'cancellable', 'actor', 'target', 'parameters', 'expires_at']);
        if ($message['schema'] !== self::ACTION_INTENT || !is_bool($message['confirmation_required'])
            || !is_bool($message['cancellable']) || !is_int($message['tier']) || $message['tier'] < 0 || $message['tier'] > 2
            || ($message['tier'] === 2 && !$message['confirmation_required'])) $this->fail();
        foreach (['action_id', 'turn_id', 'session_id'] as $field) $this->validator->uuid($message[$field]);
        $this->generation($message['generation']);
        $this->pluginId($message['plugin_id']);
        $this->version($message['plugin_version']);
        $this->name($message['action']);
        $this->validator->timestamp($message['expires_at']);
        $this->validator->identity($message['actor']);
        if (!in_array($message['actor']['kind'], ['creature', 'npc'], true)) $this->fail();
        if ($message['target'] !== null) {
            $this->validator->identity($message['target']);
            if (!in_array($message['target']['kind'], ['creature', 'npc', 'player'], true)) $this->fail();
        }
        $this->bag($message['parameters'], function (mixed $value): void {
            if (is_string($value)) {
                $this->token($value);
            } elseif (is_array($value)) {
                $this->validator->identity($value);
            } elseif (!is_bool($value) && !$this->finite($value, PHP_FLOAT_MAX)) {
                $this->fail();
            }
        });
    }

    /** @param array<string,mixed> $message */
    private function event(array $message): void
    {
        $this->envelope($message, self::EVENT, ['observed_at', 'plugin_id', 'plugin_version', 'event', 'fields']);
        if (strlen(json_encode($message, JSON_THROW_ON_ERROR)) > self::MAX_EVENT_BYTES) $this->fail();
        $this->validator->timestamp($message['observed_at']);
        $this->pluginId($message['plugin_id']);
        $this->version($message['plugin_version']);
        $this->name($message['event']);
        $this->bag($message['fields'], function (mixed $value): void {
            if (is_string($value)) {
                $this->text($value, 512);
            } elseif (is_array($value)) {
                $this->validator->identity($value);
            } elseif (!is_bool($value) && !$this->finite($value, PHP_FLOAT_MAX)) {
                $this->fail();
            }
        });
    }

    /** @param array<string,mixed> $message */
    private function eventAccepted(array $message): void
    {
        $this->envelope($message, self::EVENT_ACCEPTED, ['duplicate']);
        if (!is_bool($message['duplicate'])) $this->fail();
    }

    /** @param array<string,mixed> $message @param list<string> $fields */
    private function envelope(array $message, string $schema, array $fields): void
    {
        $this->keys($message, ['schema', 'message_id', 'request_id', 'session_id', 'generation', ...$fields]);
        if ($message['schema'] !== $schema) $this->fail();
        foreach (['message_id', 'request_id', 'session_id'] as $field) $this->validator->uuid($message[$field]);
        $this->generation($message['generation']);
    }

    private function bag(mixed $values, callable $check): void
    {
        if (!is_array($values) || ($values !== [] && array_is_list($values)) || count($values) > 8) $this->fail();
        foreach ($values as $name => $value) {
            $this->name((string) $name);
            $check($value);
        }
    }

    private function tierConfirmation(mixed $tier, mixed $confirmation): void
    {
        if (!is_int($tier) || $tier < 0 || $tier > 2 || !in_array($confirmation, ['none', 'optional', 'required'], true)
            || ($tier === 2 && $confirmation !== 'required')) $this->fail();
    }

    /** @param list<string> $allowed */
    private function kinds(mixed $kinds, array $allowed, int $minimum): void
    {
        $kinds = $this->list($kinds, count($allowed));
        if (count($kinds) < $minimum || count(array_unique($kinds, SORT_REGULAR)) !== count($kinds)) $this->fail();
        foreach ($kinds as $kind) if (!in_array($kind, $allowed, true)) $this->fail();
    }

    /** @param list<mixed> $items @param callable(mixed):string $key */
    private function unique(array $items, callable $key): void
    {
        $seen = [];
        foreach ($items as $item) {
            $name = $key($item);
            if (isset($seen[$name])) $this->fail();
            $seen[$name] = true;
        }
    }

    /** @return list<mixed> */
    private function list(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) $this->fail();
        return $value;
    }

    private function generation(mixed $value): void
    {
        if (!is_int($value) || $value < 1 || $value > 9_007_199_254_740_991) $this->fail();
    }

    private function finite(mixed $value, float $limit): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && abs((float) $value) <= $limit;
    }

    private function pluginId(mixed $value): void
    {
        if (!is_string($value) || preg_match(self::PLUGIN_ID, $value) !== 1
            || in_array(strstr($value, '.', true), self::RESERVED_NAMESPACES, true)) $this->fail();
    }

    private function version(mixed $value): void
    {
        if (!is_string($value) || preg_match(self::VERSION, $value) !== 1) $this->fail();
    }

    private function name(mixed $value): void
    {
        if (!is_string($value) || preg_match(self::NAME, $value) !== 1) $this->fail();
    }

    private function token(mixed $value): void
    {
        if (!is_string($value) || preg_match(self::TOKEN, $value) !== 1) $this->fail();
    }

    private function text(mixed $value, int $maximum): void
    {
        if (!is_string($value) || $value === '' || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > $maximum || preg_match('/\p{Cc}/u', $value) === 1) $this->fail();
    }

    /** @param array<string,mixed> $value @param list<string> $allowed */
    private function keys(array $value, array $allowed): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) $this->fail();
    }

    private function fail(): never
    {
        throw new ValidationException('invalid_schema');
    }
}
