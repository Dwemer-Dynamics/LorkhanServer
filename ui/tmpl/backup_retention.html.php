<details class="operational-retention"><summary>Operational retention</summary>
    <p>This cleanup runs across the server. The selected day limit applies to request-deduplication records. Rate-limit buckets older than one day and expired or revoked browser sessions are also removed.</p>
    <p>Backups, NPC memories, narrative entries, voice files and game saves are retained.</p>
    <form id="operational-retention-form" method="post" action="<?= lorkhan_ui_h($managementBasePath) ?>/forms/retention">
        <input type="hidden" name="_csrf" value="<?= lorkhan_ui_h($csrf) ?>">
        <label for="retention-days">Request-deduplication retention (days)<input id="retention-days" type="number" name="days" min="1" max="3650" value="30" required></label>
        <button class="btn-base btn-danger" type="button" data-retention-open>Run Bounded Retention</button>
    </form>
    <a class="btn-base btn-secondary" href="<?= lorkhan_ui_h($webRoot) ?>/ui/database_manager.php<?= $embedded?'?embed=1':'' ?>">Configuration Backups</a>
</details>
<dialog id="operational-retention-confirm" class="request-log-dialog clear-log-dialog" aria-labelledby="retention-confirm-title">
    <div class="modal-head"><h2 id="retention-confirm-title" class="modal-title">Run Operational Retention</h2><button class="btn-base btn-secondary" type="button" data-retention-cancel autofocus>Cancel</button></div>
    <div class="modal-body"><p>Remove request-deduplication records older than <strong data-retention-days>30</strong> days across the server?</p><p>Rate-limit buckets older than one day and expired or revoked browser sessions will also be removed. Backups, NPC memories, narrative entries, voices and game saves are retained.</p><button class="btn-base btn-danger" type="button" data-retention-confirm>Confirm Retention</button></div>
</dialog>
<script src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/backup-retention.js?v=<?= filemtime(__DIR__.'/../js/backup-retention.js') ?>" defer></script>
<?php if (($_GET['status']??'')==='saved'): ?><p role="status">Operational retention completed.</p><?php endif; ?>
