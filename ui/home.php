<?php

declare(strict_types=1);

use LorkhanServer\Application\MorrowindCalendar;
use LorkhanServer\Application\SpeechPreviewCatalog;

$pageTitle = 'Home';
$topNavSection = 'home';
require __DIR__ . '/ui_bootstrap.php';
$dashboard = $uiRepository->dashboard();
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
    ['Stats' => 'State', 'Value' => $dashboard['current']['state'] ?? 'unknown'],
    ['Stats' => 'Last Connected (UTC)', 'Value' => $dashboardTime($dashboard['current']['created_at'] ?? null)],
    ['Stats' => 'OpenMW Version', 'Value' => $dashboard['current']['openmw_version'] ?? 'unknown'],
    ['Stats' => 'Lua API Revision', 'Value' => $dashboard['current']['lua_api_revision'] ?? 'unknown'],
    ['Stats' => 'Client Version', 'Value' => $dashboard['current']['client_version'] ?? 'unknown'],
    ['Stats' => 'Platform', 'Value' => $dashboard['current']['platform'] ?? 'unknown'],
    ['Stats' => 'Profile', 'Value' => $dashboard['current']['profile_name'] ?? 'unbound'],
    ['Stats' => 'Playthrough', 'Value' => $dashboard['current']['playthrough_name'] ?? 'unbound'],
];
$dialogueRows = array_map(static fn(array $row): array => [
    'Dialogue' => (string) ($row['text'] ?? ''),
    'Time (UTC)' => $dashboardTime($row['emitted_at'] ?? null),
    'Tamrielic Time' => MorrowindCalendar::parse($row['calendar_data'] ?? null)['label'] ?? 'Not recorded',
], $dashboard['dialogue']);
$installation = (string) ($dashboard['current']['installation_id'] ?? '');
$scopeQuery = http_build_query(['installation_id' => $installation, 'playthrough_id' => $dashboard['current']['playthrough_id'] ?? '']);
$diary = $dashboard['latest_diary'];
$preview = [];
if ($diary !== null && $installation !== '') {
    $narrator = $productRepository->narratorProfileForInstallation($installation);
    $preview = SpeechPreviewCatalog::options(
        $productRepository->listRevisioned('tts_provider', $installation), $productRepository->connectorVoiceCatalog(),
        (string) ($config['voice_storage_path'] ?? ''),
        (string) ($productRepository->connectorForInstallation($installation, 'tts_provider')['configuration_id'] ?? ''),
        SpeechPreviewCatalog::narratorVoice($narrator), SpeechPreviewCatalog::narratorConnector($narrator));
}
$diaryAudioReady = ($preview['default_connector_id'] ?? '') !== '' && ($preview['default_voice'] ?? '') !== '';
$includeManagementStyles = false;
$additionalStylesheets = ['herika-home.css?v=' . (string) filemtime(__DIR__ . '/css/herika-home.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<div class="container home-version-info">
    <div class="home-version-stack">
        <span>Server: LorkhanServer · PostgreSQL <?php echo lorkhan_ui_h($dashboard['database_version']); ?></span>
        <span>Client: <?php echo $dashboard['current'] === null ? 'Not connected' : lorkhan_ui_h('OpenMW '.$dashboard['current']['openmw_version'].' / Lua API '.$dashboard['current']['lua_api_revision']); ?></span>
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
                <?php if ($diary === null): ?>
                    <p class="empty-state">No diary entries found yet.</p>
                <?php else: ?>
                    <div class="diary-entry" data-reader data-preview-endpoint="<?= lorkhan_ui_h($managementBasePath.'/api/v1/tts-previews') ?>" data-installation="<?= lorkhan_ui_h($installation) ?>" data-csrf="<?= lorkhan_ui_h($csrf) ?>" data-connector="<?= lorkhan_ui_h($preview['default_connector_id'] ?? '') ?>" data-voice="<?= lorkhan_ui_h($preview['default_voice'] ?? '') ?>" data-max-length="<?= SpeechPreviewCatalog::MAX_TEXT_LENGTH ?>">
                        <article data-reader-entry>
                            <div class="diary-paper"><div class="diary-author"><?= lorkhan_ui_h($diary['author']) ?></div><div class="diary-entry-body" data-reader-text><?= nl2br(lorkhan_ui_h($diary['content'])) ?></div></div>
                            <div class="diary-audio-controls">
                                <button type="button" class="dashboard-btn" data-reader-play<?= $diaryAudioReady ? '' : ' disabled' ?> title="<?= $diaryAudioReady ? 'Uses the Narrator voice or TTS default. Your speech provider may charge.' : 'Configure a TTS connector and voice in TTS Studio.' ?>">▶ Play Audio</button>
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
            <div class="widget-header"><h3>Morrowind Runtime Stats</h3></div>
            <div class="widget-content widget-stats">
                <?php foreach ($dashboard['runtime'] as $label => $value): ?>
                    <div class="stat-card"><span class="stat-value"><?php echo lorkhan_ui_h($value); ?></span><span class="stat-label"><?php echo lorkhan_ui_h($label); ?></span></div>
                <?php endforeach; ?>
            </div>
        </article>
    </section>
</main>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/lib/ui/d3/d3.v7.9.0.min.js"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/lib/ui/d3/d3.layout.cloud.v1.2.7.js"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/home-dashboard.js"></script>
<script defer src="<?= lorkhan_ui_h($webRoot) ?>/ui/js/roleplay-reader.js?v=<?= filemtime(__DIR__.'/js/roleplay-reader.js') ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
