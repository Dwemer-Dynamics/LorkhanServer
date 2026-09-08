<?php

declare(strict_types=1);

use LorkhanServer\Application\CredentialStore;

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'API Keys';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page api-keys-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$store = new CredentialStore((string) $config['credential_storage_path']);
$notice = '';
$error = '';
$responseStatus = 200;
$savedVariable = null;
$testHttpStatus = null;
// Environment-owned credentials cannot be replaced by an ineffective managed value.
$assertEditable = static function (string $variable): void {
    if (!CredentialStore::isAllowed($variable)) throw new InvalidArgumentException('invalid_credential_variable');
    $environment = getenv($variable);
    if (is_string($environment) && $environment !== '') throw new InvalidArgumentException('This key is managed by the server environment.');
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!is_string($_POST['_csrf'] ?? null) || !hash_equals($csrf, $_POST['_csrf'])) {
            $responseStatus = 401;
            throw new RuntimeException('unauthorized');
        }
        foreach (['action','test_key','add_custom','custom_name','custom_credential','delete_custom','variable','credential','display_label'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) throw new InvalidArgumentException('invalid_credentials');
        }
        if (isset($_POST['credentials']) && (!is_array($_POST['credentials'])
            || array_filter($_POST['credentials'], static fn($value): bool => !is_string($value)) !== [])) throw new InvalidArgumentException('invalid_credentials');
        $action = (string) ($_POST['action'] ?? '');
        if (isset($_POST['test_key'])) {
            $variable=(string)$_POST['test_key'];
            $url=match($variable){'LORKHAN_LLM_API_KEY'=>'https://openrouter.ai/api/v1/auth/key',
                'LORKHAN_TTS_OPENAI_API_KEY','LORKHAN_LLM_OPENAI_API_KEY'=>'https://api.openai.com/v1/models',
                default=>throw new InvalidArgumentException('invalid_credential_variable')};
            $key=trim((string)($_POST['credentials'][$variable]??''));if($key==='')$key=$store->resolve($variable);
            if($key===''||strlen($key)>8192||preg_match('/[\x00-\x1f\x7f]/',$key))throw new InvalidArgumentException('Enter an API key first.');
            $handle=curl_init($url);if($handle===false)throw new RuntimeException('credential_test_failed');
            curl_setopt_array($handle,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: application/json'],
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>8000,
                CURLOPT_WRITEFUNCTION=>static fn($handle,string $chunk):int=>strlen($chunk)]);
            try{$ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);}finally{curl_close($handle);}
            $testHttpStatus=$status;
            if($ok!==false&&$status>=200&&$status<300)$notice='API key accepted. Test does not save an unsaved key.';
            else $error='API key test failed (HTTP '.$status.'). Check the key, permissions, and connection.';
        } elseif ($action==='label') {
            $variable=(string)($_POST['variable']??'');$assertEditable($variable);
            $store->setLabel($variable,(string)($_POST['display_label']??''));$notice='Custom label saved. Connector references are unchanged.';
        } elseif (isset($_POST['add_custom'])) {
            $name=strtoupper(trim((string)($_POST['custom_name']??'')));
            if(preg_match('/^[A-Z][A-Z0-9_]{0,39}$/D',$name)!==1)throw new InvalidArgumentException('Use a key name containing letters, digits, or underscores.');
            $savedVariable='LORKHAN_CUSTOM_'.$name.'_API_KEY';
            $assertEditable($savedVariable);
            foreach ($store->statuses() as $status) if ($status['variable'] === $savedVariable) throw new InvalidArgumentException('A custom key with this label already exists.');
            $store->set($savedVariable,(string)($_POST['custom_credential']??''));
            $notice='Custom key saved. Select it in a service connector.';
        } elseif (isset($_POST['delete_custom'])) {
            $variable=(string)$_POST['delete_custom'];if(isset(CredentialStore::PRESET_LABELS[$variable]))throw new InvalidArgumentException('invalid_credential_variable');
            $assertEditable($variable);
            $store->delete($variable);$notice='Custom key removed.';
        } elseif (isset($_POST['save_all'])) {
            $credentials = $_POST['credentials'] ?? [];
            if (!is_array($credentials)) throw new InvalidArgumentException('invalid_credentials');
            $saved = 0;
            foreach ($credentials as $variable => $credential) {
                $variable = (string) $variable;
                $credential = is_string($credential) ? trim($credential) : '';
                if ($credential === '') continue;
                $assertEditable($variable);
                $store->set($variable, $credential);
                $saved++;
            }
            $notice = $saved === 1 ? '1 API key saved.' : $saved . ' API keys saved.';
        } else {
            $variable = (string) ($_POST['variable'] ?? '');
            if ($action === 'set') {
                $assertEditable($variable);
                $store->set($variable, (string) ($_POST['credential'] ?? ''));
                $notice = 'Credential saved.';
            } elseif ($action === 'delete') {
                $assertEditable($variable);
                $store->delete($variable);
                $notice = 'Managed credential removed.';
            } else {
                throw new InvalidArgumentException('invalid_credential_action');
            }
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'credential_update_failed';
        if ($responseStatus === 200) $responseStatus = 422;
    }
    // Asynchronous editors receive status only, never a rendered page or provider payload.
    if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        http_response_code($error === '' ? 200 : ($responseStatus === 200 ? 502 : $responseStatus));
        echo json_encode(['ok' => $error === '', 'message' => $error !== '' ? $error : $notice,
            'variable' => $error === '' ? $savedVariable : null] + ($testHttpStatus === null ? [] : ['test_http_status' => $testHttpStatus]), JSON_THROW_ON_ERROR);
        exit;
    }
}

$statuses = [];
foreach ($store->statuses() as $status) $statuses[(string) $status['variable']] = $status;
$providers = [
    'openrouter' => ['OpenRouter', 'https://openrouter.ai/keys', 'LORKHAN_LLM_API_KEY', ['LLM'], 'config.keys'],
    'openai' => ['OpenAI', 'https://platform.openai.com/api-keys', 'LORKHAN_TTS_OPENAI_API_KEY', ['LLM', 'TTS', 'STT'], 'config.keys'],
    'deepgram' => ['Deepgram', 'https://console.deepgram.com/', 'LORKHAN_TTS_DEEPGRAM_API_KEY', ['STT', 'TTS'], 'config.keys'],
    'google' => ['Google', 'https://console.cloud.google.com/apis/credentials', 'LORKHAN_TTS_GCP_API_KEY', ['LLM', 'TTS'], 'config.keys'],
    'azure' => ['Azure', 'https://ai.azure.com/', 'LORKHAN_TTS_AZURE_API_KEY', ['TTS', 'STT'], 'config.keys'],
    'elevenlabs' => ['ElevenLabs', 'https://elevenlabs.io/app/settings/api-keys', 'LORKHAN_TTS_ELEVENLABS_API_KEY', ['TTS'], 'config.keys'],
    'cartesia' => ['Cartesia', 'https://play.cartesia.ai/console', 'LORKHAN_TTS_CARTESIA_API_KEY', ['TTS'], 'config.keys'],
    'inworld' => ['Inworld', 'https://studio.inworld.ai/', 'LORKHAN_TTS_INWORLD_API_KEY', ['TTS'], 'config.keys'],
    'groq' => ['Groq', 'https://console.groq.com/keys', 'LORKHAN_LLM_GROQ_API_KEY', ['LLM'], 'config.keys'],
    'nano-gpt' => ['Nano-GPT', 'https://nano-gpt.com/', 'LORKHAN_LLM_NANOGPT_API_KEY', ['LLM'], 'config.keys'],
    'deepl' => ['DeepL', 'https://www.deepl.com/en/pro-api', 'LORKHAN_DEEPL_API_KEY', ['Translation'], 'config.keys.deepl'],
];

// Render saved and unused additional badges with identical reference card markup.
$renderCredentialCard = static function (string $variable, array $status): void {
    $environment=$status['source']==='environment';
?>
                    <article class="custom-card<?php echo $status['configured'] ? ' has-key' : ''; ?>" data-configured="<?php echo $status['configured'] ? 'true' : 'false'; ?>" data-key-card data-variable="<?php echo lorkhan_ui_h($variable); ?>">
                        <header class="provider-head"><div class="provider-title"><span class="provider-icon" aria-hidden="true">&#x1F9E9;</span><span>Custom Key</span></div><div class="key-actions"><button type="button" class="button btn-save" data-save-custom<?php echo $environment?' disabled':''; ?>>Save</button><button type="submit" class="button btn-delete btn-danger" name="delete_custom" value="<?php echo lorkhan_ui_h($variable); ?>"<?php echo $environment?' disabled':''; ?>>Delete</button></div></header>
                        <label for="custom-label-<?php echo lorkhan_ui_h($variable); ?>">Label</label><input id="custom-label-<?php echo lorkhan_ui_h($variable); ?>" type="text" value="<?php echo lorkhan_ui_h($status['label']??$variable); ?>" data-custom-display-label maxlength="80"<?php echo $environment || !$status['configured'] ? ' readonly' : ''; ?> title="Display label; connector identifier stays unchanged.">
                        <label for="custom-<?php echo lorkhan_ui_h($variable); ?>">API Key</label><div class="provider-body"><input id="custom-<?php echo lorkhan_ui_h($variable); ?>" type="password" name="credentials[<?php echo lorkhan_ui_h($variable); ?>]" placeholder="<?php echo $status['configured'] ? 'Leave blank to keep saved key' : 'Paste API key'; ?>" autocomplete="new-password" maxlength="8192"<?php echo $environment?' disabled':''; ?>>
                        <button type="button" class="button" data-key-visibility<?php echo $environment?' disabled':''; ?>>Show</button></div><div class="key-status" role="status" aria-live="polite" data-key-status><?php echo $environment?'Managed by the server environment.':''; ?></div></article>
<?php
};
$additionalKeys=[];$unusedKeys=[];
foreach($statuses as $variable=>$status){
    if(isset(CredentialStore::PRESET_LABELS[$variable]))continue;
    if($status['configured'])$additionalKeys[$variable]=$status;
    else $unusedKeys[$variable]=$status;
}

$additionalStylesheets = ['herika-api-keys.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-api-keys.css')];
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="api-keys-page">
    <header class="page-header lorkhan-page-head">
        <h1 class="api-title lorkhan-page-head-title">API Keys</h1>
        <p class="page-subtitle lorkhan-page-head-note">Manage API keys for LLM, TTS, and other service connectors</p>
    </header>

    <?php if ($notice !== ''): ?><div class="keys-notice" role="status"><?php echo lorkhan_ui_h($notice); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="keys-notice error" role="alert"><?php echo lorkhan_ui_h($error); ?></div><?php endif; ?>

    <form id="api-keys-form" method="post" action="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/api_keys.php<?php echo $embedded ? '?embed=1' : ''; ?>">
        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
        <div class="content-grid">
            <section class="content-section full-width-section">
                <div class="section-header">
                    <h2>Preset Keys (Saves Automatically)</h2>
                    <button type="submit" name="save_all" value="1" class="button btn-save">Save Keys</button>
                </div>
                <div class="provider-grid">
                    <?php foreach ($providers as $slug => [$label, $link, $variable, $uses, $featureId]):
                        $status = $variable !== null ? ($statuses[$variable] ?? ['configured' => false, 'source' => 'not configured']) : ['configured' => false, 'source' => 'not configured'];
                        $configured = (bool) $status['configured'];
                        $label = $status['label'] ?? $label;
                        $environment = $status['source'] === 'environment';
                        $available = $variable !== null && !$environment;
                        $placeholder = $configured ? 'Configured - enter replacement' : 'Paste API key';
                    ?>
                    <article class="provider-card" data-key-card data-variable="<?php echo lorkhan_ui_h($variable); ?>">
                        <header class="provider-head">
                            <div class="provider-title"><span class="provider-icon" aria-hidden="true">&#x1F511;</span><span><?php echo lorkhan_ui_h($label); ?></span></div>
                            <div class="provider-links">
                                <?php if ($featureId !== 'config.keys'): echo lorkhan_ui_feature_badge($featureId, true); endif; ?>
                                <?php if ($environment): ?><span class="key-source" tabindex="0" aria-label="Environment managed" aria-describedby="environment-help-<?= lorkhan_ui_h($variable) ?>"><span aria-hidden="true">🔒</span><span class="key-source-help" id="environment-help-<?= lorkhan_ui_h($variable) ?>" role="tooltip">Environment managed. Change this key in the server environment; it cannot be replaced here.</span></span><?php endif; ?>
                                <?php if ($link !== null): ?><a href="<?php echo lorkhan_ui_h($link); ?>" target="_blank" rel="noopener noreferrer">Create Key</a><?php endif; ?>
                            </div>
                        </header>
                        <div class="provider-body">
                            <?php $inputId = 'credential-' . $slug; ?><label class="visually-hidden" for="<?php echo lorkhan_ui_h($inputId); ?>"><?php echo lorkhan_ui_h($label); ?> API key</label><input id="<?php echo lorkhan_ui_h($inputId); ?>" type="password" aria-describedby="preset-key-help"<?php echo $variable !== null ? ' name="credentials[' . lorkhan_ui_h($variable) . ']"' : ''; ?> placeholder="<?php echo lorkhan_ui_h($placeholder); ?>" autocomplete="new-password" maxlength="8192"<?php echo $available ? '' : ' disabled aria-disabled="true"'; ?>>
                            <button type="button" class="button" data-key-visibility<?php echo $available ? '' : ' disabled aria-disabled="true"'; ?>>Show</button>
                            <?php if (in_array($slug, ['openrouter', 'openai','openai-llm'], true)): ?><button type="submit" class="btn-save" name="test_key" data-test-provider="<?php echo $slug === 'openrouter' ? 'OPENROUTER' : 'OPENAI'; ?>" value="<?php echo lorkhan_ui_h($variable); ?>">Test</button><?php endif; ?>
                        </div>
                        <div class="provider-subtext"><p class="desc">This key can be used for: <?php echo lorkhan_ui_h(implode(', ', $uses)); ?></p></div>
                        <div class="key-status" role="status" aria-live="polite" data-key-status></div>
                    </article>
                    <?php endforeach; ?>
                </div>
                <p id="preset-key-help" class="keys-help preset-key-help">Replacement keys save when you leave the card. Blank keeps the saved key. Show reveals only your entered replacement.</p>
            </section>

            <section class="content-section full-width-section">
                <h2>Custom Keys</h2>
                <div id="custom-keys" class="provider-grid"><?php foreach ($additionalKeys as $variable=>$status) $renderCredentialCard($variable,$status); ?></div>
                <button type="button" class="action-button add-new" id="add-custom-key">Add Custom Key</button>
                <p class="keys-help">New keys use a stable identifier with letters, digits and underscores. You can edit their display labels without changing saved connector references.</p>
                <?php if($unusedKeys!==[]): ?><details class="unused-key-slots"><summary>Additional credential slots</summary><p class="keys-help">Optional service and runtime slots. Saving a key preserves its existing connector identifier. Separate keys are never merged.</p><div class="provider-grid"><?php foreach($unusedKeys as $variable=>$status)$renderCredentialCard($variable,$status); ?></div></details><?php endif; ?>
            </section>
        </div>
    </form>
    <template id="custom-key-template"><article class="custom-card" data-key-card data-variable="">
        <header class="provider-head"><div class="provider-title"><span class="provider-icon" aria-hidden="true">&#x1F9E9;</span><span>Custom Key</span></div><div class="key-actions"><button type="button" class="button btn-save" data-save-custom>Save</button><button type="button" class="button btn-delete btn-danger" data-delete-draft>Delete</button></div></header>
        <label data-custom-label>Label</label><input type="text" data-new-label maxlength="40" pattern="[A-Za-z][A-Za-z0-9_]{0,39}" placeholder="Provider label (e.g., MyService)">
        <label data-custom-key-label>API Key</label><div class="provider-body"><input type="password" data-new-key autocomplete="new-password" maxlength="8192" placeholder="Paste API key"><button type="button" class="button" data-key-visibility>Show</button></div><div class="key-status" role="status" aria-live="polite" data-key-status></div>
    </article></template>
</main>
<dialog id="apikey-test-dialog" class="apikey-test-dialog" aria-labelledby="apikey-test-title" aria-describedby="apikey-test-help">
    <button type="button" class="button apikey-test-close" id="apikey-test-close" autofocus>Close</button>
    <div id="apikey-test-loading" class="apikey-test-loading" hidden aria-hidden="true"><span class="apikey-test-spinner"></span></div>
    <div class="apikey-test-content"><div class="apikey-test-panel"><div class="apikey-test-heading"><h1 id="apikey-test-title">API Key Test</h1><span id="apikey-test-provider"></span></div><div id="apikey-test-status" role="status" aria-live="polite"></div><p id="apikey-test-help" class="keys-help visually-hidden">Testing checks provider authentication. It does not save the entered key.</p></div></div>
</dialog>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/api-keys.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/api-keys.js')); ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
