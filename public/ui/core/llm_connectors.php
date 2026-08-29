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

/** The three model-slot runtimes, in the order the editor offers them: label, then list badge. */
const ALMSIVI_LLM_DRIVERS = [
    'configured' => ['Configured runtime', 'Runtime'],
    'openai-compatible' => ['Direct OpenAI-compatible endpoint', 'Direct'],
    'mock' => ['Deterministic mock', 'Mock'],
];

/** Server-held credential references a direct connector may point at; key values never reach this page. */
const ALMSIVI_LLM_CREDENTIALS = [
    'none' => 'No API key',
    'default' => 'Default LLM key',
    'openai' => 'OpenAI LLM key',
    'openrouter' => 'OpenRouter LLM key',
    'custom' => 'Custom LLM key',
];

/** Numeric override fields: name, label, type, minimum, maximum, step, help. */
const ALMSIVI_LLM_GENERATION_FIELDS = [
    ['max_tokens', 'Max tokens', 'integer', 1, 32768, '1', 'Upper bound on the tokens one response may generate. Set this or Max completion tokens, never both.'],
    ['max_completion_tokens', 'Max completion tokens', 'integer', 1, 32768, '1', 'Alternative token limit for models that require this parameter. Set only one token limit.'],
    ['temperature', 'Temperature', 'number', 0, 2, '0.01', 'Higher values make wording more varied.'],
];

const ALMSIVI_LLM_SAMPLING_FIELDS = [
    ['top_p', 'Top p', 'number', 0, 1, '0.01', 'Keeps the smallest set of tokens whose probabilities reach p.'],
    ['top_k', 'Top k', 'integer', 0, 1000, '1', 'Keeps only the k most likely tokens; 0 applies no limit.'],
    ['min_p', 'Min p', 'number', 0, 1, '0.01', 'Drops tokens far below the most likely token.'],
    ['top_a', 'Top a', 'number', 0, 1, '0.01', 'Scales the cutoff with the most likely token probability.'],
    ['frequency_penalty', 'Frequency penalty', 'number', -2, 2, '0.01', 'Discourages tokens that already appeared often.'],
    ['presence_penalty', 'Presence penalty', 'number', -2, 2, '0.01', 'Discourages topics that already appeared.'],
    ['repetition_penalty', 'Repetition penalty', 'number', 0, 2, '0.01', 'Penalises repeated spans across the whole response.'],
];

/** Boolean override fields: name, label, inherit-option label, help, optional feature id. */
const ALMSIVI_LLM_BOOLEAN_FIELDS = [
    ['stream', 'Streaming', 'Default', 'Dialogue only. Default is on for direct connectors; configured connectors inherit the runtime.'],
    ['json_mode', 'JSON mode', 'Default', 'Requests JSON from the provider. Default is on for direct connectors; configured connectors inherit the runtime. ALMSIVI validates responses even when this is off.'],
    ['disable_reasoning', 'Disable reasoning', 'Inherit', 'Asks the provider to skip reasoning output. Configured connectors inherit the server runtime; direct connectors are off unless set. This does not clean reasoning tags out of a response.'],
    ['reasoning_model', 'Reasoning Model Fix', 'Inherit', 'Removes one leading <think>, <thinking>, or <reasoning> block from a response before ALMSIVI parses the JSON. Off unless set, or unless a configured runtime supplies it. Disable reasoning is the separate setting that asks the provider not to produce reasoning at all; this one only cleans a block that was already returned, and JSON and result checks still apply.', 'config.llm.reasoning-fix'],
];

/** Merge the shipped UI bounds with the server-owned rules once the backend class is present. */
function almsivi_llm_option_rules(): array
{
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = [];
    $class = 'ALMSIVIserver\\Application\\LlmConnector';
    if (class_exists($class) && defined($class . '::OPTION_RULES')) {
        $declared = constant($class . '::OPTION_RULES');
        if (is_array($declared)) $rules = $declared;
    }
    return $rules;
}

/** Render one numeric override where an empty control means "inherit", never "zero". */
function almsivi_llm_number_field(array $field, array $options, string $formId, bool $active): void
{
    [$name, $label, $type, $minimum, $maximum, $step, $help] = $field;
    $rule = almsivi_llm_option_rules()[$name] ?? null;
    if (is_array($rule)) {
        $type = (string) ($rule['type'] ?? $type);
        if (array_key_exists('minimum', $rule)) $minimum = $rule['minimum'];
        if (array_key_exists('maximum', $rule)) $maximum = $rule['maximum'];
    }
    if ($type === 'integer') $step = '1';
    $stored = $options[$name] ?? null;
    $value = is_int($stored) || is_float($stored) ? (string) $stored : (is_string($stored) ? $stored : '');
    $id = 'llm_option_' . $name;
    ?>
    <div class="llm-option-field">
        <label for="<?php echo almsivi_ui_h($id); ?>"><?php echo almsivi_ui_h($label); ?></label>
        <input id="<?php echo almsivi_ui_h($id); ?>" name="option_<?php echo almsivi_ui_h($name); ?>" type="number"
               inputmode="<?php echo $type === 'integer' ? 'numeric' : 'decimal'; ?>"
               min="<?php echo almsivi_ui_h($minimum); ?>" max="<?php echo almsivi_ui_h($maximum); ?>" step="<?php echo almsivi_ui_h($step); ?>"
               value="<?php echo almsivi_ui_h($value); ?>" placeholder="Default"
               aria-describedby="<?php echo almsivi_ui_h($id); ?>-help"<?php echo $active ? '' : ' disabled'; ?> form="<?php echo almsivi_ui_h($formId); ?>">
        <p class="llm-help" id="<?php echo almsivi_ui_h($id); ?>-help"><?php echo almsivi_ui_h($help); ?> Range: <?php echo almsivi_ui_h($minimum); ?> to <?php echo almsivi_ui_h($maximum); ?>.</p>
    </div>
    <?php
}

/** Render one boolean override as an explicit inherit / on / off choice instead of an ambiguous checkbox. */
function almsivi_llm_boolean_field(array $field, array $options, string $formId, bool $active): void
{
    [$name, $label, $inheritLabel, $help] = $field;
    $featureId = (string) ($field[4] ?? '');
    $stored = $options[$name] ?? null;
    $current = $stored === true ? 'true' : ($stored === false ? 'false' : '');
    $id = 'llm_option_' . $name;
    ?>
    <div class="llm-option-field">
        <label for="<?php echo almsivi_ui_h($id); ?>"><?php echo almsivi_ui_h($label); ?><?php if ($featureId !== '') echo ' ' . almsivi_ui_feature_badge($featureId, true); ?></label>
        <select id="<?php echo almsivi_ui_h($id); ?>" name="option_<?php echo almsivi_ui_h($name); ?>"
                aria-describedby="<?php echo almsivi_ui_h($id); ?>-help"<?php echo $active ? '' : ' disabled'; ?> form="<?php echo almsivi_ui_h($formId); ?>">
            <option value=""<?php echo $current === '' ? ' selected' : ''; ?>><?php echo almsivi_ui_h($inheritLabel); ?></option>
            <option value="true"<?php echo $current === 'true' ? ' selected' : ''; ?>>On</option>
            <option value="false"<?php echo $current === 'false' ? ' selected' : ''; ?>>Off</option>
        </select>
        <p class="llm-help" id="<?php echo almsivi_ui_h($id); ?>-help"><?php echo almsivi_ui_h($help); ?></p>
    </div>
    <?php
}

/** Describe one inherited Herika control ALMSIVI does not implement, without drawing a switch that does nothing. */
function almsivi_llm_legacy_row(string $label, string $featureId): void
{
    ?>
    <div class="llm-legacy-row">
        <p class="llm-legacy-name"><span><?php echo almsivi_ui_h($label); ?></span><?php echo almsivi_ui_feature_badge($featureId, true); ?></p>
        <p class="llm-help"><?php echo almsivi_ui_h(almsivi_ui_feature($featureId)['description']); ?></p>
    </div>
    <?php
}

/** Keep Herika's provider service strip visible as a clearly inert legacy surface. */
function almsivi_llm_service_picker(string $webRoot): void
{
    $services = [
        'openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'google' => 'Google',
        'groq' => 'Groq', 'nanogpt' => 'NanoGPT', 'player2' => 'Player2', 'custom' => 'Custom',
    ];
    ?>
    <div class="llm-legacy-row llm-service-block">
        <p class="llm-legacy-name"><span>Service preset icons</span><?php echo almsivi_ui_feature_badge('config.llm.service', true); ?></p>
        <p class="llm-help"><?php echo almsivi_ui_h(almsivi_ui_feature('config.llm.service')['description']); ?></p>
        <div class="llm-service-icons" role="presentation">
            <?php foreach ($services as $file => $label): ?>
            <img class="llm-service-icon" src="<?php echo almsivi_ui_h($webRoot); ?>/ui/images/core/icons/<?php echo almsivi_ui_h($file); ?>.jpg" alt="" aria-hidden="true">
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

$additionalStylesheets = ['herika-llm.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-llm.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="d-flex flex-column llm-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-header almsivi-page-head">
        <h1 class="api-title almsivi-page-head-title">LLM Connectors</h1>
        <p class="page-subtitle almsivi-page-head-note">Configure Language Model connectors for AI dialogue generation</p>
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
                    $inUse = (int) ($row['profile_usage'] ?? 0) > 0 || (int) ($row['active_session_usage'] ?? 0) > 0
                        || (int) ($row['queued_job_usage'] ?? 0) > 0 || (int) ($row['memory_policy_usage'] ?? 0) > 0;
                    $rowDriver = (string) ($content['driver'] ?? 'configured');
                ?>
                <div class="conn-li<?php echo $active ? ' active' : ''; ?>" data-configuration-id="<?php echo almsivi_ui_h($row['configuration_id']); ?>">
                    <a class="conn-li-select" href="<?php echo almsivi_ui_h($queryFor(['edit' => $row['configuration_id']])); ?>" aria-label="Edit <?php echo almsivi_ui_h($row['name']); ?>">
                        <span class="head"><span class="title"><?php echo almsivi_ui_h($row['name']); ?></span><span class="badge"><?php echo almsivi_ui_h(ALMSIVI_LLM_DRIVERS[$rowDriver][1] ?? $rowDriver); ?></span></span>
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
                        <label for="llm-import-file">Choose portable JSON file</label>
                        <input id="llm-import-file" type="file" accept="application/json,.json" data-json-import-target="llm-import-json">
                        <label for="llm-import-json">Portable ALMSIVI model-slot JSON</label>
                        <textarea id="llm-import-json" name="provider_json" required aria-describedby="llm-import-help" placeholder="Choose a JSON file or paste its contents here."></textarea>
                        <p class="llm-help" id="llm-import-help">A portable model slot never carries an API key value. An imported direct connector keeps its endpoint but always arrives set to No API key, so it cannot pick up a key you already hold. Choose the credential yourself after importing.</p>
                        <button class="btn-save" type="submit">Import</button>
                    </form>
                </div>
            <?php else:
                $creating = $mode === 'create';
                $content = $creating ? [] : (is_array($selected['content'] ?? null) ? $selected['content'] : []);
                $options = is_array($content['options'] ?? null) ? $content['options'] : [];
                $formId = $creating ? 'llm-create-form' : 'llm-revise-form';
                $formAction = $creating ? 'providers' : 'provider-revise';
                $driver = (string) ($content['driver'] ?? 'configured');
                if (!isset(ALMSIVI_LLM_DRIVERS[$driver])) $driver = 'configured';
                $credential = (string) ($content['credential'] ?? 'none');
                if (!isset(ALMSIVI_LLM_CREDENTIALS[$credential])) $credential = 'none';
                $storedTimeout = $content['timeout_ms'] ?? null;
                $timeout = is_int($storedTimeout) ? (string) $storedTimeout : (is_string($storedTimeout) ? $storedTimeout : '');
                $isDirect = $driver === 'openai-compatible';
                $isMock = $driver === 'mock';
                // Inactive mode controls stay disabled so they neither submit nor block native validation.
                $unless = static fn(bool $active): string => $active ? '' : ' disabled';
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
                        <div class="llm-test-note">Save does not call the provider. Test uses saved settings and may incur provider charges.</div>
                        <?php if (!$creating): ?><span class="visually-hidden"><?php echo (int) ($selected['profile_usage'] ?? 0); ?> profiles</span><?php if ((int) ($selected['profile_usage'] ?? 0) > 0 || (int) ($selected['active_session_usage'] ?? 0) > 0 || (int) ($selected['queued_job_usage'] ?? 0) > 0 || (int) ($selected['memory_policy_usage'] ?? 0) > 0): ?><span class="visually-hidden">Connector is in use.</span><?php endif; ?><?php endif; ?>
                    </div>

                    <div class="two-col-llm">
                        <div class="llm-column">
                            <label for="llm_name">Name<?php if (!$creating) echo ' ' . almsivi_ui_feature_badge('config.llm.identity', true); ?></label>
                            <input id="llm_name" type="text" aria-describedby="llm_name-help" <?php echo $creating ? 'name="name" required maxlength="128" form="' . almsivi_ui_h($formId) . '"' : 'value="' . almsivi_ui_h($selected['name']) . '" readonly'; ?>>
                            <p class="llm-help" id="llm_name-help"><?php echo $creating ? 'This label appears in profile and player connector pickers.' : 'The name stays fixed while model-slot content changes through immutable revisions.'; ?></p>

                            <label for="llm_driver">Mode</label>
                            <select id="llm_driver" name="driver" aria-describedby="llm_driver-help" form="<?php echo almsivi_ui_h($formId); ?>">
                                <?php foreach (ALMSIVI_LLM_DRIVERS as $driverId => $driverLabels): ?>
                                <option value="<?php echo almsivi_ui_h($driverId); ?>"<?php echo $driver === $driverId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($driverLabels[0]); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="llm-help" id="llm_driver-help">Configured runtime inherits the server endpoint and credential. Direct calls one complete endpoint you supply. Deterministic mock never contacts a provider.</p>

                            <label for="llm_model">Model</label>
                            <input id="llm_model" type="text" name="model" required maxlength="256" value="<?php echo almsivi_ui_h($content['model'] ?? ''); ?>" aria-describedby="llm_model-help" form="<?php echo almsivi_ui_h($formId); ?>">
                            <p class="llm-help" id="llm_model-help">Required in every mode. Up to 256 characters, spelled exactly as the provider expects.</p>

                            <section class="llm-mode-panel llm-connection-panel" data-llm-modes="configured"<?php echo $driver === 'configured' ? '' : ' hidden'; ?>>
                                <div class="llm-group-heading"><span>Inherited connection</span><?php echo almsivi_ui_feature_badge('config.llm.service', true); ?></div>
                                <p class="llm-help">The endpoint and the API key come from the ALMSIVI server runtime. This mode has no per-connector endpoint or credential of its own.</p>
                            </section>

                            <section class="llm-mode-panel llm-connection-panel" data-llm-modes="openai-compatible"<?php echo $isDirect ? '' : ' hidden'; ?>>
                                <div class="llm-group-heading"><span>Direct connection</span><?php echo almsivi_ui_feature_badge('config.llm.endpoint', true); ?></div>
                                <label for="llm_endpoint">Endpoint URL</label>
                                <input id="llm_endpoint" type="url" name="endpoint" required maxlength="2048" inputmode="url" spellcheck="false"
                                       value="<?php echo almsivi_ui_h($content['endpoint'] ?? ''); ?>" placeholder="http://127.0.0.1:1234/v1/chat/completions"
                                       aria-describedby="llm_endpoint-help"<?php echo $unless($isDirect); ?> form="<?php echo almsivi_ui_h($formId); ?>">
                                <p class="llm-help" id="llm_endpoint-help">Paste the complete chat-completions URL. ALMSIVI stores it verbatim and never appends or rewrites a path.</p>
                                <details class="llm-help-details">
                                    <summary>Endpoint rules</summary>
                                    <ul>
                                        <li>Give the whole path, for example <code>http://127.0.0.1:1234/v1/chat/completions</code>.</li>
                                        <li>Plain HTTP is accepted for loopback hosts only (<code>127.*</code> or <code>localhost</code>). Any other host must use HTTPS.</li>
                                        <li>No query string, no fragment, and no <code>user:password@</code> userinfo.</li>
                                        <li>Nothing is normalised or guessed from a provider preset, so a wrong path fails at Test rather than being silently corrected.</li>
                                    </ul>
                                </details>

                                <label for="llm_credential">API key <?php echo almsivi_ui_feature_badge('config.llm.api-key', true); ?></label>
                                <select id="llm_credential" name="credential" aria-describedby="llm_credential-help"<?php echo $unless($isDirect); ?> form="<?php echo almsivi_ui_h($formId); ?>">
                                    <?php foreach (ALMSIVI_LLM_CREDENTIALS as $credentialId => $credentialLabel): ?>
                                    <option value="<?php echo almsivi_ui_h($credentialId); ?>"<?php echo $credential === $credentialId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($credentialLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="llm-help" id="llm_credential-help">Chooses which server-held key this connector sends. Key values live on the API Keys page and never appear in this form, in a revision, or in an export; an export resets this choice to No API key. New connectors start at No API key, which suits a local endpoint.</p>
                            </section>

                            <section class="llm-mode-panel" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                <label for="llm_timeout_ms">Request timeout (ms)</label>
                                <input id="llm_timeout_ms" type="number" name="timeout_ms" min="1000" max="120000" step="1" inputmode="numeric"
                                       value="<?php echo almsivi_ui_h($timeout); ?>" placeholder="<?php echo $isDirect ? '30000' : 'Inherit runtime timeout'; ?>"
                                       aria-describedby="llm_timeout_ms-help"<?php echo $unless(!$isMock); ?> form="<?php echo almsivi_ui_h($formId); ?>">
                                <p class="llm-help" id="llm_timeout_ms-help">1000 to 120000 milliseconds. Blank on a configured connector inherits the runtime timeout; blank on a direct connector uses 30000.</p>
                            </section>

                            <section class="llm-mode-panel" data-llm-modes="mock"<?php echo $isMock ? '' : ' hidden'; ?>>
                                <label for="llm_mock_prefix">Mock prefix</label>
                                <input id="llm_mock_prefix" type="text" name="mock_prefix" maxlength="256" value="<?php echo almsivi_ui_h($content['mock_prefix'] ?? ''); ?>" aria-describedby="llm_mock_prefix-help"<?php echo $unless($isMock); ?> form="<?php echo almsivi_ui_h($formId); ?>">
                                <p class="llm-help" id="llm_mock_prefix-help">Prepended to every deterministic mock response, up to 256 characters. Saving in mock mode keeps whatever is written here.</p>
                            </section>
                        </div>

                        <div class="llm-column">
                            <section class="llm-mode-panel" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                <div class="llm-group-heading"><span>Generation Controls</span><?php echo almsivi_ui_feature_badge('config.llm.generation', true); ?></div>
                                <p class="llm-help">Blank sampling fields use provider defaults for direct connectors, or inherit server settings for configured connectors. Zero and Off are explicit overrides.</p>
                                <div class="llm-option-grid">
                                    <?php foreach (ALMSIVI_LLM_GENERATION_FIELDS as $field) almsivi_llm_number_field($field, $options, $formId, !$isMock); ?>
                                    <?php foreach (ALMSIVI_LLM_BOOLEAN_FIELDS as $field) almsivi_llm_boolean_field($field, $options, $formId, !$isMock); ?>
                                </div>

                                <section class="llm-advanced-panel">
                                    <div class="llm-group-heading"><span>Advanced sampling overrides</span><?php echo almsivi_ui_feature_badge('config.llm.advanced', true); ?></div>
                                    <p class="llm-help">Leave a field empty to keep the provider or runtime default. Not every provider honours every value.</p>
                                    <div class="llm-option-grid">
                                        <?php foreach (ALMSIVI_LLM_SAMPLING_FIELDS as $field) almsivi_llm_number_field($field, $options, $formId, !$isMock); ?>
                                    </div>
                                </section>
                            </section>

                            <section class="llm-mode-panel llm-connection-panel" data-llm-modes="mock"<?php echo $isMock ? '' : ' hidden'; ?>>
                                <div class="llm-group-heading"><span>Deterministic mock</span></div>
                                <p class="llm-help">Mock connectors never contact a provider. Switching modes keeps unsaved field values, but Save stores only fields for the selected mode. Earlier saved settings remain in revision history.</p>
                            </section>

                            <details class="llm-legacy-panel">
                                <summary>Herika controls ALMSIVI does not implement <?php echo almsivi_ui_feature_badge('config.llm.legacy-controls', true); ?></summary>
                                <p class="llm-help">These controls exist in the Herika editor this page was copied from. They are listed so nothing looks silently missing. None of them is wired up, and none is a hidden default.</p>
                                <?php almsivi_llm_legacy_row('JSON Schema and Prefill JSON', 'config.llm.json-schema'); ?>
                                <?php almsivi_llm_legacy_row('Remove Action Prompt', 'config.llm.action-prompt'); ?>
                                <?php almsivi_llm_legacy_row('Include Body Parameters (YAML)', 'config.llm.body-parameters'); ?>
                                <?php almsivi_llm_service_picker($webRoot); ?>
                            </details>
                        </div>
                    </div>

                    <?php if (!$creating): ?>
                    <details class="llm-revisions"><summary>Revision history</summary><?php $history = is_array($selected['revisions'] ?? null) ? $selected['revisions'] : []; almsivi_ui_table($history); ?><?php $earlier = array_values(array_filter($history, static fn(array $revision): bool => (int) ($revision['revision'] ?? 0) !== (int) $selected['current_revision'])); if ($earlier !== []): ?><form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/provider-rollback"><input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo almsivi_ui_h($selected['configuration_id']); ?>"><label for="llm_rollback_revision">Restore revision</label><select id="llm_rollback_revision" name="revision"><?php foreach ($earlier as $revision): ?><option value="<?php echo (int) $revision['revision']; ?>">Revision <?php echo (int) $revision['revision']; ?></option><?php endforeach; ?></select><button type="submit">Restore</button></form><?php endif; ?></details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</main>
<script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo almsivi_ui_h($uiAssetVersion); ?>" defer></script>
<script src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/llm-connectors.js?v=<?php echo almsivi_ui_h((string) filemtime(dirname(__DIR__) . '/js/llm-connectors.js')); ?>" defer></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
