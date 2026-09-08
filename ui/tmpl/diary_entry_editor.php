<?php // Like Herika's hidden topic, preserve the title while editing diary content. ?>
<dialog class="diary-editor-modal" id="edit-<?= lorkhan_ui_h($id) ?>" aria-labelledby="edit-title-<?= lorkhan_ui_h($id) ?>">
    <h2 id="edit-title-<?= lorkhan_ui_h($id) ?>">Edit Entry</h2>
    <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/narrative-revise') ?>" data-reader-form>
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= lorkhan_ui_h($id) ?>"><input type="hidden" name="kind" value="<?= lorkhan_ui_h($row['kind']) ?>"><input type="hidden" name="provenance" value="management diary edit">
        <input type="hidden" name="title" value="<?= lorkhan_ui_h($row['title']) ?>">
        <div class="modal-body">
            <label for="edit-content-<?= lorkhan_ui_h($id) ?>">Content:</label>
            <small id="edit-help-<?= lorkhan_ui_h($id) ?>">Edit the content of the diary entry below.</small>
            <textarea id="edit-content-<?= lorkhan_ui_h($id) ?>" name="content" maxlength="65536" required autofocus aria-describedby="edit-help-<?= lorkhan_ui_h($id) ?>"><?= lorkhan_ui_h($row['content']) ?></textarea>
            <p role="status" data-reader-form-status></p>
        </div>
        <div class="modal-footer"><div class="button-group"><button class="roleplay-button diary-save" type="submit">Save Changes</button><button class="roleplay-button" type="button" data-calendar-close>Cancel</button></div></div>
    </form>
</dialog>
    <form hidden id="delete-<?= lorkhan_ui_h($id) ?>" method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/narrative-delete') ?>" data-reader-form data-reader-delete>
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= lorkhan_ui_h($id) ?>">
    </form>
