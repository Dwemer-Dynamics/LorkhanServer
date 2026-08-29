<?php

declare(strict_types=1);

$pageTitle = 'Player Management';
$topNavSection = 'configuration';
$embedded = (string) ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page player-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$rows = $uiRepository->rows('player');
$byInstallation = [];
foreach ($rows as $row) $byInstallation[(string) $row['installation_id']] = $row;

$requested = trim((string) ($_GET['installation_id'] ?? ''));
$installationId = $requested !== '' && array_filter(
    $installations,
    static fn(array $row): bool => (string) $row['installation_id'] === $requested
) ? $requested : (string) ($installations[0]['installation_id'] ?? '');
$profile = $byInstallation[$installationId] ?? null;
$content = is_array($profile['content'] ?? null) ? $profile['content'] : [];
$routing = is_array($content['routing'] ?? null) ? $content['routing'] : [];
$generationId = (string) ($routing['profile_generation_configuration_id'] ?? '');
$generationValue = array_key_exists('profile_generation_configuration_id', $routing) && $generationId === '' ? '__disabled__' : $generationId;
$generationOptions = ['' => 'Inherit Core Profile', '__disabled__' => 'Use server runtime'];
foreach ($uiRepository->rows('llm') as $connector) {
    if ((string) ($connector['installation_id'] ?? '') === $installationId) $generationOptions[(string) $connector['configuration_id']] = (string) $connector['name'];
}
if ($generationId !== '' && !isset($generationOptions[$generationId])) $generationOptions[$generationId] = 'Unavailable connector';
$latestContext = is_array($profile['latest_context'] ?? null) ? $profile['latest_context'] : [];
$biographyKnownByAll = ($content['biography_known_by_all'] ?? true) !== false;

/** Normalize PostgreSQL JSON values used by the latest typed OpenMW context. */
function almsivi_player_json_array(mixed $value): array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/** Return an item list from either a typed envelope or a direct JSON array. */
function almsivi_player_context_items(mixed $value): array
{
    $decoded = almsivi_player_json_array($value);
    $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : $decoded;
    return array_is_list($items) ? array_values(array_filter($items, 'is_array')) : [];
}

/** Render one Herika-style live switch backed by the typed player profile document. */
function almsivi_player_toggle(string $name, string $id, string $label, bool $checked, string $hint): void
{
    $hintId = $id . '-help';
    echo '<input type="hidden" name="' . almsivi_ui_h($name) . '" value="0">'
        . '<label class="toggle-row" for="' . almsivi_ui_h($id) . '"><span class="toggle-switch"><input id="' . almsivi_ui_h($id) . '" name="' . almsivi_ui_h($name) . '" type="checkbox" value="1"' . ($checked ? ' checked' : '') . ' aria-describedby="' . almsivi_ui_h($hintId) . '"><span class="toggle-slider"></span></span><span class="toggle-label">' . almsivi_ui_h($label) . '</span></label>'
        . '<span class="hint" id="' . almsivi_ui_h($hintId) . '">' . almsivi_ui_h($hint) . '</span>';
}

/** Render a copied Herika switch that cannot mutate unsupported ALMSIVI state. */
function almsivi_player_placeholder_toggle(string $label, string $featureId, string $hint): void
{
    echo '<label class="toggle-row is-placeholder"><span class="toggle-switch"><input type="checkbox" disabled aria-disabled="true"><span class="toggle-slider"></span></span><span class="toggle-label">' . almsivi_ui_h($label) . '</span>' . almsivi_ui_feature_badge($featureId, true) . '</label><span class="hint">' . almsivi_ui_h($hint) . '</span>';
}

$playerState = almsivi_player_json_array($latestContext['playerState'] ?? []);
$playerIdentity = almsivi_player_json_array($playerState['identity'] ?? []);
$playerStats = almsivi_player_json_array($playerState['stats'] ?? []);
$playerSkills = almsivi_player_json_array($playerState['skills'] ?? ($latestContext['skills'] ?? []));
$playerAttributes = almsivi_player_json_array($playerState['attributes'] ?? []);
$inventory = almsivi_player_context_items($latestContext['inventory'] ?? []);
$equipment = almsivi_player_context_items($playerState['equipment'] ?? ($latestContext['equipment'] ?? []));
$acceptedAt = trim((string) ($latestContext['accepted_at'] ?? ''));

usort($inventory, static fn(array $a, array $b): int => strcasecmp(
    (string) ($a['display_name'] ?? $a['record_id'] ?? ''),
    (string) ($b['display_name'] ?? $b['record_id'] ?? '')
));
ksort($playerSkills, SORT_NATURAL | SORT_FLAG_CASE);
ksort($playerAttributes, SORT_NATURAL | SORT_FLAG_CASE);

$additionalStylesheets = ['herika-player.css?v=' . (string) filemtime(dirname(__DIR__) . '/css/herika-player.css')];
$includeManagementStyles = false;
include dirname(__DIR__) . '/tmpl/head.html';
if (!$embedded) include dirname(__DIR__) . '/tmpl/navbar.php';
?>
<main class="player-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="page-container">
        <div class="page-header almsivi-page-head">
            <h1 class="almsivi-page-head-title">&#x1F464; Player Management</h1>
            <div class="almsivi-page-head-note">
                <p>Manage your character's information and view in game statistics</p>
                <p>Changes made here will be used by AI NPCs to understand your character better</p>
            </div>
        </div>

        <?php if (isset($_GET['status'])): ?><div class="almsivi-status" role="status"><?php echo (is_string($_GET['status']) && $_GET['status'] === 'imported') ? 'Portable player settings imported as a new player profile revision.' : 'Player profile saved.'; ?></div><?php endif; ?>

        <?php if ($installations === []): ?>
            <section class="content-section"><div class="no-data">Connect OpenMW once before creating the player profile.</div></section>
        <?php else: ?>
            <?php if (count($installations) > 1): ?>
                <section class="content-section player-installation">
                    <label for="player-installation">Installation</label>
                    <select id="player-installation" data-installation-select>
                        <?php foreach ($installations as $installation): ?>
                            <option value="<?php echo almsivi_ui_h($installation['installation_id']); ?>"<?php echo (string) $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($installation['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </section>
            <?php endif; ?>

            <?php if ($profile !== null && (int) ($profile['input_count'] ?? 0) > 0): ?>
                <form id="player-speech-ai-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/player-speech-style-generate">
                    <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                    <input type="hidden" name="profile_id" value="<?php echo almsivi_ui_h($profile['profile_id']); ?>">
                </form>
            <?php endif; ?>

            <form id="player-profile-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/<?php echo $profile === null ? 'player-profile-create' : 'player-profile-revise'; ?>">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
                <?php if ($profile !== null): ?>
                    <input type="hidden" name="profile_id" value="<?php echo almsivi_ui_h($profile['profile_id']); ?>">
                    <input type="hidden" name="base_content_json" value="<?php echo almsivi_ui_h(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                    <input type="hidden" name="change_reason" value="Management player update">
                <?php endif; ?>

                <button type="submit" class="btn-save">Save Player Settings</button>

                <div class="content-grid player-overview-grid">
                    <section class="content-section">
                        <h2>&#x1F3F7;&#xFE0F; Player Information</h2>
                        <label for="player-name">Player Name</label>
                        <input id="player-name" name="name" type="text" required maxlength="256" value="<?php echo almsivi_ui_h($profile['name'] ?? ''); ?>"<?php echo $profile === null ? '' : ' readonly'; ?>>
                        <span class="hint">Your character's name.</span>
                        <?php if ($playerIdentity !== []): ?><span class="hint"><?php echo almsivi_ui_h(ucfirst((string) ($playerIdentity['gender'] ?? '')) . ' ' . ucfirst((string) ($playerIdentity['race'] ?? '')) . ' ' . ucfirst((string) ($playerIdentity['class'] ?? ''))); ?></span><?php endif; ?>
                    </section>

                    <section class="content-section">
                        <h2>&#x1F464; Player Appearance</h2>
                        <label for="player-appearance">Physical Description</label>
                        <textarea id="player-appearance" name="appearance" placeholder="Describe your character's appearance..."><?php echo almsivi_ui_h($content['appearance'] ?? ''); ?></textarea>
                        <span class="hint">Physical description of your character used for AI context. NPCs will be aware of your appearance.</span>
                    </section>

                    <section class="content-section player-bio-section">
                        <h2>&#x1F4DC; Player Bio</h2>
                        <label for="player-biography">Character Bio</label>
                        <textarea id="player-biography" name="biography" placeholder="Describe your character's background and story..."><?php echo almsivi_ui_h($content['biography'] ?? ''); ?></textarea>
                        <span class="hint">Backstory and character context stored in the versioned player profile.</span>
                        <?php almsivi_player_toggle('biography_known_by_all', 'player-biography-known-by-all', 'Player Biography Known by All', $biographyKnownByAll, 'On, NPCs and the Narrator may receive this biography. Off, only the Narrator may receive it. This visibility setting is saved with the player profile and carried by portable player settings alongside appearance, biography, personality, speech style, goals, and notes.'); ?>
                        <details>
                            <summary>Additional typed player profile</summary>
                            <div class="field-block"><label for="player-personality">Personality</label><textarea id="player-personality" name="personality"><?php echo almsivi_ui_h($content['personality'] ?? ''); ?></textarea></div>
                            <div class="field-block"><label for="player-goals">Goals and motivations</label><textarea id="player-goals" name="goals"><?php echo almsivi_ui_h($content['goals'] ?? ''); ?></textarea></div>
                            <div class="field-block"><label for="player-notes">Additional roleplay notes</label><textarea id="player-notes" name="notes"><?php echo almsivi_ui_h($content['notes'] ?? ''); ?></textarea></div>
                        </details>
                    </section>

                    <section class="content-section player-tts-section">
                        <h2 class="section-title-with-status"><span>Player Autochat and TTS</span><?php echo almsivi_ui_feature_badge('config.player.autochat-tts', true); ?></h2>
                        <div class="field-block"><label>Player TTS Connector</label><select disabled aria-disabled="true"><option>Use active ALMSIVI TTS route</option></select><span class="hint">Per-player re-speech connector selection is not connected to OpenMW yet.</span></div>
                        <div class="field-block"><label>Voice ID Override</label><input type="text" value="" placeholder="TheNarrator" disabled aria-disabled="true"><span class="hint">A copied Herika control retained for future typed player speech routing.</span></div>
                        <div class="field-block"><label>Player Autochat Connector</label><select disabled aria-disabled="true"><option>Use active ALMSIVI model route</option></select><span class="hint">ALMSIVI uses the inherited typed model pipeline.</span></div>
                        <label for="player-speech-style">Speech Style</label>
                        <textarea id="player-speech-style" name="speech_style" placeholder="Describe how your character speaks and communicates..."><?php echo almsivi_ui_h($content['speech_style'] ?? ''); ?></textarea>
                        <span class="hint">A concise speech profile used by NPCs to understand how the player communicates.</span>
                        <label for="player-generation-llm">Profile Generation LLM</label>
                        <select id="player-generation-llm" name="profile_generation_configuration_id" aria-describedby="player-generation-help">
                            <?php foreach ($generationOptions as $id => $label): ?>
                                <option value="<?php echo almsivi_ui_h($id); ?>"<?php echo $id === $generationValue ? ' selected' : ''; ?>><?php echo almsivi_ui_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint" id="player-generation-help">Applies to newly queued speech-style jobs. Queued jobs keep their frozen connector revision; saving never calls a provider.</span>
                        <div class="speech-style-tools">
                            <?php if ($profile !== null && (int) ($profile['input_count'] ?? 0) > 0): ?>
                                <button type="submit" form="player-speech-ai-form" class="btn-ai-generate">AI Generate From Last 200 Inputs</button>
                                <span class="hint">Generate Speech Style with AI from <?php echo almsivi_ui_h($profile['input_count']); ?> observed player messages without replacing the rest of the player profile.</span>
                            <?php else: ?>
                                <button type="button" class="btn-ai-generate" disabled>AI Generate From Last 200 Inputs</button>
                                <span class="hint">Speech-style generation becomes available after OpenMW records a player message.</span>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="content-section">
                        <h2 class="section-title-with-status"><span>&#x1F4D9; Player Diary</span><?php echo almsivi_ui_feature_badge('config.player.diary', true); ?></h2>
                        <div class="status-field"><span class="status-field-label">Player Diary Connector</span><div class="status-field-value">Typed ALMSIVI narrative pipeline</div><div class="status-field-source">A separate player diary connector is not configured.</div></div>
                        <?php
                        almsivi_player_placeholder_toggle('Enable Player Diary', 'config.player.diary', 'Manual player diary generation is planned.');
                        almsivi_player_placeholder_toggle('Player Auto Diary', 'config.player.diary', 'Automatic diary generation on resting is planned.');
                        almsivi_player_placeholder_toggle('Player Auto Diary Wait', 'config.player.diary', 'Automatic diary generation when waiting is planned.');
                        ?>
                    </section>
                </div>

                <button type="submit" class="btn-save">Save Player Settings</button>
                <?php if ($profile !== null): ?><span class="revision-meta">Revision <?php echo almsivi_ui_h($profile['current_revision']); ?> &middot; <?php echo almsivi_ui_h($profile['input_count']); ?> observed player messages</span><?php endif; ?>
            </form>

            <?php if ($profile !== null): ?>
                <details class="player-portability">
                    <summary class="player-portability-summary"><span class="player-portability-summary-icon">&#x25B6;</span><span>Portable Player Settings</span></summary>
                    <div class="player-portability-body">
                        <p class="hint" id="player-portability-scope">A player preset carries appearance, biography, the biography visibility setting, personality, speech style, goals, and notes only.</p>
                        <div class="player-portability-actions">
                            <a class="btn-portable" href="<?php echo almsivi_ui_h($managementBasePath . '/exports/player-profile-settings/' . (string) $profile['profile_id'] . '.json'); ?>" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.player.export')['description']); ?>">Export Settings</a>
                        </div>
                        <form class="player-portability-form" method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/player-profile-settings-import">
                            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                            <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
                            <div class="field-block">
                                <label for="player-preset-file">Preset file</label>
                                <input id="player-preset-file" type="file" accept="application/json,.json" data-json-import-target="player-preset-json" aria-describedby="player-portability-scope player-portability-help">
                            </div>
                            <div class="field-block">
                                <label for="player-preset-json">Preset JSON</label>
                                <textarea id="player-preset-json" name="preset_json" rows="8" required spellcheck="false" placeholder="Choose an exported .json file or paste its contents here." aria-describedby="player-portability-scope player-portability-help"></textarea>
                            </div>
                            <p class="hint" id="player-portability-help">Choosing a file fills the box above, and pasting the document works the same way. Importing saves a new revision of this installation's existing player profile. It never creates or selects a player, and it never changes the player name and identity, the Profile Generation LLM route, live OpenMW inventory, equipment, statistics, and playthrough context, or the excluded autochat, TTS, and diary controls.</p>
                            <div class="player-portability-actions">
                                <button type="submit" class="btn-portable" title="<?php echo almsivi_ui_h(almsivi_ui_feature('config.player.import')['description']); ?>">Import Preset</button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endif; ?>

            <div class="full-width-section"><h2 class="full-width-title">&#x1F4CA; Player Statistics</h2></div>
            <?php if ($acceptedAt !== ''): ?><p class="context-meta">Latest OpenMW player context accepted <?php echo almsivi_ui_h($acceptedAt); ?></p><?php endif; ?>

            <div class="content-grid two-col">
                <section class="content-section">
                    <h2>Inventory (<?php echo count($inventory); ?> items)</h2>
                    <?php if ($inventory !== []): ?><div class="inventory-list">
                        <?php foreach ($inventory as $item): ?>
                            <div class="inventory-item"><span class="inventory-item-name"><?php echo almsivi_ui_h($item['display_name'] ?? $item['record_id'] ?? 'Unknown Item'); ?></span><span class="inventory-item-count">&times;<?php echo almsivi_ui_h((int) ($item['count'] ?? 1)); ?></span></div>
                        <?php endforeach; ?>
                    </div><?php else: ?><div class="no-data">No inventory data available. Play the game to sync your inventory.</div><?php endif; ?>
                </section>

                <section class="content-section">
                    <h2>Equipment</h2>
                    <?php if ($equipment !== []): ?><div class="equipment-grid">
                        <?php foreach ($equipment as $item): ?>
                            <div class="equipment-slot"><div class="equipment-slot-name"><?php echo almsivi_ui_h(str_replace('_', ' ', (string) ($item['slot'] ?? 'Equipped'))); ?></div><div class="equipment-item-name"><?php echo almsivi_ui_h($item['display_name'] ?? $item['record_id'] ?? 'Unknown Item'); ?></div></div>
                        <?php endforeach; ?>
                    </div><?php else: ?><div class="no-data">No equipment data available. Play the game to sync your equipment.</div><?php endif; ?>
                </section>

                <section class="content-section">
                    <h2>Character Stats</h2>
                    <div class="stats-grid">
                        <div class="stat-card"><div class="stat-card-title">Level</div><div class="stat-card-value"><?php echo almsivi_ui_h((int) ($playerStats['level'] ?? 1)); ?></div></div>
                        <?php foreach (['health' => 'health', 'magicka' => 'magicka', 'fatigue' => 'stamina'] as $statName => $barClass): $stat = almsivi_player_json_array($playerStats[$statName] ?? []); $current = (float) ($stat['current'] ?? 0); $base = max(1.0, (float) ($stat['base'] ?? 1)); ?>
                            <div class="stat-card"><div class="stat-card-title"><?php echo almsivi_ui_h(ucfirst($statName)); ?></div><div class="stat-card-value"><?php echo almsivi_ui_h((int) round($current)); ?> / <?php echo almsivi_ui_h((int) round($base)); ?></div><div class="stat-bar-container"><div class="stat-bar <?php echo almsivi_ui_h($barClass); ?>" style="width:<?php echo almsivi_ui_h(min(100, max(0, ($current / $base) * 100))); ?>%"></div></div></div>
                        <?php endforeach; ?>
                        <div class="stat-card"><div class="stat-card-title">Encumbrance</div><div class="stat-card-value"><?php echo almsivi_ui_h(round((float) ($playerStats['encumbrance'] ?? 0), 1)); ?> / <?php echo almsivi_ui_h(round((float) ($playerStats['capacity'] ?? 0), 1)); ?></div></div>
                    </div>
                </section>
            </div>

            <?php if ($playerAttributes !== []): ?>
                <section class="content-section full-width-section"><h2>Attributes</h2><div class="skills-grid">
                    <?php foreach ($playerAttributes as $name => $value): $entry = almsivi_player_json_array($value); ?><div class="skill-item"><div class="skill-name"><?php echo almsivi_ui_h(str_replace('_', ' ', (string) $name)); ?></div><div class="skill-value"><?php echo almsivi_ui_h($entry['modified'] ?? $entry['base'] ?? $value); ?></div></div><?php endforeach; ?>
                </div></section>
            <?php endif; ?>

            <section class="content-section full-width-section"><h2>&#x2B50; Skills</h2>
                <?php if ($playerSkills !== []): ?><div class="skills-grid">
                    <?php foreach ($playerSkills as $name => $value): $entry = almsivi_player_json_array($value); ?><div class="skill-item"><div class="skill-name"><?php echo almsivi_ui_h(str_replace('_', ' ', (string) $name)); ?></div><div class="skill-value"><?php echo almsivi_ui_h($entry['modified'] ?? $entry['base'] ?? $value); ?></div></div><?php endforeach; ?>
                </div><?php else: ?><div class="no-data">No skill data available. Play the game to sync your skills.</div><?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php if ($profile !== null): ?><script defer src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo almsivi_ui_h($uiAssetVersion); ?>"></script><?php endif; ?>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
