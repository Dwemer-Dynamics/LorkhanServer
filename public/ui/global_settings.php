<?php

declare(strict_types=1);

use LorkhanServer\Application\EffectiveSettingsResolver;
use LorkhanServer\Application\SettingsCatalog;
use LorkhanServer\Application\TranslationPolicy;

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Global Settings';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page global-settings-page-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$requested = trim((string) ($_GET['installation_id'] ?? ''));
$installationId = '';
foreach ($installations as $row) if ($requested !== '' && hash_equals((string) $row['installation_id'], $requested)) $installationId = $requested;
if ($installationId === '' && isset($installations[0])) $installationId = (string) $installations[0]['installation_id'];
$stored = $installationId === '' ? null : $productRepository->globalSettingsForInstallation($installationId);
$storedContent = is_array($stored['content'] ?? null) ? $stored['content'] : [];
$autoLockProfile = $installationId === '' || $productRepository->profileAutoLockEnabled($installationId);
$oghmaSettings = $installationId === ''
    ? ['enabled'=>true,'knowledge_tags'=>'','racial_context_enabled'=>true,'location_context_enabled'=>true,'topic_count'=>1,'result_limit'=>3,'extractor_enabled'=>false,'extractor_timeout_ms'=>1500]
    : $productRepository->oghmaSettings($installationId);
$translationPolicy = $installationId === '' ? TranslationPolicy::defaults()
    : $productRepository->translationPolicyForInstallation($installationId)['content'];
$globalDocument = EffectiveSettingsResolver::globalDocument($storedContent, $oghmaSettings, $translationPolicy, $autoLockProfile);
if (($storedContent['schema'] ?? null) !== SettingsCatalog::GLOBAL_SCHEMA && $installationId !== '') {
    $defaultCore = $productRepository->defaultCoreProfileForInstallation($installationId);
    $legacyRouting = is_array($defaultCore['content']['routing'] ?? null) ? $defaultCore['content']['routing'] : [];
    foreach (SettingsCatalog::systemRoutingFields() as $field) {
        if (is_string($legacyRouting[$field] ?? null)) $globalDocument['system_routing'][$field] = $legacyRouting[$field];
    }
    $legacyRelationship = $defaultCore['content']['settings_overrides']['relationship']['update_chance_percent'] ?? null;
    if (is_int($legacyRelationship)) {
        $globalDocument['relationship']['update_chance_percent'] = $legacyRelationship;
        $globalDocument['relationship']['enabled'] = $legacyRelationship > 0
            && $globalDocument['system_routing']['relationship_configuration_id'] !== '';
    }
}
$settings = $globalDocument['client'];
$autoLockProfile = $globalDocument['profile_management']['auto_lock_profile'];
$oghmaSettings = $globalDocument['oghma'];
$translationPolicy = $globalDocument['translation'];
$contextPolicy = $globalDocument['context'];
$relationshipSettings = $globalDocument['relationship'];
$systemRouting = $globalDocument['system_routing'];
$llmOptions = ['' => 'Disabled'];
foreach ($uiRepository->rows('llm') as $row) {
    $id = (string)($row['configuration_id'] ?? '');
    if ($id !== '') $llmOptions[$id] = (string)($row['name'] ?? $id);
}

$globalSettingsRow = null;
if ($installationId !== '') {
    foreach ($uiRepository->rows('global_settings') as $row) {
        if (hash_equals((string) ($row['installation_id'] ?? ''), $installationId)) { $globalSettingsRow = $row; break; }
    }
}
$settingsConfigurationId = (string) ($globalSettingsRow['configuration_id'] ?? $stored['configuration_id'] ?? '');
$settingsRevision = (int) ($globalSettingsRow['current_revision'] ?? $stored['current_revision'] ?? 0);
$settingsSavedAt = trim((string) ($globalSettingsRow['created_at'] ?? ''));
$revisionHistory = is_array($globalSettingsRow['revisions'] ?? null) ? $globalSettingsRow['revisions'] : [];
$hasStoredSettings = $settingsConfigurationId !== '' && $settingsRevision > 0;
$earlierRevisions = array_values(array_filter(
    $revisionHistory,
    static fn(mixed $revision): bool => is_array($revision) && (int) ($revision['revision'] ?? 0) > 0 && (int) ($revision['revision'] ?? 0) < $settingsRevision
));
$portableScopeNote = 'A portable file includes shared prompt context, blacklists, Rechat, Oghma, translation, relationship evaluation, Auto Lock Profile, and system connector assignments. It never includes installation identity, revision history, API keys, Core Profile response connectors, NPC profiles, voices, or assignments.';
$statusMessages = [
    'saved' => 'Global settings saved to the database.',
    'imported' => 'Preset imported as a new Global Settings revision.',
    'rolled-back' => 'Earlier revision restored as a new Global Settings revision.',
];

$sections = [
    'prompt-rechat' => [
        'Prompt & Rechat' => [
            ['rechat_mode', 'Rechat Mode', '&#x1F501;', 'select', $settings['behavior']['rechat_mode'], 'Tight uses the listener, Conversational prefers the current partner, Group rotates nearby NPCs, and Random selects one mode per chain.', ['values' => ['tight', 'conversational', 'group', 'random']]],
            ['rechat_strict_targeting', 'Strict Rechat Targeting', '&#x1F3AF;', 'boolean', $settings['behavior']['rechat_strict_targeting'], 'Requires the selected responder to address the previous speaker directly.', []],
            ['open_rechat', 'Open Rechat', '&#x1F5E3;&#xFE0F;', 'boolean', $settings['behavior']['open_rechat'], 'Allows nearby scene participants to become the next responder when the selected mode permits it.', []],
            ['end_conversation_cooldown_seconds', 'End Conversation Cooldown', '&#x23F3;', 'integer', $settings['behavior']['end_conversation_cooldown_seconds'], 'Seconds an NPC remains ineligible for another rechat chain after ending a conversation.', ['min' => 0, 'max' => 300]],
            ['relationship_enabled', 'Relationship Evaluation', '&#x1F91D;', 'boolean', $relationshipSettings['enabled'], 'Allow eligible completed conversations to update the saved relationship.', []],
            ['relationship_update_chance_percent', 'Relationship Update Chance', '&#x1F3B2;', 'integer', $relationshipSettings['update_chance_percent'], 'Chance from 0 to 100 that an eligible completed conversation is evaluated.', ['min' => 0, 'max' => 100]],
        ],
    ],
    'ai-memory' => [
        'Memory & Others' => [
            ['auto_lock_profile', 'Auto Lock Profile', '&#x1F512;', 'boolean', $autoLockProfile, 'When enabled, saving an NPC profile automatically locks it to prevent automatic updates from overwriting manual edits.', []],
        ],
        'Translation' => [
            ['translation_provider', 'Provider', '&#x1F310;', 'select', $translationPolicy['provider'], 'Server-only NPC output translation. None leaves NPC output untranslated; DeepL uses the server-held DeepL key and the account endpoint below.', ['values' => ['none' => 'None', 'deepl' => 'DeepL'], 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'provider']],
            ['translation_text', 'Translate Text', '&#x1F4DD;', 'boolean', $translationPolicy['translate_text'], 'Translate NPC subtitles into the target language. Needs DeepL and a target language.', ['feature' => 'config.globals.translation', 'live' => true, 'control' => 'output']],
            ['translation_audio', 'Translate Audio', '&#x1F3A7;', 'boolean', $translationPolicy['translate_audio'], 'Translate NPC speech audio into the target language. Needs DeepL and a target language.', ['feature' => 'config.globals.translation', 'live' => true, 'control' => 'output']],
            ['translation_save_text', 'Save Translated Text', '&#x1F4BE;', 'boolean', $translationPolicy['save_translated_text'], 'Replace NPC speech in context history with the translation. Needs Translate Text or Translate Audio.', ['feature' => 'config.globals.translation', 'live' => true, 'control' => 'save']],
            ['translation_source_language', 'Source Language', '&#x1F5E3;&#xFE0F;', 'text', $translationPolicy['source_language'], 'NPC source language code such as EN or PT-BR. Leave blank for DeepL auto-detection.', ['maxlength' => 16, 'pattern' => '[A-Za-z]{2,3}(-[A-Za-z]{2})?', 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'language']],
            ['translation_target_language', 'Target Language', '&#x1F30D;', 'text', $translationPolicy['target_language'], 'Language code such as DE or PT-BR that NPC output is translated into. Required once Translate Text or Translate Audio is on.', ['maxlength' => 16, 'pattern' => '[A-Za-z]{2,3}(-[A-Za-z]{2})?', 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'target']],
            ['translation_endpoint_url', 'DeepL Account Endpoint', '&#x1F517;', 'select', $translationPolicy['endpoint'], 'DeepL account type. Free and Pro use fixed endpoints, and no other URL is accepted.', ['values' => [TranslationPolicy::FREE_ENDPOINT => 'Free account (api-free.deepl.com)', TranslationPolicy::PRO_ENDPOINT => 'Pro account (api.deepl.com)'], 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'language']],
        ],
    ],
    'context-knowledge' => [
        'Oghma Infinium' => [
            ['oghma_enabled', 'Oghma Infinium', '&#x1F4DA;', 'boolean', $oghmaSettings['enabled'], 'Enable deterministic catalog grounding, access checks, and Oghma prompt context.', []],
            ['oghma_knowledge_tags', 'Oghma Knowledge Tags', '&#x1F4D9;', 'text', $oghmaSettings['knowledge_tags'], 'Installation knowledge classes inherited by Core Profiles and NPCs. Use comma-separated tags such as traveler, dunmer, scholar, or knowall. Leave empty for public basic access only; common is an article-only basic marker, not an NPC tag.', []],
            ['oghma_topic_count', 'Extracted Topics', '&#x1F4DA;', 'integer', $oghmaSettings['topic_count'], 'Maximum conversational topics extracted and injected for each request.', ['min' => 1, 'max' => 3]],
            ['oghma_result_limit', 'Knowledge Results', '&#x1F4D1;', 'integer', $oghmaSettings['result_limit'], 'Maximum authorized or structured-denial Oghma articles injected for each request.', ['min' => 1, 'max' => 5]],
            ['oghma_extractor_timeout_ms', 'Extractor Timeout', '&#x23F1;&#xFE0F;', 'integer', $oghmaSettings['extractor_timeout_ms'], 'Maximum connector-fallback time in milliseconds. Local deterministic retrieval does not use this budget.', ['min' => 250, 'max' => 3000]],
            ['oghma_racial_context_enabled', 'Racial Knowledge Injection', '&#x1F9DD;', 'boolean', $oghmaSettings['racial_context_enabled'], 'Always consider the target and nearby NPC races as Oghma topics when matching articles exist.', []],
            ['oghma_location_context_enabled', 'Location Knowledge Injection', '&#x1F5FA;&#xFE0F;', 'boolean', $oghmaSettings['location_context_enabled'], 'Always consider the current cell, region, and named location as Oghma topics when matching articles exist.', []],
        ],
        'Context Sections' => array_map(
            static fn(string $key, bool $value): array => ['context_section_' . $key, ucwords(str_replace('_', ' ', $key)), '&#x1F4CC;', 'boolean', $value, 'Include this optional context family in NPC prompts.', []],
            array_keys($contextPolicy['sections']), array_values($contextPolicy['sections'])
        ),
        'Context Details' => array_map(
            static fn(string $key, bool $value): array => ['context_detail_' . $key, ucwords(str_replace('_', ' ', $key)), '&#x1F50E;', 'boolean', $value, 'Include this detail when its parent context section is enabled.', []],
            array_keys($contextPolicy['details']), array_values($contextPolicy['details'])
        ),
        'Context Filters' => [
            ['context_event_types', 'Event Type Filter', '&#x1F4CB;', 'multiselect', $contextPolicy['event_types'], 'Only selected event types enter conversation history.', ['values' => array_combine(SettingsCatalog::eventTypes(), array_map(static fn(string $type): string => ucwords(str_replace('_', ' ', $type)), SettingsCatalog::eventTypes()))]],
            ['context_location_blacklist', 'Location Blacklist', '&#x1F5FA;&#xFE0F;', 'textarea', implode("\n", $contextPolicy['location_blacklist']), 'One exact location or cell name per line. Matching history and world context are excluded.', ['maxlength' => 32768]],
            ['context_item_blacklist', 'Item Blacklist', '&#x1F6AB;', 'textarea', implode("\n", $contextPolicy['item_blacklist']), 'One exact item display name or record ID per line. Matching nearby, equipped, inventory, and description entries are excluded.', ['maxlength' => 32768]],
            ['context_magic_effects_blacklist', 'Magic & Effects Blacklist', '&#x2728;', 'textarea', implode("\n", $contextPolicy['magic_effects_blacklist']), 'One exact spell or active-effect name per line.', ['maxlength' => 32768]],
        ],
    ],
    'global-connectors' => [
        'Global Connectors' => [
            ['profile_generation_configuration_id', 'Profile Generation LLM', '&#x1F58B;&#xFE0F;', 'select', $systemRouting['profile_generation_configuration_id'], 'Creates requested NPC, player, and narrator profile text. Disabled never calls a provider.', ['values' => $llmOptions]],
            ['oghma_configuration_id', 'Oghma Extractor LLM', '&#x1F4DA;', 'select', $systemRouting['oghma_configuration_id'], 'Used only when deterministic Oghma matching cannot resolve an explicit lore request.', ['values' => $llmOptions]],
            ['oghma_extractor_enabled', 'Oghma Topic Extractor', '&#x1F9E0;', 'boolean', $oghmaSettings['extractor_enabled'], 'Allow the selected Oghma connector to run as a bounded fallback.', []],
            ['relationship_configuration_id', 'Relationship LLM', '&#x1F91D;', 'select', $systemRouting['relationship_configuration_id'], 'Evaluates eligible completed conversations when Relationship Evaluation is enabled.', ['values' => $llmOptions]],
        ],
    ],
];

$sectionNotes = [
    'Translation' => 'LORKHAN translates NPC subtitles and speech audio. Saving never calls DeepL.',
    'Context Sections' => 'Response rules, NPC identity, speaker rules, the current turn, and the action contract are always included for safety and cannot be disabled.',
    'Context Filters' => 'Blacklists use case-insensitive exact matching. They do not accept patterns or regular expressions.',
];

$additionalStylesheets = ['herika-global-settings.css?v=' . (string) filemtime(__DIR__ . '/css/herika-global-settings.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="global-settings-page">
    <header class="page-header">
        <div class="page-header-row">
            <h1 class="gs-title">Global Settings</h1>
            <div class="page-header-actions">
                <?php if ($hasStoredSettings): ?>
                <a class="btn-settings-transfer" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/global-settings/' . $settingsConfigurationId . '.json'); ?>" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.globals.export')['description']); ?>">&#128228; Export Settings</a>
                <?php endif; ?>
                <?php if ($installations !== []): ?>
                <button type="button" class="btn-settings-transfer" data-gs-portability-toggle="import" aria-controls="gs-portability-panel" aria-expanded="false">&#128229; Import Settings</button>
                <?php endif; ?>
                <button type="submit" class="btn-save-green" name="save_all" value="1" form="gs_form">Save All</button>
            </div>
        </div>
        <?php if ($installations !== []): ?>
        <div class="gs-portability-row">
            <?php if ($hasStoredSettings): ?>
            <span class="gs-revision-chip">Revision <?php echo $settingsRevision; ?><?php if ($settingsSavedAt !== ''): ?> &middot; saved <?php echo lorkhan_ui_h($settingsSavedAt); ?><?php endif; ?></span>
            <?php else: ?>
            <span class="gs-revision-chip is-empty">No saved revision &middot; showing built-in defaults</span>
            <?php endif; ?>
            <button type="button" class="btn-settings-transfer preset-btn-compact" data-gs-portability-toggle="history" aria-controls="gs-portability-panel" aria-expanded="false">Revision history<?php if ($revisionHistory !== []): ?> (<?php echo count($revisionHistory); ?>)<?php endif; ?></button>
            <details class="gs-scope-details">
                <summary id="gs-portability-scope">What portable settings include</summary>
                <p class="gs-portability-note"><?php echo lorkhan_ui_h($portableScopeNote); ?></p>
            </details>
        </div>
        <?php endif; ?>
    </header>

    <?php if (isset($_GET['status'])): $statusKey = is_string($_GET['status']) ? $_GET['status'] : ''; ?><div class="result-ok" role="status"><?php echo lorkhan_ui_h($statusMessages[$statusKey] ?? $statusMessages['saved']); ?></div><?php endif; ?>
    <?php if (count($installations) > 1): ?><div class="installation-row"><label>Installation <select data-installation-select><?php foreach ($installations as $row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"<?php echo $row['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label></div><?php endif; ?>

    <?php if ($installations !== []): ?>
    <section class="gs-portability-panel" id="gs-portability-panel" aria-label="Portable Global Settings">
        <details class="gs-disclosure" data-gs-disclosure="import">
            <summary>Import a settings preset</summary>
            <form class="gs-disclosure-body" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-import">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <div class="gs-field">
                    <label for="gs-preset-file">Preset file</label>
                    <input id="gs-preset-file" type="file" accept="application/json,.json" data-json-import-target="gs-preset-json" aria-describedby="gs-import-help gs-portability-scope">
                </div>
                <div class="gs-field">
                    <label for="gs-preset-json">Preset JSON</label>
                    <textarea id="gs-preset-json" name="preset_json" rows="8" required spellcheck="false" placeholder="Choose an exported .json file or paste its contents here." aria-describedby="gs-import-help gs-portability-scope"></textarea>
                </div>
                <p class="gs-help" id="gs-import-help">Choosing a file fills the box above; the browser accepts JSON files up to 1 MiB. Pasting the document instead works the same way. Importing saves a new Global Settings revision for the selected installation and replaces every value in the typed document, so review the file before importing.</p>
                <div class="gs-actions-row"><button type="submit" class="btn-action-blue" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.globals.import')['description']); ?>">Import Preset</button></div>
            </form>
        </details>

        <details class="gs-disclosure" data-gs-disclosure="history">
            <summary>Revision history<?php if ($revisionHistory !== []): ?> (<?php echo count($revisionHistory); ?>)<?php endif; ?></summary>
            <div class="gs-disclosure-body">
                <?php if (!$hasStoredSettings): ?>
                <p class="gs-help">No Global Settings revision is saved for this installation yet. Save All creates the first revision, and history and Export become available after that.</p>
                <?php else: ?>
                <ul class="gs-revision-list">
                    <?php foreach ($revisionHistory as $revision): if (!is_array($revision)) continue; $revisionNumber = (int) ($revision['revision'] ?? 0); ?>
                    <li>
                        <span class="gs-revision-no">Revision <?php echo $revisionNumber; ?></span>
                        <?php if ($revisionNumber === $settingsRevision): ?><span class="gs-current-tag">Current</span><?php endif; ?>
                        <span class="gs-revision-reason"><?php echo lorkhan_ui_h(trim((string) ($revision['reason'] ?? '')) !== '' ? (string) $revision['reason'] : 'No revision note'); ?></span>
                        <span class="gs-revision-time"><?php echo lorkhan_ui_h((string) ($revision['created_at'] ?? '')); ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php if ($revisionHistory === []): ?><li><span class="gs-revision-no">Revision <?php echo $settingsRevision; ?></span><span class="gs-current-tag">Current</span><span class="gs-revision-reason">No revision history is recorded for this document.</span></li><?php endif; ?>
                </ul>
                <?php if ($earlierRevisions === []): ?>
                <p class="gs-help">Revision <?php echo $settingsRevision; ?> is the only saved revision, so there is nothing earlier to restore.</p>
                <?php else: ?>
                <form class="gs-rollback-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-rollback">
                    <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                    <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                    <input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($settingsConfigurationId); ?>">
                    <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <div class="gs-field">
                        <label for="gs-rollback-revision">Restore revision</label>
                        <select id="gs-rollback-revision" name="revision" aria-describedby="gs-rollback-help gs-portability-scope">
                            <?php foreach ($earlierRevisions as $revision): $revisionNumber = (int) $revision['revision']; ?>
                            <option value="<?php echo $revisionNumber; ?>">Revision <?php echo $revisionNumber; ?><?php $reason = trim((string) ($revision['reason'] ?? '')); if ($reason !== ''): ?> &mdash; <?php echo lorkhan_ui_h($reason); ?><?php endif; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="gs-help" id="gs-rollback-help">Only revisions earlier than the current one can be restored. Restoring copies that document into a new revision on top of revision <?php echo $settingsRevision; ?>; nothing is deleted and no earlier revision is removed from this list.</p>
                    <div class="gs-actions-row"><button type="submit" class="btn-action-blue" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.globals.revision-history')['description']); ?>">Restore Revision</button></div>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </details>
    </section>
    <?php endif; ?>

    <div class="settings-tabs" role="tablist" aria-label="Global settings categories">
        <?php foreach (['prompt-rechat' => '&#x1F501; Prompt & Rechat', 'ai-memory' => '&#x1F9E0; Memory & Others', 'context-knowledge' => '&#x1F4DA; Context & Knowledge', 'global-connectors' => '&#x1F50C; Global Connectors'] as $tabId => $tabLabel): ?>
        <button type="button" class="settings-tab<?php echo $tabId === 'prompt-rechat' ? ' is-active' : ''; ?>" id="settings-tab-<?php echo lorkhan_ui_h($tabId); ?>" role="tab" aria-selected="<?php echo $tabId === 'prompt-rechat' ? 'true' : 'false'; ?>" aria-controls="settings-panel-<?php echo lorkhan_ui_h($tabId); ?>" data-settings-tab="<?php echo lorkhan_ui_h($tabId); ?>"><?php echo $tabLabel; ?></button>
        <?php endforeach; ?>
    </div>

    <?php if ($installations === []): ?><section class="content-section">Connect OpenMW once before configuring installation settings.</section><?php else: ?>
    <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-save" id="gs_form">
        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
        <input type="hidden" name="change_reason" value="Management global settings">
        <div class="content-grid">
            <?php $seenTabs = []; ?>
            <?php foreach ($sections as $tabId => $tabSections): foreach ($tabSections as $sectionTitle => $fields): ?>
            <?php $isFirstTabPanel = !isset($seenTabs[$tabId]); $seenTabs[$tabId] = true; ?>
            <section class="content-section"<?php if ($isFirstTabPanel): ?> id="settings-panel-<?php echo lorkhan_ui_h($tabId); ?>"<?php endif; ?> role="tabpanel" aria-labelledby="settings-tab-<?php echo lorkhan_ui_h($tabId); ?>" data-settings-panel="<?php echo lorkhan_ui_h($tabId); ?>"<?php echo $tabId === 'prompt-rechat' ? '' : ' hidden'; ?>>
                <h2><?php echo lorkhan_ui_h($sectionTitle); ?></h2>
                <?php if (isset($sectionNotes[$sectionTitle])): ?><p class="gs-help gs-section-note"><?php echo lorkhan_ui_h($sectionNotes[$sectionTitle]); ?></p><?php endif; ?>
                <div class="provider-grid">
                    <?php foreach ($fields as $field): [$name, $label, $icon, $type, $value, $help] = $field; $options = $field[6] ?? []; $featureId = (string) ($options['feature'] ?? ''); $controlAttr = isset($options['control']) ? ' data-translation-control="' . lorkhan_ui_h((string) $options['control']) . '"' : ''; $describeAttr = $controlAttr === '' ? '' : ' aria-describedby="gs-help-' . lorkhan_ui_h($name) . '"'; ?>
                    <div class="provider-card"<?php if ($featureId !== ''): ?> title="<?php echo lorkhan_ui_h(lorkhan_ui_feature($featureId)['description']); ?>"<?php endif; ?>>
                        <div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo lorkhan_ui_h($label); ?></span><?php if ($featureId !== '') echo lorkhan_ui_feature_badge($featureId, true); ?><?php if ($type === 'boolean'): ?><span class="provider-toggle"><input type="checkbox" name="<?php echo lorkhan_ui_h($name); ?>" value="1"<?php echo $controlAttr; ?><?php echo $value ? ' checked' : ''; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>></span><?php endif; ?></div></div>
                        <div class="provider-body">
                            <?php if ($type === 'integer'): ?><input type="number" name="<?php echo lorkhan_ui_h($name); ?>" value="<?php echo lorkhan_ui_h($value); ?>" min="<?php echo lorkhan_ui_h($options['min']); ?>" max="<?php echo lorkhan_ui_h($options['max']); ?>" step="1" aria-label="<?php echo lorkhan_ui_h($label); ?>">
                            <?php elseif ($type === 'select'): ?><select name="<?php echo lorkhan_ui_h($name); ?>"<?php echo $controlAttr; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>><?php foreach ($options['values'] as $optionKey => $optionLabel): $option = is_int($optionKey) ? (string) $optionLabel : (string) $optionKey; ?><option value="<?php echo lorkhan_ui_h($option); ?>"<?php echo $option === (string) $value ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($optionLabel); ?></option><?php endforeach; ?></select>
                            <?php elseif ($type === 'multiselect'): ?><div class="gs-checklist" role="group" aria-label="<?php echo lorkhan_ui_h($label); ?>"><?php foreach ($options['values'] as $optionKey => $optionLabel): ?><label><input type="checkbox" name="<?php echo lorkhan_ui_h($name); ?>[]" value="<?php echo lorkhan_ui_h($optionKey); ?>"<?php echo in_array((string)$optionKey, $value, true) ? ' checked' : ''; ?>> <?php echo lorkhan_ui_h($optionLabel); ?></label><?php endforeach; ?></div>
                            <?php elseif ($type === 'textarea'): ?><textarea name="<?php echo lorkhan_ui_h($name); ?>" rows="6" maxlength="<?php echo (int)($options['maxlength'] ?? 32768); ?>" spellcheck="false" aria-label="<?php echo lorkhan_ui_h($label); ?>"><?php echo lorkhan_ui_h($value); ?></textarea>
                            <?php elseif ($type === 'text' || $type === 'url'): ?><input type="<?php echo $type; ?>" name="<?php echo lorkhan_ui_h($name); ?>"<?php echo $controlAttr; ?> value="<?php echo lorkhan_ui_h($value); ?>" maxlength="<?php echo (int) ($options['maxlength'] ?? 512); ?>"<?php if (isset($options['pattern'])): ?> pattern="<?php echo lorkhan_ui_h($options['pattern']); ?>"<?php endif; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>>
                            <?php endif; ?>
                        </div>
                        <div class="provider-help"<?php if ($controlAttr !== ''): ?> id="gs-help-<?php echo lorkhan_ui_h($name); ?>"<?php endif; ?>><?php echo lorkhan_ui_h($help); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; endforeach; ?>
        </div>
    </form>
    <?php endif; ?>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/global-settings.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/global-settings.js')); ?>"></script>
<?php if ($installations !== []): ?><script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>"></script><?php endif; ?>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
