<?php

declare(strict_types=1);

/** Central source of truth for copied Herika surfaces and their LORKHAN availability. */
function lorkhan_ui_feature_registry(): array
{
    static $features = [
        'home' => ['title' => 'Home', 'state' => 'live', 'description' => 'LORKHAN status, activity, and setup overview.'],
        'quickstart' => ['title' => 'Quickstart', 'state' => 'live', 'description' => 'Configure the selected Core Profile models and installation speech connectors without changing saves or NPC overrides.'],
        'autonomy' => ['title' => 'Automatic Dialogue', 'state' => 'live', 'description' => 'Automatic greetings, idle remarks, and combat barks use the game-owned scheduler and normal dialogue lane.'],
        'actions.rechat' => ['title' => 'Rechat Actions', 'state' => 'live', 'description' => 'Rechat can use the same capability and policy checked action catalog as player-started dialogue when enabled.'],
        'presentation.local' => ['title' => 'Local OpenMW Presentation', 'state' => 'replaced', 'description' => 'HUD visibility, transcript size, panel layout, hotkeys, and LORKHAN TTS volume boost are local OpenMW preferences and cannot be overridden by Global, Core Profile, or NPC settings.'],

        'config.npc' => ['title' => 'LORKHAN NPCs', 'state' => 'live', 'description' => 'Versioned OpenMW character profiles that inherit behavior and response models from their assigned Core Profile.'],
        'config.npc.relationship-builder' => ['title' => 'Build Relationships', 'state' => 'live', 'description' => 'Converts saved NPC Relationships text into typed playthrough records through the installation-wide Relationship LLM.'],
        'config.npc.reset' => ['title' => 'Reset NPC', 'state' => 'live', 'description' => 'Reapply non-empty biography template fields as a reversible revision. Identity, voice, routing and history are preserved.'],
        'config.npc.identity' => ['title' => 'OpenMW NPC Identity', 'state' => 'replaced', 'description' => 'NPC names and record identities are learned from stable OpenMW actor data rather than edited in the browser.'],
        'config.npc.memory-bank' => ['title' => 'NPC Memory Bank', 'state' => 'live', 'description' => 'NPCs inherit memory switches and limits from their assigned Core Profile.'],
        'config.npc.inherited-filter' => ['title' => 'Inherited NPC Filter', 'state' => 'excluded', 'description' => 'Autonomy filters are excluded from this milestone.'],
        'config.npc.saved-only' => ['title' => 'Saved NPC Control', 'state' => 'not-applicable', 'description' => 'This control becomes available after the NPC profile has been created.'],
        'config.profiles' => ['title' => 'Profiles', 'state' => 'live', 'description' => 'Core Profiles hold reusable roleplay prompts, response models, rechat behavior, recent history limits, and diary settings for assigned NPCs.'],
        'config.profiles.import' => ['title' => 'Import Settings Preset', 'state' => 'live', 'description' => 'Creates a new unassigned Core Profile from a portable settings preset. A preset carries Core Profile settings overrides only, and excludes prompt text, connector routing, identifiers, slots, default status, revision history, and NPC assignments.'],
        'config.profiles.rules' => ['title' => 'Profile Rules', 'state' => 'live', 'description' => 'Assigns a Core Profile automatically when a previously unknown NPC is first discovered. A rule matches on name, race, class, gender, OpenMW textual faction ID and content file: every field you fill must match, and several values in one field mean any of them. Text is compared without regard to capitals and must match in full. The highest priority enabled rule wins, and the older rule wins a tie. NPCs already assigned to a Core Profile, and any Core Profile set by hand, are never changed.'],
        'config.profiles.test' => ['title' => 'Test Profiles', 'state' => 'live', 'description' => 'Shows every LLM and TTS connector routed by these Core Profiles, then tests each distinct connector once only after you press Run tests. Live providers may charge for the request; replies are not saved or shown.'],
        'config.profiles.export' => ['title' => 'Export Settings Preset', 'state' => 'live', 'description' => 'Downloads this Core Profile as a portable settings preset. The preset carries Core Profile settings overrides only, and excludes prompt text, connector routing, identifiers, slots, default status, revision history, and NPC assignments.'],
        'config.profiles.clone' => ['title' => 'Clone Profile', 'state' => 'live', 'description' => 'Clone a Core Profile as a new unassigned profile, preserving its settings and connector routes.'],
        'config.profiles.metadata' => ['title' => 'Profile Identity Metadata', 'state' => 'replaced', 'description' => 'Core Profile identity metadata stays stable while this page creates immutable content revisions.'],
        'config.profiles.delete-protected' => ['title' => 'Protected Profile Deletion', 'state' => 'not-applicable', 'description' => 'The default Core Profile or a profile assigned to NPCs cannot be deleted.'],
        'config.profiles.dynamic-profile' => ['title' => 'Dynamic Profile', 'state' => 'live', 'description' => 'Unlocked nearby NPC profiles can evolve selected fields from bounded witnessed dialogue every 20 minutes.'],
        'config.profiles.middle-term-memory' => ['title' => 'Middle Term Memory', 'state' => 'live', 'description' => 'Core Profiles independently enable recent, middle-term and long-term memory retrieval.'],
        'config.profiles.auto-diary' => ['title' => 'Automatic Diary Generation', 'state' => 'live', 'description' => 'Core Profiles can generate bounded diaries on a timer, after sleeping, and optionally after waiting. Each profile has its own cooldown and automatic generation remains off until enabled.'],
        'config.profiles.physical-diary' => ['title' => 'Physical Diary', 'state' => 'not-applicable', 'description' => 'LORKHAN does not currently materialize generated diaries as OpenMW inventory books. A requested diary is stored as a typed diary narrative only.'],
        'config.profiles.latest-diary' => ['title' => 'Diary Context', 'state' => 'live', 'description' => 'Diary In Context keeps scoped diary narratives in this profile roleplay context, and turning it off removes them. Which diaries are in scope is still decided by the typed narrative and memory pipeline rather than a per-entry flag.'],
        'config.profiles.language' => ['title' => 'Profile Language', 'state' => 'not-applicable', 'description' => 'The current LORKHAN OpenMW dialogue pipeline uses installation-wide language handling.'],
        'config.profiles.diary-llm' => ['title' => 'Diary LLM', 'state' => 'live', 'description' => 'Each Core Profile can select one diary model for its assigned profiles. A change applies to newly queued manual or automatic diary jobs; queued jobs keep their frozen connector revision and saving a profile never calls a provider.'],
        'config.profiles.formatter-llm' => ['title' => 'Formatter LLM', 'state' => 'not-applicable', 'description' => 'The Herika Formatter LLM field is audit-only and its active runtime never calls it, so LORKHAN records no formatter connector.'],
        'config.player' => ['title' => 'Player', 'state' => 'live', 'description' => 'Versioned player identity and speech style.'],
        'config.player.biography-visibility' => ['title' => 'Player Biography Visibility', 'state' => 'live', 'description' => 'Player Biography Known by All chooses the biography audience. Enabled lets NPCs and the Narrator receive the player biography; disabled limits it to the Narrator. It is saved with the player profile and carried by portable player settings.'],
        'config.player.autochat-tts' => ['title' => 'Player Auto Chat and TTS', 'state' => 'live', 'description' => 'Player Management selects separate Auto Chat and TTS connectors. The in-game Interact toggle rewrites typed intent, then subtitles and speaks the final player line before the normal NPC turn.'],
        'config.player.diary' => ['title' => 'Player Diary Controls', 'state' => 'live', 'description' => 'The Player page controls manual diary permission, timer and sleep generation, optional wait generation, and the per-player cooldown while inheriting the diary model and instruction from its Core Profile.'],
        'config.player.statistics' => ['title' => 'Player Statistics', 'state' => 'live', 'description' => 'Latest typed OpenMW inventory, equipment, attributes, skills, and vital statistics.'],
        'config.player.export' => ['title' => 'Export Player Settings', 'state' => 'live', 'description' => 'Downloads the saved player profile as a portable settings preset carrying appearance, biography, the biography visibility setting, personality, speech style, goals, and notes only. It excludes the player name and identity, installation and profile identifiers, revision history, observed input counts, TTS and Profile Generation LLM routing, live OpenMW inventory, equipment, statistics, skills, playthrough context, autochat, and diary controls.'],
        'config.player.import' => ['title' => 'Import Player Settings', 'state' => 'live', 'description' => 'Applies a portable player settings preset to the existing player profile of the selected installation as a new revision. It never creates or selects a player, and never carries the player name and identity, installation and profile identifiers, revision history, observed input counts, TTS or Profile Generation LLM routing, live OpenMW and playthrough context, autochat, or diary controls.'],
        'config.narrator' => ['title' => 'Narration', 'state' => 'live', 'description' => 'Narrator identity, routing, and generation.'],
        'config.narrator.export' => ['title' => 'Export Narrator Settings', 'state' => 'live', 'description' => 'Downloads the saved narrator profile as a portable settings preset carrying narrator enablement, inline narration mode, narrator context visibility, welcome, random, bored, quest, and book event settings, their chances and cooldowns, the prompt head, core summary, background, personality, speech style, goals, notes, and narrator voice id and language. It excludes the narrator name and identity, installation and profile identifiers, revision history, and every provider and connector id including the TTS connector and the Profile Generation LLM.'],
        'config.narrator.import' => ['title' => 'Import Narrator Settings', 'state' => 'live', 'description' => 'Applies a portable narrator settings preset to the existing narrator profile of the selected installation as a new revision. It never creates or selects a narrator, and never carries the narrator name and identity, installation and profile identifiers, revision history, or any provider and connector id, so the TTS connector and Profile Generation LLM routes stay exactly as configured locally.'],
        'config.narrator.diaries' => ['title' => 'Narrator Diaries', 'state' => 'live', 'description' => 'The Narrator profile controls manual diary permission, timer and sleep generation, optional wait generation, and its own automatic diary cooldown.'],
        'config.narrator.asterisks' => ['title' => 'Narration Text Filters', 'state' => 'live', 'description' => 'Separate player, NPC and Auto Chat narration filters, with optional preservation in history.'],
        'config.narrator.event-tuning' => ['title' => 'Narration Event Tuning', 'state' => 'live', 'description' => 'Welcome, random, quest, and book narration use bounded game-owned triggers with configured chances and cooldowns.'],
        'config.narrator.bored-events' => ['title' => 'Narrator Bored Events', 'state' => 'live', 'description' => 'Eligible bored events can route through the narrator at the configured chance.'],
        'config.narrator.profile-connectors' => ['title' => 'Selected Profile Connectors', 'state' => 'live', 'description' => 'Narrator uses the selected Core Profile response and Diary LLM routes, with an explicit voice override.'],
        'config.narrator.dynamic-profile' => ['title' => 'Dynamic Profile Updates', 'state' => 'live', 'description' => 'The unlocked narrator profile can evolve selected fields from bounded witnessed dialogue every 20 minutes.'],
        'config.narrator.actions' => ['title' => 'Narrator Actions', 'state' => 'not-applicable', 'description' => 'The current OpenMW action catalog has no narrator-scoped actions.'],
        'config.narrator.prompts' => ['title' => 'Advanced Prompts', 'state' => 'live', 'description' => 'Welcome, random, bored, journal and book event prompts have shared inline and Prompts Manager editors, with revisioned custom text and Clear to restore defaults.'],
        'config.biographies' => ['title' => 'NPC Biographies', 'state' => 'live', 'description' => 'Biography editing for OpenMW NPC profiles.'],
        'config.biographies.import' => ['title' => 'Biography Import', 'state' => 'live', 'description' => 'Atomic installation-scoped CSV import for reusable OpenMW NPC templates.'],
        'config.biographies.export' => ['title' => 'Biography Export', 'state' => 'live', 'description' => 'Portable CSV export for reusable OpenMW NPC templates.'],
        'config.biographies.create' => ['title' => 'Create Biography Entry', 'state' => 'live', 'description' => 'Create reusable templates with stable OpenMW content-file and record identity.'],
        'config.biographies.reset' => ['title' => 'Reset Biography Overrides', 'state' => 'not-applicable', 'description' => 'LORKHAN uses revisioned NPC profiles and does not expose a destructive biography override-table reset.'],
        'config.biographies.oghma' => ['title' => 'Biography Oghma Links', 'state' => 'live', 'description' => 'Open Oghma articles using the biography tags or NPC name.'],
        'config.llm' => ['title' => 'LLM', 'state' => 'live', 'description' => 'Revisioned model slots and provider routing.'],
        'config.llm.identity' => ['title' => 'Connector Identity', 'state' => 'replaced', 'description' => 'LORKHAN keeps the connector name stable while model-slot content changes through immutable revisions.'],
        'config.llm.service' => ['title' => 'Provider Service Presets', 'state' => 'live', 'description' => 'Provider presets fill the endpoint and credential reference while preserving model and sampling choices.'],
        'config.llm.endpoint' => ['title' => 'Direct Connector Endpoint', 'state' => 'live', 'description' => 'A direct connector stores one complete OpenAI-compatible chat-completions URL with no query string, fragment, or userinfo. Plain HTTP is accepted for loopback hosts only; every other host must use HTTPS.'],
        'config.llm.api-key' => ['title' => 'Connector API Key', 'state' => 'replaced', 'description' => 'A connector stores only the name of a server-held credential, never a key value. Key material stays in the LORKHAN credential store and is kept out of forms, revisions, and portable exports, and an exported or imported connector is always reset to No API key.'],
        'config.llm.generation' => ['title' => 'Generation Controls', 'state' => 'live', 'description' => 'Timeout, token limits, temperature, streaming, JSON mode, disable-reasoning, and the Reasoning Model Fix cleanup can be set per connector. Blank sampling fields use provider defaults for direct connectors or inherit server settings for configured connectors.'],
        'config.llm.advanced' => ['title' => 'Advanced LLM Overrides', 'state' => 'live', 'description' => 'Top p, top k, min p, top a, and the frequency, presence, and repetition penalties are stored per connector only when explicitly set, within bounded ranges. Providers may still ignore individual values.'],
        'config.llm.legacy-controls' => ['title' => 'Legacy Herika LLM Controls', 'state' => 'replaced', 'description' => 'Unsupported format controls are omitted; the editor exposes the typed connector parameters used by LORKHAN.'],
        'config.llm.reasoning-fix' => ['title' => 'Reasoning Model Fix', 'state' => 'live', 'description' => 'A per-connector override that removes one leading balanced <think>, <thinking>, or <reasoning> block from a response before strict JSON parsing. It stays off unless set, or unless a configured runtime supplies it. Disable reasoning is a separate override that asks the provider not to produce reasoning at all; cleaning a returned block never disables reasoning and never relaxes JSON or result validation.'],
        'config.llm.json-schema' => ['title' => 'JSON Schema and Prefill', 'state' => 'planned', 'description' => 'Sending a provider-side JSON Schema or a prefilled starter object is not implemented. The JSON mode override only toggles the provider JSON-mode hint; LORKHAN validates its own bounded output either way.'],
        'config.llm.action-prompt' => ['title' => 'Remove Action Prompt', 'state' => 'replaced', 'description' => 'Action enforcement comes from revisioned scoped action policies over the negotiated OpenMW catalogue, so there is no per-connector switch that disables an action prompt.'],
        'config.llm.body-parameters' => ['title' => 'Request Body Parameters', 'state' => 'planned', 'description' => 'Arbitrary YAML request-body parameters are intentionally disabled until they have a bounded typed schema. The named sampling overrides above cover the supported parameters.'],
        'config.llm.import-format' => ['title' => 'Portable Connector Import', 'state' => 'replaced', 'description' => 'LORKHAN imports redacted portable JSON model slots instead of Herika connector CSV files.'],
        'config.llm.delete-protected' => ['title' => 'Protected Connector Deletion', 'state' => 'not-applicable', 'description' => 'A connector assigned to a profile or active session cannot be deleted.'],
        'config.llm.saved-only' => ['title' => 'Saved Connector Control', 'state' => 'not-applicable', 'description' => 'This control becomes available after the connector has been saved.'],
        'config.tts' => ['title' => 'TTS', 'state' => 'live', 'description' => 'Revisioned speech connectors and defaults.'],
        'config.tts.api-key' => ['title' => 'Connector API Badge', 'state' => 'replaced', 'description' => 'TTS credentials remain server-owned and are selected through LORKHAN API Keys rather than embedded in portable connector revisions.'],
        'config.tts.import-format' => ['title' => 'Portable TTS Import', 'state' => 'replaced', 'description' => 'LORKHAN imports redacted portable JSON connector records instead of Herika connector CSV files.'],
        'config.tts.delete-protected' => ['title' => 'Protected Connector Deletion', 'state' => 'not-applicable', 'description' => 'The installation default or a connector assigned to a Core Profile, NPC, or active session cannot be deleted.'],
        'config.tts.saved-only' => ['title' => 'Saved Connector Control', 'state' => 'not-applicable', 'description' => 'This control becomes available after the connector has been saved.'],
        'config.tts-studio' => ['title' => 'TTS Studio', 'state' => 'live', 'description' => 'Voice samples, provider catalogues, and synchronization.'],
        'config.tts-studio.cloud-cloning' => ['title' => 'Cloud Voice Cloning', 'state' => 'live', 'description' => 'Explicit Cartesia and Inworld voice discovery and consent-based sample uploads.'],
        'config.tts-studio.fallbacks' => ['title' => 'Race and Gender Fallback Matrix', 'state' => 'live', 'description' => 'Global race and gender fallback voices shared by every TTS connector, after an explicit NPC voice.'],
        'config.tts-studio.batch-sync' => ['title' => 'Batch Provider Sync', 'state' => 'live', 'description' => 'Sequential, resumable sample uploads with explicit consent and stop-after-current control.'],
        'config.tts-studio.provider-delete' => ['title' => 'Provider Copy Deletion', 'state' => 'replaced', 'description' => 'LORKHAN deletes persistent local samples only after checking every typed profile and connector reference.'],
        'config.stt' => ['title' => 'STT', 'state' => 'live', 'description' => 'One installation-global CHIM-compatible speech-to-text connector with typed OpenMW capture and durable transcription.'],
        'config.stt.google-free' => ['title' => 'Google Free STT', 'state' => 'replaced', 'description' => 'Browser-only dictation cannot carry LORKHAN target, session, and generation fencing; use the authenticated in-game microphone controls instead.'],
        'config.keys' => ['title' => 'API Keys', 'state' => 'live', 'description' => 'Masked browser-managed provider credentials.'],
        'config.keys.test' => ['title' => 'API Key Test', 'state' => 'live', 'description' => 'Bounded metadata requests test supported provider credentials without generating content.'],
        'config.keys.environment' => ['title' => 'Environment Credential', 'state' => 'replaced', 'description' => 'This credential is controlled by the Apache and worker environment and cannot be replaced from the browser.'],
        'config.keys.custom' => ['title' => 'Custom API Keys', 'state' => 'live', 'description' => 'Named server-held custom keys can be selected by direct LLM connectors.'],
        'config.keys.groq' => ['title' => 'Groq Credential', 'state' => 'live', 'description' => 'Groq credentials are available to direct OpenAI-compatible LLM connectors.'],
        'config.keys.nano-gpt' => ['title' => 'Nano-GPT Credential', 'state' => 'live', 'description' => 'Nano-GPT credentials are available to direct OpenAI-compatible LLM connectors.'],
        'config.keys.deepl' => ['title' => 'DeepL Credential', 'state' => 'live', 'description' => 'LORKHAN_DEEPL_API_KEY is the server-held key used by NPC output translation. The value stays in the LORKHAN credential store and is never displayed, serialized, or exported.'],
        'config.globals' => ['title' => 'Global Settings', 'state' => 'live', 'description' => 'Installation-wide rechat, context, knowledge, relationship, translation, profile management, and system connector settings.'],
        'config.globals.export' => ['title' => 'Export Global Settings', 'state' => 'live', 'description' => 'Downloads one complete portable Global Settings document, including memory service policies and system connector references but excluding installation identity, revision history, API keys, Core Profiles, NPC profiles, and assignments.'],
        'config.globals.import' => ['title' => 'Import Global Settings', 'state' => 'live', 'description' => 'Applies one complete portable Global Settings document as a new revision. Legacy presets preserve local settings they did not carry; v3 also restores memory summary and embedding policies atomically.'],
        'config.globals.revision-history' => ['title' => 'Global Settings Revision History', 'state' => 'live', 'description' => 'Restores an earlier Global Settings document as one new revision and keeps its installation-wide sidecar settings synchronized.'],
        'config.globals.prompt-head' => ['title' => 'Global Prompt Head', 'state' => 'replaced', 'description' => 'LORKHAN uses revisioned Prompt Manager documents and Core Profile prompt routing instead of one untyped global prompt field.'],
        'config.globals.emote-moods' => ['title' => 'Global Emote Moods', 'state' => 'replaced', 'description' => 'LORKHAN stores profile text and moods on Core Profiles and explicit NPC overrides rather than one untyped global list.'],
        'config.globals.rechat-mode' => ['title' => 'Rechat Mode', 'state' => 'live', 'description' => 'Herika-compatible tight, conversational, group, and random responder selection.'],
        'config.globals.strict-rechat' => ['title' => 'Strict Rechat Targeting', 'state' => 'live', 'description' => 'Require each rechat response to address the previous speaker.'],
        'config.globals.memory-recall' => ['title' => 'Long-Term Memory Recall', 'state' => 'live', 'description' => 'Memory tier retrieval is controlled in Core Profiles.'],
        'config.globals.memory-embedding' => ['title' => 'Memory Embedding Service', 'state' => 'live', 'description' => 'Global Settings controls the MiniMe embedding endpoint and timeout.'],
        'config.globals.memory-summary' => ['title' => 'Automatic Memory Summaries', 'state' => 'live', 'description' => 'Global Settings controls model summaries and bounded game-time or event-count grouping.'],
        'config.globals.worst-memory' => ['title' => 'Worst Memory Lifespan', 'state' => 'not-applicable', 'description' => 'The pinned HerikaServer reference exposes this setting but does not read it in runtime code. LORKHAN does not show an inert expiry control.'],
        'config.globals.profile-autofill' => ['title' => 'Automatic Profile Backfill', 'state' => 'live', 'description' => 'Unlocked empty NPC profiles can be generated after the configured amount of completed actor dialogue history.'],
        'config.globals.conversation-cooldown' => ['title' => 'End Conversation Cooldown', 'state' => 'live', 'description' => 'Herika-compatible cooldown before another playback rechat chain may begin.'],
        'config.globals.translation' => ['title' => 'Translation', 'state' => 'live', 'description' => 'Server-only DeepL translation of NPC subtitles and NPC speech audio. The policy is stored per installation, frozen for each turn, and saving it never calls DeepL.'],
        'config.oghma' => ['title' => 'Oghma Infinium', 'state' => 'live', 'description' => 'Scoped world-knowledge documents and retrieval data.'],
        'config.oghma.dynamic' => ['title' => 'Dynamic Oghma', 'state' => 'excluded', 'description' => 'Background quest-stage knowledge mutation is excluded from the current bounded LORKHAN scope.'],
        'config.oghma.batch' => ['title' => 'Oghma CSV Batch', 'state' => 'live', 'description' => 'Installation-scoped typed CSV import and example export.'],
        'config.oghma.destructive' => ['title' => 'Destructive Oghma Reset', 'state' => 'live', 'description' => 'Confirmed, installation-scoped Delete All and Factory Reset controls preserve NPC/playthrough knowledge and other installations.'],
        'config.descriptions' => ['title' => 'Descriptions', 'state' => 'live', 'description' => 'Morrowind description records.'],
        'config.descriptions.batch' => ['title' => 'Description CSV Batch', 'state' => 'live', 'description' => 'Installation-scoped typed description import, example download, and custom export.'],
        'config.descriptions.reset' => ['title' => 'Description Factory Reset', 'state' => 'replaced', 'description' => 'LORKHAN preserves scoped records and uses individual deletion plus Database Manager backups instead of a destructive table reset.'],
        'config.actions' => ['title' => 'Action Editor', 'state' => 'live', 'description' => 'OpenMW capability-aware action policies.'],
        'config.actions.direct-edit' => ['title' => 'Direct Action Editing', 'state' => 'replaced', 'description' => 'LORKHAN receives an immutable negotiated OpenMW action catalogue and applies permissions through revisioned scoped policies.'],
        'config.actions.advanced' => ['title' => 'Action Advanced Options', 'state' => 'replaced', 'description' => 'Confirmation and tier behavior are represented by typed LORKHAN action metadata and scoped policies rather than arbitrary per-action JSON.'],
        'config.prompts' => ['title' => 'Prompts Manager', 'state' => 'live', 'description' => 'Revisioned prompt templates and routing.'],
        'config.prompts.csv' => ['title' => 'Herika Prompt CSV', 'state' => 'live', 'description' => 'Import and export prompt_key/custom_prompt CSV overrides for the selected installation.'],
        'roleplay.events' => ['title' => 'Events', 'state' => 'live', 'description' => 'Immutable OpenMW and dialogue source events.'],
        'roleplay.responses' => ['title' => 'AI Responses', 'state' => 'live', 'description' => 'Persisted dialogue and delivery states.'],
        'roleplay.adventure' => ['title' => 'Adventure Log', 'state' => 'live', 'description' => 'Narratives, summaries, and playthrough records.'],
        'roleplay.memories' => ['title' => 'Memories', 'state' => 'live', 'description' => 'Recent, middle-term, and long-term memory records.'],
        'roleplay.diaries' => ['title' => 'LORKHAN Diaries', 'state' => 'live', 'description' => 'Versioned diary narratives.'],
        'roleplay.books' => ['title' => 'Books', 'state' => 'live', 'description' => 'Books observed during OpenMW sessions.'],
        'roleplay.journal' => ['title' => 'Journal', 'state' => 'live', 'description' => 'Morrowind Journal entries received through bounded typed OpenMW context.'],
        'roleplay.destructive' => ['title' => 'Bulk Roleplay Deletion', 'state' => 'replaced', 'description' => 'LORKHAN preserves typed audit records and uses bounded retention and Database Manager backups instead of destructive browser bulk deletion.'],

        'control.logs' => ['title' => 'Server Logs', 'state' => 'live', 'description' => 'Bounded redacted server logs.'],
        'control.requests' => ['title' => 'Request Logs', 'state' => 'live', 'description' => 'LLM attempts, recorded prompt/result readers, token usage and protected log clearing.'],
        'control.oghma-audit' => ['title' => 'Oghma Audit', 'state' => 'live', 'description' => 'Knowledge retrieval audit records.'],
        'control.relationships' => ['title' => 'Relationship Logs', 'state' => 'live', 'description' => 'Relationship change audit.'],
        'control.usage' => ['title' => 'Cost Breakdown', 'state' => 'live', 'description' => 'Recorded provider token counts and reported costs; unavailable costs are identified explicitly.'],
        'control.responses' => ['title' => 'Response Queue', 'state' => 'live', 'description' => 'Queued dialogue, action and lifecycle messages, with recorded playback details and protected log removal.'],
        'control.providers' => ['title' => 'Provider Attempts', 'state' => 'live', 'description' => 'Provider latency and bounded error traces.'],
        'control.jobs' => ['title' => 'Workers & Jobs', 'state' => 'live', 'description' => 'Durable worker and dead-letter status.'],
        'control.cache' => ['title' => 'Audio Cache', 'state' => 'live', 'description' => 'Authenticated playback of unexpired audio using opaque media IDs.'],
        'control.playthroughs' => ['title' => 'Playthrough Manager', 'state' => 'live', 'description' => 'Playthrough backup and restore.'],
        'control.database' => ['title' => 'Database Manager', 'state' => 'live', 'description' => 'Schema, backup, and retention operations.'],
        'control.game-debug' => ['title' => 'Game Debug', 'state' => 'live', 'description' => 'Typed operator-only OpenMW diagnostics for the connected local game.'],
        'control.updater' => ['title' => 'Update Server', 'state' => 'replaced', 'description' => 'Server updates are performed through the guarded LORKHAN deployment workflow.', 'controls' => ['Check for Updates', 'Install Update']],
    ];

    return $features;
}

/** Return a bounded feature record, defaulting unknown copied controls to a safe planned state. */
function lorkhan_ui_feature(string $id): array
{
    $feature = lorkhan_ui_feature_registry()[$id] ?? [
        'title' => ucwords(str_replace(['.', '-'], ' ', $id)),
        'state' => 'planned',
        'description' => 'This copied Herika surface has not been connected to LORKHAN yet.',
    ];
    $feature['id'] = $id;
    $feature['controls'] ??= [];
    return $feature;
}

/** Render the standardized feature-state tag used by hubs and inert copied controls. */
function lorkhan_ui_feature_badge(string $id, bool $compact = false): string
{
    $feature = lorkhan_ui_feature($id);
    $labels = [
        'live' => 'Live',
        'planned' => 'Planned',
        'excluded' => 'Excluded',
        'not-applicable' => 'Not Applicable',
        'replaced' => 'Replaced',
    ];
    $state = (string) $feature['state'];
    // Working controls use the same uncluttered labels as CHIM; exceptions remain explicit.
    if($state==='live')return '';
    return '<span class="feature-state-badge feature-state-' . lorkhan_ui_h($state) . ($compact ? ' feature-state-compact' : '') . '">' . lorkhan_ui_h($labels[$state] ?? 'Planned') . '</span>';
}

/** Render a copied but intentionally inert control with its availability explained in-place. */
function lorkhan_ui_placeholder_control(string $label, string $featureId): string
{
    $feature = lorkhan_ui_feature($featureId);
    return '<button type="button" class="btn-base feature-placeholder-control" disabled aria-disabled="true" title="' . lorkhan_ui_h($feature['description']) . '">' . lorkhan_ui_h($label) . ' ' . lorkhan_ui_feature_badge($featureId, true) . '</button>';
}
