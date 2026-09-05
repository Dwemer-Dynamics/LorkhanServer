<?php

declare(strict_types=1);

use LorkhanServer\Application\ConnectorCatalog;

$pageTitle = 'TTS Connectors';
$topNavSection = 'configuration';
$embedded = ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page tts-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$requestedInstallation = trim((string) ($_GET['installation_id'] ?? ''));
$installationId = '';
foreach ($installations as $installation) {
    if ($requestedInstallation !== '' && ($installation['installation_id'] ?? '') === $requestedInstallation) {
        $installationId = $requestedInstallation;
    }
}
if ($installationId === '' && isset($installations[0])) $installationId = (string) $installations[0]['installation_id'];

$rows = array_values(array_filter(
    $uiRepository->rows('tts'),
    static fn(array $row): bool => ($row['installation_id'] ?? '') === $installationId
));

$drivers = [];
$optionCatalog = [];
$connectorDefaults = [];
foreach (ConnectorCatalog::all('tts_provider') as $definition) {
    $driver = (string) $definition['driver'];
    $drivers[$driver] = (string) $definition['label'];
    $optionCatalog[$driver] = ConnectorCatalog::optionFields('tts_provider', $driver);
    $connectorDefaults[$driver] = ConnectorCatalog::defaults('tts_provider', $driver);
}

$selectedId = trim((string) ($_GET['edit'] ?? $_GET['selected'] ?? ''));
$selected = null;
foreach ($rows as $row) {
    if ($selectedId !== '' && hash_equals((string) $row['configuration_id'], $selectedId)) $selected = $row;
}
$mode = isset($_GET['import']) ? 'import' : (isset($_GET['create']) ? 'create' : ($selected !== null ? 'edit' : 'none'));
$pageUrl = $webRoot . '/ui/core/tts_connectors.php';
$queryFor = static function (array $values) use ($pageUrl, $installationId, $embedded): string {
    if ($installationId !== '') $values['installation_id'] = $installationId;
    if ($embedded) $values['embed'] = '1';
    return $pageUrl . '?' . http_build_query($values);
};

$additionalStylesheets = ['herika-tts.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-tts.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="tts-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-shell">
        <div class="page-header lorkhan-page-head">
            <h1 class="api-title lorkhan-page-head-title">TTS Connectors</h1>
            <p class="page-subtitle lorkhan-page-head-note">Text-to-Speech Setup Options.</p>
        </div>

        <?php if (isset($_GET['status'])): ?>
            <div class="notice" role="status"><?php echo lorkhan_ui_h($_GET['status'] === 'tested' ? 'Test completed: ' . ($_GET['detail'] ?? 'valid audio') : 'TTS connector saved.'); ?></div>
        <?php endif; ?>

        <?php if ($installations === []): ?>
            <div class="placeholder">Connect OpenMW once before creating connectors.</div>
        <?php else: ?>
            <label class="visually-hidden">Installation<select data-installation-select><?php foreach ($installations as $installation): ?><option value="<?php echo lorkhan_ui_h($installation['installation_id']); ?>"<?php echo $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($installation['display_name']); ?></option><?php endforeach; ?></select></label>

            <div class="layout">
                <aside class="left-col">
                    <div class="btn-row sidebar-action-grid">
                        <a class="btn-save" href="<?php echo lorkhan_ui_h($queryFor(['create' => '1'])); ?>">New</a>
                        <a class="btn-primary" href="<?php echo lorkhan_ui_h($queryFor(['import' => '1'])); ?>" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.tts.import-format')['description']); ?>">Import</a>
                    </div>
                    <div class="list-wrap" id="tts_connector_list" aria-label="TTS Connectors">
                        <?php foreach ($rows as $row):
                            $content = is_array($row['content'] ?? null) ? $row['content'] : [];
                            $active = $selected !== null && $selected['configuration_id'] === $row['configuration_id'];
                            $assignmentCount = (int) ($row['profile_usage'] ?? 0) + (int) ($row['active_session_usage'] ?? 0);
                        ?>
                            <a class="conn-card<?php echo $active ? ' active' : ''; ?>" href="<?php echo lorkhan_ui_h($queryFor(['edit' => $row['configuration_id']])); ?>">
                                <span class="conn-head"><span class="title"><?php echo lorkhan_ui_h($row['name']); ?></span><span class="conn-badge"><?php echo lorkhan_ui_h($drivers[(string) ($content['driver'] ?? '')] ?? ($content['driver'] ?? 'Configured')); ?></span></span>
                                <span class="conn-sub"><?php echo lorkhan_ui_h($content['endpoint'] ?? ''); ?></span>
                                <span class="conn-usage"><?php echo $assignmentCount; ?> assignment<?php echo $assignmentCount === 1 ? '' : 's'; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <section class="right-col">
                    <?php if ($mode === 'none'): ?>
                        <div class="placeholder">Select a connector from the left to edit it. New installs will already have the currently selected TTS provider migrated into this table.</div>
                    <?php elseif ($mode === 'import'): ?>
                        <div class="btn-row"><a class="btn-secondary" href="<?php echo lorkhan_ui_h($queryFor([])); ?>">Cancel</a></div>
                        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-import">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                            <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                            <input type="hidden" name="kind" value="tts_provider">
                            <div class="meta-group active">
                                <h3>Import TTS Connector <?php echo lorkhan_ui_feature_badge('config.tts.import-format', true); ?></h3>
                                <div class="field-block"><label>Choose portable JSON file</label><input type="file" accept="application/json,.json" data-json-import-target="tts-import-json"></div>
                                <div class="field-block"><label for="tts-import-json">Portable LORKHAN connector JSON</label><textarea id="tts-import-json" name="connector_json" required placeholder="Choose a JSON file or paste its contents here."></textarea></div>
                                <button class="btn-save" type="submit">Import</button>
                            </div>
                        </form>
                    <?php else:
                        $creating = $mode === 'create';
                        $content = $creating ? [] : (is_array($selected['content'] ?? null) ? $selected['content'] : []);
                        $defaultDriver = isset($drivers['omnivoice']) ? 'omnivoice' : (string) array_key_first($drivers);
                        $currentDriver = (string) ($content['driver'] ?? $defaultDriver);
                        $defaults = $connectorDefaults[$currentDriver] ?? [];
                        $options = is_array($content['options'] ?? null) ? $content['options'] : [];
                        $formId = $creating ? 'tts-create-form' : 'tts-revise-form';
                        $formAction = $creating ? 'tts-providers' : 'connector-revise';
                    ?>
                        <div class="btn-row editor-toolbar">
                            <button class="btn-save" type="submit" form="<?php echo lorkhan_ui_h($formId); ?>">Save</button>
                            <?php if (!$creating): ?>
                                <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-test"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><button class="btn-primary" type="submit">Test</button></form>
                                <a class="btn-save" href="<?php echo lorkhan_ui_h($managementBasePath); ?>/exports/connectors/<?php echo lorkhan_ui_h($selected['configuration_id']); ?>.json">Export</a>
                                <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-clone"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><input type="hidden" name="name" value="<?php echo lorkhan_ui_h($selected['name'] . ' copy'); ?>"><button class="btn-primary" type="submit">Clone</button></form>
                                <?php $inUse = filter_var($selected['active'], FILTER_VALIDATE_BOOL) || (int) ($selected['profile_usage'] ?? 0) > 0 || (int) ($selected['active_session_usage'] ?? 0) > 0; ?>
                                <?php if (!$inUse): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-delete" data-confirm="Delete this TTS connector?"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><button class="btn-danger" type="submit">Delete</button></form><?php else: ?><span class="toolbar-disabled"><button class="btn-danger feature-placeholder-control" type="button" disabled aria-disabled="true">Delete</button><?php echo lorkhan_ui_feature_badge('config.tts.delete-protected', true); ?></span><?php endif; ?>
                            <?php else: ?>
                                <?php foreach (['Test', 'Export', 'Clone', 'Delete'] as $pendingControl): ?><span class="toolbar-disabled"><button class="<?php echo $pendingControl === 'Delete' ? 'btn-danger' : 'btn-primary'; ?> feature-placeholder-control" type="button" disabled aria-disabled="true"><?php echo lorkhan_ui_h($pendingControl); ?></button><?php echo lorkhan_ui_feature_badge('config.tts.saved-only', true); ?></span><?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="orm-note">Please save any changes before testing to ensure the latest settings are used.</div>

                        <form id="<?php echo lorkhan_ui_h($formId); ?>" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/<?php echo lorkhan_ui_h($formAction); ?>">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                            <input type="hidden" name="option_fields_present" value="1">
                            <?php if ($creating): ?>
                                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                            <?php else: ?>
                                <input type="hidden" name="kind" value="tts_provider">
                                <input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>">
                                <input type="hidden" name="change_reason" value="Management TTS update">
                            <?php endif; ?>

                        <div class="editor-grid">
                            <div class="field-block"><label for="tts_name">Name</label><input type="text" id="tts_name" <?php echo $creating ? 'name="name" required maxlength="128" form="' . lorkhan_ui_h($formId) . '"' : 'value="' . lorkhan_ui_h($selected['name']) . '" readonly'; ?>><div class="field-help">This label appears in profile and player connector pickers.</div></div>
                            <div class="field-block"><label for="tts_driver">Service</label><select id="tts_driver" name="driver" form="<?php echo lorkhan_ui_h($formId); ?>" data-tts-driver><?php foreach ($drivers as $driverId => $label): ?><option value="<?php echo lorkhan_ui_h($driverId); ?>"<?php echo $currentDriver === $driverId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($label); ?></option><?php endforeach; ?></select><div class="field-help">The provider driver this connector loads at runtime.</div></div>
                            <div class="field-block"><label for="tts_endpoint">URL</label><input type="url" id="tts_endpoint" name="endpoint" required value="<?php echo lorkhan_ui_h($content['endpoint'] ?? $defaults['endpoint'] ?? ''); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"><div class="field-help">Used for providers that expose a local or remote HTTP endpoint.</div></div>
                            <div class="field-block" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.tts.api-key')['description']); ?>"><label>API Badge <?php echo lorkhan_ui_feature_badge('config.tts.api-key', true); ?></label><select disabled aria-disabled="true"><option>Server-owned credential</option></select><div class="field-help">Provider credentials are managed centrally through LORKHAN API Keys.</div></div>
                        </div>

                        <section class="meta-group active">
                            <h3>NPC Fallbacks</h3>
                            <div class="inline-two">
                                <div class="field-block"><label for="tts_fallback_male">Fallback Male</label><input type="text" id="tts_fallback_male" name="fallback_male" value="<?php echo lorkhan_ui_h($options['fallback_male'] ?? ''); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"><div class="field-help">NPC male fallback VoiceID if the NPC voice is blank or the provider rejects it.</div></div>
                                <div class="field-block"><label for="tts_fallback_female">Fallback Female</label><input type="text" id="tts_fallback_female" name="fallback_female" value="<?php echo lorkhan_ui_h($options['fallback_female'] ?? ''); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"><div class="field-help">NPC female fallback VoiceID if the NPC voice is blank or the provider rejects it.</div></div>
                            </div>
                        </section>

                        <section class="meta-group active runtime-settings">
                            <h3><?php echo lorkhan_ui_h($drivers[$currentDriver] ?? 'Provider'); ?> Settings</h3>
                            <div class="inline-two">
                                <div class="field-block"><label for="tts_model">Model</label><input type="text" id="tts_model" name="model" value="<?php echo lorkhan_ui_h($content['model'] ?? $defaults['model'] ?? 'default'); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"></div>
                                <div class="field-block"><label for="tts_voice">Default Voice</label><input type="text" id="tts_voice" name="voice" value="<?php echo lorkhan_ui_h($content['voice'] ?? $defaults['voice'] ?? 'default'); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"></div>
                                <div class="field-block"><label for="tts_language">Language</label><input type="text" id="tts_language" name="language" value="<?php echo lorkhan_ui_h($content['language'] ?? $defaults['language'] ?? 'en'); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"></div>
                                <div class="field-block"><label for="tts_timeout">Timeout (ms)</label><input type="number" id="tts_timeout" min="1000" max="120000" name="timeout_ms" value="<?php echo (int) ($content['timeout_ms'] ?? 30000); ?>" form="<?php echo lorkhan_ui_h($formId); ?>"></div>
                            </div>
                            <div class="connector-option-editor" data-connector-options data-driver-control="tts_driver" data-connector-defaults="<?php echo lorkhan_ui_h(json_encode($connectorDefaults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); ?>">
                                <?php foreach ($optionCatalog as $catalogDriver => $optionFields): $activeDriver = $catalogDriver === $currentDriver; ?>
                                    <div class="inline-two" data-connector-driver="<?php echo lorkhan_ui_h($catalogDriver); ?>"<?php echo $activeDriver ? '' : ' hidden'; ?>>
                                        <?php foreach ($optionFields as $field): $name = (string) $field['name']; $type = (string) $field['type']; $value = $options[$name] ?? ''; $fieldId = 'tts-option-' . preg_replace('/[^a-z0-9_-]+/i', '-', $currentDriver . '-' . $name); ?>
                                            <div class="field-block"><label for="<?php echo lorkhan_ui_h($fieldId); ?>"><?php echo lorkhan_ui_h($field['label']); ?></label>
                                                <?php if ($type === 'boolean'): ?><label class="boolean-field"><input name="option__<?php echo lorkhan_ui_h($name); ?>" type="checkbox" value="1"<?php echo $value === true ? ' checked' : ''; ?><?php echo $activeDriver ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>"> Enabled</label>
                                                <?php elseif ($type === 'select'): ?><select id="<?php echo lorkhan_ui_h($fieldId); ?>" name="option__<?php echo lorkhan_ui_h($name); ?>"<?php echo $activeDriver ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>"><option value="">Connector default</option><?php foreach ($field['values'] as $choice): ?><option value="<?php echo lorkhan_ui_h($choice); ?>"<?php echo (string) $value === (string) $choice ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($choice); ?></option><?php endforeach; ?></select>
                                                <?php elseif (in_array($type, ['number', 'integer'], true)): ?><input id="<?php echo lorkhan_ui_h($fieldId); ?>" name="option__<?php echo lorkhan_ui_h($name); ?>" type="number" step="<?php echo $type === 'integer' ? '1' : 'any'; ?>" min="<?php echo lorkhan_ui_h($field['minimum']); ?>" max="<?php echo lorkhan_ui_h($field['maximum']); ?>" value="<?php echo lorkhan_ui_h($value); ?>"<?php echo $activeDriver ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                                <?php else: ?><input id="<?php echo lorkhan_ui_h($fieldId); ?>" name="option__<?php echo lorkhan_ui_h($name); ?>" maxlength="512" value="<?php echo lorkhan_ui_h($value); ?>"<?php echo $activeDriver ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>"><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                                <p class="settings-empty-note" data-connector-options-empty<?php echo ($optionCatalog[$currentDriver] ?? []) === [] ? '' : ' hidden'; ?>>This TTS provider does not have any additional connector-level settings.</p>
                            </div>
                            <div class="field-block advanced-json"><label for="tts_options_json">Advanced connector options (JSON)</label><textarea id="tts_options_json" name="options_json" form="<?php echo lorkhan_ui_h($formId); ?>"><?php echo lorkhan_ui_h(json_encode($options === [] ? (object) [] : $options, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea></div>
                        </section>
                        </form>

                        <?php if (!$creating): ?>
                            <div class="secondary-actions">
                                <?php if (!filter_var($selected['active'], FILTER_VALIDATE_BOOL)): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-selection"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><button class="btn-primary" type="submit">Set Default</button></form><?php else: ?><span class="default-note">Installation default</span><?php endif; ?>
                                <?php if (filter_var($selected['active'], FILTER_VALIDATE_BOOL)): ?><span class="visually-hidden">This active connector cannot be deleted.</span><?php elseif ((int) ($selected['profile_usage'] ?? 0) > 0): ?><span class="visually-hidden">This connector is assigned to a profile or Core Profile.</span><?php endif; ?>
                            </div>
                            <details class="revision-history"><summary>Revision history</summary><?php $history = is_array($selected['revisions'] ?? null) ? $selected['revisions'] : []; lorkhan_ui_table($history); ?><?php $earlier = array_values(array_filter($history, static fn(array $revision): bool => (int) ($revision['revision'] ?? 0) !== (int) $selected['current_revision'])); if ($earlier !== []): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/connector-rollback"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="kind" value="tts_provider"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><label>Restore revision<select name="revision"><?php foreach ($earlier as $revision): ?><option value="<?php echo (int) $revision['revision']; ?>">Revision <?php echo (int) $revision['revision']; ?></option><?php endforeach; ?></select></label><button type="submit">Restore</button></form><?php endif; ?></details>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</main>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>" defer></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
