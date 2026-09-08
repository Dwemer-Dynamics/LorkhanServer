<?php
declare(strict_types=1);
use LorkhanServer\Application\SpeechPreviewCatalog;
$testReady = ($ttsPreview['voices'] ?? []) !== [];
?>
<dialog id="tts-test-dialog" class="tts-test-dialog" aria-labelledby="tts-test-title"
        data-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1/tts-previews"
        data-installation="<?php echo lorkhan_ui_h($installationId); ?>"
        data-configuration="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>"
        data-csrf="<?php echo lorkhan_ui_h($csrf); ?>">
    <div class="tts-test-toolbar"><button type="button" class="btn-secondary" id="tts-test-close" autofocus>Close</button></div>
    <div class="tts-test-content">
        <section class="tts-test-card">
            <h1 id="tts-test-title">TTS Connector Test</h1>
            <div><strong>Connector:</strong> <?php echo lorkhan_ui_h($selected['name']); ?></div>
            <div><strong>Provider:</strong> <?php echo lorkhan_ui_h($drivers[$selected['content']['driver']] ?? ''); ?></div>
            <p class="field-help">Uses saved settings. Opening this dialog does not generate audio. Run Test may incur provider charges.</p>
        </section>
        <form id="tts-test-form" class="tts-test-card">
            <div class="field">
                <label for="tts-test-text">Text To Synthesize</label>
                <textarea id="tts-test-text" required maxlength="<?php echo SpeechPreviewCatalog::MAX_TEXT_LENGTH; ?>">Greetings, traveler. This is LORKHAN.</textarea>
            </div>
            <div class="field">
                <label for="tts-test-voice">VoiceId</label>
                <select id="tts-test-voice" required<?php echo $testReady ? '' : ' disabled'; ?>>
                    <?php if (!$testReady): ?><option value="">No voice available</option><?php endif; ?>
                    <?php foreach ($ttsPreview['voices'] as $voice): ?>
                        <option value="<?php echo lorkhan_ui_h($voice); ?>"<?php echo $voice === $ttsPreview['default_voice'] ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($voice); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="field-help"><?php echo $testReady ? 'Uses the narrator voice when this connector offers it, then its default voice. This selection affects this test only.' : 'No installed or discovered voice is available for this connector. Add or synchronize a voice in TTS Studio first.'; ?></p>
            </div>
            <button type="submit" class="btn-save" id="tts-test-run"<?php echo $testReady ? '' : ' disabled'; ?>>Run Test</button>
        </form>
        <section class="tts-test-card" id="tts-test-result" hidden>
            <h2>Status</h2>
            <p id="tts-test-status" role="status" aria-live="polite"></p>
            <audio id="tts-test-audio" controls hidden aria-label="TTS test audio"></audio>
        </section>
        <section class="tts-test-card" id="tts-test-request" hidden>
            <h2>Request Preview</h2>
            <pre id="tts-test-request-text"></pre>
            <p class="field-help">Only the test text and voice are shown. Credentials and raw provider diagnostics remain private.</p>
        </section>
    </div>
</dialog>
