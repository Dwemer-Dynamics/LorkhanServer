<?php

declare(strict_types=1);

use LorkhanServer\Application\EffectiveSettingsResolver;
use LorkhanServer\Application\SettingsCatalog;
use LorkhanServer\Application\TranslationPolicy;

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
$storedContent = is_array($stored['content'] ?? null) ? $stored['content'] : [];
$memorySummary = $installationId === '' ? [] : ($productRepository->memorySummaryPolicyForInstallation($installationId)['content'] ?? []);
$memoryEmbedding = $installationId === '' ? [] : ($productRepository->memoryEmbeddingPolicyForInstallation($installationId)['content'] ?? []);
$autoLockProfile = $installationId === '' || $productRepository->profileAutoLockEnabled($installationId);
$oghmaSettings = $installationId === ''
    ? ['enabled'=>true,'knowledge_tags'=>'','racial_context_enabled'=>true,'location_context_enabled'=>true,'topic_count'=>1,'result_limit'=>3,'extractor_enabled'=>false,'extractor_timeout_ms'=>1500]
    : $productRepository->oghmaSettings($installationId);
$translationPolicy = $installationId === '' ? TranslationPolicy::defaults()
    : $productRepository->translationPolicyForInstallation($installationId)['content'];
$globalDocument = EffectiveSettingsResolver::globalDocument($storedContent, $oghmaSettings, $translationPolicy, $autoLockProfile);
if (($storedContent['schema'] ?? null) !== SettingsCatalog::GLOBAL_SCHEMA && $installationId !== '') {
    $defaultCore = $productRepository->defaultCoreProfileForInstallation($installationId);
    $legacyRouting = is_array($defaultCore['content']['routing'] ?? null) ? $defaultCore['content']['routing'] : [];
    foreach (SettingsCatalog::systemRoutingFields() as $field) {
        if (is_string($legacyRouting[$field] ?? null)) $globalDocument['system_routing'][$field] = $legacyRouting[$field];
    }
    $legacyRelationship = $defaultCore['content']['settings_overrides']['relationship']['update_chance_percent'] ?? null;
    if (is_int($legacyRelationship)) {
        $globalDocument['relationship']['update_chance_percent'] = $legacyRelationship;
        $globalDocument['relationship']['enabled'] = $legacyRelationship > 0
            && $globalDocument['system_routing']['relationship_configuration_id'] !== '';
    }
}
$settings = $globalDocument['client'];
$namedPresets = $installationId === '' ? [] : $managementRepository->globalSettingsPresets($installationId);
$autoLockProfile = $globalDocument['profile_management']['auto_lock_profile'];
$autofillCustomProfiles = $globalDocument['profile_management']['autofill_custom_profiles'];
$autofillCustomProfilesTrigger = $globalDocument['profile_management']['autofill_custom_profiles_trigger'];
$oghmaSettings = $globalDocument['oghma'];
$translationPolicy = $globalDocument['translation'];
$contextPolicy = $globalDocument['context'];
$relationshipSettings = $globalDocument['relationship'];
$systemRouting = $globalDocument['system_routing'];
$contextGroups = [
    'Top-Level Sections' => ['sections', [
        'player_narrator' => ['Player & Narrator', 'Player profile and narrator context.'],
        'world' => ['World', 'Recorded location, weather and world state.'],
        'people_present' => ['People Present', 'People participating in the current scene.'],
        'nearby_actors' => ['Nearby Actors', 'Details about other nearby characters.'],
        'nearby_items' => ['Nearby Items', 'Items observed around the speaker.'],
        'points_of_interest' => ['Points of Interest', 'Nearby doors, containers and activators.'],
        'record_descriptions' => ['Record Descriptions', 'Saved descriptions for observed items.'],
        'oghma' => ['Oghma Knowledge', 'Retrieved, authorized world knowledge.'],
        'relationships' => ['Relationships', 'Saved relationship context.'],
        'memories' => ['Memories', 'Retrieved memories relevant to the conversation.'],
        'narratives' => ['Narratives', 'Eligible diary and narrative context.'],
        'conversation_history' => ['Conversation History', 'Previous dialogue and selected background events.'],
        'recent_action_results' => ['Recent Action Results', 'Outcomes of recently requested actions.'],
    ]],
    'Character Subsections' => ['details', [
        'npc_summary' => ['<basic_summary>', 'Core background summary or short biography.'],
        'npc_personality' => ['<personality>', 'Behavioral traits, psychology, and temperament.'],
        'npc_relationships' => ['<relationships>', 'Named relationships and relevant social ties.'],
        'npc_occupation' => ['<occupation>', 'Job, societal role, or current profession.'],
        'npc_skills' => ['<skills>', 'Narrative skills, talents, and expertise.'],
        'npc_speech_style' => ['<speech_style>', 'Speaking style and communication habits.'],
        'npc_goals' => ['<goals>', 'Current ambitions, motivations, and long-term aims.'],
        'npc_moods' => ['Allowed Moods & Emotes', 'Allowed moods and emotes from the speaker profile.'],
        'npc_notes' => ['Notes', 'Additional notes from the speaker profile.'],
        'npc_race_gender' => ['Race & Gender', 'The speaker’s race and gender.'],
    ]],
    'Appearance / State Subsections' => ['details', [
        'npc_appearance' => ['<appearance>', 'Physical appearance and identifying features.'],
        'npc_equipment' => ['<equipment>', 'Currently equipped gear and worn items.'],
        'npc_inventory' => ['<inventory>', 'Inventory listing.'],
        'npc_current_state' => ['Current State', 'Observed activity, disposition and health.'],
        'npc_magic_effects' => ['Magic & Effects', 'Observed spells and active effects within current state.'],
    ]],
    'Nearby Actor Details' => ['details', [
        'nearby_actor_summary' => ['Basic summary', 'Nearby actor profile summary or short biography.'],
        'nearby_actor_personality' => ['Personality', 'Nearby actors’ personality traits.'],
        'nearby_actor_appearance' => ['Appearance', 'Nearby actor physical appearance and visible traits.'],
        'nearby_actor_equipment' => ['Equipment', 'Nearby actor currently equipped gear and worn items.'],
        'nearby_actor_occupation' => ['Occupation', 'Nearby actors’ occupations.'],
        'nearby_actor_activity' => ['Current activity', 'What nearby actors are currently doing.'],
    ]],
    'Nearby Item Details' => ['details', [
        'group_duplicate_items' => ['Group duplicates', 'Groups duplicate nearby ground items into counted entries.'],
        'item_descriptions' => ['Item descriptions', 'Adds item descriptions for nearby ground items when available.'],
    ]],
];
$llmOptions = ['' => 'Disabled'];
foreach ($uiRepository->rows('llm') as $row) {
    $id = (string)($row['configuration_id'] ?? '');
    if ($id !== '') $llmOptions[$id] = (string)($row['name'] ?? $id);
}

$globalSettingsRow = null;
if ($installationId !== '') {
    foreach ($uiRepository->rows('global_settings') as $row) {
        if (hash_equals((string) ($row['installation_id'] ?? ''), $installationId)) { $globalSettingsRow = $row; break; }
    }
}
$settingsConfigurationId = (string) ($globalSettingsRow['configuration_id'] ?? $stored['configuration_id'] ?? '');
$settingsRevision = (int) ($globalSettingsRow['current_revision'] ?? $stored['current_revision'] ?? 0);
$settingsSavedAt = trim((string) ($globalSettingsRow['created_at'] ?? ''));
$revisionHistory = is_array($globalSettingsRow['revisions'] ?? null) ? $globalSettingsRow['revisions'] : [];
$hasStoredSettings = $settingsConfigurationId !== '' && $settingsRevision > 0;
$earlierRevisions = array_values(array_filter(
    $revisionHistory,
    static fn(mixed $revision): bool => is_array($revision) && (int) ($revision['revision'] ?? 0) > 0 && (int) ($revision['revision'] ?? 0) < $settingsRevision
));
$portableScopeNote = 'A portable file includes shared prompt context, blacklists, automatic dialogue, Rechat, Oghma, translation, relationship evaluation, Auto Lock Profile, and system connector assignments. Memory summary scheduling, its connector reference, and MiniMe settings are included. Older presets preserve these local settings. It never includes installation identity, revision history, API keys, Core Profile response connectors, NPC profiles, voices, or assignments.';
$statusMessages = [
    'preset-applied' => 'Settings preset applied as a new revision.',
    'saved' => 'Global settings saved to the database.',
    'imported' => 'Preset imported as a new Global Settings revision.',
    'rolled-back' => 'Earlier revision restored as a new Global Settings revision.',
];

$sections = [
    'prompt-rechat' => [
        'Prompt & Rechat' => [
            ['prompt_head', 'Prompt Head', '&#x1F51D;', 'textarea', $globalDocument['prompt']['prompt_head'], 'System prompt defining the roleplay. Used when an NPC has no Prompt Head override. Leave blank to retain the built-in Morrowind roleplay instructions.', ['maxlength'=>8192]],
            ['emote_moods', 'Emote Moods', '&#x1F3AD;', 'textarea', $globalDocument['prompt']['emote_moods'], 'Default comma-separated moods passed to the model. Can be overridden per NPC. Leave blank to keep the existing NPC moods.', ['maxlength'=>4096]],
            ['rechat_mode', 'Rechat Mode', '&#x1F501;', 'select', $settings['behavior']['rechat_mode'], 'Tight uses the listener, Conversational prefers the current partner, Group rotates nearby NPCs, and Random selects one mode per chain.', ['values' => ['tight'=>'Tight', 'conversational'=>'Conversational', 'group'=>'Group', 'random'=>'Random (Recommended)']]],
            ['rechat_strict_targeting', 'Strict Rechat Targeting', '&#x1F3AF;', 'boolean', $settings['behavior']['rechat_strict_targeting'], 'Requires the selected responder to address the previous speaker directly.', []],
            ['relationship_update_chance_percent', 'Relationship Update Chance', '&#x1F3B2;', 'integer', $relationshipSettings['update_chance_percent'], 'Chance from 0 to 100 that an eligible completed conversation is evaluated.', ['min' => 0, 'max' => 100]],
        ],
    ],
    'ai-memory' => [
        'Memory' => [
            ['memory_embedding_enabled', 'Memory Embedding', '&#x1F9E0;', 'boolean', $memoryEmbedding['enabled'] ?? false, 'Use semantic memory retrieval. Existing lexical retrieval remains available if the service cannot be reached.', []],
            ['memory_embedding_endpoint', 'MiniMe / TXT2VEC URL', '&#x1F517;', 'url', $memoryEmbedding['endpoint'] ?? '', 'Address of your memory embedding service. Use a loopback HTTP address or an HTTPS endpoint.', []],
            ['memory_summary_interval', 'Summary Interval', '&#x23F3;', 'integer', $memorySummary['summary_interval'] ?? 0, 'Each point represents 0.24 in-game hours. 10 = 2.4 hours; 50 = 12 hours. Zero uses event-count grouping.', ['min'=>0,'max'=>100]],
            ['memory_embedding_timeout', 'Memory Query Timeout', '&#x23F1;', 'integer', $memoryEmbedding['timeout_ms'] ?? 1500, 'Maximum wait for one semantic query, in milliseconds.', ['min'=>250,'max'=>5000,'advanced'=>true]],
            ['memory_summary_minimum_events', 'Minimum Summary Events', '&#x1F4AC;', 'integer', $memorySummary['minimum_events'] ?? 4, 'Minimum eligible memories before a summary group is created.', ['min'=>2,'max'=>16,'advanced'=>true]],
        ],
        'Misc' => [
            ['auto_lock_profile', 'Auto Lock Profile', '&#x1F512;', 'boolean', $autoLockProfile, 'When enabled, saving an NPC profile automatically locks it to prevent automatic updates from overwriting manual edits.', []],
            ['autofill_custom_profiles', 'Automatic Profile Backfill', '&#x2728;', 'boolean', $autofillCustomProfiles, 'Fill an unlocked NPC profile with AI after it has enough completed dialogue history.', []],
            ['autofill_custom_profiles_trigger', 'Profile Backfill Trigger', '&#x1F4AC;', 'integer', $autofillCustomProfilesTrigger, 'Completed dialogue turns required before an empty unlocked NPC profile is generated.', ['min' => 10, 'max' => 100]],
            ['end_conversation_cooldown_seconds', 'End Conversation Cooldown', '&#x23F3;', 'integer', $settings['behavior']['end_conversation_cooldown_seconds'], 'Seconds an NPC remains ineligible for another rechat chain after ending a conversation.', ['min' => 0, 'max' => 300]],
        ],
        'RPG Comments' => [
            ['rpg_events','Comment Events','&#x1F4AC;','multiselect',$globalDocument['rpg_comments']['events'],'Nearby NPCs may comment on these observed game events while dialogue is idle.',['values'=>['levelup'=>'Level Up','combat_end'=>'Combat End','sleep'=>'Sleep','wait'=>'Wait']]],
            ['rpg_chance','Comment Chance','&#x1F3B2;','integer',$globalDocument['rpg_comments']['chance_percent'],'Percentage of eligible events that may request a comment.',['min'=>0,'max'=>100]],
        ],
        'Automatic Dialogue' => [
            ['open_rechat', 'Open Rechat', '&#x1F5E3;&#xFE0F;', 'boolean', $settings['behavior']['open_rechat'], 'Allows nearby scene participants to become the next responder when the selected mode permits it.', []],
            ['rechat_allow_actions', 'Allow Actions During Rechat', '&#x2699;&#xFE0F;', 'boolean', $settings['behavior']['rechat_allow_actions'], 'Lets NPCs request the same policy-checked actions during Rechat as they can during player-started dialogue.', []],
            ['auto_greeting', 'Automatic Greetings', '&#x1F44B;', 'boolean', $settings['behavior']['auto_greeting'], 'Allow a newly activated nearby NPC to greet the player once when the dialogue lane is idle.', []],
            ['boredom', 'Boredom Events', '&#x1F4AC;', 'boolean', $settings['behavior']['boredom'], 'Allow an active nearby NPC to make a brief spontaneous remark after the dialogue lane has been idle.', []],
            ['boredom_delay_seconds', 'Boredom Delay', '&#x23F3;', 'integer', $settings['behavior']['boredom_delay_seconds'], 'Idle seconds before a boredom event can start. Each event restarts this timer.', ['min' => 30, 'max' => 86400]],
            ['combat_barks', 'Combat Barks', '&#x2694;&#xFE0F;', 'boolean', $settings['behavior']['combat_barks'], 'Allow a managed NPC in combat to deliver a short urgent bark while the dialogue lane is idle.', []],
            ['combat_bark_period_seconds', 'Combat Bark Period', '&#x23F1;&#xFE0F;', 'integer', $settings['behavior']['combat_bark_period_seconds'], 'Minimum seconds between automatic combat barks.', ['min' => 5, 'max' => 600]],
        ],
        'Translation' => [
            ['translation_provider', 'Provider', '&#x1F310;', 'select', $translationPolicy['provider'], 'Server-only NPC output translation. None leaves NPC output untranslated; DeepL uses the server-held DeepL key and the account endpoint below.', ['values' => ['none' => 'None', 'deepl' => 'DeepL'], 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'provider']],
            ['translation_text', 'Translate Text', '&#x1F4DD;', 'boolean', $translationPolicy['translate_text'], 'Translate NPC subtitles into the target language. Needs DeepL and a target language.', ['feature' => 'config.globals.translation', 'live' => true, 'control' => 'output']],
            ['translation_audio', 'Translate Audio', '&#x1F3A7;', 'boolean', $translationPolicy['translate_audio'], 'Translate NPC speech audio into the target language. Needs DeepL and a target language.', ['feature' => 'config.globals.translation', 'live' => true, 'control' => 'output']],
            ['translation_save_text', 'Save Translated Text', '&#x1F4BE;', 'boolean', $translationPolicy['save_translated_text'], 'Replace NPC speech in context history with the translation. Needs Translate Text or Translate Audio.', ['feature' => 'config.globals.translation', 'live' => true, 'control' => 'save']],
            ['translation_source_language', 'Source Language', '&#x1F5E3;&#xFE0F;', 'text', $translationPolicy['source_language'], 'NPC source language code such as EN or PT-BR. Leave blank for DeepL auto-detection.', ['maxlength' => 16, 'pattern' => '[A-Za-z]{2,3}(-[A-Za-z]{2})?', 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'language']],
            ['translation_target_language', 'Target Language', '&#x1F30D;', 'text', $translationPolicy['target_language'], 'Language code such as DE or PT-BR that NPC output is translated into. Required once Translate Text or Translate Audio is on.', ['maxlength' => 16, 'pattern' => '[A-Za-z]{2,3}(-[A-Za-z]{2})?', 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'target']],
            ['translation_endpoint_url', 'DeepL Account Endpoint', '&#x1F517;', 'select', $translationPolicy['endpoint'], 'DeepL account type. Free and Pro use fixed endpoints, and no other URL is accepted.', ['values' => [TranslationPolicy::FREE_ENDPOINT => 'Free account (api-free.deepl.com)', TranslationPolicy::PRO_ENDPOINT => 'Pro account (api.deepl.com)'], 'feature' => 'config.globals.translation', 'live' => true, 'control' => 'language']],
        ],
    ],
    'context-knowledge' => [
        'Oghma' => [
            ['oghma_enabled', 'Oghma Infinium', '&#x1F4DA;', 'boolean', $oghmaSettings['enabled'], 'Enable deterministic catalog grounding, access checks, and Oghma prompt context.', []],
            ['oghma_topic_count', 'Extracted Topics', '&#x1F4DA;', 'select', $oghmaSettings['topic_count'], 'Maximum conversational topics extracted and injected for each request.', ['values' => ['1','2','3']]],
            ['oghma_racial_context_enabled', 'Force Racial Oghma', '&#x1F9DD;', 'boolean', $oghmaSettings['racial_context_enabled'], 'Always consider the target and nearby NPC races as Oghma topics when matching articles exist.', []],
            ['oghma_location_context_enabled', 'Force Location Oghma', '&#x1F5FA;&#xFE0F;', 'boolean', $oghmaSettings['location_context_enabled'], 'Always consider the current cell, region, and named location as Oghma topics when matching articles exist.', []],
            ['oghma_configuration_id', 'Custom Oghma LLM', '&#x1F4DA;', 'select', $systemRouting['oghma_configuration_id'], 'Used as a bounded fallback when deterministic Oghma matching cannot resolve an explicit lore request.', ['values'=>$llmOptions, 'toggle'=>['oghma_extractor_enabled','Oghma Topic Extractor',$oghmaSettings['extractor_enabled']]]],
            ['oghma_knowledge_tags', 'Oghma Knowledge Tags', '&#x1F4D9;', 'text', $oghmaSettings['knowledge_tags'], 'Installation knowledge classes inherited by Core Profiles and NPCs. Use comma-separated tags such as traveler, dunmer, scholar, or knowall. Leave empty for public basic access only; common is an article-only basic marker, not an NPC tag.', ['advanced'=>true]],
            ['oghma_result_limit', 'Knowledge Results', '&#x1F4D1;', 'integer', $oghmaSettings['result_limit'], 'Maximum authorized or structured-denial Oghma articles injected for each request.', ['min' => 1, 'max' => 5, 'advanced'=>true]],
            ['oghma_extractor_timeout_ms', 'Extractor Timeout', '&#x23F1;&#xFE0F;', 'integer', $oghmaSettings['extractor_timeout_ms'], 'Maximum connector-fallback time in milliseconds. Local deterministic retrieval does not use this budget.', ['min' => 250, 'max' => 3000, 'advanced'=>true]],
        ],
        'Context' => [
            ['context_ground_items_descriptions_only', 'Ground Items Descriptions Only', '&#x1FAA8;', 'boolean', $contextPolicy['ground_items_descriptions_only'] ?? false, 'Only include nearby ground items that have a saved description. Description text can remain hidden through Context Selections. Does not filter equipment or inventory.', []],
            ['context_inventory_items_descriptions_only', 'Inventory Items Descriptions Only', '&#x1F392;', 'boolean', $contextPolicy['inventory_items_descriptions_only'] ?? false, 'Only include inventory items with a saved description and a stack of five or fewer. Description text can remain hidden through Context Selections. Does not filter equipped or nearby ground items.', []],
            ['context_prompt_timestamp', 'Prompt Timestamp', '&#x1F552;', 'boolean', $contextPolicy['prompt_timestamp'] ?? false, 'Adds relative time dividers between conversation history groups, such as Moments Ago and Earlier in the day. Uses elapsed game time, not real-world time.', []],
        ],
        'Context Selections' => [
            ['context_event_types', 'Event Type Filter', '&#x1F4CB;', 'multiselect', $contextPolicy['event_types'], 'Only selected event types enter conversation history.', ['values' => array_combine(SettingsCatalog::eventTypes(), array_map(static fn(string $type): string => ucwords(str_replace('_', ' ', $type)), SettingsCatalog::eventTypes()))]],
            ['context_location_blacklist', 'Location Blacklist', '&#x1F5FA;&#xFE0F;', 'textarea', implode("\n", $contextPolicy['location_blacklist']), 'One exact location or cell name per line. Matching history and world context are excluded.', ['maxlength' => 32768]],
            ['context_item_blacklist', 'Item Blacklist', '&#x1F6AB;', 'textarea', implode("\n", $contextPolicy['item_blacklist']), 'One exact item display name or record ID per line. Matching nearby, equipped, inventory, and description entries are excluded.', ['maxlength' => 32768]],
            ['context_magic_effects_blacklist', 'Magic & Effects Blacklist', '&#x2728;', 'textarea', implode("\n", $contextPolicy['magic_effects_blacklist']), 'One exact spell or active-effect name per line.', ['maxlength' => 32768]],
        ],
    ],
    'global-connectors' => [
        'Global Connectors' => [
            ['memory_summary_connector', 'Summaries', '&#x1F4DD;', 'select', $memorySummary['provider_configuration_id'] ?? '', 'Summarize consolidated memories with the selected LLM. Original memories are retained.', ['values'=>$llmOptions, 'toggle'=>['memory_summary_enabled','Automatic Memory Summaries',$memorySummary['enabled'] ?? false]]],
            ['profile_generation_configuration_id', 'Profile Tasks', '&#x1F58B;&#xFE0F;', 'select', $systemRouting['profile_generation_configuration_id'], 'Creates requested NPC, player, and narrator profile text. Disabled never calls a provider.', ['values' => $llmOptions]],
            ['relationship_configuration_id', 'Relationship Management', '&#x1F91D;', 'select', $systemRouting['relationship_configuration_id'], 'Evaluates eligible completed conversations using Relationship Update Chance.', ['values'=>$llmOptions, 'toggle'=>['relationship_enabled','Relationship Evaluation',$relationshipSettings['enabled']]]],
        ],
    ],
];

$sectionNotes = [
    'Translation' => 'LORKHAN translates NPC subtitles and speech audio. Saving never calls DeepL.',
    'Context Selections' => 'Select the optional prompt sections and details to include. Response rules, NPC identity, speaker rules, the current turn and the action contract are always included. Blacklists use case-insensitive exact matching, not patterns or regular expressions.',
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
                <?php if ($hasStoredSettings): ?>
                <a class="btn-settings-transfer" href="<?php echo lorkhan_ui_h($managementBasePath . '/exports/global-settings/' . $settingsConfigurationId . '.json'); ?>" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.globals.export')['description']); ?>">&#128228; Export Settings</a>
                <?php endif; ?>
                <?php if ($installations !== []): ?>
                <button type="button" class="btn-settings-transfer" data-gs-portability-toggle="import" aria-controls="gs-portability-panel" aria-expanded="false">&#128229; Import Settings</button>
                <?php endif; ?>
                <?php if ($installationId !== ''): ?><button type="button" class="btn-action-blue" data-profile-test-open aria-haspopup="dialog">Test Global Connectors</button><?php endif; ?>
                <button type="submit" class="btn-save-green" name="save_all" value="1" form="gs_form">Save All</button>
            </div>
        </div>
        <?php if ($installations !== []): ?>
        <div class="preset-row" data-preset-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-preset">
            <label class="preset-label" for="gs-named-preset">Settings Preset</label>
            <select class="preset-select" id="gs-named-preset">
                <optgroup label="Built-in"><option value="default">Default</option></optgroup>
                <optgroup label="Custom" id="gs-custom-presets"><?php foreach ($namedPresets as $preset): ?>
                    <option value="<?php echo lorkhan_ui_h($preset['preset_id']); ?>" data-revision="<?php echo (int)$preset['revision']; ?>"<?php echo ($preset['preset_id'] === ($_GET['preset_id'] ?? null)) ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($preset['name']); ?></option>
                <?php endforeach; ?></optgroup>
            </select>
            <div class="preset-actions">
                <button type="button" class="btn-settings-transfer preset-btn-compact" data-preset-operation="apply">Apply</button>
                <button type="button" class="btn-settings-transfer preset-btn-compact" data-preset-operation="save_new">Save as new…</button>
                <button type="button" class="btn-settings-transfer preset-btn-compact" data-preset-operation="overwrite" disabled>Overwrite…</button>
            </div>
            <span id="gs-preset-status" role="status" aria-live="polite"></span>
        </div>
        <?php endif; ?>
    </header>

    <?php if (isset($_GET['status'])): $statusKey = is_string($_GET['status']) ? $_GET['status'] : ''; ?><div class="result-ok" role="status"><?php echo lorkhan_ui_h($statusMessages[$statusKey] ?? $statusMessages['saved']); ?></div><?php endif; ?>
    <?php if (count($installations) > 1): ?><div class="installation-row"><label>Installation <select data-installation-select><?php foreach ($installations as $row): ?><option value="<?php echo lorkhan_ui_h($row['installation_id']); ?>"<?php echo $row['installation_id'] === $installationId ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($row['display_name']); ?></option><?php endforeach; ?></select></label></div><?php endif; ?>

    <?php if ($installations !== []): ?>
    <section class="gs-portability-panel" id="gs-portability-panel" aria-label="Portable Global Settings">
        <details class="gs-disclosure" data-gs-disclosure="import">
            <summary>Import a settings preset</summary>
            <form class="gs-disclosure-body" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-import">
                <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <div class="gs-field">
                    <label for="gs-preset-file">Preset file</label>
                    <input id="gs-preset-file" type="file" accept="application/json,.json" data-json-import-target="gs-preset-json" aria-describedby="gs-import-help gs-portability-scope">
                </div>
                <div class="gs-field">
                    <label for="gs-preset-json">Preset JSON</label>
                    <textarea id="gs-preset-json" name="preset_json" rows="8" required spellcheck="false" placeholder="Choose an exported .json file or paste its contents here." aria-describedby="gs-import-help gs-portability-scope"></textarea>
                </div>
                <p class="gs-help" id="gs-import-help">Choosing a file fills the box above; the browser accepts JSON files up to 1 MiB. Pasting the document instead works the same way. Importing saves a new Global Settings revision for the selected installation and replaces every value in the typed document, so review the file before importing.</p>
                <div class="gs-actions-row"><button type="submit" class="btn-action-blue" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.globals.import')['description']); ?>">Import Preset</button></div>
            </form>
        </details>

        <details class="gs-disclosure" data-gs-disclosure="history">
            <summary>Revision history<?php if ($revisionHistory !== []): ?> (<?php echo count($revisionHistory); ?>)<?php endif; ?></summary>
            <div class="gs-disclosure-body">
                <?php if (!$hasStoredSettings): ?>
                <p class="gs-help">No Global Settings revision is saved for this installation yet. Save All creates the first revision, and history and Export become available after that.</p>
                <?php else: ?>
                <ul class="gs-revision-list">
                    <?php foreach ($revisionHistory as $revision): if (!is_array($revision)) continue; $revisionNumber = (int) ($revision['revision'] ?? 0); ?>
                    <li>
                        <span class="gs-revision-no">Revision <?php echo $revisionNumber; ?></span>
                        <?php if ($revisionNumber === $settingsRevision): ?><span class="gs-current-tag">Current</span><?php endif; ?>
                        <span class="gs-revision-reason"><?php echo lorkhan_ui_h(trim((string) ($revision['reason'] ?? '')) !== '' ? (string) $revision['reason'] : 'No revision note'); ?></span>
                        <span class="gs-revision-time"><?php echo lorkhan_ui_h((string) ($revision['created_at'] ?? '')); ?></span>
                    </li>
                    <?php endforeach; ?>
                    <?php if ($revisionHistory === []): ?><li><span class="gs-revision-no">Revision <?php echo $settingsRevision; ?></span><span class="gs-current-tag">Current</span><span class="gs-revision-reason">No revision history is recorded for this document.</span></li><?php endif; ?>
                </ul>
                <?php if ($earlierRevisions === []): ?>
                <p class="gs-help">Revision <?php echo $settingsRevision; ?> is the only saved revision, so there is nothing earlier to restore.</p>
                <?php else: ?>
                <form class="gs-rollback-form" method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-rollback">
                    <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
                    <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
                    <input type="hidden" name="configuration_id" value="<?php echo lorkhan_ui_h($settingsConfigurationId); ?>">
                    <?php if ($embedded): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <div class="gs-field">
                        <label for="gs-rollback-revision">Restore revision</label>
                        <select id="gs-rollback-revision" name="revision" aria-describedby="gs-rollback-help gs-portability-scope">
                            <?php foreach ($earlierRevisions as $revision): $revisionNumber = (int) $revision['revision']; ?>
                            <option value="<?php echo $revisionNumber; ?>">Revision <?php echo $revisionNumber; ?><?php $reason = trim((string) ($revision['reason'] ?? '')); if ($reason !== ''): ?> &mdash; <?php echo lorkhan_ui_h($reason); ?><?php endif; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="gs-help" id="gs-rollback-help">Only revisions earlier than the current one can be restored. Restoring copies that document into a new revision on top of revision <?php echo $settingsRevision; ?>; nothing is deleted and no earlier revision is removed from this list.</p>
                    <div class="gs-actions-row"><button type="submit" class="btn-action-blue" title="<?php echo lorkhan_ui_h(lorkhan_ui_feature('config.globals.revision-history')['description']); ?>">Restore Revision</button></div>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </details>
    </section>
    <?php endif; ?>

    <div class="settings-tabs" role="tablist" aria-label="Global settings categories">
        <?php foreach (['prompt-rechat' => '&#x1F4AC; Prompt & Rechat', 'ai-memory' => '&#x1F9E0; Memory & Others', 'context-knowledge' => '&#x1F4DA; Context & Knowledge', 'global-connectors' => '&#x1F50C; Global Connectors'] as $tabId => $tabLabel): ?>
        <button type="button" class="settings-tab<?php echo $tabId === 'prompt-rechat' ? ' is-active' : ''; ?>" id="settings-tab-<?php echo lorkhan_ui_h($tabId); ?>" role="tab" aria-selected="<?php echo $tabId === 'prompt-rechat' ? 'true' : 'false'; ?>" aria-controls="settings-panel-<?php echo lorkhan_ui_h($tabId); ?>" data-settings-tab="<?php echo lorkhan_ui_h($tabId); ?>"><?php echo $tabLabel; ?></button>
        <?php endforeach; ?>
    </div>

    <?php if ($installations === []): ?><section class="content-section">Connect OpenMW once before configuring installation settings.</section><?php else: ?>
    <form method="post" action="<?php echo lorkhan_ui_h($managementBasePath); ?>/forms/global-settings-save" id="gs_form">
        <input type="hidden" name="memory_settings_present" value="1">
        <input type="hidden" name="_csrf" value="<?php echo lorkhan_ui_h($csrf); ?>">
        <input type="hidden" name="installation_id" value="<?php echo lorkhan_ui_h($installationId); ?>">
        <input type="hidden" name="change_reason" value="Management global settings">
        <div class="content-grid">
            <?php $seenTabs = []; ?>
            <?php foreach ($sections as $tabId => $tabSections): foreach ($tabSections as $sectionTitle => $fields): ?>
            <?php $isFirstTabPanel = !isset($seenTabs[$tabId]); $seenTabs[$tabId] = true; ?>
            <section class="content-section<?php echo $sectionTitle === 'Global Connectors' ? ' connector-section' : ''; ?>"<?php if ($isFirstTabPanel): ?> id="settings-panel-<?php echo lorkhan_ui_h($tabId); ?>"<?php endif; ?> role="tabpanel" aria-labelledby="settings-tab-<?php echo lorkhan_ui_h($tabId); ?>" data-settings-panel="<?php echo lorkhan_ui_h($tabId); ?>"<?php echo $tabId === 'prompt-rechat' ? '' : ' hidden'; ?>>
                <h2><?php echo lorkhan_ui_h($sectionTitle); ?><?php if ($sectionTitle === 'Context Selections'): ?><span class="gs-section-help" tabindex="0" aria-label="Context inclusion rules" aria-describedby="gs-context-rules">&#9432;<span id="gs-context-rules" role="tooltip"><?php echo lorkhan_ui_h($sectionNotes[$sectionTitle]); ?></span></span><?php endif; ?></h2>
                <?php if (isset($sectionNotes[$sectionTitle]) && $sectionTitle !== 'Context Selections'): ?><p class="gs-help gs-section-note"><?php echo lorkhan_ui_h($sectionNotes[$sectionTitle]); ?></p><?php endif; ?>
                <div class="provider-grid">
                    <?php if ($sectionTitle === 'Context Selections'): ?>
                    <div class="prompt-context-wrap">
                        <?php foreach ($contextGroups as $groupTitle => [$bucket, $options]): ?>
                        <fieldset class="prompt-context-group">
                            <legend><?php echo lorkhan_ui_h($groupTitle); ?></legend>
                            <div class="prompt-context-grid">
                                <?php foreach ($options as $key => [$label, $description]): $inputName = 'context_' . ($bucket === 'sections' ? 'section_' : 'detail_') . $key; ?>
                                <label class="prompt-context-card">
                                    <input type="checkbox" name="<?php echo lorkhan_ui_h($inputName); ?>" value="1"<?php echo $contextPolicy[$bucket][$key] ? ' checked' : ''; ?>>
                                    <span><span class="prompt-context-label"><?php echo lorkhan_ui_h($label); ?></span><span class="prompt-context-desc"><?php echo lorkhan_ui_h($description); ?></span></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <?php endforeach; ?>
                        <div class="prompt-context-group"><h3>Context Options</h3><div class="provider-grid">
                    <?php endif; ?>
                    <?php $advancedOpen = false; foreach ($fields as $field): [$name, $label, $icon, $type, $value, $help] = $field; $options = $field[6] ?? []; $featureId = (string) ($options['feature'] ?? ''); $controlAttr = isset($options['control']) ? ' data-translation-control="' . lorkhan_ui_h((string) $options['control']) . '"' : ''; $describeAttr = ' aria-describedby="gs-help-' . lorkhan_ui_h($name) . '"'; ?>
                    <?php if (!empty($options['advanced']) && !$advancedOpen): $advancedOpen = true; ?><details class="gs-inline-advanced"><summary>Advanced <?php echo lorkhan_ui_h($sectionTitle); ?> settings</summary><div class="provider-grid"><?php endif; ?>
                    <div class="provider-card"<?php if ($featureId !== ''): ?> title="<?php echo lorkhan_ui_h(lorkhan_ui_feature($featureId)['description']); ?>"<?php endif; ?>>
                        <div class="provider-head"><div class="provider-title"><span class="provider-icon"><?php echo $icon; ?></span><span><?php echo lorkhan_ui_h($label); ?></span><?php if ($type === 'boolean'): ?><span class="provider-toggle"><input type="checkbox" name="<?php echo lorkhan_ui_h($name); ?>" value="1"<?php echo $controlAttr; ?><?php echo $value ? ' checked' : ''; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>></span><?php endif; ?>
                            <?php if (isset($options['toggle'])): [$toggleName, $toggleLabel, $toggleValue] = $options['toggle']; ?>
                            <label class="<?php echo $sectionTitle === 'Oghma' ? 'provider-toggle' : 'connector-availability'; ?>" title="Turn off to disable this task without changing the selected connector."><?php if ($sectionTitle !== 'Oghma'): ?><span data-connector-state><?php echo $toggleValue ? 'On' : 'Off'; ?></span><?php endif; ?><input type="checkbox" name="<?php echo lorkhan_ui_h($toggleName); ?>" value="1"<?php echo $toggleValue ? ' checked' : ''; ?> aria-label="<?php echo lorkhan_ui_h($toggleLabel); ?>"></label>
                            <?php endif; ?>
                        </div></div>
                        <div class="provider-body">
                            <?php $browseKinds = ['context_event_types'=>'event_types','context_location_blacklist'=>'locations','context_item_blacklist'=>'items','context_magic_effects_blacklist'=>'magic']; if (isset($browseKinds[$name])): ?>
                            <button type="button" class="filter-modal-close filter-browse-button" data-filter-browse="<?php echo $browseKinds[$name]; ?>" data-filter-field="<?php echo lorkhan_ui_h($name); ?>" aria-label="Select <?php echo lorkhan_ui_h($label); ?>">Select</button>
                            <?php endif; ?>
                            <?php if ($type === 'integer'): ?><input type="number" name="<?php echo lorkhan_ui_h($name); ?>" value="<?php echo lorkhan_ui_h($value); ?>" min="<?php echo lorkhan_ui_h($options['min']); ?>" max="<?php echo lorkhan_ui_h($options['max']); ?>" step="1" aria-label="<?php echo lorkhan_ui_h($label); ?>">
                            <?php elseif ($type === 'select'): ?><select name="<?php echo lorkhan_ui_h($name); ?>"<?php echo $controlAttr; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>><?php foreach ($options['values'] as $optionKey => $optionLabel): $option = is_int($optionKey) ? (string) $optionLabel : (string) $optionKey; ?><option value="<?php echo lorkhan_ui_h($option); ?>"<?php echo $option === (string) $value ? ' selected' : ''; ?>><?php echo lorkhan_ui_h($optionLabel); ?></option><?php endforeach; ?></select>
                            <?php elseif ($type === 'multiselect'): ?><div class="gs-checklist" role="group" aria-label="<?php echo lorkhan_ui_h($label); ?>"><?php foreach ($options['values'] as $optionKey => $optionLabel): ?><label><input type="checkbox" name="<?php echo lorkhan_ui_h($name); ?>[]" value="<?php echo lorkhan_ui_h($optionKey); ?>"<?php echo in_array((string)$optionKey, $value, true) ? ' checked' : ''; ?>> <?php echo lorkhan_ui_h($optionLabel); ?></label><?php endforeach; ?></div>
                            <?php elseif ($type === 'textarea'): ?><textarea name="<?php echo lorkhan_ui_h($name); ?>" rows="6" maxlength="<?php echo (int)($options['maxlength'] ?? 32768); ?>" spellcheck="false" aria-label="<?php echo lorkhan_ui_h($label); ?>"><?php echo lorkhan_ui_h($value); ?></textarea>
                            <?php elseif ($type === 'text' || $type === 'url'): ?><input type="<?php echo $type; ?>" name="<?php echo lorkhan_ui_h($name); ?>"<?php echo $controlAttr; ?> value="<?php echo lorkhan_ui_h($value); ?>" maxlength="<?php echo (int) ($options['maxlength'] ?? 512); ?>"<?php if (isset($options['pattern'])): ?> pattern="<?php echo lorkhan_ui_h($options['pattern']); ?>"<?php endif; ?> aria-label="<?php echo lorkhan_ui_h($label); ?>"<?php echo $describeAttr; ?>>
                            <?php endif; ?>
                        </div>
                        <div class="provider-help" id="gs-help-<?php echo lorkhan_ui_h($name); ?>"><?php echo lorkhan_ui_h($help); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($advancedOpen): ?></div></details><?php endif; ?>
                    <?php if ($sectionTitle === 'Context Selections'): ?></div></div></div><?php endif; ?>
                </div>
            </section>
            <?php endforeach; endforeach; ?>
        </div>
    </form>
    <?php endif; ?>
    <?php if ($installations !== []): ?>
        <div class="gs-portability-row">
            <?php if ($hasStoredSettings): ?>
            <span class="gs-revision-chip">Revision <?php echo $settingsRevision; ?><?php if ($settingsSavedAt !== ''): ?> &middot; saved <?php echo lorkhan_ui_h($settingsSavedAt); ?><?php endif; ?></span>
            <?php else: ?>
            <span class="gs-revision-chip is-empty">No saved revision &middot; showing built-in defaults</span>
            <?php endif; ?>
            <button type="button" class="btn-settings-transfer preset-btn-compact" data-gs-portability-toggle="history" aria-controls="gs-portability-panel" aria-expanded="false">Revision history<?php if ($revisionHistory !== []): ?> (<?php echo count($revisionHistory); ?>)<?php endif; ?></button>
            <details class="gs-scope-details">
                <summary id="gs-portability-scope">What portable settings include</summary>
                <p class="gs-portability-note"><?php echo lorkhan_ui_h($portableScopeNote); ?></p>
            </details>
        </div>
    <?php endif; ?>
    <dialog id="filter-browse-dialog" class="filter-modal-panel" aria-labelledby="filter-browse-title" aria-describedby="filter-browse-hint"
        data-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1/context-filter-candidates" data-installation="<?php echo lorkhan_ui_h($installationId); ?>">
        <div class="filter-modal-head"><h2 id="filter-browse-title">Recent Values</h2><p id="filter-browse-hint" class="filter-modal-hint"></p></div>
        <div class="filter-modal-body">
            <div class="filter-modal-toolbar"><input type="search" id="filter-browse-search" placeholder="Search recent values" aria-label="Search recent values"><span id="filter-browse-status" class="filter-modal-status" role="status"></span></div>
            <p id="filter-browse-feedback" class="filter-modal-loading" role="status"></p>
            <div id="filter-browse-list" class="filter-modal-list" hidden></div>
        </div>
        <div class="filter-modal-foot"><span class="filter-modal-note">Checked values stay in the field. Uncheck a value to remove it. Save Selection updates your draft; Save All persists it.</span><div class="filter-modal-actions"><button type="button" id="filter-browse-cancel" class="filter-modal-close">Cancel</button><button type="button" id="filter-browse-save" class="btn-save-green">Save Selection</button></div></div>
    </dialog>
    <dialog id="gs-preset-dialog" class="preset-dialog" aria-labelledby="gs-preset-title" aria-describedby="gs-preset-description">
        <form method="dialog" id="gs-preset-dialog-form">
            <h2 id="gs-preset-title"></h2>
            <p id="gs-preset-description"></p>
            <div class="preset-dialog-field" id="gs-preset-name-field"><label for="gs-preset-name">Preset name</label><input id="gs-preset-name" maxlength="128" autocomplete="off"></div>
            <p class="result-error" id="gs-preset-error" role="alert" hidden></p>
            <div class="preset-dialog-actions"><button type="button" class="btn-settings-transfer" id="gs-preset-cancel">Cancel</button><button type="submit" class="btn-save-green" id="gs-preset-confirm">Confirm</button></div>
        </form>
    </dialog>
    <?php if ($installationId !== ''): ?>
    <div class="global-test-modal" data-profile-test-overlay hidden>
        <div class="global-test-shell" role="dialog" aria-modal="true" aria-labelledby="global-test-title" aria-describedby="global-test-warning"
            data-profile-test-dialog data-profile-test-mode="global" data-profile-test-endpoint="<?php echo lorkhan_ui_h($managementBasePath); ?>/api/v1/global-connector-tests"
            data-profile-test-csrf="<?php echo lorkhan_ui_h($csrf); ?>" data-profile-test-installation="<?php echo lorkhan_ui_h($installationId); ?>">
            <div class="global-test-head">
                <div><div id="global-test-title" class="global-test-title">Test Global Connectors</div><div class="global-test-subtitle">Test enabled global connector slots once, then share each result across matching slots.</div></div>
                <button type="button" class="global-test-close" data-profile-test-close>Close</button>
            </div>
            <div class="global-test-body">
                <p class="global-test-warning" id="global-test-warning">Tests use saved settings and may incur provider charges. <strong>No requests are sent until you click Run tests.</strong></p>
                <div class="global-test-summary" data-profile-test-counts role="group" aria-label="Connector test result totals"></div>
                <div class="global-test-progress" data-profile-test-progress role="progressbar" aria-label="Connector tests completed" aria-valuemin="0" aria-valuemax="0" aria-valuenow="0" hidden><div data-profile-test-progress-fill></div></div>
                <p class="global-test-scope" data-profile-test-scope></p>
                <p class="global-test-status" data-profile-test-status role="status" aria-live="polite">Loading the connector test plan.</p>
                <div data-profile-test-plan></div>
            </div>
            <div class="global-test-actions">
                <button type="button" class="btn-save-green" data-profile-test-run disabled>Run tests</button>
                <button type="button" class="global-test-close" data-profile-test-stop hidden>Stop queued tests</button>
                <button type="button" class="global-test-close" data-profile-test-reload hidden>Reload plan</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/global-settings.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/global-settings.js')); ?>"></script>
<script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/context-filter-browser.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/context-filter-browser.js')); ?>"></script>
<?php if ($installationId !== ''): ?><script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/profile-connector-tests.js?v=<?php echo lorkhan_ui_h((string) filemtime(__DIR__ . '/js/profile-connector-tests.js')); ?>"></script><?php endif; ?>
<?php if ($installations !== []): ?><script defer src="<?php echo lorkhan_ui_h($webRoot); ?>/ui/js/resource-page.js?v=<?php echo lorkhan_ui_h($uiAssetVersion); ?>"></script><?php endif; ?>
<?php include __DIR__ . '/tmpl/footer.html'; ?>
