<?php
declare(strict_types=1);
use LorkhanServer\Application\SttTestSample;
?>
<dialog id="stt-test-dialog" class="stt-test-dialog" aria-labelledby="stt-test-title"
        data-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1/stt-connector-tests"
        data-installation="<?php echo lorkhan_ui_h($installationId); ?>"
        data-configuration="<?php echo lorkhan_ui_h($selected['configuration_id']); ?>">
    <button type="button" class="btn-secondary stt-test-close" id="stt-test-close" autofocus>Close</button>
    <div class="stt-test-content">
        <h1 id="stt-test-title">Speech-to-Text Test</h1>
        <div class="stt-test-status" id="stt-test-status" role="status" aria-live="polite"></div>
        <div class="stt-test-message">Expected result: <em><?php echo lorkhan_ui_h(SttTestSample::TEXT); ?></em>
            <button type="button" id="stt-test-play" aria-label="Play test audio" title="Play test audio">&#9654;</button>
            <audio id="stt-test-sample" preload="none" src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/tests/assets/stt-test.wav"></audio>
        </div>
        <div id="stt-test-results" hidden>
            <div class="stt-test-response">Output: <span id="stt-test-transcript"></span></div>
            <div class="stt-test-message"><strong>Similarity: <span id="stt-test-similarity"></span>%</strong></div>
            <div class="stt-test-message">Service used: <strong id="stt-test-service"></strong><br>Elapsed: <span id="stt-test-elapsed"></span> ms</div>
        </div>
        <p class="stt-test-note">The sample is original synthetic speech, not recorded game dialogue. No TTS connector is called. Provider credentials and raw diagnostics remain private.</p>
        <button type="button" class="btn-primary" id="stt-test-retry" hidden>Test Again</button>
    </div>
</dialog>
