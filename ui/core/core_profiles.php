<?php

declare(strict_types=1);

use LorkhanServer\Application\EffectiveSettingsResolver;

$pageTitle = 'Core Profiles';
$topNavSection = 'configuration';
$embedded = (string) ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page profiles-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$requestedInstallation = trim((string) ($_GET['installation_id'] ?? ''));
$installationId = '';
foreach ($installations as $installation) {
    if ($requestedInstallation !== '' && hash_equals((string) $installation['installation_id'], $requestedInstallation)) {
        $installationId = $requestedInstallation;
    }
}
if ($installationId === '' && isset($installations[0])) $installationId = (string) $installations[0]['installation_id'];
if ($installationId !== '') $productRepository->defaultCoreProfileForInstallation($installationId, gmdate('Y-m-d\TH:i:s\Z'), true);

$forInstallation = static fn(array $rows): array => array_values(array_filter(
    $rows,
    static fn(array $row): bool => (string) ($row['installation_id'] ?? '') === $installationId
));
$profiles = $forInstallation($uiRepository->rows('core_profiles'));
$llm = $forInstallation($uiRepository->rows('llm'));
$tts = $forInstallation($uiRepository->rows('tts'));
$prompts = $forInstallation($uiRepository->rows('prompts'));
$prompts = array_values(array_filter($prompts, static fn(array $row): bool => ($row['content']['purpose'] ?? '') !== 'narrator_event'));

$selectedId = trim((string) ($_GET['edit'] ?? ''));
$selected = null;
foreach ($profiles as $profile) {
    if ($selectedId !== '' && hash_equals((string) $profile['core_profile_id'], $selectedId)) $selected = $profile;
}
$effectiveCoreSettings = [];
if ($installationId !== '') {
    $globalSettings = $productRepository->globalSettingsForInstallation($installationId);
    $globalContent = is_array($globalSettings['content'] ?? null) ? $globalSettings['content'] : [];
    $coreContent = is_array($selected['content'] ?? null) ? $selected['content'] : [];
    $effectiveCoreSettings = (new EffectiveSettingsResolver())->resolve($globalContent, $coreContent, []);
}
$importMode = isset($_GET['import']);
$showCreate = !$importMode && (isset($_GET['create']) || $profiles === []);

$pageUrl = $webRoot . '/ui/core/core_profiles.php';
$queryFor = static function (array $values = []) use ($pageUrl, $installationId, $embedded): string {
    if ($installationId !== '') $values['installation_id'] = $installationId;
    if ($embedded) $values['embed'] = '1';
    return $pageUrl . ($values === [] ? '' : '?' . http_build_query($values));
};

$configurationLabels = [];
foreach (array_merge($llm, $tts, $prompts) as $configuration) {
    $configurationLabels[(string) ($configuration['configuration_id'] ?? '')] = (string) ($configuration['name'] ?? '');
}
$profileRouteLabel = static function (array $profile, string $field, string $emptyLabel = 'Inherited') use ($configurationLabels): string {
    $content = is_array($profile['content'] ?? null) ? $profile['content'] : [];
    $routing = is_array($content['routing'] ?? null) ? $content['routing'] : [];
    $id = trim((string) ($routing[$field] ?? ''));
    return $id !== '' ? ($configurationLabels[$id] ?? 'Missing connector') : $emptyLabel;
};
$usedProfileSlots = [];
foreach ($profiles as $profile) {
    $slot = (int) ($profile['slot'] ?? 0);
    if ($slot >= 1 && $slot <= 4) $usedProfileSlots[$slot] = (string) $profile['core_profile_id'];
}

$ruleMatchFields = [
    ['key' => 'names', 'label' => 'NPC Name', 'icon' => '👤', 'add' => 'Add a name', 'hint' => 'The NPC name as OpenMW reports it.'],
    ['key' => 'races', 'label' => 'Race', 'icon' => '🧬', 'add' => 'Add a race', 'hint' => 'The race recorded for the NPC.'],
    ['key' => 'genders', 'label' => 'Gender', 'icon' => '⚧', 'add' => 'Add a gender', 'hint' => 'The gender recorded for the NPC.'],
    ['key' => 'factions', 'label' => 'Faction', 'icon' => '⚔', 'add' => 'Add a faction', 'hint' => 'An OpenMW textual faction ID, not a numeric ID.'],
    ['key' => 'content_files', 'label' => 'Source Mods', 'icon' => '🧩', 'add' => 'Add a content file', 'hint' => 'The content file the NPC record comes from.'],
    ['key' => 'classes', 'label' => 'Class', 'icon' => '📜', 'add' => 'Add a class', 'hint' => 'The class recorded for the NPC.'],
];

$additionalStylesheets = ['herika-profiles.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-profiles.css')];
$includeManagementStyles = false;
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="profiles-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-header lorkhan-page-head">
        <h1 class="api-title lorkhan-page-head-title">Profiles</h1>
        <p class="page-subtitle lorkhan-page-head-note">Manage Core Profiles, response models, voice, prompts, and inherited NPC settings.</p>
    </div>

    <?php if (isset($_GET['status'])): ?><div class="lorkhan-status" role="status"><?php echo (is_string($_GET['status']) && $_GET['status'] === 'imported') ? 'Settings preset imported as a new unassigned Core Profile. Review it below.' : 'Core Profile change saved.'; ?></div><?php endif; ?>
    <?php if ($installations === []): ?>
        <section class="connector-card profiles-empty">Connect OpenMW once before creating Core Profiles.</section>
    <?php else: ?>
        <?php if (count($installations) > 1): ?>
            <div class="profiles-installation-switcher">
                <label>Installation<select data-installation-select><?php foreach ($installations as $installation): ?><option value="<?php echo lorkhan_ui_h($installation['installation_id']); ?>"<?php echo $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($installation['display_name']); ?></option><?php endforeach; ?></select></label>
            </div>
        <?php endif; ?>

        <div class="llm-layout">
            <aside class="llm-left">
                <div class="sidebar-action-grid">
                    <a class="btn-save" href="<?php echo lorkhan_ui_h($queryFor(['create' => '1'])); ?>">New</a>
                    <a class="btn-primary" href="<?php echo lorkhan_ui_h($queryFor(['import' => '1'])); ?>" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.profiles.import')['description']); ?>">Import</a>
                    <button class="btn-primary" id="profile-rules-open" type="button" data-profile-rules-open aria-haspopup="dialog" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.profiles.rules')['description']); ?>">Rules</button>
                    <button class="btn-primary" id="profile-connector-test-open" type="button" data-profile-test-open aria-haspopup="dialog" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.profiles.test')['description']); ?>">Test</button>
                </div>

                <div class="conn-list" aria-label="Core Profiles">
                    <div class="connector-card profile-slots">
                        <div class="connector-title" title="Can be assigned to NPCs in game through LORKHAN profile controls">Profile Slots <span class="profile-info">&#x24D8;</span></div>
                        <?php foreach (range(1, 4) as $slot): $slotted = null; foreach ($profiles as $profile) if ((int) ($profile['slot'] ?? 0) === $slot) $slotted = $profile; ?>
                            <?php if ($slotted !== null): ?>
                                <a class="slot-row" href="<?php echo lorkhan_ui_h($queryFor(['edit' => $slotted['core_profile_id']])); ?>" title="Open <?php echo lorkhan_ui_h($slotted['label']); ?>"><span class="slot-key">Slot <?php echo $slot; ?></span><span class="slot-val"><?php echo lorkhan_ui_h($slotted['label']); ?></span></a>
                            <?php else: ?>
                                <div class="slot-row slot-empty"><span class="slot-key">Slot <?php echo $slot; ?></span><span class="slot-val">— Empty —</span></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php foreach ($profiles as $profile):
                        $active = $selected !== null && $selected['core_profile_id'] === $profile['core_profile_id'];
                        $defaultNpc = filter_var($profile['default_npc'] ?? false, FILTER_VALIDATE_BOOL);
                        $usage = (int) ($profile['profile_usage'] ?? 0);
                    ?>
                        <article class="conn-li<?php echo $active ? ' active' : ''; ?>">
                            <a class="profile-card-link" href="<?php echo lorkhan_ui_h($queryFor(['edit' => $profile['core_profile_id']])); ?>">
                                <span class="head"><span class="title"><?php echo lorkhan_ui_h($profile['label']); ?></span><span class="pf-badges"><?php if ($defaultNpc): ?><span class="pf-flag">&#x1F464; NPC</span><?php endif; ?><span class="pf-flag"><?php echo $usage; ?> NPCs</span></span></span>
                                <span class="pf-lines">
                                    <span class="pf-line"><span class="pf-icon">&#x1F50A;</span><span class="pf-key">TTS Connector</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'tts_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F579;&#xFE0F;</span><span class="pf-key">Standard LLM</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'llm_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F3C3;</span><span class="pf-key">Fast LLM</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'llm_fast_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F4AA;</span><span class="pf-key">Powerful LLM</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'llm_powerful_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F9EA;</span><span class="pf-key">Experimental LLM</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'llm_experimental_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F504;</span><span class="pf-key">Fallback LLM</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'llm_fallback_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F4AC;</span><span class="pf-key">Dialogue Prompt</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'prompt_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F4D3;</span><span class="pf-key">Diary LLM</span><span class="pf-val"><?php echo lorkhan_ui_h($profileRouteLabel($profile, 'diary_generation_configuration_id', 'Disabled')); ?></span></span>
                                </span>
                            </a>
                            <div class="actions profile-card-actions">
                                <a class="btn-primary" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/core-profile-settings/' . (string) $profile['core_profile_id'] . '.json'); ?>" aria-label="Export settings preset for <?php echo lorkhan_ui_h($profile['label']); ?>" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.profiles.export')['description']); ?>">Export</a>
                                <?php if (!$defaultNpc && $usage === 0): ?>
                                    <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-delete" data-confirm="Delete this unused Core Profile?"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo lorkhan_ui_h($profile['core_profile_id']); ?>"><button class="btn-danger" type="submit">Delete</button></form>
                                <?php else: ?>
                                    <button class="btn-danger feature-placeholder-control" type="button" disabled aria-disabled="true" title="The default or assigned Core Profile cannot be deleted.">Delete <?php echo lorkhan_ui_feature_badge('config.profiles.delete-protected', true); ?></button>
                                <?php endif; ?>
                                <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-clone"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo lorkhan_ui_h($profile['core_profile_id']); ?>"><button class="btn-primary" type="submit">Clone</button></form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </aside>

            <section class="llm-right">
                <div class="form-container wide-centered">
                <?php if ($importMode): ?>
                    <form class="core-profile-form profile-import-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-settings-import" data-track-dirty>
                        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                        <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                        <div class="profile-editor-toolbar"><div><div class="profile-editor-toolbar-label">Importing Preset</div><div class="profile-editor-toolbar-name">Core Profile Settings</div></div><div class="profile-import-toolbar-actions"><span class="unsaved-indicator" data-dirty-indicator hidden>Unsaved changes</span><a class="btn-base" href="<?php echo lorkhan_ui_h($queryFor([])); ?>">Cancel</a><button type="submit" class="btn-save">Import Preset</button></div></div>
                        <div class="connector-card profile-import-card">
                            <div class="connector-title">Settings Preset</div>
                            <div class="profile-import-fields">
                                <label for="core-profile-preset-file">Preset file<input id="core-profile-preset-file" type="file" accept="application/json,.json" data-json-import-target="core-profile-preset-json" aria-describedby="core-profile-import-help"></label>
                                <label for="core-profile-preset-json">Preset JSON<textarea id="core-profile-preset-json" name="preset_json" required spellcheck="false" placeholder="Choose an exported .json file or paste its contents here." aria-describedby="core-profile-import-help"></textarea></label>
                            </div>
                            <p class="hint" id="core-profile-import-help">Importing creates a new unassigned Core Profile from settings overrides only. Prompt text, connector routing, identifiers, slots, default status, revision history, and NPC assignments are never carried by a preset, so set those on the new profile afterwards.</p>
                        </div>
                    </form>
                <?php elseif ($showCreate):
                    $content = ['schema' => 'lorkhan.core-profile.v1', 'prompt' => '', 'routing' => [], 'settings_overrides' => []];
                    $profileMeta = ['label' => '', 'slot' => null, 'default_npc' => false];
                    $coreProfileMode = 'create';
                ?>
                    <form class="core-profile-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-create" data-track-dirty>
                        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                        <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                        <div class="profile-editor-toolbar"><div><div class="profile-editor-toolbar-label">Creating Profile</div><div class="profile-editor-toolbar-name">New Profile</div></div><div class="profile-editor-actions"><span class="unsaved-indicator" data-dirty-indicator hidden>Unsaved changes</span><button type="submit" class="btn-save">Create Profile</button></div></div>
                        <?php include __DIR__ . '/tmpl/core_profile_fields.php'; ?>
                    </form>
                <?php elseif ($selected !== null):
                    $content = is_array($selected['content'] ?? null) ? $selected['content'] : [];
                    $profileMeta = $selected;
                    $coreProfileMode = 'edit';
                ?>
                    <form class="core-profile-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-save" data-track-dirty
                        data-profile-copy-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1/core-profile-copy-setting" data-profile-copy-revision="<?php echo (int)$selected['current_revision']; ?>">
                        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                        <input type="hidden" name="core_profile_id" value="<?php echo lorkhan_ui_h($selected['core_profile_id']); ?>">
                        <div class="profile-editor-toolbar"><div><div class="profile-editor-toolbar-label">Editing Profile</div><div class="profile-editor-toolbar-name"><?php echo lorkhan_ui_h($selected['label']); ?></div></div><div class="profile-editor-actions"><span class="unsaved-indicator" data-dirty-indicator hidden>Unsaved changes</span><button type="submit" class="btn-save">Save All</button></div></div>
                        <?php include __DIR__ . '/tmpl/core_profile_presets.php'; ?>
                        <?php include __DIR__ . '/tmpl/core_profile_fields.php'; ?>
                    </form>

                        <?php lorkhan_ui_effective_settings_summary($effectiveCoreSettings, 'Effective Core Profile settings and sources'); ?>
                    <details class="connector-card profile-history"><summary>Revision History</summary>
                        <?php $history = is_array($selected['revisions'] ?? null) ? $selected['revisions'] : []; lorkhan_ui_table($history, 'No revisions.'); ?>
                        <?php $earlier = array_values(array_filter($history, static fn(array $revision): bool => (int) ($revision['revision'] ?? 0) !== (int) $selected['current_revision'])); if ($earlier !== []): ?>
                            <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-rollback"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo lorkhan_ui_h($selected['core_profile_id']); ?>"><label>Restore revision<select name="revision"><?php foreach ($earlier as $revision): ?><option value="<?php echo (int) $revision['revision']; ?>">Revision <?php echo (int) $revision['revision']; ?> &mdash; <?php echo lorkhan_ui_h($revision['reason'] ?? ''); ?></option><?php endforeach; ?></select></label><button type="submit" class="btn-save">Restore Earlier Revision</button></form>
                        <?php endif; ?>
                    </details>
                    <?php if (!filter_var($selected['default_npc'] ?? false, FILTER_VALIDATE_BOOL)): ?><form class="profile-default-action" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/core-profile-default"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo lorkhan_ui_h($selected['core_profile_id']); ?>"><button type="submit" class="btn-save">Make Default NPC Profile</button></form><?php endif; ?>
                <?php else: ?>
                    <div class="connector-placeholder"><div>No profile selected</div><p>Select a profile from the list on the left to view and edit its settings.</p></div>
                <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="profile-test-overlay" data-profile-test-overlay hidden>
            <div class="profile-test-shell" role="dialog" aria-modal="true" aria-labelledby="profile-test-title" aria-describedby="profile-test-warning" data-profile-test-dialog data-profile-test-endpoint="<?php echo lorkhan_ui_h($managementBasePath . '/api/v1/profile-connector-tests'); ?>" data-profile-test-csrf="<?php echo lorkhan_ui_h($csrf); ?>" data-profile-test-installation="<?php echo lorkhan_ui_h($installationId); ?>">
                <div class="modal-header profile-test-header">
                    <h2 class="modal-title" id="profile-test-title">Test Core Profile Connectors</h2>
                    <button class="profile-test-dismiss" type="button" data-profile-test-close aria-label="Close connector tests">&#215;</button>
                </div>
                <div class="modal-body profile-test-body">
                    <p class="profile-test-warning" id="profile-test-warning"><strong>Running these tests contacts each configured connector.</strong> Deterministic mock connectors stay local. Each live connector receives one small request, so a paid provider may charge you for that usage. Nothing is sent until you press <strong>Run tests</strong>.</p>
                    <p class="hint profile-test-help">Every connector is tested once, at most two at a time, and the result appears in each Core Profile slot that uses that connector. Test replies are never saved and never shown; only the short summary the server returns is displayed, with no credentials, endpoints, or provider output.</p>
                    <p class="profile-test-scope" data-profile-test-scope></p>
                    <div class="profile-test-counts" data-profile-test-counts role="group" aria-label="Connector test result totals"></div>
                    <div class="profile-test-progress" data-profile-test-progress role="progressbar" aria-label="Connector tests completed" aria-valuemin="0" aria-valuemax="0" aria-valuenow="0" hidden><span class="profile-test-progress-fill" id="profile-test-progress-fill" data-profile-test-progress-fill></span></div>
                    <p class="profile-test-status" data-profile-test-status role="status" aria-live="polite">Loading the connector test plan.</p>
                    <div class="profile-test-plan" data-profile-test-plan></div>
                </div>
                <div class="modal-footer profile-test-footer">
                    <button class="btn-save" type="button" data-profile-test-run disabled>Run tests</button>
                    <button class="btn-danger" type="button" data-profile-test-stop hidden>Stop queued tests</button>
                    <button class="btn-base" type="button" data-profile-test-reload hidden>Reload plan</button>
                    <button class="btn-base" type="button" data-profile-test-close>Close</button>
                </div>
            </div>
        </div>

        <div class="profile-rules-overlay" data-profile-rules-overlay hidden>
            <div class="profile-rules-shell" role="dialog" aria-modal="true" aria-labelledby="profile-rules-title" aria-describedby="profile-rules-intro" data-profile-rules-dialog data-profile-rules-endpoint="<?php echo lorkhan_ui_h($managementBasePath . '/api/v1/profile-assignment-rules'); ?>" data-profile-rules-csrf="<?php echo lorkhan_ui_h($csrf); ?>" data-profile-rules-installation="<?php echo lorkhan_ui_h($installationId); ?>">
                <div class="modal-header profile-rules-header">
                    <h2 class="modal-title" id="profile-rules-title">Profile Rules</h2>
                    <div class="modal-actions profile-rules-header-actions">
                        <button class="btn-save" type="button" data-profile-rules-new hidden>+ New Rule</button>
                        <button class="modal-close" type="button" data-profile-rules-close>Close</button>
                    </div>
                </div>
                <div class="modal-body profile-rules-body">
                    <div class="connector-help rule-help" id="profile-rules-intro">
                        <strong>Profile Rules automatically assign profiles when NPCs are first activated.</strong>
                        <span>Choose one or more values inside a field to match any of them. Different fields must all match. Existing NPC assignments are preserved. Text matches in full without regard to capitals; higher priority wins, then the older rule.</span>
                    </div>
                    <p class="profile-rules-status" data-profile-rules-status role="status" aria-live="polite">Loading assignment rules.</p>

                    <div class="profile-rules-list-view" data-profile-rules-list-view>
                        <p class="profile-rules-order" data-profile-rules-order hidden>Listed in the order they are checked, highest priority first.</p>
                        <div class="profile-rules-list" data-profile-rules-list></div>
                    </div>

                    <form class="profile-rules-form" id="profile-rules-form" data-profile-rules-form novalidate hidden>
                        <div class="profile-rules-editor-header">
                            <div class="profile-rules-title-row">
                                <h3 class="profile-rules-form-title" data-profile-rules-form-title>New Rule</h3>
                                <span class="profile-rules-pill" data-profile-rules-form-state></span>
                            </div>
                            <div class="profile-rules-editor-actions">
                                <button class="btn-save" type="submit" form="profile-rules-form" data-profile-rules-save hidden>✓ Save</button>
                                <button class="btn-base" type="button" data-profile-rules-cancel hidden>× Cancel</button>
                            </div>
                        </div>
                        <p class="profile-rules-error" data-profile-rules-error role="alert" hidden></p>
                        <div class="profile-rules-fields">
                            <div class="profile-rules-field profile-rules-field-wide">
                                <label for="profile-rules-description">Rule Name</label>
                                <input id="profile-rules-description" type="text" maxlength="200" autocomplete="off" aria-required="true" aria-describedby="profile-rules-description-hint" data-profile-rules-description>
                                <p class="sr-only" id="profile-rules-description-hint">A short name so you can recognise this rule in the list.</p>
                            </div>
                            <div class="profile-rules-field">
                                <label for="profile-rules-profile">Assign Profile</label>
                                <select id="profile-rules-profile" aria-required="true" aria-describedby="profile-rules-profile-hint" data-profile-rules-profile></select>
                                <p class="sr-only" id="profile-rules-profile-hint">The Core Profile given to a matching new NPC.</p>
                            </div>
                            <div class="profile-rules-field profile-rules-field-check">
                                <div class="profile-rules-check-line">
                                    <input id="profile-rules-enabled" type="checkbox" aria-describedby="profile-rules-enabled-hint" data-profile-rules-enabled>
                                    <label for="profile-rules-enabled">Enabled</label>
                                </div>
                                <p class="sr-only" id="profile-rules-enabled-hint">A disabled rule is kept but never checked.</p>
                            </div>
                        </div>

                        <section class="profile-rules-match" aria-labelledby="profile-rules-match-heading">
                            <h3 id="profile-rules-match-heading">Match NPCs When <span class="profile-rules-match-hint">Any value inside a field; all populated fields must match. Fill at least one field.</span></h3>
                            <div class="profile-rules-match-grid">
                                <?php foreach ($ruleMatchFields as $matchField): $matchBase = 'profile-rules-' . str_replace('_', '-', $matchField['key']); ?>
                                    <div class="profile-rules-match-field" role="group" aria-labelledby="<?= lorkhan_ui_h($matchBase) ?>-label" data-profile-rules-match="<?php echo lorkhan_ui_h($matchField['key']); ?>">
                                        <div class="profile-rules-picker-label" id="<?= lorkhan_ui_h($matchBase) ?>-label"><span class="profile-rules-picker-icon" aria-hidden="true"><?= lorkhan_ui_h($matchField['icon']) ?></span><?= lorkhan_ui_h($matchField['label']) ?></div>
                                        <p class="sr-only" id="<?php echo lorkhan_ui_h($matchBase); ?>-hint"><?php echo lorkhan_ui_h($matchField['hint']); ?></p>
                                        <?php if (in_array($matchField['key'], ['factions','content_files'], true)): ?>
                                        <div class="profile-rules-detected-controls">
                                            <select data-profile-rules-detected aria-label="<?= lorkhan_ui_h('Select detected '.$matchField['label']) ?>"><option value="">Select a detected value</option></select>
                                            <button class="btn-base" type="button" data-profile-rules-detected-add>＋ Add</button>
                                        </div>
                                        <?php endif; ?>
                                        <div class="profile-rules-match-add">
                                            <label class="sr-only" for="<?php echo lorkhan_ui_h($matchBase); ?>-input"><?php echo lorkhan_ui_h($matchField['add']); ?></label>
                                            <input id="<?php echo lorkhan_ui_h($matchBase); ?>-input" type="text" placeholder="<?= lorkhan_ui_h($matchField['add']) ?>" maxlength="256" autocomplete="off" list="<?php echo lorkhan_ui_h($matchBase); ?>-options" aria-describedby="<?php echo lorkhan_ui_h($matchBase); ?>-hint" data-profile-rules-match-input>
                                            <datalist id="<?php echo lorkhan_ui_h($matchBase); ?>-options" data-profile-rules-options></datalist>
                                            <button class="btn-base" type="button" data-profile-rules-match-add><?= in_array($matchField['key'], ['factions','content_files'], true) ? '＋ Add Typed' : '＋ Add' ?></button>
                                        </div>
                                        <ul class="profile-rules-match-values" aria-label="<?php echo lorkhan_ui_h($matchField['label']); ?> in this rule" data-profile-rules-match-values hidden></ul>
                                        <p class="profile-rules-match-empty" data-profile-rules-match-empty>No values selected</p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <details class="profile-rules-priority-options">
                            <summary>Rule Priority</summary>
                            <div class="profile-rules-field">
                                <label for="profile-rules-priority">Priority</label>
                                <input id="profile-rules-priority" type="number" min="-100000" max="100000" step="1" inputmode="numeric" aria-describedby="profile-rules-priority-hint" data-profile-rules-priority>
                                <p class="hint" id="profile-rules-priority-hint">A whole number. Higher numbers are checked first.</p>
                            </div>
                        </details>

                        <div class="profile-rules-confirm" role="group" aria-label="Confirm deleting this rule" data-profile-rules-confirm hidden>
                            <p class="profile-rules-confirm-text" data-profile-rules-confirm-text></p>
                            <div class="profile-rules-confirm-actions">
                                <button class="btn-danger" type="button" data-profile-rules-confirm-delete>Yes, delete this rule</button>
                                <button class="btn-base" type="button" data-profile-rules-confirm-cancel>Keep this rule</button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer profile-rules-footer">
                    <button class="btn-base" type="button" data-profile-rules-reload hidden>Reload</button>
                    <button class="btn-danger" type="button" data-profile-rules-delete hidden>Delete rule</button>
                    <button class="btn-base" type="button" data-profile-rules-close>Close</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <dialog class="profile-copy-dialog" id="profile-copy-dialog" aria-labelledby="profile-copy-title" aria-describedby="profile-copy-description">
        <h2 id="profile-copy-title">Copy setting to all profiles?</h2>
        <p id="profile-copy-description"></p>
        <p id="profile-copy-result" role="status" hidden></p>
        <div class="profile-copy-actions"><button type="button" class="btn-base" data-profile-copy-cancel>Cancel</button><button type="button" class="btn-save" data-profile-copy-confirm>Copy to all</button></div>
    </dialog>
</main>
<?php if ($selected !== null): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/profile-settings-copy.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/profile-settings-copy.js'); ?>" defer></script><?php endif; ?>
<?php if ($installations !== []): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>" defer></script><?php endif; ?>
<?php if ($installations !== []): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/profile-connector-tests.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/profile-connector-tests.js'); ?>" defer></script><?php endif; ?>
<?php if ($installations !== []): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/profile-assignment-rules.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/profile-assignment-rules.js'); ?>" defer></script><?php endif; ?>
<?php if ($selected !== null): ?><script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/core-profile-presets.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/core-profile-presets.js'); ?>" defer></script><?php endif; ?>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/core-global-overrides.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/core-global-overrides.js'); ?>" defer></script>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/profile-connector-editors.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/profile-connector-editors.js'); ?>" defer></script>
<script type="module" src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/profile-json-editor.js?v=<?= (string) filemtime(dirname(__DIR__) . '/js/profile-json-editor.js') ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
