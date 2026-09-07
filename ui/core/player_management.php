<?php

declare(strict_types=1);

$pageTitle = 'Player Management';
$topNavSection = 'configuration';
$embedded = (string) ($_GET['embed'] ?? '') === '1';
$BODY_CLASS = 'hub-page player-page-shell' . ($embedded ? ' embedded-page' : '');
require dirname(__DIR__) . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$rows = $uiRepository->rows('player');
$ttsRows = $uiRepository->rows('tts');
$providerRows = $uiRepository->rows('llm');
$byInstallation = [];
foreach ($rows as $row) $byInstallation[(string) $row['installation_id']] = $row;

$requested = trim((string) ($_GET['installation_id'] ?? ''));
$installationId = $requested !== '' && array_filter(
    $installations,
    static fn(array $row): bool => (string) $row['installation_id'] === $requested
) ? $requested : (string) ($installations[0]['installation_id'] ?? '');
$profile = $byInstallation[$installationId] ?? null;
$content = is_array($profile['content'] ?? null) ? $profile['content'] : [];
$diary = is_array($content['diary'] ?? null) ? $content['diary'] : [];
$routing = is_array($content['routing'] ?? null) ? $content['routing'] : [];
$voice = is_array($content['voice'] ?? null) ? $content['voice'] : [];
$latestContext = is_array($profile['latest_context'] ?? null) ? $profile['latest_context'] : [];
$effectivePlayer=$installationId===''?[]:$productRepository->effectiveSettingsForProfile($installationId,$profile['profile_id']??null);
$diaryConnectorId=(string)($effectivePlayer['routing']['diary_generation_configuration_id']??'');
$diaryConnectorName=$diaryConnectorId===''?'Not configured':$diaryConnectorId;
foreach($providerRows as$providerRow)if(($providerRow['configuration_id']??'')===$diaryConnectorId)$diaryConnectorName=(string)$providerRow['name'];
$biographyKnownByAll = ($content['biography_known_by_all'] ?? true) !== false;

/** Normalize PostgreSQL JSON values used by the latest typed OpenMW context. */
function lorkhan_player_json_array(mixed $value): array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

/** Return an item list from either a typed envelope or a direct JSON array. */
function lorkhan_player_context_items(mixed $value): array
{
    $decoded = lorkhan_player_json_array($value);
    $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : $decoded;
    return array_is_list($items) ? array_values(array_filter($items, 'is_array')) : [];
}

/** Render one Herika-style live switch backed by the typed player profile document. */
function lorkhan_player_toggle(string $name, string $id, string $label, bool $checked, string $hint): void
{
    $hintId = $id . '-help';
    echo '<input type="hidden" name="' . lorkhan_ui_h($name) . '" value="0">'
        . '<label class="toggle-row" for="' . lorkhan_ui_h($id) . '"><span class="toggle-switch"><input id="' . lorkhan_ui_h($id) . '" name="' . lorkhan_ui_h($name) . '" type="checkbox" value="1"' . ($checked ? ' checked' : '') . ' aria-describedby="' . lorkhan_ui_h($hintId) . '"><span class="toggle-slider"></span></span><span class="toggle-label">' . lorkhan_ui_h($label) . '</span></label>'
        . '<span class="hint" id="' . lorkhan_ui_h($hintId) . '">' . lorkhan_ui_h($hint) . '</span>';
}

/** Render a copied Herika switch that cannot mutate unsupported LORKHAN state. */
function lorkhan_player_placeholder_toggle(string $label, string $featureId, string $hint): void
{
    echo '<label class="toggle-row is-placeholder"><span class="toggle-switch"><input type="checkbox" disabled aria-disabled="true"><span class="toggle-slider"></span></span><span class="toggle-label">' . lorkhan_ui_h($label) . '</span>' . lorkhan_ui_feature_badge($featureId, true) . '</label><span class="hint">' . lorkhan_ui_h($hint) . '</span>';
}

$playerState = lorkhan_player_json_array($latestContext['playerState'] ?? []);
$playerIdentity = lorkhan_player_json_array($playerState['identity'] ?? []);
$playerStats = lorkhan_player_json_array($playerState['stats'] ?? []);
$playerSkills = lorkhan_player_json_array($playerState['skills'] ?? ($latestContext['skills'] ?? []));
$playerAttributes = lorkhan_player_json_array($playerState['attributes'] ?? []);
$inventory = lorkhan_player_context_items($latestContext['inventory'] ?? []);
$equipment = lorkhan_player_context_items($playerState['equipment'] ?? ($latestContext['equipment'] ?? []));
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
        <div class="page-header lorkhan-page-head">
            <h1 class="lorkhan-page-head-title">&#x1F464; Player Management</h1>
            <div class="lorkhan-page-head-note">
                <p>Change Player roleplay settings</p>
            </div>
        </div>

        <?php if (isset($_GET['status'])): ?><div class="lorkhan-status" role="status"><?php echo (is_string($_GET['status']) && $_GET['status'] === 'imported') ? 'Portable player settings imported as a new player profile revision.' : 'Player profile saved.'; ?></div><?php endif; ?>

        <?php if ($installations === []): ?>
            <section class="content-section"><div class="no-data">Connect OpenMW once before creating the player profile.</div></section>
        <?php else: ?>
            <?php if (count($installations) > 1): ?>
                <section class="content-section player-installation">
                    <label for="player-installation">Installation</label>
                    <select id="player-installation" data-installation-select>
                        <?php foreach ($installations as $installation): ?>
                            <option value="<?php echo lorkhan_ui_h($installation['installation_id']); ?>"<?php echo (string) $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($installation['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </section>
            <?php endif; ?>

            <?php if ($profile !== null && (int) ($profile['input_count'] ?? 0) > 0): ?>
                <form id="player-speech-ai-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/player-speech-style-generate">
                    <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                    <input type="hidden" name="profile_id" value="<?php echo lorkhan_ui_h($profile['profile_id']); ?>">
                </form>
            <?php endif; ?>

            <form id="player-profile-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/<?php echo $profile === null ? 'player-profile-create' : 'player-profile-revise'; ?>" data-track-dirty>
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <?php if ($profile !== null): ?>
                    <input type="hidden" name="profile_id" value="<?php echo lorkhan_ui_h($profile['profile_id']); ?>">
                    <input type="hidden" name="base_content_json" value="<?php echo lorkhan_ui_h(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                    <input type="hidden" name="change_reason" value="Management player update">
                <?php endif; ?>

                <div class="player-save-row settings-page-actions"><button type="submit" class="btn-save"<?php if($profile!==null): ?> title="Revision <?php echo (int)$profile['current_revision']; ?> · <?php echo (int)$profile['input_count']; ?> observed player messages"<?php endif; ?>>Save Player Settings</button>
                    <?php if($profile!==null): ?><a class="btn-portable" href="<?php echo lorkhan_ui_h($managementBasePath.'/exports/player-profile-settings/'.$profile['profile_id'].'.json'); ?>">📤 Export Player</a><button type="button" class="btn-portable" data-player-import-open>📥 Import Player</button><?php endif; ?>
                    <span class="unsaved-indicator" data-dirty-indicator hidden>Unsaved changes</span>
                </div>

                <div class="content-grid player-overview-grid">
                    <section class="content-section">
                        <h2>&#x1F3F7;&#xFE0F; Player Information</h2>
                        <label for="player-name">Player Name</label>
                        <input id="player-name" name="name" type="text" required maxlength="256" value="<?php echo lorkhan_ui_h($profile['name'] ?? ''); ?>"<?php echo $profile === null ? '' : ' readonly'; ?>>
                        <span class="hint">Your character's name.</span>
                        <?php if ($playerIdentity !== []): ?><span class="hint"><?php echo lorkhan_ui_h(ucfirst((string) ($playerIdentity['gender'] ?? '')) . ' ' . ucfirst((string) ($playerIdentity['race'] ?? '')) . ' ' . ucfirst((string) ($playerIdentity['class'] ?? ''))); ?></span><?php endif; ?>
                    </section>

                    <section class="content-section">
                        <h2>&#x1F464; Player Appearance</h2>
                        <label for="player-appearance">Physical Description</label>
                        <textarea id="player-appearance" name="appearance" placeholder="Describe your character's appearance..."><?php echo lorkhan_ui_h($content['appearance'] ?? ''); ?></textarea>
                        <span class="hint">Physical description of your character used for AI context. NPCs will be aware of your appearance.</span>
                    </section>

                    <section class="content-section player-bio-section">
                        <h2>&#x1F4DC; Player Bio</h2>
                        <label for="player-biography">Character Bio</label>
                        <textarea id="player-biography" name="biography" placeholder="Describe your character's background and story..."><?php echo lorkhan_ui_h($content['biography'] ?? ''); ?></textarea>
                        <span class="hint">Backstory and character context.</span>
                        <div class="biography-visibility"><input type="hidden" name="biography_known_by_all" value="0"><label for="player-biography-known-by-all"><input id="player-biography-known-by-all" name="biography_known_by_all" type="checkbox" value="1"<?php echo $biographyKnownByAll?' checked':''; ?> aria-describedby="player-biography-visibility-help">Player Biography Known by All</label></div>
                        <span class="hint" id="player-biography-visibility-help">If enabled, all NPCs know this bio. If disabled, only the Narrator knows it.</span>
                        <details>
                            <summary>Additional player details</summary>
                            <div class="field-block"><label for="player-personality">Personality</label><textarea id="player-personality" name="personality"><?php echo lorkhan_ui_h($content['personality'] ?? ''); ?></textarea></div>
                            <div class="field-block"><label for="player-goals">Goals and motivations</label><textarea id="player-goals" name="goals"><?php echo lorkhan_ui_h($content['goals'] ?? ''); ?></textarea></div>
                            <div class="field-block"><label for="player-notes">Additional roleplay notes</label><textarea id="player-notes" name="notes"><?php echo lorkhan_ui_h($content['notes'] ?? ''); ?></textarea></div>
                        </details>
                    </section>

                    <section class="content-section player-tts-section">
                        <?php $playerTtsEnabled=trim((string)($routing['tts_configuration_id']??''))!==''; ?>
                        <h2 class="section-title-with-status"><span>Player Autochat and TTS</span><span class="section-status-indicator <?php echo $playerTtsEnabled?'status-enabled':'status-disabled'; ?>" data-player-tts-status><span class="status-dot" aria-hidden="true"></span><span data-player-tts-status-text><?php echo $playerTtsEnabled?'Enabled':'Disabled'; ?></span></span></h2>
                        <div class="field-block">
                            <label for="player-tts">TTS Connector</label>
                            <select id="player-tts" name="tts_configuration_id" aria-describedby="player-tts-help">
                                <option value="__disabled__"<?php echo array_key_exists('tts_configuration_id', $routing) && (string) $routing['tts_configuration_id'] === '' ? ' selected' : ''; ?>>Disabled</option>
                                <?php foreach ($ttsRows as $tts): if ((string) ($tts['installation_id'] ?? '') !== $installationId) continue; ?>
                                    <option value="<?php echo lorkhan_ui_h($tts['configuration_id']); ?>"<?php echo (string) ($routing['tts_configuration_id'] ?? '') === (string) $tts['configuration_id'] ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($tts['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint" id="player-tts-help">Enables spoken playback of typed player messages through this connector.</span>
                        </div>
                        <div class="field-block">
                            <label for="player-voice">VoiceID</label>
                            <input id="player-voice" name="voice_id" type="text" maxlength="512" value="<?php echo lorkhan_ui_h($voice['id'] ?? ''); ?>" placeholder="MaleArgonian" aria-describedby="player-voice-help">
                            <span class="hint" id="player-voice-help">Overrides the selected connector's default voice for the player.</span>
                        </div>
                        <details class="player-voice-options"><summary>Voice options</summary><div class="field-block">
                            <label for="player-voice-language">Voice Language</label>
                            <input id="player-voice-language" name="voice_language" type="text" maxlength="35" value="<?php echo lorkhan_ui_h($voice['language'] ?? 'en-US'); ?>">
                        </div></details>
                        <div class="field-block">
                            <label for="player-autochat">Player Respeech Connector</label>
                            <select id="player-autochat" name="player_autochat_configuration_id" aria-describedby="player-autochat-help">
                                <option value="__disabled__"<?php echo array_key_exists('player_autochat_configuration_id', $routing) && (string) $routing['player_autochat_configuration_id'] === '' ? ' selected' : ''; ?>>Disabled</option>
                                <?php foreach ($providerRows as $provider): if ((string) ($provider['installation_id'] ?? '') !== $installationId) continue; ?>
                                    <option value="<?php echo lorkhan_ui_h($provider['configuration_id']); ?>"<?php echo (string) ($routing['player_autochat_configuration_id'] ?? '') === (string) $provider['configuration_id'] ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($provider['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="hint" id="player-autochat-help">Rewrites typed intent as your character's spoken line. Enable Auto Chat from the in-game Interact menu.</span>
                        </div>
                        <label for="player-speech-style">Player Speech Style</label>
                        <textarea id="player-speech-style" name="speech_style" placeholder="Describe how your character speaks and communicates..."><?php echo lorkhan_ui_h($content['speech_style'] ?? ''); ?></textarea>
                        <span class="hint">A concise speech profile used by NPCs to understand how the player communicates.</span>
                        <span class="hint">Profile generation uses the connector selected in <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/global_settings.php">Global Settings</a>.</span>
                        <div class="speech-style-tools">
                            <label for="player-speech-style-guidance">AI Generation</label>
                            <textarea id="player-speech-style-guidance" name="speech_style_guidance" form="player-speech-ai-form" maxlength="4000" placeholder="Optional: mention traits or tone to prioritize when generating your speech style paragraph."></textarea>
                            <?php if ($profile !== null && (int) ($profile['input_count'] ?? 0) > 0): ?>
                                <button type="submit" form="player-speech-ai-form" class="btn-ai-generate">AI Generate From Last 200 Inputs</button>
                                <span class="hint">Generate Speech Style with AI from <?php echo lorkhan_ui_h($profile['input_count']); ?> observed player messages without replacing the rest of the player profile.</span>
                            <?php else: ?>
                                <button type="button" class="btn-ai-generate" disabled>AI Generate From Last 200 Inputs</button>
                                <span class="hint">Speech-style generation becomes available after OpenMW records a player message.</span>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="content-section">
                        <h2>&#x1F4D9; Player Diary</h2>
                        <div class="status-field"><span class="status-field-label">Player Diary Connector</span><div class="status-field-value"><?php echo lorkhan_ui_h($diaryConnectorName); ?></div><div class="status-field-source">Inherited from the assigned Core Profile's Diary LLM.</div></div>
                        <?php
                        lorkhan_player_toggle('diary_enabled', 'player-diary-enabled', 'Enable Player Diary', ($diary['enabled'] ?? false) === true, 'Allow manual and automatic diary generation for the player.');
                        lorkhan_player_toggle('auto_diary_enabled', 'player-auto-diary-enabled', 'Player Auto Diary', ($diary['automatic_enabled'] ?? false) === true, 'Generate a player diary on the configured timer and after sleeping.');
                        lorkhan_player_toggle('auto_diary_wait_enabled', 'player-auto-diary-wait-enabled', 'Player Auto Diary Wait', ($diary['automatic_wait_enabled'] ?? false) === true, 'Also generate a player diary after waiting.');
                        ?>
                        <label for="player-diary-interval">Automatic Diary Cooldown (seconds)</label>
                        <input id="player-diary-interval" name="diary_interval_seconds" type="number" min="30" max="86400" value="<?php echo (int) ($diary['automatic_interval_seconds'] ?? 120); ?>">
                        <span class="hint">Minimum real-time delay between automatic player diaries. Default: 120 seconds.</span>
                    </section>
                </div>

            </form>

            <?php if ($profile !== null): ?>
                <dialog class="player-portability modal-content" id="player-import-dialog" aria-labelledby="player-import-title">
                    <header class="modal-header"><h2 id="player-import-title">Import Player Settings</h2><button type="button" class="btn-portable" data-player-import-close aria-label="Close import player settings">&times;</button></header>
                    <div class="player-portability-body">
                        <p class="hint" id="player-portability-scope">A player preset carries appearance, biography, the biography visibility setting, personality, speech style, goals, and notes only. TTS connector and voice routing stay with this installation.</p>
                        <form class="player-portability-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/player-profile-settings-import">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                            <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                            <div class="field-block">
                                <label for="player-preset-file">Preset file</label>
                                <input id="player-preset-file" type="file" accept="application/json,.json" data-json-import-target="player-preset-json" aria-describedby="player-portability-scope player-portability-help">
                            </div>
                            <div class="field-block">
                                <label for="player-preset-json">Preset JSON</label>
                                <textarea id="player-preset-json" name="preset_json" rows="8" required spellcheck="false" placeholder="Choose an exported .json file or paste its contents here." aria-describedby="player-portability-scope player-portability-help"></textarea>
                            </div>
                            <p class="hint" id="player-portability-help">Choose a file or paste its contents above. Import saves a new revision of the existing player. Identity, voices, connectors, autochat, diary controls and game state stay unchanged.</p>
                            <div class="player-portability-actions">
                                <button type="button" class="btn-portable" data-player-import-close>Cancel</button><button type="submit" class="btn-portable" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.player.import')['description']); ?>">Import Preset</button>
                            </div>
                        </form>
                    </div>
                </dialog>
            <?php endif; ?>

            <div class="full-width-section"><h2 class="full-width-title">&#x1F4CA; Player Statistics</h2></div>
            <?php if ($acceptedAt !== ''): ?><p class="context-meta">Latest OpenMW player context accepted <?php echo lorkhan_ui_h($acceptedAt); ?></p><?php endif; ?>

            <div class="content-grid two-col">
                <section class="content-section">
                    <h2>Inventory (<?php echo count($inventory); ?> items)</h2>
                    <?php if ($inventory !== []): ?><div class="inventory-list">
                        <?php foreach ($inventory as $item): ?>
                            <div class="inventory-item"><span class="inventory-item-name"><?php echo lorkhan_ui_h($item['display_name'] ?? $item['record_id'] ?? 'Unknown Item'); ?></span><span class="inventory-item-count">&times;<?php echo lorkhan_ui_h((int) ($item['count'] ?? 1)); ?></span></div>
                        <?php endforeach; ?>
                    </div><?php else: ?><div class="no-data">No inventory data available. Play the game to sync your inventory.</div><?php endif; ?>
                </section>

                <section class="content-section">
                    <h2>Equipment</h2>
                    <?php if ($equipment !== []): ?><div class="equipment-grid">
                        <?php foreach ($equipment as $item): ?>
                            <div class="equipment-slot"><div class="equipment-slot-name"><?php echo lorkhan_ui_h(str_replace('_', ' ', (string) ($item['slot'] ?? 'Equipped'))); ?></div><div class="equipment-item-name"><?php echo lorkhan_ui_h($item['display_name'] ?? $item['record_id'] ?? 'Unknown Item'); ?></div></div>
                        <?php endforeach; ?>
                    </div><?php else: ?><div class="no-data">No equipment data available. Play the game to sync your equipment.</div><?php endif; ?>
                </section>

                <?php if($playerStats!==[]): ?><section class="content-section">
                    <h2>Character Stats</h2>
                    <div class="stats-grid">
                        <div class="stat-card"><div class="stat-card-title">Level</div><div class="stat-card-value"><?php echo lorkhan_ui_h((int) ($playerStats['level'] ?? 1)); ?></div></div>
                        <?php foreach (['health' => 'health', 'magicka' => 'magicka', 'fatigue' => 'stamina'] as $statName => $barClass): $stat = lorkhan_player_json_array($playerStats[$statName] ?? []); $current = (float) ($stat['current'] ?? 0); $base = max(1.0, (float) ($stat['base'] ?? 1)); ?>
                            <div class="stat-card"><div class="stat-card-title"><?php echo lorkhan_ui_h(ucfirst($statName)); ?></div><div class="stat-card-value"><?php echo lorkhan_ui_h((int) round($current)); ?> / <?php echo lorkhan_ui_h((int) round($base)); ?></div><div class="stat-bar-container"><div class="stat-bar <?php echo lorkhan_ui_h($barClass); ?>" style="width:<?php echo lorkhan_ui_h(min(100, max(0, ($current / $base) * 100))); ?>%"></div></div></div>
                        <?php endforeach; ?>
                        <div class="stat-card"><div class="stat-card-title">Encumbrance</div><div class="stat-card-value"><?php echo lorkhan_ui_h(round((float) ($playerStats['encumbrance'] ?? 0), 1)); ?> / <?php echo lorkhan_ui_h(round((float) ($playerStats['capacity'] ?? 0), 1)); ?></div></div>
                    </div>
                </section><?php endif; ?>
            </div>

            <?php if ($playerAttributes !== []): ?>
                <section class="content-section full-width-section"><h2>Attributes</h2><div class="skills-grid">
                    <?php foreach ($playerAttributes as $name => $value): $entry = lorkhan_player_json_array($value); ?><div class="skill-item"><div class="skill-name"><?php echo lorkhan_ui_h(str_replace('_', ' ', (string) $name)); ?></div><div class="skill-value"><?php echo lorkhan_ui_h($entry['modified'] ?? $entry['base'] ?? $value); ?></div></div><?php endforeach; ?>
                </div></section>
            <?php endif; ?>

            <?php if ($playerSkills !== []): ?><section class="content-section full-width-section"><h2>&#x2B50; Skills</h2>
                <div class="skills-grid">
                    <?php foreach ($playerSkills as $name => $value): $entry = lorkhan_player_json_array($value); ?><div class="skill-item"><div class="skill-name"><?php echo lorkhan_ui_h(str_replace('_', ' ', (string) $name)); ?></div><div class="skill-value"><?php echo lorkhan_ui_h($entry['modified'] ?? $entry['base'] ?? $value); ?></div></div><?php endforeach; ?>
                </div>
            </section><?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>"></script>
<?php include dirname(__DIR__) . '/tmpl/footer.html'; ?>
