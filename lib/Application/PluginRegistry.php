<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use LorkhanServer\Protocol\PluginContract;
use LorkhanServer\Protocol\ValidationException;
use LorkhanServer\Protocol\Validator;
use UnexpectedValueException;

/**
 * Immutable session/generation registry of client-registered third-party addons.
 *
 * A plugin is active only when its server-installed manifest matches the registered version and
 * hash, server policy enables it, the client registered it in the same negotiated session generation
 * and every dependency is active within range. Every apply revalidates the whole active set; PluginRuntimeRepository persists
 * accepted entries per session generation and restores them on each access.
 */
final class PluginRegistry
{
    /**
     * @param array<string,array{manifest:array<string,mixed>,registration:array<string,mixed>}> $active
     */
    private function __construct(
        private readonly string $sessionId,
        private readonly int $generation,
        private readonly string $serverVersion,
        private readonly string $clientVersion,
        private readonly array $active,
        private readonly PluginContract $contract,
    ) {
    }

    /**
     * Start an empty registry for a negotiated session. Clients without the capability never gain plugin actions.
     *
     * @param array{session_id:string,generation:int,capabilities:list<string>,client_version:string} $session
     */
    public static function forSession(array $session, string $serverVersion): self
    {
        if (!in_array(PluginContract::CAPABILITY, $session['capabilities'], true)) {
            throw new DomainException('plugin_contract_unsupported');
        }
        return new self($session['session_id'], $session['generation'], $serverVersion, $session['client_version'], [],
            new PluginContract(new Validator()));
    }

    /**
     * Rebuild one persisted session generation. Stored entries replay through apply(), so every access revalidates the
     * current installed manifest/hash, enabled policy and dependencies; disabled, removed or updated plugins drop out.
     *
     * @param array{session_id:string,generation:int,capabilities:list<string>,client_version:string} $session
     * @param list<array<string,mixed>> $entries Previously accepted registration entries.
     * @param array<string,array{manifest:array<string,mixed>,sha256:string}> $installed
     * @param array<string,bool> $policy
     */
    public static function restore(array $session, string $serverVersion, array $entries, array $installed, array $policy): self
    {
        $registry = self::forSession($session, $serverVersion);
        if ($entries === []) return $registry;
        return $registry->apply(['schema' => PluginContract::REGISTRATION, 'message_id' => $session['session_id'],
            'request_id' => $session['session_id'], 'session_id' => $session['session_id'], 'generation' => $session['generation'],
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'operation' => 'register', 'plugins' => $entries], $installed, $policy)[0];
    }

    /** @return array<string,array<string,mixed>> Accepted registration entries of active plugins, by plugin ID. */
    public function entries(): array
    {
        return array_map(static fn(array $plugin): array => $plugin['registration'], $this->active);
    }

    /**
     * Apply one client register/unregister message.
     *
     * @param array<string,mixed> $message
     * @param array<string,array{manifest:array<string,mixed>,sha256:string}> $installed Server-installed manifests by plugin ID.
     * @param array<string,bool> $policy Server enable overrides; absent plugins use their manifest default.
     * @return array{0:self,1:list<array{plugin_id:string,version:string,state:string,reason_code:string}>}
     */
    public function apply(array $message, array $installed, array $policy): array
    {
        $this->contract->validate($message, PluginContract::REGISTRATION);
        if ($message['session_id'] !== $this->sessionId || $message['generation'] !== $this->generation) {
            throw new UnexpectedValueException('stale_generation');
        }
        // Revalidate the current active set against supplied installed manifests and policy first.
        $active = [];
        $results = [];
        foreach ($this->active as $id => $plugin) {
            $registration = $plugin['registration'];
            $reason = $this->rejection($registration, $installed[$id] ?? null);
            if ($reason === null && !($policy[$id] ?? $installed[$id]['manifest']['default_enabled'])) {
                $results[$id] = $this->result($registration, 'disabled', 'plugin_disabled');
            } elseif ($reason !== null) {
                $results[$id] = $this->result($registration, 'unregistered', $reason);
            } else {
                $active[$id] = ['manifest' => $installed[$id]['manifest'], 'registration' => $registration];
            }
        }
        foreach ($message['plugins'] as $entry) {
            $id = $entry['plugin_id'];
            if ($message['operation'] === 'unregister') {
                $owned = isset($active[$id]) && $active[$id]['registration']['version'] === $entry['version'];
                if ($owned) unset($active[$id]);
                $results[$id] = $this->result($entry, $owned ? 'unregistered' : 'rejected', $owned ? 'unregistered' : 'plugin_not_registered');
                continue;
            }
            if (isset($active[$id])) {
                $same = self::canonical($active[$id]['registration']) === self::canonical($entry);
                $results[$id] = $this->result($entry, $same ? 'active' : 'rejected', $same ? 'registered' : 'duplicate_conflict');
                continue;
            }
            $reason = $this->rejection($entry, $installed[$id] ?? null);
            if ($reason === null && !($policy[$id] ?? $installed[$id]['manifest']['default_enabled'])) {
                $results[$id] = $this->result($entry, 'disabled', 'plugin_disabled');
                continue;
            }
            if ($reason === null && count($active) >= PluginContract::MAX_PLUGINS) $reason = 'plugin_limit_exceeded';
            if ($reason !== null) {
                $results[$id] = $this->result($entry, 'rejected', $reason);
                continue;
            }
            $active[$id] = ['manifest' => $installed[$id]['manifest'], 'registration' => $entry];
            $results[$id] = $this->result($entry, 'active', 'registered');
        }
        // Remove plugins whose dependencies are absent or out of range until the active set is stable.
        do {
            $removed = false;
            foreach ($active as $id => $plugin) {
                foreach ($plugin['manifest']['dependencies'] as $dependency) {
                    $provider = $active[$dependency['plugin_id']]['manifest']['version'] ?? null;
                    if ($provider !== null && PluginContract::versionInRange($provider, $dependency['min_version'],
                        $dependency['max_version_exclusive'] ?? null)) continue;
                    unset($active[$id]);
                    $results[$id] = $this->result($plugin['registration'], isset($this->active[$id]) ? 'unregistered' : 'rejected',
                        'dependency_unsatisfied');
                    $removed = true;
                    break;
                }
            }
        } while ($removed);
        $registry = new self($this->sessionId, $this->generation, $this->serverVersion, $this->clientVersion, $active, $this->contract);
        return [$registry, array_values($results)];
    }

    /** @return list<array<string,mixed>> Effective registered action definitions for later prompt exposure. */
    public function actions(): array
    {
        $actions = [];
        foreach ($this->active as $id => $plugin) {
            foreach ($plugin['registration']['actions'] as $registered) {
                $actions[] = ['plugin_id' => $id, 'plugin_version' => $plugin['manifest']['version']]
                    + array_replace($this->manifestAction($plugin, $registered['name']), ['executor_kinds' => $registered['executor_kinds']])
                    + (isset($registered['actors']) ? ['actors' => $registered['actors']] : []);
            }
        }
        return $actions;
    }

    /** @return list<array{plugin_id:string,slot:string,max_chars:int}> Registered prompt contribution slots. */
    public function promptSlots(): array
    {
        $slots = [];
        foreach ($this->active as $id => $plugin) {
            foreach ($plugin['manifest']['prompt_contributions'] as $slot) {
                if (in_array($slot['slot'], $plugin['registration']['prompt_slots'], true)) {
                    $slots[] = ['plugin_id' => $id, 'slot' => $slot['slot'], 'max_chars' => $slot['max_chars']];
                }
            }
        }
        return $slots;
    }

    /**
     * Convert one untrusted model proposal into a bounded wire intent for the current session generation.
     *
     * @param array<string,mixed> $proposal {plugin_id, action, actor, target, parameters}
     * @return array<string,mixed>
     */
    public function intent(array $proposal, string $actionId, string $turnId, DateTimeImmutable $now): array
    {
        $keys = array_keys($proposal);
        sort($keys);
        if ($keys !== ['action', 'actor', 'parameters', 'plugin_id', 'target']) throw new DomainException('provider_invalid_action');
        $plugin = is_string($proposal['plugin_id']) ? ($this->active[$proposal['plugin_id']] ?? null) : null;
        $registered = null;
        foreach ($plugin['registration']['actions'] ?? [] as $candidate) {
            if ($candidate['name'] === $proposal['action']) $registered = $candidate;
        }
        if ($registered === null) throw new DomainException('action_disabled');
        $spec = $this->manifestAction($plugin, $registered['name']);
        try {
            (new Validator())->identity($proposal['actor']);
            if ($proposal['target'] !== null) (new Validator())->identity($proposal['target']);
        } catch (ValidationException) {
            throw new DomainException('provider_invalid_action');
        }
        $scoped = !isset($registered['actors']);
        foreach ($registered['actors'] ?? [] as $actor) $scoped = $scoped || PluginContract::sameActor($actor, $proposal['actor']);
        if (!in_array($proposal['actor']['kind'], $registered['executor_kinds'], true) || !$scoped) {
            throw new DomainException('provider_action_not_allowed');
        }
        $target = $proposal['target'];
        if (($spec['target'] === 'none' && $target !== null) || ($spec['target'] === 'required' && $target === null)
            || ($target !== null && !in_array($target['kind'], $spec['target_kinds'], true))) {
            throw new DomainException('provider_action_not_allowed');
        }
        try {
            $this->contract->values($spec['parameters'], $proposal['parameters']);
        } catch (ValidationException) {
            throw new DomainException('action_parameters_invalid');
        }
        $intent = [
            'schema' => PluginContract::ACTION_INTENT, 'action_id' => $actionId, 'turn_id' => $turnId,
            'session_id' => $this->sessionId, 'generation' => $this->generation, 'plugin_id' => $proposal['plugin_id'],
            'plugin_version' => $plugin['manifest']['version'], 'action' => $spec['name'], 'tier' => $spec['tier'],
            'confirmation_required' => $spec['confirmation'] === 'required', 'cancellable' => $spec['cancellable'],
            'actor' => $proposal['actor'], 'target' => $target, 'parameters' => $proposal['parameters'],
            'expires_at' => $now->setTimezone(new DateTimeZone('UTC'))->modify('+' . $spec['timeout_seconds'] . ' seconds')
                ->format('Y-m-d\TH:i:s\Z'),
        ];
        $this->contract->validate($intent, PluginContract::ACTION_INTENT);
        return $intent;
    }

    /**
     * Accept one client plugin event only for a registered event of an active plugin in this generation.
     *
     * @param array<string,mixed> $message
     * @return array{plugin_id:string,event:string,fields:array<string,mixed>,max_per_minute:int}
     */
    public function event(array $message): array
    {
        $this->contract->validate($message, PluginContract::EVENT);
        if ($message['session_id'] !== $this->sessionId || $message['generation'] !== $this->generation) {
            throw new UnexpectedValueException('stale_generation');
        }
        $plugin = $this->active[$message['plugin_id']] ?? null;
        if ($plugin === null || $plugin['manifest']['version'] !== $message['plugin_version']
            || !in_array($message['event'], $plugin['registration']['events'], true)) {
            throw new DomainException('plugin_event_unregistered');
        }
        foreach ($plugin['manifest']['events'] as $event) {
            if ($event['name'] !== $message['event']) continue;
            $this->contract->values($event['fields'], $message['fields']);
            return ['plugin_id' => $message['plugin_id'], 'event' => $event['name'], 'fields' => $message['fields'],
                'max_per_minute' => $event['max_per_minute']];
        }
        throw new DomainException('plugin_event_unregistered');
    }

    /** @param array<string,mixed> $entry @param array{manifest:array<string,mixed>,sha256:string}|null $installed */
    private function rejection(array $entry, ?array $installed): ?string
    {
        if ($installed === null) return 'plugin_not_installed';
        $manifest = $installed['manifest'];
        try {
            $this->contract->validate($manifest, PluginContract::MANIFEST);
        } catch (ValidationException) {
            return 'plugin_manifest_invalid';
        }
        if ($manifest['plugin_id'] !== $entry['plugin_id'] || $manifest['version'] !== $entry['version'] || !hash_equals($installed['sha256'], $entry['manifest_sha256'])) {
            return 'plugin_version_mismatch';
        }
        $compatibility = $manifest['compatibility'];
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $this->clientVersion) !== 1
            || PluginContract::compareVersions($this->clientVersion, $compatibility['min_client_version']) < 0
            || PluginContract::compareVersions($this->serverVersion, $compatibility['min_server_version']) < 0
            || $compatibility['lua_api_revision'] > PluginContract::LUA_API_REVISION) {
            return 'plugin_incompatible';
        }
        $declared = [];
        foreach ($manifest['actions'] as $action) $declared[$action['name']] = $action;
        foreach ($entry['actions'] as $action) {
            $spec = $declared[$action['name']] ?? null;
            if ($spec === null || $spec['tier'] !== $action['tier'] || $spec['confirmation'] !== $action['confirmation']
                || array_diff($action['executor_kinds'], $spec['executor_kinds']) !== []) return 'registration_mismatch';
        }
        $events = array_column($manifest['events'], 'name');
        $slots = array_column($manifest['prompt_contributions'], 'slot');
        if (array_diff($entry['events'], $events) !== [] || array_diff($entry['prompt_slots'], $slots) !== []) {
            return 'registration_mismatch';
        }
        return null;
    }

    /** Persisted JSON objects do not keep key order; compare registrations by canonical content. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        return array_map(self::canonical(...), $value);
    }

    /** @param array{manifest:array<string,mixed>} $plugin @return array<string,mixed> */
    private function manifestAction(array $plugin, string $name): array
    {
        foreach ($plugin['manifest']['actions'] as $action) if ($action['name'] === $name) return $action;
        throw new DomainException('action_disabled');
    }

    /** @param array<string,mixed> $entry @return array{plugin_id:string,version:string,state:string,reason_code:string} */
    private function result(array $entry, string $state, string $reason): array
    {
        return ['plugin_id' => $entry['plugin_id'], 'version' => $entry['version'], 'state' => $state, 'reason_code' => $reason];
    }
}
