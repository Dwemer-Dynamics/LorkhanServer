<?php
declare(strict_types=1);
// Normalize database timestamps even when the PostgreSQL session uses a local timezone.
$playthroughUtc=static fn(?string$value):string=>$value===null?'None recorded':(new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
?>
<main class="playthrough-page">
    <header class="page-header">
        <h1>Playthrough Manager</h1>
        <p class="page-subtitle">Characters and their separate Lorkhan histories.</p>
        <div class="playthrough-help"><strong>Switch in game:</strong> Load the corresponding game save to switch playthroughs. New games receive their own data scope automatically. Existing saves ask once whether to keep existing Lorkhan data or start a new playthrough.</div>
    </header>
    <?php if(isset($_GET['status'])&&$_GET['status']==='saved'): ?><p class="playthrough-notice" role="status">Profile record operation completed.</p><?php endif; ?>
    <form method="get" class="playthrough-scope" aria-label="Playthrough installation">
        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label for="playthrough-installation">Installation</label>
        <select name="installation_id" id="playthrough-installation"><?php foreach($installations as$row): ?><option value="<?= lorkhan_ui_h($row['installation_id']) ?>"<?= $installationId===$row['installation_id']?' selected':'' ?>><?= lorkhan_ui_h($row['display_name']) ?></option><?php endforeach; ?></select>
        <button type="submit"<?= $installations===[]?' disabled':'' ?>>Apply</button>
    </form>
    <section class="content-section" aria-labelledby="active-character-title">
        <h2 id="active-character-title">Active saved character</h2>
        <?php $currentCharacter=$characterState['current']??null; ?>
        <?php if(!empty($currentCharacter['character_id'])): ?>
        <dl class="snapshot-scope"><dt>Character identity</dt><dd><?= lorkhan_ui_h($currentCharacter['character_id']) ?></dd><dt>Playthrough</dt><dd><?= lorkhan_ui_h($currentCharacter['playthrough_id']) ?></dd><dt>Session</dt><dd><?= lorkhan_ui_h($currentCharacter['state']) ?></dd></dl>
        <p class="scope-note">This is the most recently connected saved character. NPC and Player profiles belong to its playthrough. Narrator and reusable templates are shared.</p>
        <?php else: ?><p class="playthrough-empty">No saved character has been linked yet. Load your game and complete the one-time playthrough choice.</p><?php endif; ?>
        <h3>Linked characters</h3>
        <?php if(($characterState['bindings']??[])===[]): ?><p class="section-note">Linked characters appear here after the game connects.</p><?php else: ?>
        <div class="backup-list" role="region" aria-label="Linked characters" tabindex="0">
        <?php foreach($characterState['bindings'] as$binding): ?>
        <article class="backup-item"><div class="backup-info"><h3><?= lorkhan_ui_h($binding['name']) ?></h3><div class="backup-meta"><span>Character: <?= lorkhan_ui_h($binding['character_id']) ?></span></div></div>
            <?php if($binding['playthrough_id']===$activePlaythroughId): ?><span class="selected-badge">Active</span><?php endif; ?>
            <a class="button" href="<?= lorkhan_ui_h($playthroughUrl($binding['playthrough_id'])) ?>">View records</a>
        </article><?php endforeach; ?></div><?php endif; ?>
        <p class="section-note">Viewing records does not switch the active character. Load the corresponding game save to switch.</p>
    </section>
    <section class="content-section selected-playthrough" aria-labelledby="selected-playthrough-title">
        <div class="selected-title"><span aria-hidden="true">🎮</span><div><h2 id="selected-playthrough-title">Selected Playthrough</h2><p>Read-only overview of the selected Morrowind playthrough.</p></div></div>
        <?php if($selected): ?>
        <div class="selected-details"><strong class="selected-name">📋 <?= lorkhan_ui_h($selected['playthrough']) ?></strong>
            <span><b>Profile:</b> <?= lorkhan_ui_h($selected['profile']) ?></span><span><b>Game:</b> Morrowind</span>
            <?php foreach(['Sessions'=>'sessions','Turns'=>'turns','Responses'=>'responses','Memories'=>'memories','Relationships'=>'relationships','Narratives'=>'narratives','Knowledge'=>'knowledge_records']as$label=>$key): ?><span><b><?= $label ?>:</b> <?= number_format((int)$selected[$key]) ?></span><?php endforeach; ?>
            <span><b>Last session (UTC):</b> <?= lorkhan_ui_h($playthroughUtc($selected['last_session_at'])) ?></span>
        </div><p class="scope-note">Overview counts cover the whole playthrough. Profile snapshots contain only the owning profile's memories, relationships and narratives.</p>
        <?php else: ?><p class="playthrough-empty">No playthroughs exist for this installation yet.</p><?php endif; ?>
    </section>
    <div class="content-grid">
        <section class="content-section" aria-labelledby="snapshot-title">
            <h2 id="snapshot-title">📦 Export Profile Snapshot</h2>
            <p class="section-note">Download the selected profile's records as JSON. Conversations, source events, knowledge, configuration, credentials and audio are not included.</p>
            <?php if($selected): ?><dl class="snapshot-scope"><dt>Playthrough</dt><dd><?= lorkhan_ui_h($selected['playthrough']) ?></dd><dt>Profile</dt><dd><?= lorkhan_ui_h($selected['profile']) ?></dd></dl>
            <div class="button-group"><a class="button" href="<?= lorkhan_ui_h($managementBasePath) ?>/exports/playthroughs/<?= lorkhan_ui_h($selected['playthrough_id']) ?>.json">💾 Download Snapshot</a></div>
            <?php else: ?><p class="playthrough-empty">Connect a saved character before exporting its records.</p><?php endif; ?>
            <p class="scope-note">Exports include private Custom Info notes from relationship records. Share these files carefully.</p>
        </section>
        <section class="content-section" aria-labelledby="playthrough-list-title">
            <h2 id="playthrough-list-title">💾 Playthroughs</h2>
            <p class="section-note">Select a playthrough to inspect its records. These are live data scopes, not stored database backups.</p>
            <?php if($page>1||$hasNextPage): ?><nav class="button-group" aria-label="Playthrough pages">
                <?php if($page>1): ?><a class="button" href="<?= lorkhan_ui_h($pageUrl($page-1)) ?>">Previous</a><?php endif; ?>
                <span class="scope-note">Page <?= $page ?> · <?= count($rows) ?> playthroughs</span>
                <?php if($hasNextPage): ?><a class="button" href="<?= lorkhan_ui_h($pageUrl($page+1)) ?>">Next</a><?php endif; ?>
            </nav><?php endif; ?>
            <?php if($rows===[]): ?><p class="playthrough-empty"><?= $page>1?'No playthroughs on this page. Use Previous to return to earlier results.':'No playthroughs yet. Start a new game or link an existing save.' ?></p><?php else: ?>
            <div class="backup-list" role="region" aria-label="Available playthroughs" tabindex="0">
                <?php foreach($rows as$row): $isSelected=$selected['playthrough_id']===$row['playthrough_id']; ?>
                <article class="backup-item<?= $isSelected?' selected':'' ?>">
                    <div class="backup-info"><h3><?= lorkhan_ui_h($row['playthrough']) ?></h3><div class="backup-meta"><span><?= lorkhan_ui_h($playthroughUtc($row['created_at'])) ?> UTC</span><span>• Profile: <?= lorkhan_ui_h($row['profile']) ?></span><span>• Game: Morrowind</span><span>• Memories: <?= (int)$row['memories'] ?></span><span>• Relationships: <?= (int)$row['relationships'] ?></span><span>• Narratives: <?= (int)$row['narratives'] ?></span></div></div>
                    <?php if($isSelected): ?><span class="selected-badge" aria-current="true">Selected</span><?php else: ?><a class="button" href="<?= lorkhan_ui_h($playthroughUrl($row['playthrough_id'])) ?>">View<span class="visually-hidden"> <?= lorkhan_ui_h($row['playthrough']) ?></span></a><?php endif; ?>
                </article><?php endforeach; ?>
            </div><?php endif; ?>
        </section>
    </div>
    <section class="content-section" aria-labelledby="portable-archive-title">
        <h2 id="portable-archive-title">Portable playthrough archive</h2>
        <p class="section-note">Export this playthrough's supported data without installation-wide settings, credentials, queues or audio files. Import creates an inactive history copy; it never switches your running game or overwrites the original. Imported copies cannot currently be linked to a game save. Archives contain private conversations and profile notes; share carefully.</p>
        <p class="section-note">Hard limits: 16 MiB and 50,000 rows. Oversized exports fail instead of returning partial data. Imports must match the archive format and database schema version.</p>
        <?php if($selected): ?><a class="button" href="<?= lorkhan_ui_h($managementBasePath) ?>/exports/playthrough-archives/<?= lorkhan_ui_h($selected['playthrough_id']) ?>.json?installation_id=<?= lorkhan_ui_h($installationId) ?>">Export Playthrough Archive</a><?php endif; ?>
        <form method="post" enctype="multipart/form-data" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-archive" data-playthrough-archive>
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>">
            <label for="playthrough-archive-file">Archive JSON (maximum 16 MiB)</label><input type="file" id="playthrough-archive-file" name="archive_file" accept=".json,application/json" required>
            <div class="button-group"><button type="submit" name="operation" value="inspect"<?= $installationId===''?' disabled':'' ?>>Inspect Archive</button><button type="submit" name="operation" value="import" disabled>Import Inactive Copy</button></div>
            <pre role="status" data-archive-preview></pre>
        </form>
    </section>
    <details class="content-section playthrough-tools"><summary>Backup data policy</summary>
        <p class="section-note">Portable archive support is listed per table. Shared configuration stays installation-wide; operational queues are never resumed from an imported archive.</p>
        <div class="backup-list" role="region" aria-label="Backup table policy" tabindex="0">
            <?php foreach($tablePolicy as$policy): ?><article class="backup-item"><div class="backup-info"><h3><?= lorkhan_ui_h($policy['table']) ?></h3><div class="backup-meta"><span><?= lorkhan_ui_h($policy['category']) ?></span><span><?= !empty($policy['portable'])?'Included in portable archive':'Not included in portable archive' ?></span></div><p><?= lorkhan_ui_h($policy['description']) ?></p></div></article><?php endforeach; ?>
        </div>
    </details>
    <section class="content-section" aria-labelledby="dragon-break-title">
        <h2 id="dragon-break-title">Dragon Break protection</h2>
        <p class="section-note">Create a full database recovery snapshot before loading a save this many game days behind the last recorded state. This does not delete later gameplay history or automatically restore a backup.</p>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-backup-settings" data-backup-settings>
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>"><input type="hidden" name="expected_revision" value="<?= (int)($backupSettings['current_revision']??0) ?>">
            <label for="dragon-break-days">Rollback threshold (game days)</label><input type="number" id="dragon-break-days" name="dragon_break_days" min="1" max="365" required value="<?= (int)$dragonBreakDays ?>">
            <button type="submit"<?= $installationId===''?' disabled':'' ?>>Save Threshold</button><p role="status"></p>
        </form>
    </section>
    <details class="content-section playthrough-tools"><summary>Advanced profile record tools</summary>
    <p class="section-note">Record import and empty data-scope creation do not start a game or switch saved characters.</p>
    <details class="content-section playthrough-tools"><summary>Import profile snapshot</summary>
        <p>Import memories, relationships and narratives into the selected owning profile. Existing conversation history and knowledge are not replaced. Conflicting relationship changes are rejected.</p>
        <?php if($selected): ?>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthrough-import">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>"><input type="hidden" name="profile_id" value="<?= lorkhan_ui_h($selected['profile_id']) ?>"><input type="hidden" name="playthrough_id" value="<?= lorkhan_ui_h($selected['playthrough_id']) ?>">
            <p><b>Destination:</b> <?= lorkhan_ui_h($selected['playthrough']) ?> / <?= lorkhan_ui_h($selected['profile']) ?></p>
            <label for="playthrough-snapshot-file">Snapshot file</label><input type="file" id="playthrough-snapshot-file" accept=".json,application/json" aria-describedby="playthrough-file-status"><span id="playthrough-file-status" role="status"></span>
            <details><summary>Advanced: paste snapshot JSON</summary><label for="playthrough-snapshot-json">Snapshot JSON</label><textarea id="playthrough-snapshot-json" name="playthrough_json" rows="8"></textarea></details>
            <div class="button-group"><button type="submit">Import Snapshot</button></div>
        </form><?php else: ?><p class="playthrough-empty">Connect a saved character before importing its records.</p><?php endif; ?>
    </details>
    <details class="content-section playthrough-tools"><summary>Create empty data scope</summary>
        <?php if(($characterState['bindings']??[])!==[]): ?>
        <p>Create a playthrough by starting a new game. Load the corresponding game save to return to an existing playthrough.</p>
        <?php else: ?>
        <p>Create an empty data scope for an existing legacy profile. This does not start or load a game.</p>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/playthroughs">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="installation_id" value="<?= lorkhan_ui_h($installationId) ?>"><input type="hidden" name="content_json" value="{}">
            <label for="playthrough-profile">Profile</label><select name="profile_id" id="playthrough-profile"><?php foreach($profiles as$row): ?><option value="<?= lorkhan_ui_h($row['profile_id']) ?>"><?= lorkhan_ui_h($row['name']) ?></option><?php endforeach; ?></select>
            <label for="playthrough-name">Name</label><input name="name" id="playthrough-name" required maxlength="256" placeholder="e.g., Balmora playthrough">
            <?php if($profiles===[]): ?><p class="section-note">Create a profile in this installation first.</p><?php endif; ?>
            <div class="button-group"><button type="submit"<?= $profiles===[]?' disabled':'' ?>>Create Data Scope</button></div>
        </form>
        <?php endif; ?>
    </details>
    </details>
    <details class="content-section playthrough-tools"><summary>Full database backups and recovery</summary>
        <p class="section-note">These snapshots contain the whole database, including every character. Restoring one replaces server data and is not a way to switch characters. Game saves and external files are separate.</p>
        <?php include __DIR__.'/playthrough_database_snapshots.php'; ?>
        <h3>Backup-file retention</h3>
        <p class="section-note">Automatic cleanup from this control is off. Preview up to 100 old backups, then confirm deletion of each SQL file and its companion dump. This affects full-database backup files for all characters, never live gameplay records. Active, default and pending-restore backups are protected.</p>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/backup-file-retention" data-backup-retention>
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
            <label for="backup-retention-days">Backups older than (days)</label><input type="number" id="backup-retention-days" name="days" min="1" max="3650" value="30" required>
            <div class="button-group"><button type="submit" name="operation" value="preview">Preview Backups</button><button type="submit" name="operation" value="delete" disabled>Delete Previewed Backups</button></div>
            <pre role="status" data-retention-preview></pre>
        </form>
    </details>
</main>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/playthrough-snapshots.js?v=<?= (int)filemtime(__DIR__.'/../js/playthrough-snapshots.js') ?>" defer></script>

<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/database-maintenance.js?v=<?= (int)filemtime(__DIR__.'/../js/database-maintenance.js') ?>" defer></script>
