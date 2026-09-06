<main class="server-logs-page" data-server-logs>
    <div class="title-container"><h1>Server Logs</h1><div class="toolbar-actions">
        <a class="refresh-button" href="<?= lorkhan_ui_h($webRoot.'/ui/server_logs.php'.($embedded?'?embed=1':'')) ?>"><svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 3a5 5 0 0 0-5 5H1l3.5 3.5L8 8H6a2 2 0 1 1 2 2v2a4 4 0 1 0-4-4H2a6 6 0 1 1 6 6v-2a4 4 0 0 0 0-8z"/></svg> Refresh Logs</a>
        <button class="refresh-button" type="button" data-download-logs title="Download visible entries only; latest 200 lines per source, bounded to 256 KiB and redacted."><svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0a1 1 0 0 1 1 1v6h2.586l-2.293 2.293a1 1 0 0 1-1.414 0L5.586 7H8V1a1 1 0 0 1 1-1zM4 11h8a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2z"/></svg> Download Logs</button>
        <button class="refresh-button" type="button" data-log-timezone><svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg><span>Timezone: UTC</span></button>
    </div></div>
    <p class="title-helper"><span class="visually-hidden">Latest 200 lines per source, bounded to 256 KiB and redacted. Downloads include visible entries only.</span></p>
    <div class="file-log-grid">
        <?php foreach ($logSources as $source): ?>
        <section class="log-section" data-log-source="<?= lorkhan_ui_h($source['id']) ?>">
            <div class="section-header"><h2><?= lorkhan_ui_h($source['title']) ?></h2><button class="expand-button" type="button" data-expand-log aria-label="Expand <?= lorkhan_ui_h($source['title']) ?>" title="Expand"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg></button></div>
            <div class="source-meta">Source: <code><?= lorkhan_ui_h($source['file']) ?></code></div>
            <div class="search-container"><input class="search-input" type="search" aria-label="Search <?= lorkhan_ui_h($source['title']) ?>" placeholder="Search in <?= lorkhan_ui_h($source['title']) ?>..."></div>
            <?php if (!$source['raw']): ?><div class="log-filter-container"><div class="filter-header">Filter by Level:</div>
                <div class="filter-controls"><button class="filter-btn" type="button" data-level-action="all">All</button><button class="filter-btn" type="button" data-level-action="none">None</button></div>
                <?php foreach (['error','warn','info','debug','trace'] as $level): ?><label class="filter-checkbox"><input type="checkbox" class="level-filter" data-level="<?= $level ?>"<?= in_array($level,['error','warn'],true)?' checked':'' ?>><span class="filter-badge <?= $level ?>-badge"><?= strtoupper($level) ?> <span class="level-count"><?= count(array_filter($source['entries'],static fn(array $entry):bool=>$entry['level']===$level)) ?></span></span></label><?php endforeach; ?>
            </div><?php endif; ?>
            <div class="log-container" tabindex="0" role="region" aria-label="<?= lorkhan_ui_h($source['title']) ?> entries">
                <?php if ($source['entries']===[]): ?><div class="info-message"><?= lorkhan_ui_h($source['empty']) ?></div><?php endif; ?>
                <?php foreach ($source['entries'] as $entry): ?><div class="log-entry<?= $source['raw']?' raw-entry':($entry['level']!==''?' '.$entry['level'].'-level':($entry['timestamp']===''?' unclassified-entry':'')) ?>" data-level="<?= lorkhan_ui_h($entry['level']) ?>">
                    <?php if (!$source['raw']): ?><div class="timestamp"<?= $entry['iso']!==''?' data-utc="'.lorkhan_ui_h($entry['iso']).'"':'' ?> title="<?= $entry['iso']!==''?'Recorded timestamp':'Timezone unavailable; original timestamp retained' ?>"><?= lorkhan_ui_h($entry['timestamp']) ?></div><div class="log-level"><?= lorkhan_ui_h(strtoupper($entry['level'])) ?></div><?php endif; ?>
                    <div class="log-message<?= $source['raw']?' raw-line':'' ?>"><?= lorkhan_ui_h($entry['message']) ?></div>
                </div><?php endforeach; ?>
                <div class="info-message" data-log-no-match hidden>No entries match these filters.</div>
            </div>
        </section><?php endforeach; ?>
    </div>
    <p class="log-feedback" data-log-feedback role="status"></p>
    <dialog class="log-modal-content" aria-labelledby="log-modal-title">
        <div class="log-modal-header"><h2 class="log-modal-title" id="log-modal-title">Server Logs</h2><button class="close-modal" type="button" aria-label="Close expanded log">&times;</button></div>
        <div class="modal-search-container"><input class="modal-search-input" type="search" aria-label="Search expanded log" placeholder="Search in expanded log..."></div>
        <div class="log-modal-body"><div class="log-container" tabindex="0" role="region" aria-label="Expanded log entries"></div></div>
    </dialog>
</main>
