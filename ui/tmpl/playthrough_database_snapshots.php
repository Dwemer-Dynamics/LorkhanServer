<?php
$snapshotMessages=[
    'snapshot-saved'=>'Playthrough Save created.',
    'snapshot-setup'=>'Playthrough management is set up. Existing progress was preserved.',
    'snapshot-copy-queued'=>'Playthrough Save prepared. Load the corresponding game save to switch. Current progress was saved first.',
    'snapshot-deleted'=>'Stored Playthrough Save deleted. Live gameplay was not deleted.',
    'snapshot-busy'=>'Another save operation is running. Try again shortly.',
    'snapshot-switch-pending'=>'A switch is already pending. Load the character or cancel the pending association first.',
    'snapshot-character-required'=>'Choose a playthrough linked to a saved character before loading a copy.',
    'snapshot-protected'=>'This save is protected or no longer available.',
    'snapshot-incompatible'=>'This save needs a compatible database schema and its referenced shared profiles/connectors. Nothing was restored.',
    'snapshot-limit'=>'The save exceeded the archive size or time limit. No partial save was created.',
];
?>
<dialog id="switch-overlay" aria-labelledby="loading-title"><div class="loading-modal">
    <h2 class="loading-title" id="loading-title">Creating Playthrough Save…</h2>
    <div class="lds-ring" aria-hidden="true"><div></div><div></div><div></div><div></div></div>
    <p class="loading-sub">Please keep this tab open until the operation finishes.</p>
</div></dialog>
<?php if(isset($snapshotMessages[$_GET['status']??''])): ?><p class="playthrough-notice" role="status"><?= lorkhan_ui_h($snapshotMessages[$_GET['status']]) ?></p><?php endif; ?>
<?php if($needsSetup): ?>
<section class="content-section" aria-labelledby="playthrough-setup-title">
    <h2 id="playthrough-setup-title">🎮 Set up Playthrough Saves</h2>
    <p>Playthrough management is not set up yet. Nothing has been changed by opening this page.</p>
    <p class="section-note">Save your current LORKHAN data as the protected <strong>default</strong> Playthrough Save. Your current progress stays unchanged.</p>
    <form method="post" class="create-form" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-snapshot">
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="operation" value="setup">
        <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>"><input type="hidden" name="playthrough_id" value="<?= lorkhan_ui_h($selected['playthrough_id']) ?>">
        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <button type="submit" class="button snapshot-save">🚀 Set up Playthrough Saves</button>
    </form>
</section>
<?php return; endif; ?>
<?php if($snapshotTimeline!==[]): ?>
<section class="content-section"><div class="timeline" id="pt-timeline" role="group" aria-label="Playthrough Save timeline" data-snapshot-timeline="<?= lorkhan_ui_h(json_encode($snapshotTimeline,JSON_THROW_ON_ERROR)) ?>">
    <div class="timeline-title"></div><div class="timeline-track"></div><div class="timeline-notches"></div><div class="timeline-nodes"></div>
    <div class="timeline-legend"><span data-timeline-min></span><span data-timeline-max></span></div><div class="timeline-tooltip" role="tooltip" id="pt-tooltip"></div>
</div></section>
<?php endif; ?>
<div class="content-grid database-snapshot-grid">
    <section class="content-section" aria-labelledby="database-save-title">
        <h2 id="database-save-title">📦 Save Current Playthrough</h2>
        <p class="section-note">Save the selected character's NPC profiles, dialogue, memories, relationships, diaries and gameplay history. Shared settings, connectors and API keys stay unchanged.</p>
        <?php if($selected): ?>
        <form method="post" class="create-form" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-snapshot">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="operation" value="create">
            <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>"><input type="hidden" name="playthrough_id" value="<?= lorkhan_ui_h($selected['playthrough_id']) ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <label for="snapshot-name">Save name</label><input id="snapshot-name" type="text" name="name" required maxlength="128" placeholder="e.g., Before Quest X">
            <label for="snapshot-notes">Notes (optional)</label><input id="snapshot-notes" type="text" name="notes" maxlength="1024">
            <div class="button-group"><button type="submit" class="button snapshot-save">💾 Save Playthrough</button></div>
        </form>
        <?php else: ?><p class="playthrough-empty">Load a character first.</p><?php endif; ?>
        <p class="scope-note">Game saves and audio files are not included. Full database backups, including older SQL snapshots, remain in <a href="<?= lorkhan_ui_h($webRoot) ?>/ui/database_manager.php">Database Manager</a>.</p>
        <p class="scope-note">Save limits: 128 MiB, with up to 50,000 playthrough rows and 50,000 additional gameplay-state rows. Restores require the same database schema. Generated summaries are preserved; embeddings regenerate.</p>
    </section>
    <section class="content-section" aria-labelledby="stored-snapshots-title">
        <h2 id="stored-snapshots-title">💾 Stored Playthrough Saves</h2>
        <p class="section-note">Close the game before loading a copy. Current progress is saved first; the copy takes effect on your next character load. Shared configuration is never restored.</p>
        <?php if($storedSnapshots===[]): ?><p class="playthrough-empty">No saved copies yet.</p><?php else: ?>
        <div class="backup-list" role="region" aria-label="Stored Playthrough Saves" tabindex="0">
        <?php foreach($storedSnapshots as $snapshot): ?>
            <article class="backup-item<?= filter_var($snapshot['dragon_break'],FILTER_VALIDATE_BOOL)?' dragonbreak':'' ?>"><div class="backup-info">
                <h3><?= lorkhan_ui_h($snapshot['name']) ?></h3>
                <div class="backup-meta"><span><?= lorkhan_ui_h($playthroughUtc($snapshot['created_at'])) ?> UTC</span><span><?= lorkhan_ui_table_value($snapshot['byte_count'],'bytes') ?></span><span><?= lorkhan_ui_h($snapshot['kind']) ?></span></div>
                <?php if($snapshot['notes']!==''): ?><p class="scope-note"><?= lorkhan_ui_h($snapshot['notes']) ?></p><?php endif; ?>
                <div class="button-group snapshot-actions">
                    <?php if($selected): ?><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-snapshot" data-snapshot-confirm="copy" data-snapshot-name="<?= lorkhan_ui_h($snapshot['name']) ?>">
                        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="operation" value="copy"><input type="hidden" name="backup_id" value="<?= lorkhan_ui_h($snapshot['backup_id']) ?>"><input type="hidden" name="confirm" value="Copy">
                        <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>"><input type="hidden" name="playthrough_id" value="<?= lorkhan_ui_h($selected['playthrough_id']) ?>">
                        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <button class="button snapshot-copy" type="submit">Load Copy</button>
                    </form><?php endif; ?>
                    <?php if($snapshot['kind']!=='default'): ?><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-snapshot" data-snapshot-confirm="delete" data-snapshot-name="<?= lorkhan_ui_h($snapshot['name']) ?>">
                        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="operation" value="delete"><input type="hidden" name="backup_id" value="<?= lorkhan_ui_h($snapshot['backup_id']) ?>"><input type="hidden" name="confirm" value="Delete">
                        <input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>">
                        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <button class="button snapshot-delete btn-danger" type="submit" aria-label="Delete save <?= lorkhan_ui_h($snapshot['name']) ?>">🗑️</button>
                    </form><?php else: ?><span class="selected-badge">Protected default</span><?php endif; ?>
                </div>
            </div></article>
        <?php endforeach; ?></div><?php endif; ?>
        <nav class="button-group" aria-label="Playthrough Save pages"><?php if($snapshotPage>1): ?><a class="button" href="<?= lorkhan_ui_h($snapshotPageUrl($snapshotPage-1)) ?>">Previous</a><?php endif; ?><?php if($snapshotsHasNext): ?><a class="button" href="<?= lorkhan_ui_h($snapshotPageUrl($snapshotPage+1)) ?>">Next</a><?php endif; ?></nav>
    </section>
</div>
