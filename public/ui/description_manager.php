<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Descriptions';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page descriptions-page-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installationRows = $uiRepository->rows('installations');
$installations = [];
foreach ($installationRows as $installation) {
    $id = (string) ($installation['installation_id'] ?? '');
    if ($id !== '') $installations[$id] = (string) ($installation['display_name'] ?? $id);
}
$installationId = (string) ($_GET['installation_id'] ?? ($installationRows[0]['installation_id'] ?? '') ?? '');
if (!isset($installations[$installationId])) $installationId = (string) (array_key_first($installations) ?? '');
$filters = [
    'search' => (string) ($_GET['search'] ?? ''),
    'letter' => (string) ($_GET['letter'] ?? ''),
    'source' => (string) ($_GET['source'] ?? 'all'),
    'plugin' => (string) ($_GET['plugin'] ?? 'all'),
    'page' => (string) ($_GET['page'] ?? '1'),
];
$catalog = $installationId === '' ? ['items'=>[],'total'=>0,'page'=>1,'pages'=>1,'page_size'=>50,'filters'=>$filters]
    : $uiRepository->descriptionCatalog($installationId, $filters);
$summary = $installationId === '' ? ['default_count'=>0,'custom_count'=>0,'missing_count'=>0]
    : $uiRepository->descriptionSummary($installationId);
$discoveredItems = $installationId === '' ? [] : $uiRepository->discoveredDescriptionItems($installationId);
$filters = $catalog['filters'];

/** Build a Description Manager link while preserving the selected installation and filters. */
function lorkhan_description_url(array $changes = []): string
{
    global $webRoot, $embedded, $installationId, $filters;
    $query = array_merge(['installation_id'=>$installationId], $filters, $changes);
    if ($embedded) $query['embed'] = '1';
    foreach ($query as $key => $value) if ($value === '' || $value === null || ($key === 'page' && (int) $value === 1)) unset($query[$key]);
    return $webRoot . '/ui/description_manager.php?' . http_build_query($query);
}

$status = (string) ($_GET['status'] ?? '');
$statusText = match ($status) {
    'saved' => 'Changes saved.',
    'imported' => (int) ($_GET['count'] ?? 0) . ' custom descriptions imported.',
    default => '',
};
$additionalStylesheets = ['herika-descriptions.css?v=' . (string) filemtime(__DIR__ . '/css/herika-descriptions.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="descriptions-page">
    <header class="page-header lorkhan-page-head"><h1>Description Manager</h1><p class="page-subtitle lorkhan-page-head-note">Create custom descriptions for Morrowind items and equipment that enhance NPC context</p></header>
    <?php if ($statusText !== ''): ?><div class="description-notice" role="status"><?php echo lorkhan_ui_h($statusText); ?></div><?php endif; ?>
    <?php if ($installations === []): ?><div class="description-notice warning">Connect OpenMW once before managing installation-scoped descriptions.</div><?php else: ?>
    <form class="installation-selector" method="get" action="<?php echo lorkhan_ui_h($webRoot . '/ui/description_manager.php'); ?>">
        <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>Installation<select name="installation_id" data-description-installation><?php foreach ($installations as $id => $label): ?><option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo $id === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($label); ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="action-button">Switch</button>
    </form>
    <div class="description-summary" aria-label="Description catalog summary">
        <article><strong><?php echo (int) $summary['default_count']; ?></strong><span>Defaults</span></article>
        <article><strong><?php echo (int) $summary['custom_count']; ?></strong><span>Custom</span></article>
        <article><strong><?php echo (int) $summary['missing_count']; ?></strong><span>Missing</span></article>
    </div>
    <div class="content-grid">
        <section class="content-section">
            <h2>Batch Upload</h2>
            <form action="<?php echo lorkhan_ui_h($managementBasePath . '/forms/description-import'); ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <label>Select .csv file to upload:<input type="file" name="csv_file" accept=".csv,text/csv" required></label>
                <div class="button-group"><button type="submit" class="action-button upload-csv">Upload CSV</button><a class="action-button download-csv" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/descriptions/example.csv'); ?>">Download Example CSV</a><a class="action-button export-csv" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/descriptions/custom.csv?' . http_build_query(['installation_id'=>$installationId])); ?>">Export Custom Descriptions</a></div>
            </form>
            <p>CSV format: plugin, baseid, name, description. Uploads create installation-specific custom overrides.</p>
        </section>
        <section class="content-section">
            <h2>Database Management</h2><p>Factory defaults remain read-only. Custom descriptions override matching defaults for this installation.</p><p>Deleting or resetting an override reveals its factory default again.</p>
            <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath . '/forms/description-reset'); ?>" data-confirm="Delete every custom description for this installation?">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="confirm" value="Reset"><button type="submit" class="btn-danger">Factory Reset Item Override Table</button>
            </form>
        </section>
    </div>
    <section class="full-width-section discovered-section">
        <h2>&#x1F50E; Discovered OpenMW Items</h2><p>Items are learned from bounded inventory, equipment, and nearby-object context using content file and record ID.</p>
        <div class="table-container"><table><thead><tr><th>Base ID</th><th>Plugin</th><th>Name</th><th>Seen In</th><th>Status</th></tr></thead><tbody><?php foreach ($discoveredItems as $item): ?><tr><td><?php echo lorkhan_ui_h($item['record_id']); ?></td><td><?php echo lorkhan_ui_h($item['content_file']); ?></td><td><?php echo lorkhan_ui_h($item['display_name'] ?? ''); ?></td><td><?php $sources=$item['observed_sources']??[];if(is_string($sources))$sources=json_decode($sources,true);echo lorkhan_ui_h(is_array($sources)?implode(', ',$sources):''); ?></td><td><span class="source-badge source-<?php echo lorkhan_ui_h(strtolower((string)$item['description_source'])); ?>"><?php echo lorkhan_ui_h($item['description_source']); ?></span><?php if (!filter_var($item['active'] ?? false,FILTER_VALIDATE_BOOL)): ?> <span class="source-badge inactive">Inactive Plugin</span><?php endif; ?></td></tr><?php endforeach; ?><?php if ($discoveredItems === []): ?><tr><td colspan="5">No items have been discovered in game yet.</td></tr><?php endif; ?></tbody></table></div>
    </section>
    <section class="full-width-section">
        <h2 id="entries">&#x1F4CB; Descriptions Database</h2>
        <div class="action-container"><button type="button" class="action-button add-new" data-description-create>Add New Entry</button>
            <form class="search-container" method="get" action="<?php echo lorkhan_ui_h($webRoot . '/ui/description_manager.php'); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?><input type="hidden" name="letter" value="<?php echo lorkhan_ui_h($filters['letter']); ?>"><input type="hidden" name="source" value="<?php echo lorkhan_ui_h($filters['source']); ?>"><input type="hidden" name="plugin" value="<?php echo lorkhan_ui_h($filters['plugin']); ?>"><input type="search" name="search" value="<?php echo lorkhan_ui_h($filters['search']); ?>" placeholder="Search descriptions..." aria-label="Search descriptions"><button type="submit" class="action-button edit">Search</button></form>
        </div>
        <div class="catalog-filters"><label>Source<select data-description-filter data-filter-name="source"><option value="all">All Sources</option><?php foreach (['default'=>'Default','custom'=>'Custom','missing'=>'Missing'] as $value=>$label): ?><option value="<?php echo $value; ?>"<?php echo $filters['source']===$value?' selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label><label>Plugin state<select data-description-filter data-filter-name="plugin"><option value="all">All Plugins</option><option value="active"<?php echo $filters['plugin']==='active'?' selected':''; ?>>Active</option><option value="inactive"<?php echo $filters['plugin']==='inactive'?' selected':''; ?>>Inactive</option></select></label></div>
        <div class="filter-section"><strong>Filter by Name:</strong><div class="filter-buttons"><a class="alphabet-button<?php echo $filters['letter']===''?' active':''; ?>" href="<?php echo lorkhan_ui_h(lorkhan_description_url(['letter'=>'','page'=>1])); ?>#entries">All</a><?php foreach (range('A','Z') as $letter): ?><a class="alphabet-button<?php echo $filters['letter']===$letter?' active':''; ?>" href="<?php echo lorkhan_ui_h(lorkhan_description_url(['letter'=>$letter,'page'=>1])); ?>#entries"><?php echo $letter; ?></a><?php endforeach; ?></div></div>
        <section class="new-entry-panel" data-description-panel hidden><h2>Add New Entry</h2><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath . '/forms/description-save'); ?>"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><div class="entry-form-grid"><label>Content file<input name="content_file" value="Morrowind.esm" maxlength="256" required></label><label>Record ID<input name="record_id" maxlength="256" required></label><label>Display name<input name="display_name" maxlength="256" required></label><label class="wide">Description<textarea name="description" maxlength="8192" required></textarea></label></div><div class="button-group"><button class="action-button add-new" type="submit">Save description</button><button type="button" class="action-button" data-description-cancel>Cancel</button></div></form></section>
        <p class="catalog-result-count">Showing <?php echo count($catalog['items']); ?> of <?php echo (int)$catalog['total']; ?> matching descriptions.</p>
        <div class="table-container"><table><thead><tr><th>Base ID</th><th>Plugin</th><th>Name</th><th>Description</th><th>Source</th><th>Actions</th></tr></thead><tbody><?php foreach ($catalog['items'] as $row): $isCustom=$row['source']==='custom'; ?><tr><td><?php echo lorkhan_ui_h($row['record_id']); ?></td><td><?php echo lorkhan_ui_h($row['content_file']); ?></td><td><?php echo lorkhan_ui_h($row['display_name']); ?></td><td><?php echo lorkhan_ui_h($row['description'] ?? 'No description has been provided.'); ?></td><td><span class="source-badge source-<?php echo lorkhan_ui_h($row['source']); ?>"><?php echo lorkhan_ui_h(ucfirst((string)$row['source'])); ?></span><?php if ($row['active'] !== null && !filter_var($row['active'],FILTER_VALIDATE_BOOL)): ?> <span class="source-badge inactive">Inactive Plugin</span><?php endif; ?></td><td><details><summary><?php echo $isCustom?'Edit':'Add Override'; ?></summary><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath . '/forms/description-save'); ?>"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input name="content_file" value="<?php echo lorkhan_ui_h($row['content_file']); ?>" required><input name="record_id" value="<?php echo lorkhan_ui_h($row['record_id']); ?>" required><input name="display_name" value="<?php echo lorkhan_ui_h($row['display_name']); ?>" required><textarea name="description" required><?php echo lorkhan_ui_h($row['description'] ?? ''); ?></textarea><button type="submit">Save Override</button></form></details><?php if ($isCustom): ?><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath . '/forms/description-delete'); ?>" data-confirm="Delete this custom override?"><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="description_id" value="<?php echo lorkhan_ui_h($row['description_id']); ?>"><button type="submit" class="btn-danger">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if ($catalog['items'] === []): ?><tr><td colspan="6">No descriptions match these filters.</td></tr><?php endif; ?></tbody></table></div>
        <?php if ((int)$catalog['pages'] > 1): ?><nav class="description-pagination" aria-label="Description pages"><?php if ((int)$catalog['page'] > 1): ?><a href="<?php echo lorkhan_ui_h(lorkhan_description_url(['page'=>(int)$catalog['page']-1])); ?>#entries">Previous</a><?php endif; ?><span>Page <?php echo (int)$catalog['page']; ?> of <?php echo (int)$catalog['pages']; ?></span><?php if ((int)$catalog['page'] < (int)$catalog['pages']): ?><a href="<?php echo lorkhan_ui_h(lorkhan_description_url(['page'=>(int)$catalog['page']+1])); ?>#entries">Next</a><?php endif; ?></nav><?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/descriptions.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/descriptions.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
