<?php
declare(strict_types=1);
// Edit the native profile document; immutable observations remain in Recorded State.
?>
<div class="form-item span-2 npc-observed-state"><details class="npc-metadata-collapse" data-profile-json-editor>
<summary>Metadata (JSON)</summary><div class="npc-metadata-collapse-body">
<small class="hint">General NPC profile data used by Lorkhan systems. Visible fields and setting overrides take precedence when saving. This does not change recorded game state, actor identity or live game values.</small>
<div data-json-editor-target></div>
<label for="<?= lorkhan_ui_h($formId) ?>-metadata">Profile content (JSON fallback)</label>
<textarea id="<?= lorkhan_ui_h($formId) ?>-metadata" form="<?= lorkhan_ui_h($formId) ?>" name="base_content_json" data-json-editor-source spellcheck="false" rows="12"><?= lorkhan_ui_h(json_encode($content?:new stdClass(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?></textarea>
<p role="status" data-json-editor-status></p>
</div></details></div>
