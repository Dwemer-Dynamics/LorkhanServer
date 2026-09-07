<?php // Separate diary editor follows Herika diarylog.php; the native title remains secondary metadata. ?>
<dialog class="diary-editor-modal" id="edit-<?= lorkhan_ui_h($id) ?>" aria-labelledby="edit-title-<?= lorkhan_ui_h($id) ?>">
    <h2 id="edit-title-<?= lorkhan_ui_h($id) ?>">Edit Entry</h2>
    <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/narrative-revise') ?>" data-reader-form>
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= lorkhan_ui_h($id) ?>"><input type="hidden" name="kind" value="<?= lorkhan_ui_h($row['kind']) ?>"><input type="hidden" name="provenance" value="management diary edit">
        <div class="modal-body">
            <label for="edit-content-<?= lorkhan_ui_h($id) ?>">Content:</label>
            <small id="edit-help-<?= lorkhan_ui_h($id) ?>">Edit the content of the diary entry below.</small>
            <textarea id="edit-content-<?= lorkhan_ui_h($id) ?>" name="content" maxlength="65536" required autofocus aria-describedby="edit-help-<?= lorkhan_ui_h($id) ?>"><?= lorkhan_ui_h($row['content']) ?></textarea>
            <details class="diary-entry-metadata"><summary>Entry details</summary><label for="edit-name-<?= lorkhan_ui_h($id) ?>">Title</label><input id="edit-name-<?= lorkhan_ui_h($id) ?>" name="title" maxlength="256" required value="<?= lorkhan_ui_h($row['title']) ?>"></details>
            <p role="status" data-reader-form-status></p>
        </div>
        <div class="modal-footer"><button class="roleplay-button diary-save" type="submit">Save Changes</button><button class="roleplay-button" type="button" data-calendar-close>Cancel</button></div>
    </form>
</dialog>
<dialog class="diary-editor-modal diary-delete-modal" id="delete-<?= lorkhan_ui_h($id) ?>" aria-labelledby="delete-title-<?= lorkhan_ui_h($id) ?>">
    <h2 id="delete-title-<?= lorkhan_ui_h($id) ?>">Delete Entry</h2>
    <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/narrative-delete') ?>" data-reader-form data-reader-delete>
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="narrative_id" value="<?= lorkhan_ui_h($id) ?>">
        <div class="modal-body"><p>Delete “<?= lorkhan_ui_h($row['title']) ?>” from this playthrough?</p><p role="status" data-reader-form-status></p></div>
        <div class="modal-footer"><button class="roleplay-button danger" type="submit">Delete Entry</button><button class="roleplay-button" type="button" data-calendar-close autofocus>Cancel</button></div>
    </form>
</dialog>
