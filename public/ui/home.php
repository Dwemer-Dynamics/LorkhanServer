<?php

declare(strict_types=1);

$pageTitle = 'ALMSIVI Home';
$topNavSection = 'home';
require __DIR__ . '/ui_bootstrap.php';
$dashboard = $uiRepository->dashboard();
$includeManagementStyles = false;
$additionalStylesheets = ['herika-home.css?v=' . (string) filemtime(__DIR__ . '/css/herika-home.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<div class="container home-version-info">
    <span>Server: ALMSIVIserver</span>
    <span>Database: PostgreSQL <?php echo almsivi_ui_h($dashboard['database_version']); ?></span>
    <span>Client: OpenMW 0.51 / Lua API 129</span>
</div>
<main class="container">
    <h1>Dwemer Dashboard</h1>

    <div class="dashboard-buttons">
        <a class="dashboard-btn" href="<?php echo almsivi_ui_h($webRoot); ?>/ui/core/config_hub.php"><span class="btn-icon" aria-hidden="true">⚙️</span> Configuration</a>
        <a class="dashboard-btn" href="<?php echo almsivi_ui_h($webRoot); ?>/ui/events-memories.php"><span class="btn-icon" aria-hidden="true">📖</span> Roleplay</a>
    </div>

    <section class="dashboard-container" aria-label="ALMSIVI dashboard">
        <article class="widget">
            <div class="widget-header"><h3>Current Playthrough</h3></div>
            <div class="widget-content">
                <?php if ($dashboard['current'] === null): ?>
                    <p class="empty-state">No OpenMW session has connected yet.</p>
                <?php else: ?>
                    <?php almsivi_ui_table([$dashboard['current']]); ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget">
            <div class="widget-header"><h3>Recent Dialogue</h3></div>
            <div class="widget-content widget-table"><?php almsivi_ui_table($dashboard['dialogue'], 'No dialogue has been recorded yet.'); ?></div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>ALMSIVI Stats</h3></div>
            <div class="widget-content widget-stats">
                <?php foreach ($dashboard['stats'] as $label => $value): ?>
                    <div class="stat-card"><span class="stat-value"><?php echo almsivi_ui_h($value); ?></span><span class="stat-label"><?php echo almsivi_ui_h($label); ?></span></div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Latest Diary Entry</h3></div>
            <div class="widget-content">
                <?php if ($dashboard['latest_narrative'] === null): ?>
                    <p class="empty-state">No diary or narrative entry is available yet.</p>
                <?php else: ?>
                    <h4><?php echo almsivi_ui_h($dashboard['latest_narrative']['title']); ?></h4>
                    <p><?php echo nl2br(almsivi_ui_h($dashboard['latest_narrative']['content'])); ?></p>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Recent Most Used Words</h3></div>
            <div class="widget-content word-cloud">
                <?php if ($dashboard['words'] === []): ?>
                    <p class="empty-state">Dialogue words will appear after the first conversation.</p>
                <?php else: ?>
                    <?php foreach ($dashboard['words'] as $word): ?>
                        <span class="word-chip"><?php echo almsivi_ui_h($word['word']); ?> · <?php echo almsivi_ui_h($word['uses']); ?></span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Morrowind Runtime Stats</h3></div>
            <div class="widget-content widget-stats">
                <?php foreach ($dashboard['runtime'] as $label => $value): ?>
                    <div class="stat-card"><span class="stat-value"><?php echo almsivi_ui_h($value); ?></span><span class="stat-label"><?php echo almsivi_ui_h($label); ?></span></div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
