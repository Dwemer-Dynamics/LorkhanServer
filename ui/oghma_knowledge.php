<?php

declare(strict_types=1);

$pageTitle = 'NPC Oghma Knowledge';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page oghma-knowledge-shell';
require __DIR__ . '/ui_bootstrap.php';
$installationId = trim((string) ($_GET['installation_id'] ?? ''));
$profileId = trim((string) ($_GET['profile_id'] ?? ''));
if (preg_match('/^[0-9a-f-]{36}$/D', $installationId) !== 1 || preg_match('/^[0-9a-f-]{36}$/D', $profileId) !== 1) {
    http_response_code(404);
    exit;
}
try {
    $viewer = $productRepository->oghmaKnowledgeForProfile($installationId, $profileId, [
        'search' => $_GET['search'] ?? '', 'category' => $_GET['category'] ?? '',
        'access' => $_GET['access'] ?? 'all', 'page' => $_GET['page'] ?? 1,
        'playthrough_id' => $_GET['playthrough_id'] ?? '',
    ]);
} catch (Throwable) {
    http_response_code(404);
    exit;
}
$filters = $viewer['filters'];
$playthroughId = (string) ($viewer['playthrough']['playthrough_id'] ?? '');
$additionalStylesheets = ['herika-oghma-runtime.css?v=' . (string) filemtime(__DIR__ . '/css/herika-oghma-runtime.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';

// Keep every navigation action scoped to the same NPC; filter changes start at page one.
$pageUrl = static function (array $changes = []) use ($installationId, $profileId, $playthroughId, $filters, $embedded): string {
    return '?' . http_build_query(array_merge($filters, [
        'installation_id' => $installationId, 'profile_id' => $profileId,
        'playthrough_id' => $playthroughId,
        'page' => 1, 'embed' => $embedded ? '1' : '0',
    ], $changes));
};
?>
<main class="npc-knowledge-reader">
    <header class="knowledge-header"><h1>Oghma Knowledge: <?php echo lorkhan_ui_h($viewer['profile']['name']); ?></h1></header>
    <div class="knowledge-body">
        <form class="knowledge-filters" method="get">
            <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
            <input type="hidden" name="profile_id" value="<?php echo lorkhan_ui_h($profileId); ?>">
            <input type="hidden" name="playthrough_id" value="<?php echo lorkhan_ui_h($playthroughId); ?>">
            <input type="hidden" name="embed" value="<?php echo $embedded ? '1' : '0'; ?>">
            <div><label for="knowledge-search">Search Topics &amp; Descriptions:</label><input type="search" id="knowledge-search" name="search" maxlength="100" value="<?php echo lorkhan_ui_h($filters['search']); ?>" placeholder="Search knowledge articles..."></div>
            <div><label for="knowledge-category">Category:</label><select id="knowledge-category" name="category"><option value="">All Categories</option><?php foreach ($viewer['categories'] as $category): ?><option value="<?php echo lorkhan_ui_h($category); ?>"<?php echo $filters['category'] === $category ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($category); ?></option><?php endforeach; ?></select></div>
            <div class="knowledge-filter-actions"><button type="submit" class="action-button">Apply Filters</button><a class="action-button" href="<?php echo lorkhan_ui_h($pageUrl(['search' => '', 'category' => '', 'access' => 'all'])); ?>">Clear</a></div>
            <details class="knowledge-access"<?php echo $filters['access'] !== 'all' ? ' open' : ''; ?>><summary>Knowledge access</summary>
                <label for="knowledge-access">Show level:</label><select id="knowledge-access" name="access"><?php foreach (['all' => 'All accessible', 'advanced' => 'Advanced', 'basic' => 'Basic'] as $value => $label): ?><option value="<?php echo $value; ?>"<?php echo $filters['access'] === $value ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select>
                <p>Effective tags: <?php echo lorkhan_ui_h(implode(', ', $viewer['knowledge_tags']) ?: 'None'); ?>.</p>
                <?php if ($playthroughId !== ''): ?><p>Playthrough: <?php echo lorkhan_ui_h($viewer['playthrough']['name']); ?>.</p><?php endif; ?>
                <p><?php echo (int) $viewer['counts']['advanced']; ?> Advanced &middot; <?php echo (int) $viewer['counts']['basic']; ?> Basic &middot; <?php echo (int) $viewer['counts']['denied']; ?> Denied. Access uses the Global &rarr; Core Profile &rarr; NPC settings and each article's knowledge classes.</p>
            </details>
        </form>
        <div class="knowledge-table" role="region" aria-label="Accessible Oghma articles" tabindex="0">
            <table><thead><tr><th>Topic</th><th>Knowledge Level</th><th>Description</th></tr></thead><tbody>
                <?php foreach ($viewer['items'] as $item): ?>
                <tr><td><strong><?php echo lorkhan_ui_h($item['topic']); ?></strong>
                    <?php $classes = $item['access_level'] === 'advanced' ? $item['knowledge_class'] : $item['knowledge_class_basic']; ?>
                    <div class="knowledge-topic-meta">
                    <?php if ($item['category'] !== ''): ?><span class="knowledge-topic-class">&#x1F4C1; <?php echo lorkhan_ui_h($item['category']); ?></span><?php endif; ?>
                    <?php foreach (['class' => $classes, 'tag' => $item['tags']] as $kind => $values): ?><?php foreach (array_filter(array_map('trim', explode(',', (string) $values))) as $value): ?><span class="knowledge-topic-<?php echo $kind; ?>"><?php echo $kind === 'tag' ? '&#x1F3F7; ' : ($item['access_level'] === 'advanced' ? '&#x1F538; ' : '&#x1F539; '); ?><?php echo lorkhan_ui_h($value); ?></span><?php endforeach; ?><?php endforeach; ?>
                    </div>
                    <?php if (trim((string) $item['aliases']) !== ''): ?><div class="knowledge-topic-meta">Aliases: <?php echo lorkhan_ui_h($item['aliases']); ?></div><?php endif; ?>
                </td><td><span class="knowledge-level <?php echo lorkhan_ui_h($item['access_level']); ?>"><?php echo lorkhan_ui_h(ucfirst($item['access_level'])); ?></span></td><td class="knowledge-description"><?php echo lorkhan_ui_h(html_entity_decode(strip_tags($item['effective_content']), ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php if ($viewer['items'] === []): ?><div class="knowledge-empty"><p>No knowledge articles found matching the current filters.</p><p><small>Try adjusting your search terms, category or knowledge level.</small></p></div><?php endif; ?>
        <nav class="knowledge-pagination" aria-label="NPC knowledge pages"><span><?php echo number_format((int) $viewer['total']); ?> articles &middot; Page <?php echo (int) $viewer['page']; ?> of <?php echo (int) $viewer['pages']; ?></span><div><?php if ($viewer['page'] > 1): ?><a class="action-button" href="<?php echo lorkhan_ui_h($pageUrl(['page' => $viewer['page'] - 1])); ?>">Previous</a><?php endif; ?><?php if ($viewer['page'] < $viewer['pages']): ?><a class="action-button" href="<?php echo lorkhan_ui_h($pageUrl(['page' => $viewer['page'] + 1])); ?>">Next</a><?php endif; ?></div></nav>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
