<?php

declare(strict_types=1);

/** Central source of truth for copied Herika surfaces and their LORKHAN availability. */
function lorkhan_ui_feature_registry(): array
{
    static $features = [
        'home' => ['title' => 'Home', 'state' => 'live', 'description' => 'LORKHAN status, activity, and setup overview.'],
        'quickstart' => ['title' => 'Quickstart', 'state' => 'planned', 'description' => 'The Herika onboarding presentation is retained while LORKHAN pairing and provider setup are consolidated.'],
        'autonomy' => ['title' => 'Autonomy', 'state' => 'excluded', 'description' => 'Automatic greetings, boredom events, combat barks, schedules, and other model-triggering autonomy are excluded from this milestone.'],
        'actions.rechat' => ['title' => 'Rechat Actions', 'state' => 'excluded', 'description' => 'Herika-style playback rechat is supported, but action generation during a rechat exchange remains disabled.'],
        'presentation.local' => ['title' => 'Local OpenMW Presentation', 'state' => 'replaced', 'description' => 'HUD visibility, transcript size, panel layout, hotkeys, and LORKHAN TTS volume boost are local OpenMW preferences and cannot be overridden by Global, Core Profile, or NPC settings.'],

        'config.npc' => ['title' => 'LORKHAN NPCs', 'state' => 'live', 'description' => 'Versioned OpenMW NPC profiles and explicit overrides.'],
        'config.npc.relationship-builder' => ['title' => 'Build Relationships', 'state' => 'live', 'description' => "Converts saved NPC profile Relationships text into typed relationship records for one playthrough, using each NPC's saved Relationship LLM."],
        'config.npc.reset' => ['title' => 'Reset NPC', 'state' => 'planned', 'description' => 'OpenMW save-aware NPC reset is not connected yet.'],
        'config.npc.identity' => ['title' => 'OpenMW NPC Identity', 'state' => 'replaced', 'description' => 'NPC names and record identities are learned from stable OpenMW actor data rather than edited in the browser.'],
        'config.npc.memory-bank' => ['title' => 'NPC Memory Bank', 'state' => 'replaced', 'description' => 'LORKHAN resolves recent, middle-term, and long-term memory through typed repositories instead of a per-NPC switch.'],
        'config.npc.inherited-filter' => ['title' => 'Inherited NPC Filter', 'state' => 'excluded', 'description' => 'Autonomy filters are excluded from this milestone.'],
        'config.npc.saved-only' => ['title' => 'Saved NPC Control', 'state' => 'not-applicable', 'description' => 'This control becomes available after the NPC profile has been created.'],
        'config.profiles' => ['title' => 'Profiles', 'state' => 'live', 'description' => 'Core Profiles between Global Settings and individual NPC overrides.'],
        'config.profiles.import' => ['title' => 'Import Settings Preset', 'state' => 'live', 'description' => 'Creates a new unassigned Core Profile from a portable settings preset. A preset carries Core Profile settings overrides only, and excludes prompt text, connector routing, identifiers, slots, default status, revision history, and NPC assignments.'],
        'config.profiles.rules' => ['title' => 'Profile Rules', 'state' => 'live', 'description' => 'Assigns a Core Profile automatically when a previously unknown NPC is first discovered. A rule matches on name, race, class, gender, OpenMW textual faction ID and content file: every field you fill must match, and several values in one field mean any of them. Text is compared without regard to capitals and must match in full. The highest priority enabled rule wins, and the older rule wins a tie. NPCs already assigned to a Core Profile, and any Core Profile set by hand, are never changed.'],
        'config.profiles.test' => ['title' => 'Test Profiles', 'state' => 'live', 'description' => 'Shows every LLM and TTS connector routed by these Core Profiles, then tests each distinct connector once only after you press Run tests. Live providers may charge for the request; replies are not saved or shown.'],
        'config.profiles.export' => ['title' => 'Export Settings Preset', 'state' => 'live', 'description' => 'Downloads this Core Profile as a portable settings preset. The preset carries Core Profile settings overrides only, and excludes prompt text, connector routing, identifiers, slots, default status, revision history, and NPC assignments.'],
        'config.profiles.clone' => ['title' => 'Clone Profile', 'state' => 'replaced', 'description' => 'NPC cloning is available in the typed LORKHAN NPC editor; Core Profile duplication remains an explicit create-and-edit workflow.'],
        'config.profiles.metadata' => ['title' => 'Profile Identity Metadata', 'state' => 'replaced', 'description' => 'Core Profile identity metadata stays stable while this page creates immutable content revisions.'],
        'config.profiles.delete-protected' => ['title' => 'Protected Profile Deletion', 'state' => 'not-applicable', 'description' => 'The default Core Profile or a profile assigned to NPCs cannot be deleted.'],
        'config.profiles.dynamic-profile' => ['title' => 'Dynamic Profile', 'state' => 'excluded', 'description' => 'Automatic profile evolution is autonomy and is excluded from this milestone.'],
        'config.profiles.middle-term-memory' => ['title' => 'Middle Term Memory', 'state' => 'replaced', 'description' => 'LORKHAN uses its typed recent, middle-term, and long-term memory repositories instead of a profile toggle.'],
        'config.profiles.auto-diary' => ['title' => 'Automatic Diary Generation', 'state' => 'excluded', 'description' => 'Timer, sleep, and wait triggered diary generation is excluded from the current LORKHAN scope. A diary is written only when a person explicitly requests one from Narratives.'],
        'config.profiles.physical-diary' => ['title' => 'Physical Diary', 'state' => 'not-applicable', 'description' => 'LORKHAN does not currently materialize generated diaries as OpenMW inventory books. A requested diary is stored as a typed diary narrative only.'],
        'config.profiles.latest-diary' => ['title' => 'Diary Context', 'state' => 'live', 'description' => 'Diary In Context keeps scoped diary narratives in this profile roleplay context, and turning it off removes them. Which diaries are in scope is still decided by the typed narrative and memory pipeline rather than a per-entry flag.'],
        'config.profiles.language' => ['title' => 'Profile Language', 'state' => 'not-applicable', 'description' => 'The current LORKHAN OpenMW dialogue pipeline uses installation-wide language handling.'],
        'config.profiles.diary-llm' => ['title' => 'NPC Diary LLM Override', 'state' => 'live', 'description' => 'The NPC editor selects the diary connector for one NPC. Inherit Core Profile leaves the Core Profile connector in charge, Disabled for this NPC refuses manual diary generation for that NPC, and a named connector routes its diaries to that exact same-installation connector. A change applies to newly queued manual diary jobs only: already queued jobs keep their frozen connector revision, saving an NPC never calls a provider, and automatic diary generation stays excluded.'],
        'config.profiles.formatter-llm' => ['title' => 'Formatter LLM', 'state' => 'not-applicable', 'description' => 'The Herika Formatter LLM field is audit-only and its active runtime never calls it, so LORKHAN records no formatter connector.'],
        'config.player' => ['title' => 'Player', 'state' => 'live', 'description' => 'Versioned player identity and speech style.'],
        'config.player.biography-visibility' => ['title' => 'Player Biography Visibility', 'state' => 'live', 'description' => 'Player Biography Known by All chooses the biography audience. Enabled lets NPCs and the Narrator receive the player biography; disabled limits it to the Narrator. It is saved with the player profile and carried by portable player settings.'],
        'config.player.autochat-tts' => ['title' => 'Player Autochat and TTS', 'state' => 'excluded', 'description' => 'Player autochat is autonomy and is excluded; local player TTS presentation remains an OpenMW preference.'],
        'config.player.diary' => ['title' => 'Player Diary Controls', 'state' => 'planned', 'description' => 'The Player page exposes no diary controls. A player diary request resolves diary opt-in, the diary connector, and the diary instruction from the Core Profile the player profile inherits, and automatic player diary generation stays excluded.'],
        'config.player.statistics' => ['title' => 'Player Statistics', 'state' => 'live', 'description' => 'Latest typed OpenMW inventory, equipment, attributes, skills, and vital statistics.'],
        'config.player.export' => ['title' => 'Export Player Settings', 'state' => 'live', 'description' => 'Downloads the saved player profile as a portable settings preset carrying appearance, biography, the biography visibility setting, personality, speech style, goals, and notes only. It excludes the player name and identity, installation and profile identifiers, revision history, observed input counts, the Profile Generation LLM and every other connector route, live OpenMW inventory, equipment, statistics, skills, and playthrough context, and the excluded autochat, TTS, and diary controls.'],
        'config.player.import' => ['title' => 'Import Player Settings', 'state' => 'live', 'description' => 'Applies a portable player settings preset to the existing player profile of the selected installation as a new revision. It never creates or selects a player, and never carries the player name and identity, installation and profile identifiers, revision history, observed input counts, the Profile Generation LLM or any other connector route, live OpenMW and playthrough context, or the excluded autochat, TTS, and diary controls.'],
        'config.narrator' => ['title' => 'Narration', 'state' => 'live', 'description' => 'Narrator identity, routing, and generation.'],
        'config.narrator.export' => ['title' => 'Export Narrator Settings', 'state' => 'live', 'description' => 'Downloads the saved narrator profile as a portable settings preset carrying narrator enablement, inline narration mode, narrator context visibility, the welcome, random, quest, and book event switches, the prompt head, core summary, background, personality, speech style, goals, and notes, and the narrator voice id and language. It excludes the narrator name and identity, installation and profile identifiers, revision history, and every provider and connector id including the TTS connector and the Profile Generation LLM.'],
        'config.narrator.import' => ['title' => 'Import Narrator Settings', 'state' => 'live', 'description' => 'Applies a portable narrator settings preset to the existing narrator profile of the selected installation as a new revision. It never creates or selects a narrator, and never carries the narrator name and identity, installation and profile identifiers, revision history, or any provider and connector id, so the TTS connector and Profile Generation LLM routes stay exactly as configured locally.'],
        'config.narrator.diaries' => ['title' => 'Narrator Diaries', 'state' => 'planned', 'description' => 'Narrator-specific diary permissions and recall rules are not connected to the typed LORKHAN narrator profile yet, and automatic narrator diary generation stays excluded.'],
        'config.narrator.asterisks' => ['title' => 'Narration Text Filters', 'state' => 'planned', 'description' => 'Per-source asterisk filtering is not configurable in the current LORKHAN response pipeline.'],
        'config.narrator.event-tuning' => ['title' => 'Narration Event Tuning', 'state' => 'excluded', 'description' => 'Automatic narrator event triggers, chances, and cooldowns are autonomy and are excluded from this milestone.'],
        'config.narrator.bored-events' => ['title' => 'Narrator Bored Events', 'state' => 'excluded', 'description' => 'Bored-event narrator routing is autonomy and is excluded from this milestone.'],
        'config.narrator.profile-connectors' => ['title' => 'Selected Profile Connectors', 'state' => 'replaced', 'description' => 'LORKHAN uses its explicit narrator TTS route and the inherited model pipeline instead of a Herika profile connector bundle.'],
        'config.narrator.dynamic-profile' => ['title' => 'Dynamic Profile Updates', 'state' => 'excluded', 'description' => 'Automatic narrator profile evolution is autonomy and is excluded from this milestone.'],
        'config.narrator.actions' => ['title' => 'Narrator Actions', 'state' => 'replaced', 'description' => 'Narrator action availability is managed centrally through LORKHAN Action Editor policies.'],
        'config.narrator.prompts' => ['title' => 'Advanced Prompts', 'state' => 'replaced', 'description' => 'Narrator prompt templates are managed centrally through LORKHAN Prompts Manager.'],
        'config.biographies' => ['title' => 'NPC Biographies', 'state' => 'live', 'description' => 'Biography editing for OpenMW NPC profiles.'],
        'config.biographies.import' => ['title' => 'Biography Import', 'state' => 'live', 'description' => 'Atomic installation-scoped CSV import for reusable OpenMW NPC templates.'],
        'config.biographies.export' => ['title' => 'Biography Export', 'state' => 'live', 'description' => 'Portable CSV export for reusable OpenMW NPC templates.'],
        'config.biographies.create' => ['title' => 'Create Biography Entry', 'state' => 'replaced', 'description' => 'LORKHAN creates NPC profiles from stable OpenMW actor identity; create the NPC through the NPC page or encounter it in game.'],
        'config.biographies.reset' => ['title' => 'Reset Biography Overrides', 'state' => 'not-applicable', 'description' => 'LORKHAN uses revisioned NPC profiles and does not expose a destructive biography override-table reset.'],
        'config.biographies.oghma' => ['title' => 'Biography Oghma Links', 'state' => 'replaced', 'description' => 'World knowledge is managed centrally through LORKHAN Oghma Infinium instead of per-template biography links.'],
        'config.llm' => ['title' => 'LLM', 'state' => 'live', 'description' => 'Revisioned model slots and provider routing.'],
        'config.llm.identity' => ['title' => 'Connector Identity', 'state' => 'replaced', 'description' => 'LORKHAN keeps the connector name stable while model-slot content changes through immutable revisions.'],
        'config.llm.service' => ['title' => 'Provider Service Presets', 'state' => 'replaced', 'description' => 'Herika picks a provider from an icon strip that fills in an endpoint for you. LORKHAN either inherits the server runtime connection or takes one complete endpoint URL you type, and never rewrites or guesses a URL, so the preset icons stay inert.'],
        'config.llm.endpoint' => ['title' => 'Direct Connector Endpoint', 'state' => 'live', 'description' => 'A direct connector stores one complete OpenAI-compatible chat-completions URL with no query string, fragment, or userinfo. Plain HTTP is accepted for loopback hosts only; every other host must use HTTPS.'],
        'config.llm.api-key' => ['title' => 'Connector API Key', 'state' => 'replaced', 'description' => 'A connector stores only the name of a server-held credential, never a key value. Key material stays in the LORKHAN credential store and is kept out of forms, revisions, and portable exports, and an exported or imported connector is always reset to No API key.'],
        'config.llm.generation' => ['title' => 'Generation Controls', 'state' => 'live', 'description' => 'Timeout, token limits, temperature, streaming, JSON mode, disable-reasoning, and the Reasoning Model Fix cleanup can be set per connector. Blank sampling fields use provider defaults for direct connectors or inherit server settings for configured connectors.'],
        'config.llm.advanced' => ['title' => 'Advanced LLM Overrides', 'state' => 'live', 'description' => 'Top p, top k, min p, top a, and the frequency, presence, and repetition penalties are stored per connector only when explicitly set, within bounded ranges. Providers may still ignore individual values.'],
        'config.llm.legacy-controls' => ['title' => 'Legacy Herika LLM Controls', 'state' => 'planned', 'description' => 'Inherited Herika connector controls that LORKHAN has not implemented are grouped together and left inert rather than shown as working switches.'],
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
        'config.tts-studio.cloud-cloning' => ['title' => 'Cloud Voice Cloning', 'state' => 'planned', 'description' => 'Cartesia and Inworld voice-clone generation are not connected to the typed LORKHAN voice library yet.'],
        'config.tts-studio.fallbacks' => ['title' => 'Race and Gender Fallback Matrix', 'state' => 'replaced', 'description' => 'LORKHAN resolves explicit NPC voices first and stores bounded male and female fallback IDs on each typed TTS connector.'],
        'config.tts-studio.batch-sync' => ['title' => 'Batch Provider Sync', 'state' => 'replaced', 'description' => 'LORKHAN uses explicit per-sample synchronization so provider writes stay bounded and auditable.'],
        'config.tts-studio.provider-delete' => ['title' => 'Provider Copy Deletion', 'state' => 'replaced', 'description' => 'LORKHAN deletes persistent local samples only after checking every typed profile and connector reference.'],
        'config.stt' => ['title' => 'STT', 'state' => 'live', 'description' => 'One installation-global CHIM-compatible speech-to-text connector with typed OpenMW capture and durable transcription.'],
        'config.stt.google-free' => ['title' => 'Google Free STT', 'state' => 'replaced', 'description' => 'Browser-only dictation cannot carry LORKHAN target, session, and generation fencing; use the authenticated in-game microphone controls instead.'],
        'config.keys' => ['title' => 'API Keys', 'state' => 'live', 'description' => 'Masked browser-managed provider credentials.'],
        'config.keys.test' => ['title' => 'API Key Test', 'state' => 'planned', 'description' => 'Bounded browser-side provider credential tests are not connected to the typed provider clients yet.'],
        'config.keys.environment' => ['title' => 'Environment Credential', 'state' => 'replaced', 'description' => 'This credential is controlled by the Apache and worker environment and cannot be replaced from the browser.'],
        'config.keys.custom' => ['title' => 'Custom API Keys', 'state' => 'planned', 'description' => 'Arbitrary custom credential names are disabled until the typed provider catalogue can consume them.'],
        'config.keys.groq' => ['title' => 'Groq Credential', 'state' => 'planned', 'description' => 'Groq is not represented in the current typed LORKHAN LLM provider document.'],
        'config.keys.nano-gpt' => ['title' => 'Nano-GPT Credential', 'state' => 'planned', 'description' => 'Nano-GPT is not represented in the current typed LORKHAN LLM provider document.'],
        'config.keys.deepl' => ['title' => 'DeepL Credential', 'state' => 'live', 'description' => 'LORKHAN_DEEPL_API_KEY is the server-held key used by NPC output translation. The value stays in the LORKHAN credential store and is never displayed, serialized, or exported.'],
        'config.globals' => ['title' => 'Global Settings', 'state' => 'live', 'description' => 'Installation-wide defaults inherited by Core Profiles and NPCs.'],
        'config.globals.export' => ['title' => 'Export Global Settings', 'state' => 'live', 'description' => 'Downloads the saved typed Global Settings document as a portable file. It excludes installation identity, revision history, Core Profile and NPC overrides, connector routing, API keys, NPC translation policy, Oghma catalog and access settings, Auto Lock Profile, and NPC assignments.'],
        'config.globals.import' => ['title' => 'Import Global Settings', 'state' => 'live', 'description' => 'Applies a portable Global Settings document to the selected installation as a new revision. An import replaces the typed Global Settings document only, and never carries installation identity, revision history, Core Profile or NPC overrides, connector routing, API keys, NPC translation policy, Oghma catalog and access settings, Auto Lock Profile, or NPC assignments. Local OpenMW HUD, transcript, and TTS preferences stay client-local even though compatibility fields exist in the strict document.'],
        'config.globals.revision-history' => ['title' => 'Global Settings Revision History', 'state' => 'live', 'description' => 'Lists saved Global Settings revisions and restores an earlier one as a new revision. Restoring rewrites the typed Global Settings document only and deletes no revision.'],
        'config.globals.prompt-head' => ['title' => 'Global Prompt Head', 'state' => 'replaced', 'description' => 'LORKHAN uses revisioned Prompt Manager documents and Core Profile prompt routing instead of one untyped global prompt field.'],
        'config.globals.emote-moods' => ['title' => 'Global Emote Moods', 'state' => 'replaced', 'description' => 'LORKHAN stores profile text and moods on Core Profiles and explicit NPC overrides rather than one untyped global list.'],
        'config.globals.rechat-mode' => ['title' => 'Rechat Mode', 'state' => 'live', 'description' => 'Herika-compatible tight, conversational, group, and random responder selection.'],
        'config.globals.strict-rechat' => ['title' => 'Strict Rechat Targeting', 'state' => 'live', 'description' => 'Require each rechat response to address the previous speaker.'],
        'config.globals.memory-recall' => ['title' => 'Long-Term Memory Recall', 'state' => 'replaced', 'description' => 'LORKHAN always resolves bounded recent, middle-term, and long-term memory through its typed repositories instead of a global recall switch.'],
        'config.globals.memory-embedding' => ['title' => 'Memory Embedding Service', 'state' => 'planned', 'description' => 'A configurable MiniMe or TXT2VEC embedding service is not connected to the typed LORKHAN memory pipeline yet.'],
        'config.globals.memory-summary' => ['title' => 'Automatic Memory Summaries', 'state' => 'planned', 'description' => 'Automatic interval-based memory summary generation is not connected to the typed LORKHAN worker yet.'],
        'config.globals.worst-memory' => ['title' => 'Worst Memory Lifespan', 'state' => 'planned', 'description' => 'Game-day expiry for relationship memories is not represented in the current typed LORKHAN memory schema.'],
        'config.globals.profile-autofill' => ['title' => 'Automatic Profile Backfill', 'state' => 'excluded', 'description' => 'Automatic AI profile generation is autonomy and is excluded from this milestone.'],
        'config.globals.conversation-cooldown' => ['title' => 'End Conversation Cooldown', 'state' => 'live', 'description' => 'Herika-compatible cooldown before another playback rechat chain may begin.'],
        'config.globals.quest-progression' => ['title' => 'CHIM AI Quest Progression', 'state' => 'not-applicable', 'description' => 'These controls target Skyrim quest-stage actions; LORKHAN exposes Morrowind quest state through the Journal instead.'],
        'config.globals.translation' => ['title' => 'Translation', 'state' => 'live', 'description' => 'Server-only DeepL translation of NPC subtitles and NPC speech audio. The policy is stored per installation, frozen for each turn, and saving it never calls DeepL.'],
        'config.oghma' => ['title' => 'Oghma Infinium', 'state' => 'live', 'description' => 'Scoped world-knowledge documents and retrieval data.'],
        'config.oghma.dynamic' => ['title' => 'Dynamic Oghma', 'state' => 'excluded', 'description' => 'Background quest-stage knowledge mutation is excluded from the current bounded LORKHAN scope.'],
        'config.oghma.batch' => ['title' => 'Oghma CSV Batch', 'state' => 'planned', 'description' => 'Scoped typed CSV import and example export are not connected yet.'],
        'config.oghma.destructive' => ['title' => 'Destructive Oghma Reset', 'state' => 'replaced', 'description' => 'LORKHAN uses bounded Database Manager backups and retention instead of unscoped delete-all or factory-reset controls.'],
        'config.descriptions' => ['title' => 'Descriptions', 'state' => 'live', 'description' => 'Morrowind description records.'],
        'config.descriptions.batch' => ['title' => 'Description CSV Batch', 'state' => 'live', 'description' => 'Installation-scoped typed description import, example download, and custom export.'],
        'config.descriptions.reset' => ['title' => 'Description Factory Reset', 'state' => 'replaced', 'description' => 'LORKHAN preserves scoped records and uses individual deletion plus Database Manager backups instead of a destructive table reset.'],
        'config.actions' => ['title' => 'Action Editor', 'state' => 'live', 'description' => 'OpenMW capability-aware action policies.'],
        'config.actions.direct-edit' => ['title' => 'Direct Action Editing', 'state' => 'replaced', 'description' => 'LORKHAN receives an immutable negotiated OpenMW action catalogue and applies permissions through revisioned scoped policies.'],
        'config.actions.advanced' => ['title' => 'Action Advanced Options', 'state' => 'replaced', 'description' => 'Confirmation and tier behavior are represented by typed LORKHAN action metadata and scoped policies rather than arbitrary per-action JSON.'],
        'config.prompts' => ['title' => 'Prompts Manager', 'state' => 'live', 'description' => 'Revisioned prompt templates and routing.'],
        'config.prompts.csv' => ['title' => 'Herika Prompt CSV', 'state' => 'replaced', 'description' => 'LORKHAN uses redacted portable JSON documents so typed revisions and installation ownership remain intact.'],
        'roleplay.events' => ['title' => 'Events', 'state' => 'live', 'description' => 'Immutable OpenMW and dialogue source events.'],
        'roleplay.responses' => ['title' => 'AI Responses', 'state' => 'live', 'description' => 'Persisted dialogue and delivery states.'],
        'roleplay.adventure' => ['title' => 'Adventure Log', 'state' => 'live', 'description' => 'Narratives, summaries, and playthrough records.'],
        'roleplay.memories' => ['title' => 'Memories', 'state' => 'live', 'description' => 'Recent, middle-term, and long-term memory records.'],
        'roleplay.diaries' => ['title' => 'LORKHAN Diaries', 'state' => 'live', 'description' => 'Versioned diary narratives.'],
        'roleplay.books' => ['title' => 'Books', 'state' => 'live', 'description' => 'Books observed during OpenMW sessions.'],
        'roleplay.journal' => ['title' => 'Journal', 'state' => 'live', 'description' => 'Morrowind Journal entries received through bounded typed OpenMW context.'],
        'roleplay.quest-manager' => ['title' => 'AI Quest Manager', 'state' => 'not-applicable', 'description' => 'Herika quest creation depends on Skyrim-specific outcomes that LORKHAN does not expose.', 'controls' => ['Create AI Quest', 'Manage Quest Actors']],
        'roleplay.background-life' => ['title' => 'Background Life', 'state' => 'excluded', 'description' => 'Background Life is intentionally excluded from the current LORKHAN scope.', 'controls' => ['Enable Background Life', 'Generate Rumors', 'View History']],
        'roleplay.destructive' => ['title' => 'Bulk Roleplay Deletion', 'state' => 'replaced', 'description' => 'LORKHAN preserves typed audit records and uses bounded retention and Database Manager backups instead of destructive browser bulk deletion.'],

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
    return '<span class="feature-state-badge feature-state-' . lorkhan_ui_h($state) . ($compact ? ' feature-state-compact' : '') . '">' . lorkhan_ui_h($labels[$state] ?? 'Planned') . '</span>';
}

/** Render a copied but intentionally inert control with its availability explained in-place. */
function lorkhan_ui_placeholder_control(string $label, string $featureId): string
{
    $feature = lorkhan_ui_feature($featureId);
    return '<button type="button" class="btn-base feature-placeholder-control" disabled aria-disabled="true" title="' . lorkhan_ui_h($feature['description']) . '">' . lorkhan_ui_h($label) . ' ' . lorkhan_ui_feature_badge($featureId, true) . '</button>';
}
