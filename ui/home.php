<?php

declare(strict_types=1);

use LorkhanServer\Application\MorrowindCalendar;

$pageTitle = 'Home';
$topNavSection = 'home';
require __DIR__ . '/ui_bootstrap.php';
// Herika checks on Home visits with a ten-minute cooldown; the native worker does the expensive dump.
try{(new \LorkhanServer\Infrastructure\ManagementRepository($database))->queueDatabaseBackup(true);}
catch(Throwable){error_log('Lorkhan automatic backup scheduling unavailable.');}
$dashboard = $uiRepository->dashboard();
$serverVersionDisplay = trim((string) @file_get_contents(dirname(__DIR__) . '/version.txt'));
if (!preg_match('/^[a-f0-9]{7,40}$/D', $serverVersionDisplay)) $serverVersionDisplay = 'Development';
$pluginVersionDisplay = trim((string) ($dashboard['current']['client_version'] ?? '')) ?: 'N/A';
// Match the dashboard's readable dates and its UTC column label, regardless of database timezone.
$dashboardTime = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') return 'Unknown';
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('jS F, Y, H:i');
    } catch (Throwable) {
        return 'Unknown';
    }
};
$currentRows = $dashboard['current'] === null ? [] : [
    ['Stats' => 'Player Name', 'Value' => $dashboard['current']['player_name'] ?? 'Not recorded'],
    ['Stats' => 'Last Played (UTC)', 'Value' => $dashboardTime($dashboard['current']['last_played'] ?? null)],
    ['Stats' => 'Current In-Game Time', 'Value' => MorrowindCalendar::parse($dashboard['current']['calendar_data'] ?? null)['label'] ?? 'Not recorded'],
    ['Stats' => 'LORKHAN Mode', 'Value' => strtoupper($dashboard['current']['dialogue_mode'] ?? 'Not recorded')],
    ['Stats' => 'LORKHAN Active Model', 'Value' => ucfirst($dashboard['current']['model_slot'] ?? 'standard')],
    ['Stats' => 'Compact Chat', 'Value' => 'ENABLED'],
    ['Stats' => 'Background Processor', 'Value' => \LorkhanServer\Infrastructure\BackgroundWorkerStatus::read()],
];
$statistics = $dashboard['statistics'];
$playerCategories = [];
foreach (['Vitals' => 'player_stats', 'Attributes' => 'player_attributes', 'Skills' => 'player_skills'] as $category => $field) {
    $values = $dashboard['current'][$field] ?? [];
    foreach (is_array($values) ? $values : [] as $name => $value) {
        // Only observed numeric stats are shown; unavailable values are not manufactured as zero.
        $number = is_array($value) ? ($value['current'] ?? $value['modified'] ?? $value['base'] ?? null) : $value;
        if (!is_int($number) && !is_float($number)) continue;
        $label = ['mediumarmor'=>'Medium Armor','heavyarmor'=>'Heavy Armor','lightarmor'=>'Light Armor',
            'bluntweapon'=>'Blunt Weapon','longblade'=>'Long Blade','shortblade'=>'Short Blade','handtohand'=>'Hand-to-Hand'][$name] ?? ucfirst((string) $name);
        $playerCategories[$category][$label] = number_format($number, $number == (int) $number ? 0 : 1);
    }
}
$dialogueRows = array_map(static fn(array $row): array => [
    'Dialogue' => (string) ($row['text'] ?? ''),
    'Time (UTC)' => $dashboardTime($row['emitted_at'] ?? null),
    'Tamrielic Time' => MorrowindCalendar::parse($row['calendar_data'] ?? null)['label'] ?? 'Not recorded',
], $dashboard['dialogue']);
$installation = (string) ($dashboard['current']['installation_id'] ?? '');
$scopeQuery = http_build_query(['installation_id' => $installation, 'playthrough_id' => $dashboard['current']['playthrough_id'] ?? '']);
$homeCharacterState = $installation === '' ? ['bindings'=>[], 'pending_associations'=>[]] : (new \LorkhanServer\Infrastructure\CharacterPlaythroughRepository($database))->state($installation);
$homePlaythroughs = $installation === '' ? [] : array_slice($uiRepository->rows('playthroughs', null, ['installation_id'=>$installation, 'page'=>1]), 0, 100);
$diary = $dashboard['latest_diary'];
$diaryAudioReady = $diary !== null && $installation !== '';
$includeManagementStyles = false;
$additionalStylesheets = ['herika-home.css?v=' . (string) filemtime(__DIR__ . '/css/herika-home.css'), 'playthrough-home.css?v=' . (string) filemtime(__DIR__ . '/css/playthrough-home.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<div class="container home-version-info">
    <div class="server-version-info">
        Server: <?= lorkhan_ui_h($serverVersionDisplay) ?>
        Plugin: <?= lorkhan_ui_h($pluginVersionDisplay) ?>
    </div>
    <div class="home-social-links" aria-label="Dwemer Dynamics links">
        <a href="https://www.youtube.com/@DwemerDynamics" target="_blank" rel="noopener noreferrer" title="Dwemer Dynamics on YouTube"><img src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/youtube.png" alt="YouTube"></a>
        <a href="https://discord.gg/NDn9qud2ug" target="_blank" rel="noopener noreferrer" title="Dwemer Dynamics Discord"><img src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/discord.png" alt="Discord"></a>
        <a href="https://patreon.com/DwemerDynamics" target="_blank" rel="noopener noreferrer" title="Dwemer Dynamics Patreon"><img src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/images/patreon.png" alt="Patreon"></a>
    </div>
</div>
<main class="container">
    <h1>Dwemer Dashboard</h1>
    <?php include __DIR__.'/tmpl/playthrough_home_controls.php'; ?>

    <div class="dashboard-buttons">
        <a class="dashboard-btn" href="https://docs.google.com/spreadsheets/d/1UtAR_r18wskmTMMsg8IlhVvr1Fn9tHvRJT8drH6RuzY/edit?gid=1257158105#gid=1257158105" target="_blank" rel="noopener noreferrer"><span class="btn-icon" aria-hidden="true">🥇</span> AI/LLM Tier List</a>
        <a class="dashboard-btn" href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/quickstart.php"><span class="btn-icon" aria-hidden="true">&#x1F680;</span> Go to Quickstart</a>
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
                    <p class="home-observed-note">Mode, time and player details reflect the last recorded game context. Model shows the selected slot; NPC routing may use its configured fallback.</p>
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
                <?php foreach ($statistics['counts'] as $label => $value): ?>
                    <?php if ($label === 'Total Events'): ?><button type="button" class="stat-card clickable-card" data-home-open="home-events"><span class="stat-value"><?= number_format($value) ?></span><span class="stat-label"><?= lorkhan_ui_h($label) ?></span></button>
                    <?php else: ?><div class="stat-card"><span class="stat-value"><?= number_format($value) ?></span><span class="stat-label"><?= lorkhan_ui_h($label) ?></span></div><?php endif; ?>
                <?php endforeach; ?>
                <button type="button" class="stat-card double-width clickable-card" data-home-llm title="Click to cycle through 24 hours, 72 hours, one week and lifetime" aria-live="polite">
                    <?php foreach ($statistics['llm'] as $period => $counts): $total = (int) $counts['total']; $success = (int) $counts['success']; ?>
                        <span data-home-period<?= $period === '24h' ? '' : ' hidden' ?>><span class="stat-value"><?= $success ?>/<?= $total ?> (<?= $total === 0 ? 0 : round(100 * $success / $total) ?>%)</span><span class="stat-label">LLM Requests Success Rate (<?= lorkhan_ui_h($period) ?>)</span></span>
                    <?php endforeach; ?>
                </button>
                <?php foreach (['locations' => 'Travel To Locations', 'mods' => 'Detected Mods'] as $key => $label): ?>
                    <button type="button" class="stat-card double-width clickable-card" data-home-open="home-<?= $key ?>"><span class="stat-value"><?= number_format(count($statistics[$key])) ?></span><span class="stat-label"><?= $label ?></span></button>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Latest Diary Entry</h3></div>
            <div class="widget-content">
                <?php if ($diary === null): ?>
                    <p class="empty-state">No diary entries found yet.</p>
                <?php else: ?>
                    <div class="diary-entry" data-reader data-diary-endpoint="<?= lorkhan_ui_h($managementBasePath.'/api/v1/diary-audio') ?>" data-installation="<?= lorkhan_ui_h($installation) ?>" data-csrf="<?= lorkhan_ui_h($csrf) ?>">
                        <article data-reader-entry data-narrative-id="<?= lorkhan_ui_h($diary['narrative_id']) ?>">
                            <div class="diary-paper"><div class="diary-author"><?= lorkhan_ui_h($diary['author']) ?></div><div class="diary-entry-body" data-reader-text><?= nl2br(lorkhan_ui_h($diary['content'])) ?></div></div>
                            <div class="diary-audio-controls">
                                <button type="button" class="dashboard-btn" data-reader-play<?= $diaryAudioReady ? '' : ' disabled' ?> title="<?= $diaryAudioReady ? 'Uses the diary author’s configured voice. Your speech provider may charge.' : 'Configure a TTS connector and voice in TTS Studio.' ?>">▶ Play Audio</button>
                                <button type="button" class="dashboard-btn" data-reader-stop hidden>Stop Audio</button>
                            </div>
                        </article>
                        <p data-reader-status role="status" aria-live="polite"></p><audio data-reader-audio controls preload="none" hidden></audio>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Recent Relationship Changes</h3><a class="relationship-change-link" href="<?= lorkhan_ui_h($webRoot.'/ui/relationship_logs.php?'.$scopeQuery.'#relationship-history') ?>">View full timeline</a></div>
            <div class="widget-content">
                <?php if ($dashboard['relationships'] === []): ?><p class="relationship-change-empty">No relationship changes recorded yet.</p>
                <?php else: ?><ul class="relationship-change-list" role="list">
                    <?php foreach ($dashboard['relationships'] as $change):
                        $before = json_decode((string) ($change['before_value'] ?? '{}'), true) ?: [];
                        $after = json_decode((string) ($change['after_value'] ?? '{}'), true) ?: [];
                        $delta = isset($after['affinity']) && is_numeric($after['affinity']) ? (int) $after['affinity'] - (int) ($before['affinity'] ?? 0) : 0;
                        $typeChanged = ($after['relationship_type'] ?? '') !== ($before['relationship_type'] ?? '');
                        $badge = $delta > 0 ? '+'.$delta : ($delta < 0 ? (string) $delta : ($typeChanged ? 'Type' : 'Updated'));
                        $badgeClass = $delta > 0 ? 'is-up' : ($delta < 0 ? 'is-down' : 'is-type'); ?>
                        <li class="relationship-change-item"><span class="relationship-change-delta <?= $badgeClass ?>" aria-label="<?= lorkhan_ui_h($delta === 0 ? 'Relationship updated' : 'Affinity change '.$badge) ?>"><?= lorkhan_ui_h($badge) ?></span><div class="relationship-change-body">
                            <p class="relationship-change-reason" title="<?= lorkhan_ui_h($change['reason']) ?>"><?= lorkhan_ui_h($change['reason']) ?></p>
                            <p class="relationship-change-meta"><span class="relationship-change-npc"><?= lorkhan_ui_h($change['owner']) ?></span><span aria-hidden="true">→</span><span class="relationship-change-target"><?= lorkhan_ui_h($change['target']) ?></span>
                            <?php if (($after['relationship_type'] ?? '') !== ''): ?><span class="relationship-change-tier"><?= lorkhan_ui_h(ucfirst((string) $after['relationship_type'])) ?></span><?php endif; ?>
                            <time class="relationship-change-time" datetime="<?= lorkhan_ui_h($change['created_at']) ?>" title="<?= lorkhan_ui_h($dashboardTime($change['created_at'])) ?> UTC"><?= lorkhan_ui_h((new DateTimeImmutable($change['created_at']))->setTimezone(new DateTimeZone('UTC'))->format('j M, H:i')) ?></time></p>
                        </div></li>
                    <?php endforeach; ?>
                </ul><?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Recent Most Used Words</h3></div>
            <div class="widget-content">
                <?php if ($dashboard['words'] === []): ?>
                    <p class="empty-state">Dialogue words will appear after the first conversation.</p>
                <?php else: ?>
                    <div class="word-cloud-container">
                        <p class="word-count-display" id="word-count-display" aria-live="polite"></p>
                        <svg id="word-cloud" role="group" aria-label="Most used dialogue words" data-words="<?= lorkhan_ui_h(json_encode($dashboard['words'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) ?>"></svg>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="widget widget-wide">
            <div class="widget-header"><h3>Morrowind Stats</h3></div>
            <div class="widget-content">
                <?php if ($playerCategories === []): ?><p class="empty-state">No player statistics have been recorded yet.</p>
                <?php else: ?><p class="home-observed-note">Last recorded: <?= lorkhan_ui_h($dashboardTime($dashboard['current']['observed_at'])) ?> UTC. These are observed OpenMW values, not Skyrim lifetime counters.</p>
                    <div class="home-game-stats"><?php foreach ($playerCategories as $category => $values): ?><section class="home-stats-category"><h4><?= lorkhan_ui_h($category) ?></h4><dl><?php foreach ($values as $label => $value): ?><div><dt><?= lorkhan_ui_h($label) ?></dt><dd><?= lorkhan_ui_h($value) ?></dd></div><?php endforeach; ?></dl></section><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        </article>
    </section>
</main>
<?php foreach (['events' => 'Event Types', 'locations' => 'Available Locations', 'mods' => 'Detected Mods'] as $key => $title): ?>
<dialog id="home-<?= $key ?>" class="home-stat-dialog" aria-labelledby="home-<?= $key ?>-title">
    <header><h3 id="home-<?= $key ?>-title"><?= $title ?></h3><button type="button" data-home-close aria-label="Close <?= $title ?>">×</button></header>
    <?php if ($key === 'locations'): ?><p>Named locations observed by OpenMW. Locations use cell names instead of Skyrim FormIDs.</p><?php elseif ($key === 'mods'): ?><p>Active content files from the latest recorded OpenMW load order.</p><?php endif; ?>
    <div class="home-modal-table"><table><thead><tr><?php foreach (match ($key) {'events'=>['Event Type','Count'],'locations'=>['Name','Cell','Region'],default=>['Load Order','Plugin Name','Type']} as $label): ?><th scope="col"><?= $label ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($statistics[$key] as $row): ?><tr><?php foreach (match ($key) {'events'=>[$row['type'],number_format((int) $row['count'])],'locations'=>[$row['name'],$row['cell_key'],$row['region']??''],default=>[(string) $row['load_order'],$row['content_file'],'OpenMW content']} as $cell): ?><td><?= lorkhan_ui_h($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if ($statistics[$key] === []): ?><tr><td colspan="<?= $key === 'events' ? 2 : 3 ?>">No <?= strtolower($title) ?> recorded yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</dialog>
<?php endforeach; ?>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/lib/ui/d3/d3.v7.9.0.min.js"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/lib/ui/d3/d3.layout.cloud.v1.2.7.js"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/home-dashboard.js?v=<?= filemtime(__DIR__.'/js/home-dashboard.js') ?>"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/roleplay-reader.js?v=<?= filemtime(__DIR__.'/js/roleplay-reader.js') ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
