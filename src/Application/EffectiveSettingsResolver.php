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
    public function resolve(array $globalSettings, array $coreProfileContent, array $npcProfileContent, array $oghmaGlobal = [], bool $allowProfileTtsRouting = false): array
    {
        $global = $globalSettings === [] ? SettingsCatalog::globalDefaults() : self::validateGlobalSettings($globalSettings);
        $settings = $global['client'];
        $settings['diary'] = DiaryGenerationPolicy::defaults();
        $sources = [];
        $this->markLeaves($settings, $globalSettings === [] ? 'default' : 'global', 'settings', $sources);
        $this->markLeaves($settings['diary'], 'default', 'settings.diary', $sources);

        $oghmaDocument = is_array($global['oghma'] ?? null) ? $global['oghma'] : [];
        if (($globalSettings['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA) {
            $oghmaDocument = array_replace($oghmaDocument, $oghmaGlobal);
        }
        $settings['memory']['oghma_knowledge_tags'] = trim((string)($oghmaDocument['knowledge_tags'] ?? ''));
        $sources['settings.memory.oghma_knowledge_tags'] = $settings['memory']['oghma_knowledge_tags'] === '' ? 'default' : 'global';
        $settings['oghma'] = array_replace(SettingsCatalog::oghmaDefaults(), array_intersect_key($oghmaDocument, SettingsCatalog::oghmaDefaults()));
        $settings['oghma']['extractor_fallback_enabled'] = ($oghmaDocument['extractor_enabled'] ?? $settings['oghma']['extractor_fallback_enabled']) === true;
        foreach ($settings['oghma'] as $field => $_) {
            $sources['settings.oghma.' . $field] = array_key_exists($field, $oghmaDocument) ? 'global' : 'default';
        }

        $relationship = $global['relationship'];
        $settings['relationship'] = [
            'update_chance_percent' => $relationship['enabled'] ? $relationship['update_chance_percent'] : 0,
            'locked' => false,
        ];
        $this->markLeaves($settings['relationship'], 'global', 'settings.relationship', $sources);

        $routing = [];
        foreach (SettingsCatalog::systemRoutingFields() as $field) {
            $routing[$field] = (string)$global['system_routing'][$field];
            $sources['routing.' . $field] = 'global';
        }

        if (!is_array($coreProfileContent) || ($coreProfileContent !== [] && array_is_list($coreProfileContent))) {
            throw new InvalidArgumentException('invalid_settings_layer');
        }
        $coreOverrides = self::validateSettingsOverrides($coreProfileContent['settings_overrides'] ?? []);
        $allowedOverrides = [];
        foreach (['rechat', 'rechat_max_depth', 'rechat_probability_percent'] as $field) {
            if (array_key_exists($field, $coreOverrides['behavior'] ?? [])) $allowedOverrides['behavior'][$field] = $coreOverrides['behavior'][$field];
        }
        if (array_key_exists('recent_turn_limit', $coreOverrides['memory'] ?? [])) {
            $allowedOverrides['memory']['recent_turn_limit'] = $coreOverrides['memory']['recent_turn_limit'];
        }
        if (isset($coreOverrides['diary'])) $allowedOverrides['diary'] = $coreOverrides['diary'];
        $this->mergeSettings($settings, $allowedOverrides, 'core_profile', 'settings', $sources);

        $coreRouting = self::validateRouting($coreProfileContent['routing'] ?? []);
        if (($globalSettings['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA) {
            foreach (SettingsCatalog::systemRoutingFields() as $field) {
                if (!array_key_exists($field, $coreRouting)) continue;
                $routing[$field] = $coreRouting[$field];
                $sources['routing.' . $field] = 'core_profile_legacy';
            }
            $legacyChance = $coreOverrides['relationship']['update_chance_percent'] ?? null;
            if (is_int($legacyChance)) {
                $settings['relationship']['update_chance_percent'] = $legacyChance;
                $sources['settings.relationship.update_chance_percent'] = 'core_profile_legacy';
            }
        }
        if (!$settings['oghma']['extractor_fallback_enabled']) $routing['oghma_configuration_id'] = '';
        $allowedCoreRouting = array_fill_keys(SettingsCatalog::coreRoutingFields(), true);
        foreach (array_intersect_key($coreRouting, $allowedCoreRouting) as $key => $value) {
            $routing[$key] = $value;
            $sources['routing.' . $key] = 'core_profile';
        }

        if (!is_array($npcProfileContent) || ($npcProfileContent !== [] && array_is_list($npcProfileContent))) {
            throw new InvalidArgumentException('invalid_settings_layer');
        }
        if ($allowProfileTtsRouting) {
            $profileRouting = self::validateRouting($npcProfileContent['routing'] ?? []);
            if (array_key_exists('tts_configuration_id', $profileRouting)) {
                $routing['tts_configuration_id'] = $profileRouting['tts_configuration_id'];
                $sources['routing.tts_configuration_id'] = 'npc';
            }
        }
        if (is_string($npcProfileContent['oghma_knowledge_tags'] ?? null)
            && trim($npcProfileContent['oghma_knowledge_tags']) !== '') {
            $settings['memory']['oghma_knowledge_tags'] = trim($npcProfileContent['oghma_knowledge_tags']);
            $sources['settings.memory.oghma_knowledge_tags'] = 'npc';
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

        $context = $global['context'];
        $document = ['schema' => 'lorkhan.effective-settings.v2', 'settings' => $settings, 'routing' => $routing, 'context' => $context];
        return [
            'document' => $document,
            'settings' => $settings,
            'routing' => $routing,
            'context' => $context,
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
        if (($content['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA) {
            self::validateSettingsShape($content, SettingsCatalog::clientDefaults(), false);
            $migrated = SettingsCatalog::globalDefaults();
            $migrated['client'] = $content;
            return $migrated;
        }
        $expected = SettingsCatalog::globalDefaults();
        // Early v2 settings predate automatic profile backfill. Normalize those saved documents
        // to the current v2 defaults before enforcing the otherwise exact settings shape.
        if (($content['schema'] ?? null) === SettingsCatalog::GLOBAL_SCHEMA
            && is_array($content['profile_management'] ?? null) && !array_is_list($content['profile_management'])) {
            $content['profile_management'] += $expected['profile_management'];
        }
        self::assertExactKeys($content, $expected, 'invalid_global_settings');
        if (($content['schema'] ?? null) !== SettingsCatalog::GLOBAL_SCHEMA) throw new InvalidArgumentException('invalid_global_settings');
        self::validateSettingsShape($content['client'], SettingsCatalog::clientDefaults(), false);
        self::assertExactKeys($content['profile_management'], $expected['profile_management'], 'invalid_global_settings');
        if (!is_bool($content['profile_management']['auto_lock_profile'])
            || !is_bool($content['profile_management']['autofill_custom_profiles'])
            || !is_int($content['profile_management']['autofill_custom_profiles_trigger'])
            || $content['profile_management']['autofill_custom_profiles_trigger'] < 10
            || $content['profile_management']['autofill_custom_profiles_trigger'] > 100) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
        $content['translation'] = TranslationPolicy::validate($content['translation']);
        self::validateGlobalOghma($content['oghma']);
        $content['context'] = self::validateContextPolicy($content['context']);
        self::assertExactKeys($content['relationship'], $expected['relationship'], 'invalid_global_settings');
        if (!is_bool($content['relationship']['enabled']) || !is_int($content['relationship']['update_chance_percent'])
            || $content['relationship']['update_chance_percent'] < 0 || $content['relationship']['update_chance_percent'] > 100) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
        self::assertExactKeys($content['system_routing'], $expected['system_routing'], 'invalid_global_settings');
        foreach (SettingsCatalog::systemRoutingFields() as $field) self::validateUuidOrEmpty($content['system_routing'][$field]);
        return $content;
    }

    /** Normalize sidecar-backed v1 settings into the single v2 management document. */
    public static function globalDocument(array $content, array $oghma, array $translation, bool $autoLock): array
    {
        $document = $content === [] ? SettingsCatalog::globalDefaults() : self::validateGlobalSettings($content);
        if (($content['schema'] ?? null) === SettingsCatalog::CLIENT_SCHEMA || $content === []) {
            $document['profile_management']['auto_lock_profile'] = $autoLock;
            $document['translation'] = TranslationPolicy::validate($translation);
            foreach (array_keys($document['oghma']) as $field) {
                if (array_key_exists($field, $oghma)) $document['oghma'][$field] = $oghma[$field];
            }
            $document['oghma']['extractor_fallback_enabled'] = (bool)($oghma['extractor_enabled'] ?? false);
        }
        return self::validateGlobalSettings($document);
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

    /** Validate the server-only prompt-context policy and canonicalize bounded text lists. */
    private static function validateContextPolicy(mixed $context): array
    {
        $expected = SettingsCatalog::globalDefaults()['context'];
        if (!is_array($context) || array_is_list($context)) throw new InvalidArgumentException('invalid_global_settings');
        self::assertExactKeys($context, $expected, 'invalid_global_settings');
        foreach (['sections', 'details'] as $group) {
            self::assertExactKeys($context[$group], $expected[$group], 'invalid_global_settings');
            foreach ($context[$group] as $value) if (!is_bool($value)) throw new InvalidArgumentException('invalid_global_settings');
        }
        if (!is_array($context['event_types']) || !array_is_list($context['event_types'])
            || count($context['event_types']) > count(SettingsCatalog::eventTypes())) throw new InvalidArgumentException('invalid_global_settings');
        $eventTypes = [];
        foreach ($context['event_types'] as $value) {
            if (!is_string($value) || !in_array($value, SettingsCatalog::eventTypes(), true)) throw new InvalidArgumentException('invalid_global_settings');
            $eventTypes[$value] = true;
        }
        $context['event_types'] = array_values(array_filter(SettingsCatalog::eventTypes(), static fn(string $type): bool => isset($eventTypes[$type])));
        foreach (['location_blacklist', 'item_blacklist', 'magic_effects_blacklist'] as $field) {
            $context[$field] = self::validateTextList($context[$field]);
        }
        return $context;
    }

    private static function validateGlobalOghma(mixed $settings): void
    {
        $expected = SettingsCatalog::globalDefaults()['oghma'];
        if (!is_array($settings) || array_is_list($settings)) throw new InvalidArgumentException('invalid_global_settings');
        self::assertExactKeys($settings, $expected, 'invalid_global_settings');
        foreach ($settings as $field => $value) {
            if (get_debug_type($value) !== get_debug_type($expected[$field])) throw new InvalidArgumentException('invalid_global_settings');
        }
        if ($settings['topic_count'] < 1 || $settings['topic_count'] > 3
            || $settings['result_limit'] < 1 || $settings['result_limit'] > 5
            || $settings['extractor_timeout_ms'] < 250 || $settings['extractor_timeout_ms'] > 3000
            || strlen($settings['knowledge_tags']) > 4096 || !mb_check_encoding($settings['knowledge_tags'], 'UTF-8')) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
    }

    private static function validateTextList(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > 256) throw new InvalidArgumentException('invalid_global_settings');
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) throw new InvalidArgumentException('invalid_global_settings');
            $value = trim($value);
            if ($value === '') continue;
            if (strlen($value) > 256 || !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('invalid_global_settings');
            $normalized[mb_strtolower($value, 'UTF-8')] = $value;
        }
        return array_values($normalized);
    }

    private static function assertExactKeys(mixed $actual, array $expected, string $error): void
    {
        if (!is_array($actual) || array_is_list($actual)) throw new InvalidArgumentException($error);
        $actualKeys = array_keys($actual); sort($actualKeys);
        $expectedKeys = array_keys($expected); sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) throw new InvalidArgumentException($error);
    }

    private static function validateUuidOrEmpty(mixed $value): void
    {
        if (!is_string($value) || ($value !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1)) {
            throw new InvalidArgumentException('invalid_global_settings');
        }
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
