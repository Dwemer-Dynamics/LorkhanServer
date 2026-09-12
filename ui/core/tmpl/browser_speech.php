<?php declare(strict_types=1); ?>
<dialog id="browser-speech-dialog" class="stt-test-dialog" aria-labelledby="browser-speech-title"
 data-api="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1" data-installation="<?php echo lorkhan_ui_h($installationId); ?>"
 data-csrf="<?php echo lorkhan_ui_h($csrf); ?>">
 <button type="button" class="btn-secondary stt-test-close" id="browser-speech-close">Close</button>
 <div class="stt-test-content browser-speech-content">
  <h1 id="browser-speech-title">🎤 Free Auto Speech-to-Text</h1>
  <h2>Uses Chrome's built-in speech recognition</h2>
  <div id="browser-speech-result" aria-live="polite">Click “Start Listening” to begin speech recognition.</div>
  <div class="browser-speech-controls">
   <select id="browser-speech-session" aria-label="Game session"><option value="">Loading game sessions…</option></select>
   <select id="browser-speech-language" aria-label="Recognition language">
    <?php foreach(['en-US'=>'English (US)','en-GB'=>'English','es-ES'=>'Spanish','fr-FR'=>'French','de-DE'=>'German','zh-CN'=>'Chinese (Simplified)','ja-JP'=>'Japanese','hi-IN'=>'Hindi','ru-RU'=>'Russian'] as $code=>$label): ?>
    <option value="<?php echo lorkhan_ui_h($code); ?>"><?php echo lorkhan_ui_h($label); ?></option><?php endforeach; ?>
   </select>
   <select id="browser-speech-delay" aria-label="Silence delay">
    <?php for($seconds=1;$seconds<=10;$seconds++): ?><option value="<?php echo $seconds; ?>"><?php echo $seconds; ?> second<?php echo $seconds===1?'':'s'; ?> delay</option><?php endfor; ?>
   </select>
   <button id="browser-speech-toggle" type="button" disabled>Start Listening</button>
  </div>
  <p id="browser-speech-status" role="status" aria-live="polite"></p>
  <div class="browser-speech-info">
   <p><strong>Free Auto STT</strong> lets your browser send recognized speech to the selected LORKHAN game session. Start it while in game. Chrome may send microphone audio to Google's recognition service.</p>
   <p><strong>How to use it:</strong></p>
   <ol><li>Select your game session and language.</li><li>Click <strong>Start Listening</strong> and allow microphone access.</li><li>Leave this tab running in the background. Speech is sent after the selected silence delay.</li><li>Aim at an NPC or select a dialogue target in game. Avoid using push-to-talk or open mic at the same time.</li></ol>
   <p>Stop Listening or Close stops microphone recognition and discards unsent words. An utterance already queued cannot be recalled here. “Dialogue queued” means the game accepted the turn, not that response audio has finished.</p>
   <p>If microphone access fails, check Chrome's site permissions and Windows microphone permissions. Use localhost or HTTPS; do not disable browser security.</p>
  </div>
 </div>
</dialog>
