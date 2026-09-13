<main class="database-manager-page">
    <header class="page-header">
        <div class="page-header-top"><h1>Database Manager</h1><?php if (!$embedded): ?><a class="back-link" href="<?= lorkhan_ui_h($webRoot) ?>/ui/control_panel.php?tab=dbmgr">Back to Control Panel</a><?php endif; ?></div>
        <p class="page-subtitle">Manage database backups, configuration snapshots, maintenance and applied schema migrations</p>
    </header>
    <?php if (($_GET['status']??'')==='saved'): ?><p class="database-notice" role="status">Database operation completed.</p><?php endif; ?>
    <?php $maintenanceMessages=[
        'factory-queued'=>'Factory reset queued. Keep the game closed. A rollback backup is created before any data is replaced.',
        'factory-unavailable'=>'The factory artifact could not be verified or changed since this page opened. Refresh or redeploy before confirming again.',
        'replay-queued'=>'Migration replay queued. Keep the game closed. A rollback backup is created before any migration changes.',
        'replay-plan-changed'=>'Migration sources changed since this page was opened. Review the refreshed versions before confirming again.',
        'backup-deleted'=>'Automatic backup deleted, including its private restore archive.',
        'backup-restore-pending'=>'This backup is queued for restoration and cannot be deleted yet.',
        'backup-delete-failed'=>'Backup deletion did not finish. A file may already have been removed; retry to finish deleting this backup.',
        'import-queued'=>'SQL import queued. Keep the game closed. The worker validates the isolated data and creates a rollback backup before replacing database rows.',
        'import-conflict'=>'This confirmation was already used for another file. Refresh the page and review the file before confirming again.',
        'restore-queued'=>'SQL restore queued. Keep the game closed. The worker will first create a rollback backup, then restore in one transaction.',
        'backup-settings-saved'=>'Automatic backup settings saved. Older automatic backups are removed only after the next successful automatic backup.',
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
        <p>The background job has a 10-minute limit, 1 GiB per SQL file and 4 GiB of backup storage, including private compressed restore archives. Manual backups are never deleted automatically.</p>
        <p role="status" data-database-maintenance data-kind="backup" data-download-base="<?= lorkhan_ui_h($managementBasePath) ?>/exports/database/" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-backup" data-state="<?= lorkhan_ui_h($sqlBackupJob['state']??'') ?>">Latest SQL backup: <?= lorkhan_ui_h($sqlBackupJob['state']??'none') ?>.</p>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-backup">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <label for="sql-backup-confirm">Type Backup to confirm</label><input id="sql-backup-confirm" name="confirm" required pattern="Backup" autocomplete="off">
            <button class="button" type="submit">Create SQL Backup</button>
        </form>
        <?php if($sqlBackups===[]): ?><p class="empty-state">No full SQL backups on this page.</p><?php else: ?>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-restore">
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <p>Choose a stored SQL backup to restore. This replaces database history and settings. A separate rollback SQL backup is created first; browser login, pairing, backup records and automatic-backup preferences are preserved. Current schema and installation IDs must match. Administrator-owned PostgreSQL extensions are preserved. Older SQL-only backups remain downloadable; native restore requires the archive included with new backups. Old sessions and queued jobs are stopped; reconnect the game after completion. External media and credentials are not restored.</p>
        <div class="server-file-list">
        <?php foreach($sqlBackups as $backup): ?><div class="backup-file-row"><label class="server-file-option"><input type="radio" name="backup_id" value="<?= lorkhan_ui_h($backup['backup_id']) ?>" required<?= (int)$backup['format_version']<2?' disabled':'' ?> aria-label="Restore SQL backup <?= lorkhan_ui_h($backup['backup_id']) ?>"><span class="server-file-card"><span class="server-file-card-header"><span class="backup-details">
            <div class="backup-filename"><?= lorkhan_ui_h($backup['backup_id']) ?>.sql</div>
            <div class="backup-badges"><span class="backup-scope-badge">LorkhanServer database · <?= !empty($backup['rollback_for'])?'Before restore':(($backup['automatic']??'')==='true'?'Automatic':'Manual') ?></span></div>
            <div class="backup-meta"><span><?= lorkhan_ui_table_value($backup['byte_count'],'bytes') ?></span><span>Created <?= lorkhan_ui_h($backup['created_utc']) ?> UTC</span><span><?= lorkhan_ui_h($backup['state']) ?></span></div>
        </span><span class="server-file-radio-indicator" aria-hidden="true"></span></span></span></label><a class="backup-download" href="<?= lorkhan_ui_h($managementBasePath.'/exports/database/'.$backup['backup_id'].'.sql') ?>" aria-label="Download SQL backup <?= lorkhan_ui_h($backup['backup_id']) ?>">Download SQL</a>
        <?php if(($backup['automatic']??'')==='true'): ?><button type="submit" form="delete-backup-<?= lorkhan_ui_h($backup['backup_id']) ?>" class="button backup-delete btn-danger" title="Delete this automatic backup" aria-label="Delete automatic backup <?= lorkhan_ui_h($backup['backup_id']) ?>">🗑️</button><?php endif; ?></div><?php endforeach; ?>
        </div>
        <label for="sql-restore-confirm">Type Restore SQL to confirm replacement</label><input id="sql-restore-confirm" type="text" name="confirm" required pattern="Restore SQL" autocomplete="off">
        <button class="button backup-restore" type="submit"<?= !$sqlCanRestore?' disabled':'' ?>>Restore SQL Backup</button>
        </form><?php endif; ?>
        <?php foreach($sqlBackups as $backup): if(($backup['automatic']??'')!=='true')continue; ?>
        <form id="delete-backup-<?= lorkhan_ui_h($backup['backup_id']) ?>" method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-backup-delete" data-backup-delete data-backup-name="<?= lorkhan_ui_h($backup['backup_id']) ?>.sql">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="backup_id" value="<?= lorkhan_ui_h($backup['backup_id']) ?>"><input type="hidden" name="confirm" value="Delete">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        </form><?php endforeach; ?>
        <p role="status" data-database-maintenance data-kind="restore" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-restore" data-state="<?= lorkhan_ui_h($sqlRestoreJob['state']??'') ?>">Latest SQL restore: <?= lorkhan_ui_h($sqlRestoreJob['state']??'none') ?>.</p>
        <details class="instruction-box" id="sql-import">
            <summary>Import an SQL backup file</summary>
            <p>Import a plain .sql backup without its private restore archive. Database history and settings will be replaced. The schema version and installation IDs must match this server; older releases are rejected. A rollback backup is created first. Login, pairing and backup records are preserved; external media and credentials are not imported.</p>
            <form method="post" enctype="multipart/form-data" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-import">
                <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                <input type="hidden" name="request_id" value="<?= lorkhan_ui_h(\LorkhanServer\Infrastructure\Uuid::v4()) ?>">
                <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <div class="restore-fields"><div><label for="sql-import-file">SQL backup file (up to 1 GiB)</label><input id="sql-import-file" name="sql_file" type="file" accept=".sql" required></div>
                <div><label for="sql-import-confirm">Type Import SQL to confirm replacement</label><input id="sql-import-confirm" name="confirm" type="text" pattern="Import SQL" autocomplete="off" required></div></div>
                <button class="button backup-restore" type="submit">Import SQL</button>
            </form>
            <h3 class="server-import-heading">Import from Server</h3>
            <p>Place a plain SQL backup in <code><?= lorkhan_ui_h($serverImportDirectory) ?></code>, refresh this page, then select it below. The same validation, replacement and rollback rules apply. The original file is kept.</p>
            <?php if($serverImportError): ?><p role="alert">The import folder could not be listed. Check its permissions and keep at most 1,000 files in it.</p>
            <?php elseif($serverImportFiles===[]): ?><p>No SQL files found in the server import folder.</p>
            <?php else: ?>
            <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-import">
                <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                <input type="hidden" name="request_id" value="<?= lorkhan_ui_h(\LorkhanServer\Infrastructure\Uuid::v4()) ?>">
                <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <div class="restore-fields"><div><label for="sql-server-file">Available SQL files on server:</label><select id="sql-server-file" name="server_file" required>
                    <?php foreach($serverImportFiles as$file): ?><option value="<?= lorkhan_ui_h($file['name']) ?>"><?= lorkhan_ui_h($file['name']) ?> (<?= number_format($file['bytes']) ?> bytes)</option><?php endforeach; ?>
                </select></div><div><label for="sql-server-confirm">Type Import SQL to confirm replacement</label><input id="sql-server-confirm" name="confirm" type="text" pattern="Import SQL" autocomplete="off" required></div></div>
                <button class="button backup-restore" type="submit">Import from Server</button>
            </form>
            <?php endif; ?>
            <p role="status" data-database-maintenance data-kind="import" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-import" data-state="<?= lorkhan_ui_h($sqlImportJob['state']??'') ?>">Latest SQL import: <?= lorkhan_ui_h($sqlImportJob['state']??'none') ?>.</p>
        </details>
        <nav class="backup-pagination" aria-label="SQL backup pages"><?php if($sqlPage>1): ?><a class="button" href="<?= lorkhan_ui_h($sqlPageUrl($sqlPage-1)) ?>">Previous</a><?php endif; ?><?php if($sqlHasNext): ?><a class="button" href="<?= lorkhan_ui_h($sqlPageUrl($sqlPage+1)) ?>">Next</a><?php endif; ?></nav>
        <a class="button" href="<?= lorkhan_ui_h($sqlPageUrl($sqlPage)) ?>">Refresh backup list</a>
    </section>
    <div class="manager-sections">
        <section class="message" id="automatic-backups" aria-labelledby="automatic-backup-heading">
            <h2 id="automatic-backup-heading">🤖 Automatic Backup System</h2>
            <p>Checks when Home is opened, with a 10-minute cooldown. SQL snapshots run in the background worker. Keeps the newest automatic backups up to the selected limit, removing older automatic copies only after a successful replacement.</p>
            <div class="stats-grid">
                <div class="stat-tile"><h3>Status</h3><form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-backup-settings">
                <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="enabled" value="<?= $automaticBackupSettings['enabled']?'0':'1' ?>">
                <button type="submit" class="button automatic-backup-toggle <?= $automaticBackupSettings['enabled']?'is-enabled':'is-disabled' ?>" aria-label="<?= $automaticBackupSettings['enabled']?'Disable':'Enable' ?> automatic backups"><?= $automaticBackupSettings['enabled']?'✅ On':'❌ Off' ?></button>
                </form></div>
                    <div class="stat-tile"><h3><label for="automatic-backup-max">Available</label></h3>
                        <form method="post" class="automatic-backup-count" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-backup-settings">
                        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
                        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                        <span><?= (int)$automaticBackupStats['count'] ?> / </span><select id="automatic-backup-max" name="max_count" data-backup-auto-submit><?php for($i=1;$i<=10;$i++): ?><option value="<?= $i ?>"<?= $i===$automaticBackupSettings['max_count']?' selected':'' ?>><?= $i ?></option><?php endfor; ?></select>
                        <noscript><button class="button" type="submit">Save</button></noscript></form>
                    </div>
                    <div class="stat-tile"><h3>Total Size</h3><p class="stat-value"><?= lorkhan_ui_table_value($automaticBackupStats['bytes'],'bytes') ?></p></div>
                </div>
            <p>Backups appear in Full Database Backups above. If backup storage is full, older copies remain intact and the new job fails. Disabling stops new automatic backups and prevents queued automatic work from starting.</p>
        </section>
        <section class="manager-section grid-container tools-grid" aria-label="Database tools">
            <article class="card-tile factory-reset-card">
                <div class="card-content"><h2>💥 Factory Reset Database</h2>
                    <p>Wipe and reinstall the Lorkhan database to its default configuration.</p>
                    <p><strong>⚠️ DANGER:</strong> Replaces NPC profiles, settings, events, diaries, memories and custom knowledge. Login, pairing and stored backups are retained. External credentials, voices and game saves are unchanged.</p>
                    <p role="status" data-database-maintenance data-kind="factory" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-factory-reset">Latest factory reset: <?= lorkhan_ui_h($factoryJob['state']??'none') ?>.</p>
                    <?php if($factoryPlan===null): ?><p class="empty-state">A verified factory artifact is unavailable. Redeploy the server before resetting.</p><?php endif; ?>
                </div><div class="card-actions"><button type="button" class="button version-reset-all" data-factory-open<?= $factoryPlan===null?' disabled':'' ?>>Factory Reset LorkhanServer</button></div>
            </article>
            <article class="card-tile" data-database-access>
                <div class="card-content"><h2>🗄️ Database Access</h2><p>Access the pgAdmin database manager for advanced database management.</p><p>Sign in with your database administrator account. Server credentials are not shown here.</p>
                <?php if($databaseAdminUrl===''): ?><p class="database-notice">Database Access is not configured. Set a valid <code>database_admin_url</code> in the private server configuration.</p><?php endif; ?></div>
                <div class="card-actions"><?php if($databaseAdminUrl!==''): ?><a class="button database-admin-link" href="<?= lorkhan_ui_h($databaseAdminUrl) ?>" target="_blank" rel="noopener noreferrer">Open Database Manager</a><?php else: ?><button type="button" class="button" disabled>Open Database Manager</button><?php endif; ?></div>
            </article>
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
                    <p>Review or reset applied updates below. Backup Health contains stored backup identities and the existing retention control.</p>
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
    <section id="database-versions" class="message versioning-manager" aria-labelledby="version-heading">
        <h2 id="version-heading">Database Versioning Manager</h2>
        <p>Reset reruns the selected update and all later updates in one transaction. This can remove data or restore factory values. A verified rollback backup is required; migration safety checks may refuse the operation.</p>
        <div class="version-toolbar"><h3>Lorkhan Version Entries (<?= count($migrations) ?> total)</h3>
        <?php if($replayPlan!==null&&$replayPlan['versions']!==[]): ?><button type="button" class="button version-reset-all" data-replay-version="<?= (int)$replayPlan['versions'][0]['version'] ?>" data-replay-count="<?= count($replayPlan['versions']) ?>">Reset All Versions</button><?php endif; ?></div>
        <?php if($replayPlan===null): ?><p class="database-notice" role="status">Migration sources and the applied ledger could not be verified. Reset is unavailable; check the deployment before continuing.</p><?php endif; ?>
        <p role="status" data-database-maintenance data-kind="replay" data-endpoint="<?= lorkhan_ui_h($managementBasePath) ?>/api/v1/database-replay">Latest migration replay: <?= lorkhan_ui_h($replayJob['state']??'none') ?>.</p>
        <?php if ($migrations===[]): ?><p class="empty-state">No schema migrations are recorded.</p><?php else: ?><div class="version-table-container" tabindex="0" role="region" aria-label="Applied schema migrations"><table class="version-table"><thead><tr><th scope="col">Table/Feature Name</th><th scope="col">Version</th><th scope="col">Action</th></tr></thead><tbody>
        <?php foreach ($migrations as $index=>$row): ?><tr><td><span class="version-name"><?= lorkhan_ui_h($row['name']) ?></span><details class="version-details"><summary>Migration details</summary><span>Applied <?= lorkhan_ui_h($row['applied_utc']) ?> UTC</span><span class="checksum"><?= lorkhan_ui_h($row['checksum']) ?></span></details></td><td><?= lorkhan_ui_h($row['version']) ?></td><td><?php if($replayPlan!==null): ?><button type="button" class="button version-reset" data-replay-version="<?= (int)$row['version'] ?>" data-replay-count="<?= $index+1 ?>" aria-label="Reset version <?= (int)$row['version'] ?>">Reset</button><?php else: ?><span>Unavailable</span><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
        <?php if($replayPlan!==null): ?><dialog class="replay-dialog" data-replay-dialog aria-labelledby="replay-title">
            <h2 id="replay-title">Reset Database Versions</h2>
            <p data-replay-description></p><p>Keep the game closed. The server first creates a private rollback backup, then reverses and reapplies these migrations. This may delete affected history or settings. Login and pairing are preserved; reconnect the game afterward. A failed migration rolls back the database changes.</p>
            <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-replay">
                <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="fingerprint" value="<?= lorkhan_ui_h($replayPlan['fingerprint']) ?>"><input type="hidden" name="version" value="">
                <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <label for="replay-confirm" data-replay-label>Type the confirmation below</label><input id="replay-confirm" name="confirm" autocomplete="off" required>
                <div class="card-actions"><button class="button" type="button" data-replay-cancel>Cancel</button><button class="button version-reset-all" type="submit">Back Up and Reset</button></div>
            </form>
        </dialog><?php endif; ?>
    </section>
    <?php if($factoryPlan!==null): ?><dialog class="replay-dialog" data-factory-dialog aria-labelledby="factory-title">
        <h2 id="factory-title">Factory Reset Database</h2>
        <p>Keep the game closed. This replaces all application data with the factory configuration. A private rollback backup is required and will appear in the backup list. Login, pairing and backup records are preserved.</p>
        <form method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/database-factory-reset">
            <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>"><input type="hidden" name="fingerprint" value="<?= lorkhan_ui_h($factoryPlan['fingerprint']) ?>">
            <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
            <label for="factory-confirm">Type Factory Reset to confirm</label><input id="factory-confirm" name="confirm" pattern="Factory Reset" autocomplete="off" required>
            <div class="card-actions"><button type="button" class="button" data-factory-cancel>Cancel</button><button class="button version-reset-all" type="submit">Back Up and Factory Reset</button></div>
        </form>
    </dialog><?php endif; ?>
</main>
