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
const LORKHAN_LLM_DRIVERS = [
    'configured' => ['Configured runtime', 'Runtime'],
    'openai-compatible' => ['Direct OpenAI-compatible endpoint', 'Direct'],
    'mock' => ['Deterministic mock', 'Mock'],
];

/** Server-held credential references a direct connector may point at; key values never reach this page. */
$llmCredentials = [
    'none' => 'No API key',
    'default' => 'Default LLM key',
    'openai' => 'OpenAI LLM key',
    'openrouter' => 'OpenRouter LLM key',
    'custom' => 'Custom LLM key',
    'groq'=>'Groq key','nanogpt'=>'NanoGPT key','google'=>'Google LLM key',
];

foreach ((new \LorkhanServer\Application\CredentialStore((string)$config['credential_storage_path']))->statuses() as $status) {
    if(preg_match('/^LORKHAN_CUSTOM_(.+)_API_KEY$/D',$status['variable'],$match)===1)
        $llmCredentials['custom:'.$match[1]]=$match[1];
}

/** Numeric override fields: name, label, type, minimum, maximum, step, help. */
const LORKHAN_LLM_GENERATION_FIELDS = [
    ['max_tokens', 'Max tokens', 'integer', 1, 32768, '1', 'Upper bound on the tokens one response may generate. Set this or Max completion tokens, never both.'],
    ['max_completion_tokens', 'Max completion tokens', 'integer', 1, 32768, '1', 'Alternative token limit for models that require this parameter. Set only one token limit.'],
    ['temperature', 'Temperature', 'number', 0, 2, '0.01', 'Higher values make wording more varied.'],
];

const LORKHAN_LLM_SAMPLING_FIELDS = [
    ['presence_penalty', 'Presence penalty', 'number', -2, 2, '0.01', 'Discourages topics that already appeared.'],
    ['frequency_penalty', 'Frequency penalty', 'number', -2, 2, '0.01', 'Discourages tokens that already appeared often.'],
    ['repetition_penalty', 'Repetition penalty', 'number', 0, 2, '0.01', 'Penalises repeated spans across the whole response.'],
    ['top_p', 'Top p', 'number', 0, 1, '0.01', 'Keeps the smallest set of tokens whose probabilities reach p.'],
    ['top_k', 'Top k', 'integer', 0, 1000, '1', 'Keeps only the k most likely tokens; 0 applies no limit.'],
    ['min_p', 'Min p', 'number', 0, 1, '0.01', 'Drops tokens far below the most likely token.'],
    ['top_a', 'Top a', 'number', 0, 1, '0.01', 'Scales the cutoff with the most likely token probability.'],
];

/** Boolean override fields: name, label, inherit-option label, help, optional feature id. */
const LORKHAN_LLM_BOOLEAN_FIELDS = [
    ['stream', 'Streaming', 'Default', 'Dialogue only. Default is on for direct connectors; configured connectors inherit the runtime.'],
    ['json_mode', 'JSON mode', 'Default', 'Requests JSON from the provider. Default is on for direct connectors; configured connectors inherit the runtime. LORKHAN validates responses even when this is off.'],
    ['disable_reasoning', 'Disable reasoning', 'Inherit', 'Asks the provider to skip reasoning output. Configured connectors inherit the server runtime; direct connectors are off unless set. This does not clean reasoning tags out of a response.'],
    ['reasoning_model', 'Reasoning Model Fix', 'Inherit', 'Removes one leading <think>, <thinking>, or <reasoning> block from a response before LORKHAN parses the JSON. Off unless set, or unless a configured runtime supplies it. Disable reasoning is the separate setting that asks the provider not to produce reasoning at all; this one only cleans a block that was already returned, and JSON and result checks still apply.', 'config.llm.reasoning-fix'],
];

/** Merge the shipped UI bounds with the server-owned rules once the backend class is present. */
function lorkhan_llm_option_rules(): array
{
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = [];
    $class = 'LorkhanServer\\Application\\LlmConnector';
    if (class_exists($class) && defined($class . '::OPTION_RULES')) {
        $declared = constant($class . '::OPTION_RULES');
        if (is_array($declared)) $rules = $declared;
    }
    return $rules;
}

/** Render one numeric override where an empty control means "inherit", never "zero". */
function lorkhan_llm_number_field(array $field, array $options, string $formId, bool $active): void
{
    [$name, $label, $type, $minimum, $maximum, $step, $help] = $field;
    $rule = lorkhan_llm_option_rules()[$name] ?? null;
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
    <div class="llm-option-field<?php echo str_contains($name, 'tokens') ? ' llm-token-field' : ' llm-sampling-field'; ?>">
        <label for="<?php echo lorkhan_ui_h($id); ?>"><?php echo lorkhan_ui_h($label); ?></label>
        <div class="llm-option-control">
        <?php if (!str_contains($name, 'tokens')): ?>
        <input type="range" aria-label="<?php echo lorkhan_ui_h($label); ?> slider" data-range-for="<?php echo lorkhan_ui_h($id); ?>"
               min="<?php echo lorkhan_ui_h($minimum); ?>" max="<?php echo lorkhan_ui_h($maximum); ?>" step="<?php echo lorkhan_ui_h($step); ?>"
               value="<?php echo lorkhan_ui_h($value === '' ? ($name === 'temperature' ? 1 : max(0, $minimum)) : $value); ?>"<?php echo $active ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>">
        <?php endif; ?>
        <input id="<?php echo lorkhan_ui_h($id); ?>" name="option_<?php echo lorkhan_ui_h($name); ?>" type="number"
               inputmode="<?php echo $type === 'integer' ? 'numeric' : 'decimal'; ?>"
               min="<?php echo lorkhan_ui_h($minimum); ?>" max="<?php echo lorkhan_ui_h($maximum); ?>" step="<?php echo lorkhan_ui_h($step); ?>"
               value="<?php echo lorkhan_ui_h($value); ?>" placeholder="Default"
               aria-describedby="<?php echo lorkhan_ui_h($id); ?>-help"<?php echo $active ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>">
        </div>
        <p class="llm-help llm-field-tooltip" role="tooltip" id="<?php echo lorkhan_ui_h($id); ?>-help"><?php echo lorkhan_ui_h($help); ?> Range: <?php echo lorkhan_ui_h($minimum); ?> to <?php echo lorkhan_ui_h($maximum); ?>.</p>
    </div>
    <?php
}

/** Render one boolean override as an explicit inherit / on / off choice instead of an ambiguous checkbox. */
function lorkhan_llm_boolean_field(array $field, array $options, string $formId, bool $active): void
{
    [$name, $label, $inheritLabel, $help] = $field;
    $featureId = (string) ($field[4] ?? '');
    $stored = $options[$name] ?? null;
    $current = $stored === true ? 'true' : ($stored === false ? 'false' : '');
    $id = 'llm_option_' . $name;
    ?>
    <div class="llm-option-field llm-boolean-field">
        <label for="<?php echo lorkhan_ui_h($id); ?>"><?php echo lorkhan_ui_h($label); ?><?php if ($featureId !== '') echo ' ' . lorkhan_ui_feature_badge($featureId, true); ?></label>
        <select id="<?php echo lorkhan_ui_h($id); ?>" name="option_<?php echo lorkhan_ui_h($name); ?>"
                aria-describedby="<?php echo lorkhan_ui_h($id); ?>-help"<?php echo $active ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>">
            <option value=""<?php echo $current === '' ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($inheritLabel); ?></option>
            <option value="true"<?php echo $current === 'true' ? ' selected' : ''; ?>>On</option>
            <option value="false"<?php echo $current === 'false' ? ' selected' : ''; ?>>Off</option>
        </select>
        <p class="llm-help llm-field-tooltip" role="tooltip" id="<?php echo lorkhan_ui_h($id); ?>-help"><?php echo lorkhan_ui_h($help); ?></p>
    </div>
    <?php
}

/** Describe one inherited Herika control LORKHAN does not implement, without drawing a switch that does nothing. */
function lorkhan_llm_legacy_row(string $label, string $featureId): void
{
    ?>
    <div class="llm-legacy-row">
        <p class="llm-legacy-name"><span><?php echo lorkhan_ui_h($label); ?></span><?php echo lorkhan_ui_feature_badge($featureId, true); ?></p>
        <p class="llm-help"><?php echo lorkhan_ui_h(lorkhan_ui_feature($featureId)['description']); ?></p>
    </div>
    <?php
}

/** Apply provider connection presets without changing the selected model or sampling controls. */
function lorkhan_llm_service_picker(string $webRoot): void
{
    $services = [
        'openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'google' => 'Google',
        'groq' => 'Groq', 'nanogpt' => 'NanoGPT', 'player2' => 'Player2', 'custom' => 'Custom',
    ];
    ?>
    <div class="llm-legacy-row llm-service-block">
        <p class="llm-legacy-name"><span id="llm-service-label">Service</span></p>
        <div class="llm-service-icons" role="group" aria-label="Service presets">
            <?php foreach ($services as $file => $label): ?>
            <button type="button" data-llm-service="<?php echo lorkhan_ui_h($file); ?>" aria-pressed="false" title="<?php echo lorkhan_ui_h($label); ?>"><img class="llm-service-icon" src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/core/icons/<?php echo lorkhan_ui_h($file); ?>.jpg" alt="<?php echo lorkhan_ui_h($label); ?>"></button>
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
    <div class="page-header lorkhan-page-head">
        <h1 class="api-title lorkhan-page-head-title">LLM Connectors</h1>
        <p class="page-subtitle lorkhan-page-head-note">Configure Language Model connectors for AI dialogue generation</p>
    </div>

    <div id="toast" class="toast-notification llm-toast-spacer<?php echo isset($_GET['status']) ? ' show' : ''; ?>" role="status" aria-live="polite">
        <span class="message"><?php
            if (isset($_GET['status'])) echo lorkhan_ui_h($_GET['status'] === 'tested' ? 'Test completed: ' . ($_GET['detail'] ?? 'valid response') : 'LLM connector saved.');
        ?></span>
    </div>

    <?php if ($installations !== []): ?>
    <form class="visually-hidden" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-runtime-test" aria-hidden="true"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><button type="submit" tabindex="-1">Test Server runtime</button></form><span class="visually-hidden">LORKHAN_LLM_API_KEY</span>
    <?php endif; ?>

    <?php if ($installations === []): ?>
        <section class="connector-placeholder"><strong>Connect OpenMW once before creating connectors.</strong></section>
    <?php else: ?>
    <div class="llm-layout">
        <aside class="llm-left position-sticky">
            <div class="sidebar-action-grid">
                <a class="btn-save" href="<?php echo lorkhan_ui_h($queryFor(['create' => '1'])); ?>">New</a>
                <a class="btn-primary" href="<?php echo lorkhan_ui_h($queryFor(['import' => '1'])); ?>" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.llm.import-format')['description']); ?>">Import</a>
            </div>
            <div id="llm_list" class="conn-list" aria-label="LLM Connectors">
                <?php foreach ($rows as $row):
                    $content = is_array($row['content'] ?? null) ? $row['content'] : [];
                    $active = $selected !== null && $selected['configuration_id'] === $row['configuration_id'];
                    $inUse = (int) ($row['profile_usage'] ?? 0) > 0 || (int) ($row['active_session_usage'] ?? 0) > 0
                        || (int) ($row['queued_job_usage'] ?? 0) > 0 || (int) ($row['memory_policy_usage'] ?? 0) > 0;
                    $rowDriver = (string) ($content['driver'] ?? 'configured');
                ?>
                <div class="conn-li<?php echo $active ? ' active' : ''; ?>" data-configuration-id="<?php echo lorkhan_ui_h($row['configuration_id']); ?>">
                    <a class="conn-li-select" href="<?php echo lorkhan_ui_h($queryFor(['edit' => $row['configuration_id']])); ?>" aria-label="Edit <?php echo lorkhan_ui_h($row['name']); ?>">
                        <span class="head"><span class="title"><?php echo lorkhan_ui_h($row['name']); ?></span><span class="badge"><?php echo lorkhan_ui_h(LORKHAN_LLM_DRIVERS[$rowDriver][1] ?? $rowDriver); ?></span></span>
                        <span class="sub"><?php echo lorkhan_ui_h($content['model'] ?? ''); ?></span>
                    </a>
                    <div class="actions">
                        <?php if (!$inUse): ?>
                        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-delete" data-confirm="Delete this connector?">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>">
                            <button class="btn-danger" type="submit">Delete</button>
                        </form>
                        <?php else: ?>
                        <button class="btn-danger" type="button" disabled aria-disabled="true" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.llm.delete-protected')['description']); ?>">Delete</button>
                        <?php endif; ?>
                        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-clone">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"><input type="hidden" name="name" value="<?php echo lorkhan_ui_h($row['name'] . ' copy'); ?>">
                            <button class="btn-primary" type="submit">Clone</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="llm-right">
            <?php if ($mode === 'none'): ?>
                <div class="llm-empty-selection"><strong>No connector selected</strong><p>Select a connector from the list on the left to view and edit its settings.</p></div>
            <?php elseif ($mode === 'import'): ?>
                <div class="form-container wide-centered llm-import-panel">
                    <div class="llm-editor-toolbar"><a class="btn-base" href="<?php echo lorkhan_ui_h($queryFor([])); ?>">Cancel</a></div>
                    <h2>Import LLM Connector <?php echo lorkhan_ui_feature_badge('config.llm.import-format', true); ?></h2>
                    <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-import">
                        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                        <label for="llm-import-file">Choose portable JSON file</label>
                        <input id="llm-import-file" type="file" accept="application/json,.json" data-json-import-target="llm-import-json">
                        <label for="llm-import-json">Portable LORKHAN model-slot JSON</label>
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
                if (!isset(LORKHAN_LLM_DRIVERS[$driver])) $driver = 'configured';
                $credential = (string) ($content['credential'] ?? 'none');
                if (!isset($llmCredentials[$credential])) $credential = 'none';
                $storedTimeout = $content['timeout_ms'] ?? null;
                $timeout = is_int($storedTimeout) ? (string) $storedTimeout : (is_string($storedTimeout) ? $storedTimeout : '');
                $isDirect = $driver === 'openai-compatible';
                $isMock = $driver === 'mock';
                // Inactive mode controls stay disabled so they neither submit nor block native validation.
                $unless = static fn(bool $active): string => $active ? '' : ' disabled';
            ?>
                <div class="form-container wide-centered llm-editor">
                    <form id="<?php echo lorkhan_ui_h($formId); ?>" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/<?php echo lorkhan_ui_h($formAction); ?>">
                        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                        <?php if ($creating): ?><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><?php else: ?><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><input type="hidden" name="change_reason" value="Management LLM update"><?php endif; ?>
                    </form>

                    <div class="two-col-llm">
                        <div class="llm-column">
                            <div class="llm-editor-toolbar">
                                <button class="btn-save" type="submit" form="<?php echo lorkhan_ui_h($formId); ?>">Save</button>
                                <?php if (!$creating): ?>
                                <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-test"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><button class="btn-primary" type="submit">Test</button></form>
                                <a class="btn-save" href="<?php echo lorkhan_ui_h($managementBasePath); ?>/exports/providers/<?php echo lorkhan_ui_h($selected['configuration_id']); ?>.json">Export</a>
                                <?php else: ?>
                                <span class="llm-toolbar-placeholder"><button class="btn-primary" type="button" disabled aria-disabled="true" title="Save this connector before testing it.">Test</button></span>
                                <span class="llm-toolbar-placeholder"><button class="btn-save" type="button" disabled aria-disabled="true" title="Save this connector before exporting it.">Export</button></span>
                                <?php endif; ?>
                                <div class="llm-test-note">Save does not call the provider. Test uses saved settings and may incur provider charges.</div>
                                <?php if (!$creating): ?><span class="visually-hidden"><?php echo (int) ($selected['profile_usage'] ?? 0); ?> profiles</span><?php if ((int) ($selected['profile_usage'] ?? 0) > 0 || (int) ($selected['active_session_usage'] ?? 0) > 0 || (int) ($selected['queued_job_usage'] ?? 0) > 0 || (int) ($selected['memory_policy_usage'] ?? 0) > 0): ?><span class="visually-hidden">Connector is in use.</span><?php endif; ?><?php endif; ?>
                            </div>


                            <div class="llm-connection-field">
                                <label for="llm_name">Name</label>
                                <input id="llm_name" type="text" aria-describedby="llm_name-help" name="name" required maxlength="128" form="<?php echo lorkhan_ui_h($formId); ?>" value="<?php echo $creating ? '' : lorkhan_ui_h($selected['name']); ?>">
                                <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_name-help">This label appears in profile and player connector pickers. Renaming keeps existing assignments.</p>
                            </div>

                            <?php lorkhan_llm_service_picker($webRoot); ?>

                            <div class="llm-connection-field">
                                <label for="llm_driver">Mode</label>
                                <select id="llm_driver" name="driver" aria-describedby="llm_driver-help" form="<?php echo lorkhan_ui_h($formId); ?>">
                                <?php foreach (LORKHAN_LLM_DRIVERS as $driverId => $driverLabels): ?>
                                <option value="<?php echo lorkhan_ui_h($driverId); ?>"<?php echo $driver === $driverId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($driverLabels[0]); ?></option>
                                <?php endforeach; ?>
                                </select>
                                <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_driver-help">Configured runtime inherits the server endpoint and credential. Direct calls one complete endpoint you supply. Deterministic mock never contacts a provider.</p>
                            </div>

                            <div class="llm-connection-field">
                                <label for="llm_model">Model</label>
                                <input id="llm_model" type="text" name="model" required maxlength="256" value="<?php echo lorkhan_ui_h($content['model'] ?? ''); ?>" aria-describedby="llm_model-help" form="<?php echo lorkhan_ui_h($formId); ?>" data-model-catalogue="<?php echo lorkhan_ui_h($managementBasePath . '/api/v1/llm-models'); ?>">
                                <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_model-help">Required in every mode. Up to 256 characters, spelled exactly as the provider expects.</p>
                            </div>

                            <section class="llm-mode-panel llm-connection-panel" data-llm-modes="configured"<?php echo $driver === 'configured' ? '' : ' hidden'; ?>>
                                <div class="llm-group-heading"><span>Inherited connection</span><?php echo lorkhan_ui_feature_badge('config.llm.service', true); ?></div>
                                <p class="llm-help">The endpoint and the API key come from the LorkhanServer runtime. This mode has no per-connector endpoint or credential of its own.</p>
                            </section>

                            <section class="llm-mode-panel llm-connection-panel" data-llm-modes="openai-compatible"<?php echo $isDirect ? '' : ' hidden'; ?>>
                                <div class="llm-group-heading"><span>Direct connection</span><?php echo lorkhan_ui_feature_badge('config.llm.endpoint', true); ?></div>
                                <div class="llm-connection-field">
                                    <label for="llm_endpoint">Endpoint URL</label>
                                    <input id="llm_endpoint" type="url" name="endpoint" required maxlength="2048" inputmode="url" spellcheck="false"
                                           value="<?php echo lorkhan_ui_h($content['endpoint'] ?? ''); ?>" placeholder="http://127.0.0.1:1234/v1/chat/completions"
                                           aria-describedby="llm_endpoint-help"<?php echo $unless($isDirect); ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                    <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_endpoint-help">Paste the complete chat-completions URL. LORKHAN stores it verbatim and never appends or rewrites a path.</p>
                                </div>
                                <details class="llm-help-details">
                                    <summary>Endpoint rules</summary>
                                    <ul>
                                        <li>Give the whole path, for example <code>http://127.0.0.1:1234/v1/chat/completions</code>.</li>
                                        <li>Plain HTTP is accepted for loopback hosts only (<code>127.*</code> or <code>localhost</code>). Any other host must use HTTPS.</li>
                                        <li>No query string, no fragment, and no <code>user:password@</code> userinfo.</li>
                                        <li>Nothing is normalised or guessed from a provider preset, so a wrong path fails at Test rather than being silently corrected.</li>
                                    </ul>
                                </details>

                                <div class="llm-connection-field">
                                    <label for="llm_credential">API Key</label>
                                    <select id="llm_credential" name="credential" aria-describedby="llm_credential-help"<?php echo $unless($isDirect); ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                        <?php foreach ($llmCredentials as $credentialId => $credentialLabel): ?>
                                        <option value="<?php echo lorkhan_ui_h($credentialId); ?>"<?php echo $credential === $credentialId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($credentialLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_credential-help">Chooses which server-held key this connector sends. Key values live on the API Keys page and never appear in this form, in a revision, or in an export; an export resets this choice to No API key. New connectors start at No API key, which suits a local endpoint.</p>
                                </div>
                            </section>

                            <section class="llm-mode-panel llm-boolean-controls" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                    <?php foreach (LORKHAN_LLM_BOOLEAN_FIELDS as $field) lorkhan_llm_boolean_field($field, $options, $formId, !$isMock); ?>
                            </section>

                            <section class="llm-mode-panel" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                <div class="llm-connection-field">
                                    <label for="llm_timeout_ms">Request timeout (ms)</label>
                                    <input id="llm_timeout_ms" type="number" name="timeout_ms" min="1000" max="120000" step="1" inputmode="numeric"
                                           value="<?php echo lorkhan_ui_h($timeout); ?>" placeholder="<?php echo $isDirect ? '30000' : 'Inherit runtime timeout'; ?>"
                                           aria-describedby="llm_timeout_ms-help"<?php echo $unless(!$isMock); ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                    <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_timeout_ms-help">1000 to 120000 milliseconds. Blank on a configured connector inherits the runtime timeout; blank on a direct connector uses 30000.</p>
                                </div>
                            </section>

                            <section class="llm-mode-panel" data-llm-modes="mock"<?php echo $isMock ? '' : ' hidden'; ?>>
                                <div class="llm-connection-field">
                                    <label for="llm_mock_prefix">Mock prefix</label>
                                    <input id="llm_mock_prefix" type="text" name="mock_prefix" maxlength="256" value="<?php echo lorkhan_ui_h($content['mock_prefix'] ?? ''); ?>" aria-describedby="llm_mock_prefix-help"<?php echo $unless($isMock); ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                    <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_mock_prefix-help">Prepended to every deterministic mock response, up to 256 characters. Saving in mock mode keeps whatever is written here.</p>
                                </div>
                            </section>
                        </div>

                        <div class="llm-column">
                            <section class="llm-mode-panel" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                <div class="llm-option-grid">
                                    <?php foreach (LORKHAN_LLM_GENERATION_FIELDS as $field) lorkhan_llm_number_field($field, $options, $formId, !$isMock); ?>
                                </div>

                                <section class="llm-advanced-panel">
                                    <div class="llm-group-heading"><span>Advanced LLM Settings Override</span><?php echo lorkhan_ui_feature_badge('config.llm.advanced', true); ?></div>
                                    <p class="llm-help">Leave a field empty to keep the provider or runtime default. Not every provider honours every value.</p>
                                    <div class="llm-option-grid">
                                        <?php foreach (LORKHAN_LLM_SAMPLING_FIELDS as $field) lorkhan_llm_number_field($field, $options, $formId, !$isMock); ?>
                                    </div>
                                </section>
                            </section>

                            <section class="llm-mode-panel llm-connection-panel" data-llm-modes="mock"<?php echo $isMock ? '' : ' hidden'; ?>>
                                <div class="llm-group-heading"><span>Deterministic mock</span></div>
                                <p class="llm-help">Mock connectors never contact a provider. Switching modes keeps unsaved field values, but Save stores only fields for the selected mode. Earlier saved settings remain in revision history.</p>
                            </section>


                        </div>
                    </div>

                    <?php if (!$creating): ?>
                    <details class="llm-revisions"><summary>Revision history</summary><?php $history = is_array($selected['revisions'] ?? null) ? $selected['revisions'] : []; lorkhan_ui_table($history); ?><?php $earlier = array_values(array_filter($history, static fn(array $revision): bool => (int) ($revision['revision'] ?? 0) !== (int) $selected['current_revision'])); if ($earlier !== []): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-rollback"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><label for="llm_rollback_revision">Restore revision</label><select id="llm_rollback_revision" name="revision"><?php foreach ($earlier as $revision): ?><option value="<?php echo (int) $revision['revision']; ?>">Revision <?php echo (int) $revision['revision']; ?></option><?php endforeach; ?></select><button type="submit">Restore</button></form><?php endif; ?></details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
</main>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>" defer></script>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/llm-connectors.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/llm-connectors.js')); ?>" defer></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
