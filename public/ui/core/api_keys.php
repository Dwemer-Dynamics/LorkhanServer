<?php

declare(strict_types=1);

use LORKHANserver\Application\CredentialStore;

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'API Keys';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page api-keys-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$store = new CredentialStore((string) $config['credential_storage_path']);
$notice = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['_csrf'] ?? ''))) throw new RuntimeException('unauthorized');
        $action = (string) ($_POST['action'] ?? '');
        if (isset($_POST['save_all'])) {
            $credentials = $_POST['credentials'] ?? [];
            if (!is_array($credentials)) throw new InvalidArgumentException('invalid_credentials');
            $saved = 0;
            foreach ($credentials as $variable => $credential) {
                $variable = (string) $variable;
                $credential = is_string($credential) ? trim($credential) : '';
                if ($credential === '') continue;
                $store->set($variable, $credential);
                $saved++;
            }
            $notice = $saved === 1 ? '1 API key saved.' : $saved . ' API keys saved.';
        } else {
            $variable = (string) ($_POST['variable'] ?? '');
            if ($action === 'set') {
                $store->set($variable, (string) ($_POST['credential'] ?? ''));
                $notice = 'Credential saved.';
            } elseif ($action === 'delete') {
                $store->delete($variable);
                $notice = 'Managed credential removed.';
            } else {
                throw new InvalidArgumentException('invalid_credential_action');
            }
        }
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'credential_update_failed';
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
    'groq' => ['Groq', 'https://console.groq.com/keys', null, ['LLM'], 'config.keys.groq'],
    'nano-gpt' => ['Nano-GPT', 'https://nano-gpt.com/', null, ['LLM'], 'config.keys.nano-gpt'],
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

    <form method="post" action="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/api_keys.php<?php echo $embedded ? '?embed=1' : ''; ?>">
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
                        $environment = $status['source'] === 'environment';
                        $available = $variable !== null && !$environment;
                        $placeholder = $configured ? 'Configured - enter replacement' : 'Paste API key';
                    ?>
                    <article class="provider-card<?php echo $configured ? ' has-key' : ''; ?>">
                        <header class="provider-head">
                            <div class="provider-title"><span class="provider-icon" aria-hidden="true">&#x1F511;</span><span><?php echo lorkhan_ui_h($label); ?></span></div>
                            <div class="provider-links">
                                <?php if ($featureId !== 'config.keys'): echo lorkhan_ui_feature_badge($featureId, true); endif; ?>
                                <?php if ($environment): echo lorkhan_ui_feature_badge('config.keys.environment', true); endif; ?>
                                <?php if ($link !== null): ?><a href="<?php echo lorkhan_ui_h($link); ?>" target="_blank" rel="noopener noreferrer">Create Key</a><?php endif; ?>
                            </div>
                        </header>
                        <div class="provider-body">
                            <?php $inputId = 'credential-' . $slug; ?><label class="visually-hidden" for="<?php echo lorkhan_ui_h($inputId); ?>"><?php echo lorkhan_ui_h($label); ?> API key</label><input id="<?php echo lorkhan_ui_h($inputId); ?>" type="password"<?php echo $variable !== null ? ' name="credentials[' . lorkhan_ui_h($variable) . ']"' : ''; ?> placeholder="<?php echo lorkhan_ui_h($placeholder); ?>" autocomplete="new-password" maxlength="8192"<?php echo $available ? '' : ' disabled aria-disabled="true"'; ?>>
                            <button type="button" class="button" data-key-visibility<?php echo $available ? '' : ' disabled aria-disabled="true"'; ?>>Show</button>
                            <?php if (in_array($slug, ['openrouter', 'openai'], true)): ?><span class="status-control"><button type="button" class="btn-save" disabled aria-disabled="true">Test</button><?php echo lorkhan_ui_feature_badge('config.keys.test', true); ?></span><?php endif; ?>
                        </div>
                        <div class="provider-subtext"><p class="desc">This key can be used for: <?php echo lorkhan_ui_h(implode(', ', $uses)); ?></p></div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="content-section full-width-section">
                <div class="section-header"><h2>Custom Keys</h2><?php echo lorkhan_ui_feature_badge('config.keys.custom'); ?></div>
                <div id="custom-keys"></div>
                <span class="status-control add-custom-control"><button type="button" class="action-button add-new" disabled aria-disabled="true">Add Custom Key</button><?php echo lorkhan_ui_feature_badge('config.keys.custom', true); ?></span>
            </section>
        </div>
    </form>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/api-keys.js?v=<?php echo lorkhan_ui_h((string) filemtime(dirname(__DIR__) . '/js/api-keys.js')); ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
