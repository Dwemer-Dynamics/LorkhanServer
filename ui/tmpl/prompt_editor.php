<?php /* Herika-style prompt rows/readers over Lorkhan's revisioned prompt documents. */ ?>
<section class="prompt-database">
    <div class="search-heading">
        <label for="prompt-search">Search Prompts</label>
        <p>Filter by prompt key, description, status, or preview text.</p>
        <input id="prompt-search" type="search" class="form-control" placeholder="Search prompts..." autocomplete="off" data-prompt-search>
        <p data-prompt-search-empty hidden>No prompts match your current search.</p>
    </div>
    <?php if($rows===[]): ?>
        <div class="prompt-warning"><h3>⚠️ No Prompts Found</h3><p>Use Prompt documents → Create Prompt to add one.</p></div>
    <?php else: ?>
    <div class="prompt-table-wrap table-container"><table class="prompts-table"><thead><tr><th>Prompt Key</th><th>Description</th><th>Status</th><th>Preview</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $row):
        $content=$row['content'];$custom=(string)($content['custom_prompt']??'');$isCustom=trim($custom)!=='';
        $description=(string)($content['description']??$row['name']);
        $preview=mb_strimwidth($isCustom?$custom:(string)($content['default_prompt']??''),0,150,'...');
        $id=(string)$row['configuration_id'];
    ?>
        <tr data-prompt-row data-search="<?= lorkhan_ui_h(mb_strtolower($row['prompt_key'].' '.$description.' '.($isCustom?'custom':'default').' '.$preview)) ?>">
            <td class="prompt-key-cell"><code><?= lorkhan_ui_h($row['prompt_key']) ?></code></td>
            <td class="prompt-description"><?= lorkhan_ui_h($description) ?></td>
            <td><span class="prompt-status <?= $isCustom?'custom':'default' ?>"><?= $isCustom?'🎨 Custom':'📋 Default' ?></span></td>
            <td class="prompt-content-cell"><div class="prompt-preview <?= $isCustom?'custom':'' ?>"><?= lorkhan_ui_h($preview) ?></div></td>
            <td><div class="row-actions"><button type="button" class="prompt-button prompt-edit-button" data-prompt-edit="<?= lorkhan_ui_h($id) ?>">✏️ Edit</button>
                <?php if($isCustom): ?><button type="button" class="prompt-button prompt-clear-button" data-prompt-clear="<?= lorkhan_ui_h($id) ?>">🔄 Clear</button><?php endif; ?>
            </div></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php foreach($rows as$row):
    $id=(string)$row['configuration_id'];$content=$row['content'];$formId='prompt-form-'.$id;
?>
<dialog class="prompt-edit-modal modal-content" id="prompt-editor-<?= lorkhan_ui_h($id) ?>" aria-labelledby="prompt-title-<?= lorkhan_ui_h($id) ?>" data-prompt-dialog>
    <header class="modal-header"><button type="button" class="prompt-close" data-prompt-close aria-label="Close">&times;</button><h2 id="prompt-title-<?= lorkhan_ui_h($id) ?>">✏️ Edit Prompt: <span><?= lorkhan_ui_h($row['prompt_key']) ?></span></h2></header>
    <div class="prompt-modal-body modal-body">
        <form id="<?= lorkhan_ui_h($formId) ?>" method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/configuration-revise" data-prompt-save>
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
            <input type="hidden" name="kind" value="prompt">
            <input type="hidden" name="configuration_id" value="<?= lorkhan_ui_h($id) ?>">
            <input type="hidden" name="prompt_text_editor" value="1">
            <input type="hidden" name="expected_revision" value="<?= (int)$row['current_revision'] ?>">
            <input type="hidden" name="change_reason" value="management custom prompt">
            <div class="prompt-form-group"><strong>📝 Description &amp; File Location</strong><p><?= lorkhan_ui_h($content['description']??$row['name']) ?></p></div>
            <div class="prompt-form-group"><strong>📋 Default Prompt (Read-Only)</strong><div class="prompt-default readonly-content" tabindex="0" aria-label="Default Prompt"><?= lorkhan_ui_h($content['default_prompt']??'') ?></div></div>
            <div class="prompt-form-group"><label for="prompt-custom-<?= lorkhan_ui_h($id) ?>">🎨 Custom Prompt (Optional - Leave empty to use default)</label>
                <textarea class="form-control" id="prompt-custom-<?= lorkhan_ui_h($id) ?>" name="custom_prompt" maxlength="65536" placeholder="Enter your custom prompt here, or leave empty to use the default prompt..."><?= lorkhan_ui_h($content['custom_prompt']??'') ?></textarea>
            </div>
            <?php $renderPlayerMoodFields('prompt-'.$id,is_array($content['player_mood_prompts']??null)?$content['player_mood_prompts']:[]); ?>
        </form>
        <details class="prompt-management-tools"><summary>Revision history and management</summary>
            <p>Revision <?= (int)$row['current_revision'] ?> · <?= (int)$row['profile_usage'] ?> explicit profile assignments.</p>
            <?php lorkhan_ui_table(is_array($row['revisions'])?$row['revisions']:[]); ?>
            <a class="prompt-button" href="<?= lorkhan_ui_h($managementBasePath) ?>/exports/prompts/<?= lorkhan_ui_h($id) ?>.json">Export prompt</a>
            <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/prompt-clone"><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="configuration_id" value="<?= lorkhan_ui_h($id) ?>"><label>Clone name<input name="name" required value="<?= lorkhan_ui_h($row['name'].' copy') ?>"></label><button type="submit" class="prompt-button">Clone</button></form>
            <?php if((int)$row['profile_usage']===0): ?><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/configuration-delete" data-confirm="Delete this prompt document? This is separate from clearing its custom text."><input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="kind" value="prompt"><input type="hidden" name="configuration_id" value="<?= lorkhan_ui_h($id) ?>"><button type="submit" class="prompt-button danger">Delete prompt document</button></form>
            <?php else: ?><p>Assigned prompts cannot be deleted.</p><?php endif; ?>
        </details>
        <p data-prompt-save-status role="status" hidden></p>
    </div>
    <footer class="modal-footer"><button type="button" class="prompt-button" data-prompt-close>Cancel</button><button type="submit" class="prompt-button primary" form="<?= lorkhan_ui_h($formId) ?>">💾 Save Custom Prompt</button></footer>
</dialog>
<?php endforeach; ?>
