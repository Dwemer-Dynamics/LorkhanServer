<?php

declare(strict_types=1);

use ALMSIVIserver\Application\EffectiveSettingsResolver;

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

$selectedId = trim((string) ($_GET['edit'] ?? ''));
$selected = null;
foreach ($profiles as $profile) {
    if ($selectedId !== '' && hash_equals((string) $profile['core_profile_id'], $selectedId)) $selected = $profile;
}
$effectiveCoreSettings = [];
if ($selected !== null && $installationId !== '') {
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

$additionalStylesheets = ['herika-profiles.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-profiles.css')];
$includeManagementStyles = false;
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="profiles-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-header almsivi-page-head">
        <h1 class="api-title almsivi-page-head-title">ALMSIVI Profiles</h1>
        <p class="page-subtitle almsivi-page-head-note">Manage NPC profiles with LLM and TTS connectors</p>
    </div>

    <?php if (isset($_GET['status'])): ?><div class="almsivi-status" role="status"><?php echo (is_string($_GET['status']) && $_GET['status'] === 'imported') ? 'Settings preset imported as a new unassigned Core Profile. Review it below.' : 'Core Profile change saved.'; ?></div><?php endif; ?>
    <?php if ($installations === []): ?>
        <section class="connector-card profiles-empty">Connect OpenMW once before creating Core Profiles.</section>
    <?php else: ?>
        <?php if (count($installations) > 1): ?>
            <div class="profiles-installation-switcher">
                <label>Installation<select data-installation-select><?php foreach ($installations as $installation): ?><option value="<?php echo almsivi_ui_h($installation['installation_id']); ?>"<?php echo $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($installation['display_name']); ?></option><?php endforeach; ?></select></label>
            </div>
        <?php endif; ?>

        <div class="llm-layout">
            <aside class="llm-left">
                <div class="sidebar-action-grid">
                    <a class="btn-save" href="<?php echo almsivi_ui_h($queryFor(['create' => '1'])); ?>">New</a>
                    <a class="btn-primary" href="<?php echo almsivi_ui_h($queryFor(['import' => '1'])); ?>" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.profiles.import')['description']); ?>">Import</a>
                    <?php echo almsivi_ui_placeholder_control('Rules', 'config.profiles.rules'); ?>
                    <button class="btn-primary" id="profile-connector-test-open" type="button" data-profile-test-open aria-haspopup="dialog" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.profiles.test')['description']); ?>">Test</button>
                </div>

                <details class="profile-preset-note">
                    <summary>What a settings preset contains</summary>
                    <p>Import and Export move Core Profile <strong>settings overrides only</strong>. A preset excludes prompt text, connector routing, identifiers, slots, default status, revision history, and NPC assignments.</p>
                </details>

                <div class="connector-card profile-slots">
                    <div class="connector-title" title="Can be assigned to NPCs in game through ALMSIVI profile controls">Profile Slots <span class="profile-info">&#x24D8;</span></div>
                    <?php foreach (range(1, 4) as $slot): $slotted = null; foreach ($profiles as $profile) if ((int) ($profile['slot'] ?? 0) === $slot) $slotted = $profile; ?>
                        <div class="slot-row"><span class="slot-key">Slot <?php echo $slot; ?></span><span class="slot-val"><?php echo almsivi_ui_h($slotted['label'] ?? '— Empty —'); ?></span></div>
                    <?php endforeach; ?>
                </div>

                <div class="conn-list" aria-label="Core Profiles">
                    <?php foreach ($profiles as $profile):
                        $active = $selected !== null && $selected['core_profile_id'] === $profile['core_profile_id'];
                        $defaultNpc = filter_var($profile['default_npc'] ?? false, FILTER_VALIDATE_BOOL);
                        $usage = (int) ($profile['profile_usage'] ?? 0);
                    ?>
                        <article class="conn-li<?php echo $active ? ' active' : ''; ?>">
                            <a class="profile-card-link" href="<?php echo almsivi_ui_h($queryFor(['edit' => $profile['core_profile_id']])); ?>">
                                <span class="head"><span class="title"><?php echo almsivi_ui_h($profile['label']); ?></span><span class="pf-badges"><?php if ($defaultNpc): ?><span class="pf-flag">&#x1F464; NPC</span><?php endif; ?><span class="pf-flag"><?php echo $usage; ?> NPCs</span></span></span>
                                <span class="pf-lines">
                                    <span class="pf-line"><span class="pf-icon">&#x1F50A;</span><span class="pf-key">TTS Connector</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'tts_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F579;&#xFE0F;</span><span class="pf-key">Standard LLM</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'llm_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F3C3;</span><span class="pf-key">Fast LLM</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'llm_fast_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F4AA;</span><span class="pf-key">Powerful LLM</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'llm_powerful_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F9EA;</span><span class="pf-key">Experimental LLM</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'llm_experimental_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F504;</span><span class="pf-key">Fallback LLM</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'llm_fallback_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F4AC;</span><span class="pf-key">Dialogue Prompt</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'prompt_configuration_id')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F58B;&#xFE0F;</span><span class="pf-key">Profile Generation</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'profile_generation_configuration_id', 'Server runtime')); ?></span></span>
                                    <span class="pf-line"><span class="pf-icon">&#x1F91D;</span><span class="pf-key">Relationship LLM</span><span class="pf-val"><?php echo almsivi_ui_h($profileRouteLabel($profile, 'relationship_configuration_id', 'Disabled')); ?></span></span>
                                </span>
                            </a>
                            <div class="actions profile-card-actions">
                                <a class="btn-primary" href="<?php echo almsivi_ui_h($managementBasePath . '/exports/core-profile-settings/' . (string) $profile['core_profile_id'] . '.json'); ?>" aria-label="Export settings preset for <?php echo almsivi_ui_h($profile['label']); ?>" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.profiles.export')['description']); ?>">Export</a>
                                <?php if (!$defaultNpc && $usage === 0): ?>
                                    <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-delete" data-confirm="Delete this unused Core Profile?"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($profile['core_profile_id']); ?>"><button class="btn-danger" type="submit">Delete</button></form>
                                <?php else: ?>
                                    <button class="btn-danger feature-placeholder-control" type="button" disabled aria-disabled="true" title="The default or assigned Core Profile cannot be deleted.">Delete <?php echo almsivi_ui_feature_badge('config.profiles.delete-protected', true); ?></button>
                                <?php endif; ?>
                                <?php echo almsivi_ui_placeholder_control('Clone', 'config.profiles.clone'); ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </aside>

            <section class="llm-right">
                <div class="form-container wide-centered">
                <?php if ($importMode): ?>
                    <form class="core-profile-form profile-import-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-settings-import">
                        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                        <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
                        <div class="profile-editor-toolbar"><div><div class="profile-editor-toolbar-label">Importing Preset</div><div class="profile-editor-toolbar-name">Core Profile Settings</div></div><div class="profile-import-toolbar-actions"><a class="btn-base" href="<?php echo almsivi_ui_h($queryFor([])); ?>">Cancel</a><button type="submit" class="btn-save">Import Preset</button></div></div>
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
                    $content = ['schema' => 'almsivi.core-profile.v1', 'prompt' => '', 'routing' => [], 'settings_overrides' => []];
                    $profileMeta = ['label' => '', 'slot' => null, 'default_npc' => false];
                    $coreProfileMode = 'create';
                ?>
                    <form class="core-profile-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-create">
                        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                        <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
                        <div class="profile-editor-toolbar"><div><div class="profile-editor-toolbar-label">Creating Profile</div><div class="profile-editor-toolbar-name">New Profile</div></div><button type="submit" class="btn-save">Create Profile</button></div>
                        <?php include __DIR__ . '/tmpl/core_profile_fields.php'; ?>
                    </form>
                <?php elseif ($selected !== null):
                    $content = is_array($selected['content'] ?? null) ? $selected['content'] : [];
                    $profileMeta = $selected;
                    $coreProfileMode = 'edit';
                ?>
                    <form class="core-profile-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-save">
                        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                        <input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($selected['core_profile_id']); ?>">
                        <div class="profile-editor-toolbar"><div><div class="profile-editor-toolbar-label">Editing Profile</div><div class="profile-editor-toolbar-name"><?php echo almsivi_ui_h($selected['label']); ?></div></div><button type="submit" class="btn-save">Save All</button></div>
                        <?php almsivi_ui_effective_settings_summary($effectiveCoreSettings, 'Effective Core Profile settings and sources'); ?>
                        <?php include __DIR__ . '/tmpl/core_profile_fields.php'; ?>
                        <div class="connector-card profile-revision-card"><div class="connector-title">Revision Note</div><label>Change reason<input name="change_reason" required maxlength="512" value="Management Core Profile update"></label></div>
                    </form>

                    <details class="connector-card profile-history"><summary>Revision History</summary>
                        <?php $history = is_array($selected['revisions'] ?? null) ? $selected['revisions'] : []; almsivi_ui_table($history, 'No revisions.'); ?>
                        <?php $earlier = array_values(array_filter($history, static fn(array $revision): bool => (int) ($revision['revision'] ?? 0) !== (int) $selected['current_revision'])); if ($earlier !== []): ?>
                            <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-rollback"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($selected['core_profile_id']); ?>"><label>Restore revision<select name="revision"><?php foreach ($earlier as $revision): ?><option value="<?php echo (int) $revision['revision']; ?>">Revision <?php echo (int) $revision['revision']; ?> &mdash; <?php echo almsivi_ui_h($revision['reason'] ?? ''); ?></option><?php endforeach; ?></select></label><button type="submit" class="btn-save">Restore Earlier Revision</button></form>
                        <?php endif; ?>
                    </details>
                    <?php if (!filter_var($selected['default_npc'] ?? false, FILTER_VALIDATE_BOOL)): ?><form class="profile-default-action" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/core-profile-default"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="core_profile_id" value="<?php echo almsivi_ui_h($selected['core_profile_id']); ?>"><button type="submit" class="btn-save">Make Default NPC Profile</button></form><?php endif; ?>
                <?php else: ?>
                    <div class="connector-placeholder"><div>No profile selected</div><p>Select a profile from the list on the left to view and edit its settings.</p></div>
                <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="profile-test-overlay" data-profile-test-overlay hidden>
            <div class="profile-test-shell" role="dialog" aria-modal="true" aria-labelledby="profile-test-title" aria-describedby="profile-test-warning" data-profile-test-dialog data-profile-test-endpoint="<?php echo almsivi_ui_h($managementBasePath . '/api/v1/profile-connector-tests'); ?>" data-profile-test-csrf="<?php echo almsivi_ui_h($csrf); ?>" data-profile-test-installation="<?php echo almsivi_ui_h($installationId); ?>">
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
    <?php endif; ?>
</main>
<?php if ($importMode): ?><script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo almsivi_ui_h($uiAssetVersion); ?>" defer></script><?php endif; ?>
<?php if ($installations !== []): ?><script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/profile-connector-tests.js?v=<?php echo (string) filemtime(dirname(__DIR__) . '/js/profile-connector-tests.js'); ?>" defer></script><?php endif; ?>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
