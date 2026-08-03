<?php

declare(strict_types=1);

$pageTitle = 'ALMSIVI Control Panel';
$topNavSection = 'control';
$BODY_CLASS = 'hub-page';
require __DIR__ . '/ui_bootstrap.php';
$tabs = [
    'diagnostics' => ['label' => 'Diagnostics', 'pages' => [
        'server-logs-page' => ['📄', 'Server Logs', $webRoot . '/ui/server_logs.php?embed=1'],
        'oghma-audit-page' => ['📖', 'Oghma Audit', $webRoot . '/ui/oghma_audit.php?embed=1'],
        'health-page' => ['🌲', 'Server Health', $webRoot . '/ui/diagnostics.php?embed=1'],
        'requests-page' => ['🔍', 'Request & Prompt Traces', $webRoot . '/ui/request_logs.php?embed=1'],
        'relationships-page' => ['🔗', 'Relationship Audit', $webRoot . '/ui/relationship_logs.php?embed=1'],
    ]],
    'monitoring' => ['label' => 'Monitoring', 'pages' => [
        'usage-page' => ['📊', 'Provider Usage', $webRoot . '/ui/provider_usage.php?embed=1'],
        'queue-page' => ['📩', 'Response Queue', $webRoot . '/ui/response_queue.php?embed=1'],
        'providers-page' => ['📊', 'Provider Attempts', $webRoot . '/ui/provider_attempts.php?embed=1'],
        'jobs-page' => ['💬', 'Workers & Jobs', $webRoot . '/ui/jobs.php?embed=1'],
    ]],
    'data-tools' => ['label' => 'Data & Tools', 'pages' => [
        'cache-page' => ['🗃️', 'Cache Browser', $webRoot . '/ui/cache_browser.php?embed=1'],
        'playthrough-page' => ['🎮', 'Playthrough Manager', $webRoot . '/ui/playthrough_manager.php?embed=1'],
        'database-page' => ['🗄️', 'Database Manager', $webRoot . '/ui/database_manager.php?embed=1'],
    ]],
];
$allTabIds = [];
foreach ($tabs as $group) $allTabIds = array_merge($allTabIds, array_keys($group['pages']));
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'health-page';
$activeTab = in_array($requestedTab, $allTabIds, true) ? $requestedTab : 'health-page';
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="almsivi-config-hub" data-config-hub data-active-tab="<?php echo almsivi_ui_h($activeTab); ?>">
    <div class="config-navigation" aria-label="Control Panel sections">
        <div class="tab-groups">
            <?php foreach ($tabs as $group): ?>
                <?php $groupActive = array_key_exists($activeTab, $group['pages']); ?>
                <section class="tab-group<?php echo $groupActive ? ' active' : ''; ?>">
                    <div class="tab-group-label"><?php echo almsivi_ui_h($group['label']); ?></div>
                    <div class="tab-buttons" role="tablist" aria-label="<?php echo almsivi_ui_h($group['label']); ?> pages">
                        <?php foreach ($group['pages'] as $tabId => [$icon, $label]): ?>
                            <button class="tab-button" type="button" data-tab="<?php echo almsivi_ui_h($tabId); ?>" data-category="control" aria-selected="<?php echo $activeTab === $tabId ? 'true' : 'false'; ?>">
                                <span class="tab-icon" aria-hidden="true"><?php echo almsivi_ui_h($icon); ?></span><span><?php echo almsivi_ui_h($label); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="almsivi-hub-content">
        <?php foreach ($tabs as $group): foreach ($group['pages'] as $tabId => [, $label, $src]): ?>
            <section id="<?php echo almsivi_ui_h($tabId); ?>" class="almsivi-hub-panel" data-tab-panel>
                <iframe title="<?php echo almsivi_ui_h($label); ?>" loading="<?php echo $activeTab === $tabId ? 'eager' : 'lazy'; ?>" src="<?php echo $activeTab === $tabId ? almsivi_ui_h($src) : 'about:blank'; ?>" data-src="<?php echo almsivi_ui_h($src); ?>"></iframe>
            </section>
        <?php endforeach; endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
