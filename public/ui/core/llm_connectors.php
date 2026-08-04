<?php

declare(strict_types=1);

$pageTitle = 'LLM Connectors';
$topNavSection = 'configuration';
$embedded = ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page llm-page-shell' . ($embedded ? ' embedded-page' : '');
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
    $uiRepository->rows('llm'),
    static fn(array $row): bool => ($row['installation_id'] ?? '') === $installationId
));

$selectedId = trim((string) ($_GET['edit'] ?? $_GET['selected'] ?? ''));
$selected = null;
foreach ($rows as $row) {
    if ($selectedId !== '' && hash_equals((string) $row['configuration_id'], $selectedId)) $selected = $row;
}
$mode = isset($_GET['import']) ? 'import' : (isset($_GET['create']) ? 'create' : ($selected !== null ? 'edit' : 'none'));
$pageUrl = $webRoot . '/ui/core/llm_connectors.php';
$queryFor = static function (array $values) use ($pageUrl, $installationId, $embedded): string {
    if ($installationId !== '') $values['installation_id'] = $installationId;
    if ($embedded) $values['embed'] = '1';
    return $pageUrl . '?' . http_build_query($values);
};

/** Render one copied Herika toggle that is awaiting a typed ALMSIVI provider field. */
function almsivi_llm_planned_toggle(string $label, string $description): void
{
    ?>
    <label class="llm-toggle-row" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.llm.generation')['description']); ?>">
        <span><strong><?php echo almsivi_ui_h($label); ?></strong><small><?php echo almsivi_ui_h($description); ?></small></span>
        <?php echo almsivi_ui_feature_badge('config.llm.generation', true); ?>
        <input type="checkbox" disabled aria-disabled="true">
    </label>
    <?php
}

/** Render one disabled Herika sampling control under the centralized advanced status. */
function almsivi_llm_planned_slider(string $label, string $description, string $min, string $max, string $step): void
{
    ?>
    <div class="llm-slider-row" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.llm.advanced')['description']); ?>">
        <label><?php echo almsivi_ui_h($label); ?><small><?php echo almsivi_ui_h($description); ?></small></label>
        <div class="llm-slider-controls"><input type="range" min="<?php echo almsivi_ui_h($min); ?>" max="<?php echo almsivi_ui_h($max); ?>" step="<?php echo almsivi_ui_h($step); ?>" disabled aria-disabled="true"><input class="inline-num" type="number" disabled aria-disabled="true"></div>
    </div>
    <?php
}

/** Render Herika's provider service picker as a visible server-owned adapter. */
function almsivi_llm_service_picker(string $webRoot): void
{
    $services = [
        'openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'google' => 'Google',
        'groq' => 'Groq', 'nanogpt' => 'NanoGPT', 'player2' => 'Player2', 'custom' => 'Custom',
    ];
    ?>
    <div class="llm-service-block" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.llm.service')['description']); ?>">
        <div class="llm-field-heading"><span>Service: ALMSIVI Server runtime</span><?php echo almsivi_ui_feature_badge('config.llm.service', true); ?></div>
        <div class="service-picker"><div class="service-icons" aria-label="Server-owned provider services">
            <?php foreach ($services as $file => $label): ?>
            <span class="service-icon-shell" title="<?php echo almsivi_ui_h($label); ?> is selected by the server runtime"><img class="service-icon" src="<?php echo almsivi_ui_h($webRoot); ?>/ui/images/core/icons/<?php echo almsivi_ui_h($file); ?>.jpg" alt="<?php echo almsivi_ui_h($label); ?>" aria-disabled="true"></span>
            <?php endforeach; ?>
        </div></div>
    </div>
    <?php
}

$additionalStylesheets = ['herika-llm.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-llm.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="d-flex flex-column llm-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-header">
        <h1 class="api-title">LLM Connectors</h1>
        <p class="page-subtitle">Configure Language Model connectors for AI dialogue generation</p>
    </div>

    <div id="toast" class="toast-notification llm-toast-spacer<?php echo isset($_GET['status']) ? ' show' : ''; ?>" role="status" aria-live="polite">
        <span class="message"><?php
            if (isset($_GET['status'])) echo almsivi_ui_h($_GET['status'] === 'tested' ? 'Test completed: ' . ($_GET['detail'] ?? 'valid response') : 'LLM connector saved.');
        ?></span>
    </div>

    <?php if ($installations !== []): ?>
    <form class="visually-hidden" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/provider-runtime-test" aria-hidden="true"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><button type="submit" tabindex="-1">Test Server runtime</button></form><span class="visually-hidden">ALMSIVI_LLM_API_KEY</span>
    <?php endif; ?>

    <?php if ($installations === []): ?>
        <section class="connector-placeholder"><strong>Connect OpenMW once before creating connectors.</strong></section>
    <?php else: ?>
    <div class="llm-layout">
        <aside class="llm-left position-sticky">
            <div class="sidebar-action-grid">
                <a class="btn-save" href="<?php echo almsivi_ui_h($queryFor(['create' => '1'])); ?>">New</a>
                <a class="btn-primary" href="<?php echo almsivi_ui_h($queryFor(['import' => '1'])); ?>" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.llm.import-format')['description']); ?>">Import</a>
            </div>
            <div id="llm_list" class="conn-list" aria-label="LLM Connectors">
                <?php foreach ($rows as $row):
                    $content = is_array($row['content'] ?? null) ? $row['content'] : [];
                    $active = $selected !== null && $selected['configuration_id'] === $row['configuration_id'];
                    $inUse = (int) ($row['profile_usage'] ?? 0) > 0 || (int) ($row['active_session_usage'] ?? 0) > 0;
                ?>
                <div class="conn-li<?php echo $active ? ' active' : ''; ?>" data-configuration-id="<?php echo almsivi_ui_h($row['configuration_id']); ?>">
                    <a class="conn-li-select" href="<?php echo almsivi_ui_h($queryFor(['edit' => $row['configuration_id']])); ?>" aria-label="Edit <?php echo almsivi_ui_h($row['name']); ?>">
                        <span class="head"><span class="title"><?php echo almsivi_ui_h($row['name']); ?></span><span class="badge"><?php echo almsivi_ui_h($content['driver'] ?? 'configured'); ?></span></span>
                        <span class="sub"><?php echo almsivi_ui_h($content['model'] ?? ''); ?></span>
                    </a>
                    <div class="actions">
                        <?php if (!$inUse): ?>
                        <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/provider-delete" data-confirm="Delete this connector?">
                            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($row['configuration_id']); ?>">
                            <button class="btn-danger" type="submit">Delete</button>
                        </form>
                        <?php else: ?>
                        <button class="btn-danger feature-placeholder-control" type="button" disabled aria-disabled="true" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.llm.delete-protected')['description']); ?>">Delete <?php echo almsivi_ui_feature_badge('config.llm.delete-protected', true); ?></button>
                        <?php endif; ?>
                        <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/provider-clone">
                            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($row['configuration_id']); ?>"><input type="hidden" name="name" value="<?php echo almsivi_ui_h($row['name'] . ' copy'); ?>">
                            <button class="btn-primary" type="submit">Clone</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="llm-right">
            <?php if ($mode === 'none'): ?>
                <div class="form-container wide-centered"><div class="connector-placeholder"><strong>No connector selected</strong><span>Select a connector from the list on the left to view and edit its settings.</span></div></div>
            <?php elseif ($mode === 'import'): ?>
                <div class="form-container wide-centered llm-import-panel">
                    <div class="llm-editor-toolbar"><a class="btn-base" href="<?php echo almsivi_ui_h($queryFor([])); ?>">Cancel</a></div>
                    <h2>Import LLM Connector <?php echo almsivi_ui_feature_badge('config.llm.import-format', true); ?></h2>
                    <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/provider-import">
                        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
                        <label>Choose portable JSON file<input type="file" accept="application/json,.json" data-json-import-target="llm-import-json"></label>
                        <label>Portable ALMSIVI model-slot JSON<textarea id="llm-import-json" name="provider_json" required placeholder="Choose a JSON file or paste its contents here."></textarea></label>
                        <button class="btn-save" type="submit">Import</button>
                    </form>
                </div>
            <?php else:
                $creating = $mode === 'create';
                $content = $creating ? [] : (is_array($selected['content'] ?? null) ? $selected['content'] : []);
                $formId = $creating ? 'llm-create-form' : 'llm-revise-form';
                $formAction = $creating ? 'providers' : 'provider-revise';
                $driver = (string) ($content['driver'] ?? 'configured');
            ?>
                <div class="form-container wide-centered llm-editor">
                    <form id="<?php echo almsivi_ui_h($formId); ?>" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/<?php echo almsivi_ui_h($formAction); ?>">
                        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                        <?php if ($creating): ?><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>"><?php else: ?><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($selected['configuration_id']); ?>"><input type="hidden" name="change_reason" value="Management LLM update"><?php endif; ?>
                    </form>

                    <div class="llm-editor-toolbar">
                        <button class="btn-save" type="submit" form="<?php echo almsivi_ui_h($formId); ?>">Save</button>
                        <?php if (!$creating): ?>
                        <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/provider-test"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($selected['configuration_id']); ?>"><button class="btn-primary" type="submit">Test</button></form>
                        <a class="btn-save" href="<?php echo almsivi_ui_h($managementBasePath); ?>/exports/providers/<?php echo almsivi_ui_h($selected['configuration_id']); ?>.json">Export</a>
                        <?php else: ?>
                        <span class="llm-toolbar-placeholder"><button class="btn-primary feature-placeholder-control" type="button" disabled aria-disabled="true">Test</button><?php echo almsivi_ui_feature_badge('config.llm.saved-only', true); ?></span>
                        <span class="llm-toolbar-placeholder"><button class="btn-save feature-placeholder-control" type="button" disabled aria-disabled="true">Export</button><?php echo almsivi_ui_feature_badge('config.llm.saved-only', true); ?></span>
                        <?php endif; ?>
                        <div class="llm-test-note">Please save any changes before testing to ensure the latest settings are used.</div>
                        <?php if (!$creating): ?><span class="visually-hidden"><?php echo (int) ($selected['profile_usage'] ?? 0); ?> profiles</span><?php if ((int) ($selected['profile_usage'] ?? 0) > 0 || (int) ($selected['active_session_usage'] ?? 0) > 0): ?><span class="visually-hidden">Connector is in use.</span><?php endif; ?><?php endif; ?>
                    </div>

                    <div class="two-col-llm">
                        <div class="llm-column">
                            <label>Name<?php if (!$creating) echo almsivi_ui_feature_badge('config.llm.identity', true); ?><input type="text" <?php echo $creating ? 'name="name" required maxlength="128" form="' . almsivi_ui_h($formId) . '"' : 'value="' . almsivi_ui_h($selected['name']) . '" readonly'; ?>></label>
                            <?php almsivi_llm_service_picker($webRoot); ?>
                            <label>Driver<select name="driver" form="<?php echo almsivi_ui_h($formId); ?>"><option value="configured"<?php echo $driver === 'configured' ? ' selected' : ''; ?>>Configured runtime</option><option value="mock"<?php echo $driver === 'mock' ? ' selected' : ''; ?>>Deterministic mock</option></select></label>
                            <label>Model<input type="text" name="model" required maxlength="256" value="<?php echo almsivi_ui_h($content['model'] ?? ''); ?>" form="<?php echo almsivi_ui_h($formId); ?>"></label>
                            <label>Mock prefix<input type="text" name="mock_prefix" maxlength="256" value="<?php echo almsivi_ui_h($content['mock_prefix'] ?? ''); ?>" form="<?php echo almsivi_ui_h($formId); ?>"></label>
                            <label>Provider <?php echo almsivi_ui_feature_badge('config.llm.service', true); ?><input type="text" placeholder="Selected by the server runtime" disabled aria-disabled="true"></label>
                            <label>API Key <?php echo almsivi_ui_feature_badge('config.llm.api-key', true); ?><select disabled aria-disabled="true"><option>Server-owned credential</option></select></label>

                            <div class="llm-toggle-list">
                                <?php almsivi_llm_planned_toggle('Reasoning Model Fix', 'Fixes reasoning-only model tags before response parsing.'); ?>
                                <?php almsivi_llm_planned_toggle('Enforce JSON', 'Force responses to use the bounded dialogue response schema.'); ?>
                                <?php almsivi_llm_planned_toggle('JSON Schema', 'Guide and validate the JSON structure.'); ?>
                                <?php almsivi_llm_planned_toggle('Prefill JSON', 'Send a starter JSON object to steer field names and shape.'); ?>
                                <?php almsivi_llm_planned_toggle('Disable Streaming', 'Wait for a complete provider response before parsing.'); ?>
                                <?php almsivi_llm_planned_toggle('Remove Action Prompt', 'Disable the action-enforcement prompt for this connector.'); ?>
                            </div>
                        </div>

                        <div class="llm-column">
                            <div class="llm-group-heading"><span>Generation Controls</span><?php echo almsivi_ui_feature_badge('config.llm.generation', true); ?></div>
                            <div class="llm-slider-row"><label>Max Tokens<small>Maximum tokens the model can generate for a response.</small></label><div class="llm-slider-controls"><input type="number" value="750" disabled aria-disabled="true"></div></div>
                            <div class="llm-slider-row"><label>Temperature<small>Controls randomness; higher is more creative.</small></label><div class="llm-slider-controls"><input type="range" min="0" max="2" step="0.1" value="1" disabled aria-disabled="true"><input class="inline-num" type="number" value="1" disabled aria-disabled="true"></div></div>

                            <section class="llm-advanced-panel">
                                <div class="llm-group-heading"><span>Advanced LLM Settings Override</span><?php echo almsivi_ui_feature_badge('config.llm.advanced', true); ?></div>
                                <p>If a value is left empty, the API provider's recommended default will be used.</p>
                                <?php almsivi_llm_planned_slider('Presence penalty', 'Reduces repetition by discouraging repeated topics.', '-2', '2', '0.1'); ?>
                                <?php almsivi_llm_planned_slider('Frequency penalty', 'Reduces repeated words or phrases.', '0', '2', '0.1'); ?>
                                <?php almsivi_llm_planned_slider('Repetition penalty', 'Stops the model from repeating itself.', '0', '2', '0.1'); ?>
                                <?php almsivi_llm_planned_slider('Top p', 'Chooses tokens with a combined probability up to p.', '0', '1', '0.01'); ?>
                                <?php almsivi_llm_planned_slider('Top k', 'Picks from the top k most likely words.', '0', '100', '1'); ?>
                                <?php almsivi_llm_planned_slider('Min p', 'Ignores words with very low probability.', '0', '1', '0.01'); ?>
                                <?php almsivi_llm_planned_slider('Top a', 'Adjusts word probabilities for better balance.', '0', '1', '0.01'); ?>
                            </section>

                            <section class="llm-body-parameters" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.llm.body-parameters')['description']); ?>">
                                <div class="llm-group-heading"><span>Include Body Parameters (YAML)</span><?php echo almsivi_ui_feature_badge('config.llm.body-parameters', true); ?></div>
                                <label class="llm-toggle-row"><span><strong>Enable YAML Body Parameters</strong><small>When off, saved parameters remain stored but are not sent.</small></span><input type="checkbox" disabled aria-disabled="true"></label>
                                <textarea disabled aria-disabled="true" placeholder="Additional request body parameters"></textarea>
                                <small>Enter additional request body parameters in YAML format. (Advanced users only.)</small>
                            </section>
                        </div>
                    </div>

                    <?php if (!$creating): ?>
                    <details class="llm-revisions"><summary>Revision history</summary><?php $history = is_array($selected['revisions'] ?? null) ? $selected['revisions'] : []; almsivi_ui_table($history); ?><?php $earlier = array_values(array_filter($history, static fn(array $revision): bool => (int) ($revision['revision'] ?? 0) !== (int) $selected['current_revision'])); if ($earlier !== []): ?><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/provider-rollback"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($selected['configuration_id']); ?>"><label>Restore revision<select name="revision"><?php foreach ($earlier as $revision): ?><option value="<?php echo (int) $revision['revision']; ?>">Revision <?php echo (int) $revision['revision']; ?></option><?php endforeach; ?></select></label><button type="submit">Restore</button></form><?php endif; ?></details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</main>
<script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo almsivi_ui_h($uiAssetVersion); ?>" defer></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
