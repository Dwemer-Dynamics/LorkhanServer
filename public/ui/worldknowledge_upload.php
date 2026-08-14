<?php

declare(strict_types=1);

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Oghma Infinium';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page oghma-page-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$selectedInstallation = (string) ($_GET['installation_id'] ?? ($installations[0]['installation_id'] ?? ''));
$filters = [
    'search' => (string) ($_GET['search'] ?? ''),
    'category' => (string) ($_GET['category'] ?? ''),
    'order' => (string) ($_GET['order'] ?? 'asc'),
    'page' => 1,
    'page_size' => 500,
    'installation_id' => $selectedInstallation,
];
$catalog = $uiRepository->oghmaCatalog($filters);
$rows = $catalog['rows'];
$categories = $uiRepository->oghmaCategories();
$additionalStylesheets = ['herika-oghma.css?v=' . (string) filemtime(__DIR__ . '/css/herika-oghma.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) {
    include __DIR__ . '/tmpl/navbar.php';
}

$query = static function (array $replace = []) use ($filters, $embedded): string {
    $values = array_merge($filters, ['embed' => $embedded ? '1' : '0'], $replace);
    unset($values['page'], $values['page_size']);
    return '?' . http_build_query($values) . '#entries';
};

$knowledgeBadges = static function (string $value, bool $basic = false): void {
    $classes = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $class): bool => $class !== ''));
    if ($classes === []) {
        echo '<span class="knowledge-everyone">Everyone</span>';
        return;
    }
    foreach ($classes as $class) {
        echo '<span class="knowledge-badge' . ($basic ? ' basic' : '') . '">' . almsivi_ui_h($class) . '</span>';
    }
};

$modalFields = static function (string $prefix, array $row = []): void {
    $field = static fn (string $key): string => almsivi_ui_h((string) ($row[$key] ?? ''));
    ?>
    <label for="<?php echo $prefix; ?>-topic">Topic:</label>
    <small>Topic name for keyword searching.</small>
    <input type="text" name="topic" id="<?php echo $prefix; ?>-topic" value="<?php echo $field('topic'); ?>" required>

    <label for="<?php echo $prefix; ?>-aliases">Aliases:</label>
    <small>Alternate names that should find this article. Separate aliases with commas.</small>
    <input type="text" name="aliases" id="<?php echo $prefix; ?>-aliases" value="<?php echo $field('aliases'); ?>">

    <label for="<?php echo $prefix; ?>-content">Topic Description:</label>
    <small>Advanced knowledge information on the subject.</small>
    <textarea name="content" id="<?php echo $prefix; ?>-content" rows="8" required><?php echo $field('content'); ?></textarea>

    <label for="<?php echo $prefix; ?>-knowledge-class">Knowledge Class:</label>
    <small>Who should have access to this advanced knowledge. Separate tags with commas. Do not use common here &mdash; it only marks public basic access.</small>
    <input type="text" name="knowledge_class" id="<?php echo $prefix; ?>-knowledge-class" value="<?php echo $field('knowledge_class'); ?>">

    <label for="<?php echo $prefix; ?>-basic">Topic Description (Basic):</label>
    <small>Basic information available when advanced access is not granted.</small>
    <textarea name="topic_desc_basic" id="<?php echo $prefix; ?>-basic" rows="8"><?php echo $field('topic_desc_basic'); ?></textarea>

    <label for="<?php echo $prefix; ?>-basic-class">Knowledge Class (Basic):</label>
    <small>Who should have access to the basic article. Use common to mark this article public basic knowledge for every NPC. Leave empty to allow all NPCs to know this.</small>
    <input type="text" name="knowledge_class_basic" id="<?php echo $prefix; ?>-basic-class" value="<?php echo $field('knowledge_class_basic'); ?>">

    <label for="<?php echo $prefix; ?>-tags">Tags:</label>
    <small>Additional retrieval tags.</small>
    <input type="text" name="tags" id="<?php echo $prefix; ?>-tags" value="<?php echo $field('tags'); ?>">

    <label for="<?php echo $prefix; ?>-category">Category:</label>
    <small>Category for database searching.</small>
    <input type="text" name="category" id="<?php echo $prefix; ?>-category" value="<?php echo $field('category'); ?>" required>
    <input type="hidden" name="title" id="<?php echo $prefix; ?>-title" value="<?php echo $field('title'); ?>">
    <input type="hidden" name="provenance" value="management">
    <?php
};
?>
<main class="oghma-page">
    <div id="toast" class="toast-notification" hidden><span class="message"></span></div>

    <div class="page-header">
        <h1 id="page-title">
            <img src="<?php echo almsivi_ui_h($webRoot); ?>/ui/images/oghma_infinium.png" alt="" aria-hidden="true" width="32" height="32">
            <span id="title-text">Oghma Infinium</span>
        </h1>
        <div id="header-content">
            <div id="oghma-header-content">
                <p class="oghma-summary">Oghma matches conversation topics to articles. NPCs receive the most detailed version they are allowed to know; if no version matches, they know nothing about the topic.</p>
            </div>
        </div>
    </div>

    <div class="tab-navigation" role="tablist" aria-label="Oghma pages">
        <button type="button" class="tab-button active" role="tab" aria-selected="true"><span aria-hidden="true">&#x1F4DA;</span>&#160;Oghma Infinium</button>
        <span class="oghma-tab-placeholder">
            <button type="button" class="tab-button" role="tab" disabled aria-disabled="true"><span aria-hidden="true">&#x26A1;</span>&#160;Dynamic Oghma</button>
            <?php echo almsivi_ui_feature_badge('config.oghma.dynamic', true); ?>
        </span>
    </div>

    <?php if (isset($_GET['status'])): ?>
        <div class="oghma-notice" role="status"><?php echo almsivi_ui_h($_GET['status'] === 'imported' ? 'CSV validated and imported.' : 'Knowledge record saved.'); ?></div>
    <?php endif; ?>

    <div id="oghma-tab" class="tab-content active">
        <div class="content-grid">
            <div class="content-section">
                <h2>Batch Upload</h2>
                <form method="post" enctype="multipart/form-data" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge-import">
                    <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                    <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($selectedInstallation); ?>">
                    <div>
                        <label for="csv_file">Select .csv file to upload:</label>
                        <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required>
                    </div>
                    <div class="button-group">
                        <button type="submit" class="action-button upload-csv">Upload CSV</button>
                        <a href="<?php echo almsivi_ui_h($managementBasePath); ?>/exports/oghma/example.csv" class="action-button download-csv">Download Example CSV</a>
                    </div>
                </form>
                <p>Uploaded topics are validated as UTF-8 CHIM-format CSV and scoped to this ALMSIVI installation. Existing user topics with the same key are revised safely.</p>

                <details class="oghma-tips">
                    <summary>Article editing tips</summary>
                    <ul>
                        <li>Write topic titles in lowercase and replace spaces with underscores &mdash; "House Redoran" becomes <code>house_redoran</code>.</li>
                        <li>Use <code>common</code> only as an article marker for public basic knowledge. Do not assign it to NPCs.</li>
                        <li>Access is inherited through ALMSIVI's Global &rarr; Core Profile &rarr; NPC hierarchy.</li>
                    </ul>
                </details>
            </div>

            <div class="content-section">
                <h2>Database Management</h2>
                <p>Verify imports:<br><b>Control Panel &rarr; Database Manager &rarr; knowledge_documents</b></p>
                <p>View conversation usage:<br><b>Control Panel &rarr; Oghma Audit</b></p>
                <div class="button-group destructive-controls">
                    <span class="status-control"><button type="button" class="btn-danger" disabled aria-disabled="true">Delete All Entries</button><?php echo almsivi_ui_feature_badge('config.oghma.destructive', true); ?></span>
                    <span class="status-control"><button type="button" class="btn-danger" disabled aria-disabled="true">Factory Reset Database</button><?php echo almsivi_ui_feature_badge('config.oghma.destructive', true); ?></span>
                </div>
                <p>The shipped factory Oghma dataset is the current source of truth and is read-only. User-created entries remain editable and soft-deletable.</p>
            </div>
        </div>

        <div class="full-width-section">
            <h2 id="entries"><span aria-hidden="true">&#x1F4CB;</span>&#160;Oghma Infinium Entries</h2>
            <div class="action-container">
                <button type="button" class="action-button add-new" data-oghma-new-open>Add New Entry</button>
                <form class="search-container" method="get" action="#entries">
                    <input type="hidden" name="embed" value="<?php echo $embedded ? '1' : '0'; ?>">
                    <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($selectedInstallation); ?>">
                    <input type="hidden" name="category" value="<?php echo almsivi_ui_h($filters['category']); ?>">
                    <input type="hidden" name="order" value="<?php echo almsivi_ui_h($filters['order']); ?>">
                    <input type="text" name="search" placeholder="Search topics..." value="<?php echo almsivi_ui_h($filters['search']); ?>">
                    <button class="action-button edit">Search</button>
                </form>
            </div>

            <div class="filter-section">
                <div class="category-filter">
                    <strong>Filter by Category:</strong><br>
                    <div class="filter-buttons">
                        <a class="alphabet-button<?php echo $filters['category'] === '' ? ' selected' : ''; ?>" href="<?php echo almsivi_ui_h($query(['category' => ''])); ?>">All Categories</a>
                        <?php foreach ($categories as $category): ?>
                            <a class="alphabet-button<?php echo $filters['category'] === $category ? ' selected' : ''; ?>" href="<?php echo almsivi_ui_h($query(['category' => $category])); ?>"><?php echo almsivi_ui_h((string) $category); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <strong>Sort Order:</strong><br>
                    <div class="sort-buttons">
                        <a class="alphabet-button<?php echo $filters['order'] === 'asc' ? ' selected' : ''; ?>" href="<?php echo almsivi_ui_h($query(['order' => 'asc'])); ?>"><span aria-hidden="true">&#x1F53C;</span>&#160;Ascending</a>
                        <a class="alphabet-button<?php echo $filters['order'] === 'desc' ? ' selected' : ''; ?>" href="<?php echo almsivi_ui_h($query(['order' => 'desc'])); ?>"><span aria-hidden="true">&#x1F53D;</span>&#160;Descending</a>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead><tr><th>Topic</th><th>Aliases</th><th>Topic Description (Advanced)</th><th>Knowledge Class (Advanced)</th><th>Topic Description (Basic)</th><th>Knowledge Class (Basic)</th><th>Tags</th><th>Category</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row):
                        $factory = ($row['provenance']['source'] ?? null) === 'factory-oghma';
                        $editPayload = [
                            'document_id' => $row['document_id'], 'topic' => $row['topic'], 'title' => $row['title'],
                            'aliases' => $row['aliases'], 'content' => $row['content'], 'knowledge_class' => $row['knowledge_class'],
                            'topic_desc_basic' => $row['topic_desc_basic'], 'knowledge_class_basic' => $row['knowledge_class_basic'],
                            'tags' => $row['tags'], 'category' => $row['category'],
                        ];
                    ?>
                        <tr>
                            <td><?php echo almsivi_ui_h($row['topic']); ?></td>
                            <td><?php echo trim((string) $row['aliases']) !== '' ? almsivi_ui_h($row['aliases']) : '<span class="empty-value">None</span>'; ?></td>
                            <td><?php echo nl2br(almsivi_ui_h($row['content'])); ?></td>
                            <td class="knowledge-cell"><?php $knowledgeBadges((string) $row['knowledge_class']); ?></td>
                            <td><?php echo nl2br(almsivi_ui_h($row['topic_desc_basic'])); ?></td>
                            <td class="knowledge-cell"><?php $knowledgeBadges((string) $row['knowledge_class_basic'], true); ?></td>
                            <td><?php echo trim((string) $row['tags']) !== '' ? nl2br(almsivi_ui_h($row['tags'])) : '<span class="empty-value">None</span>'; ?></td>
                            <td><?php echo almsivi_ui_h($row['category']); ?></td>
                            <td class="action-cell">
                                <?php if ($factory): ?>
                                    <button type="button" class="action-button edit" disabled aria-disabled="true" title="Factory catalog entries are source-controlled and read-only.">Edit</button>
                                    <span class="factory-label">Factory</span>
                                <?php else: ?>
                                    <button type="button" class="action-button edit" data-oghma-edit='<?php echo almsivi_ui_h((string) json_encode($editPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); ?>'>Edit</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?><tr><td colspan="9" class="empty-table">No entries found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<div id="editModal" class="modal-backdrop" hidden aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
        <div class="modal-header"><h2 class="modal-title" id="edit-modal-title">Edit Oghma Entry</h2></div>
        <div class="modal-body">
            <form id="oghma-edit-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge-revise">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <input type="hidden" name="document_id" id="edit-document-id">
                <?php $modalFields('edit'); ?>
                <div class="modal-footer">
                    <button type="submit" class="btn-save">Save Changes</button>
                    <button type="submit" class="btn-danger" form="oghma-delete-form" data-confirm="Delete this Oghma entry?">Delete</button>
                    <button type="button" class="btn-base btn-cancel" data-oghma-modal-close>Cancel</button>
                </div>
            </form>
            <form id="oghma-delete-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge-delete">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <input type="hidden" name="document_id" id="delete-document-id">
            </form>
        </div>
    </div>
</div>

<div id="newEntryModal" class="modal-backdrop" hidden aria-hidden="true">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="new-modal-title">
        <div class="modal-header"><h2 class="modal-title" id="new-modal-title">Add New Oghma Entry</h2></div>
        <div class="modal-body">
            <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/knowledge">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($selectedInstallation); ?>">
                <?php $modalFields('new', ['category' => 'lore']); ?>
                <div class="modal-footer">
                    <button type="submit" class="btn-save">Add Entry</button>
                    <button type="button" class="btn-base btn-cancel" data-oghma-modal-close>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script defer src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/oghma.js?v=<?php echo almsivi_ui_h((string) filemtime(__DIR__ . '/js/oghma.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
