<?php
// Setup card structure derives from HerikaServer 529364c, wired to native revisioned routes.
$localKeyConfigured=($keyStatuses[\LorkhanServer\Application\QuickstartLocalLlm::CREDENTIAL]['configured']??false)===true;
$localKeyLocked=!$keyStoreReady||($keyStatuses[\LorkhanServer\Application\QuickstartLocalLlm::CREDENTIAL]['source']??'')==='environment';
?>
<section class="qs-section qs-profile-section" id="qs_settings_preset_section" data-local-test="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/quickstart-local-llm-test">
    <h2 class="qs-section-title">Setup</h2>
    <div class="form-group qs-field qs-settings-preset">
        <fieldset class="qs-preset-fieldset" aria-describedby="qs_settings_preset_desc">
            <legend class="qs-preset-label">Profile</legend>
            <div class="qs-preset-options">
            <?php foreach(['builtin:default'=>'Default','builtin:local_llm'=>'Local LLM'] as $value=>$label): ?>
                <label class="qs-preset-option"><input class="qs-preset-input" type="radio" name="settings_preset" value="<?= $value ?>"<?= $value==='builtin:default'?' checked':'' ?>><span class="qs-preset-card"><span class="qs-preset-mark" aria-hidden="true"></span><span class="qs-preset-title"><?= $label ?></span></span></label>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <p class="qs-preset-desc" id="qs_settings_preset_desc" role="status" aria-live="polite">Default Core Profile settings. Your selected connectors are kept.</p>
    </div>
    <fieldset class="qs-local-llm" id="qs_local_llm_panel" hidden disabled>
        <div class="qs-local-llm-head"><h3 class="qs-local-llm-title">Local LLM Setup</h3></div>
        <input type="hidden" name="local_fingerprint" value="<?= lorkhan_ui_h($localPlan['fingerprint']??'') ?>">
        <input type="hidden" name="local_key_configured" value="<?= $localKeyConfigured?'1':'0' ?>">
        <div class="qs-local-llm-grid">
            <div class="qs-local-llm-field"><label for="qs-local-server">Server type</label><select class="form-control" id="qs-local-server" name="local_server">
            <?php foreach(\LorkhanServer\Application\QuickstartLocalLlm::SERVERS as $value=>[$label,$port]): ?><option value="<?= $value ?>" data-port="<?= $port??'' ?>"<?= ($localState['server_type']??'lm_studio')===$value?' selected':'' ?>><?= lorkhan_ui_h($label) ?></option><?php endforeach; ?>
            </select></div>
            <div class="qs-local-llm-field"><label for="qs-local-model">Model name</label><input class="form-control" id="qs-local-model" name="local_model" required maxlength="256" autocomplete="off" spellcheck="false" placeholder="llama-3.1-8b-instruct" value="<?= lorkhan_ui_h($localContent['model']??'') ?>"><small class="form-text">Enter the exact model id your server reports.</small></div>
            <div class="qs-local-llm-field qs-local-llm-field-wide"><label for="qs-local-endpoint">Server URL</label><input class="form-control" type="url" id="qs-local-endpoint" name="local_endpoint" required autocomplete="off" spellcheck="false" value="<?= lorkhan_ui_h($localContent['endpoint']??'http://127.0.0.1:1234/v1/chat/completions') ?>" aria-describedby="qs-local-url-help qs-local-loopback">
                <small class="form-text" id="qs-local-url-help">OpenAI compatible chat completions endpoint. Defaults: LM Studio 1234, Ollama 11434, llama.cpp 8080, KoboldCPP 5001, path /v1/chat/completions.</small>
                <p class="qs-local-llm-note" id="qs-local-loopback" role="status" hidden>This URL points at the server itself. When LorkhanServer runs in WSL and your model runs on Windows, enter the Windows host IP instead.</p>
            </div>
            <fieldset class="qs-local-llm-field-wide qs-scope-fieldset"><legend class="qs-scope-legend">Where should LORKHAN use this model?</legend><div class="qs-scope-cards">
            <?php foreach(['conversations'=>['Dialogue only','Use this local model for in-game dialogue. Other AI tasks keep their current connectors.'],'all'=>['Dialogue + background tasks','Also use it for memories, summaries, relationships, profiles, scene handling, and other supporting tasks.']] as $value=>[$label,$description]): ?>
                <label class="qs-scope-option"><input class="qs-scope-input" type="radio" name="local_scope" value="<?= $value ?>"<?= ($localState['scope']??'conversations')===$value?' checked':'' ?>><span class="qs-scope-card"><span class="qs-scope-head"><span class="qs-scope-mark" aria-hidden="true"></span><span class="qs-scope-title"><?= $label ?></span><?php if($value==='conversations'): ?><span class="qs-scope-badge">Recommended</span><?php endif; ?></span><span class="qs-scope-desc"><?= $description ?></span></span></label>
            <?php endforeach; ?></div></fieldset>
        </div>
        <details class="qs-local-llm-advanced"><summary>Advanced</summary><div class="qs-local-llm-grid">
            <div class="qs-local-llm-field" data-quick-key="local_llm"><label for="qs-local-key">API key</label>
                <input class="form-control" type="password" id="qs-local-key" data-key-input data-locked="<?= $localKeyLocked?'1':'0' ?>" autocomplete="new-password" maxlength="8192" placeholder="<?= $localKeyConfigured?'Saved key will be kept unless replaced':'Optional API key' ?>"<?= $localKeyLocked?' disabled':'' ?> aria-describedby="qs-local-key-help qs-local-key-status">
                <button type="button" data-key-unhide hidden>Unhide</button>
                <small class="form-text" id="qs-local-key-help"><?= $localKeyLocked?'Key managed outside Quickstart.':'Optional. Most local servers ignore it, but some require any non empty value. Leave blank to keep the saved key.' ?></small>
                <div id="qs-local-key-status" data-key-status role="status" aria-live="polite" hidden></div>
            </div>
            <div class="qs-local-llm-field"><label for="qs-local-timeout">Timeout (seconds)</label><input class="form-control" type="number" id="qs-local-timeout" name="local_timeout" min="5" max="120" step="1" value="<?= (int)(($localContent['timeout_ms']??30000)/1000) ?>" required><small class="form-text">How long to wait for a reply before giving up. Default 30.</small></div>
            <div class="qs-local-llm-field qs-local-llm-field-wide"><label class="qs-local-llm-check"><input type="checkbox" name="local_disable_streaming"<?= ($localContent['options']['stream']??true)===false?' checked':'' ?>> Disable streaming</label><small class="form-text">Off by default. Turn on only if your server returns broken or empty streamed replies.</small></div>
        </div></details>
        <div class="qs-local-llm-test"><button type="button" class="btn-primary qs-mini-btn qs-test-btn" id="qs_test_local_llm">Test connection</button><div class="qs-status qs-local-llm-status" id="qs_local_llm_status" role="status" aria-live="polite" hidden></div></div>
        <p class="form-text">Saving updates the selected Core Profile, default NPC and Narrator model routes. NPC-specific overrides and your current in-game slot stay unchanged. Global behavior settings are not reset by this setup.</p>
    </fieldset>
</section>
