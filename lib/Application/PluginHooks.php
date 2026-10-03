<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

use Closure;
use LorkhanServer\Infrastructure\PluginRuntimeRepository;
use LorkhanServer\Protocol\PluginContract;
use Throwable;

/**
 * Trusted server hooks of installed, enabled and session-registered addons.
 *
 * The only executable file is the fixed server/plugin.php inside the immutable, checksum-verified package tree; it
 * must return a map of known closures. This is unsandboxed PHP running with server privileges: installing a package
 * that ships it is an explicit trust decision. Hooks run only on durable workers, outside database transactions, with
 * typed public context. Their output is captured and discarded, exceptions are contained, and prompt text is limited
 * to registered slots and declared max_chars.
 */
final class PluginHooks
{
    public const CALLBACKS = ['prompt', 'event', 'response'];
    public const MAX_LOADED = 16;
    public const MAX_PROMPT_CHARS = 8_192;
    public const NARRATION_SLOTS = ['scene_notes', 'world_state'];
    private const ENTRYPOINT = 'server/plugin.php';
    private const MAX_ENTRYPOINT_BYTES = 262_144;
    private const AUTONOMOUS_SOURCES = ['lorkhan_rpg_event', 'lorkhan_quest_event'];
    /** @var array<string,array<string,Closure>|null> Keyed by plugin, archive and package revision. */
    private static array $loaded = [];

    public function __construct(private readonly PluginRuntimeRepository $runtime)
    {
    }

    /** Clients without the negotiated capability never cause registry queries or hook loading. */
    public static function negotiated(array $turn): bool
    {
        return in_array(PluginContract::CAPABILITY, (array) ($turn['_negotiated_capabilities'] ?? []), true);
    }

    public static function profile(array $turn): string
    {
        $payload = (array) ($turn['payload'] ?? []);
        $source = (string) ($payload['ui_source'] ?? '');
        if (($payload['execution_mode'] ?? 'standard') === 'narrator' || ($payload['target']['kind'] ?? null) === 'narrator'
            || str_starts_with($source, 'lorkhan_narrator_')) return 'narration';
        if ($source === 'lorkhan_rechat') return 'rechat';
        if (str_starts_with($source, 'lorkhan_auto_') || in_array($source, self::AUTONOMOUS_SOURCES, true)) return 'autonomous';
        return 'dialogue';
    }

    /** @return list<array{plugin_id:string,slot:string,text:string}> Bounded prompt contributions in plugin ID order. */
    public function promptContributions(array $turn): array
    {
        if (!self::negotiated($turn)) return [];
        $installation = (string) $turn['installation_id'];
        $profile = self::profile($turn);
        $context = self::turnContext($turn, $profile);
        $contributions = []; $total = 0;
        foreach ($this->runtime->activePlugins($installation, (string) $turn['session_id'], (int) $turn['generation']) as $plugin) {
            $slots = $profile === 'narration' ? array_intersect_key($plugin['slots'], array_flip(self::NARRATION_SLOTS)) : $plugin['slots'];
            $hook = $slots === [] ? null : ($this->callbacks($plugin)['prompt'] ?? null);
            if ($hook === null) continue;
            $value = self::call($plugin['plugin_id'], 'prompt', $hook, ['plugin_id' => $plugin['plugin_id'],
                'plugin_version' => $plugin['version'], 'slots' => $slots] + $context,
                $this->runtime->npcData($installation, $plugin['plugin_id'], false));
            if (!is_array($value)) continue;
            foreach ($slots as $slot => $maxChars) {
                $text = self::text($value[$slot] ?? null, $maxChars);
                $length = mb_strlen($text, 'UTF-8');
                if ($length === 0 || $total + $length > self::MAX_PROMPT_CHARS) continue;
                $total += $length;
                $contributions[] = ['plugin_id' => $plugin['plugin_id'], 'slot' => $slot, 'text' => $text];
            }
        }
        return $contributions;
    }

    /** Tell active addons about one committed public response; never its private thought or provider details. */
    public function notifyResponse(array $turn, array $result): void
    {
        if (!self::negotiated($turn)) return;
        $installation = (string) $turn['installation_id'];
        $plugins = $this->runtime->activePlugins($installation, (string) $turn['session_id'], (int) $turn['generation']);
        if ($plugins === []) return;
        $utterances = [];
        foreach (array_slice((array) ($result['utterances'] ?? []), 0, 8) as $utterance) {
            $text = self::text(is_array($utterance) ? ($utterance['text'] ?? null) : null, 1024);
            if ($text !== '') $utterances[] = $text;
        }
        $context = self::turnContext($turn, self::profile($turn)) + ['utterances' => $utterances];
        foreach ($plugins as $plugin) {
            $hook = $this->callbacks($plugin)['response'] ?? null;
            if ($hook !== null) self::call($plugin['plugin_id'], 'response', $hook, ['plugin_id' => $plugin['plugin_id'],
                'plugin_version' => $plugin['version']] + $context, $this->runtime->npcData($installation, $plugin['plugin_id'], true));
        }
    }

    /** Deliver one stored client event to its addon if that addon and event are still active in the same generation. */
    public function dispatchEvent(string $installation, string $messageId): void
    {
        $event = $this->runtime->storedEvent($installation, $messageId);
        if ($event === null) return;
        foreach ($this->runtime->activePlugins($installation, $event['session_id'], $event['generation']) as $plugin) {
            if ($plugin['plugin_id'] !== $event['plugin_id'] || $plugin['version'] !== $event['plugin_version']
                || !in_array($event['event'], $plugin['events'], true)) continue;
            $hook = $this->callbacks($plugin)['event'] ?? null;
            if ($hook !== null) self::call($plugin['plugin_id'], 'event', $hook, array_intersect_key($event, array_flip(['message_id',
                'session_id', 'generation', 'observed_at', 'plugin_id', 'plugin_version', 'event', 'fields'])),
                $this->runtime->npcData($installation, $plugin['plugin_id'], true));
        }
    }

    /** @return array<string,Closure> */
    private function callbacks(array $plugin): array
    {
        return self::load($this->runtime->storeRoot(), $plugin) ?? [];
    }

    /**
     * Load once per plugin/archive/revision per process; at most MAX_LOADED entries are retained.
     *
     * @param array{plugin_id:string,archive_sha256:string,manifest_sha256:string,revision:int} $plugin
     * @return array<string,Closure>|null
     */
    public static function load(string $storeRoot, array $plugin): ?array
    {
        $key = $plugin['plugin_id'] . '|' . $plugin['archive_sha256'] . '|' . $plugin['revision'];
        if (array_key_exists($key, self::$loaded)) return self::$loaded[$key];
        if (count(self::$loaded) >= self::MAX_LOADED) unset(self::$loaded[array_key_first(self::$loaded)]);
        return self::$loaded[$key] = self::read(rtrim($storeRoot, '/'), $plugin);
    }

    /** @return array<string,Closure>|null */
    private static function read(string $storeRoot, array $plugin): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $plugin['archive_sha256']) !== 1) return null;
        $tree = $storeRoot . '/' . $plugin['archive_sha256'];
        $file = $tree . '/' . self::ENTRYPOINT;
        foreach ([$storeRoot, $tree, $tree . '/server', $file] as $path) if (is_link($path)) return self::rejected($plugin, 'link');
        if (!is_file($file)) return null;
        $source = @file_get_contents($file, false, null, 0, self::MAX_ENTRYPOINT_BYTES + 1);
        $sums = [];
        foreach (preg_split('/\r?\n/', (string) @file_get_contents($tree . '/checksums.sha256')) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/D', $line, $match) === 1) $sums[$match[2]] = $match[1];
        }
        if (!is_string($source) || strlen($source) > self::MAX_ENTRYPOINT_BYTES || !isset($sums[self::ENTRYPOINT])
            || !hash_equals($sums[self::ENTRYPOINT], hash('sha256', $source))
            || !hash_equals($plugin['manifest_sha256'], (string) @hash_file('sha256', $tree . '/' . PluginPackageArchive::CONTRACT_PATH))) {
            return self::rejected($plugin, 'checksum');
        }
        if (!self::closuresOnly($source)) return self::rejected($plugin, 'entrypoint_unsupported');
        $level = ob_get_level();
        ob_start();
        try {
            $callbacks = (static function (string $entrypoint): mixed { return require $entrypoint; })($file);
        } catch (Throwable $error) {
            return self::rejected($plugin, 'entrypoint_failed:' . $error::class);
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
        if (!is_array($callbacks) || $callbacks === [] || array_diff(array_keys($callbacks), self::CALLBACKS) !== []) {
            return self::rejected($plugin, 'callbacks_invalid');
        }
        foreach ($callbacks as $callback) if (!$callback instanceof Closure) return self::rejected($plugin, 'callbacks_invalid');
        return $callbacks;
    }

    /**
     * Keep the entrypoint a single self-contained file of closures so per-revision reloads cannot redeclare symbols.
     * This is a hygiene check, not a sandbox.
     */
    private static function closuresOnly(string $source): bool
    {
        if (!str_starts_with($source, '<?php')) return false;
        $previous = null; $afterFunction = false;
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                if ($token === '`') return false;
                if ($token === '(') $afterFunction = false;
                $previous = $token;
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if (in_array($id, [T_INLINE_HTML, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_GLOBAL,
                T_HALT_COMPILER, T_INTERFACE, T_TRAIT, T_ENUM], true)) return false;
            if ($id === T_CLASS && !(is_array($previous) && $previous[0] === T_DOUBLE_COLON)) return false;
            if (($id === T_VARIABLE && $text === '$GLOBALS') || ($id === T_STRING && $afterFunction)) return false;
            if ($id === T_FUNCTION) $afterFunction = true;
            $previous = $token;
        }
        return true;
    }

    private static function rejected(array $plugin, string $reason): ?array
    {
        error_log('[LORKHAN] plugin hooks unavailable plugin=' . $plugin['plugin_id'] . ' reason=' . $reason);
        return null;
    }

    /** Contain hook failures and discard anything a hook prints, so prompts, HTTP bodies and audio stay intact. */
    private static function call(string $pluginId, string $name, Closure $hook, mixed ...$arguments): mixed
    {
        $level = ob_get_level();
        ob_start();
        try {
            return $hook(...$arguments);
        } catch (Throwable $error) {
            error_log('[LORKHAN] plugin hook failed plugin=' . $pluginId . ' hook=' . $name . ' exception=' . $error::class);
            return null;
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
    }

    /** Public, typed turn facts only: no credentials, provider configuration, prompts, memories or private thoughts. */
    private static function turnContext(array $turn, string $profile): array
    {
        $payload = (array) ($turn['payload'] ?? []);
        $actor = static fn(mixed $identity): ?array => is_array($identity)
            ? array_intersect_key($identity, array_flip(['kind', 'record_id', 'content_file', 'refnum', 'display_name', 'cell', 'dynamic'])) : null;
        $input = self::text($payload['input']['text'] ?? null, 1024);
        return ['profile' => $profile, 'turn_id' => (string) $turn['turn_id'], 'session_id' => (string) $turn['session_id'],
            'generation' => (int) $turn['generation'], 'speaker' => $actor($payload['speaker'] ?? null),
            'target' => $actor($payload['target'] ?? null), 'input_text' => $input === '' ? null : $input];
    }

    /** One line of plain text: valid UTF-8, controls collapsed to spaces, at most $maxChars characters. */
    private static function text(mixed $value, int $maxChars): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return '';
        $text = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $value));
        return mb_substr($text, 0, $maxChars, 'UTF-8');
    }
}
