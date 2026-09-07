<?php

declare(strict_types=1);

$pageTitle = 'Narrator Management';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page narrator-page-shell' . (((string) ($_GET['embed'] ?? '')) === '1' ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$rows = $uiRepository->rows('narrator');
$ttsRows = $uiRepository->rows('tts');
$coreRows = $uiRepository->rows('core_profiles');
$llmRows = $uiRepository->rows('llm');
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
$latestDiaryContextInherited=!array_key_exists('latest_entry_in_context',$diary);
$latestDiaryContextEnabled=$installationId!==''
    ?($productRepository->effectiveSettingsForProfile($installationId,$profile['profile_id']??null)['settings']['diary']['latest_entry_in_context']??false)===true
    :false;

$routing = is_array($content['routing'] ?? null) ? $content['routing'] : [];
$voice = is_array($content['voice'] ?? null) ? $content['voice'] : [];
$narratorPromptRows = \LorkhanServer\Application\NarratorEventPrompts::rows($installationId, $uiRepository->rows('prompts'));
$embedded = ($_GET['embed'] ?? '') === '1';
$coreRows = array_values(array_filter($coreRows, static fn(array $row): bool =>
    (string)$row['installation_id'] === $installationId));
$selectedCoreId = (string)($profile['core_profile_id'] ?? $coreRows[0]['core_profile_id'] ?? '');
$connectorLabels = array_column(array_filter($llmRows, static fn(array $row): bool =>
    (string)$row['installation_id'] === $installationId), 'name', 'configuration_id');
$ttsLabels = array_column(array_filter($ttsRows, static fn(array $row): bool =>
    (string)$row['installation_id'] === $installationId), 'name', 'configuration_id');
$connectorFields = [
    'tts_configuration_id' => '🔊 TTS',
    'llm_configuration_id' => '🕹️ Standard',
    'llm_fast_configuration_id' => '🏃 Fast',
    'llm_powerful_configuration_id' => '💪 Power',
    'llm_experimental_configuration_id' => '🧪 Experimental',
    'diary_generation_configuration_id' => '📓 Diary',
];
// The browser receives display labels only, never provider configuration or credentials.
$profileConnectorLabels = [];
foreach ($coreRows as $core) {
    $labels = [];
    foreach ($connectorFields as $key => $label) {
        $id = (string)($core['content']['routing'][$key] ?? '');
        $labels[$key] = (string)(($key === 'tts_configuration_id' ? $ttsLabels : $connectorLabels)[$id] ?? '—');
    }
    $profileConnectorLabels[(string)$core['core_profile_id']] = $labels;
}

/** Render one Herika-style live switch backed by the typed narrator document. */
function lorkhan_narrator_toggle(string $name, string $label, bool $checked, string $hint): void
{
    echo '<label class="narrator-toggle-row"><span class="narrator-toggle-switch"><input type="checkbox" name="' . lorkhan_ui_h($name) . '" value="1"' . ($checked ? ' checked' : '') . '><span class="narrator-toggle-slider"></span></span><span class="narrator-toggle-label">' . lorkhan_ui_h($label) . '</span></label><span class="narrator-hint">' . lorkhan_ui_h($hint) . '</span>';
}

/** Render a copied Herika switch that remains visible but cannot mutate LORKHAN state. */
function lorkhan_narrator_placeholder_toggle(string $label, string $featureId, string $hint): void
{
    echo '<label class="narrator-toggle-row is-placeholder"><span class="narrator-toggle-switch"><input type="checkbox" disabled aria-disabled="true"><span class="narrator-toggle-slider"></span></span><span class="narrator-toggle-label">' . lorkhan_ui_h($label) . '</span>' . lorkhan_ui_feature_badge($featureId, true) . '</label><span class="narrator-hint">' . lorkhan_ui_h($hint) . '</span>';
}

/** Render one live bounded narrator event setting in the Herika field layout. */
function lorkhan_narrator_number(string $name, string $label, int $value, int $minimum, int $maximum, string $hint): void
{
    echo '<label for="narrator-' . lorkhan_ui_h($name) . '">' . lorkhan_ui_h($label) . '</label><input id="narrator-' . lorkhan_ui_h($name) . '" name="' . lorkhan_ui_h($name) . '" type="number" value="' . $value . '" min="' . $minimum . '" max="' . $maximum . '"><span class="narrator-hint">' . lorkhan_ui_h($hint) . '</span>';
}

$additionalStylesheets = ['herika-prompts.css?v=' . (string) filemtime(__DIR__ . '/css/herika-prompts.css'), 'herika-narrator.css?v=' . (string) filemtime(__DIR__ . '/css/herika-narrator.css'), 'player-narration.css?v=' . (string) filemtime(__DIR__ . '/css/player-narration.css')];
$includeManagementStyles = false;
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="narrator-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="narrator-page-container">
        <div class="page-header lorkhan-page-head">
            <h1 class="lorkhan-page-head-title">&#x1F5E3;&#xFE0F; Narrator Management</h1>
            <p class="lorkhan-page-head-note">Configure narrator behavior and settings</p>
        </div>

        <?php if (isset($_GET['status'])): ?><div class="lorkhan-status" role="status"><?php echo (is_string($_GET['status']) && $_GET['status'] === 'imported') ? 'Portable narrator settings imported as a new narrator profile revision.' : 'Narrator profile saved.'; ?></div><?php endif; ?>

        <?php if ($installations === []): ?>
            <section class="narrator-content-section">Connect OpenMW once before configuring narration.</section>
        <?php else: ?>
            <?php if (count($installations) > 1): ?>
                <section class="narrator-content-section narrator-installation">
                    <label for="narrator-installation">Installation</label>
                    <select id="narrator-installation" data-installation-select>
                        <?php foreach ($installations as $installation): ?>
                            <option value="<?php echo lorkhan_ui_h($installation['installation_id']); ?>"<?php echo (string) $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($installation['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </section>
            <?php endif; ?>

            <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/<?php echo $profile === null ? 'narrator-profile-create' : 'narrator-profile-revise'; ?>" data-track-dirty>
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <?php if ($profile !== null): ?>
                    <input type="hidden" name="profile_id" value="<?php echo lorkhan_ui_h($profile['profile_id']); ?>">
                    <input type="hidden" name="base_content_json" value="<?php echo lorkhan_ui_h(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                <?php endif; ?>

                <div class="narrator-save-row settings-page-actions">
                    <button type="submit" class="narrator-save-button">Save Narration Settings</button>
                    <?php if($profile!==null): ?><a class="narrator-transfer-button" href="<?php echo lorkhan_ui_h($managementBasePath.'/exports/narrator-profile-settings/'.$profile['profile_id'].'.json'); ?>">📤 Export Narration</a><button type="button" class="narrator-transfer-button" data-narrator-import-open>📥 Import Narration</button><?php endif; ?>
                    <span class="unsaved-indicator" data-dirty-indicator hidden>Unsaved changes</span>
                </div>

                <div class="narrator-content-grid">
                    <section class="narrator-content-section">
                        <h2>Core Settings</h2>
                        <label for="narrator-name">Narrator Name</label>
                        <input id="narrator-name" name="name" type="text" maxlength="256" required value="<?php echo lorkhan_ui_h($profile['name'] ?? 'The Narrator'); ?>"<?php echo $profile === null ? '' : ' readonly'; ?>>
                        <span class="narrator-hint">Changes how the narrator is identified in prompts, context, subtitles, and history displays.</span>
                        <?php
                        lorkhan_narrator_toggle('enabled', 'Enable Narrator', ($content['enabled'] ?? false) === true, 'Enable or disable the narrator system entirely.');
                        echo '<span class="narrator-hint">Book event summaries and Read Aloud use the Narrator profile. Read Aloud is enabled separately in the in-game Sound settings.</span>';
                        lorkhan_narrator_toggle('book_events', 'Narrate Book Events', ($content['book_events'] ?? false) === true, 'Allow the narrator to respond to supported book events.');
                        echo '<input type="hidden" name="narrator_visibility_present" value="1">';
                        lorkhan_narrator_toggle('hide_from_context', 'Hide Narrator from NPC Context', ($content['hide_from_context'] ?? true) === true, 'Hide Narrator-spoken dialogue lines from NPC context.');
                        lorkhan_narrator_toggle('diary_enabled', 'Narrator Diary', ($diary['enabled'] ?? false) === true, 'Allow manual and automatic diary generation for the narrator.');
                        lorkhan_narrator_toggle('auto_diary_enabled', 'Narrator Auto Diary', ($diary['automatic_enabled'] ?? false) === true, 'Generate a narrator diary on the configured timer and after sleeping.');
                        echo '<input type="hidden" name="narrator_diary_access_present" value="1">';
                        lorkhan_narrator_toggle('only_diary_access', 'Narrator only diary access', ($content['only_diary_access'] ?? false) === true, 'Restrict the Narrator to diary entries written by The Narrator. When disabled, the Narrator may recall relevant diary entries from all NPCs.');
                        ?>
                        <input type="hidden" name="latest_diary_context_present" value="1">
                        <label class="narrator-toggle-row">
                            <div class="narrator-toggle-switch">
                                <input type="checkbox" id="latest_diary_context_enabled" name="latest_diary_context_enabled" value="1" <?php echo $latestDiaryContextEnabled ? 'checked' : ''; ?>>
                                <span class="narrator-toggle-slider"></span>
                            </div>
                            <span class="narrator-toggle-label">&#x1F4D6; Include Latest Diary Entry</span>
                        </label>
                        <span class="narrator-hint">Add The Narrator's most recent diary entry to its context. Narrator only &mdash; NPCs sharing the same Core Profile are unaffected.<?php echo $latestDiaryContextInherited ? ' Currently inherited from the Core Profile until you save Narrator settings.' : ''; ?></span>
                        <details class="narrator-additional-controls">
                            <summary>Additional narration controls</summary>
                            <?php
                        lorkhan_narrator_toggle('context_visibility', 'Include Narrator Context in Prompts', ($content['context_visibility'] ?? false) === true, 'Include narrator profile context when assembling NPC prompts.');
                        lorkhan_narrator_toggle('auto_diary_wait_enabled', 'Narrator Auto Diary Wait', ($diary['automatic_wait_enabled'] ?? false) === true, 'Also generate a narrator diary after waiting.');
                        lorkhan_narrator_number('diary_interval_seconds', 'Automatic Diary Cooldown (seconds)', (int) ($diary['automatic_interval_seconds'] ?? 120), 30, 86400, 'Minimum real-time delay between automatic narrator diaries. Default: 120 seconds.');
                            ?>
                        </details>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Inline Narration</h2>
                        <label for="inline-narration-mode">Inline Narration Mode</label>
                        <select id="inline-narration-mode" name="inline_narration_mode">
                            <?php foreach (['Disabled', 'Narrator', 'NPC', 'Text Only'] as $mode): ?>
                                <option<?php echo (string) ($content['inline_narration_mode'] ?? 'Disabled') === $mode ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($mode); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="narrator-hint">Controls leading *narration* blocks. Narrator uses the narrator voice, NPC speaks the full line, Text Only displays narration without speech, and Disabled turns off special routing.</span>
                        <?php
                        echo '<input type="hidden" name="narration_filters_present" value="1">';
                        foreach (['remove_player_input_asterisks'=>['Remove Player Input Asterisks From TTS','Keep player subtitles unchanged; omit asterisked stage directions from player speech.'],
                            'remove_npc_output_asterisks'=>['Remove NPC Output Asterisks','Omit asterisked directions from NPC speech and subtitles when inline narration routing is Disabled.'],
                            'remove_player_autochat_asterisks'=>['Remove Player Autochat Asterisks','Keep rewritten player dialogue spoken-only.'],
                            'keep_npc_narration_in_history'=>['Keep NPC Narration Description in Context History','Retain the original NPC narration in saved context when output filtering is enabled.']] as $field=>[$label,$hint]) {
                            lorkhan_narrator_toggle($field,$label,($content['narration_filters'][$field]??\LorkhanServer\Application\NarrationTextPolicy::defaults()[$field])===true,$hint);
                        }
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Welcome Message</h2>
                        <?php
                        lorkhan_narrator_toggle('welcome_events', 'Enable Welcome Message on Load', ($content['welcome_events'] ?? false) === true, 'Allow a narrator welcome event after a supported OpenMW session load.');
                        lorkhan_narrator_number('welcome_cooldown_minutes', 'Welcome Message Cooldown (minutes)', (int) ($content['welcome_cooldown_minutes'] ?? 10), 1, 1440, 'Minimum in-game minutes between welcome messages. Default: 10.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Random Narration</h2>
                        <?php
                        lorkhan_narrator_toggle('random_events', 'Enable Random Narration', ($content['random_events'] ?? false) === true, 'Allow supported random events to route through the narrator.');
                        lorkhan_narrator_number('random_chance_percent', 'Random Narration Chance (%)', (int) ($content['random_chance_percent'] ?? 15), 1, 100, 'Chance after an eligible completed conversation round. Default: 15%.');
                        lorkhan_narrator_number('random_cooldown_rounds', 'Random Narration Cooldown', (int) ($content['random_cooldown_rounds'] ?? 2), 0, 10, 'Minimum completed non-narrator rounds between interjections. Default: 2.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Bored Events</h2>
                        <?php
                        lorkhan_narrator_toggle('bored_events', 'Allow Narrator Bored Events', ($content['bored_events'] ?? false) === true, 'Route some eligible bored events through the narrator instead of the selected NPC.');
                        lorkhan_narrator_number('bored_chance_percent', 'Narrator Bored Event Chance (%)', (int) ($content['bored_chance_percent'] ?? 25), 1, 100, 'Chance that an eligible bored event uses the narrator. Default: 25%.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Quest Comments</h2>
                        <?php
                        lorkhan_narrator_toggle('quest_events', 'Enable Quest Comments', ($content['quest_events'] ?? false) === true, 'Allow the narrator to comment on supported OpenMW quest events.');
                        lorkhan_narrator_number('quest_chance_percent', 'Quest Comment Chance (%)', (int) ($content['quest_chance_percent'] ?? 10), 1, 100, 'Chance that a newly observed journal update receives narrator commentary. Default: 10%.');
                        lorkhan_narrator_number('quest_cooldown_minutes', 'Quest Comment Cooldown (minutes)', (int) ($content['quest_cooldown_minutes'] ?? 3), 1, 60, 'Minimum in-game minutes between quest comments. Default: 3.');
                        ?>
                    </section>
                </div>

                <div class="narrator-content-grid">
                    <section class="narrator-content-section">
                        <h2>Profile &amp; Voice</h2>
                            <label for="narrator-core-profile">Profile</label>
                            <select id="narrator-core-profile" name="core_profile_id" data-narrator-connectors="<?php echo lorkhan_ui_h(json_encode($profileConnectorLabels,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); ?>">
                            <?php foreach ($coreRows as $core): if (($core['installation_id'] ?? '') !== $installationId) continue; ?>
                                <option value="<?php echo lorkhan_ui_h($core['core_profile_id']); ?>"<?php echo $selectedCoreId === $core['core_profile_id'] ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($core['label']); ?></option>
                            <?php endforeach; ?>
                            </select>
                            <span class="narrator-hint">LLM connector profile for The Narrator.</span>
                        <label for="narrator-voice">Voice ID</label>
                        <input id="narrator-voice" name="voice_id" type="text" value="<?php echo lorkhan_ui_h($voice['id'] ?? ''); ?>" placeholder="TheNarrator">
                        <span class="narrator-hint">TTS voice identifier for the narrator.</span>
                        <label for="narrator-oghma-knowledge">Oghma Knowledge Tags</label>
                        <input type="text" id="narrator-oghma-knowledge" name="oghma_knowledge_tags" maxlength="4096" placeholder="Comma-separated knowledge tags (e.g., knowall, knowsome, knownone)" value="<?php echo lorkhan_ui_h($content['oghma_knowledge_tags'] ?? ''); ?>">
                        <span class="narrator-hint">Comma-separated knowledge tags used by Oghma systems for knowledge lookup restrictions.</span>
                        <details class="narrator-voice-options"><summary>Advanced routing</summary>
                        <span class="narrator-hint">Profile generation uses the connector selected in <a href="<?php echo lorkhan_ui_h($webRoot); ?>/ui/global_settings.php">Global Settings</a>.</span>
                        <label for="narrator-tts">TTS Connector</label>
                        <select id="narrator-tts" name="tts_configuration_id">
                            <option value="">Use installation default</option>
                            <?php foreach ($ttsRows as $tts): if ((string) ($tts['installation_id'] ?? '') !== $installationId) continue; ?>
                                <option value="<?php echo lorkhan_ui_h($tts['configuration_id']); ?>"<?php echo (string) ($routing['tts_configuration_id'] ?? '') === (string) $tts['configuration_id'] ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($tts['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="narrator-hint">Typed speech connector used by the narrator.</span>
                        <label for="narrator-language">Voice Language</label>
                        <input id="narrator-language" name="voice_language" type="text" value="<?php echo lorkhan_ui_h($voice['language'] ?? 'en'); ?>">
                        </details>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Selected Profile Connectors</h2>
                        <dl class="narrator-connector-summary">
                            <?php foreach ($connectorFields as $key=>$label): ?>
                            <dt><?php echo lorkhan_ui_h($label); ?>:</dt><dd data-narrator-connector="<?php echo lorkhan_ui_h($key); ?>"><?php echo lorkhan_ui_h($profileConnectorLabels[$selectedCoreId][$key]??'—'); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                        <span class="narrator-hint">Connectors configured in the selected profile. An explicit narrator TTS selection overrides its speech connector.</span>
                    </section>
                </div>

                <section class="narrator-content-section narrator-full-width">
                    <h2>Prompt Head Override</h2>
                    <label for="narrator-prompt-head">Custom Prompt Head</label>
                    <textarea id="narrator-prompt-head" name="prompt_head" rows="5" placeholder="High-level system instructions injected before the core..."><?php echo lorkhan_ui_h($content['prompt_head'] ?? ''); ?></textarea>
                    <span class="narrator-hint">System preamble inserted before other narrator profile sections. Leave empty to inherit the normal prompt head.</span>
                </section>

                <section class="narrator-content-section narrator-full-width">
                    <h2>Character Description</h2>
                    <div class="narrator-dynamic-profile-card">
                        <div class="narrator-heading-with-badge"><h3>&#x267B;&#xFE0F; Dynamic Profile Updates</h3><?php echo lorkhan_ui_feature_badge('config.narrator.dynamic-profile', true); ?></div>
                        <?php $dynamicProfileFields=is_array($content['dynamic_profile_fields']??null)?$content['dynamic_profile_fields']:['personality','speech_style','goals']; ?>
                        <input type="hidden" name="dynamic_profile_fields_present" value="1">
                        <label class="narrator-toggle-row"><span class="narrator-toggle-switch"><input type="checkbox" name="dynamic_profile" value="1" aria-describedby="narrator-dynamic-help"<?php echo ($content['dynamic_profile']??false)===true?' checked':''; ?>><span class="narrator-toggle-slider" aria-hidden="true"></span></span><span class="narrator-toggle-label">Enable Dynamic Profile</span></label>
                        <span class="narrator-hint" id="narrator-dynamic-help">Every 20 minutes, evolve the selected fields from witnessed dialogue. Locked narrator profiles are never changed.</span>
                        <span class="narrator-hint">Field Selection (choose 1-3)</span>
                        <div class="narrator-field-chips"><?php foreach(['personality'=>'Personality','speech_style'=>'Speech Style','goals'=>'Goals']as$key=>$label): ?><label class="narrator-field-chip"><input type="checkbox" name="dynamic_profile_fields[]" value="<?php echo lorkhan_ui_h($key); ?>"<?php echo in_array($key,$dynamicProfileFields,true)?' checked':''; ?>> <?php echo lorkhan_ui_h($label); ?></label><?php endforeach; ?></div>
                    </div>

                    <label for="narrator-core">Core Summary</label>
                    <textarea id="narrator-core" name="core" rows="3" placeholder="Quick summary of the narrator persona..."><?php echo lorkhan_ui_h($content['core'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Brief summary of the narrator's role and boundaries.</span>
                    <label for="narrator-background">Background</label>
                    <textarea id="narrator-background" name="biography" rows="4"><?php echo lorkhan_ui_h($content['biography'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Detailed background and history of the narrator.</span>
                    <label for="narrator-personality">Personality</label>
                    <textarea id="narrator-personality" name="personality" rows="3"><?php echo lorkhan_ui_h($content['personality'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Behavioral traits and personality characteristics.</span>
                    <label for="narrator-speech-style">Speech Style</label>
                    <textarea id="narrator-speech-style" name="speech_style" rows="2"><?php echo lorkhan_ui_h($content['speech_style'] ?? ''); ?></textarea>
                    <span class="narrator-hint">How the narrator communicates and speaks.</span>
                    <label for="narrator-goals">Goals</label>
                    <textarea id="narrator-goals" name="goals" rows="3"><?php echo lorkhan_ui_h($content['goals'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Current goals and objectives for the narrator.</span>
                    <label for="narrator-notes">Additional Notes</label>
                    <textarea id="narrator-notes" name="notes" rows="3"><?php echo lorkhan_ui_h($content['notes'] ?? ''); ?></textarea>

                    <details class="narrator-advanced-wrap">
                        <summary class="narrator-advanced-summary"><span class="narrator-advanced-summary-text"><span class="narrator-advanced-summary-icon">▶</span><span>Narration Actions</span></span><span class="narrator-actions-summary-count">0 enabled / 0 total</span></summary>
                        <?php // ActionCatalogRepository currently defines no narrator-capable OpenMW actions. ?>
                        <div class="narrator-advanced-panel"><div class="narrator-actions-empty">No narrator-scoped actions were found.</div></div>
                    </details>
                    <details class="narrator-advanced-wrap" data-narrator-prompts>
                        <summary class="narrator-advanced-summary"><span class="narrator-advanced-summary-text"><span class="narrator-advanced-summary-icon">▶</span><span>Advanced Prompts (Prompts Manager)</span></span></summary>
                        <div class="narrator-advanced-panel">
                            <?php include __DIR__ . '/tmpl/narrator_prompt_rows.php'; ?>
                        </div>
                    </details>
                </section>

                <?php if ($profile !== null): ?><section class="narrator-content-section narrator-full-width"><label for="narrator-revision-note">Revision Note</label><input id="narrator-revision-note" name="change_reason" required maxlength="512" value="Management narrator update"><span class="narrator-hint">Saved as revision <?php echo lorkhan_ui_h((int) $profile['current_revision'] + 1); ?> of the typed narrator profile.</span></section><?php endif; ?>

                <div class="narrator-save-row"><button type="submit" class="narrator-save-button">Save Narration Settings</button><span class="unsaved-indicator" data-dirty-indicator hidden>Unsaved changes</span></div>
            </form>

            <?php if ($profile !== null): ?>
                <dialog class="narrator-portability modal-content" id="narrator-import-dialog" aria-labelledby="narrator-import-title">
                    <header class="modal-header"><h2 id="narrator-import-title">Import Narration Settings</h2><button type="button" class="narrator-transfer-button" data-narrator-import-close aria-label="Close import narration settings">&times;</button></header>
                    <div class="narrator-advanced-panel">
                        <p class="narrator-hint" id="narrator-portability-scope">A narrator preset carries narrator enablement, inline narration mode, narrator context visibility, the welcome, random, quest, and book event switches, the prompt head, core summary, background, personality, speech style, goals, and notes, and the narrator voice id and language. Provider and connector selections are never carried.</p>
                        <form class="narrator-portability-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/narrator-profile-settings-import">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                            <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                            <div class="narrator-portability-field">
                                <label for="narrator-preset-file">Preset file</label>
                                <input id="narrator-preset-file" type="file" accept="application/json,.json" data-json-import-target="narrator-preset-json" aria-describedby="narrator-portability-scope narrator-portability-help">
                            </div>
                            <div class="narrator-portability-field">
                                <label for="narrator-preset-json">Preset JSON</label>
                                <textarea id="narrator-preset-json" name="preset_json" rows="8" required spellcheck="false" placeholder="Choose an exported .json file or paste its contents here." aria-describedby="narrator-portability-scope narrator-portability-help"></textarea>
                            </div>
                            <p class="narrator-hint" id="narrator-portability-help">Choosing a file fills the box above, and pasting the document works the same way. Importing saves a new revision of this installation's existing narrator profile. It never creates or selects a narrator, and it never changes the narrator name and identity, the TTS connector and Profile Generation LLM routes, live OpenMW and playthrough context, dynamic profile state, or diary generation controls.</p>
                            <div class="narrator-portability-actions">
                                <button type="button" class="narrator-transfer-button" data-narrator-import-close>Cancel</button><button type="submit" class="narrator-save-button" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.narrator.import')['description']); ?>">Import Preset</button>
                            </div>
                        </form>
                    </div>
                </dialog>
            <?php endif; ?>

            <?php if ($profile !== null): ?>
                <details class="narrator-advanced-wrap">
                    <summary class="narrator-advanced-summary"><span class="narrator-advanced-summary-text"><span class="narrator-advanced-summary-icon">&#x25B6;</span><span>AI Profile Generation</span></span></summary>
                    <div class="narrator-advanced-panel">
                        <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/narrator-profile-generate" class="narrator-advanced-placeholder">
                            <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                            <input type="hidden" name="profile_id" value="<?php echo lorkhan_ui_h($profile['profile_id']); ?>">
                            <p>AI generation fills the narrator persona while preserving narrator enablement and voice routing.</p>
                            <button type="submit" class="narrator-save-button">Generate narrator profile with AI</button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php $rows = $narratorPromptRows; $narratorInlinePromptEditor = true; include __DIR__ . '/tmpl/prompt_dialogs.php'; ?>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/prompts-manager.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/prompts-manager.js')); ?>"></script>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
