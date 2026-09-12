<?php

declare(strict_types=1);

$pageTitle = 'LLM Connectors';
$topNavSection = 'configuration';
$partialEditor = ($_GET['partial'] ?? '') === 'editor';
if ($partialEditor) $_GET['embed'] = '1';
$embedded = ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page llm-page-shell' . ($embedded ? ' embedded-page' : '') . ($partialEditor ? ' connector-editor-only' : '');
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
$importedCount = filter_var($_GET['imported'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>20]]) ?: 0;
$pageUrl = $webRoot . '/ui/core/llm_connectors.php';
$queryFor = static function (array $values) use ($pageUrl, $installationId, $embedded, $partialEditor): string {
    if ($installationId !== '') $values['installation_id'] = $installationId;
    if ($embedded) $values['embed'] = '1';
    if ($partialEditor) $values['partial'] = 'editor';
    return $pageUrl . '?' . http_build_query($values);
};

/** The three model-slot runtimes, in the order the editor offers them: label, then list badge. */
const LORKHAN_LLM_DRIVERS = [
    'configured' => ['Configured runtime', 'Runtime'],
    'openai-compatible' => ['Direct OpenAI-compatible endpoint', 'Direct'],
    'mock' => ['Deterministic mock', 'Mock'],
];

/** Identify known services consistently in the list and editor without exposing endpoints. */
function lorkhan_llm_endpoint_service(string $endpoint): string
{
    return match (rtrim($endpoint, '/')) {
        'https://openrouter.ai/api/v1/chat/completions' => 'openrouter',
        'https://api.openai.com/v1/chat/completions' => 'openai',
        'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' => 'google',
        'https://api.groq.com/openai/v1/chat/completions' => 'groq',
        'https://nano-gpt.com/api/v1/chat/completions' => 'nanogpt',
        'http://127.0.0.1:4315/v1/chat/completions' => 'player2',
        default => '',
    };
}

/** Server-held credential references a direct connector may point at; key values never reach this page. */
$llmCredentials = [
    'none' => 'No API key',
    'default' => 'Default LLM key',
    'openai' => 'OpenAI LLM key',
    'openrouter' => 'OpenRouter LLM key',
    'custom' => 'Custom LLM key',
    'groq'=>'Groq key','nanogpt'=>'NanoGPT key','google'=>'Google LLM key',
];

$llmKeyStatuses = [];
foreach ((new \LorkhanServer\Application\CredentialStore((string)$config['credential_storage_path']))->statuses() as $status) {
    $llmKeyStatuses[$status['variable']] = (bool)$status['configured'];
    $existingReference=array_search($status['variable'],\LorkhanServer\Application\LlmConnector::CREDENTIALS,true);
    if($existingReference!==false)$llmCredentials[$existingReference]=$status['label'];
    if(preg_match('/^LORKHAN_CUSTOM_(.+)_API_KEY$/D',$status['variable'],$match)===1)
        $llmCredentials['custom:'.$match[1]]=$status['label']??$match[1];
    elseif(!in_array($status['variable'],\LorkhanServer\Application\LlmConnector::CREDENTIALS,true))
        $llmCredentials['badge:'.$status['variable']]=$status['label']??ucwords(strtolower(str_replace('_',' ',preg_replace('/^LORKHAN_|_API_KEY$/','',$status['variable']))));
}
// Match Herika's configured-first list using status metadata, never secret values.
asort($llmCredentials, SORT_NATURAL | SORT_FLAG_CASE);
$llmCredentialGroups = ['configured' => [], 'missing' => []];
foreach ($llmCredentials as $reference => $label) {
    if ($reference === 'none') continue;
    $variable = \LorkhanServer\Application\LlmConnector::credentialVariable($reference);
    $llmCredentialGroups[!empty($llmKeyStatuses[$variable]) ? 'configured' : 'missing'][$reference] = $label;
}

/** Numeric override fields: name, label, type, minimum, maximum, step, help. */
const LORKHAN_LLM_GENERATION_FIELDS = [
    ['max_tokens', 'Max Tokens', 'integer', 1, 32768, '1', 'Upper bound on the tokens one response may generate. Set this or Max completion tokens, never both.'],
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
    ['stream', 'Disable Streaming', 'Default', 'Wait for the complete JSON response instead of streaming dialogue. Off by default; configured connectors inherit the runtime.'],
    ['json_mode', 'Enforce JSON', 'Default', 'Requests JSON from the provider. Default is on for direct connectors; configured connectors inherit the runtime. LORKHAN validates responses even when this is off.'],
    ['json_schema', 'JSON Schema', 'Default', 'With Enforce JSON on, sends the exact response schema for dialogue or the current generation job. Requires a provider and model that support structured output. Off by default; configured connectors inherit the runtime.'],
    ['prefill_json', 'Prefill JSON', 'Default', 'Starts the assistant response with the expected JSON field. Requires a provider and model that support assistant continuation. Off by default; configured connectors inherit the runtime. Streaming and response validation still apply.'],
    ['extra_parameters_enabled', 'Enable YAML Body Parameters', 'Default', 'When off, saved YAML remains stored but is not injected into requests. Off by default; configured connectors inherit the runtime. Body parameters can override sampling and provider preferences. Native messages, model, streaming, actions and credentials remain managed by LORKHAN. Enforce JSON and Disable reasoning take precedence.'],
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
    // Reference thumb positions are presentation only; an empty numeric field still inherits.
    $emptyPosition = match ($name) {
        'temperature', 'repetition_penalty' => 1,
        'top_p', 'min_p', 'top_a' => 0.5,
        'top_k' => 50,
        default => max(0, $minimum),
    };
    ?>
    <div class="llm-option-field<?php echo str_contains($name, 'tokens') ? ' llm-token-field' : ' llm-sampling-field'; ?>">
        <label for="<?php echo lorkhan_ui_h($id); ?>"><?php echo lorkhan_ui_h($label); ?></label>
        <div class="llm-option-control">
        <?php if (!str_contains($name, 'tokens')): ?>
        <input type="range" aria-label="<?php echo lorkhan_ui_h($label); ?> slider" data-range-for="<?php echo lorkhan_ui_h($id); ?>"
               min="<?php echo lorkhan_ui_h($minimum); ?>" max="<?php echo lorkhan_ui_h($maximum); ?>" step="<?php echo lorkhan_ui_h($step); ?>"
               data-empty-position="<?php echo lorkhan_ui_h($emptyPosition); ?>" value="<?php echo lorkhan_ui_h($value === '' ? $emptyPosition : $value); ?>"<?php echo $active ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>">
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

/** Keep a three-state form fallback; JavaScript adds Herika's checkbox without losing inheritance. */
function lorkhan_llm_boolean_field(array $field, array $options, string $formId, bool $active, array $runtimeDefaults): void
{
    [$name, $label, $inheritLabel, $help] = $field;
    $featureId = (string) ($field[4] ?? '');
    $stored = $options[$name] ?? null;
    $current = $stored === true ? 'true' : ($stored === false ? 'false' : '');
    $id = 'llm_option_' . $name;
    $inverted = $name === 'stream';
    ?>
    <div class="llm-option-field llm-boolean-field">
        <label for="<?php echo lorkhan_ui_h($id); ?>"><?php echo lorkhan_ui_h($label); ?><?php if ($featureId !== '') echo ' ' . lorkhan_ui_feature_badge($featureId, true); ?></label>
        <select id="<?php echo lorkhan_ui_h($id); ?>" name="option_<?php echo lorkhan_ui_h($name); ?>"
                data-direct-default="<?php echo in_array($name, ['stream', 'json_mode'], true) ? 'true' : 'false'; ?>"
                data-runtime-default="<?php echo !empty($runtimeDefaults[$name]) ? 'true' : 'false'; ?>" data-inverted="<?php echo $inverted ? 'true' : 'false'; ?>"
                aria-describedby="<?php echo lorkhan_ui_h($id); ?>-help"<?php echo $active ? '' : ' disabled'; ?> form="<?php echo lorkhan_ui_h($formId); ?>">
            <option value=""<?php echo $current === '' ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($inheritLabel); ?></option>
            <option value="true"<?php echo $current === 'true' ? ' selected' : ''; ?>><?php echo $inverted ? 'Off' : 'On'; ?></option>
            <option value="false"<?php echo $current === 'false' ? ' selected' : ''; ?>><?php echo $inverted ? 'On' : 'Off'; ?></option>
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
        <div id="llm-service-signup" class="orm-note llm-service-note" hidden><a target="_blank" rel="noopener noreferrer">Sign up here</a> to get your API key for this service.</div>
        <div id="llm-service-terms" class="orm-note llm-service-note" hidden>Check your provider's terms for content restrictions before use. <a href="https://openrouter.ai/terms#_6_-prohibited-conduct_" target="_blank" rel="noopener noreferrer">More info here.</a></div>
        <div id="llm-service-custom" class="orm-note llm-service-note" hidden>Enter the full chat completions endpoint for your OpenAI-compatible service below.</div>
    </div>
    <?php
}

$additionalStylesheets = ['herika-llm.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-llm.css'), '../js/ace/editor-ambiance.css'];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="d-flex flex-column llm-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-header lorkhan-page-head">
        <h1 class="api-title lorkhan-page-head-title">LLM Connectors</h1>
        <p class="page-subtitle lorkhan-page-head-note">Configure Language Model connectors for AI dialogue generation</p>
    </div>

    <div id="toast" class="toast-notification llm-toast-spacer<?php echo isset($_GET['status']) || $importedCount ? ' show' : ''; ?>" role="status" aria-live="polite">
        <span class="message"><?php
            if ($importedCount) echo 'Imported ' . $importedCount . ($importedCount === 1 ? ' connector.' : ' connectors.');
            elseif (isset($_GET['status'])) echo lorkhan_ui_h($_GET['status'] === 'tested' ? 'Test completed: ' . ($_GET['detail'] ?? 'valid response') : 'LLM connector saved.');
        ?></span>
    </div>

    <?php if ($installations !== []): ?>
    <form class="visually-hidden" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-runtime-test" aria-hidden="true"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?><button type="submit" tabindex="-1">Test Server runtime</button></form><span class="visually-hidden">LORKHAN_LLM_API_KEY</span>
    <?php endif; ?>

    <?php if ($installations === []): ?>
        <section class="connector-placeholder"><strong>Connect OpenMW once before creating connectors.</strong></section>
    <?php else: ?>
    <div class="llm-layout">
        <aside class="llm-left position-sticky">
            <div class="sidebar-action-grid">
                <a class="btn-save" href="<?php echo lorkhan_ui_h($queryFor(['create' => '1'])); ?>">New</a>
                <a class="btn-primary" href="<?php echo lorkhan_ui_h($queryFor(['import' => '1'])); ?>" data-llm-import-open title="Import portable LORKHAN connector files. No API keys are imported.">Import</a>
            </div>
            <form id="llm-quick-import" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-import" hidden>
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?>
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
            </form>
            <input id="llm-import-picker" type="file" accept="application/json,.json" multiple hidden>
            <p id="llm-import-status" class="llm-help" role="status" hidden></p>
            <div id="llm_list" class="conn-list" aria-label="LLM Connectors">
                <?php foreach ($rows as $row):
                    $content = is_array($row['content'] ?? null) ? $row['content'] : [];
                    $active = $selected !== null && $selected['configuration_id'] === $row['configuration_id'];
                    $inUse = (int) ($row['profile_usage'] ?? 0) > 0 || (int) ($row['active_session_usage'] ?? 0) > 0
                        || (int) ($row['queued_job_usage'] ?? 0) > 0 || (int) ($row['memory_policy_usage'] ?? 0) > 0;
                    $rowDriver = (string) ($content['driver'] ?? 'configured');
                    $rowService = $rowDriver === 'openai-compatible' && isset($content['service']) ? (string)$content['service'] : ($rowDriver === 'mock' ? '' : lorkhan_llm_endpoint_service((string)($rowDriver === 'configured' ? ($config['provider']['endpoint'] ?? '') : ($content['endpoint'] ?? ''))));
                    $rowBadge = ['openrouter'=>'OpenRouter','openai'=>'OpenAI','google'=>'Google','groq'=>'Groq','nanogpt'=>'NanoGPT','player2'=>'Player2','custom'=>'Custom','local'=>'Custom'][$rowService] ?? (LORKHAN_LLM_DRIVERS[$rowDriver][1] ?? $rowDriver);
                ?>
                <div class="conn-li<?php echo $active ? ' active' : ''; ?>" data-configuration-id="<?php echo lorkhan_ui_h($row['configuration_id']); ?>">
                    <a class="conn-li-select" href="<?php echo lorkhan_ui_h($queryFor(['edit' => $row['configuration_id']])); ?>" aria-label="Edit <?php echo lorkhan_ui_h($row['name']); ?>">
                        <span class="head"><span class="title"><?php echo lorkhan_ui_h($row['name']); ?></span><span class="badge" title="<?php echo lorkhan_ui_h(LORKHAN_LLM_DRIVERS[$rowDriver][0] ?? $rowDriver); ?>"><?php echo lorkhan_ui_h($rowBadge); ?></span></span>
                        <span class="sub"><?php echo lorkhan_ui_h($content['model'] ?? ''); ?></span>
                    </a>
                    <div class="actions">
                        <?php if (!$inUse): ?>
                        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-delete" data-confirm="Delete this connector?">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                            <button class="btn-danger" type="submit">Delete</button>
                        </form>
                        <?php else: ?>
                        <button class="btn-danger" type="button" disabled aria-disabled="true" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.llm.delete-protected')['description']); ?>">Delete</button>
                        <?php endif; ?>
                        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-clone">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($row['configuration_id']); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="name" value="<?php echo lorkhan_ui_h($row['name'] . ' copy'); ?>">
                            <button class="btn-primary" type="submit">Clone</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="llm-right">
            <?php if ($mode === 'none'): ?>
                <p class="visually-hidden">No connector selected. Select a connector from the list to view and edit its settings.</p>
            <?php elseif ($mode === 'import'): ?>
                <div class="form-container wide-centered llm-import-panel">
                    <div class="llm-editor-toolbar"><a class="btn-base" href="<?php echo lorkhan_ui_h($queryFor([])); ?>">Cancel</a></div>
                    <h2>Import LLM Connector <?php echo lorkhan_ui_feature_badge('config.llm.import-format', true); ?></h2>
                    <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-import">
                        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
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
                $credential = (string) ($content['credential'] ?? ($driver === 'configured' ? '__inherit__' : 'none'));
                if ($credential !== '__inherit__' && !isset($llmCredentials[$credential])) $credential = 'none';
                $storedTimeout = $content['timeout_ms'] ?? null;
                $timeout = is_int($storedTimeout) ? (string) $storedTimeout : (is_string($storedTimeout) ? $storedTimeout : '');
                $isDirect = $driver === 'openai-compatible';
                $isPlayer2 = $isDirect && ($content['service'] ?? '') === 'player2';
                $isMock = $driver === 'mock';
                $switchFields = array_column(LORKHAN_LLM_BOOLEAN_FIELDS, null, 0);
                $runtimeDefaults = array_replace(['stream'=>true, 'json_mode'=>true, 'reasoning_model'=>false, 'json_schema'=>false, 'prefill_json'=>false,
                    'disable_reasoning'=>(bool)($config['provider']['disable_reasoning'] ?? false)], (array)($config['provider']['options'] ?? []));
                $runtimeService = lorkhan_llm_endpoint_service((string)($config['provider']['endpoint'] ?? ''));
                // Inactive mode controls stay disabled so they neither submit nor block native validation.
                $unless = static fn(bool $active): string => $active ? '' : ' disabled';
            ?>
                <div class="form-container wide-centered llm-editor">
                    <form id="<?php echo lorkhan_ui_h($formId); ?>" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/<?php echo lorkhan_ui_h($formAction); ?>">
                        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?>
                        <?php if ($creating): ?><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><?php else: ?><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><input type="hidden" name="change_reason" value="Management LLM update"><?php endif; ?>
                        <input type="hidden" id="llm_service" name="service" value="<?php echo lorkhan_ui_h($isDirect ? ($content['service'] ?? '') : ''); ?>">
                    </form>

                    <div class="two-col-llm">
                        <div class="llm-column">
                            <div class="llm-editor-toolbar">
                                <button class="btn-save" type="submit" form="<?php echo lorkhan_ui_h($formId); ?>"><?php echo $creating ? 'Create' : 'Save'; ?></button>
                                <?php if (!$creating): ?>
                                <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-test" data-llm-test-form data-connector-name="<?php echo lorkhan_ui_h($selected['name']); ?>"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><button class="btn-primary llm-test-button" type="submit">Test</button></form>
                                <a class="btn-save" href="<?php echo lorkhan_ui_h($managementBasePath); ?>/exports/providers/<?php echo lorkhan_ui_h($selected['configuration_id']); ?>.json">Export</a>
                                <div class="llm-test-note">Test saves these settings first, then checks the connector. Provider charges may apply.</div>
                                <noscript><div class="llm-test-note">With JavaScript off, save changes before pressing Test.</div></noscript>
                                <?php endif; ?>
                                <?php if (!$creating): ?><span class="visually-hidden"><?php echo (int) ($selected['profile_usage'] ?? 0); ?> profiles</span><?php if ((int) ($selected['profile_usage'] ?? 0) > 0 || (int) ($selected['active_session_usage'] ?? 0) > 0 || (int) ($selected['queued_job_usage'] ?? 0) > 0 || (int) ($selected['memory_policy_usage'] ?? 0) > 0): ?><span class="visually-hidden">Connector is in use.</span><?php endif; ?><?php endif; ?>
                            </div>

                            <div class="llm-connection-field">
                                <label for="llm_name">Name</label>
                                <input id="llm_name" type="text" aria-describedby="llm_name-help" name="name" required maxlength="128" form="<?php echo lorkhan_ui_h($formId); ?>" value="<?php echo $creating ? '' : lorkhan_ui_h($selected['name']); ?>">
                                <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_name-help">This label appears in profile and player connector pickers. Renaming keeps existing assignments.</p>
                            </div>

                            <?php lorkhan_llm_service_picker($webRoot); ?>

                            <div class="llm-mode-panel llm-connection-field" id="llm_endpoint_row" data-llm-modes="openai-compatible"<?php echo $isDirect ? '' : ' hidden'; ?>>
                                <label for="llm_endpoint">URL</label>
                                <input id="llm_endpoint" type="url" name="endpoint" required maxlength="2048" inputmode="url" spellcheck="false"
                                       value="<?php echo lorkhan_ui_h($content['endpoint'] ?? ''); ?>" placeholder="http://127.0.0.1:1234/v1/chat/completions"
                                       aria-describedby="llm_endpoint-help"<?php echo $unless($isDirect); ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_endpoint-help">Paste the complete chat-completions URL. LORKHAN stores it verbatim and never appends or rewrites a path.</p>
                            </div>

                            <div class="llm-connection-field"<?php echo $isPlayer2 ? ' hidden' : ''; ?>>
                                <label for="llm_model">Model</label>
                                <input id="llm_model" type="text" name="model"<?php echo $isPlayer2 ? '' : ' required'; ?> maxlength="256" value="<?php echo lorkhan_ui_h($isPlayer2 ? '' : ($content['model'] ?? '')); ?>" aria-describedby="llm_model-help" form="<?php echo lorkhan_ui_h($formId); ?>" data-model-catalogue="<?php echo lorkhan_ui_h($managementBasePath . '/api/v1/llm-models'); ?>" data-groq-catalogue="<?php echo lorkhan_ui_h($managementBasePath . '/api/v1/llm-groq-models'); ?>" data-runtime-service="<?php echo lorkhan_ui_h($runtimeService); ?>" data-runtime-openrouter="<?php echo $runtimeService === 'openrouter' ? 'true' : 'false'; ?>">
                                <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_model-help">Required except for Player2, which uses the model selected in its app. Up to 256 characters, spelled exactly as the provider expects.</p>
                            </div>

                            <div class="llm-connection-field" id="llm_provider_row" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                <label for="llm_provider">Provider</label>
                                <input id="llm_provider" type="text" name="option_provider_order" maxlength="2078" value="<?php echo lorkhan_ui_h(implode(', ', $options['provider_order'] ?? [])); ?>" placeholder="(Optional) leave empty to use recommended provider" aria-describedby="llm_provider-help" form="<?php echo lorkhan_ui_h($formId); ?>" data-provider-catalogue="<?php echo lorkhan_ui_h($managementBasePath . '/api/v1/llm-providers'); ?>"<?php echo $unless(!$isMock); ?>>
                                <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_provider-help">Preferred OpenRouter provider slugs, separated by commas in priority order. Other providers can still handle the request if these are unavailable. Blank uses the default routing (or the configured runtime preference).</p>
                            </div>

                            <section class="llm-mode-panel" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>

                                <div class="llm-connection-field"<?php echo $isPlayer2 ? ' hidden' : ''; ?>>
                                    <label for="llm_credential">API Key</label>
                                    <select id="llm_credential" name="credential" aria-describedby="llm_credential-help llm_key_notice"<?php echo $unless(!$isMock); ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                        <option value="__inherit__"<?php echo $credential === '__inherit__' ? ' selected' : ''; ?><?php echo $driver !== 'configured' ? ' disabled hidden' : ''; ?>>Inherit runtime API key</option>
                                        <option value="none"<?php echo $credential === 'none' ? ' selected' : ''; ?>>No API key</option>
                                        <?php foreach ($llmCredentialGroups as $group => $groupCredentials): ?>
                                        <?php if ($group === 'missing' && $groupCredentials !== []): ?><option value="" disabled>— Missing Key —</option><?php endif; ?>
                                        <?php foreach ($groupCredentials as $credentialId => $credentialLabel): ?>
                                        <option value="<?php echo lorkhan_ui_h($credentialId); ?>" data-empty="<?php echo $group === 'missing' ? '1' : '0'; ?>"<?php echo $credential === $credentialId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h(($group === 'missing' ? '🔴 ' : '🟢 ') . $credentialLabel . ($group === 'missing' ? ' — No key' : '')); ?></option>
                                        <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php $keyNotice = $credential === 'none' ? 'No API key selected. Some services require a key.' : (isset($llmCredentialGroups['missing'][$credential]) ? 'Selected API key is empty. Add it on the API Keys page.' : ''); ?>
                                    <div id="llm_key_notice" class="api-key-notice warn" role="status"><?php echo lorkhan_ui_h($keyNotice); ?></div>
                                    <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_credential-help">Chooses which server-held key this connector sends. Inherit keeps the configured runtime key; No API key explicitly sends none. Exports reset this choice to No API key. Secret values never appear in this form, a revision or an export.</p>
                                </div>
                            </section>

                            <section class="llm-mode-panel llm-boolean-controls" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                    <?php foreach (['reasoning_model', 'json_mode', 'json_schema', 'prefill_json', 'stream'] as $name) lorkhan_llm_boolean_field($switchFields[$name], $options, $formId, !$isMock, $runtimeDefaults); ?>
                            </section>

                            <details class="llm-help-details llm-connection-options"<?php echo $isMock || isset($options['max_completion_tokens']) ? ' open' : ''; ?>>
                                <summary>Connection options</summary>
                                <div class="llm-connection-field">
                                    <label for="llm_driver">Mode</label>
                                    <select id="llm_driver" name="driver" aria-describedby="llm_driver-help" form="<?php echo lorkhan_ui_h($formId); ?>">
                                    <?php foreach (LORKHAN_LLM_DRIVERS as $driverId => $driverLabels): ?>
                                    <option value="<?php echo lorkhan_ui_h($driverId); ?>"<?php echo $driver === $driverId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($driverLabels[0]); ?></option>
                                    <?php endforeach; ?>
                                    </select>
                                    <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_driver-help">Configured runtime inherits the server endpoint and uses the selected API key or runtime inheritance. Direct calls one complete endpoint you supply. Deterministic mock never contacts a provider.</p>
                                </div>
                                <section class="llm-mode-panel llm-connection-panel" data-llm-modes="configured"<?php echo $driver === 'configured' ? '' : ' hidden'; ?>>
                                    <div class="llm-group-heading"><span>Inherited connection</span><?php echo lorkhan_ui_feature_badge('config.llm.service', true); ?></div>
                                    <p class="llm-help">The endpoint comes from the LorkhanServer runtime. API Key can inherit the runtime key, select another server-held key, or explicitly send no key.</p>
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
                                <section data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                    <?php foreach (LORKHAN_LLM_GENERATION_FIELDS as $field) if ($field[0] === 'max_completion_tokens') lorkhan_llm_number_field($field, $options, $formId, !$isMock); ?>
                                    <?php lorkhan_llm_boolean_field($switchFields['disable_reasoning'], $options, $formId, !$isMock, $runtimeDefaults); ?>
                                    <button type="button" class="btn-primary" data-llm-reset-switches hidden>Reset request switches to defaults</button>
                                </section>
                                <details class="llm-help-details">
                                    <summary>Endpoint rules</summary>
                                    <ul>
                                        <li>Give the whole path, for example <code>http://127.0.0.1:1234/v1/chat/completions</code>.</li>
                                        <li>Plain HTTP is accepted for loopback hosts only (<code>127.*</code> or <code>localhost</code>). Any other host must use HTTPS.</li>
                                        <li>No query string, no fragment, and no <code>user:password@</code> userinfo.</li>
                                        <li>Nothing is normalised or guessed from a provider preset, so a wrong path fails at Test rather than being silently corrected.</li>
                                    </ul>
                                </details>
                                <section class="llm-mode-panel" data-llm-modes="mock"<?php echo $isMock ? '' : ' hidden'; ?>>
                                    <div class="llm-connection-field">
                                        <label for="llm_mock_prefix">Mock prefix</label>
                                        <input id="llm_mock_prefix" type="text" name="mock_prefix" maxlength="256" value="<?php echo lorkhan_ui_h($content['mock_prefix'] ?? ''); ?>" aria-describedby="llm_mock_prefix-help"<?php echo $unless($isMock); ?> form="<?php echo lorkhan_ui_h($formId); ?>">
                                        <p class="llm-help llm-field-tooltip" role="tooltip" id="llm_mock_prefix-help">Prepended to every deterministic mock response, up to 256 characters. Saving in mock mode keeps whatever is written here.</p>
                                    </div>
                                </section>
                            </details>
                        </div>

                        <div class="llm-column">
                            <section class="llm-mode-panel" data-llm-modes="configured openai-compatible"<?php echo $isMock ? ' hidden' : ''; ?>>
                                <div class="llm-option-grid">
                                    <?php foreach (LORKHAN_LLM_GENERATION_FIELDS as $field) if ($field[0] !== 'max_completion_tokens') lorkhan_llm_number_field($field, $options, $formId, !$isMock); ?>
                                </div>

                                <section class="llm-advanced-panel">
                                    <div class="llm-group-heading"><span>Advanced LLM Settings Override</span><?php echo lorkhan_ui_feature_badge('config.llm.advanced', true); ?></div>
                                    <small class="llm-advanced-hint">If a value is left empty, the API provider's recommended default will be used.</small>
                                    <div class="llm-option-grid">
                                        <?php foreach (LORKHAN_LLM_SAMPLING_FIELDS as $field) lorkhan_llm_number_field($field, $options, $formId, !$isMock); ?>
                                    </div>
                                    <div class="llm-body-parameters">
                                        <label for="llm_body_yaml">Include Body Parameters (YAML)</label>
                                        <?php lorkhan_llm_boolean_field($switchFields['extra_parameters_enabled'], $options, $formId, !$isMock, $runtimeDefaults); ?>
                                        <div id="llm_body_editor" class="extra_parameters_editor_container" hidden></div>
                                        <input id="llm_body_present" type="hidden" name="body_yaml_present" value="<?php echo array_key_exists('extra_parameters_yaml', $options) ? '1' : '0'; ?>" form="<?php echo lorkhan_ui_h($formId); ?>">
                                        <textarea id="llm_body_yaml" name="option_extra_parameters_yaml" maxlength="16384" spellcheck="false" aria-describedby="llm_body_help" form="<?php echo lorkhan_ui_h($formId); ?>"<?php echo $unless(!$isMock); ?>><?php echo lorkhan_ui_h($options['extra_parameters_yaml'] ?? ''); ?></textarea>
                                        <p id="llm_body_help">Enter additional request body parameters in YAML format. (Advanced users only.)</p>
                                    </div>
                                    <button type="button" class="btn-danger" data-llm-clear-advanced hidden>Clear advanced settings</button>
                                </section>
                            </section>

                            <section class="llm-mode-panel llm-connection-panel" data-llm-modes="mock"<?php echo $isMock ? '' : ' hidden'; ?>>
                                <div class="llm-group-heading"><span>Deterministic mock</span></div>
                                <p class="llm-help">Mock connectors never contact a provider. Switching modes keeps unsaved field values, but Save stores only fields for the selected mode. Earlier saved settings remain in revision history.</p>
                            </section>

                        </div>
                    </div>

                    <?php if (!$creating): ?>
                    <details class="llm-revisions"><summary>Revision history</summary><?php $history = is_array($selected['revisions'] ?? null) ? $selected['revisions'] : []; lorkhan_ui_table($history); ?><?php $earlier = array_values(array_filter($history, static fn(array $revision): bool => (int) ($revision['revision'] ?? 0) !== (int) $selected['current_revision'])); if ($earlier !== []): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/provider-rollback"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php if ($partialEditor): ?><input type="hidden" name="partial" value="editor"><?php endif; ?><?php endif; ?><input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"><label for="llm_rollback_revision">Restore revision</label><select id="llm_rollback_revision" name="revision"><?php foreach ($earlier as $revision): ?><option value="<?php echo (int) $revision['revision']; ?>">Revision <?php echo (int) $revision['revision']; ?></option><?php endforeach; ?></select><button type="submit">Restore</button></form><?php endif; ?></details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
    <dialog id="llm-test-dialog" aria-labelledby="llm-test-title">
        <button type="button" class="btn-secondary" data-llm-test-close autofocus>Close</button>
        <div class="llm-test-viewport"><div class="llm-test-wrap">
            <h2 id="llm-test-title">🔧 LLM Connector Test</h2>
            <div class="llm-test-panel"><strong>Connector:</strong> <span data-llm-test-name></span></div>
            <div class="llm-test-panel" role="status" aria-live="polite"><strong>Status:</strong> <span data-llm-test-result></span></div>
            <div class="llm-test-panel"><strong>Test input:</strong><pre>Reply with one brief in-character greeting.</pre><p>Tests the settings saved by this click. Does not queue game dialogue.</p></div>
            <div class="llm-test-panel"><strong>Saved connector:</strong><pre data-llm-diagnostic="connector">Not tested yet.</pre></div>
            <div class="llm-test-panel"><strong>Validated response / Actions:</strong><pre data-llm-diagnostic="response">Not tested yet.</pre></div>
            <div class="llm-test-panel"><strong>Request payload:</strong><pre data-llm-diagnostic="request">Not tested yet.</pre></div>
            <div class="llm-test-panel"><strong>Usage:</strong><pre data-llm-diagnostic="usage">Not tested yet.</pre><p>Diagnostics are redacted and limited to 128 KiB per body. Transport headers and raw internal buffers are never displayed. Actions returned by this test are not executed.</p></div>
        </div>
        </div>
        <div class="llm-test-loading" data-llm-test-loading hidden><span class="llm-test-spinner" aria-hidden="true"></span><span class="visually-hidden">Testing connector</span></div>
    </dialog>
</main>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>" defer></script>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/ace/ace.js" defer></script>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/ace/mode-yaml.js" defer></script>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/ace/theme-ambiance.js" defer></script>
<script src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/llm-connectors.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/llm-connectors.js')); ?>" defer></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
