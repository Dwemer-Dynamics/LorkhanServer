<?php foreach($rows as$row):
    $id=(string)$row['configuration_id'];$content=$row['content'];$formId='prompt-form-'.$id;
?>
<dialog class="prompt-edit-modal modal-content" id="prompt-editor-<?= lorkhan_ui_h($id) ?>" aria-labelledby="prompt-title-<?= lorkhan_ui_h($id) ?>" data-prompt-dialog<?= !empty($narratorInlinePromptEditor)?' data-prompt-inline':'' ?>>
    <header class="modal-header"><button type="button" class="prompt-close" data-prompt-close aria-label="Close">&times;</button><h2 id="prompt-title-<?= lorkhan_ui_h($id) ?>"><?= !empty($narratorInlinePromptEditor)?'':'✏️ ' ?>Edit Prompt: <span><?= lorkhan_ui_h($row['prompt_key']) ?></span></h2></header>
    <div class="prompt-modal-body modal-body">
        <form id="<?= lorkhan_ui_h($formId) ?>" method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/<?= !empty($row['narrator_event_prompt'])?'narrator-prompt-save':'configuration-revise' ?>" data-prompt-save>
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
            <?php if(!empty($row['narrator_event_prompt'])): ?>
            <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($row['installation_id']) ?>">
            <input type="hidden" name="prompt_key" value="<?= lorkhan_ui_h($row['prompt_key']) ?>">
            <?php endif; ?>
            <input type="hidden" name="kind" value="prompt">
            <input type="hidden" name="configuration_id" value="<?= lorkhan_ui_h($id) ?>">
            <input type="hidden" name="prompt_text_editor" value="1">
            <input type="hidden" name="expected_revision" value="<?= (int)$row['current_revision'] ?>">
            <input type="hidden" name="change_reason" value="management custom prompt">
            <div class="prompt-form-group"><strong><?= !empty($narratorInlinePromptEditor)?'Description':'📝 Description &amp; File Location' ?></strong><p><?= lorkhan_ui_h($content['description']??$row['name']) ?></p></div>
            <div class="prompt-form-group"><strong><?= !empty($narratorInlinePromptEditor)?'':'📋 ' ?>Default Prompt (Read-Only)</strong><div class="prompt-default readonly-content" tabindex="0" aria-label="Default Prompt"><?= lorkhan_ui_h($content['default_prompt']??'') ?></div></div>
            <div class="prompt-form-group"><label for="prompt-custom-<?= lorkhan_ui_h($id) ?>"><?= !empty($narratorInlinePromptEditor)?'':'🎨 ' ?>Custom Prompt (Optional - Leave empty to use default)</label>
                <textarea class="form-control" id="prompt-custom-<?= lorkhan_ui_h($id) ?>" name="custom_prompt" maxlength="<?= !empty($row['narrator_event_prompt'])?'32768':'65536' ?>" placeholder="Enter your custom prompt here, or leave empty to use the default prompt..."><?= lorkhan_ui_h($content['custom_prompt']??'') ?></textarea>
            </div>
            <?php if(empty($row['narrator_event_prompt'])) $renderPlayerMoodFields('prompt-'.$id,is_array($content['player_mood_prompts']??null)?$content['player_mood_prompts']:[]); ?>
        </form>
        <?php if(empty($row['narrator_event_prompt'])): ?>
        <details class="prompt-management-tools"><summary>Revision history and management</summary>
            <p>Revision <?= (int)$row['current_revision'] ?> · <?= (int)$row['profile_usage'] ?> explicit profile assignments.</p>
            <?php lorkhan_ui_table(is_array($row['revisions'])?$row['revisions']:[]); ?>
            <a class="prompt-button" href="<?= lorkhan_ui_h($managementBasePath) ?>/exports/prompts/<?= lorkhan_ui_h($id) ?>.json">Export prompt</a>
            <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/prompt-clone"><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="configuration_id" value="<?= lorkhan_ui_h($id) ?>"><label>Clone name<input name="name" required value="<?= lorkhan_ui_h($row['name'].' copy') ?>"></label><button type="submit" class="prompt-button">Clone</button></form>
            <?php if((int)$row['profile_usage']===0): ?><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/configuration-delete" data-confirm="Delete this prompt document? This is separate from clearing its custom text."><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="kind" value="prompt"><input type="hidden" name="configuration_id" value="<?= lorkhan_ui_h($id) ?>"><button type="submit" class="prompt-button danger">Delete prompt document</button></form>
            <?php else: ?><p>Assigned prompts cannot be deleted.</p><?php endif; ?>
        </details>
        <?php else: ?><p class="prompt-format-hint">Shared with Narrator Management and Prompts Manager. Saved changes apply to future generation requests; queued work keeps its frozen instructions.</p><?php endif; ?>
        <p data-prompt-save-status role="status" hidden></p>
    </div>
    <footer class="modal-footer"><button type="button" class="prompt-button" data-prompt-close>Cancel</button><button type="submit" class="prompt-button primary" form="<?= lorkhan_ui_h($formId) ?>"><?= !empty($narratorInlinePromptEditor)?'':'💾 ' ?>Save Custom Prompt</button></footer>
</dialog>
<?php endforeach; ?>
