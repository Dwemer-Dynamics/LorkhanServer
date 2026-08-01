<?php

declare(strict_types=1);

$pageTitle = 'ALMSIVI Control Panel';
$topNavSection = 'control';
$bodyClass = 'hub-page';
require __DIR__ . '/ui_bootstrap.php';
$tabs = [
    'diagnostics' => ['label' => 'Diagnostics', 'pages' => [
        'health-page' => ['🌲', 'Server Health', $webRoot . '/ui/diagnostics.php?embed=1'],
        'requests-page' => ['🔍', 'Request & Prompt Traces', $webRoot . '/ui/request_logs.php?embed=1'],
        'relationships-page' => ['🔗', 'Relationship Audit', $webRoot . '/ui/relationship_logs.php?embed=1'],
    ]],
    'monitoring' => ['label' => 'Monitoring', 'pages' => [
        'providers-page' => ['📊', 'Provider Attempts', $webRoot . '/ui/provider_attempts.php?embed=1'],
        'jobs-page' => ['💬', 'Workers & Jobs', $webRoot . '/ui/jobs.php?embed=1'],
    ]],
    'data-tools' => ['label' => 'Data & Tools', 'pages' => [
        'playthrough-page' => ['🎮', 'Playthrough Manager', $webRoot . '/ui/playthrough_manager.php?embed=1'],
        'backup-page' => ['🗄️', 'Backup & Health', $webRoot . '/ui/backup_health.php?embed=1'],
    ]],
];
$allTabIds = [];
foreach ($tabs as $group) $allTabIds = array_merge($allTabIds, array_keys($group['pages']));
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'health-page';
$activeTab = in_array($requestedTab, $allTabIds, true) ? $requestedTab : 'health-page';
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="d-flex flex-column">
    <div class="config-navigation" aria-label="Control Panel sections">
        <div class="tab-groups">
            <?php foreach ($tabs as $group): ?>
                <?php $groupActive = array_key_exists($activeTab, $group['pages']); ?>
                <section class="tab-group<?php echo $groupActive ? ' active' : ''; ?>">
                    <div class="tab-group-label"><?php echo almsivi_ui_h($group['label']); ?></div>
                    <div class="tab-buttons" role="tablist" aria-label="<?php echo almsivi_ui_h($group['label']); ?> pages">
                        <?php foreach ($group['pages'] as $tabId => [$icon, $label]): ?>
                            <button class="tab-button<?php echo $activeTab === $tabId ? ' active' : ''; ?>" type="button" data-tab="<?php echo almsivi_ui_h($tabId); ?>" aria-selected="<?php echo $activeTab === $tabId ? 'true' : 'false'; ?>">
                                <span class="tab-icon" aria-hidden="true"><?php echo almsivi_ui_h($icon); ?></span><span><?php echo almsivi_ui_h($label); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="content-area flex-grow-1 d-flex overflow-hidden">
        <?php foreach ($tabs as $group): foreach ($group['pages'] as $tabId => [, $label, $src]): ?>
            <section id="<?php echo almsivi_ui_h($tabId); ?>" class="tab-content<?php echo $activeTab === $tabId ? ' active' : ''; ?>">
                <div class="embed-wrap"><iframe class="embed" title="<?php echo almsivi_ui_h($label); ?>" loading="<?php echo $activeTab === $tabId ? 'eager' : 'lazy'; ?>" src="<?php echo $activeTab === $tabId ? almsivi_ui_h($src) : 'about:blank'; ?>"<?php echo $activeTab === $tabId ? '' : ' data-src="' . almsivi_ui_h($src) . '"'; ?>></iframe></div>
            </section>
        <?php endforeach; endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
