<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Oghma Infinium';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page oghma-page-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$selectedInstallation = (string) ($_GET['installation_id'] ?? ($installations[0]['installation_id'] ?? ''));
$catalogPageSize = 500;
$filters = [
    'search' => (string) ($_GET['search'] ?? ''),
    'category' => (string) ($_GET['category'] ?? ''),
    'order' => (string) ($_GET['order'] ?? 'asc'),
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'page_size' => $catalogPageSize,
    'installation_id' => $selectedInstallation,
];
$catalog = $uiRepository->oghmaCatalog($filters);
$rows = $catalog['rows'];
$catalogTotal = (int) $catalog['total'];
$catalogPages = max(1, (int) $catalog['pages']);
$catalogPage = min(max(1, (int) $catalog['page']), $catalogPages);
$catalogFirst = $rows === [] ? 0 : (($catalogPage - 1) * $catalogPageSize) + 1;
$catalogLast = $rows === [] ? 0 : $catalogFirst + count($rows) - 1;
$categories = $uiRepository->oghmaCategories();
$additionalStylesheets = ['herika-oghma.css?v=' . (string) filemtime(__DIR__ . '/css/herika-oghma.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) {
    include __DIR__ . '/tmpl/navbar.php';
}

// Filter changes drop back to page 1; only explicit page links carry a page number.
$query = static function (array $replace = []) use ($filters, $embedded): string {
    $values = array_merge($filters, ['embed' => $embedded ? '1' : '0', 'page' => 1], $replace);
    unset($values['page_size']);
    return '?' . http_build_query($values) . '#entries';
};

$knowledgeBadges = static function (string $value, bool $basic = false): void {
    $classes = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $class): bool => $class !== ''));
    if ($classes === []) {
        echo '<span class="knowledge-everyone">Everyone</span>';
        return;
    }
    foreach ($classes as $class) {
        echo '<span class="knowledge-badge' . ($basic ? ' basic' : '') . '">' . lorkhan_ui_h($class) . '</span>';
    }
};

$modalFields = static function (string $prefix, array $row = []): void {
    $field = static fn (string $key): string => lorkhan_ui_h((string) ($row[$key] ?? ''));
    ?>
    <label for="<?php echo $prefix; ?>-topic">Topic<?php echo $prefix === 'new' ? ' (required)' : ''; ?>:</label>
    <small>Topic name for keyword searching.</small>
    <input type="text" name="topic" id="<?php echo $prefix; ?>-topic" value="<?php echo $field('topic'); ?>" required>

    <label for="<?php echo $prefix; ?>-aliases">Aliases:</label>
    <small>Alternate names that should find this article. Separate aliases with commas.</small>
    <input type="text" name="aliases" id="<?php echo $prefix; ?>-aliases" value="<?php echo $field('aliases'); ?>">

    <label for="<?php echo $prefix; ?>-content">Topic Description<?php echo $prefix === 'new' ? ' (required)' : ''; ?>:</label>
    <small>Advanced knowledge information on the subject.</small>
    <textarea name="content" id="<?php echo $prefix; ?>-content" rows="<?php echo $prefix === 'new' ? 5 : 8; ?>" required><?php echo $field('content'); ?></textarea>

    <label for="<?php echo $prefix; ?>-knowledge-class">Knowledge Class:</label>
    <small>Who should have access to this advanced knowledge. Separate tags with commas. Do not use common here &mdash; it only marks public basic access.</small>
    <input type="text" name="knowledge_class" id="<?php echo $prefix; ?>-knowledge-class" value="<?php echo $field('knowledge_class'); ?>">

    <label for="<?php echo $prefix; ?>-basic">Topic Description (Basic):</label>
    <small>Basic information available when advanced access is not granted.</small>
    <textarea name="topic_desc_basic" id="<?php echo $prefix; ?>-basic" rows="<?php echo $prefix === 'new' ? 5 : 8; ?>"><?php echo $field('topic_desc_basic'); ?></textarea>

    <label for="<?php echo $prefix; ?>-basic-class">Knowledge Class (Basic):</label>
    <small>Who should have access to the basic article. Use common to mark this article public basic knowledge for every NPC. Leave empty to allow all NPCs to know this.</small>
    <input type="text" name="knowledge_class_basic" id="<?php echo $prefix; ?>-basic-class" value="<?php echo $field('knowledge_class_basic'); ?>">

    <label for="<?php echo $prefix; ?>-tags">Tags:</label>
    <small>Additional retrieval tags.</small>
    <input type="text" name="tags" id="<?php echo $prefix; ?>-tags" value="<?php echo $field('tags'); ?>">

    <label for="<?php echo $prefix; ?>-category">Category:</label>
    <small>Category for database searching.</small>
    <input type="text" name="category" id="<?php echo $prefix; ?>-category" value="<?php echo $field('category'); ?>">
    <input type="hidden" name="title" id="<?php echo $prefix; ?>-title" value="<?php echo $field('title'); ?>">
    <input type="hidden" name="provenance" value="management">
    <?php
};
?>
<main class="oghma-page">
    <div id="toast" class="toast-notification" hidden><span class="message"></span></div>

    <div class="page-header">
        <h1 id="page-title">
            <img src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/oghma_infinium.png" alt="" aria-hidden="true" width="32" height="32">
            <span id="title-text">Oghma Infinium</span>
        </h1>
        <div id="header-content">
            <div id="oghma-header-content">
                <p>The <b>Oghma Infinium</b> is a Morrowind encyclopedia that AI NPCs use to help them roleplay.</p>
                <p>It detects topics during conversations and injects the appropriate information into the AI's prompt.</p>
                <h3><strong>Ensure all topic titles are lowercase and spaces are replaced with underscores (_).</strong></h3>
                <h4>Example: "Fishy Stick" becomes "fishy_stick"</h4>
                <p>Knowledge classes use the NPC's effective Oghma tags from Global Settings, Core Profile and NPC settings.</p>
                <div class="logic-section">
                    <h3 class="logic-title">&#x1F50D; Article Search Logic</h3>
                    <div class="logic-steps">
                        <div class="logic-step"><div class="step-number">1</div><div class="step-content"><strong>Keyword Search</strong><p>Look for articles matching the most relevant topics in the conversation.</p></div></div>
                        <div class="logic-step"><div class="step-number">2</div><div class="step-content"><strong>Advanced Access Check</strong><p>Check <code>knowledge_class</code> for access to the advanced article (<code>topic_desc</code>).</p></div></div>
                        <div class="logic-step"><div class="step-number">3</div><div class="step-content"><strong>Basic Access Check</strong><p>Check <code>knowledge_class_basic</code> for access to the basic article (<code>topic_desc_basic</code>).</p></div></div>
                        <div class="logic-step"><div class="step-number">4</div><div class="step-content"><strong>Fallback Response</strong><p>If neither version is permitted, the NPC receives <em>no article knowledge about that topic</em>.</p></div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-navigation" role="tablist" aria-label="Oghma pages">
        <button type="button" class="tab-button active" role="tab" aria-selected="true"><span aria-hidden="true">&#x1F4DA;</span>&#160;Oghma Infinium</button>
    </div>

    <?php if (isset($_GET['status'])):
        $status = is_string($_GET['status']) ? $_GET['status'] : '';
        $noticeCount = (int) (is_string($_GET['count'] ?? null) ? $_GET['count'] : 0);
        $notice = match ($status) {
            'imported' => 'CSV validated and imported.',
            'factory-synced' => 'Factory catalog synced. '
                . ($noticeCount > 0 ? $noticeCount . ' factory ' . ($noticeCount === 1 ? 'article was' : 'articles were') . ' refreshed' : 'Factory articles were refreshed')
                . ' and your own articles were kept.',
            default => 'Knowledge record saved.',
        };
    ?>
        <div class="oghma-notice" role="status"><?php echo lorkhan_ui_h($notice); ?></div>
    <?php endif; ?>

    <div id="oghma-tab" class="tab-content active">
        <div class="content-grid">
            <div class="content-section">
                <h2>Batch Upload</h2>
                <form method="post" enctype="multipart/form-data" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/knowledge-import">
                    <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                    <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selectedInstallation); ?>">
                    <div>
                        <label for="csv_file">Select .csv file to upload:</label>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required>
                    </div>
                    <div class="button-group">
                        <button type="submit" class="action-button upload-csv">Upload CSV</button>
                        <a href="<?php echo lorkhan_ui_h($managementBasePath); ?>/exports/oghma/example.csv" class="action-button download-csv">Download Example CSV</a>
                    </div>
                </form>
                <p>Upload CHIM-format UTF-8 CSV. Matching custom topics are revised for this installation.</p>

                <details class="oghma-tips">
                    <summary>Article editing tips</summary>
                    <ul>
                        <li>Write topic titles in lowercase and replace spaces with underscores &mdash; "House Redoran" becomes <code>house_redoran</code>.</li>
                        <li>Use <code>common</code> only as an article marker for public basic knowledge. Do not assign it to NPCs.</li>
                        <li>Access is inherited through LORKHAN's Global &rarr; Core Profile &rarr; NPC hierarchy.</li>
                    </ul>
                </details>
            </div>

            <div class="content-section">
                <h2>Database Management</h2>
                <p>Article storage:<br><b>lorkhan_internal &rarr; knowledge_documents</b></p>
                <p>View conversation usage:<br><b>Control Panel &rarr; Oghma Audit</b></p>
                <div class="button-group database-actions">
                <form class="factory-sync-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/oghma-factory-sync">
                    <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                    <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selectedInstallation); ?>">
                    <input type="hidden" name="embed" value="<?php echo $embedded ? '1' : '0'; ?>">
                        <button type="submit" class="action-button sync-factory" aria-describedby="factory-sync-help" data-confirm="Sync the factory catalog for every local installation? Factory articles are refreshed from the shipped catalog. Your custom articles are kept.">Sync Factory Catalog</button>
                </form>
                    <span class="status-control"><button type="button" class="btn-danger" disabled aria-disabled="true">Delete All Entries</button><?php echo lorkhan_ui_feature_badge('config.oghma.destructive', true); ?></span>
                </div>
                <p id="factory-sync-help">Sync refreshes the shipped factory catalog across this server. Your custom articles are kept.</p>
            </div>
        </div>

        <div class="full-width-section">
            <h2 id="entries"><span aria-hidden="true">&#x1F4CB;</span>&#160;Oghma Infinium Entries</h2>
            <div class="action-container">
                <button type="button" class="action-button add-new" data-oghma-new-open>Add New Entry</button>
                <form class="search-container" method="get" action="#entries">
                    <input type="hidden" name="embed" value="<?php echo $embedded ? '1' : '0'; ?>">
                    <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selectedInstallation); ?>">
                    <input type="hidden" name="category" value="<?php echo lorkhan_ui_h($filters['category']); ?>">
                    <input type="hidden" name="order" value="<?php echo lorkhan_ui_h($filters['order']); ?>">
                    <input type="text" name="search" aria-label="Search topics" maxlength="100" placeholder="Search topics..." value="<?php echo lorkhan_ui_h($filters['search']); ?>">
                    <button type="submit" class="action-button edit">Search</button>
                </form>
            </div>

            <div class="filter-section">
                <div class="category-filter">
                    <strong>Filter by Category:</strong><br>
                    <div class="filter-buttons">
                        <a class="alphabet-button<?php echo $filters['category'] === '' ? ' selected' : ''; ?>" href="<?php echo lorkhan_ui_h($query(['category' => ''])); ?>">All Categories</a>
                        <?php foreach ($categories as $category): ?>
                            <a class="alphabet-button<?php echo $filters['category'] === $category ? ' selected' : ''; ?>" href="<?php echo lorkhan_ui_h($query(['category' => $category])); ?>"><?php echo lorkhan_ui_h((string) $category); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <strong>Sort Order:</strong><br>
                    <div class="sort-buttons">
                        <a class="alphabet-button<?php echo $filters['order'] === 'asc' ? ' selected' : ''; ?>" href="<?php echo lorkhan_ui_h($query(['order' => 'asc'])); ?>"><span aria-hidden="true">&#x1F53C;</span>&#160;Ascending</a>
                        <a class="alphabet-button<?php echo $filters['order'] === 'desc' ? ' selected' : ''; ?>" href="<?php echo lorkhan_ui_h($query(['order' => 'desc'])); ?>"><span aria-hidden="true">&#x1F53D;</span>&#160;Descending</a>
                    </div>
                </div>
            </div>

            <div class="table-container" role="region" tabindex="0" aria-label="Oghma articles">
                <table>
                    <thead><tr><th>Topic</th><th>Aliases</th><th>Topic Description (Advanced)</th><th>Knowledge Class (Advanced)</th><th>Topic Description (Basic)</th><th>Knowledge Class (Basic)</th><th>Tags</th><th>Category</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $factory = ($row['provenance']['source'] ?? null) === 'factory-oghma';
                        $editPayload = [
                            'document_id' => $row['document_id'], 'topic' => $row['topic'], 'title' => $row['title'],
                            'aliases' => $row['aliases'], 'content' => $row['content'], 'knowledge_class' => $row['knowledge_class'],
                            'topic_desc_basic' => $row['topic_desc_basic'], 'knowledge_class_basic' => $row['knowledge_class_basic'],
                            'tags' => $row['tags'], 'category' => $row['category'], 'factory' => $factory,
                        ];
                    ?>
                        <tr>
                            <td><?php echo lorkhan_ui_h($row['topic']); ?></td>
                            <td><?php echo trim((string) $row['aliases']) !== '' ? lorkhan_ui_h($row['aliases']) : '<span class="empty-value">None</span>'; ?></td>
                            <td><?php echo nl2br(lorkhan_ui_h($row['content'])); ?></td>
                            <td class="knowledge-cell"><?php $knowledgeBadges((string) $row['knowledge_class']); ?></td>
                            <td><?php echo nl2br(lorkhan_ui_h($row['topic_desc_basic'])); ?></td>
                            <td class="knowledge-cell"><?php $knowledgeBadges((string) $row['knowledge_class_basic'], true); ?></td>
                            <td><?php echo trim((string) $row['tags']) !== '' ? nl2br(lorkhan_ui_h($row['tags'])) : '<span class="empty-value">None</span>'; ?></td>
                            <td><?php echo lorkhan_ui_h($row['category']); ?></td>
                            <td class="action-cell">
                                <button type="button" class="action-button edit"<?php echo $factory ? ' title="Editing a factory article saves your changes as a custom article. The factory article stays unchanged."' : ''; ?> data-oghma-edit='<?php echo lorkhan_ui_h((string) json_encode($editPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); ?>'>Edit</button>
                                <?php if ($factory): ?><span class="factory-label">Factory</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($rows === []): ?><p class="oghma-empty">No entries found.</p><?php endif; ?>

            <nav class="oghma-pagination" aria-label="Oghma catalog pagination">
                <div class="oghma-pagination-summary">
                    <p class="oghma-pagination-range">
                        <?php if ($catalogTotal === 0): ?>
                            Showing 0 of 0 articles
                        <?php else: ?>
                            Showing <?php echo number_format($catalogFirst); ?>&#8211;<?php echo number_format($catalogLast); ?> of <?php echo number_format($catalogTotal); ?> article<?php echo $catalogTotal === 1 ? '' : 's'; ?>
                        <?php endif; ?>
                    </p>
                    <p class="oghma-pagination-page">Page <?php echo number_format($catalogPage); ?> of <?php echo number_format($catalogPages); ?></p>
                </div>
                <?php if ($catalogPages > 1):
                    $pageNumbers = $catalogPages <= 10
                        ? range(1, $catalogPages)
                        : array_values(array_unique(array_merge([1], range(max(2, $catalogPage - 2), min($catalogPages - 1, $catalogPage + 2)), [$catalogPages])));
                    $previousNumber = 0;
                ?>
                    <ul class="oghma-pagination-list">
                        <li>
                            <?php if ($catalogPage > 1): ?>
                                <a class="oghma-page-link" rel="prev" href="<?php echo lorkhan_ui_h($query(['page' => $catalogPage - 1])); ?>">Previous</a>
                            <?php else: ?>
                                <span class="oghma-page-link disabled" aria-disabled="true">Previous</span>
                            <?php endif; ?>
                        </li>
                        <?php foreach ($pageNumbers as $pageNumber): ?>
                            <?php if ($previousNumber !== 0 && $pageNumber > $previousNumber + 1): ?>
                                <li class="oghma-pagination-gap" aria-hidden="true">&hellip;</li>
                            <?php endif; ?>
                            <li>
                                <?php if ($pageNumber === $catalogPage): ?>
                                    <span class="oghma-page-link current" aria-current="page"><?php echo number_format($pageNumber); ?></span>
                                <?php else: ?>
                                    <a class="oghma-page-link" href="<?php echo lorkhan_ui_h($query(['page' => $pageNumber])); ?>" aria-label="Page <?php echo number_format($pageNumber); ?>"><?php echo number_format($pageNumber); ?></a>
                                <?php endif; ?>
                            </li>
                            <?php $previousNumber = $pageNumber; ?>
                        <?php endforeach; ?>
                        <li>
                            <?php if ($catalogPage < $catalogPages): ?>
                                <a class="oghma-page-link" rel="next" href="<?php echo lorkhan_ui_h($query(['page' => $catalogPage + 1])); ?>">Next</a>
                            <?php else: ?>
                                <span class="oghma-page-link disabled" aria-disabled="true">Next</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</main>

<div id="editModal" class="modal-backdrop" hidden aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
        <div class="modal-header">
            <h2 class="modal-title" id="edit-modal-title">Edit Oghma Entry</h2>
        </div>
        <div class="modal-body">
            <p class="factory-edit-note" id="edit-factory-note" hidden>This is a factory article, so it is never changed here. Saving creates a custom article for this server that replaces it in the catalog. Delete that custom article later to bring the factory version back.</p>
            <form id="oghma-edit-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/knowledge-revise">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="document_id" id="edit-document-id">
                <?php $modalFields('edit'); ?>
                <div class="modal-footer">
                    <button type="submit" class="btn-save" id="edit-save-button">Save Changes</button>
                    <button type="submit" class="btn-danger" id="edit-delete-button" form="oghma-delete-form" data-confirm="Delete this Oghma entry?">Delete</button>
                    <button type="button" class="btn-base btn-cancel" data-oghma-modal-close>Cancel</button>
                </div>
            </form>
            <form id="oghma-delete-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/knowledge-delete">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="document_id" id="delete-document-id">
            </form>
        </div>
    </div>
</div>

<div id="newEntryModal" class="modal-backdrop" hidden aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="new-modal-title">
        <div class="modal-header"><h2 class="modal-title" id="new-modal-title">Add New Oghma Entry</h2></div>
        <div class="modal-body">
            <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/knowledge">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($selectedInstallation); ?>">
                <?php $modalFields('new'); ?>
                <div class="modal-footer">
                    <button type="submit" class="btn-save">Save</button>
                    <button type="button" class="btn-base btn-cancel" data-oghma-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/oghma.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/oghma.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
