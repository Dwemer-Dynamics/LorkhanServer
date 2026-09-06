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
        foreach (['action','test_key','add_custom','custom_name','custom_credential','delete_custom','variable','credential'] as $field) {
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
            if($ok!==false&&$status>=200&&$status<300)$notice='API key accepted. Test does not save an unsaved key.';
            else $error='API key test failed (HTTP '.$status.'). Check the key, permissions, and connection.';
        } elseif (isset($_POST['add_custom'])) {
            $name=strtoupper(trim((string)($_POST['custom_name']??'')));
            if(preg_match('/^[A-Z][A-Z0-9_]{0,39}$/D',$name)!==1)throw new InvalidArgumentException('Use a key name containing letters, digits, or underscores.');
            $savedVariable='LORKHAN_CUSTOM_'.$name.'_API_KEY';
            $assertEditable($savedVariable);
            foreach ($store->statuses() as $status) if ($status['variable'] === $savedVariable) throw new InvalidArgumentException('A custom key with this label already exists.');
            $store->set($savedVariable,(string)($_POST['custom_credential']??''));
            $notice='Custom key saved. Select it in a service connector.';
        } elseif (isset($_POST['delete_custom'])) {
            $variable=(string)$_POST['delete_custom'];if(!str_starts_with($variable,'LORKHAN_CUSTOM_'))throw new InvalidArgumentException('invalid_credential_variable');
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
            'variable' => $error === '' ? $savedVariable : null], JSON_THROW_ON_ERROR);
        exit;
    }
}

$statuses = [];
foreach ($store->statuses() as $status) $statuses[(string) $status['variable']] = $status;
$providers = [
    'openrouter' => ['Default LLM key (OpenRouter)', 'https://openrouter.ai/keys', 'LORKHAN_LLM_API_KEY', ['configured runtime and Default LLM key'], 'config.keys'],
    'openai-llm' => ['OpenAI LLM key', 'https://platform.openai.com/api-keys', 'LORKHAN_LLM_OPENAI_API_KEY', ['direct LLM connectors selecting OpenAI LLM key'], 'config.keys'],
    'openrouter-llm' => ['OpenRouter LLM key', 'https://openrouter.ai/keys', 'LORKHAN_LLM_OPENROUTER_API_KEY', ['direct LLM connectors selecting OpenRouter LLM key'], 'config.keys'],
    'custom-llm' => ['Custom LLM key', null, 'LORKHAN_LLM_CUSTOM_API_KEY', ['direct LLM connectors selecting Custom LLM key'], 'config.keys'],
    'openai' => ['OpenAI speech key', 'https://platform.openai.com/api-keys', 'LORKHAN_TTS_OPENAI_API_KEY', ['TTS'], 'config.keys'],
    'deepgram' => ['Deepgram', 'https://console.deepgram.com/', 'LORKHAN_TTS_DEEPGRAM_API_KEY', ['STT', 'TTS'], 'config.keys'],
    'google' => ['Google', 'https://console.cloud.google.com/apis/credentials', 'LORKHAN_TTS_GCP_API_KEY', ['LLM', 'TTS'], 'config.keys'],
    'azure' => ['Azure', 'https://ai.azure.com/', 'LORKHAN_TTS_AZURE_API_KEY', ['TTS', 'STT'], 'config.keys'],
    'elevenlabs' => ['ElevenLabs', 'https://elevenlabs.io/app/settings/api-keys', 'LORKHAN_TTS_ELEVENLABS_API_KEY', ['TTS'], 'config.keys'],
    'cartesia' => ['Cartesia', 'https://play.cartesia.ai/console', 'LORKHAN_TTS_CARTESIA_API_KEY', ['TTS'], 'config.keys'],
    'inworld' => ['Inworld', 'https://studio.inworld.ai/', 'LORKHAN_TTS_INWORLD_API_KEY', ['TTS'], 'config.keys'],
    'google-stt' => ['Google Gemini STT', 'https://aistudio.google.com/apikey', 'LORKHAN_STT_GEMINI_API_KEY', ['STT'], 'config.keys'],
    'groq' => ['Groq', 'https://console.groq.com/keys', 'LORKHAN_LLM_GROQ_API_KEY', ['LLM'], 'config.keys'],
    'nano-gpt' => ['Nano-GPT', 'https://nano-gpt.com/', 'LORKHAN_LLM_NANOGPT_API_KEY', ['LLM'], 'config.keys'],
    'google-llm' => ['Google LLM', 'https://aistudio.google.com/apikey', 'LORKHAN_LLM_GOOGLE_API_KEY', ['LLM'], 'config.keys'],
    'deepl' => ['DeepL', 'https://www.deepl.com/en/pro-api', 'LORKHAN_DEEPL_API_KEY', ['Translation'], 'config.keys.deepl'],
];

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
                <p class="keys-help">Replacement keys save when you leave the card. Blank keeps the saved key. Show reveals only your entered replacement.</p>
                <div class="provider-grid">
                    <?php foreach ($providers as $slug => [$label, $link, $variable, $uses, $featureId]):
                        $status = $variable !== null ? ($statuses[$variable] ?? ['configured' => false, 'source' => 'not configured']) : ['configured' => false, 'source' => 'not configured'];
                        $configured = (bool) $status['configured'];
                        $environment = $status['source'] === 'environment';
                        $available = $variable !== null && !$environment;
                        $placeholder = $configured ? 'Configured - enter replacement' : 'Paste API key';
                    ?>
                    <article class="provider-card" data-key-card data-variable="<?php echo lorkhan_ui_h($variable); ?>">
                        <header class="provider-head">
                            <div class="provider-title"><span class="provider-icon" aria-hidden="true">&#x1F511;</span><span><?php echo lorkhan_ui_h($label); ?></span></div>
                            <div class="provider-links">
                                <?php if ($featureId !== 'config.keys'): echo lorkhan_ui_feature_badge($featureId, true); endif; ?>
                                <?php if ($environment): ?><span class="key-source" title="Change this key in the server environment; it cannot be replaced here.">Environment managed</span><?php endif; ?>
                                <?php if ($link !== null): ?><a href="<?php echo lorkhan_ui_h($link); ?>" target="_blank" rel="noopener noreferrer">Create Key</a><?php endif; ?>
                            </div>
                        </header>
                        <div class="provider-body">
                            <?php $inputId = 'credential-' . $slug; ?><label class="visually-hidden" for="<?php echo lorkhan_ui_h($inputId); ?>"><?php echo lorkhan_ui_h($label); ?> API key</label><input id="<?php echo lorkhan_ui_h($inputId); ?>" type="password"<?php echo $variable !== null ? ' name="credentials[' . lorkhan_ui_h($variable) . ']"' : ''; ?> placeholder="<?php echo lorkhan_ui_h($placeholder); ?>" autocomplete="new-password" maxlength="8192"<?php echo $available ? '' : ' disabled aria-disabled="true"'; ?>>
                            <button type="button" class="button" data-key-visibility<?php echo $available ? '' : ' disabled aria-disabled="true"'; ?>>Show</button>
                            <?php if (in_array($slug, ['openrouter', 'openai','openai-llm'], true)): ?><button type="submit" class="btn-save" name="test_key" value="<?php echo lorkhan_ui_h($variable); ?>">Test</button><?php endif; ?>
                        </div>
                        <div class="provider-subtext"><p class="desc">This key can be used for: <?php echo lorkhan_ui_h(implode(', ', $uses)); ?></p></div>
                        <div class="key-status" role="status" aria-live="polite" data-key-status></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="content-section full-width-section">
                <h2>Custom Keys</h2>
                <div id="custom-keys" class="provider-grid"><?php foreach($statuses as $variable=>$status): if(preg_match('/^LORKHAN_CUSTOM_(.+)_API_KEY$/D',$variable,$match)!==1)continue; $environment=$status['source']==='environment'; ?>
                    <article class="custom-card has-key" data-key-card data-variable="<?php echo lorkhan_ui_h($variable); ?>">
                        <header class="provider-head"><div class="provider-title"><span class="provider-icon" aria-hidden="true">&#x1F9E9;</span><span>Custom Key</span></div><div class="key-actions"><button type="button" class="button btn-save" data-save-custom<?php echo $environment?' disabled':''; ?>>Save</button><button type="submit" class="button btn-delete btn-danger" name="delete_custom" value="<?php echo lorkhan_ui_h($variable); ?>"<?php echo $environment?' disabled':''; ?>>Delete</button></div></header>
                        <label for="custom-label-<?php echo lorkhan_ui_h($match[1]); ?>">Label</label><input id="custom-label-<?php echo lorkhan_ui_h($match[1]); ?>" type="text" value="<?php echo lorkhan_ui_h($match[1]); ?>" readonly title="This identifier is referenced by saved connectors.">
                        <label for="custom-<?php echo lorkhan_ui_h($match[1]); ?>">API Key</label><div class="provider-body"><input id="custom-<?php echo lorkhan_ui_h($match[1]); ?>" type="password" name="credentials[<?php echo lorkhan_ui_h($variable); ?>]" placeholder="Leave blank to keep saved key" autocomplete="new-password" maxlength="8192"<?php echo $environment?' disabled':''; ?>>
                        <button type="button" class="button" data-key-visibility<?php echo $environment?' disabled':''; ?>>Show</button></div><div class="key-status" role="status" aria-live="polite" data-key-status><?php echo $environment?'Managed by the server environment.':''; ?></div></article>
                <?php endforeach; ?></div>
                <button type="button" class="action-button add-new" id="add-custom-key">Add Custom Key</button>
                <p class="keys-help">Custom labels are stable connector identifiers: letters, digits and underscores, starting with a letter. Existing labels cannot yet be renamed.</p>
            </section>
        </div>
    </form>
    <template id="custom-key-template"><article class="custom-card" data-key-card data-variable="">
        <header class="provider-head"><div class="provider-title"><span class="provider-icon" aria-hidden="true">&#x1F9E9;</span><span>Custom Key</span></div><div class="key-actions"><button type="button" class="button btn-save" data-save-custom>Save</button><button type="button" class="button btn-delete btn-danger" data-delete-draft>Delete</button></div></header>
        <label data-custom-label>Label<input type="text" data-new-label maxlength="40" pattern="[A-Za-z][A-Za-z0-9_]{0,39}" placeholder="Provider label (e.g., MyService)"></label>
        <label data-custom-key-label>API Key</label><div class="provider-body"><input type="password" data-new-key autocomplete="new-password" maxlength="8192" placeholder="Paste API key"><button type="button" class="button" data-key-visibility>Show</button></div><div class="key-status" role="status" aria-live="polite" data-key-status></div>
    </article></template>
</main>
<dialog id="apikey-test-dialog" class="apikey-test-dialog" aria-labelledby="apikey-test-title">
    <button type="button" class="button apikey-test-close" id="apikey-test-close" autofocus>Close</button>
    <div class="apikey-test-content"><div class="apikey-test-panel"><div class="apikey-test-heading"><h1 id="apikey-test-title">API Key Test</h1><span id="apikey-test-provider"></span></div><div id="apikey-test-status" role="status" aria-live="polite"></div><p class="keys-help">Testing checks provider authentication. It does not save the entered key.</p></div></div>
</dialog>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/api-keys.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/api-keys.js')); ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
