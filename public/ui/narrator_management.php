<?php

declare(strict_types=1);

$pageTitle = 'Narrator Management';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page narrator-page-shell' . (((string) ($_GET['embed'] ?? '')) === '1' ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$rows = $uiRepository->rows('narrator');
$ttsRows = $uiRepository->rows('tts');
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
$voice = is_array($content['voice'] ?? null) ? $content['voice'] : [];
$embedded = ($_GET['embed'] ?? '') === '1';

$selectedTtsLabel = 'Use installation default';
foreach ($ttsRows as $tts) {
    if ((string) ($tts['installation_id'] ?? '') !== $installationId) continue;
    if ((string) ($routing['tts_configuration_id'] ?? '') === (string) ($tts['configuration_id'] ?? '')) {
        $selectedTtsLabel = (string) ($tts['name'] ?? 'Configured TTS');
        break;
    }
}

/** Render one Herika-style live switch backed by the typed narrator document. */
function almsivi_narrator_toggle(string $name, string $label, bool $checked, string $hint): void
{
    echo '<label class="narrator-toggle-row"><span class="narrator-toggle-switch"><input type="checkbox" name="' . almsivi_ui_h($name) . '" value="1"' . ($checked ? ' checked' : '') . '><span class="narrator-toggle-slider"></span></span><span class="narrator-toggle-label">' . almsivi_ui_h($label) . '</span></label><span class="narrator-hint">' . almsivi_ui_h($hint) . '</span>';
}

/** Render a copied Herika switch that remains visible but cannot mutate ALMSIVI state. */
function almsivi_narrator_placeholder_toggle(string $label, string $featureId, string $hint): void
{
    echo '<label class="narrator-toggle-row is-placeholder"><span class="narrator-toggle-switch"><input type="checkbox" disabled aria-disabled="true"><span class="narrator-toggle-slider"></span></span><span class="narrator-toggle-label">' . almsivi_ui_h($label) . '</span>' . almsivi_ui_feature_badge($featureId, true) . '</label><span class="narrator-hint">' . almsivi_ui_h($hint) . '</span>';
}

/** Render one inert numeric control using the same field rhythm as Herika. */
function almsivi_narrator_placeholder_number(string $label, string $featureId, string $value, string $hint): void
{
    echo '<div class="narrator-placeholder-field"><label>' . almsivi_ui_h($label) . almsivi_ui_feature_badge($featureId, true) . '</label><input type="number" value="' . almsivi_ui_h($value) . '" disabled aria-disabled="true"><span class="narrator-hint">' . almsivi_ui_h($hint) . '</span></div>';
}

$additionalStylesheets = ['herika-narrator.css?v=' . (string) filemtime(__DIR__ . '/css/herika-narrator.css')];
$includeManagementStyles = false;
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="narrator-page<?php echo $embedded ? ' embedded' : ''; ?>">
    <div class="narrator-page-container">
        <div class="page-header">
            <h1>&#x1F5E3;&#xFE0F; Narrator Management</h1>
            <p>Configure narrator behavior and settings</p>
        </div>

        <?php if (isset($_GET['status'])): ?><div class="almsivi-status" role="status">Narrator profile saved.</div><?php endif; ?>

        <?php if ($installations === []): ?>
            <section class="narrator-content-section">Connect OpenMW once before configuring narration.</section>
        <?php else: ?>
            <?php if (count($installations) > 1): ?>
                <section class="narrator-content-section narrator-installation">
                    <label for="narrator-installation">Installation</label>
                    <select id="narrator-installation" data-installation-select>
                        <?php foreach ($installations as $installation): ?>
                            <option value="<?php echo almsivi_ui_h($installation['installation_id']); ?>"<?php echo (string) $installation['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($installation['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </section>
            <?php endif; ?>

            <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/<?php echo $profile === null ? 'narrator-profile-create' : 'narrator-profile-revise'; ?>">
                <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
                <?php if ($profile !== null): ?>
                    <input type="hidden" name="profile_id" value="<?php echo almsivi_ui_h($profile['profile_id']); ?>">
                    <input type="hidden" name="base_content_json" value="<?php echo almsivi_ui_h(json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?>">
                <?php endif; ?>

                <div class="narrator-save-row">
                    <button type="submit" class="narrator-save-button">Save Narration Settings</button>
                </div>

                <div class="narrator-content-grid">
                    <section class="narrator-content-section">
                        <h2>Core Settings</h2>
                        <label for="narrator-name">Narrator Name</label>
                        <input id="narrator-name" name="name" type="text" maxlength="256" required value="<?php echo almsivi_ui_h($profile['name'] ?? 'The Narrator'); ?>"<?php echo $profile === null ? '' : ' readonly'; ?>>
                        <span class="narrator-hint">Changes how the narrator is identified in prompts, context, subtitles, and history displays.</span>
                        <?php
                        almsivi_narrator_toggle('enabled', 'Enable Narrator', ($content['enabled'] ?? false) === true, 'Enable or disable the narrator system entirely.');
                        almsivi_narrator_placeholder_toggle('Only the Narrator can Summarize Books', 'config.narrator.event-tuning', 'Exclusive book-summary routing is not connected to OpenMW yet.');
                        almsivi_narrator_toggle('book_events', 'Narrate Book Events', ($content['book_events'] ?? false) === true, 'Allow the narrator to respond to supported book events.');
                        almsivi_narrator_toggle('context_visibility', 'Include Narrator Context in Prompts', ($content['context_visibility'] ?? false) === true, 'Include narrator profile context when assembling NPC prompts.');
                        almsivi_narrator_placeholder_toggle('Narrator Diary', 'config.narrator.diaries', 'Narrator-specific manual diary permissions are planned.');
                        almsivi_narrator_placeholder_toggle('Narrator Auto Diary', 'config.narrator.diaries', 'Narrator-specific automatic diary generation is planned.');
                        almsivi_narrator_placeholder_toggle('Narrator only diary access', 'config.narrator.diaries', 'Narrator-specific diary recall permissions are planned.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Inline Narration</h2>
                        <label for="inline-narration-mode">Inline Narration Mode</label>
                        <select id="inline-narration-mode" name="inline_narration_mode">
                            <?php foreach (['Disabled', 'Narrator', 'NPC', 'Text Only'] as $mode): ?>
                                <option<?php echo (string) ($content['inline_narration_mode'] ?? 'Disabled') === $mode ? ' selected' : ''; ?>><?php echo almsivi_ui_h($mode); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="narrator-hint">Controls leading *narration* blocks. Narrator uses the narrator voice, NPC speaks the full line, Text Only displays narration without speech, and Disabled turns off special routing.</span>
                        <?php
                        almsivi_narrator_placeholder_toggle('Remove Player Input Asterisks From TTS', 'config.narrator.asterisks', 'Per-source player TTS filtering is not configurable yet.');
                        almsivi_narrator_placeholder_toggle('Remove NPC Output Asterisks', 'config.narrator.asterisks', 'Per-source NPC output filtering is not configurable yet.');
                        almsivi_narrator_placeholder_toggle('Remove Player Autochat Asterisk', 'config.narrator.asterisks', 'Autochat-specific filtering is not configurable yet.');
                        almsivi_narrator_placeholder_toggle('Keep NPC Narration Description in Context History', 'config.narrator.asterisks', 'Context-history preservation is handled by the typed response pipeline.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Welcome Message</h2>
                        <?php
                        almsivi_narrator_toggle('welcome_events', 'Enable Welcome Message on Load', ($content['welcome_events'] ?? false) === true, 'Allow a narrator welcome event after a supported OpenMW session load.');
                        almsivi_narrator_placeholder_number('Welcome Message Cooldown (minutes)', 'config.narrator.event-tuning', '10', 'Per-event cooldown tuning is planned.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Random Narration</h2>
                        <?php
                        almsivi_narrator_toggle('random_events', 'Enable Random Narration', ($content['random_events'] ?? false) === true, 'Allow supported random events to route through the narrator.');
                        almsivi_narrator_placeholder_number('Random Narration Chance (%)', 'config.narrator.event-tuning', '15', 'Per-event probability tuning is planned.');
                        almsivi_narrator_placeholder_number('Random Narration Cooldown', 'config.narrator.event-tuning', '2', 'Per-event cooldown tuning is planned.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Bored Events</h2>
                        <?php
                        almsivi_narrator_placeholder_toggle('Allow Narrator Bored Events', 'config.narrator.bored-events', 'Bored-event narrator routing is not connected yet.');
                        almsivi_narrator_placeholder_number('Narrator Bored Event Chance (%)', 'config.narrator.bored-events', '25', 'Bored-event probability tuning is not connected yet.');
                        ?>
                    </section>

                    <section class="narrator-content-section">
                        <h2>Quest Comments</h2>
                        <?php
                        almsivi_narrator_toggle('quest_events', 'Enable Quest Comments', ($content['quest_events'] ?? false) === true, 'Allow the narrator to comment on supported OpenMW quest events.');
                        almsivi_narrator_placeholder_number('Quest Comment Chance (%)', 'config.narrator.event-tuning', '10', 'Per-event probability tuning is planned.');
                        almsivi_narrator_placeholder_number('Quest Comment Cooldown (minutes)', 'config.narrator.event-tuning', '3', 'Per-event cooldown tuning is planned.');
                        ?>
                    </section>
                </div>

                <div class="narrator-content-grid">
                    <section class="narrator-content-section">
                        <h2>Profile &amp; Voice</h2>
                        <label for="narrator-generation-llm">Profile Generation LLM</label>
                        <select id="narrator-generation-llm" name="profile_generation_configuration_id" aria-describedby="narrator-generation-help">
                            <?php foreach ($generationOptions as $id => $label): ?>
                                <option value="<?php echo almsivi_ui_h($id); ?>"<?php echo $id === $generationValue ? ' selected' : ''; ?>><?php echo almsivi_ui_h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="narrator-hint" id="narrator-generation-help">Applies to newly queued generation jobs. Queued jobs keep their frozen connector revision; saving never calls a provider.</span>
                        <div class="narrator-placeholder-field">
                            <label>Profile <?php echo almsivi_ui_feature_badge('config.narrator.profile-connectors', true); ?></label>
                            <select disabled aria-disabled="true"><option>ALMSIVI narrator profile</option></select>
                            <span class="narrator-hint">ALMSIVI stores the narrator as its own versioned typed profile.</span>
                        </div>
                        <label for="narrator-tts">TTS Connector</label>
                        <select id="narrator-tts" name="tts_configuration_id">
                            <option value="">Use installation default</option>
                            <?php foreach ($ttsRows as $tts): if ((string) ($tts['installation_id'] ?? '') !== $installationId) continue; ?>
                                <option value="<?php echo almsivi_ui_h($tts['configuration_id']); ?>"<?php echo (string) ($routing['tts_configuration_id'] ?? '') === (string) $tts['configuration_id'] ? ' selected' : ''; ?>><?php echo almsivi_ui_h($tts['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="narrator-hint">Typed speech connector used by the narrator.</span>
                        <label for="narrator-voice">Voice ID</label>
                        <input id="narrator-voice" name="voice_id" type="text" value="<?php echo almsivi_ui_h($voice['id'] ?? ''); ?>" placeholder="TheNarrator">
                        <span class="narrator-hint">TTS voice identifier for the narrator.</span>
                        <label for="narrator-language">Voice Language</label>
                        <input id="narrator-language" name="voice_language" type="text" value="<?php echo almsivi_ui_h($voice['language'] ?? 'en'); ?>">
                    </section>

                    <section class="narrator-content-section">
                        <div class="narrator-heading-with-badge"><h2>Selected Profile Connectors</h2><?php echo almsivi_ui_feature_badge('config.narrator.profile-connectors', true); ?></div>
                        <dl class="narrator-connector-summary">
                            <dt>&#x1F50A; TTS:</dt><dd><?php echo almsivi_ui_h($selectedTtsLabel); ?></dd>
                            <dt>&#x1F579;&#xFE0F; Standard:</dt><dd>Inherited ALMSIVI model route</dd>
                            <dt>&#x1F3C3; Fast:</dt><dd>Inherited ALMSIVI model route</dd>
                            <dt>&#x1F4AA; Power:</dt><dd>Inherited ALMSIVI model route</dd>
                            <dt>&#x1F9EA; Experimental:</dt><dd>Inherited ALMSIVI model route</dd>
                            <dt>&#x1F4D3; Diary:</dt><dd>Replaced</dd>
                            <dt>&#x1F9FE; Formatter:</dt><dd>Replaced</dd>
                        </dl>
                        <span class="narrator-hint">ALMSIVI keeps explicit narrator speech routing while model selection follows the typed inherited settings pipeline.</span>
                    </section>
                </div>

                <section class="narrator-content-section narrator-full-width">
                    <h2>Prompt Head Override</h2>
                    <label for="narrator-prompt-head">Custom Prompt Head</label>
                    <textarea id="narrator-prompt-head" name="prompt_head" rows="5" placeholder="High-level system instructions injected before the core..."><?php echo almsivi_ui_h($content['prompt_head'] ?? ''); ?></textarea>
                    <span class="narrator-hint">System preamble inserted before other narrator profile sections. Leave empty to inherit the normal prompt head.</span>
                </section>

                <section class="narrator-content-section narrator-full-width">
                    <h2>Character Description</h2>
                    <div class="narrator-dynamic-profile-card">
                        <div class="narrator-heading-with-badge"><h3>&#x267B;&#xFE0F; Dynamic Profile Updates</h3><?php echo almsivi_ui_feature_badge('config.narrator.dynamic-profile', true); ?></div>
                        <?php almsivi_narrator_placeholder_toggle('Enable Dynamic Profile', 'config.narrator.dynamic-profile', 'Automatic narrator profile evolution is not connected to OpenMW yet.'); ?>
                        <span class="narrator-hint">Field Selection (choose 1-3)</span>
                        <div class="narrator-field-chips"><span class="narrator-field-chip">Personality</span><span class="narrator-field-chip">Speech Style</span><span class="narrator-field-chip">Goals</span></div>
                    </div>

                    <label for="narrator-core">Core Summary</label>
                    <textarea id="narrator-core" name="core" rows="3" placeholder="Quick summary of the narrator persona..."><?php echo almsivi_ui_h($content['core'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Brief summary of the narrator's role and boundaries.</span>
                    <label for="narrator-background">Background</label>
                    <textarea id="narrator-background" name="biography" rows="4"><?php echo almsivi_ui_h($content['biography'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Detailed background and history of the narrator.</span>
                    <label for="narrator-personality">Personality</label>
                    <textarea id="narrator-personality" name="personality" rows="3"><?php echo almsivi_ui_h($content['personality'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Behavioral traits and personality characteristics.</span>
                    <label for="narrator-speech-style">Speech Style</label>
                    <textarea id="narrator-speech-style" name="speech_style" rows="2"><?php echo almsivi_ui_h($content['speech_style'] ?? ''); ?></textarea>
                    <span class="narrator-hint">How the narrator communicates and speaks.</span>
                    <label for="narrator-goals">Goals</label>
                    <textarea id="narrator-goals" name="goals" rows="3"><?php echo almsivi_ui_h($content['goals'] ?? ''); ?></textarea>
                    <span class="narrator-hint">Current goals and objectives for the narrator.</span>
                    <label for="narrator-notes">Additional Notes</label>
                    <textarea id="narrator-notes" name="notes" rows="3"><?php echo almsivi_ui_h($content['notes'] ?? ''); ?></textarea>

                    <?php foreach ([
                        'config.narrator.actions' => ['Narrator Actions', 'Manage Narrator Actions'],
                        'config.narrator.prompts' => ['Advanced Prompts (Prompts Manager)', 'Edit Narrator Prompt'],
                    ] as $featureId => [$label, $control]): ?>
                        <details class="narrator-advanced-wrap">
                            <summary class="narrator-advanced-summary"><span class="narrator-advanced-summary-text"><span class="narrator-advanced-summary-icon">&#x25B6;</span><span><?php echo almsivi_ui_h($label); ?></span></span><?php echo almsivi_ui_feature_badge($featureId, true); ?></summary>
                            <div class="narrator-advanced-panel"><div class="narrator-advanced-placeholder"><p><?php echo almsivi_ui_h(almsivi_ui_feature($featureId)['description']); ?></p><button type="button" disabled aria-disabled="true"><?php echo almsivi_ui_h($control); ?></button></div></div>
                        </details>
                    <?php endforeach; ?>
                </section>

                <?php if ($profile !== null): ?><section class="narrator-content-section narrator-full-width"><label for="narrator-revision-note">Revision Note</label><input id="narrator-revision-note" name="change_reason" required maxlength="512" value="Management narrator update"><span class="narrator-hint">Saved as revision <?php echo almsivi_ui_h((int) $profile['current_revision'] + 1); ?> of the typed narrator profile.</span></section><?php endif; ?>

                <div class="narrator-save-row"><button type="submit" class="narrator-save-button">Save Narration Settings</button></div>
            </form>

            <?php if ($profile !== null): ?>
                <details class="narrator-advanced-wrap">
                    <summary class="narrator-advanced-summary"><span class="narrator-advanced-summary-text"><span class="narrator-advanced-summary-icon">&#x25B6;</span><span>AI Profile Generation</span></span></summary>
                    <div class="narrator-advanced-panel">
                        <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/narrator-profile-generate" class="narrator-advanced-placeholder">
                            <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
                            <input type="hidden" name="profile_id" value="<?php echo almsivi_ui_h($profile['profile_id']); ?>">
                            <p>AI generation fills the narrator persona while preserving narrator enablement and voice routing.</p>
                            <button type="submit" class="narrator-save-button">Generate narrator profile with AI</button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
