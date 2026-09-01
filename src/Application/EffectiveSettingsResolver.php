<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

/** Resolve typed settings without collapsing explicit false, zero, or empty-string overrides. */
final class EffectiveSettingsResolver
{

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return SettingsCatalog::clientDefaults();
    }

    /** Project internal settings into the unchanged strict v1 controls contract. */
    public static function controlsProjection(array $resolved): array
    {
        $settings = SettingsCatalog::clientDefaults();
        unset($settings['schema']);
        $projectionFields = SettingsCatalog::controlsProjectionFields();
        foreach ($projectionFields as $section => $fields) {
            $allowed = array_fill_keys($fields, true);
            $settings[$section] = array_replace($settings[$section],
                array_intersect_key($resolved['settings'][$section], $allowed));
        }
        // Presentation and legacy behavior fields are inert v1 compatibility defaults.
        // They are never projected into Lua; the client's local preferences remain authoritative.
        $routing = array_intersect_key($resolved['routing'], array_fill_keys([
            'prompt_configuration_id', 'llm_configuration_id', 'llm_fast_configuration_id',
            'llm_powerful_configuration_id', 'llm_experimental_configuration_id',
            'llm_fallback_configuration_id', 'tts_configuration_id',
            'llm_randomizer_enabled', 'llm_fallback_enabled',
        ], true));
        $routing += ['llm_randomizer_enabled' => false, 'llm_fallback_enabled' => false];
        $sources = [];
        foreach ($projectionFields as $section => $fields) {
            foreach ($fields as $field) {
                $path = 'settings.' . $section . '.' . $field;
                $source = $resolved['sources'][$path] ?? null;
                if (in_array($source, ['default', 'global', 'core_profile', 'npc'], true)) $sources[$path] = $source;
            }
        }
        foreach ($routing as $field => $_) {
            $path = 'routing.' . $field;
            $source = $resolved['sources'][$path] ?? 'default';
            if (in_array($source, ['default', 'global', 'core_profile', 'npc'], true)) $sources[$path] = $source;
        }
        return ['settings' => $settings, 'routing' => $routing, 'source_map' => $sources];
    }

    /**
     * @param array<string,mixed> $globalSettings
     * @param array<string,mixed> $coreProfileContent
     * @param array<string,mixed> $npcProfileContent
     * @return array{document:array<string,mixed>,settings:array<string,mixed>,routing:array<string,mixed>,sources:array<string,string>,sha256:string}
     */
    public function resolve(array $globalSettings, array $coreProfileContent, array $npcProfileContent, array $oghmaGlobal = []): array
    {
        $settings = SettingsCatalog::clientDefaults();
        $settings['diary'] = DiaryGenerationPolicy::defaults();
        $sources = [];
        $this->markLeaves($settings, 'default', 'settings', $sources);

        if ($globalSettings !== []) {
            self::validateGlobalSettings($globalSettings);
            $settings = $globalSettings;
            $settings['diary'] = DiaryGenerationPolicy::defaults();
            $sources = [];
            $this->markLeaves($settings, 'global', 'settings', $sources);
            $this->markLeaves($settings['diary'], 'default', 'settings.diary', $sources);
        }
        $settings['memory']['oghma_knowledge_tags'] = '';
        $sources['settings.memory.oghma_knowledge_tags'] = 'server_default';
        self::validateOghmaSettings($oghmaGlobal, true);
        $settings['oghma'] = array_replace(SettingsCatalog::oghmaDefaults(), $oghmaGlobal);
        foreach ($settings['oghma'] as $field => $_) {
            $sources['settings.oghma.' . $field] = array_key_exists($field, $oghmaGlobal) ? 'global' : 'default';
        }

        $settings['relationship'] = ['update_chance_percent'=>0, 'locked'=>false];
        $this->markLeaves($settings['relationship'], 'default', 'settings.relationship', $sources);
        $routing = [];
        foreach ([['core_profile', $coreProfileContent], ['npc', $npcProfileContent]] as [$source, $content]) {
            if (!is_array($content) || ($content !== [] && array_is_list($content))) throw new InvalidArgumentException('invalid_settings_layer');
            $overrides = $content['settings_overrides'] ?? [];
            self::validateSettingsOverrides($overrides);
            $oghmaOverrides = is_array($overrides['oghma'] ?? null) ? $overrides['oghma'] : [];
            unset($overrides['oghma']);
            $this->mergeSettings($settings, $overrides, $source, 'settings', $sources);
            $this->mergeSettings($settings, $oghmaOverrides === [] ? [] : ['oghma'=>$oghmaOverrides], $source, 'settings', $sources);

            $route = $content['routing'] ?? [];
            self::validateRouting($route);
            foreach ($route as $key => $value) {
                $routing[$key] = $value;
                $sources['routing.' . $key] = $source;
            }
            if ($source === 'npc' && is_string($content['oghma_knowledge_tags'] ?? null)
                && trim($content['oghma_knowledge_tags']) !== '') {
                $settings['memory']['oghma_knowledge_tags'] = trim($content['oghma_knowledge_tags']);
                $sources['settings.memory.oghma_knowledge_tags'] = 'npc';
            }
        }

        // Compatibility fields remain in the v1 document, but excluded automation can never become effective.
        foreach ([
            ['behavior', 'auto_greeting'],
            ['behavior', 'rechat_allow_actions'],
            ['behavior', 'boredom'],
            ['behavior', 'combat_barks'],
            ['narrator', 'welcome_events'],
            ['narrator', 'random_events'],
            ['narrator', 'quest_events'],
            ['narrator', 'book_events'],
        ] as [$section, $field]) {
            $settings[$section][$field] = false;
            $sources['settings.' . $section . '.' . $field] = 'excluded';
        }

        $document = ['schema' => 'lorkhan.effective-settings.v1', 'settings' => $settings, 'routing' => $routing];
        return [
            'document' => $document,
            'settings' => $settings,
            'routing' => $routing,
            'sources' => $sources,
            'sha256' => hash('sha256', self::canonical($document)),
        ];
    }

    /** @param array<string,mixed> $content */
    public static function validateCoreProfile(array $content): array
    {
        $keys = array_keys($content);
        sort($keys);
        if ($keys !== ['prompt', 'routing', 'schema', 'settings_overrides']
            || ($content['schema'] ?? null) !== 'lorkhan.core-profile.v1'
            || !is_string($content['prompt'] ?? null)
            || strlen($content['prompt']) > 65_536
            || !mb_check_encoding($content['prompt'], 'UTF-8')) {
            throw new InvalidArgumentException('invalid_core_profile');
        }
        self::validateRouting($content['routing']);
        self::validateSettingsOverrides($content['settings_overrides']);
        return $content;
    }

    /** @param array<string,mixed> $content */
    public static function validateGlobalSettings(array $content): array
    {
        $expected = SettingsCatalog::clientDefaults();
        $keys = array_keys($content);
        sort($keys);
        $expectedKeys = array_keys($expected);
        sort($expectedKeys);
        if ($keys !== $expectedKeys || ($content['schema'] ?? null) !== 'lorkhan.client-settings.v1') {
            throw new InvalidArgumentException('invalid_global_settings');
        }
        self::validateSettingsShape($content, $expected, false);
        return $content;
    }

    /** @param mixed $overrides */
    public static function validateSettingsOverrides(mixed $overrides): array
    {
        if (!is_array($overrides) || ($overrides !== [] && array_is_list($overrides)) || array_key_exists('schema', $overrides)) {
            throw new InvalidArgumentException('invalid_settings_overrides');
        }
        $validation=$overrides;
        if(array_key_exists('relationship',$validation)){
            self::validateSettingsShape(['relationship'=>$validation['relationship']],
                ['relationship'=>['update_chance_percent'=>0,'locked'=>false]],true);
            unset($validation['relationship']);
        }
        if(array_key_exists('diary',$validation)){
            DiaryGenerationPolicy::validateOverrides($validation['diary']);
            unset($validation['diary']);
        }
        if(array_key_exists('oghma_knowledge_tags',$validation['memory']??[])){
            $value=$validation['memory']['oghma_knowledge_tags'];
            if(!is_string($value)||strlen($value)>4096||!mb_check_encoding($value,'UTF-8'))throw new InvalidArgumentException('invalid_settings_overrides');
            unset($validation['memory']['oghma_knowledge_tags']);if($validation['memory']===[])unset($validation['memory']);
        }
        if (array_key_exists('oghma', $validation)) {
            self::validateOghmaSettings($validation['oghma'], true);
            unset($validation['oghma']);
        }
        self::validateSettingsShape($validation, SettingsCatalog::clientDefaults(), true);
        return $overrides;
    }

    private static function validateOghmaSettings(mixed $settings, bool $partial): void
    {
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))
            || array_diff(array_keys($settings), array_keys(SettingsCatalog::oghmaDefaults())) !== []) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        foreach ($settings as $field => $value) {
            if (get_debug_type($value) !== get_debug_type(SettingsCatalog::oghmaDefaults()[$field])) {
                throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
            }
            $valid = match ($field) {
                'topic_count' => $value >= 1 && $value <= 3,
                'result_limit' => $value >= 1 && $value <= 5,
                'extractor_timeout_ms' => $value >= 250 && $value <= 3000,
                default => true,
            };
            if (!$valid) throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if (!$partial && count($settings) !== count(SettingsCatalog::oghmaDefaults())) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
    }

    /** @param mixed $routing */
    public static function validateRouting(mixed $routing): array
    {
        $routingTypes = SettingsCatalog::routingTypes();
        if (!is_array($routing) || ($routing !== [] && array_is_list($routing)) || array_diff(array_keys($routing), array_keys($routingTypes)) !== []) {
            throw new InvalidArgumentException('invalid_profile_routing');
        }
        foreach ($routing as $field => $value) {
            if ($routingTypes[$field] === 'bool') {
                if (!is_bool($value)) throw new InvalidArgumentException('invalid_profile_routing');
                continue;
            }
            if (!is_string($value) || ($value !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1)) {
                throw new InvalidArgumentException('invalid_profile_routing');
            }
        }
        return $routing;
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private static function validateSettingsShape(array $actual, array $expected, bool $partial): void
    {
        foreach ($actual as $section => $values) {
            if ($section === 'schema') continue;
            if (!array_key_exists($section, $expected) || !is_array($values) || array_is_list($values)) {
                throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
            }
            foreach ($values as $field => $value) {
                if (!array_key_exists($field, $expected[$section]) || get_debug_type($value) !== get_debug_type($expected[$section][$field])) {
                    throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
                }
                self::validateSettingValue($section, $field, $value, $partial);
            }
            if (!$partial && count($values) !== count($expected[$section])) {
                throw new InvalidArgumentException('invalid_global_settings');
            }
        }
        if (!$partial && count($actual) !== count($expected)) throw new InvalidArgumentException('invalid_global_settings');
    }

    private static function validateSettingValue(string $section, string $field, mixed $value, bool $partial): void
    {
        $ranges = SettingsCatalog::ranges();
        $path = $section . '.' . $field;
        if (isset($ranges[$path]) && ($value < $ranges[$path][0] || $value > $ranges[$path][1])) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if (isset(SettingsCatalog::enums()[$path]) && !in_array($value, SettingsCatalog::enums()[$path], true)) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if ($path === 'narrator.name' && (trim($value) === '' || strlen($value) > 128 || !mb_check_encoding($value, 'UTF-8'))) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
        if ($path === 'memory.oghma_knowledge_tags' && (strlen($value) > 4096 || !mb_check_encoding($value, 'UTF-8'))) {
            throw new InvalidArgumentException($partial ? 'invalid_settings_overrides' : 'invalid_global_settings');
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $overrides @param array<string,string> $sources */
    private function mergeSettings(array &$target, array $overrides, string $source, string $prefix, array &$sources): void
    {
        foreach ($overrides as $section => $values) {
            foreach ($values as $field => $value) {
                $target[$section][$field] = $value;
                $sources[$prefix . '.' . $section . '.' . $field] = $source;
            }
        }
    }

    /** @param array<string,mixed> $value @param array<string,string> $sources */
    private function markLeaves(array $value, string $source, string $prefix, array &$sources): void
    {
        foreach ($value as $key => $child) {
            $path = $prefix . '.' . $key;
            if (is_array($child)) $this->markLeaves($child, $source, $path, $sources);
            else $sources[$path] = $source;
        }
    }

    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $child) use (&$sort): mixed {
            if (!is_array($child)) return $child;
            if (array_is_list($child)) return array_map($sort, $child);
            ksort($child, SORT_STRING);
            foreach ($child as &$item) $item = $sort($item);
            unset($item);
            return $child;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
