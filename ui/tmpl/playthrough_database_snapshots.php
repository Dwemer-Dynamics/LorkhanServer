<?php
$snapshotMessages=[
    'snapshot-save-queued'=>'Snapshot save queued. The worker will capture the full current database.',
    'snapshot-copy-queued'=>'Copy queued. Keep the game closed until restoration completes.',
    'snapshot-deleted'=>'Stored snapshot deleted. The active database was not deleted.',
    'snapshot-busy'=>'Another backup or restore is pending. Wait for it to finish before retrying.',
    'snapshot-name-exists'=>'A stored snapshot already has that name. Choose a different name; the existing snapshot was not changed.',
    'snapshot-protected'=>'This snapshot is queued for copying and cannot be deleted yet.',
    'snapshot-active-protected'=>'The active snapshot cannot be deleted. Switch to another snapshot first.',
    'snapshot-default-protected'=>'The initial default snapshot is protected and cannot be deleted.',
    'snapshot-delete-failed'=>'Snapshot deletion did not finish. A file may already be removed; retry to finish deletion.',
];
$snapshotCalendar=\LorkhanServer\Application\MorrowindCalendar::parse($liveDatabase['current']['calendar_data']??null)['label']??'n/a';
?>
<?php if(isset($snapshotMessages[$_GET['status']??''])): ?><p class="playthrough-notice" role="status"><?= lorkhan_ui_h($snapshotMessages[$_GET['status']]) ?></p><?php endif; ?>
<section class="content-section selected-playthrough" aria-labelledby="active-database-title">
    <div class="selected-title"><span aria-hidden="true">🎮</span><div><h2 id="active-database-title">Active Database</h2><p>This is the live database used by Lorkhan, including the native data behind its public views.</p></div></div>
    <div class="selected-details">
        <strong class="selected-name">📋 Last copied from: <?= lorkhan_ui_h($snapshotSource['name']??($snapshotSource['backup_id']?'Database backup':'(unknown)')) ?></strong><?php if($snapshotSource['backup_id']&&!filter_var($snapshotSource['stored'],FILTER_VALIDATE_BOOL)): ?><span>(stored copy deleted)</span><?php endif; ?>
        <span><b>Player:</b> <?= lorkhan_ui_h($liveDatabase['current']['player_name']??'Not recorded') ?></span><span><b>Game:</b> Morrowind</span>
        <span><b>Events:</b> <?= number_format((int)$snapshotCounts['events']) ?></span><span><b>Oghma:</b> <?= number_format((int)$snapshotCounts['knowledge']) ?></span>
        <span><b>Last in-game date:</b> <?= lorkhan_ui_h($snapshotCalendar) ?></span>
    </div>
    <?php if($snapshotTimeline!==[]): ?>
    <div class="timeline" id="pt-timeline" role="group" aria-label="Snapshot timeline by recorded Morrowind time" data-snapshot-timeline="<?= lorkhan_ui_h(json_encode($snapshotTimeline,JSON_THROW_ON_ERROR)) ?>">
        <div class="timeline-title"></div>
        <div class="timeline-track"></div><div class="timeline-notches"></div><div class="timeline-nodes"></div>
        <div class="timeline-legend"><span data-timeline-min></span><span data-timeline-max></span></div>
        <div class="timeline-tooltip" role="tooltip" id="pt-tooltip"></div>
    </div>
    <?php endif; ?>
</section>
<div class="content-grid database-snapshot-grid">
    <section class="content-section" aria-labelledby="database-save-title">
        <h2 id="database-save-title">📦 Save Current Public Database</h2>
        <p class="section-note">Save the whole server database, including native records behind the public schema. External media, voice files, credentials and game saves are not included.</p>
        <form method="post" class="create-form" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-snapshot">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="operation" value="create">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <label for="snapshot-name">Snapshot name</label><input id="snapshot-name" type="text" name="name" required maxlength="128" placeholder="e.g., Before Quest X">
            <label for="snapshot-notes">Notes (optional)</label><input id="snapshot-notes" type="text" name="notes" maxlength="1024" placeholder="e.g., Level 25, just finished main quest">
            <div class="button-group"><button type="submit" class="button snapshot-save">💾 Save Snapshot</button></div>
        </form>
        <p class="scope-note" role="status" data-database-maintenance data-kind="snapshot" data-state="<?= lorkhan_ui_h($snapshotSaveJob['state']??'') ?>" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/playthrough-snapshot">Latest save: <?= lorkhan_ui_h($snapshotSaveJob['state']??'none') ?>.</p>
        <p class="scope-note">Stored as private PostgreSQL archives. Snapshots share the backup storage limit. Keep downloaded SQL private.</p>
        <p class="scope-note">The first visit queues a protected default snapshot when no named snapshots exist. Its capture time is when the worker starts the backup.</p>
    </section>
    <section class="content-section" aria-labelledby="stored-snapshots-title">
        <h2 id="stored-snapshots-title">💾 Stored Snapshots</h2>
        <p class="section-note">Stored snapshots are not actively used. Copy to Public saves the current named playthrough before loading the selected snapshot. A separate rollback is kept when there is no current named playthrough. Keep the game closed, then reconnect with the corresponding game save. Schema and installation IDs must match.</p>
        <?php if($storedSnapshots===[]): ?><p class="playthrough-empty">No snapshots on this page. Save one from the left panel.</p><?php else: ?>
        <div class="backup-list" role="region" aria-label="Stored database snapshots" tabindex="0">
        <?php foreach($storedSnapshots as $snapshot): $isSource=$snapshotSource['backup_id']===$snapshot['backup_id'];
            $metadata=json_decode($snapshot['game_metadata']??'null',true)??[];
            $calendar=\LorkhanServer\Application\MorrowindCalendar::parse($metadata['calendar']??null);
            $timeDifference=$calendar!==null&&$snapshotLiveCalendar!==null?$calendar['minute']-$snapshotLiveCalendar['minute']:null;
            $daysApart=$timeDifference===null?0:(int)floor(abs($timeDifference)/1440);
        ?>
            <article class="backup-item<?= filter_var($snapshot['dragon_break']??false,FILTER_VALIDATE_BOOL)?' dragonbreak':'' ?><?= $isSource?' selected':'' ?>">
                <div class="backup-info">
                    <h3><?php if($isSource): ?><span class="snapshot-source-badge">✓ SOURCE OF PUBLIC</span><?php endif; ?><?= lorkhan_ui_h($snapshot['name']) ?></h3>
                    <div class="backup-meta"><span><?= lorkhan_ui_h($playthroughUtc($snapshot['created_at'])) ?> UTC</span><span><?= lorkhan_ui_table_value($snapshot['byte_count'],'bytes') ?></span><?php if($snapshot['rollback_for']): ?><span>Automatic rollback</span><?php endif; ?></div>
                    <div class="backup-meta"><span>Player: <?= lorkhan_ui_h($metadata['player_name']??'Not recorded') ?></span><span>Game: Morrowind</span><span>Events: <?= isset($metadata['events'])?number_format((int)$metadata['events']):'n/a' ?></span><span>Oghma: <?= isset($metadata['knowledge'])?number_format((int)$metadata['knowledge']):'n/a' ?></span><span>Last in-game: <?= lorkhan_ui_h($calendar['label']??'n/a') ?></span></div>
                    <?php if($timeDifference!==null): ?><span class="snapshot-time <?= $timeDifference<0?'behind':($timeDifference>0?'ahead':'') ?>"><?= $timeDifference===0?'Current Time':number_format($daysApart).' '.($daysApart===1?'day':'days').' '.($timeDifference<0?'behind':'ahead') ?></span><?php endif; ?>
                    <?php if($snapshot['notes']!==''): ?><p class="scope-note"><?= lorkhan_ui_h($snapshot['notes']) ?></p><?php endif; ?>
                    <div class="button-group snapshot-actions">
                        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-snapshot" data-snapshot-confirm="copy" data-snapshot-name="<?= lorkhan_ui_h($snapshot['name']) ?>">
                            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="operation" value="copy"><input type="hidden" name="backup_id" value="<?= lorkhan_ui_h($snapshot['backup_id']) ?>"><input type="hidden" name="confirm" value="Copy">
                            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                            <button class="button snapshot-copy" type="submit"<?= $isSource?' disabled':'' ?>><?= $isSource?'Already Active':'Copy to Public' ?></button>
                        </form>
                        <a class="button" href="<?= lorkhan_ui_h($managementBasePath.'/exports/database/'.$snapshot['backup_id'].'.sql') ?>">Download SQL</a>
                        <?php if(strtolower($snapshot['name'])!=='default'): ?><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-snapshot" data-snapshot-confirm="delete" data-snapshot-name="<?= lorkhan_ui_h($snapshot['name']) ?>">
                            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="operation" value="delete"><input type="hidden" name="backup_id" value="<?= lorkhan_ui_h($snapshot['backup_id']) ?>"><input type="hidden" name="confirm" value="Delete">
                            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                            <button class="button snapshot-delete btn-danger" type="submit"<?= $isSource?' disabled':'' ?> aria-label="Delete snapshot <?= lorkhan_ui_h($snapshot['name']) ?>">🗑️</button>
                        </form><?php else: ?><span class="selected-badge">Protected default</span><?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?></div><?php endif; ?>
        <nav class="button-group" aria-label="Snapshot pages"><?php if($snapshotPage>1): ?><a class="button" href="<?= lorkhan_ui_h($snapshotPageUrl($snapshotPage-1)) ?>">Previous</a><?php endif; ?><?php if($snapshotsHasNext): ?><a class="button" href="<?= lorkhan_ui_h($snapshotPageUrl($snapshotPage+1)) ?>">Next</a><?php endif; ?><a class="button" href="<?= lorkhan_ui_h($snapshotPageUrl($snapshotPage)) ?>">Refresh snapshots</a></nav>
        <p class="scope-note" role="status" data-database-maintenance data-kind="restore" data-state="<?= lorkhan_ui_h($snapshotRestoreJob['state']??'') ?>" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-restore">Latest copy: <?= lorkhan_ui_h($snapshotRestoreJob['state']??'none') ?>.</p>
    </section>
</div>
