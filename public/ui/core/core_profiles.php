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
$showCreate = isset($_GET['create']) || $profiles === [];

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

    <?php if (isset($_GET['status'])): ?><div class="almsivi-status" role="status">Core Profile change saved.</div><?php endif; ?>
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
                    <?php echo almsivi_ui_placeholder_control('Import', 'config.profiles.import'); ?>
                    <?php echo almsivi_ui_placeholder_control('Rules', 'config.profiles.rules'); ?>
                    <?php echo almsivi_ui_placeholder_control('Test', 'config.profiles.test'); ?>
                </div>

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
                                <?php echo almsivi_ui_placeholder_control('Export', 'config.profiles.export'); ?>
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
                <?php if ($showCreate):
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
    <?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
