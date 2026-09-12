<?php
// Herika npc_master.php history_viewer structure, bound to native immutable revisions.
$versionRows=is_array($row['revisions']??null)?$row['revisions']:[];
?>
<dialog class="npc-versions-dialog" id="<?= lorkhan_ui_h($modalKey) ?>-versions" aria-labelledby="<?= lorkhan_ui_h($modalKey) ?>-versions-title"
    data-npc-versions data-version-url="<?= lorkhan_ui_h($managementBasePath.'/api/v1/npc-profile-versions/'.$profileId) ?>"
    data-version-list="<?= lorkhan_ui_h(json_encode($versionRows,JSON_THROW_ON_ERROR)) ?>">
    <header class="npc-versions-header"><h2 id="<?= lorkhan_ui_h($modalKey) ?>-versions-title">NPC Profile Versions</h2><button type="button" class="btn-cancel" data-versions-close>Close</button></header>
    <div class="npc-versions-body">
        <nav class="npc-versions-list" aria-label="Saved profile versions" data-versions-list></nav>
        <div class="npc-versions-detail">
            <p class="npc-versions-note">Saved profile content. Actor identity and Core Profile assignment are not part of these snapshots.</p>
            <p data-versions-status role="status">Select a snapshot to view details.</p>
            <form method="post" action="<?= lorkhan_ui_h($managementBasePath.'/forms/profile-rollback') ?>" data-versions-restore hidden>
                <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="profile_id" value="<?= lorkhan_ui_h($profileId) ?>">
                <input type="hidden" name="base_revision" value="<?= (int)$row['current_revision'] ?>"><input type="hidden" name="revision">
                <?php lorkhan_ui_hidden_state($listState); ?>
                <div class="npc-versions-toolbar"><span data-version-date></span><button type="submit" class="btn-save">Restore this version</button></div>
            </form>
            <div class="npc-versions-fields" data-version-fields></div>
        </div>
    </div>
</dialog>
