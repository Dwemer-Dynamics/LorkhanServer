<?php

declare(strict_types=1);

/** Central source of truth for copied Herika surfaces and their ALMSIVI availability. */
function almsivi_ui_feature_registry(): array
{
    static $features = [
        'home' => ['title' => 'Home', 'state' => 'live', 'description' => 'ALMSIVI status, activity, and setup overview.'],
        'quickstart' => ['title' => 'Quickstart', 'state' => 'planned', 'description' => 'The Herika onboarding presentation is retained while ALMSIVI pairing and provider setup are consolidated.'],
        'autonomy' => ['title' => 'Autonomy', 'state' => 'excluded', 'description' => 'Automatic greetings, boredom events, combat barks, schedules, and other model-triggering autonomy are excluded from this milestone.'],
        'actions.rechat' => ['title' => 'Rechat Actions', 'state' => 'excluded', 'description' => 'Herika-style playback rechat is supported, but action generation during a rechat exchange remains disabled.'],
        'presentation.local' => ['title' => 'Local OpenMW Presentation', 'state' => 'replaced', 'description' => 'HUD visibility, transcript size, panel layout, hotkeys, and ALMSIVI TTS volume boost are local OpenMW preferences and cannot be overridden by Global, Core Profile, or NPC settings.'],

        'config.npc' => ['title' => 'ALMSIVI NPCs', 'state' => 'live', 'description' => 'Versioned OpenMW NPC profiles and explicit overrides.'],
        'config.npc.relationship-builder' => ['title' => 'Build Relationships', 'state' => 'planned', 'description' => 'Automatic relationship rebuilding requires a future typed OpenMW capability.'],
        'config.npc.reset' => ['title' => 'Reset NPC', 'state' => 'planned', 'description' => 'OpenMW save-aware NPC reset is not connected yet.'],
        'config.npc.identity' => ['title' => 'OpenMW NPC Identity', 'state' => 'replaced', 'description' => 'NPC names and record identities are learned from stable OpenMW actor data rather than edited in the browser.'],
        'config.npc.memory-bank' => ['title' => 'NPC Memory Bank', 'state' => 'replaced', 'description' => 'ALMSIVI resolves recent, middle-term, and long-term memory through typed repositories instead of a per-NPC switch.'],
        'config.npc.inherited-filter' => ['title' => 'Inherited NPC Filter', 'state' => 'excluded', 'description' => 'Autonomy filters are excluded from this milestone.'],
        'config.npc.saved-only' => ['title' => 'Saved NPC Control', 'state' => 'not-applicable', 'description' => 'This control becomes available after the NPC profile has been created.'],
        'config.profiles' => ['title' => 'Profiles', 'state' => 'live', 'description' => 'Core Profiles between Global Settings and individual NPC overrides.'],
        'config.profiles.import' => ['title' => 'Import Profile', 'state' => 'planned', 'description' => 'Portable Core Profile import is not connected yet.'],
        'config.profiles.rules' => ['title' => 'Profile Rules', 'state' => 'planned', 'description' => 'Automatic Core Profile assignment rules require typed OpenMW matching data.'],
        'config.profiles.test' => ['title' => 'Test Profiles', 'state' => 'planned', 'description' => 'Bulk connector tests for Core Profiles are not connected yet.'],
        'config.profiles.export' => ['title' => 'Export Profile', 'state' => 'planned', 'description' => 'Portable typed Core Profile export is not connected yet.'],
        'config.profiles.clone' => ['title' => 'Clone Profile', 'state' => 'replaced', 'description' => 'NPC cloning is available in the typed ALMSIVI NPC editor; Core Profile duplication remains an explicit create-and-edit workflow.'],
        'config.profiles.metadata' => ['title' => 'Profile Identity Metadata', 'state' => 'replaced', 'description' => 'Core Profile identity metadata stays stable while this page creates immutable content revisions.'],
        'config.profiles.delete-protected' => ['title' => 'Protected Profile Deletion', 'state' => 'not-applicable', 'description' => 'The default Core Profile or a profile assigned to NPCs cannot be deleted.'],
        'config.profiles.dynamic-profile' => ['title' => 'Dynamic Profile', 'state' => 'excluded', 'description' => 'Automatic profile evolution is autonomy and is excluded from this milestone.'],
        'config.profiles.middle-term-memory' => ['title' => 'Middle Term Memory', 'state' => 'replaced', 'description' => 'ALMSIVI uses its typed recent, middle-term, and long-term memory repositories instead of a profile toggle.'],
        'config.profiles.auto-diary' => ['title' => 'Automatic Diary Generation', 'state' => 'excluded', 'description' => 'Background diary generation is excluded from the current ALMSIVI scope.'],
        'config.profiles.physical-diary' => ['title' => 'Physical Diary', 'state' => 'not-applicable', 'description' => 'ALMSIVI does not currently materialize generated diaries as OpenMW inventory books.'],
        'config.profiles.latest-diary' => ['title' => 'Latest Diary Context', 'state' => 'replaced', 'description' => 'Diary context is selected by the typed narrative and memory pipeline rather than a profile flag.'],
        'config.profiles.language' => ['title' => 'Profile Language', 'state' => 'not-applicable', 'description' => 'The current ALMSIVI OpenMW dialogue pipeline uses installation-wide language handling.'],
        'config.profiles.diary-llm' => ['title' => 'Diary LLM', 'state' => 'replaced', 'description' => 'ALMSIVI narratives use the active typed narrative pipeline rather than a separate profile connector.'],
        'config.profiles.formatter-llm' => ['title' => 'Formatter LLM', 'state' => 'planned', 'description' => 'A separate formatter connector is not wired to the ALMSIVI response pipeline yet.'],
        'config.player' => ['title' => 'Player', 'state' => 'live', 'description' => 'Versioned player identity and speech style.'],
        'config.player.biography-visibility' => ['title' => 'Player Biography Visibility', 'state' => 'planned', 'description' => 'Per-audience player biography visibility is not represented in the current typed player profile.'],
        'config.player.autochat-tts' => ['title' => 'Player Autochat and TTS', 'state' => 'excluded', 'description' => 'Player autochat is autonomy and is excluded; local player TTS presentation remains an OpenMW preference.'],
        'config.player.diary' => ['title' => 'Player Diary Controls', 'state' => 'planned', 'description' => 'Manual and automatic player diary controls are not connected to the typed ALMSIVI player profile.'],
        'config.player.statistics' => ['title' => 'Player Statistics', 'state' => 'live', 'description' => 'Latest typed OpenMW inventory, equipment, attributes, skills, and vital statistics.'],
        'config.narrator' => ['title' => 'Narration', 'state' => 'live', 'description' => 'Narrator identity, routing, and generation.'],
        'config.narrator.diaries' => ['title' => 'Narrator Diaries', 'state' => 'planned', 'description' => 'Narrator-specific manual and automatic diary permissions are not connected to the typed ALMSIVI profile yet.'],
        'config.narrator.asterisks' => ['title' => 'Narration Text Filters', 'state' => 'planned', 'description' => 'Per-source asterisk filtering is not configurable in the current ALMSIVI response pipeline.'],
        'config.narrator.event-tuning' => ['title' => 'Narration Event Tuning', 'state' => 'excluded', 'description' => 'Automatic narrator event triggers, chances, and cooldowns are autonomy and are excluded from this milestone.'],
        'config.narrator.bored-events' => ['title' => 'Narrator Bored Events', 'state' => 'excluded', 'description' => 'Bored-event narrator routing is autonomy and is excluded from this milestone.'],
        'config.narrator.profile-connectors' => ['title' => 'Selected Profile Connectors', 'state' => 'replaced', 'description' => 'ALMSIVI uses its explicit narrator TTS route and the inherited model pipeline instead of a Herika profile connector bundle.'],
        'config.narrator.dynamic-profile' => ['title' => 'Dynamic Profile Updates', 'state' => 'excluded', 'description' => 'Automatic narrator profile evolution is autonomy and is excluded from this milestone.'],
        'config.narrator.actions' => ['title' => 'Narrator Actions', 'state' => 'replaced', 'description' => 'Narrator action availability is managed centrally through ALMSIVI Action Editor policies.'],
        'config.narrator.prompts' => ['title' => 'Advanced Prompts', 'state' => 'replaced', 'description' => 'Narrator prompt templates are managed centrally through ALMSIVI Prompts Manager.'],
        'config.biographies' => ['title' => 'NPC Biographies', 'state' => 'live', 'description' => 'Biography editing for OpenMW NPC profiles.'],
        'config.biographies.import' => ['title' => 'Biography Import', 'state' => 'planned', 'description' => 'CSV biography import is not connected to the typed ALMSIVI profile repository yet.'],
        'config.biographies.export' => ['title' => 'Biography Export', 'state' => 'planned', 'description' => 'Portable typed NPC biography exports are not connected yet.'],
        'config.biographies.create' => ['title' => 'Create Biography Entry', 'state' => 'replaced', 'description' => 'ALMSIVI creates NPC profiles from stable OpenMW actor identity; create the NPC through the NPC page or encounter it in game.'],
        'config.biographies.reset' => ['title' => 'Reset Biography Overrides', 'state' => 'not-applicable', 'description' => 'ALMSIVI uses revisioned NPC profiles and does not expose a destructive biography override-table reset.'],
        'config.biographies.oghma' => ['title' => 'Biography Oghma Links', 'state' => 'replaced', 'description' => 'World knowledge is managed centrally through ALMSIVI Oghma Infinium instead of per-template biography links.'],
        'config.llm' => ['title' => 'LLM', 'state' => 'live', 'description' => 'Revisioned model slots and provider routing.'],
        'config.llm.identity' => ['title' => 'Connector Identity', 'state' => 'replaced', 'description' => 'ALMSIVI keeps the connector name stable while model-slot content changes through immutable revisions.'],
        'config.llm.service' => ['title' => 'Provider Service', 'state' => 'replaced', 'description' => 'Provider endpoints and credentials are selected by the server-owned ALMSIVI runtime rather than stored in browser connector records.'],
        'config.llm.api-key' => ['title' => 'Connector API Key', 'state' => 'replaced', 'description' => 'LLM credentials remain server-owned and are never stored in portable model-slot revisions.'],
        'config.llm.generation' => ['title' => 'Generation Controls', 'state' => 'planned', 'description' => 'Per-model JSON, streaming, token, and temperature controls are reserved for a future typed provider document.'],
        'config.llm.advanced' => ['title' => 'Advanced LLM Overrides', 'state' => 'planned', 'description' => 'Penalty and sampling overrides are not represented in the current typed ALMSIVI provider document.'],
        'config.llm.body-parameters' => ['title' => 'Request Body Parameters', 'state' => 'planned', 'description' => 'Arbitrary request-body parameters are intentionally disabled until they have a bounded typed schema.'],
        'config.llm.import-format' => ['title' => 'Portable Connector Import', 'state' => 'replaced', 'description' => 'ALMSIVI imports redacted portable JSON model slots instead of Herika connector CSV files.'],
        'config.llm.delete-protected' => ['title' => 'Protected Connector Deletion', 'state' => 'not-applicable', 'description' => 'A connector assigned to a profile or active session cannot be deleted.'],
        'config.llm.saved-only' => ['title' => 'Saved Connector Control', 'state' => 'not-applicable', 'description' => 'This control becomes available after the connector has been saved.'],
        'config.tts' => ['title' => 'TTS', 'state' => 'live', 'description' => 'Revisioned speech connectors and defaults.'],
        'config.tts.api-key' => ['title' => 'Connector API Badge', 'state' => 'replaced', 'description' => 'TTS credentials remain server-owned and are selected through ALMSIVI API Keys rather than embedded in portable connector revisions.'],
        'config.tts.import-format' => ['title' => 'Portable TTS Import', 'state' => 'replaced', 'description' => 'ALMSIVI imports redacted portable JSON connector records instead of Herika connector CSV files.'],
        'config.tts.delete-protected' => ['title' => 'Protected Connector Deletion', 'state' => 'not-applicable', 'description' => 'The installation default or a connector assigned to a Core Profile, NPC, or active session cannot be deleted.'],
        'config.tts.saved-only' => ['title' => 'Saved Connector Control', 'state' => 'not-applicable', 'description' => 'This control becomes available after the connector has been saved.'],
        'config.tts-studio' => ['title' => 'TTS Studio', 'state' => 'live', 'description' => 'Voice samples, provider catalogues, and synchronization.'],
        'config.tts-studio.cloud-cloning' => ['title' => 'Cloud Voice Cloning', 'state' => 'planned', 'description' => 'Cartesia and Inworld voice-clone generation are not connected to the typed ALMSIVI voice library yet.'],
        'config.tts-studio.fallbacks' => ['title' => 'Race and Gender Fallback Matrix', 'state' => 'replaced', 'description' => 'ALMSIVI resolves explicit NPC voices first and stores bounded male and female fallback IDs on each typed TTS connector.'],
        'config.tts-studio.batch-sync' => ['title' => 'Batch Provider Sync', 'state' => 'replaced', 'description' => 'ALMSIVI uses explicit per-sample synchronization so provider writes stay bounded and auditable.'],
        'config.tts-studio.provider-delete' => ['title' => 'Provider Copy Deletion', 'state' => 'replaced', 'description' => 'ALMSIVI deletes persistent local samples only after checking every typed profile and connector reference.'],
        'config.stt' => ['title' => 'STT', 'state' => 'live', 'description' => 'One installation-global CHIM-compatible speech-to-text connector with typed OpenMW capture and durable transcription.'],
        'config.stt.google-free' => ['title' => 'Google Free STT', 'state' => 'replaced', 'description' => 'Browser-only dictation cannot carry ALMSIVI target, session, and generation fencing; use the authenticated in-game microphone controls instead.'],
        'config.itt' => ['title' => 'ITT', 'state' => 'excluded', 'description' => 'Image-to-text is intentionally excluded from the current ALMSIVI scope.', 'controls' => ['Create ITT Connector', 'Import Connector', 'Test Connection']],
        'config.keys' => ['title' => 'API Keys', 'state' => 'live', 'description' => 'Masked browser-managed provider credentials.'],
        'config.keys.test' => ['title' => 'API Key Test', 'state' => 'planned', 'description' => 'Bounded browser-side provider credential tests are not connected to the typed provider clients yet.'],
        'config.keys.environment' => ['title' => 'Environment Credential', 'state' => 'replaced', 'description' => 'This credential is controlled by the Apache and worker environment and cannot be replaced from the browser.'],
        'config.keys.custom' => ['title' => 'Custom API Keys', 'state' => 'planned', 'description' => 'Arbitrary custom credential names are disabled until the typed provider catalogue can consume them.'],
        'config.keys.replicate' => ['title' => 'Replicate Credential', 'state' => 'not-applicable', 'description' => 'The Skyrim Soulgaze processor is not applicable to ALMSIVI.'],
        'config.keys.groq' => ['title' => 'Groq Credential', 'state' => 'planned', 'description' => 'Groq is not represented in the current typed ALMSIVI LLM provider document.'],
        'config.keys.nano-gpt' => ['title' => 'Nano-GPT Credential', 'state' => 'planned', 'description' => 'Nano-GPT is not represented in the current typed ALMSIVI LLM provider document.'],
        'config.keys.deepl' => ['title' => 'DeepL Credential', 'state' => 'planned', 'description' => 'A separate translation connector is not represented in the current typed ALMSIVI pipeline.'],
        'config.globals' => ['title' => 'Global Settings', 'state' => 'live', 'description' => 'Installation-wide defaults inherited by Core Profiles and NPCs.'],
        'config.globals.prompt-head' => ['title' => 'Global Prompt Head', 'state' => 'replaced', 'description' => 'ALMSIVI uses revisioned Prompt Manager documents and Core Profile prompt routing instead of one untyped global prompt field.'],
        'config.globals.emote-moods' => ['title' => 'Global Emote Moods', 'state' => 'replaced', 'description' => 'ALMSIVI stores profile text and moods on Core Profiles and explicit NPC overrides rather than one untyped global list.'],
        'config.globals.rechat-mode' => ['title' => 'Rechat Mode', 'state' => 'live', 'description' => 'Herika-compatible tight, conversational, group, and random responder selection.'],
        'config.globals.strict-rechat' => ['title' => 'Strict Rechat Targeting', 'state' => 'live', 'description' => 'Require each rechat response to address the previous speaker.'],
        'config.globals.memory-recall' => ['title' => 'Long-Term Memory Recall', 'state' => 'replaced', 'description' => 'ALMSIVI always resolves bounded recent, middle-term, and long-term memory through its typed repositories instead of a global recall switch.'],
        'config.globals.memory-embedding' => ['title' => 'Memory Embedding Service', 'state' => 'planned', 'description' => 'A configurable MiniMe or TXT2VEC embedding service is not connected to the typed ALMSIVI memory pipeline yet.'],
        'config.globals.memory-summary' => ['title' => 'Automatic Memory Summaries', 'state' => 'planned', 'description' => 'Automatic interval-based memory summary generation is not connected to the typed ALMSIVI worker yet.'],
        'config.globals.worst-memory' => ['title' => 'Worst Memory Lifespan', 'state' => 'planned', 'description' => 'Game-day expiry for relationship memories is not represented in the current typed ALMSIVI memory schema.'],
        'config.globals.profile-autofill' => ['title' => 'Automatic Profile Backfill', 'state' => 'excluded', 'description' => 'Automatic AI profile generation is autonomy and is excluded from this milestone.'],
        'config.globals.conversation-cooldown' => ['title' => 'End Conversation Cooldown', 'state' => 'live', 'description' => 'Herika-compatible cooldown before another playback rechat chain may begin.'],
        'config.globals.quest-progression' => ['title' => 'CHIM AI Quest Progression', 'state' => 'not-applicable', 'description' => 'These controls target Skyrim quest-stage actions; ALMSIVI exposes Morrowind quest state through the Journal instead.'],
        'config.globals.translation' => ['title' => 'Translation', 'state' => 'planned', 'description' => 'DeepL translation is not connected to the typed ALMSIVI dialogue and TTS pipeline yet.'],
        'config.oghma' => ['title' => 'Oghma Infinium', 'state' => 'live', 'description' => 'Scoped world-knowledge documents and retrieval data.'],
        'config.oghma.dynamic' => ['title' => 'Dynamic Oghma', 'state' => 'excluded', 'description' => 'Background quest-stage knowledge mutation is excluded from the current bounded ALMSIVI scope.'],
        'config.oghma.batch' => ['title' => 'Oghma CSV Batch', 'state' => 'planned', 'description' => 'Scoped typed CSV import and example export are not connected yet.'],
        'config.oghma.destructive' => ['title' => 'Destructive Oghma Reset', 'state' => 'replaced', 'description' => 'ALMSIVI uses bounded Database Manager backups and retention instead of unscoped delete-all or factory-reset controls.'],
        'config.descriptions' => ['title' => 'Descriptions', 'state' => 'live', 'description' => 'Morrowind description records.'],
        'config.descriptions.batch' => ['title' => 'Description CSV Batch', 'state' => 'planned', 'description' => 'Scoped typed description import and export are not connected yet.'],
        'config.descriptions.reset' => ['title' => 'Description Factory Reset', 'state' => 'replaced', 'description' => 'ALMSIVI preserves scoped records and uses individual deletion plus Database Manager backups instead of a destructive table reset.'],
        'config.actions' => ['title' => 'Action Editor', 'state' => 'live', 'description' => 'OpenMW capability-aware action policies.'],
        'config.actions.direct-edit' => ['title' => 'Direct Action Editing', 'state' => 'replaced', 'description' => 'ALMSIVI receives an immutable negotiated OpenMW action catalogue and applies permissions through revisioned scoped policies.'],
        'config.actions.advanced' => ['title' => 'Action Advanced Options', 'state' => 'replaced', 'description' => 'Confirmation and tier behavior are represented by typed ALMSIVI action metadata and scoped policies rather than arbitrary per-action JSON.'],
        'config.prompts' => ['title' => 'Prompts Manager', 'state' => 'live', 'description' => 'Revisioned prompt templates and routing.'],
        'config.prompts.csv' => ['title' => 'Herika Prompt CSV', 'state' => 'replaced', 'description' => 'ALMSIVI uses redacted portable JSON documents so typed revisions and installation ownership remain intact.'],
        'config.plugins' => ['title' => 'Server Plugins', 'state' => 'replaced', 'description' => 'ALMSIVI exposes a read-only first-party module inventory; arbitrary plugin installation is disabled.', 'controls' => ['Install Server Plugin', 'Upload Plugin Package']],
        'config.plugins.sync' => ['title' => 'Automatic Plugin Sync', 'state' => 'planned', 'description' => 'Signed and typed OpenMW-to-server package negotiation is not connected yet.'],
        'config.plugins.lifecycle' => ['title' => 'Plugin Lifecycle', 'state' => 'replaced', 'description' => 'First-party ALMSIVI modules are source-controlled and deployed through the guarded local deployment workflow.'],
        'config.plugins.marketplace' => ['title' => 'Plugin Repository', 'state' => 'planned', 'description' => 'The ALMSIVI plugin package contract and repository are not available yet.'],
        'config.plugins.skyrim' => ['title' => 'Skyrim Plugin', 'state' => 'not-applicable', 'description' => 'This Herika extension depends on Skyrim-specific runtime capabilities and is not applicable to OpenMW.'],

        'roleplay.events' => ['title' => 'Events', 'state' => 'live', 'description' => 'Immutable OpenMW and dialogue source events.'],
        'roleplay.responses' => ['title' => 'AI Responses', 'state' => 'live', 'description' => 'Persisted dialogue and delivery states.'],
        'roleplay.adventure' => ['title' => 'Adventure Log', 'state' => 'live', 'description' => 'Narratives, summaries, and playthrough records.'],
        'roleplay.memories' => ['title' => 'Memories', 'state' => 'live', 'description' => 'Recent, middle-term, and long-term memory records.'],
        'roleplay.diaries' => ['title' => 'ALMSIVI Diaries', 'state' => 'live', 'description' => 'Versioned diary narratives.'],
        'roleplay.books' => ['title' => 'Books', 'state' => 'live', 'description' => 'Books observed during OpenMW sessions.'],
        'roleplay.soulgaze' => ['title' => 'Soulgaze Gallery', 'state' => 'not-applicable', 'description' => 'The Skyrim Soulgaze workflow has no safe OpenMW equivalent.', 'controls' => ['Generate Portrait', 'Open Soulgaze']],
        'roleplay.journal' => ['title' => 'Journal', 'state' => 'live', 'description' => 'Morrowind Journal entries received through bounded typed OpenMW context.'],
        'roleplay.quest-manager' => ['title' => 'AI Quest Manager', 'state' => 'not-applicable', 'description' => 'Herika quest creation depends on Skyrim-specific outcomes that ALMSIVI does not expose.', 'controls' => ['Create AI Quest', 'Manage Quest Actors']],
        'roleplay.background-life' => ['title' => 'Background Life', 'state' => 'excluded', 'description' => 'Background Life is intentionally excluded from the current ALMSIVI scope.', 'controls' => ['Enable Background Life', 'Generate Rumors', 'View History']],
        'roleplay.destructive' => ['title' => 'Bulk Roleplay Deletion', 'state' => 'replaced', 'description' => 'ALMSIVI preserves typed audit records and uses bounded retention and Database Manager backups instead of destructive browser bulk deletion.'],

        'control.logs' => ['title' => 'Server Logs', 'state' => 'live', 'description' => 'Bounded redacted server logs.'],
        'control.requests' => ['title' => 'Request Logs', 'state' => 'live', 'description' => 'Request and prompt traces.'],
        'control.oghma-audit' => ['title' => 'Oghma Audit', 'state' => 'live', 'description' => 'Knowledge retrieval audit records.'],
        'control.relationships' => ['title' => 'Relationship Logs', 'state' => 'live', 'description' => 'Relationship change audit.'],
        'control.usage' => ['title' => 'Cost Breakdown', 'state' => 'live', 'description' => 'Provider usage without fabricated currency values.'],
        'control.responses' => ['title' => 'Response Queue', 'state' => 'live', 'description' => 'Dialogue delivery and terminal states.'],
        'control.providers' => ['title' => 'Provider Attempts', 'state' => 'live', 'description' => 'Provider latency and bounded error traces.'],
        'control.jobs' => ['title' => 'Workers & Jobs', 'state' => 'live', 'description' => 'Durable worker and dead-letter status.'],
        'control.cache' => ['title' => 'Audio & Image Cache', 'state' => 'live', 'description' => 'Private media metadata browser.'],
        'control.playthroughs' => ['title' => 'Playthrough Manager', 'state' => 'live', 'description' => 'Playthrough backup and restore.'],
        'control.database' => ['title' => 'Database Manager', 'state' => 'live', 'description' => 'Schema, backup, and retention operations.'],
        'control.updater' => ['title' => 'Update Server', 'state' => 'replaced', 'description' => 'Server updates are performed through the guarded ALMSIVI deployment workflow.', 'controls' => ['Check for Updates', 'Install Update']],
    ];

    return $features;
}

/** Return a bounded feature record, defaulting unknown copied controls to a safe planned state. */
function almsivi_ui_feature(string $id): array
{
    $feature = almsivi_ui_feature_registry()[$id] ?? [
        'title' => ucwords(str_replace(['.', '-'], ' ', $id)),
        'state' => 'planned',
        'description' => 'This copied Herika surface has not been connected to ALMSIVI yet.',
    ];
    $feature['id'] = $id;
    $feature['controls'] ??= [];
    return $feature;
}

/** Render the standardized feature-state tag used by hubs and inert copied controls. */
function almsivi_ui_feature_badge(string $id, bool $compact = false): string
{
    $feature = almsivi_ui_feature($id);
    $labels = [
        'live' => 'Live',
        'planned' => 'Planned',
        'excluded' => 'Excluded',
        'not-applicable' => 'Not Applicable',
        'replaced' => 'Replaced',
    ];
    $state = (string) $feature['state'];
    return '<span class="feature-state-badge feature-state-' . almsivi_ui_h($state) . ($compact ? ' feature-state-compact' : '') . '">' . almsivi_ui_h($labels[$state] ?? 'Planned') . '</span>';
}

/** Render a copied but intentionally inert control with its availability explained in-place. */
function almsivi_ui_placeholder_control(string $label, string $featureId): string
{
    $feature = almsivi_ui_feature($featureId);
    return '<button type="button" class="btn-base feature-placeholder-control" disabled aria-disabled="true" title="' . almsivi_ui_h($feature['description']) . '">' . almsivi_ui_h($label) . ' ' . almsivi_ui_feature_badge($featureId, true) . '</button>';
}
