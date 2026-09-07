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
    <header class="page-header chim-page-head"><h1 id="page-title" class="chim-page-head-title"><span id="title-text">Description Manager</span></h1><p class="page-subtitle chim-page-head-note">Create custom descriptions for items and equipment that enhance NPC context</p></header>
    <?php if ($statusText !== ''): ?><div class="description-notice" role="status"><?php echo lorkhan_ui_h($statusText); ?></div><?php endif; ?>
    <?php if ($installations === []): ?><p class="description-notice">Connect OpenMW once before managing installation-scoped descriptions.</p><?php endif; ?>
    <?php if(count($installations)>1): ?><form class="installation-selector" method="get">
        <?php if($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>Installation<select name="installation_id" data-description-installation><?php foreach($installations as $id=>$label): ?><option value="<?php echo lorkhan_ui_h($id); ?>"<?php echo $id===$installationId?' selected':''; ?>><?php echo lorkhan_ui_h($label); ?></option><?php endforeach; ?></select></label><button type="submit" class="action-button">Switch</button>
    </form><?php endif; ?>
    <div class="content-grid">
        <section class="content-section">
            <h2>Batch Upload</h2>
            <form action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/description-import'); ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <label for="description-csv">Select .csv file to upload:</label><input type="file" name="csv_file" id="description-csv" accept=".csv,text/csv" required<?php echo $installationId===''?' disabled':''; ?>>
                <div class="button-group"><button type="submit" class="action-button upload-csv"<?php echo $installationId===''?' disabled':''; ?>>Upload CSV</button><a class="action-button download-csv" href="<?php echo lorkhan_ui_h($managementBasePath.'/exports/descriptions/example.csv'); ?>">Download Example CSV</a><?php if($installationId!==''): ?><a class="action-button export-csv" href="<?php echo lorkhan_ui_h($managementBasePath.'/exports/descriptions/custom.csv?'.http_build_query(['installation_id'=>$installationId])); ?>">Export Custom Descriptions</a><?php endif; ?></div>
                <p class="csv-format">CSV format: plugin, baseid, name, description</p>
                <p class="csv-help">Use the source plugin filename, such as <code>Morrowind.esm</code>, and the stable OpenMW record ID, such as <code>misc_dwrv_coin00</code>. Custom overrides apply only to the selected installation.</p>
            </form>
        </section>
        <section class="content-section">
            <h2>Database Management</h2>
            <p>Custom description storage:<br><b>lorkhan_internal &rarr; item_descriptions</b></p>
            <p>View effective defaults and overrides in the Descriptions Database below. Factory descriptions remain read-only.</p>
            <div class="button-group database-reset"><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/description-reset'); ?>" data-confirm="Delete every custom description for this installation? Factory defaults remain unchanged.">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="confirm" value="Reset"><button type="submit" class="btn-danger"<?php echo $installationId===''?' disabled':''; ?>>Factory Reset Item Override Table</button>
            </form></div>
            <p class="reset-help">This deletes custom item descriptions for this installation. Matching factory defaults become effective again.</p>
        </section>
    </div>
    <section class="full-width-section">
        <h2 id="entries">&#x1F4CB; Descriptions Database</h2>
        <div class="action-container"><button type="button" class="action-button add-new" data-description-create<?php echo $installationId===''?' disabled':''; ?>>Add New Entry</button>
            <form class="search-container" method="get" action="#entries"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="embed" value="<?php echo $embedded?'1':'0'; ?>"><?php foreach(['letter','source','plugin'] as $field): ?><input type="hidden" name="<?php echo $field; ?>" value="<?php echo lorkhan_ui_h($filters[$field]); ?>"><?php endforeach; ?><input type="search" name="search" value="<?php echo lorkhan_ui_h($filters['search']); ?>" placeholder="Search descriptions..." aria-label="Search descriptions" maxlength="100"><button type="submit" class="action-button edit">Search</button></form>
        </div>
        <div class="filter-section"><strong>Filter by Name:</strong><div class="filter-buttons"><?php foreach(['All',...range('A','Z')] as $letter): $value=$letter==='All'?'':$letter; ?><a class="alphabet-button<?php echo $filters['letter']===$value?' active':''; ?>" href="<?php echo lorkhan_ui_h(lorkhan_description_url(['letter'=>$value,'page'=>1])); ?>#entries"<?php echo $filters['letter']===$value?' aria-current="true"':''; ?>><?php echo $letter; ?></a><?php endforeach; ?></div></div>
        <div class="table-container" id="item-table-container" role="region" aria-label="Item descriptions" tabindex="0"><table><thead><tr><th>Base ID</th><th>Plugin</th><th>Name</th><th>Description</th><th>Actions</th></tr></thead><tbody>
            <?php foreach($catalog['items'] as $row): $description=(string)($row['description']??''); $preview=mb_substr($description,0,200,'UTF-8').(mb_strlen($description,'UTF-8')>200?'...':''); ?>
            <tr><td><?php echo lorkhan_ui_h($row['record_id']); ?></td><td><?php echo lorkhan_ui_h($row['content_file']); ?></td><td><?php echo lorkhan_ui_h($row['display_name']); ?><?php if($row['source']==='custom'): ?><small class="description-source">Custom override</small><?php elseif($row['source']==='missing'): ?><small class="description-source">Missing description</small><?php endif; ?><?php if($row['active']!==null&&!filter_var($row['active'],FILTER_VALIDATE_BOOL)): ?><small class="description-source">Inactive plugin</small><?php endif; ?></td><td><?php echo nl2br(lorkhan_ui_h($preview)); ?></td><td><button type="button" class="action-button edit" data-description-edit data-description-entry="<?php echo lorkhan_ui_h(json_encode(array_intersect_key($row,array_flip(['description_id','content_file','record_id','display_name','description','source'])),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); ?>">Edit</button></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php if($catalog['items']===[]): ?><p class="no-data">No descriptions found.</p><?php endif; ?>
        <nav class="description-pagination" aria-label="Description pages"><span>Showing <?php echo count($catalog['items']); ?> of <?php echo number_format((int)$catalog['total']); ?> descriptions &middot; Page <?php echo (int)$catalog['page']; ?> of <?php echo (int)$catalog['pages']; ?></span><div><?php if((int)$catalog['page']>1): ?><a class="alphabet-button" href="<?php echo lorkhan_ui_h(lorkhan_description_url(['page'=>(int)$catalog['page']-1])); ?>#entries">Previous</a><?php endif; ?><?php if((int)$catalog['page']<(int)$catalog['pages']): ?><a class="alphabet-button" href="<?php echo lorkhan_ui_h(lorkhan_description_url(['page'=>(int)$catalog['page']+1])); ?>#entries">Next</a><?php endif; ?></div></nav>
    </section>
    <?php if($installationId!==''): ?><details class="description-installation-details"<?php echo $filters['source']!=='all'||$filters['plugin']!=='all'?' open':''; ?>><summary>Installation catalog details and filters</summary>
    <div class="description-summary" aria-label="Description catalog summary">
        <article><strong><?php echo (int) $summary['default_count']; ?></strong><span>Defaults</span></article>
        <article><strong><?php echo (int) $summary['custom_count']; ?></strong><span>Custom</span></article>
        <article><strong><?php echo (int) $summary['missing_count']; ?></strong><span>Missing</span></article>
    </div>

        <div class="catalog-filters"><label>Source<select data-description-filter data-filter-name="source"><option value="all">All Sources</option><?php foreach (['default'=>'Default','custom'=>'Custom','missing'=>'Missing'] as $value=>$label): ?><option value="<?php echo $value; ?>"<?php echo $filters['source']===$value?' selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label><label>Plugin state<select data-description-filter data-filter-name="plugin"><option value="all">All Plugins</option><option value="active"<?php echo $filters['plugin']==='active'?' selected':''; ?>>Active</option><option value="inactive"<?php echo $filters['plugin']==='inactive'?' selected':''; ?>>Inactive</option></select></label></div>
    <section class="full-width-section discovered-section">
        <h2>&#x1F50E; Discovered OpenMW Items</h2><p>Items are learned from bounded inventory, equipment, and nearby-object context using content file and record ID.</p>
        <div class="table-container"><table><thead><tr><th>Base ID</th><th>Plugin</th><th>Name</th><th>Seen In</th><th>Status</th></tr></thead><tbody><?php foreach ($discoveredItems as $item): ?><tr><td><?php echo lorkhan_ui_h($item['record_id']); ?></td><td><?php echo lorkhan_ui_h($item['content_file']); ?></td><td><?php echo lorkhan_ui_h($item['display_name'] ?? ''); ?></td><td><?php $sources=$item['observed_sources']??[];if(is_string($sources))$sources=json_decode($sources,true);echo lorkhan_ui_h(is_array($sources)?implode(', ',$sources):''); ?></td><td><span class="source-badge source-<?php echo lorkhan_ui_h(strtolower((string)$item['description_source'])); ?>"><?php echo lorkhan_ui_h($item['description_source']); ?></span><?php if (!filter_var($item['active'] ?? false,FILTER_VALIDATE_BOOL)): ?> <span class="source-badge inactive">Inactive Plugin</span><?php endif; ?></td></tr><?php endforeach; ?><?php if ($discoveredItems === []): ?><tr><td colspan="5">No items have been discovered in game yet.</td></tr><?php endif; ?></tbody></table></div>
    </section>

    </details><?php endif; ?>
</main>
<div class="description-modal" id="description-editor" aria-hidden="true"><div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="description-editor-title">
    <div class="modal-header"><h2 class="modal-title" id="description-editor-title">Add New Description</h2></div>
    <div class="modal-body"><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/description-save'); ?>" id="description-editor-form">
        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
        <label for="description-plugin">Plugin:</label><small id="description-plugin-help">Use the source plugin filename, such as Morrowind.esm.</small><input type="text" id="description-plugin" name="content_file" maxlength="256" value="Morrowind.esm" aria-describedby="description-plugin-help" required>
        <label for="description-record">Base ID (required):</label><small id="description-record-help">The stable OpenMW record ID. Create a new entry to use a different ID.</small><input type="text" id="description-record" name="record_id" maxlength="256" aria-describedby="description-record-help" required>
        <label for="description-name">Name:</label><small id="description-name-help">Display name for the entry (optional).</small><input type="text" id="description-name" name="display_name" maxlength="256" aria-describedby="description-name-help">
        <label for="description-text">Description:</label><small id="description-text-help">Short description to be injected into AI context.</small><textarea id="description-text" name="description" maxlength="8192" rows="6" aria-describedby="description-text-help"></textarea>
        <div class="modal-footer"><button type="submit" class="btn-save" id="description-save">Add Entry</button><button type="button" class="btn-cancel" data-description-cancel>Cancel</button></div>
    </form><form method="post" action="<?php echo lorkhan_ui_h($managementBasePath.'/forms/description-delete'); ?>" id="description-delete-form" hidden data-confirm="Delete this custom override? Its factory default, if any, becomes effective again."><input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>"><input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>"><input type="hidden" name="description_id" id="description-delete-id"><button type="submit" class="btn-danger">Delete Custom Override</button></form></div>
</div></div>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/descriptions.js?v=<?php echo lorkhan_ui_h((string)filemtime(__DIR__.'/js/descriptions.js')); ?>"></script>
<?php include __DIR__.'/tmpl/footer.html'; ?>
