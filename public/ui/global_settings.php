<?php

declare(strict_types=1);

use ALMSIVIserver\Application\EffectiveSettingsResolver;

$embedded = (string) ($_GET['embed'] ?? '') === '1';
$pageTitle = 'Global Settings';
$topNavSection = 'configuration';
$BODY_CLASS = 'hub-page global-settings-page-shell' . ($embedded ? ' embedded-page' : '');
require __DIR__ . '/ui_bootstrap.php';

$installations = $uiRepository->rows('installations');
$requested = trim((string) ($_GET['installation_id'] ?? ''));
$installationId = '';
foreach ($installations as $row) if ($requested !== '' && hash_equals((string) $row['installation_id'], $requested)) $installationId = $requested;
if ($installationId === '' && isset($installations[0])) $installationId = (string) $installations[0]['installation_id'];
$stored = $installationId === '' ? null : $productRepository->globalSettingsForInstallation($installationId);
$settings = is_array($stored['content'] ?? null) ? $stored['content'] : EffectiveSettingsResolver::defaults();
$autoLockProfile = $installationId === '' || $productRepository->profileAutoLockEnabled($installationId);
$oghmaSettings = $installationId === ''
    ? ['enabled'=>true,'knowledge_tags'=>'','racial_context_enabled'=>true,'location_context_enabled'=>true,'topic_count'=>1,'result_limit'=>3,'extractor_enabled'=>false,'extractor_timeout_ms'=>1500]
    : $productRepository->oghmaSettings($installationId);

$sections = [
    'prompt-rechat' => [
        'Prompt & Rechat' => [
            ['auto_greeting', 'Automatic Greeting', '&#x1F44B;', 'boolean', $settings['behavior']['auto_greeting'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['rechat', 'Rechat', '&#x1F501;', 'boolean', $settings['behavior']['rechat'], 'Continue a player-started conversation only after the current spoken-response queue finishes.', []],
            ['rechat_delay_seconds', 'Rechat Delay', '&#x23F1;&#xFE0F;', 'integer', $settings['behavior']['rechat_delay_seconds'], 'Automatic rechat scheduling is excluded from this build.', ['min' => 30, 'max' => 3600, 'feature' => 'autonomy']],
            ['rechat_max_depth', 'Rechat Rounds', '&#x1F4AC;', 'integer', $settings['behavior']['rechat_max_depth'], 'Higher values increase the number of times AI NPCs can go back-and-forth during a conversation.', ['min' => 1, 'max' => 20]],
            ['rechat_probability_percent', 'Rechat Probability', '&#x1F3B2;', 'integer', $settings['behavior']['rechat_probability_percent'], 'Chance that an AI NPC will continue an ongoing conversation.', ['min' => 0, 'max' => 100]],
            ['rechat_mode', 'Rechat Mode', '&#x1F501;', 'select', $settings['behavior']['rechat_mode'], 'Tight uses the listener, Conversational prefers the current partner, Group rotates nearby NPCs, and Random selects one mode per chain.', ['values' => ['tight', 'conversational', 'group', 'random']]],
            ['rechat_strict_targeting', 'Strict Rechat Targeting', '&#x1F3AF;', 'boolean', $settings['behavior']['rechat_strict_targeting'], 'Requires the selected responder to address the previous speaker directly.', []],
            ['open_rechat', 'Open Rechat', '&#x1F5E3;&#xFE0F;', 'boolean', $settings['behavior']['open_rechat'], 'Allows nearby scene participants to become the next responder when the selected mode permits it.', []],
            ['rechat_allow_actions', 'Allow Rechat Actions', '&#x2694;&#xFE0F;', 'boolean', false, 'Actions between NPCs remain disabled until the OpenMW action matrix is proven.', ['feature' => 'actions.rechat']],
            ['end_conversation_cooldown_seconds', 'End Conversation Cooldown', '&#x23F3;', 'integer', $settings['behavior']['end_conversation_cooldown_seconds'], 'Seconds an NPC remains ineligible for another rechat chain after ending a conversation.', ['min' => 0, 'max' => 300]],
            ['boredom', 'Boredom Events', '&#x1F4AD;', 'boolean', $settings['behavior']['boredom'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['boredom_delay_seconds', 'Boredom Delay', '&#x23F3;', 'integer', $settings['behavior']['boredom_delay_seconds'], 'Automatic boredom scheduling is excluded from this build.', ['min' => 30, 'max' => 86400, 'feature' => 'autonomy']],
            ['combat_barks', 'Combat Barks', '&#x2694;&#xFE0F;', 'boolean', $settings['behavior']['combat_barks'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['combat_bark_period_seconds', 'Combat Bark Period', '&#x1F6E1;&#xFE0F;', 'integer', $settings['behavior']['combat_bark_period_seconds'], 'Automatic combat-bark scheduling is excluded from this build.', ['min' => 5, 'max' => 300, 'feature' => 'autonomy']],
        ],
    ],
    'ai-memory' => [
        'Memory' => [
            ['memory_embedding_enabled', 'Enabled', '&#x1F9E0;', 'boolean', true, 'Enable long-term memory recall. The system injects relevant memory summaries into AI context for responses.', ['feature' => 'config.globals.memory-recall']],
            ['memory_embedding_url', 'MiniMe / TXT2VEC URL', '&#x1F517;', 'url', '', 'MiniMe/TXT2VEC service base URL. Use the local DwemerDistro service or the reachable URL of a remote service.', ['feature' => 'config.globals.memory-embedding']],
            ['memory_embedding_use_text2vec', 'Use Text2vec', '&#x1F524;', 'boolean', false, 'Use TXT2VEC to create embeddings for memories. These are more accurate than keywords.', ['feature' => 'config.globals.memory-embedding']],
            ['memory_summary_interval', 'Auto Create Summary Interval', '&#x23F1;&#xFE0F;', 'integer', 10, 'Time frame used to pack summary data. Each point is about 0.24 in-game hours.', ['min' => 0, 'max' => 100000, 'feature' => 'config.globals.memory-summary']],
            ['player_worst_memory_game_days', 'Worst Memory Lifespan', '&#x1F494;', 'integer', 7, 'How long the player\'s worst memory of an NPC lingers before it fades, in in-game days (0 = never forget).', ['min' => 0, 'max' => 365, 'feature' => 'config.globals.worst-memory']],
        ],
        'Misc' => [
            ['auto_lock_profile', 'Auto Lock Profile', '&#x1F512;', 'boolean', $autoLockProfile, 'When enabled, saving an NPC profile automatically locks it to prevent automatic updates from overwriting manual edits.', []],
            ['autofill_custom_profiles', 'Autofill Custom Profiles', '&#x2728;', 'boolean', false, 'Marks imported custom NPCs with blank AI profile fields for automatic profile backfill.', ['feature' => 'config.globals.profile-autofill']],
            ['autofill_custom_profiles_trigger', 'Autofill Custom Profiles Trigger', '&#x1F3AF;', 'integer', 40, 'Number of usable AI profile events required before a blank custom NPC is auto-filled.', ['min' => 10, 'max' => 100, 'feature' => 'config.globals.profile-autofill']],
            ['end_conversation_cooldown', 'End Conversation Cooldown', '&#x23F3;', 'integer', 60, 'Seconds an NPC is ineligible for AI dialogue after ending a conversation.', ['min' => 0, 'max' => 300, 'feature' => 'config.globals.conversation-cooldown']],
        ],
        'Quests' => [
            ['chim_ai_quest_progression', 'CHIM AI Quest Progression (Beta)', '&#x1F5FA;&#xFE0F;', 'boolean', false, 'Allows supported Skyrim quests to progress through AI dialogue.', ['feature' => 'config.globals.quest-progression']],
            ['chim_player_only_quest_advancement', 'Player Only Quest Advancement', '&#x1F9CD;', 'boolean', true, 'Limits CHIM AI quest beats and quest-stage actions to direct player dialogue.', ['feature' => 'config.globals.quest-progression']],
        ],
        'Translation' => [
            ['translation_provider', 'Provider', '&#x1F310;', 'select', 'none', 'Translate subtitles and/or audio into a different language.', ['values' => ['none', 'DeepL'], 'feature' => 'config.globals.translation']],
            ['translation_audio', 'Translate Audio', '&#x1F3A7;', 'boolean', false, 'NPC audio will be translated to the target language.', ['feature' => 'config.globals.translation']],
            ['translation_text', 'Translate Text', '&#x1F4DD;', 'boolean', false, 'NPC subtitles will be translated to the target language.', ['feature' => 'config.globals.translation']],
            ['translation_save_text', 'Save Translated Text', '&#x1F4BE;', 'boolean', false, 'Replaces NPC speech in context history with the translation.', ['feature' => 'config.globals.translation']],
            ['translation_player_audio', 'Translate Player Audio', '&#x1F399;&#xFE0F;', 'boolean', false, 'Player TTS audio will be translated to the player target language.', ['feature' => 'config.globals.translation']],
            ['translation_save_player_text', 'Save Translated Player Text', '&#x1F4BE;', 'boolean', false, 'Replaces player input in context history with the translation.', ['feature' => 'config.globals.translation']],
            ['translation_source_language', 'Source Language', '&#x1F5E3;&#xFE0F;', 'text', '', 'NPC source language. May be left blank for auto-detection.', ['feature' => 'config.globals.translation']],
            ['translation_target_language', 'Target Language', '&#x1F30D;', 'text', '', 'NPC target language to translate into.', ['feature' => 'config.globals.translation']],
            ['translation_endpoint_url', 'Endpoint URL', '&#x1F517;', 'url', 'https://api-free.deepl.com/v2/translate', 'DeepL endpoint URL for the selected account type.', ['feature' => 'config.globals.translation']],
            ['translation_player_source_language', 'Player Source Language', '&#x1F3A4;', 'text', '', 'Player source language. May be left blank for auto-detection.', ['feature' => 'config.globals.translation']],
            ['translation_player_target_language', 'Player Target Language', '&#x1F30E;', 'text', '', 'Player target language to translate into.', ['feature' => 'config.globals.translation']],
        ],
    ],
    'context-knowledge' => [
        'Context & Knowledge' => [
            ['oghma_enabled', 'Oghma Infinium', '&#x1F4DA;', 'boolean', $oghmaSettings['enabled'], 'Enable deterministic catalog grounding, access checks, and Oghma prompt context.', []],
            ['oghma_knowledge_tags', 'Oghma Knowledge Tags', '&#x1F4D9;', 'text', $oghmaSettings['knowledge_tags'], 'Installation knowledge classes inherited by Core Profiles and NPCs. Use comma-separated CHIM tags such as traveler, dunmer, scholar, or knowall. Leave empty for public basic access only; common is an article-only basic marker, not an NPC tag.', []],
            ['oghma_extractor_enabled', 'Oghma Topic Extractor', '&#x1F9E0;', 'boolean', $oghmaSettings['extractor_enabled'], 'Use the inherited connector once when local catalog grounding cannot resolve an explicit lore request. Connector suggestions must resolve to exact catalog topics.', []],
            ['oghma_topic_count', 'Extracted Topics', '&#x1F4DA;', 'integer', $oghmaSettings['topic_count'], 'Maximum conversational topics extracted and injected for each request.', ['min' => 1, 'max' => 3]],
            ['oghma_result_limit', 'Knowledge Results', '&#x1F4D1;', 'integer', $oghmaSettings['result_limit'], 'Maximum authorized or structured-denial Oghma articles injected for each request.', ['min' => 1, 'max' => 5]],
            ['oghma_extractor_timeout_ms', 'Extractor Timeout', '&#x23F1;&#xFE0F;', 'integer', $oghmaSettings['extractor_timeout_ms'], 'Maximum connector-fallback time in milliseconds. Local deterministic retrieval does not use this budget.', ['min' => 250, 'max' => 3000]],
            ['oghma_racial_context_enabled', 'Racial Knowledge Injection', '&#x1F9DD;', 'boolean', $oghmaSettings['racial_context_enabled'], 'Always consider the target and nearby NPC races as Oghma topics when matching articles exist.', []],
            ['oghma_location_context_enabled', 'Location Knowledge Injection', '&#x1F5FA;&#xFE0F;', 'boolean', $oghmaSettings['location_context_enabled'], 'Always consider the current cell, region, and named location as Oghma topics when matching articles exist.', []],
            ['narrator_welcome_events', 'Welcome Events', '&#x1F44B;', 'boolean', $settings['narrator']['welcome_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['narrator_random_events', 'Random Events', '&#x1F3B2;', 'boolean', $settings['narrator']['random_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['narrator_quest_events', 'Quest Events', '&#x1F5FA;&#xFE0F;', 'boolean', $settings['narrator']['quest_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
            ['narrator_book_events', 'Book Events', '&#x1F4D6;', 'boolean', $settings['narrator']['book_events'], 'Automatic model-triggering is excluded from this build.', ['feature' => 'autonomy']],
        ],
    ],
];

$additionalStylesheets = ['herika-global-settings.css?v=' . (string) filemtime(__DIR__ . '/css/herika-global-settings.css')];
include __DIR__ . '/tmpl/head.html';
if (!$embedded) include __DIR__ . '/tmpl/navbar.php';
?>
<main class="global-settings-page">
    <header class="page-header">
        <div class="page-header-row">
            <h1 class="gs-title">Global Settings</h1>
            <div class="page-header-actions">
                <button type="submit" class="btn-save-green" name="save_all" value="1" form="gs_form">Save All</button>
            </div>
        </div>
    </header>

    <?php if (isset($_GET['status'])): ?><div class="result-ok">Global settings saved to the database.</div><?php endif; ?>
    <?php if (count($installations) > 1): ?><div class="installation-row"><label>Installation <select data-installation-select><?php foreach ($installations as $row): ?><option value="<?php echo almsivi_ui_h($row['installation_id']); ?>"<?php echo $row['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo almsivi_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label></div><?php endif; ?>

    <nav class="settings-tabs" role="tablist" aria-label="Global settings categories">
        <?php foreach (['prompt-rechat' => '&#x1F4AC; Prompt & Rechat', 'ai-memory' => '&#x1F9E0; Memory & Others', 'context-knowledge' => '&#x1F4DA; Context & Knowledge'] as $tabId => $tabLabel): ?>
        <button type="button" class="settings-tab<?php echo $tabId === 'prompt-rechat' ? ' is-active' : ''; ?>" id="settings-tab-<?php echo almsivi_ui_h($tabId); ?>" role="tab" aria-selected="<?php echo $tabId === 'prompt-rechat' ? 'true' : 'false'; ?>" data-settings-tab="<?php echo almsivi_ui_h($tabId); ?>"><?php echo $tabLabel; ?></button>
        <?php endforeach; ?>
    </nav>

    <?php if ($installations === []): ?><section class="content-section">Connect OpenMW once before configuring installation settings.</section><?php else: ?>
    <form method="post" action="<?php echo almsivi_ui_h($managementBasePath); ?>/forms/global-settings-save" id="gs_form">
        <input type="hidden" name="_csrf" value="<?php echo almsivi_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo almsivi_ui_h($installationId); ?>">
        <input type="hidden" name="change_reason" value="Management global settings">
        <?php foreach ($settings['safety'] as $name => $enabled): if ($enabled): ?><input type="hidden" name="<?php echo almsivi_ui_h($name); ?>" value="1"><?php endif; endforeach; ?>
        <?php if ($settings['narrator']['enabled']): ?><input type="hidden" name="narrator_enabled" value="1"><?php endif; ?>
        <input type="hidden" name="narrator_name" value="<?php echo almsivi_ui_h($settings['narrator']['name']); ?>">
        <input type="hidden" name="narrator_inline_mode" value="<?php echo almsivi_ui_h($settings['narrator']['inline_mode']); ?>">
        <?php if ($settings['narrator']['context_visibility']): ?><input type="hidden" name="narrator_context_visibility" value="1"><?php endif; ?>
        <input type="hidden" name="recent_turn_limit" value="<?php echo almsivi_ui_h($settings['memory']['recent_turn_limit']); ?>">
        <input type="hidden" name="knowledge_limit" value="<?php echo almsivi_ui_h($settings['memory']['knowledge_limit']); ?>">
        <?php if ($settings['presentation']['show_status_hud']): ?><input type="hidden" name="show_status_hud" value="1"><?php endif; ?>
        <input type="hidden" name="transcript_rows" value="<?php echo almsivi_ui_h($settings['presentation']['transcript_rows']); ?>">
        <input type="hidden" name="tts_volume_boost" value="<?php echo almsivi_ui_h($settings['presentation']['tts_volume_boost']); ?>">
        <div class="content-grid">
            <?php foreach ($sections as $tabId => $tabSections): foreach ($tabSections as $sectionTitle => $fields): ?>
            <section class="content-section" role="tabpanel" aria-labelledby="settings-tab-<?php echo almsivi_ui_h($tabId); ?>" data-settings-panel="<?php echo almsivi_ui_h($tabId); ?>"<?php echo $tabId === 'prompt-rechat' ? '' : ' hidden'; ?>>
                <h2><?php echo almsivi_ui_h($sectionTitle); ?></h2>
                <div class="provider-grid">
                    <?php if ($tabId === 'prompt-rechat'): ?>
                    <?php foreach ([
                        ['Prompt Head', '&#x1F51D;', 'textarea', 'System Prompt. Defines the rules of the roleplay.', 'config.globals.prompt-head', []],
                        ['Emote Moods', '&#x1F3AD;', 'textarea', 'Default list of moods passed to the LLM. Core Profiles and NPCs can provide explicit profile text.', 'config.globals.emote-moods', []],
                    ] as [$label, $icon, $placeholderType, $help, $placeholderFeature, $placeholderOptions]): ?>
                    <div class="provider-card" title="<?php echo almsivi_ui_h(almsivi_ui_feature($placeholderFeature)['description']); ?>">
                        <div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo almsivi_ui_h($label); ?></span><?php echo almsivi_ui_feature_badge($placeholderFeature, true); ?><?php if ($placeholderType === 'boolean'): ?><span class="provider-toggle"><input type="checkbox" disabled aria-disabled="true" aria-label="<?php echo almsivi_ui_h($label); ?>"></span><?php endif; ?></div></div>
                        <div class="provider-body"><?php if ($placeholderType === 'textarea'): ?><textarea rows="4" disabled aria-disabled="true"></textarea><?php elseif ($placeholderType === 'select'): ?><select disabled aria-disabled="true"><?php foreach ($placeholderOptions as $option): ?><option<?php echo str_starts_with($option, 'Random') ? ' selected' : ''; ?>><?php echo almsivi_ui_h($option); ?></option><?php endforeach; ?></select><?php endif; ?></div>
                        <div class="provider-help"><?php echo almsivi_ui_h($help); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="provider-subsection-title">Roleplay Management</div>
                    <?php endif; ?>
                    <?php foreach ($fields as $field): [$name, $label, $icon, $type, $value, $help] = $field; $options = $field[6] ?? []; $placeholderFeature = (string) ($options['feature'] ?? ''); $disabled = $placeholderFeature !== ''; ?>
                    <div class="provider-card"<?php if ($disabled): ?> title="<?php echo almsivi_ui_h(almsivi_ui_feature($placeholderFeature)['description']); ?>"<?php endif; ?>>
                        <div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo almsivi_ui_h($label); ?></span><?php if ($disabled) echo almsivi_ui_feature_badge($placeholderFeature, true); ?><?php if ($type === 'boolean'): ?><span class="provider-toggle"><input type="checkbox" name="<?php echo almsivi_ui_h($name); ?>" value="1"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?><?php echo $value ? ' checked' : ''; ?> aria-label="<?php echo almsivi_ui_h($label); ?>"></span><?php endif; ?></div></div>
                        <div class="provider-body">
                            <?php if ($type === 'integer'): ?><input type="number" name="<?php echo almsivi_ui_h($name); ?>"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?> value="<?php echo almsivi_ui_h($value); ?>" min="<?php echo almsivi_ui_h($options['min']); ?>" max="<?php echo almsivi_ui_h($options['max']); ?>" step="1" aria-label="<?php echo almsivi_ui_h($label); ?>">
                            <?php elseif ($type === 'select'): ?><select name="<?php echo almsivi_ui_h($name); ?>"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?> aria-label="<?php echo almsivi_ui_h($label); ?>"><?php foreach ($options['values'] as $option): ?><option value="<?php echo almsivi_ui_h($option); ?>"<?php echo $option === $value ? ' selected' : ''; ?>><?php echo almsivi_ui_h($option); ?></option><?php endforeach; ?></select>
                            <?php elseif ($type === 'text' || $type === 'url'): ?><input type="<?php echo $type; ?>" name="<?php echo almsivi_ui_h($name); ?>"<?php if ($disabled): ?> disabled aria-disabled="true"<?php endif; ?> value="<?php echo almsivi_ui_h($value); ?>" maxlength="512" aria-label="<?php echo almsivi_ui_h($label); ?>">
                            <?php endif; ?>
                        </div>
                        <div class="provider-help"><?php echo almsivi_ui_h($help); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; endforeach; ?>
        </div>
    </form>
    <?php endif; ?>
</main>
<script defer src="<?php echo almsivi_ui_h($webRoot); ?>/ui/js/global-settings.js?v=<?php echo almsivi_ui_h((string) filemtime(__DIR__ . '/js/global-settings.js')); ?>"></script>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
