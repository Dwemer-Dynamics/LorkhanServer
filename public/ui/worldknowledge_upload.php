<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Oghma Infinium';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page oghma-page-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';
$rows = $uiRepository->rows('worldknowledge');
$installations = $uiRepository->rows('installations');
$profiles = array_merge($uiRepository->rows('characters'), $uiRepository->rows('player'));
$playthroughs = $uiRepository->rows('playthroughs');
$additionalStylesheets = ['herika-oghma.css?v=' . (string) filemtime(__DIR__ . '/css/herika-oghma.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="oghma-page">
    <header class="page-header">
        <h1 id="page-title"><span class="oghma-title-icon" aria-hidden="true">&#x1F4D9;</span><span>Oghma Infinium</span></h1>
        <div class="header-content">
            <p>The <strong>Oghma Infinium</strong> is a Morrowind encyclopedia that AI NPCs use to help them roleplay.</p>
            <p>Knowledge remains scoped to an installation, profile, and playthrough, then retrieved through the audited ALMSIVI context pipeline.</p>
            <h3>Use concise titles and bounded knowledge entries with clear provenance.</h3>
            <div class="logic-section">
                <h3 class="logic-title">&#x1F50D; Article Search Logic</h3>
                <div class="logic-steps">
                    <?php foreach ([['1','Scoped Search','ALMSIVI searches only the active installation, profile, and playthrough.'],['2','Relevant Results','The configured knowledge limit bounds the most relevant records.'],['3','Prompt Injection','Selected knowledge is injected into the audited prompt context.'],['4','No Fabrication','When no record matches, the model receives no invented encyclopedia article.']] as [$number,$title,$text]): ?>
                    <div class="logic-step"><span class="step-number"><?php echo $number; ?></span><span class="step-content"><strong><?php echo almsivi_ui_h($title); ?></strong><span><?php echo almsivi_ui_h($text); ?></span></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </header>

    <nav class="tab-navigation" aria-label="Oghma pages"><button type="button" class="tab-button active">&#x1F4DA; Oghma Infinium</button><span class="status-control"><button type="button" class="tab-button" disabled aria-disabled="true">&#x26A1; Dynamic Oghma</button><?php echo almsivi_ui_feature_badge('config.oghma.dynamic', true); ?></span></nav>

    <?php if (isset($_GET['status'])): ?><div class="oghma-notice">Knowledge record saved.</div><?php endif; ?>
    <div class="content-grid">
        <section class="content-section">
            <h2>Batch Upload</h2>
            <label>Select .csv file to upload:<input type="file" accept=".csv" disabled aria-disabled="true"></label>
            <div class="button-group"><span class="status-control"><button type="button" class="action-button upload-csv" disabled aria-disabled="true">Upload CSV</button><?php echo almsivi_ui_feature_badge('config.oghma.batch', true); ?></span><span class="status-control"><button type="button" class="action-button download-csv" disabled aria-disabled="true">Download Example CSV</button><?php echo almsivi_ui_feature_badge('config.oghma.batch', true); ?></span></div>
            <p>Batch import stays disabled until a typed, scoped CSV format can preserve installation and playthrough ownership.</p>
        </section>
        <section class="content-section">
            <h2>Database Management</h2>
            <p>Verify retrievals through <strong>Control Panel &rarr; Oghma Audit</strong>.</p><p>Backups and retention remain in <strong>Control Panel &rarr; Database Manager</strong>.</p>
            <div class="button-group"><span class="status-control"><button type="button" class="btn-danger" disabled aria-disabled="true">Delete All Entries</button><?php echo almsivi_ui_feature_badge('config.oghma.destructive', true); ?></span><span class="status-control"><button type="button" class="btn-danger" disabled aria-disabled="true">Factory Reset Database</button><?php echo almsivi_ui_feature_badge('config.oghma.destructive', true); ?></span></div>
        </section>
    </div>

    <section class="full-width-section">
        <h2 id="entries">&#x1F4CB; Oghma Infinium Entries</h2>
        <div class="action-container"><button type="button" class="action-button add-new" data-oghma-create>Add New Entry</button><div class="search-container"><input type="search" placeholder="Search topics..." data-oghma-search><button type="button" class="action-button edit" data-oghma-search-button>Search</button></div></div>
        <div class="filter-section"><strong>Filter by Category:</strong><div class="filter-buttons"><button type="button" class="alphabet-button active">All Categories</button></div><strong>Sort Order:</strong><div><button type="button" class="alphabet-button">&#x1F53C; Ascending</button><button type="button" class="alphabet-button">&#x1F53D; Descending</button></div></div>

        <section class="new-entry-panel" data-oghma-create-panel hidden>
            <h2>Add New Entry</h2>
            <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <div class="entry-form-grid"><label>Installation<select name="installation_id"><?php foreach ($installations as $row): ?><option value="<?php echo almsivi_ui_h($row['installation_id']); ?>"><?php echo almsivi_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label><label>Profile<select name="profile_id"><?php foreach ($profiles as $row): ?><option value="<?php echo almsivi_ui_h($row['profile_id']); ?>"><?php echo almsivi_ui_h($row['name']); ?></option><?php endforeach; ?></select></label><label>Playthrough<select name="playthrough_id"><?php foreach ($playthroughs as $row): ?><option value="<?php echo almsivi_ui_h($row['playthrough_id']); ?>"><?php echo almsivi_ui_h($row['playthrough']); ?></option><?php endforeach; ?></select></label><label>Title<input name="title" required maxlength="256"></label><label class="wide">Knowledge<textarea name="content" required></textarea></label><label>Provenance<input name="provenance" required value="management"></label></div>
                <div class="button-group"><button type="submit" class="action-button add-new">Add Knowledge</button><button type="button" class="action-button" data-oghma-create-cancel>Cancel</button></div>
            </form>
        </section>

        <div class="oghma-entry-grid" data-oghma-entry-grid>
            <?php foreach ($rows as $row): ?><article class="oghma-entry" data-oghma-entry data-search="<?php echo almsivi_ui_h(strtolower((string) (($row['title'] ?? '') . ' ' . ($row['content'] ?? '')))); ?>"><h3><?php echo almsivi_ui_h($row['title'] ?? 'Knowledge entry'); ?></h3><p><?php echo nl2br(almsivi_ui_h($row['content'] ?? '')); ?></p><small><?php echo almsivi_ui_h($row['provenance'] ?? ''); ?></small></article><?php endforeach; ?>
            <?php if ($rows === []): ?><p class="oghma-empty">No world knowledge exists yet.</p><?php endif; ?>
        </div>
    </section>
</main>
<script defer src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/oghma.js?v=<?php echo almsivi_ui_h((string) filemtime(__DIR__ . '/js/oghma.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
