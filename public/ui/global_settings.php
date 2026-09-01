<?php

declare(strict_types=1);

use LorkhanServer\Application\EffectiveSettingsResolver;
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
$settings = is_array($stored['content'] ?? null) ? $stored['content'] : EffectiveSettingsResolver::defaults();
$autoLockProfile = $installationId === '' || $productRepository->profileAutoLockEnabled($installationId);
$oghmaSettings = $installationId === ''
    ? ['enabled'=>true,'knowledge_tags'=>'','racial_context_enabled'=>true,'location_context_enabled'=>true,'topic_count'=>1,'result_limit'=>3,'extractor_enabled'=>false,'extractor_timeout_ms'=>1500]
    : $productRepository->oghmaSettings($installationId);
$translationPolicy = $installationId === '' ? TranslationPolicy::defaults()
    : $productRepository->translationPolicyForInstallation($installationId)['content'];

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
$portableScopeNote = 'A portable file and a restore both cover the typed Global Settings document only. Neither carries or changes installation identity, revision history, Core Profile or NPC overrides, connector routing, API keys, NPC translation policy, Oghma catalog and access settings, Auto Lock Profile, or NPC assignments. Local OpenMW HUD, transcript, and TTS preferences stay client-local even though compatibility fields exist in the strict document.';
$statusMessages = [
    'saved' => 'Global settings saved to the database.',
    'imported' => 'Preset imported as a new Global Settings revision.',
    'rolled-back' => 'Earlier revision restored as a new Global Settings revision.',
];

$sections = [
    'prompt-rechat' => [
        'Rechat' => [
            ['rechat', 'Rechat', '&#x1F501;', 'boolean', $settings['behavior']['rechat'], 'Continue a player-started conversation only after the current spoken-response queue finishes.', []],
            ['rechat_max_depth', 'Rechat Rounds', '&#x1F4AC;', 'integer', $settings['behavior']['rechat_max_depth'], 'Higher values increase the number of times AI NPCs can go back-and-forth during a conversation.', ['min' => 1, 'max' => 20]],
            ['rechat_probability_percent', 'Rechat Probability', '&#x1F3B2;', 'integer', $settings['behavior']['rechat_probability_percent'], 'Chance that an AI NPC will continue an ongoing conversation.', ['min' => 0, 'max' => 100]],
            ['rechat_mode', 'Rechat Mode', '&#x1F501;', 'select', $settings['behavior']['rechat_mode'], 'Tight uses the listener, Conversational prefers the current partner, Group rotates nearby NPCs, and Random selects one mode per chain.', ['values' => ['tight', 'conversational', 'group', 'random']]],
            ['rechat_strict_targeting', 'Strict Rechat Targeting', '&#x1F3AF;', 'boolean', $settings['behavior']['rechat_strict_targeting'], 'Requires the selected responder to address the previous speaker directly.', []],
            ['open_rechat', 'Open Rechat', '&#x1F5E3;&#xFE0F;', 'boolean', $settings['behavior']['open_rechat'], 'Allows nearby scene participants to become the next responder when the selected mode permits it.', []],
            ['end_conversation_cooldown_seconds', 'End Conversation Cooldown', '&#x23F3;', 'integer', $settings['behavior']['end_conversation_cooldown_seconds'], 'Seconds an NPC remains ineligible for another rechat chain after ending a conversation.', ['min' => 0, 'max' => 300]],
        ],
    ],
    'ai-memory' => [
        'Profiles' => [
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
        'Context & Knowledge' => [
            ['recent_turn_limit', 'Recent Conversation Turns', '&#x1F4AC;', 'integer', $settings['memory']['recent_turn_limit'], 'Maximum recent conversation turns included in each NPC prompt.', ['min' => 1, 'max' => 100]],
            ['oghma_enabled', 'Oghma Infinium', '&#x1F4DA;', 'boolean', $oghmaSettings['enabled'], 'Enable deterministic catalog grounding, access checks, and Oghma prompt context.', []],
            ['oghma_knowledge_tags', 'Oghma Knowledge Tags', '&#x1F4D9;', 'text', $oghmaSettings['knowledge_tags'], 'Installation knowledge classes inherited by Core Profiles and NPCs. Use comma-separated tags such as traveler, dunmer, scholar, or knowall. Leave empty for public basic access only; common is an article-only basic marker, not an NPC tag.', []],
            ['oghma_extractor_enabled', 'Oghma Topic Extractor', '&#x1F9E0;', 'boolean', $oghmaSettings['extractor_enabled'], 'Use the inherited connector once when local catalog grounding cannot resolve an explicit lore request. Connector suggestions must resolve to exact catalog topics.', []],
            ['oghma_topic_count', 'Extracted Topics', '&#x1F4DA;', 'integer', $oghmaSettings['topic_count'], 'Maximum conversational topics extracted and injected for each request.', ['min' => 1, 'max' => 3]],
            ['oghma_result_limit', 'Knowledge Results', '&#x1F4D1;', 'integer', $oghmaSettings['result_limit'], 'Maximum authorized or structured-denial Oghma articles injected for each request.', ['min' => 1, 'max' => 5]],
            ['oghma_extractor_timeout_ms', 'Extractor Timeout', '&#x23F1;&#xFE0F;', 'integer', $oghmaSettings['extractor_timeout_ms'], 'Maximum connector-fallback time in milliseconds. Local deterministic retrieval does not use this budget.', ['min' => 250, 'max' => 3000]],
            ['oghma_racial_context_enabled', 'Racial Knowledge Injection', '&#x1F9DD;', 'boolean', $oghmaSettings['racial_context_enabled'], 'Always consider the target and nearby NPC races as Oghma topics when matching articles exist.', []],
            ['oghma_location_context_enabled', 'Location Knowledge Injection', '&#x1F5FA;&#xFE0F;', 'boolean', $oghmaSettings['location_context_enabled'], 'Always consider the current cell, region, and named location as Oghma topics when matching articles exist.', []],
        ],
    ],
];

$sectionNotes = [
    'Translation' => 'LORKHAN translates NPC output only: NPC subtitles and NPC speech audio. OpenMW player input is not server-generated TTS, so the server has no player speech to translate. These values are stored server-side, frozen for each turn, and saving never calls DeepL.',
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
                <button type="submit" class="btn-save-green" name="save_all" value="1" form="gs_form">Save All</button>
            </div>
        </div>
    </header>

    <?php if (isset($_GET['status'])): $statusKey = is_string($_GET['status']) ? $_GET['status'] : ''; ?><div class="result-ok" role="status"><?php echo lorkhan_ui_h($statusMessages[$statusKey] ?? $statusMessages['saved']); ?></div><?php endif; ?>
    <?php if (count($installations) > 1): ?><div class="installation-row"><label>Installation <select data-installation-select><?php foreach ($installations as $row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"<?php echo $row['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label></div><?php endif; ?>

    <?php if ($installations !== []): ?>
    <section class="gs-portability" aria-labelledby="gs-portability-title">
        <div class="gs-portability-head">
            <h2 class="gs-portability-title" id="gs-portability-title">Portable Global Settings</h2>
            <?php if ($hasStoredSettings): ?>
            <span class="gs-revision-chip">Revision <?php echo $settingsRevision; ?><?php if ($settingsSavedAt !== ''): ?> &middot; saved <?php echo lorkhan_ui_h($settingsSavedAt); ?><?php endif; ?></span>
            <?php else: ?>
            <span class="gs-revision-chip is-empty">No saved revision &middot; showing built-in defaults</span>
            <?php endif; ?>
            <div class="gs-portability-actions">
                <?php if ($hasStoredSettings): ?>
                <a class="btn-action-blue" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/global-settings/' . $settingsConfigurationId . '.json'); ?>" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.globals.export')['description']); ?>">Export Settings</a>
                <?php endif; ?>
            </div>
        </div>
        <details class="gs-scope-details">
            <summary id="gs-portability-scope">What portable settings include</summary>
            <p class="gs-portability-note"><?php echo lorkhan_ui_h($portableScopeNote); ?></p>
        </details>

        <details class="gs-disclosure">
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

        <details class="gs-disclosure">
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

    <nav class="settings-tabs" role="tablist" aria-label="Global settings categories">
        <?php foreach (['prompt-rechat' => '&#x1F501; Rechat', 'ai-memory' => '&#x1F310; Profiles & Translation', 'context-knowledge' => '&#x1F4DA; Context & Knowledge'] as $tabId => $tabLabel): ?>
        <button type="button" class="settings-tab<?php echo $tabId === 'prompt-rechat' ? ' is-active' : ''; ?>" id="settings-tab-<?php echo lorkhan_ui_h($tabId); ?>" role="tab" aria-selected="<?php echo $tabId === 'prompt-rechat' ? 'true' : 'false'; ?>" data-settings-tab="<?php echo lorkhan_ui_h($tabId); ?>"><?php echo $tabLabel; ?></button>
        <?php endforeach; ?>
    </nav>

    <?php if ($installations === []): ?><section class="content-section">Connect OpenMW once before configuring installation settings.</section><?php else: ?>
    <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-save" id="gs_form">
        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
        <input type="hidden" name="change_reason" value="Management global settings">
        <div class="content-grid">
            <?php foreach ($sections as $tabId => $tabSections): foreach ($tabSections as $sectionTitle => $fields): ?>
            <section class="content-section" role="tabpanel" aria-labelledby="settings-tab-<?php echo lorkhan_ui_h($tabId); ?>" data-settings-panel="<?php echo lorkhan_ui_h($tabId); ?>"<?php echo $tabId === 'prompt-rechat' ? '' : ' hidden'; ?>>
                <h2><?php echo lorkhan_ui_h($sectionTitle); ?></h2>
                <?php if (isset($sectionNotes[$sectionTitle])): ?><p class="gs-help gs-section-note"><?php echo lorkhan_ui_h($sectionNotes[$sectionTitle]); ?></p><?php endif; ?>
                <div class="provider-grid">
                    <?php foreach ($fields as $field): [$name, $label, $icon, $type, $value, $help] = $field; $options = $field[6] ?? []; $featureId = (string) ($options['feature'] ?? ''); $controlAttr = isset($options['control']) ? ' data-translation-control="' . lorkhan_ui_h((string) $options['control']) . '"' : ''; $describeAttr = $controlAttr === '' ? '' : ' aria-describedby="gs-help-' . lorkhan_ui_h($name) . '"'; ?>
                    <div class="provider-card"<?php if ($featureId !== ''): ?> title="<?php echo lorkhan_ui_h(lorkhan_ui_feature($featureId)['description']); ?>"<?php endif; ?>>
                        <div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo lorkhan_ui_h($label); ?></span><?php if ($featureId !== '') echo lorkhan_ui_feature_badge($featureId, true); ?><?php if ($type === 'boolean'): ?><span class="provider-toggle"><input type="checkbox" name="<?php echo lorkhan_ui_h($name); ?>" value="1"<?php echo $controlAttr; ?><?php echo $value ? ' checked' : ''; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>></span><?php endif; ?></div></div>
                        <div class="provider-body">
                            <?php if ($type === 'integer'): ?><input type="number" name="<?php echo lorkhan_ui_h($name); ?>" value="<?php echo lorkhan_ui_h($value); ?>" min="<?php echo lorkhan_ui_h($options['min']); ?>" max="<?php echo lorkhan_ui_h($options['max']); ?>" step="1" aria-label="<?php echo lorkhan_ui_h($label); ?>">
                            <?php elseif ($type === 'select'): ?><select name="<?php echo lorkhan_ui_h($name); ?>"<?php echo $controlAttr; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>><?php foreach ($options['values'] as $optionKey => $optionLabel): $option = is_int($optionKey) ? (string) $optionLabel : (string) $optionKey; ?><option value="<?php echo lorkhan_ui_h($option); ?>"<?php echo $option === (string) $value ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($optionLabel); ?></option><?php endforeach; ?></select>
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
