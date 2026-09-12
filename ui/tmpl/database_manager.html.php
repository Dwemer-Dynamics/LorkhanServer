<main class="database-manager-page">
    <header class="page-header">
        <div class="page-header-top"><h1>Database Manager</h1><?php if (!$embedded): ?><a class="back-link" href="<?= lorkhan_ui_h($webRoot) ?>/ui/control_panel.php?tab=dbmgr">Back to Control Panel</a><?php endif; ?></div>
        <p class="page-subtitle">Manage database backups, configuration snapshots, maintenance and applied schema migrations</p>
    </header>
    <?php if (($_GET['status']??'')==='saved'): ?><p class="database-notice" role="status">Database operation completed.</p><?php endif; ?>
    <?php $maintenanceMessages=[
        'backup-queued'=>'Full SQL backup queued. Reload the backup list after the worker completes.',
        'maintenance-queued'=>'Database maintenance queued. The worker will process it; status is shown below.',
        'maintenance-completed'=>'Database maintenance completed: Lorkhan application tables compacted and analysed.',
        'maintenance-busy'=>'Maintenance is already running or was started within the last minute. Please wait before retrying.',
        'maintenance-permission'=>'Maintenance requires ownership of all Lorkhan application tables. No maintenance was started.',
        'maintenance-empty'=>'No application tables were found. No maintenance was started.',
        'maintenance-failed'=>'Maintenance did not complete within the database limits or encountered an error. Some tables may already be compacted. Your records were not deleted. See server logs before retrying.',
    ]; if(isset($maintenanceMessages[$_GET['status']??''])): ?><p class="database-notice" role="status"><?= lorkhan_ui_h($maintenanceMessages[$_GET['status']]) ?></p><?php endif; ?>
    <section class="message" id="sql-backups" aria-labelledby="sql-backup-heading">
        <h2 id="sql-backup-heading">Full Database Backups</h2>
        <p>Create a consistent SQL snapshot of this Lorkhan PostgreSQL database, including NPCs, events, history and configuration. External audio, voice files, game saves and private server credential files are not included. Keep downloaded backups private.</p>
        <p>The background job has a 10-minute limit, 1 GiB per backup and 4 GiB of SQL backup storage. No existing backup is deleted automatically.</p>
        <p role="status" data-database-maintenance data-kind="backup" data-download-base="<?= lorkhan_ui_h($managementBasePath) ?>/exports/database/" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-backup" data-state="<?= lorkhan_ui_h($sqlBackupJob['state']??'') ?>">Latest SQL backup: <?= lorkhan_ui_h($sqlBackupJob['state']??'none') ?>.</p>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-backup">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <label for="sql-backup-confirm">Type Backup to confirm</label><input id="sql-backup-confirm" name="confirm" required pattern="Backup" autocomplete="off">
            <button class="button" type="submit">Create SQL Backup</button>
        </form>
        <?php if($sqlBackups===[]): ?><p class="empty-state">No full SQL backups on this page.</p><?php else: ?>
        <div class="server-file-list">
        <?php foreach($sqlBackups as $backup): ?><div class="backup-file-row"><div class="server-file-option"><div class="server-file-card"><div class="server-file-card-header"><div class="backup-details">
            <div class="backup-filename"><?= lorkhan_ui_h($backup['backup_id']) ?>.sql</div>
            <div class="backup-badges"><span class="backup-scope-badge">LorkhanServer database</span></div>
            <div class="backup-meta"><span><?= lorkhan_ui_table_value($backup['byte_count'],'bytes') ?></span><span>Created <?= lorkhan_ui_h($backup['created_utc']) ?> UTC</span><span><?= lorkhan_ui_h($backup['state']) ?></span></div>
        </div></div></div></div><a class="backup-download" href="<?= lorkhan_ui_h($managementBasePath.'/exports/database/'.$backup['backup_id'].'.sql') ?>" aria-label="Download SQL backup <?= lorkhan_ui_h($backup['backup_id']) ?>">Download SQL</a></div><?php endforeach; ?>
        </div><?php endif; ?>
        <nav class="backup-pagination" aria-label="SQL backup pages"><?php if($sqlPage>1): ?><a class="button" href="<?= lorkhan_ui_h($sqlPageUrl($sqlPage-1)) ?>">Previous</a><?php endif; ?><?php if($sqlHasNext): ?><a class="button" href="<?= lorkhan_ui_h($sqlPageUrl($sqlPage+1)) ?>">Next</a><?php endif; ?></nav>
        <a class="button" href="<?= lorkhan_ui_h($sqlPageUrl($sqlPage)) ?>">Refresh backup list</a>
    </section>
    <div class="manager-sections">
        <section class="manager-section grid-container tools-grid" aria-label="Database tools">
            <article class="card-tile">
                <div class="card-content"><h2>🔧 Database Maintenance</h2>
                    <p>Optimize and compact this Lorkhan database with VACUUM FULL ANALYZE. No other server database is touched.</p>
                    <p>Stop the game and wait for pending server work first. Tables are locked during compaction and temporary free disk space is required. This does not delete conversation records or reset settings.</p>
                    <p>Runs in the background worker with a 30-minute database limit. Locks wait up to 3 seconds. A failed run can have completed some tables. Keep the server running until it finishes.</p>
                </div>
                <p role="status" data-database-maintenance data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-maintenance" data-state="<?= lorkhan_ui_h($maintenanceJob['state']??'') ?>"><?php if($maintenanceJob): ?>Latest maintenance: <?= lorkhan_ui_h($maintenanceJob['state']) ?>.<?php else: ?>No queued maintenance requests.<?php endif; ?></p>
                <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-maintenance">
                    <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                    <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <label for="database-maintenance-confirm">Type Maintenance to confirm</label>
                    <input id="database-maintenance-confirm" type="text" name="confirm" required pattern="Maintenance" autocomplete="off">
                    <div class="card-actions"><button class="button" type="submit">Run Database Maintenance</button></div>
                </form>
            </article>
            <article class="card-tile">
                <div class="card-content"><h2>📦 Manual Backup</h2><p><strong>Installation Configuration Backups</strong> contain Core Profiles, NPC assignments and portable settings. They exclude credentials, voices, game saves and conversation history.</p><p>Creates a stored JSON file you can download below. This is not a full database backup.</p></div>
                <?php if ($installations===[]): ?><p class="empty-state">No installation is available for a configuration backup.</p>
                <?php else: ?><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/configuration-backup">
                    <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                    <label for="backup-installation">Installation</label><select id="backup-installation" name="installation_id"><?php foreach ($installations as $row): ?><option value="<?= lorkhan_ui_h($row['installation_id']) ?>"><?= lorkhan_ui_h($row['display_name']) ?></option><?php endforeach; ?></select>
                    <label for="backup-confirm">Type Backup to confirm</label><input id="backup-confirm" type="text" name="confirm" required autocomplete="off">
                    <div class="card-actions"><button class="button backup-create" type="submit">Create Backup</button></div>
                </form><?php endif; ?>
            </article>
            <article class="card-tile">
                <div class="card-content"><h2>🗄️ Database State</h2><p>Inspect the migration history and stored backup records for this Lorkhan server.</p>
                    <div class="stats-grid"><div class="stat-tile"><h3>Schema</h3><p class="stat-value"><?= lorkhan_ui_h($migrations[0]['version']??'Unknown') ?></p></div><div class="stat-tile"><h3>Applied Updates</h3><p class="stat-value"><?= count($migrations) ?></p></div><div class="stat-tile"><h3>Configuration Backups</h3><p class="stat-value"><?= $state['total'] ?></p></div></div>
                    <p>Applied updates are read-only here. Backup Health contains stored backup identities and the existing retention control.</p>
                </div><div class="card-actions"><a class="button" href="#database-versions">View Applied Updates</a><a class="button" href="<?= lorkhan_ui_h($webRoot) ?>/ui/backup_health.php<?= $embedded?'?embed=1':'' ?>">Backup Health</a></div>
            </article>
        </section>
        <section class="manager-section message" aria-labelledby="restore-heading">
            <h2 id="restore-heading">💾 Restore Manual Backup</h2>
            <p>Choose a stored configuration backup. Downloads contain the original, secret-free JSON document.</p>
            <details class="instruction-box"><summary>What restoring changes</summary><p>Restoring imports the saved configuration, Core Profiles and NPC assignments into the destination installation. It can replace existing settings. Credentials, voice files, conversation history and game saves are not restored.</p><p>Use the stored backup ID; no browser-supplied filesystem path is accepted.</p></details>
            <?php if ($configurationBackups===[]): ?><div class="empty-state"><div class="empty-state-icon" aria-hidden="true">📁</div><p>No configuration backups are available.</p><small>Create a manual configuration backup above to download or restore it here.</small></div>
            <?php else: ?>
                <p class="backup-count">Showing <?= count($configurationBackups) ?> of <?= $state['total'] ?> configuration backups. Page <?= $state['page'] ?> / <?= $state['pages'] ?>.</p>
                <form id="restore-backup" method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/configuration-restore">
                    <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                    <fieldset class="server-file-list"><legend>Stored configuration backups</legend>
                        <?php foreach ($configurationBackups as $index=>$row): ?>
                        <div class="backup-choice"><label class="server-file-option">
                            <input type="radio" name="backup_id" value="<?= lorkhan_ui_h($row['backup_id']) ?>"<?= $index===0?' checked':'' ?> required>
                            <span class="server-file-card"><span class="server-file-card-header"><span class="backup-details">
                                <span class="backup-filename"><?= lorkhan_ui_h($row['backup_id']) ?>.json</span>
                                <span class="backup-badges"><span class="backup-scope-badge">Configuration · <?= lorkhan_ui_h($state['installations'][$row['installation_id']]??'Unknown installation') ?></span></span>
                                <span class="backup-meta"><span><?= lorkhan_ui_h($row['state']) ?> · <?= lorkhan_ui_table_value($row['byte_count'],'bytes') ?> · format <?= (int)$row['format_version'] ?></span><span>Created <?= lorkhan_ui_h($row['created_utc']) ?> UTC</span></span>
                                <?php if ($row['restored_utc']!==null): ?><span class="server-file-notes">Restored <?= lorkhan_ui_h($row['restored_utc']) ?> UTC</span><?php endif; ?>
                            </span><span class="server-file-radio-indicator" aria-hidden="true"></span></span></span>
                        </label><a class="backup-download" href="<?= lorkhan_ui_h($managementBasePath.'/exports/backups/'.$row['backup_id'].'.json') ?>" aria-label="Download backup <?= lorkhan_ui_h($row['backup_id']) ?>">Download backup</a></div>
                        <?php endforeach; ?>
                    </fieldset>
                    <div class="restore-fields"><div><label for="restore-installation">Destination installation</label><select id="restore-installation" name="installation_id" required><?php foreach ($installations as $row): ?><option value="<?= lorkhan_ui_h($row['installation_id']) ?>"><?= lorkhan_ui_h($row['display_name']) ?></option><?php endforeach; ?></select></div><div><label for="restore-confirm">Type Restore to confirm</label><input id="restore-confirm" type="text" name="confirm" required autocomplete="off"></div></div>
                    <button class="button backup-restore" type="submit"<?= $installations===[]?' disabled':'' ?>>Restore Backup</button>
                </form>
                <nav class="backup-pagination" aria-label="Backup pages"><?php if ($state['page']>1): ?><a class="button" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>1])) ?>#restore-heading">First</a><a class="button" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']-1])) ?>#restore-heading">Previous</a><?php endif; ?><?php if ($state['page']<$state['pages']): ?><a class="button" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['page']+1])) ?>#restore-heading">Next</a><a class="button" href="<?= lorkhan_ui_h(lorkhan_control_url($state,['page'=>$state['pages']])) ?>#restore-heading">Last</a><?php endif; ?></nav>
            <?php endif; ?>
        </section>
    </div>
    <div class="section-divider"></div>
    <section id="database-versions" class="message versioning-manager" aria-labelledby="version-heading"><h2 id="version-heading">Database Versioning Manager</h2><p>Applied schema migrations, newest first. Updates are installed through deployment, not reset from this page.</p>
        <?php if ($migrations===[]): ?><p class="empty-state">No schema migrations are recorded.</p><?php else: ?><div class="version-table-container" tabindex="0" role="region" aria-label="Applied schema migrations"><table class="version-table"><thead><tr><th scope="col">Version</th><th scope="col">Update</th><th scope="col">Checksum</th><th scope="col">Applied (UTC)</th></tr></thead><tbody><?php foreach ($migrations as $row): ?><tr><td><?= lorkhan_ui_h($row['version']) ?></td><td><?= lorkhan_ui_h($row['name']) ?></td><td class="checksum"><?= lorkhan_ui_h($row['checksum']) ?></td><td><?= lorkhan_ui_h($row['applied_utc']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
</main>
