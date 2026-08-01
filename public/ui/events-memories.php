<?php

declare(strict_types=1);

$pageTitle = 'ALMSIVI Roleplay';
$topNavSection = 'roleplay';
$bodyClass = 'hub-page';
require __DIR__ . '/ui_bootstrap.php';
$roleplay = $uiRepository->roleplay();
$allowedTabs = ['eventlog-tab', 'responses-tab', 'memories-tab', 'relationships-tab', 'narratives-tab', 'knowledge-tab'];
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'eventlog-tab';
$activeTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'eventlog-tab';
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="container-fluid events-memories-page">
    <div class="tab-container">
        <?php include __DIR__ . '/tmpl/events_memories_navigation.php'; ?>

        <?php
        $panels = [
            'eventlog-tab' => ['Events', $roleplay['events'], 'No source events have been recorded yet.'],
            'responses-tab' => ['AI Responses', $roleplay['responses'], 'No AI dialogue has been recorded yet.'],
            'memories-tab' => ['Memories', $roleplay['memories'], 'No memories are available yet.'],
            'relationships-tab' => ['Relationships', $roleplay['relationships'], 'No relationships are available yet.'],
            'narratives-tab' => ['Narratives', $roleplay['narratives'], 'No narratives are available yet.'],
            'knowledge-tab' => ['Knowledge Records', $roleplay['knowledge'], 'No world knowledge is available yet.'],
        ];
        foreach ($panels as $tabId => [$heading, $rows, $emptyMessage]):
        ?>
            <section id="<?php echo almsivi_ui_h($tabId); ?>" class="tab-content<?php echo $activeTab === $tabId ? ' active' : ''; ?>">
                <div class="tab-panel-inner"><h2><?php echo almsivi_ui_h($heading); ?></h2><?php almsivi_ui_table($rows, $emptyMessage); ?></div>
            </section>
        <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
