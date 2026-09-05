<?php

declare(strict_types=1);

$pageTitle = 'Home';
$topNavSection = 'home';
require __DIR__ . '/ui_bootstrap.php';
$dashboard = $uiRepository->dashboard();
$currentRows = $dashboard['current'] === null ? [] : [
    ['Stats' => 'State', 'Value' => $dashboard['current']['state'] ?? 'unknown'],
    ['Stats' => 'Last Connected', 'Value' => $dashboard['current']['created_at'] ?? 'unknown'],
    ['Stats' => 'OpenMW Version', 'Value' => $dashboard['current']['openmw_version'] ?? 'unknown'],
    ['Stats' => 'Lua API Revision', 'Value' => $dashboard['current']['lua_api_revision'] ?? 'unknown'],
    ['Stats' => 'Client Version', 'Value' => $dashboard['current']['client_version'] ?? 'unknown'],
    ['Stats' => 'Platform', 'Value' => $dashboard['current']['platform'] ?? 'unknown'],
    ['Stats' => 'Profile', 'Value' => $dashboard['current']['profile_name'] ?? 'unbound'],
    ['Stats' => 'Playthrough', 'Value' => $dashboard['current']['playthrough_name'] ?? 'unbound'],
];
$dialogueRows = array_map(static fn(array $row): array => [
    'Dialogue' => (string) ($row['speaker'] ?? 'Unknown') . ': ' . (string) ($row['text'] ?? ''),
    'Time (UTC)' => $row['emitted_at'] ?? '',
    'Delivery' => $row['delivery_state'] ?? 'unknown',
], $dashboard['dialogue']);
// Herika ranks its word cloud with a CDN d3 layout. LORKHAN keeps CSP-safe chips and
// reproduces the same "biggest word wins" reading order with five CSS-only tiers
// derived from the counts the dashboard query already returns.
$wordUses = array_map(static fn(array $row): int => (int) ($row['uses'] ?? 0), $dashboard['words']);
$wordLeast = $wordUses === [] ? 0 : min($wordUses);
$wordMost = $wordUses === [] ? 0 : max($wordUses);
$wordTotalUses = array_sum($wordUses);
// Herika sizes each word by log(count), which keeps a skewed vocabulary readable.
// The tier index reproduces that curve so one dominant word cannot flatten the rest.
$wordTier = static function (int $uses) use ($wordLeast, $wordMost): int {
    if ($wordMost <= $wordLeast) return 3;
    $span = log($wordMost + 1) - log($wordLeast + 1);
    $tier = 1 + (int) floor(4 * (log(max(0, $uses) + 1) - log($wordLeast + 1)) / $span);
    return max(1, min(5, $tier));
};
$includeManagementStyles = false;
$additionalStylesheets = ['herika-home.css?v=' . (string) filemtime(__DIR__ . '/css/herika-home.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<div class="container home-version-info">
    <div class="home-version-stack">
        <span>Server: LorkhanServer · PostgreSQL <?php echo lorkhan_ui_h($dashboard['database_version']); ?></span>
        <span>Client: OpenMW 0.51 / Lua API 129</span>
    </div>
    <div class="home-social-links" aria-label="Dwemer Dynamics links">
        <a href="https://www.youtube.com/@DwemerDynamics" target="_blank" rel="noopener noreferrer" title="Dwemer Dynamics on YouTube"><img src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/youtube.png" alt="YouTube"></a>
        <a href="https://discord.gg/NDn9qud2ug" target="_blank" rel="noopener noreferrer" title="Dwemer Dynamics Discord"><img src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/discord.png" alt="Discord"></a>
        <a href="https://patreon.com/DwemerDynamics" target="_blank" rel="noopener noreferrer" title="Dwemer Dynamics Patreon"><img src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/patreon.png" alt="Patreon"></a>
    </div>
</div>
<main class="container">
    <h1>Dwemer Dashboard</h1>

    <div class="dashboard-buttons">
        <a class="dashboard-btn" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/quickstart.php"><span class="btn-icon" aria-hidden="true">&#9889;</span> Quickstart</a>
        <a class="dashboard-btn" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/core/config_hub.php"><span class="btn-icon" aria-hidden="true">⚙️</span> Configuration</a>
        <a class="dashboard-btn" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/events-memories.php"><span class="btn-icon" aria-hidden="true">📖</span> Roleplay</a>
    </div>

    <section class="dashboard-container" aria-label="LORKHAN dashboard">
        <article class="widget">
            <div class="widget-header"><h3>Current Playthrough</h3></div>
            <div class="widget-content">
                <?php if ($dashboard['current'] === null): ?>
                    <p class="empty-state">No OpenMW session has connected yet.</p>
                <?php else: ?>
                    <h4>World Information</h4>
                    <div class="widget-table"><?php lorkhan_ui_table($currentRows); ?></div>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget">
            <div class="widget-header"><h3>Recent Dialogue</h3></div>
            <div class="widget-content widget-table widget-dialogue-table"><?php lorkhan_ui_table($dialogueRows, 'No dialogue has been recorded yet.'); ?></div>
        </article>

        <article class="widget">
            <div class="widget-header"><h3>LORKHAN Stats</h3></div>
            <div class="widget-content widget-stats">
                <?php foreach ($dashboard['stats'] as $label => $value): ?>
                    <div class="stat-card"><span class="stat-value"><?php echo lorkhan_ui_h($value); ?></span><span class="stat-label"><?php echo lorkhan_ui_h($label); ?></span></div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Latest Diary Entry</h3></div>
            <div class="widget-content">
                <?php if ($dashboard['latest_narrative'] === null): ?>
                    <p class="empty-state">No diary or narrative entry is available yet.</p>
                <?php else: ?>
                    <article class="diary-entry">
                        <h4><?php echo lorkhan_ui_h($dashboard['latest_narrative']['title']); ?></h4>
                        <p class="diary-entry-body"><?php echo nl2br(lorkhan_ui_h($dashboard['latest_narrative']['content'])); ?></p>
                    </article>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Recent Most Used Words</h3></div>
            <div class="widget-content">
                <?php if ($dashboard['words'] === []): ?>
                    <p class="empty-state">Dialogue words will appear after the first conversation.</p>
                <?php else: ?>
                    <div class="word-cloud-container">
                        <p class="word-count-display"><?php echo count($dashboard['words']); ?> words · <?php echo (int) $wordTotalUses; ?> uses</p>
                        <div class="word-cloud">
                            <?php foreach ($dashboard['words'] as $word): ?>
                                <span class="word-chip word-tier-<?php echo $wordTier((int) ($word['uses'] ?? 0)); ?>"><span class="word-chip-text"><?php echo lorkhan_ui_h($word['word']); ?></span><span class="word-chip-count"><?php echo lorkhan_ui_h($word['uses']); ?></span></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Morrowind Runtime Stats</h3></div>
            <div class="widget-content widget-stats">
                <?php foreach ($dashboard['runtime'] as $label => $value): ?>
                    <div class="stat-card"><span class="stat-value"><?php echo lorkhan_ui_h($value); ?></span><span class="stat-label"><?php echo lorkhan_ui_h($label); ?></span></div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
