# All user-facing pages: HerikaServer UI parity

## Goal status: active, not complete

The user rejected the earlier shared-style pass as insufficient on 2026-09-05.
Availability, no-overflow checks and shared CSS do **not** establish page parity.
Every row below stays pending until its actual structure, controls and relevant
populated/empty/editor states have been compared with its current counterpart.

Reference: Dwemer-Dynamics/HerikaServer `unstable`
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`, with the local port-8081 server as
visual evidence. Herika is read-only. Lorkhan retains its gold `#bc9d5a`, branding,
OpenMW data ownership, authenticated forms, revisions and protected credentials.
Background Life, AI Quest Manager, Active Quests, Soulgaze Gallery, ITT and Server
Plugins remain excluded from this release.

## Implementation order and acceptance

1. Roleplay: Events, complete AI Responses, Adventure Log, Memories, Diaries,
   Books and Morrowind Journal. Replace generic readers with real counterpart
   layouts and correct data sources. Compare populated rows and entry dialogs.
2. Configuration: every hub tab, every Global Settings section, list/editor/modal
   states for profiles, NPCs, connectors, voices, pronunciations, actions and
   catalogs. Preserve unsaved edits, help, keyboard focus and supported options.
3. Control: every operational page, filter, detail view and supported tool.
   Product-only diagnostics use the shared visual hierarchy without fake CHIM data.
4. Home, Quickstart and secondary direct routes. Review desktop and narrow widths,
   existing form checks and live deployment hashes. No game launch or provider calls.

For each page: compare headings, toolbar order, widths, gutters, typography,
table/card choice, column proportions, scrolling, empty states, dialogs and actions.
Record a real product exception only when the data or supported operation differs;
do not use an exception to excuse a generic substitute layout.

## Current structural corrections

- AI Responses now reads `public.log` with scoped turn metadata, not sentence-level
  `speech` rows. It shows whole responses, selected Oghma topics, input/request data,
  recorded turn duration and allowlisted frozen messages in a role-based dialog.
- Books uses Herika's full-content, striped table with Tamrielic/UTC/timestamp columns and a content dialog. AI and book exports read the selected
  scope server-side; no credentials or provider configuration are exported.
- Adventure Log now reads recorded game events rather than generated narratives.
- Diaries and Adventure have UTC and recorded Morrowind calendars, date navigation and scoped downloads;
  Diaries also has person selection and entry dialogs with existing revisioned forms.
- Memories replaces record cards with a scope/people/time/summary table and keeps
  revision details, edit/delete and summary controls.
- The semantic Roleplay `nav` inherited a legacy 20px font. Its base now matches
  Herika's 15px hub, fixing the oversized tab labels across the shared navigation.

### Explicit remaining Roleplay work

- Memories now has the counterpart status strip, settings link, summary actions,
  game dates and compact edit/delete controls. Its advanced Lorkhan tools stay collapsed.
- The completed response-log maintenance preserves active responses, prompt traces,
  source events and conversation history. Diary bulk deletion uses soft deletion.
  Both use confirmed, scoped, CSRF-protected POST, never destructive GET.
- Tamrielic dates come from recorded OpenMW calendar globals or scoped diary source
  turns. Entries with no recorded calendar keep an explicit unknown date.
- Populated diary/book states were checked in isolated presentation fixtures;
  production records and speech providers were not used as test data.
- Global Settings includes Prompt Head / Emote Moods with saved global defaults,
  NPC overrides, portable export/import and a persistent named-preset toolbar.
  The Local LLM built-in and Herika's preset effects on NPC profiles remain pending,
  not accepted product exceptions. The Global Connector test dialog is implemented.

## Complete page matrix

| Lorkhan page | Herika counterpart | Current status |
| --- | --- | --- |
| `home.php` | `home.php` | Widgets, tables, word cloud, observed world/player statistics and drilldowns aligned; read-only worker indicator verified; populated/empty, desktop/narrow and whole-page review completed with OpenMW exceptions below |
| `quickstart.php` | `quickstart.php` | Header/980px shell, editable Player, speech sections, four-card model recap and protected OpenRouter/Deepgram quick keys implemented; Setup/Local LLM, MiniMe probe, service provisioning and Player2 still pending |
| `core/config_hub.php` | Same path | Shared geometry corrected; Oghma, Global Settings, Profiles, Player and Narration embedded entry views compared. Unsaved Player/Narration switches survive shared and ordinary tab changes in isolated rendered fixtures. Remaining children and full embedded interactions still pending |
| `global_settings.php` | Same path | Prompt/preset toolbar, grouped context selections, Oghma, connector cards/test dialog and blacklist browsers aligned; profile-affecting built-ins pending |
| `core/core_profiles.php` | Same path | In progress: response word limit, profile memory grouping, stacked settings/range controls, Copy to all and visible sticky toolbar; scrolling slot summary, linked assigned slots and toolbar spacing aligned. Presets and additional profile fields remain |
| `core/npc_master.php` | Same path | Mass Core Profile switch, model summary, tabs, Roleplay and General diary controls compared; movement-card layout matches but NPC-targeted Visit/Teleport/Return is unsupported; Relationships affinity table, scoped editing/build dialogs and recent changes implemented; Relationship Lock/Clear All, build direction and recorded outcome added; manual edits now stage with the NPC save; Details dialog and AI-visible role/memory fields added; AI-result staging, remaining General/Info and full editor/list review remain |
| `core/player_management.php` | Same path | Header/toolbar, biography checkbox, TTS status, card geometry and empty/populated stats compared; import reader and narrow controls checked. Player name editing, AI-generation guidance and per-player provider overrides remain pending |
| `narrator_management.php` | `core/narrator_management.php` | Toolbar, switches, dynamic field chips, connector summary and five shared event-prompt rows/editors aligned; core semantics and remaining inline/speech-style templates pending; current action catalog has no narrator-capable actions |
| `core/api_keys.php` | `core/api_badge.php` | Preset/card geometry, custom Add/Save/Delete editors, replacement autosave and Test reader compared; protected credential identities remain separate, editable custom labels and full provider-badge consolidation remain pending |
| `core/llm_connectors.php` | Same path | Common fields flattened, checkbox request switches and measured columns/icons aligned; native runtime/mock/alternative-token controls moved to secondary connection options with inheritance preserved. OpenRouter model/provider catalogues, filtering, selection, pricing/context, ordered provider preferences and configured-first API-key selection implemented and deployed. JSON Schema and Prefill JSON wired to operation-specific requests. Direct multi-file import, Clear advanced settings and Groq model selection now follow the reference; evidence below. YAML body editor, enable switch and request wiring are implemented; final full-editor/hub review remains. Remove Action Prompt is saved UI metadata only in the pinned reference, with no request-side consumer; not copied as an inert switch |
| `core/tts_connectors.php` | Same path | Populated Inworld/list layout compared; playable Test dialog, provider grouping/field identity, editable Name and collapsed raw options corrected; API Badge selection now matches configured/missing/none states and is wired through speech and account-scoped automatic cloning; provider-specific grids replace generic primary fields, with Inworld/PocketTTS/Cartesia visual comparisons and workspace routing wired; remaining provider controls listed in the latest audit below |
| `core/stt_connectors.php` | `stt_connectors.php` | Fixed-sample test/result reader, provider-specific fields, functional API Badge and independent service drafts compared; editable Name implemented; Google Free STT still pending |
| `core/voice_library.php` | `xtts_clone.php` | Provider cache/upload/batch structure aligned; Inworld batch states compared. Global Fallback/Pronunciation populated, empty, filtered and built-in edit states corrected and compared below. Provider-side clone management, OmniVoice language workflow, upload-to-provider automation, successful-batch refresh and remaining provider/hub comparisons remain |
| `core/npc_biographies.php` | `npc_upload.php` | Header, summary, Add/Edit, Extended Profiles, inline Oghma and full-catalog search/paging aligned. Batch guidance, complete global/installation custom export and confirmed factory reset are implemented; ownership and native fallback protections are explicit. Final tests/deployment evidence below. |
| `function_editor.php` | Same path | Summary, filters, editable rows, Behavior controls, scoped saves and both readers aligned; populated/empty and narrow states checked. Negotiated OpenMW parameters remain read-only; see Action Editor evidence below. |
| `prompts_manager.php` | Same path | Full header/CSV/search/table/reader comparison completed; Default/Custom editing, safe Clear, CSV round trip and plain instruction creation implemented; desktop/narrow, populated/empty, search and keyboard controls checked; retained document tools and validation limits documented below |
| `worldknowledge_upload.php` | `oghma_upload.php` | Regular Oghma header/search-logic panels, category filters, table, badges and Add/Edit dialogs compared and corrected. Topic/alias substring search and optional Basic Description/Category match the reference form contract, including CSV. Delete All, Factory Reset and factory-entry Delete are implemented with explicit confirmation and persistent deletion choices; final evidence below. Dynamic Oghma UI and runtime verified and deployed; evidence below. |
| `description_manager.php` | `description_upload.php` | Compact header, paired panels, five-column table, alphabet/search controls and Add/Edit dialogs aligned; populated/empty, full-text editing, keyboard and narrow states compared. Name and Description are now optional in forms/save/CSV, with stable OpenMW plugin/record identity retained. Latest migration/tests/deployment evidence below. |
| `oghma_knowledge.php` | `npc_upload.php` Oghma knowledge reader | Replaced article cards with the reference Topic/Knowledge Level/Description table, metadata chips and filter controls. Populated/empty and narrow states compared; permitted-description search, paging, scope rejection and hidden-text exclusion tested. Existing standalone navigation and access diagnostics retained. |
| `events-memories.php` | Same path | Events note, striped table, record heading, pagination/filter layout and recorded calendar dates corrected; populated live view and AJAX pagination verified |
| Roleplay `memory` tab | Herika Memories | Summary-only table, status/settings strip, scoped sync/delete, Tamrielic dates and compact editor implemented; 67 populated live summaries, empty fixture, Cancel/focus and narrow advanced tools checked |
| Roleplay `responselog` tab | Herika AI Responses | Whole-turn log, prompt dialog, topics, scoped export and protected clean-log workflow implemented; populated live table, prompt dialog and controls checked |
| Roleplay `diaries` tab | Herika CHIM Diaries | UTC/Tamrielic calendars, person mode, export and bulk delete retained. Full-content rows now use separate Play/Edit/Delete actions; dedicated content editor and paper reader replace combined Read/Edit. Desktop/narrow populated/empty and interaction comparisons, existing tests and local deployment passed. |
| Roleplay `books` tab | Herika Books | Full-content striped table, game/UTC/TS columns and content dialog implemented; populated fixture and focus restoration checked |
| Roleplay `adventure` tab | Herika Adventure Log | Chronological context/people/game-time/UTC rows, location dividers, contiguous speaker bands and counterpart CSV formatting implemented. Desktop/narrow populated, empty and long fixtures compared; date-selection, selected/latest-day and full exports checked. Full checks and 744-file deployment passed; live populated calendar/table verified. Native dates and complete OpenMW cell names retained. |
| Roleplay `journal` tab | Morrowind-only Journal using Herika's record table | Full-content striped table, Journal ID, game/UTC/TS columns and content dialog implemented; three live records, reader and focus restoration verified |
| `control_panel.php` | Same path | Shared geometry corrected; embedded child state comparisons pending |
| `request_logs.php` | Same path | Nine-column LLM-attempt table, toolbar, page sizes and separate payload readers aligned; populated/empty, keyboard and narrow fixture states compared. Safe scoped Clear preserves accounting/history/pending work; URL and unretained raw provider payloads remain explicit data limitations. See Request Logs evidence below. |
| `response_queue.php` | Control Panel -> `index.php?table=responselog` | Actual queued-message projection and seven-column striped table aligned; populated/empty, narrow, confirmation, playback details, pagination, CSV and live hub embedding checked. Row removal preserves native delivery/history and protects pending work. |
| `cache_browser.php` | Same path, audio portion | Compact file-list panel, typography and inline players aligned; populated/empty, expired/unavailable, keyboard, narrow and live hub states checked. Private authenticated media replaces public paths; excluded Soulgaze image panel stays absent. |
| `relationship_logs.php` | Same path | Evaluation-first header/filter/table/context/cleanup compared populated and empty; request/proposal and committed per-target/type-change evidence implemented and checked. Historical missing data stays explicit. Final native-tool placement and live Control Panel tab-state review completed below; tools remain in a secondary collapsed disclosure |
| `oghma_audit.php` | Same path | Header, filters/pager, nine metadata pills and five trace sections compared populated/empty at 1280px and narrow 390px; native retrieval evidence retained in secondary details; see Oghma Audit checkpoint |
| `playthrough_manager.php` | Same path | Header, selected-scope overview, paired panels and bounded list compared populated/empty/narrow; owning-profile export/import and installation selection aligned with actual behavior. Still incomplete: full stored-snapshot creation/switch/delete, automatic rollback snapshots, timeline and access beyond the 100-row list. See Playthrough Manager checkpoint below. |
| `server_logs.php` | Control Panel -> Dwemer Debugger CHIM log panels | Three-column log panels, search/severity controls, expanded readers, refresh, visible-entry download and UTC/local display aligned; populated/empty/narrow fixtures and dense live standalone/hub views checked. Only actual Lorkhan service logs are read; cross-product dashboard/MCP controls are not imported. |
| `provider_usage.php` | `audit.php` (Cost Breakdown) | Date/week header/filter/pie layout aligned; desktop and narrow populated/empty/unknown-cost states compared. Whole-range request-type totals, scoped UTC boundaries and CSV coverage checked; token/provider details remain collapsed. See Cost Breakdown checkpoint below. |
| `provider_attempts.php` | `request_logs.php` operational presentation; no exact all-provider CHIM page | Explicit safe metadata columns, toolbar, status pills, scoped filters, full pagination and page CSV. Populated/empty source-rendered comparison and narrow keyboard scrolling checked. |
| `jobs.php` | `request_logs.php` operational presentation; no exact durable-job CHIM page | Same shared reader, native queued/running/success/dead-letter states and retry/schedule metadata. Populated/empty comparison checked; no invented worker actions or exposed payloads. |
| `game_debug.php` | Herika Request Logs operational components; no equivalent OpenMW command page | Generic widgets replaced with compact session controls, grouped commands and a shared status/UTC history table. Desktop/narrow populated, offline and outdated-client fixtures compared; command names/parameters preserved. Existing checks and local deployment passed; no live commands issued. |
| `database_manager.php` | Dwemer-Dashboard `database_manager.php`, embedded by Herika Control Panel | Header, tools, backup cards and migration table replaced and visually compared. Native configuration backup create/download/restore verified; full SQL backup/import, automatic backups, database access, maintenance/reset and version-reset controls remain missing, not accepted product exceptions. |
| `diagnostics.php` | Herika Request Logs operational layout + Dashboard summary tiles; native health data | Server-wide snapshot and paged audit with identifier-only scope. Populated/empty desktop and narrow source/reference style comparisons, HTTP redaction/UTC and deployed checks passed. Counts explicitly do not claim worker/provider connectivity. |
| `backup_health.php` | Herika Request Logs operational layout + Dashboard instruction panel; native backup data | Explicit backup columns, paging, safe CSV and explained retention scope. Populated/empty desktop/narrow comparison, native confirmation keyboard checks, HTTP and local deployment passed. Backup metadata does not claim file-integrity verification. |
| `narrative_manager.php` | Herika `diarylog.php` entry table/editor; native create/generate extension | Replaced inline forms/cards with a compact searchable paged table, content-first editor, create/generate dialogs and separate delete confirmation. Source/reference editor and table styling compared; CRUD and generation refusal covered by existing HTTP tests. No equivalent standalone manual narrative manager exists in Herika. |

Redirects `core/character_manager.php` and `core/global_settings.php` resolve to
their canonical pages. Media routes (`cache_audio.php`, `core/profile_portrait.php`),
bootstrap files and templates are not separate user pages. NPC knowledge needs
an existing profile/installation identity when testing its direct route.

## Evidence for the current work

- Read-only live database probe resolved 49 complete responses and 115 Adventure
  events in the selected playthrough. It caught a date-format error before deploy.
- Browser comparison inspected populated AI response table and prompt dialog;
  closing the dialog restored focus to View Prompt. Adventure calendar was inspected.
- Existing checks passed after the Memory table/export refinements: 319 server
  checks, 98 protocol files, management HTTP, integration vertical slice, migrations
  and durable jobs. The 165-relation inventory matches; only source-reader evidence
  changed, not the database schema.
- Existing management HTTP fixtures now cover calendar rendering, populated diary
  dialogs, date/person filtering and scoped CSV export. No new test harness.
- Local code deployment completed with private configuration, credentials and voice
  hashes unchanged. The goal remains active; this is a Roleplay checkpoint.
- An isolated populated diary fixture verified the paper/handwritten reader, escaped
  markup, red delete control, edit panel and narrow viewport. It did not add records
  to the production database or call a TTS provider.
- Populated Memories was compared against Herika; the summary table and status
  overview were corrected. Global Settings comparison still found header/placement
  differences, so its matrix row remains pending.
- No game was launched, controlled or used as a UI validation substitute.

## Earlier shared-style pass (not completion evidence)

- Hub navigation uses Herika's content-sized groups, compact typography, spacing
  and wrapping instead of wide equal-sized buttons. Narrow layouts keep readable
  controls. Configuration, Roleplay and Control share the correction.
- Shared resource styling no longer adds card borders/margins to dashboard
  widgets or an extra embedded NPC page offset.
- Compact header roles work on operational pages as well as configuration pages.
  The Action Editor's unnecessary nested title wrapper was removed.
- Dashboard statistics remain in two columns within narrow desktop widgets;
  labels wrap between words. Dates are readable and converted to the UTC label.
- Action summaries, filters, table headings and row actions follow Herika's
  geometry. Semantic filter fieldsets remain accessible without extra card borders.
  Save/disable controls use the shared success/danger styling.
- The NPC auto-lock checkbox stays beside its label.
- An unselected LLM editor no longer has two nested empty-state frames.
- Direct TTS Studio pages no longer reserve the fixed navbar offset twice.
- Monitoring readers use framed panels, bounded scrolling and sticky table heads.
  Operational headers use the common compact title/note layout.
- The live Roleplay event panel has the same inset as other Roleplay panels.
  Delete controls retain their danger styling instead of generic gray buttons.
- Shared stylesheets have file modification versions, so local redeployment
  invalidates previously cached CSS.

### Calendar, maintenance and Books follow-up

- `MorrowindCalendar` validates recorded zero-based months, fixed month lengths and
  optional time for Events, Adventure, Diaries and Books. The test rejects leap-day
  fabrication and verifies diary date filtering and provenance after editing.
- The 16 Last Seed 3E 427 fixture lands on Fredas. Its selected-date count now has
  dark text on the gold background. Calendar headers, mode controls and green/red
  actions were compared to the current Herika diary page.
- The book fixture verified full content, the five counterpart columns, escaped
  markup and content-dialog focus restoration. The diary paper reader remained
  contained at a 390px viewport; effective content width was 375px with its scrollbar.
- Bulk-maintenance HTTP tests cover missing CSRF, incorrect confirmation, invalid
  kind/scope, GET rejection, diary-only soft deletion and repeated requests. The
  integration test preserves immutable histories, outside-scope responses and an
  active response while clearing completed response rows.

- Local deployment verified all 673 runtime files against source with no mismatches,
  extras or old paths. Private config/credential/voice hashes remained unchanged;
  unauthenticated native session creation remained 401 and private files remained 403.
- Live Events pagination changed from 100 rows to the remaining 16 without losing
  the counterpart columns. Live Adventure date selection resolved 21 entries for
  17 Last Seed, 3E 427. AI cleanup controls and the Books empty state were inspected;
  no production cleanup action was executed.

### Global prompt defaults and Journal checkpoint

- Prompt Head and Emote Moods now use the counterpart form rows. Values persist
  through save/export/import, enter the effective-settings hash and frozen turn
  context, and fill absent NPC overrides. Blank defaults preserve current behavior.
- Matched 1280px screenshots confirmed the first two rows' columns and dimensions.
  At 390px the fields stack without horizontal page overflow. The preset toolbar
  and connector-test controls remain outstanding; this page is not complete.
- Journal now uses the shared full-content record table rather than cards. Three
  existing records and the content reader were inspected locally; closing restored
  focus. Its Morrowind-specific data does not restore the excluded quest manager.
- 321 server checks, 98 protocol files, management HTTP, integration, migrations
  and durable-job tests passed. The database inventory hash is unchanged.

### Memories controls and data parity

- The table now shows mid/long summaries, matching Herika's summary reader rather
  than mixing in raw recent memories. Generated text is displayed and edited first;
  original text and revisions remain inspectable. Individual ownership is explicit.
- Game dates resolve from direct events or the summary's scoped source-event
  provenance. All 67 summaries in the local playthrough have recorded dates.
  Unknown dates remain labelled instead of inferred from wall-clock time.
- The overview matches the compact title/actions/status arrangement. Configure
  Settings opens the selected installation; the embedding address omits credentials
  and query strings. Model-summary status is labelled accurately because disabling
  model summaries does not disable Lorkhan's deterministic memory retrieval.
- Sync queues missing eligible summaries in 100-record requests through the existing
  worker. A scope lock and existing job identities prevent duplicate provider work.
  Existing failed jobs remain in Jobs for review. No separate session/IP rate limiter
  was introduced; requests require browser authentication, CSRF and confirmation.
- Bulk deletion soft-deletes summary tiers only. Recent memories, immutable events,
  outside-scope records and revisions are preserved. No live sync/delete was run.
- Existing HTTP tests cover confirmation, CSRF, invalid scope, disabled policy,
  editing and deletion. Integration checks cover queued work, repeat requests,
  retained originals, recent memories and other playthroughs. 321 server checks,
  protocol verification, HTTP, integration, migrations and jobs passed.
- Browser checks compared the live summary page, a synthetic generated summary and
  an empty table. Cancel resets unsaved text and restores focus to Edit. The live
  header and expanded advanced tools fit at 390px (375px content with scrollbar).
- Deployment preserved config, credential and voice hashes and matched all 673
  runtime files. No game was launched or paid provider invoked for these checks.

### Global Settings named preset checkpoint

- Added the counterpart Settings Preset selector, Apply, Save as new and Overwrite
  controls. Revision history remains available below the editor without adding a
  permanent header row. Selection alone does not change settings.
- Custom presets persist per installation in migration 087, with unique names,
  a 64-preset limit and optimistic revisions for overwrite conflicts. Save/overwrite
  capture unsaved form controls without changing the active configuration.
- Confirmed Apply creates a settings revision. A typed allowlist excludes connector
  bindings, service addresses, hidden client controls and NPC/Core Profile overrides.
  Lists replace completely rather than retaining trailing blacklist entries.
- Default currently resets this global-only scope to Lorkhan defaults. Herika's
  profile-affecting Default/Local LLM presets are still pending; no placeholder Local
  LLM option or silent mass profile change was introduced.
- Existing tests cover unsaved capture, duplicate names, stale overwrite, confirmation,
  CSRF, built-in overwrite rejection, default application, list replacement and retained
  routing. 323 server checks, 98 protocol files, HTTP forms, integration, migrations
  and durable jobs passed. Inventory: 166 relations, hash
  `abe1e3ea8e0667336b77280dbc19baa610b4eb76472d145b922278ff012ec296`.
- Paired desktop screenshots confirmed toolbar geometry and the top-aligned preset
  dialog. At 390px, the toolbar and dialogs fit the 375px content width. Cancel restores
  focus; Apply initially focuses Cancel. No live preset was saved/applied, provider
  invoked or game launched. Remaining Global Settings tabs and connector tests are
  still part of the active goal.
- Local deployment matches all 676 runtime files, with no extra files or old paths.
  Configuration, credential and voice hashes were preserved; private files remain
  forbidden and unauthenticated native session creation returns 401.

### Global Settings section and control layout

- Replaced 33 full-width context rows with Herika's compact checkbox groups:
  Top-Level Sections, Character Subsections, Appearance / State Subsections,
  Nearby Actor Details and Nearby Item Details. All existing field names, values
  and submission behavior are retained. Required prompt rules remain mandatory;
  their explanation is available on hover/focus rather than as permanent copy.
- Context filters now sit under Context Selections / Context Options. The missing
  blacklist Browse dialogs remain pending; the grouped layout does not imply those
  controls or all Herika context features have been implemented.
- Global Connectors uses the counterpart two-column card grid, labels and On/Off
  controls for existing tasks. Summary and relationship toggles sit with their
  connectors; Oghma's connector and toggle sit in Oghma. No connector choice is
  cleared when a task is disabled. No unsupported slot was represented by a fake control.
- End Conversation Cooldown is in Misc. Lorkhan's existing global RPG/automatic
  dialogue defaults remain available in Memory & Others. Additional Oghma and memory
  tuning is collapsed under Advanced settings. URL fields use the same input styling;
  translation no longer adds non-counterpart Live badges beside every setting.
- Desktop screenshots compared actual Oghma, Memory, context groups and connector
  cards. Computed context heading, label and description typography matches the
  reference. At 390px the groups and connector cards fit the 375px content width.
- A live unsaved validation probe confirmed that an invalid collapsed Memory Query
  Timeout opens the correct tab and disclosure without opening or saving a preset.
  Connector-toggle probes preserved the selected LLM. Reload discarded all test edits;
  no live settings were saved and no provider request or game launch was performed.
- Existing HTTP tests verify all 33 fields occur exactly once, paired controls are
  unique and unchecked context options persist. 323 server checks, 98 protocol files,
  HTTP forms, integration, migrations and durable jobs passed. Schema hash is unchanged.

### Global connector test dialog

- Added the missing header action and Herika-style modal: summary counters, progress,
  grouped slot results, status badges and a scrollable body. The existing Core Profile
  test controller is reused, including its two-request concurrency cap and Stop queued
  tests behavior. Opening/reopening loads the saved plan, not provider output.
- Global plans expose only saved, enabled installation-owned LLM routes. Disabled
  slots stay visible as skipped; unavailable selections warn without being called.
  Shared connectors produce one job and propagate its result to all matching slots.
- A separate Run tests confirmation prevents opening the dialog from starting paid
  requests. POST rechecks current enabled assignments and rejects stale or unassigned
  connectors. No API keys, endpoints or provider replies are returned in the plan.
- Existing HTTP tests cover read-only plans, deduplication, missing CSRF, confirmation,
  unknown installations, unassigned connectors, disabled/stale plans and a mock test.
  323 server checks, 98 protocol files, HTTP forms, integration, migrations and durable
  jobs passed. No schema change was required.
- Compared the dialog with an isolated rendering of the pinned Herika modal markup
  and CSS, avoiding Herika's automatic live test calls. In the Lorkhan mock fixture,
  three enabled slots made exactly two requests: one pass populated two slots, one
  failure populated the third, and the disabled slot remained skipped. Opening made
  zero requests. Closing restored focus to the opener.
- The deployed plan shows one unique enabled connector and three skipped slots. At
  390px the body scrolls while Close and Run controls remain accessible, with no
  horizontal overflow. Core Profile Test still loads its original ten-slot plan.
  No live test was started, settings saved, game launched or paid provider called.
- Deployment matches all 676 runtime files, with no extras or old paths. Existing
  configuration, credential and voice contents were preserved; private files remain
  403 and unauthenticated native session creation remains 401.

### Global context filter browsers

- Added Herika-derived Select dialogs for locations, items, magic/effects and event
  types: search, hit badges, selected badges, empty feedback, Cancel and Save Selection.
  The 900px panel, spacing, list rows and footer use the pinned reference CSS with
  Lorkhan gold. Native dialog focus containment, Escape and opener focus restoration
  are retained. Narrow layouts keep the actions visible and the list scrollable.
- A read-only authenticated management endpoint projects candidate names from at
  most 5,000 installation-owned turns, groups/counts in PostgreSQL and returns at
  most 500 names. It accepts raw and bounded-array OpenMW contexts, including player,
  target and nearby actor equipment. No full prompts, provider data or context samples
  are sent to the browser. Event types use the supported typed catalog plus counts
  from the latest 5,000 scoped events, preserving the existing inclusion semantics.
- Manual newline entries, including commas, remain selectable even when absent from
  recent observations. Searching does not discard offscreen selections. Save Selection
  changes only the form draft; Cancel does not change it, and Save All remains the only
  persistence action. Failed loads disable selection saving rather than clearing data.
- Existing HTTP tests cover all four routes, unknown kinds and missing installations.
  The existing integration suite covers raw/wrapped context projection, item hit counts,
  exclusion of non-item objects and installation isolation. PHP lint, 323 server checks,
  98 protocol files, HTTP forms, integration, migrations and durable jobs passed;
  JavaScript syntax and whitespace checks passed. No schema change.
- Compared the live Herika location dialog and deployed Lorkhan location/item dialogs
  at 1280px. Live candidate counts were 5 locations, 49 items, 15 magic/effects and
  11 supported event types. Checked search, empty search, manual comma entries,
  offscreen preservation, deselection, Cancel, focus restoration and event checkbox
  synchronization. At 390px the 366px dialog had no horizontal overflow and both footer
  buttons remained visible. All temporary form edits were discarded by reloading;
  no live settings were saved or providers called.
- Deployed 677 matching runtime files with no extras or old paths. Configuration,
  credentials and voice contents were preserved; private files remain 403 and native
  unauthenticated session creation remains 401. The game was not launched or controlled.

### Core Profile editor: response limits and field layout

- The pinned Herika preset source (`lib/core/settings_presets.php`) shows that both
  built-ins and custom Global Settings presets include Core Profile settings. The
  existing Lorkhan named preset implementation is global-only. Completing that parity
  depends on the missing profile controls below; the Global Settings row remains open.
- Added the typed server-only `response.max_words` override (0..10,000) and the
  Max Words Limit field. It is included in effective settings/source tracing and Core
  Profile settings export/import, but not projected into the OpenMW v1 controls schema.
  Positive values add a combined-utterance word request to the compact Markdown output
  contract. Zero/absent values add no limit. This is a model instruction, not guaranteed
  hard truncation, and no speech buffering or stream splitting was changed.
- Fixed settings preset export/import dropping the existing Rechat Actions setting.
  Connector assignments, profile prompts, slots and NPC assignments remain excluded
  from this existing settings-only export format.
- Moved memory switches into Profile Core's Profiles & Memories group. Matched
  stacked field rows, equal-height setting cards and linked slider/number pairs for
  Rechat, context and diary controls. Matched reference field typography (12px help,
  14px numeric inputs) and corrected the sticky Save All toolbar's offset beneath the
  64px standalone navbar. Embedded editors retain a zero offset.
- Live Herika and Lorkhan populated editors were compared at 1280px. Both directions
  of range synchronization were checked (number 7 -> slider 7; slider click -> both 11),
  then the draft was restored to its original value 2. No live profile was saved;
  the existing dirty-form navigation guard correctly prevented an unconfirmed reload.
  At 390px all five range pairs fit their 283px control width with no page overflow.
- A fresh deployed embedded editor confirmed the reference's exact numeric/help/label
  typography, zero toolbar offset, no navbar, and unchanged saved values (rounds 2,
  word limit 0). Standalone toolbar offset was verified at 64px. Existing unit checks
  cover prompt inclusion/absence, strict bounds, source tracing and unchanged client
  projection. HTTP tests cover save, invalid-limit rejection, export/import and the
  preserved Rechat Actions flag. PHP lint, 328 server checks, 98 protocol files, HTTP
  forms, integration, migration/durable-job checks and JavaScript syntax passed.
  Schema remains 166 relations with the same inventory hash.

Remaining Core Profile requirements identified from the pinned source and live editor:

- Profile Preset toolbar, Default / Local LLM / Follower / Passive built-ins, custom
  save/overwrite and confirmed application, plus Global preset profile snapshots.
- Extend Copy to all to additional reference fields as their runtime mappings are added.
- Context History, Diary and Dynamic Profile event-count controls, including the
  reference zero/fallback behavior and ranges; do not relabel a turn count as an event
  count without changing the actual selection path.
- Profile-scoped dynamic evolution fields, short-term summary count, RPG comments,
  language options, Bored Event, Combat and Quest controls and their runtime mappings.
- Physical diary support and latest-entry context behavior need a real implementation
  before their reference controls can be presented as working.
- Full connector-role/import-export, assignment Rules, Test, default replacement/delete,
  list/create/clone and embedded-editor state comparisons remain open. The word-limit
  and layout checkpoint is not a whole-page completion claim.

### Core Profile Copy to all

- Added the reference's compact Copy to all buttons for the eight currently supported
  copyable settings: word limit, Rechat rounds/probability/actions, conversation history,
  diary history/cooldown/instruction. Rechat Allow Actions now sits beside the Rechat
  numeric controls, matching the reference setting placement.
- The authenticated, CSRF-protected management endpoint accepts only one typed,
  allowlisted setting and an explicit confirmation. It locks current installation-owned
  profiles in stable order, rejects stale source revisions, preserves all other content
  and metadata, and writes immutable revisions only where the value changed. More than
  1,000 profiles is an explicit error, never a silently partial copy.
- Confirmation uses a native HTML dialog with Cancel initially focused. The browser
  submits only that field, not the entire editor draft. Success updates the source
  revision for subsequent copies without discarding other unsaved inputs. Closing
  restores focus; errors keep the dialog and draft available. No request starts on open.
- Existing HTTP tests cover eight rendered actions, CSRF, confirmation, unsupported
  settings, strict value types, missing/stale sources, success and repeat no-ops.
  Existing database integration tests verify unchanged unrelated content/metadata,
  installation isolation, revision accounting, false checkbox values and diary text.
  PHP lint, 328 server checks, 98 protocol files, HTTP forms, integration, migrations,
  durable jobs, JavaScript syntax and whitespace checks passed.
- An isolated fixture using the actual dialog and script verified Cancel made zero
  requests, successful copying sent only the exact five request properties, a stale
  revision showed the correct error, and an unrelated draft was preserved. The deployed
  editor was checked only through open/Cancel for word-limit and diary actions; no live
  profile was copied or saved. Buttons measure 9px font/18px height, as in the pinned
  reference CSS. At 390px the 354px confirmation fits with both actions visible.
- All 678 deployed runtime files match source, with no extras or old paths. Existing
  configuration, credentials and voice contents were preserved. Private files remain
  403 and unauthenticated native session creation remains 401. No game was launched,
  controlled or used for validation. Remaining profile and whole-page requirements
  above are still open.

### LLM connector editor structure and sampling controls

- Compared the pinned Herika source and its populated live DeepSeek editor with the
  same Lorkhan editor at 1280px. Moved Save/Test/Export and service selection into the
  connection column; moved supported response-format controls below the connection.
  Sampling now uses the reference's stacked label/slider/number rows and advanced
  section order: presence, frequency, repetition, Top p, Top k, Min p, Top a.
- Eight unnamed range companions reuse the existing numeric synchronization code.
  They do not submit duplicate fields or replace an inherited blank with zero.
  Explicit zero, false and the two mutually exclusive token-limit parameters retain
  their existing typed semantics. This pass does not change provider requests.
- Field help is available on hover/focus and dismissible with Escape. Service logos
  now occupy the icon buttons instead of being reduced by shared button padding;
  selected direct services have a gold outline and an accessible pressed state.
  Configured and mock modes are named honestly rather than guessing their service.
  Disabled Delete/Test/Export controls no longer inherit a 190px placeholder width.
- Deployed inputs match the reference 14px/21px font and 43px height. All seven service
  icons share one desktop row. The 390px layout retains readable stacked columns,
  wrapping icons and paired sampling controls. Long help stays within the editor.
- An isolated copy of the actual create page, CSS and scripts blocked every POST.
  Browser checks covered service selection, numeric zero, range keyboard updates,
  clearing to inherit, switching to mock and back without losing drafts, disabled
  inactive controls, token-limit conflict/recovery and Escape/focus behavior.
  Live connector settings were never saved, and no paid provider test was run.
- The existing HTTP suite now checks eight unnamed sliders plus blank and explicit
  zero rendering alongside its actual mock-provider request tests. PHP lint, 328
  server checks, 98 protocol files, HTTP forms, integration, migrations and durable
  jobs passed; the 166-relation schema inventory remains unchanged.
- Remaining LLM work is explicit: model browsing/information, provider preference,
  editable connector names, additional request-body controls and import/editor
  interaction parity. Reference JSON schema/prefill/action-prompt controls still need
  their runtime mapping assessed. None is replaced with a nonfunctional placeholder
  or declared a product exception by this checkpoint.
- Local deployment preserves configuration, credentials and voice contents. Runtime
  hash and access checks cover all 678 shipped files. No game was launched or controlled.

### TTS connector test reader and provider controls

- Compared the pinned `ui/core/tts_connectors.php` and `ui/tests/tts-test.php` with
  the live Herika Inworld editor and its open test window. No reference synthesis
  was requested. The Lorkhan list/editor already shared the main card/column layout;
  the meaningful missing operation was a playable test, not another theme overlay.
- Test now opens a native dialog with the reference reader structure: connector and
  provider header, Text To Synthesize, VoiceId, Run Test, Status/player and Request
  Preview. Its 1100px/820px maximum dimensions, card spacing, type hierarchy and
  narrow scrolling follow the reference. Lorkhan gold branding is retained.
- `ui/core/tmpl/tts_connector_test.php`, `ui/js/tts-connector-test.js` and the test-reader
  additions to `ui/css/herika-tts.css` derive their presentation from those pinned
  Herika files. The existing protected `/api/v1/tts-previews` implementation supplies
  audio; no additional provider path, schema, queue or persisted test record was added.
- Opening the dialog reads only the selected connector's saved voice catalog. It
  defaults to the narrator voice when offered, then the connector default/first valid
  voice. Run Test is explicit and warns about provider charges. The existing 240-character
  limit, installation/connector/voice checks, CSRF, rate limit and opaque provider errors
  remain in force. Request Preview shows only the submitted test text and voice.
- Closing stops the audio, releases its object URL and aborts the browser request.
  A late response cannot populate a closed or reopened dialog. This is browser playback
  cancellation, not a claim that a remote provider stops an already received request.
  Failures and autoplay blocking leave useful status and controls for retry/playback.
- The service picker uses Herika's Recommended/Others groups. Provider option IDs now
  include the owning driver: all 46 are unique. Inactive providers no longer start with
  another provider's saved values. Switching updates the settings heading and preserves
  each provider's unsaved option values. Raw JSON remains available in a collapsed
  Advanced connector options section. Disabled toolbar buttons no longer carry badges.
- Isolated actual-template/script fixtures verified a valid synthetic WAV (readyState 4,
  2-second duration), safe request preview, failure recovery, close cleanup/focus,
  pending-request close/reopen with no late audio, and no-voice disabled controls.
  The 390px reader remains within the viewport. An isolated populated editor checked
  unique IDs, the active label's focus target, provider groups, independent draft values
  and the advanced JSON expander. All fixture writes to real management routes were blocked.
- Live Inworld open/close confirmed three offered voices and focus restoration without
  Run Test or Save. Existing HTTP tests verify opening never synthesizes, the shared
  preview route/catalog is rendered, and option IDs are unique; the same suite exercises
  actual mock-provider preview bytes, invalid voices/text, CSRF and rate limits.
- PHP lint, 328 server checks, 98 protocol files, management HTTP, integration, migrations,
  durable jobs, JavaScript syntax and whitespace checks passed. The schema inventory
  remains 166 relations. All 680 deployed files match source, with private-file 403 and
  unauthenticated-session 401 protections intact. Configuration, credentials and voice
  contents were preserved. No game or live paid speech service was used.
- Remaining TTS parity: functional API Badge selection, editable connector names,
  provider-specific field placement/types/coverage (including Inworld workspace),
  URL visibility and complete import/create/clone/delete state comparison. Free-form
  test voice IDs and longer test text are not implemented by this catalog-backed
  preview; this checkpoint does not declare the whole page complete.

### STT fixed-sample test reader

- Compared pinned Herika `ui/stt_connectors.php` and `ui/tests/stt-test.php`.
  The new `ui/core/tmpl/stt_connector_test.php`, `ui/js/stt-connector-test.js`
  and reader styles in `ui/css/herika-excluded-connectors.css` derive their
  dialog, expected sample/play control, transcript and similarity presentation
  from those files. A source-derived reference fixture was rendered without
  executing Herika's automatic provider call; it is not live provider evidence.
- Test follows the reference save-before-test flow. The button warns that it
  saves the connector and that cloud transcription may incur charges. Save
  failure prevents transcription. Closing aborts the browser request, stops
  sample playback and restores focus; it cannot roll back a completed save or
  guarantee cancellation of work already received by a provider.
- `/api/v1/stt-connector-tests` accepts only installation and connector IDs,
  enforces CSRF, connector kind/ownership and the shared speech-preview budget,
  and returns bounded transcript, similarity, service and elapsed time. Provider
  failures are opaque. It uses an original offline-synthesized English WAV,
  checksum-verified by `SttTestSample`; no game sample, microphone recording,
  generated TTS, source event, dialogue job or audio record is involved.
- This also fixes Local Whisper credential resolution: its declared empty API-key
  setting means no credential, rather than an invalid environment-variable name.
  Existing HTTP tests exercise real multipart sample bytes against the mock STT
  provider and prove no TTS requests occur, plus invalid scope/kind, extra fields,
  missing CSRF and provider-failure responses.
- Actual-template/script browser fixtures checked save-before-test ordering,
  successful output with literal HTML text, save/provider errors, late-response
  fencing, Close focus restoration and the 390px layout. Live Test is deliberately
  not clicked because it saves settings and invokes the selected provider.
- Raw connector JSON is collapsed behind Advanced connector options; provider
  option labels now have explicit input IDs. The rest of the STT page is still
  pending: API Badge selection, Google Free STT, provider-specific fields and
  preservation of unsaved drafts when switching services. This is a partial
  page checkpoint, not completion of the full matrix.
- Verification for this checkpoint: PHP lint, 328 server checks, 98 protocol
  files, management HTTP, integration, migrations, durable jobs, JavaScript
  syntax and whitespace checks passed. The schema remains 166 relations.
  All 685 deployed runtime files hash-match source; private paths return 403
  and unauthenticated native sessions 401. Existing configuration, credentials
  and voice contents were preserved. Live GET confirms the new dialog, script,
  sample URL and protected endpoint without clicking Test or Save. No game ran.

### STT provider editor and API Badge

- Replaced the uniform Lang/Model/Timeout form with each provider's pinned Herika
  `conf/conf_schema.json` field order and types: Whisper Lang/Translate,
  Azure Lang/Profanity, Local Whisper URL/Formfield, Deepgram and Gemini Lang/model
  selectors, Parakeet Lang, and Inworld Model Id/Language. A saved custom model
  remains an option instead of being silently replaced by a suggested model.
- `ui/core/tmpl/stt_connector_fields.php` and `ui/js/stt-connector-editor.js`
  derive the field groups, labels, provider titles, badge list and switch behavior
  from the pinned STT editor/schema. Non-reference transport options remain in
  the collapsed Advanced section so existing custom endpoints, model overrides,
  timeouts and Gemini tone behavior are preserved, not silently reset.
- Switching cards or the Service selector changes the form locally, without
  navigation. Each service retains its own unsaved values and badge selection.
  Only the active group's controls submit, and all 8 groups have unique label
  targets. The active service card has a keyboard focus outline/current state.
  True/False selects now persist explicit false through the typed form parser.
- API Badge is functional: configured keys appear first, then missing keys, with
  -- None -- and a status notice. STT records optionally store only an allowlisted
  server-held credential reference. ProviderFactory resolves that selection;
  old records without it retain their provider default. Keys are never serialized
  into the editor, revisions or portable exports. Nested secret fields still fail
  validation. Export and import force the reference to None so a portable endpoint
  cannot acquire a destination server's key without explicit selection.
- Browser fixtures exercised every provider group, independent URL/language/model/
  Translate/badge drafts, configured/missing badge notices, saved custom models,
  the Disabled empty state, and keyboard access at 390px. Desktop structure was
  compared with the live read-only Herika Parakeet editor and the pinned schema.
  Fixture writes were blocked; neither server's live settings were saved.
- Existing HTTP tests cover all 8 rendered groups, label uniqueness, active-only
  form fields, true/false persistence, rejected credential references, mock-provider
  Authorization selection/None, portable import/export credential removal, and
  nested-secret rejection. Test credentials are isolated fixtures, never live keys.
- Remaining STT work is explicitly narrowed by evidence, not waived: editable Name
  and Google Free STT are still missing. This row does not yet claim full parity.
- Final checks passed: PHP lint, 328 server checks, 98 protocol files, management
  HTTP including import/secret-rejection probes, integration, migrations/durable
  jobs, JavaScript syntax and whitespace checks. The 166-relation schema is unchanged.
  Local deployment hash-verifies all 687 runtime files, preserves private config,
  credentials and voice contents, and retains private-file 403/native-session 401.
  Live GET shows the new Deepgram model selector and configured/missing badge list
  without saving, testing, recording speech, or launching the game.

### Editable connector names

- LLM, TTS and STT Name fields now match the reference's editable text inputs,
  keeping their existing placement, labels and form associations. No new naming
  configuration or duplicate connector record is introduced.
- The existing Save routes atomically update the display name and create the
  typed content revision. Connector IDs, profile/player bindings and server-held
  credentials are not replaced. Public projection triggers propagate the new label
  to the pickers/list views. Old CLI/form clients that omit Name retain the old
  revision-only behavior.
- Empty, oversized and invalid UTF-8 names are rejected. A database name collision
  returns a specific validation error and rolls back the whole edit, including
  any simultaneous model/options change. Settings validation still happens before
  mutation; the rename path retains the same secret/reference rules.
- Google Free STT remains pending on the STT row. The pinned reference opens
  `ui/addons/pmstt/index.html` and sends recognized speech through `PlayerSays.php`
  as an ImpersonatePlayer command. A microphone/transcript-only mockup would not
  be parity. Lorkhan's current typed debug command catalog has no corresponding
  player-dialogue operation; implement a real session-bound input path before
  enabling that control, without invoking it against a live game during this goal.
- Verification: existing HTTP tests renamed all three connector kinds, confirmed
  unchanged IDs/content and updated editor labels, rejected missing CSRF/invalid
  names, rolled back a colliding STT name plus model change, and confirmed the
  assigned LLM connector still cannot be deleted. PHP lint, 328 server checks,
  98 protocol files, HTTP, integration, migrations/durable jobs and whitespace
  checks passed; schema inventory remains 166 relations.
- Deployed browser checks confirmed Name is editable and associated with each
  correct form (`llm-revise-form`, `tts-revise-form`, `stt-form`). Typed unsaved
  drafts were discarded by reloading, restoring all original live names. Save,
  Test, speech recording and game commands were not invoked. All 687 runtime
  files hash-match source, private-file 403/native-session 401 checks remain
  intact, and configuration, credentials and voice contents were preserved.

### Home dialogue, diary, relationship changes and word cloud

- Recent Dialogue now uses the latest five whole `chat`/`inputtext` event-log
  records, with UTC and recorded Tamrielic time, rather than twelve speech
  delivery rows and a Delivery column. Hidden log records remain hidden.
- Latest Diary selects only non-deleted `diary` narratives, with the author,
  existing paper artwork, handwritten font and centered Play Audio control.
  It reuses the existing authenticated sentence-by-sentence reader and Narrator
  defaults. Stop releases audio and cancels pending requests. No generation occurs
  on page load; unavailable voices disable the control with setup guidance.
- Recent Relationship Changes shows five audit changes, signed affinity badges,
  type changes, reasons, owner/target and compact UTC timestamps. Its header links
  to Lorkhan's existing full relationship audit. The separate audit page still
  needs its own parity work; this is not a replacement for that work.
- Removed the chip substitute. The cloud uses locally vendored D3 7.9.0 and
  d3-cloud 1.2.7 with their full licenses, the reference's 10,000-chat window,
  top-100 vocabulary, filtering, logarithmic font sizing, horizontal layout,
  five gold shades and hover count. Keyboard focus also exposes the count.
  Resizing reruns the layout from immutable data. Positions are randomized,
  as in the reference; narrow clouds may fit fewer words.
- These four datasets use the selected current session's installation and
  playthrough. No source records or live settings are changed. The client
  version strip now reports observed versions instead of hardcoded versions.
- Scoped CSS defeats the shared theme's rounded/gold table overrides so the
  dashboard uses the pinned reference's table headers, rows and spacing.
- Source comparison used pinned Herika `529364c`; browser comparison also
  inspected the live CHIM Home diary, relationship panel and cloud. The live
  reference has some newer shared-theme styling; it does not silently replace
  the pinned target. Synthetic populated fixtures were inspected at 1440px and
  390px. Mock audio completed, queued separate text segments, stopped immediately
  and released its source. Markup in diary prose remained literal text.
- PHP lint, 328 server checks, 98 protocol checks, management HTTP, integration,
  migrations/durable jobs and JavaScript syntax checks passed. The existing
  integration suite now checks the five-event limit, calendar, diary-only
  selection, stop/speaker/context-word filtering, suppression and scope.
- Home remains incomplete: align the current player/world/mode/model fields,
  real product statistics and their drilldowns, observed Morrowind statistics,
  and header destinations. Do not treat generic server/job counters as parity
  for player statistics. Excluded Background Life/Active Quests stay excluded.
- Local deployment hash-matches all 692 runtime files with no extras or old
  paths. Private files return 403 on all three checked listeners; the native
  session route remains 401 without authentication. Configuration, credentials
  and voice contents were preserved. Live Home shows five dialogue rows with
  recorded game dates, the correct empty diary state and a populated SVG cloud.
  No live TTS calls, settings writes or game operations were made.

### Home world information and product statistics

- Current Playthrough now shows the recorded player name, last-played UTC time,
  recorded game calendar, dialogue mode, selected LLM slot and Compact Chat state.
  Last context is selected within the current installation/playthrough, including
  earlier sessions in the same save. The UI explains that observations are not
  a live poll and NPC routing may resolve a fallback from the selected slot.
- Replaced installation/session/job counters with Total Events, Oghma Entries,
  Memory Summaries, Diary Entries, Entity Deaths, Items Found, Books Read and
  Player Messages, using the real records and existing hidden/deleted policy.
- Total Events opens the event-type counts; locations and detected mods open
  their full recorded lists. Native dialogs retain Escape, focus trapping and
  focus restoration. The LLM card cycles 24h / 72h / 1w / lifetime as in Herika.
  It counts scoped LLM attempts, including background jobs, success and failure;
  it excludes TTS/STT rather than mistaking speech for a language request.
- Knowledge, known locations and active content files are installation catalogs;
  event, diary, book, memory and LLM statistics use the current playthrough.
  OpenMW lists cell identities and content-file load order, not fabricated Skyrim
  FormIDs, ESL prefixes or light-plugin flags.
- Morrowind Stats uses the reference's category/list structure for recorded
  vitals, attributes and skills. Client source confirms these are the available
  observed stats; it does not supply Skyrim lifetime shout, dragon, crafting,
  property or crime counters. Missing observations remain an empty state, not
  zeros. Generic server queue statistics no longer masquerade as game statistics.
- The pinned header has a guide and AI/LLM Tier List. The latter uses the same
  destination; the guide opens the existing Lorkhan Quickstart, clearly labelled,
  rather than directing Morrowind users to CHIM's Skyrim wiki. No guessed external
  Lorkhan wiki URL is introduced. The newer live reference's additional
  Playthrough Management button is not part of the pinned target.
- Existing integration checks cover scoped/suppressed totals, diary selection,
  and synthetic succeeded/failed LLM attempts versus a TTS attempt in all four
  periods. PHP lint, 328 server checks, 98 protocol checks, management HTTP,
  integration and migrations/durable jobs passed. Schema relations/columns remain
  unchanged; the inventory hash changes because it also records runtime readers.
- Populated and empty browser fixtures verified the three dialogs, Escape/focus
  return, all four LLM periods including wraparound, 390px layout and matching
  interactive/noninteractive card typography. Only synthetic data was used.
- Still pending on Home: a truthful equivalent of the background-processor
  indicator and final whole-page audit. Herika's page also calls its process-start
  helper on an unparameterized GET; do not copy that side effect into a Lorkhan
  presentation-only page. Use a query parameter for future read-only Herika Home
  comparisons so its automatic startup guard is not invoked.
- Local deployment verified 692 matching runtime files, no extras/old paths,
  private-file 403 and native-session 401. Private configuration, credentials and
  voice file contents were preserved. Live Home correctly shows the recorded
  player, Standard dialogue mode, selected Fast slot, game date, populated
  product counters, three known locations, fifteen content files and observed
  Vitals/Attributes/Skills. No provider tests or game controls were invoked.

### Home background processor and final review

- Current Playthrough now reports the installed background supervisor's status.
  Page loads only observe fixed service/PID paths; they never start workers.
  SysV checks the exact supervisor argument so a stale/reused PID is not reported
  as running. Systemd observes fixed service/timer units with a bounded 300ms
  subprocess and reports running, waiting, failed, stopped or unavailable.
- Nine additions to the existing server suite cover missing/invalid/stale PID,
  actual supervisor identity and systemd service/timer/error states. All 337 server
  and 98 protocol checks, HTTP, integration and migrations passed. The live SysV
  result under Apache's user matches service status: Running. Systemd behavior
  was fixture-tested; the local runtime does not use systemd.
- Final live Home review inspected the complete desktop page at 1440px and the
  header/world table at 390px, including the new worker row. Earlier populated and
  empty fixtures cover the diary, relationships, cloud and interactive drilldowns.
  Remaining differences are the recorded OpenMW stat categories, content-file/cell
  identities, gold branding and local Quickstart guide destination described above.
- Deployed and hash-verified all 693 runtime files, with no extras or old paths.
  Private-file 403/native-session 401 checks passed; private configuration,
  credentials and voice files were preserved. No game or provider was invoked.

### Quickstart implementation boundary

- Pinned Herika uses Player, OpenRouter, Setup, MiniMe, individual speech-service
  cards, Player2 and a four-slot connector recap followed by Save and Continue.
  Lorkhan's current three connector selectors/cards are not that structure.
- Setup's Default/Local LLM choices depend on the same profile-affecting preset
  semantics still pending in Global Settings. Implement them once for both pages;
  do not present a radio card that only changes appearance. Preserve current
  routes, NPC overrides and revision-checked atomic saves.
- Reuse protected credentials rather than rendering saved API keys into HTML.
  Provider tests must remain explicit. Do not copy reference model prices or
  overwrite current model routes merely to reproduce static recap cards.

### Quickstart player and section structure checkpoint

- Replaced the generic Quickstart header, combined speech panel and model grid
  with the reference's 980px shell, Quickstart Menu card, Player section,
  individual TTS/STT Service sections, four model recap cards and Save and
  Continue placement. Installation/Core Profile selection is retained in a
  compact disclosure; existing non-default profiles and routes remain selectable.
- Player Name now edits the installation player persona through a bounded,
  revision-checked write. The name and identity display name change together;
  the profile ID, biography, voice, routing and immutable source events do not.
  Quickstart saves player/connector changes in one transaction; stale player or
  Core Profile revisions roll back the entire save. Old forms that omit player
  fields remain compatible. This is not a game-console character rename, and
  observed game speaker names still take precedence in dialogue prompts.
- Model recaps show the actual selected connector's model ID, including unsaved
  choices, rather than hardcoded reference model names or prices. The existing
  dirty-form guard is loaded. Changes were previewed then discarded; no live
  settings were saved and no provider or game operation was invoked.
- A local reference fixture executed the pinned page's actual header, Player and
  speech-section rendering with synthetic values, plus its real stylesheet
  cascade. It omitted runtime bootstrap, databases, credentials and scripts;
  it proves those sections' presentation, not complete Quickstart behavior.
  The comparison caught and fixed doubled navbar spacing, 16px versus 15px base
  text, control padding and heading/label sizing. White headings remain white;
  Lorkhan keeps gold accents. Desktop and 390px live rendering, stacked recap
  cards, unsaved indication/restoration and missing-installation state were checked.
- Existing HTTP tests cover empty player setup, player rename, invalid names and
  stale-player rollback of the Core Profile revision. Full PHP lint, 337 server
  checks, 98 protocol checks, HTTP, integration and migration/durable-job checks
  passed. The schema remains 166 relations with the same inventory hash.
- Quickstart is still incomplete: OpenRouter and Deepgram quick-key editing,
  shared Default/Local LLM profile effects and local-model setup, MiniMe status,
  service provisioning and Player2 routing still need implementation and review.
  Current saved-connector selects are not accepted as a substitute for service
  provisioning. The complete-page counterpart comparison remains pending.
- Final HTTP rerun and JavaScript syntax checks passed after the style/dirty-form
  corrections. Local deployment hash-matches 694 runtime files with no extras or
  old paths; private-file 403 and native-session 401 checks pass. Configuration,
  credentials and voice hashes were preserved. Live Quickstart was restored to
  its unchanged saved Player, GLM 4.7 Standard and Dialectic Inworld selections;
  no dirty draft remains. The isolated reference server was stopped.

### Quickstart OpenRouter and Deepgram keys

- Added the reference's OpenRouter section after Player and Deepgram key field
  inside STT Service, with Unhide and Create key controls. Leaving a key field
  saves its entered replacement; blank keeps the existing key. Stored values
  are never rendered: the page exposes configured/environment status only.
  Unhide reveals only the current entered draft. Successful saves clear it.
- The fixed, CSRF-protected management endpoint accepts only OpenRouter's
  Default LLM key or the provider-default Deepgram key. Unknown providers,
  additional properties, invalid types, blank/oversized/control-character values
  are rejected. Responses contain only status, never keys. Environment-owned
  keys are disabled in the page and rejected by the endpoint.
- The Deepgram field follows the selected STT driver. It is hidden for other
  services and disabled, with an explanation, when that connector selects a
  different API badge. Saving a quick key does not rewrite connector bindings.
  Keys are server-wide, like the existing API Keys page; this scope is explicit.
- Writes are serialized, use a ten-second timeout and preserve failed/newer
  drafts. Save and Continue flushes active key drafts before the existing profile
  save; failed writes keep the form dirty. The shared dirty-form handler now
  respects prevented submit events so this asynchronous prerequisite cannot
  discard unsaved state. Keys have no form names and are absent from profile POSTs.
- Existing HTTP checks cover authorization, strict input validation, both key
  writes and status-only HTML/JSON. Full PHP lint, 337 server checks, 98 protocol
  checks, HTTP, integration and migrations passed. JavaScript syntax passed.
- An isolated browser fixture used the actual Quickstart template/scripts and
  mock key/form endpoints. It verified Unhide, save-on-leave, Deepgram/Parakeet
  switching, custom-badge lockout, failed-save dirty state, recovery, serial
  writes (maximum one active), and exactly one final form submission without
  any key fields. The 390px key rows fit within the page. No live key or provider
  was used for these checks. Setup, local models, MiniMe, provisioning and
  Player2 remain open; this is not complete-page acceptance.
- Deployed and hash-verified all 694 runtime files. Private-file 403/native-session
  401 checks passed; configuration, credentials and voice hashes were unchanged.
  Live desktop review confirmed that the existing OpenRouter key is environment
  managed and locked, while the selected Deepgram connector correctly reports
  its provider-default key as not configured. No live key was entered or saved.

### API Keys cards and test reader

- Compared the actual pinned API badge preset/custom-card markup and styles in an
  isolated reference fixture. Restored the 30px section spacing, standalone Custom
  Keys heading, puzzle-icon cards, stacked Label/API Key fields, per-card Save/Delete
  and Add Custom Key. Desktop and 390px reviews caught and corrected a green Delete
  button and an extra section-header bar. Narrow cards keep usable input widths.
- Preset and existing custom replacements save on leaving their card; blank keeps
  the saved key. New custom cards require explicit Save. Writes are serialized,
  failed/newer drafts remain visible, and unsaved drafts have a navigation guard.
  Duplicate custom identifiers are rejected instead of overwriting an existing key.
- Test now opens the reference-sized 900px/70vh reader, keeps the entered draft and
  page position, shows loading/success/failure and restores focus on Close/Escape.
  It posts only the tested key's fields. The existing fixed authentication probes
  remain; the reference's paid chat-completion probe is not copied. Neither test
  result nor autosave returns stored keys, raw provider bodies or configuration.
- Environment-owned keys show a clear status and are protected by the POST path
  as well as disabled inputs. Strict input/CSRF checks and metadata-only responses
  support asynchronous card actions without rendering secrets into HTML or JSON.
- Full lint, 337 server checks, 98 protocol checks, HTTP, integration and migration
  checks passed; the final HTTP rerun and JavaScript syntax checks also passed.
  Isolated browser checks covered test success/failure, focus, custom save failure
  and recovery, and failed/retried replacement autosave. Native Delete confirmation
  blocked browser automation; its mutation/authorization path passed HTTP checks,
  but complete browser confirmation acceptance is not claimed.
- Live deployment preserved configuration, credential and voice content hashes.
  Its preset controls and empty Custom Keys state were reviewed without entering
  a key or invoking a provider. Custom-label renaming and consolidation of separate
  service key identities remain pending, so this is not whole-page acceptance.

### Action Editor rows, saves and readers

- Compared the pinned and live Herika Action Editor with Lorkhan's actual template,
  stylesheet and script in an isolated browser fixture. Matched summary typography,
  count pills, filter sizing, column proportions, description height, code hints,
  row buttons and the three Behavior controls. Installation/NPC policy selection
  remains in a collapsed Action scope section rather than an extra permanent toolbar.
- Advanced Options now follows Response, Behavior, collapsed Parameter Schema and
  Technical Details, then Save Advanced Options. Its 920px dialog and the 1120px
  active-actions reader match the reference geometry. Active actions use saved
  values, scope/name/description columns and inline code IDs, not unsaved drafts.
  Dialogs have a single content scrollbar and preserve Close/Escape focus.
- Enable/Disable saves only enabled state; row Save owns name and description;
  Save Advanced Options owns return message, cooldown and follow-up prompt. Other
  staged fields survive each save. Save all changes still applies all staged fields,
  including action scope, as its existing Lorkhan behavior. Failed requests retain
  edits; requests are bounded and controls are locked during the revision save.
- Reset Override restores inheritance. Fixed the management endpoint rejecting an
  empty final override map: the optional stored actions property is omitted when
  no overrides remain. Existing HTTP tests cover reset, readback and stale revision.
- Browser fixture checks covered independent toggle/basic/advanced saves, bulk
  follow-up saves, final override reset, a rejected save, empty search and Reset
  Filters. Populated list, both reader layouts and 390px cards/dialogs were reviewed;
  expanded schema/technical disclosures and Escape focus restoration were checked.
  All fixture mutations were in-memory, with no live policy/provider/game writes.
- Real product differences remain explicit: 16 shipped OpenMW actions rather than
  Skyrim/extensions; required confirmation cannot be disabled; client-negotiated
  parameters/result schema are read-only; cooldown uses the supported OpenMW field
  rather than a nonfunctional follow-up argument-name input. No unsupported action
  or editable wire contract was added for cosmetic parity.
- Full PHP lint, 337 server checks, 98 protocol checks, management HTTP, integration,
  migration/durable-job and schema checks passed. This is page UI evidence, not
  in-game execution validation or completion of the whole website matrix.
- Local deployment hash-matches all 694 runtime files, with no extras or old source
  paths. Private-file probes return 403 and unauthenticated native-session requests
  return 401. Existing configuration, credential and voice hashes are unchanged.
  The live Action Editor populated all 16 actions and its layout was reviewed;
  no live action policy was changed. Rollback: lorkhanserver-code.N2KsyJ.

### NPC Management: Mass Switch Profile

- Found and removed a functional mismatch: this control previously moved OpenMW
  actor bindings from one NPC identity to another. Herika instead assigns all NPCs
  on one Core Profile to another Core Profile. The revised path changes only
  `profiles.core_profile_id`, keeping identities, actor bindings, content/voice
  overrides and existing profile revisions intact.
- Source and target must be different, active Core Profiles in one installation.
  Locked NPCs are skipped by default; Include locked NPCs is explicit. Player,
  Narrator, template and deleted profiles are excluded. Locks and assignment writes
  are transactional; no provider or game commands are issued.
- Replaced the divergent full-width generic form with Herika's compact 560px
  dialog: From profile / To profile, Include locked NPCs, typed Switch confirmation,
  Cancel and Switch Profiles. It defaults from the current Core Profile filter,
  reports updated/skipped counts, bounds requests and retains failed inputs.
  Multiple installations get an additional scoped selector; a single installation
  does not get an extra visible field.
- Compared against isolated markup extracted from pinned Herika
  `529364c4c12b3a8bd4cc12a481f400ce19b3a344` with its stylesheets. The Lorkhan fixture
  renders the actual PHP form block and page CSS/JavaScript. Checked desktop and
  390px layouts, same-profile rejection, installation options, fewer-than-two
  profiles, and successful mocked submission/counts. No live NPC was switched.
- Existing regression suites now verify Core Profile switching instead of the
  incorrect identity-binding behavior, including locks, unchanged identity/content/
  revisions/bindings, invalid NPC targets and cross-installation rejection.
  Full lint, 337 server checks, 98 protocol checks, HTTP, integration and migration
  checks passed. The final HTTP run also exercised CSRF, confirmation, target types
  and the status-only JSON form response. NPC list/editor/tab comparisons remain
  open; this does not mark the entire NPC Management page accepted.
- Derived presentation source: HerikaServer `ui/core/npc_master.php` at the pinned
  revision above; destinations are `ui/tmpl/resource_page.php`,
  `ui/css/herika-npcs.css` and `ui/js/resource-page.js`, under the shared MIT notice.
- Deployed locally and reviewed the live one-Core-Profile state: both selectors show
  Default and Switch Profiles stays disabled with the missing-second-profile hint.
  No live assignments were changed. Configuration, credentials and voice content
  hashes were preserved. Code rollback: lorkhanserver-code.aATWLc.

### NPC editor: Roleplay fields and Profile LLMs

- Replaced the misleading profile-name/voice summary with the selected Core
  Profile's Standard, Fast, Powerful, Experimental and Diary connector labels.
  Selection updates immediately, including installation-default inheritance.
  Only display labels are rendered; provider configuration and keys are not.
  No formatter slot is shown because Lorkhan uses its markdown prompting route.
- Matched all eight Roleplay fields against extracted pinned Herika markup and
  styles: one column, field order, Core/Backstory/Skills labels, 134px long fields,
  96px Speech Style/Goals, padding, label size and help text. Occupation now supports
  multiline editing. Existing saved field names and values are unchanged.
- Emote Moods Override is under Info. The six supported tabs fill six columns;
  Background Life stays excluded. Tab/panel ARIA relationships now resolve, and
  existing keyboard navigation and Escape focus restoration remain functional.
- Used actual Lorkhan PHP/CSS/JavaScript with synthetic records and no write
  endpoint. Compared actual extracted Herika Roleplay fields/styles at matching
  widths. Checked desktop and 390px layouts, escaped connector names, profile/default
  summary switching, mood placement, keyboard arrows and Escape focus. This fixture
  stubs History; it does not establish History or full NPC editor acceptance.
- Full PHP lint, 337 server checks, 98 protocol checks, management HTTP, integration,
  migration/durable-job and schema checks passed. Final JavaScript syntax passed.
  General controls, relationship editing, Actions and full modal/list parity remain
  open. No live NPC, provider setting or game state was changed for these checks.
- Local deployment hash-matches all 694 runtime files with no extras or old paths.
  Private-file probes return 403 and unauthenticated native requests return 401.
  Existing configuration, credential and voice hashes are unchanged.
  Code rollback: lorkhanserver-code.UorSbu.
- Reviewed the deployed Fargoth editor without saving: the actual five-slot Core
  Profile model summary is populated and all eight Roleplay field dimensions match
  the isolated comparison. Closed the modal without invoking any provider or mutation.

### NPC editor: diary controls and movement-card distinction

- General now exposes Auto Diary and Auto Diary Wait with Herika's paired checkbox
  layout, label/help typography and 1.8x checkbox size. Each switch retains explicit
  true/false separately from Core Profile inheritance. Untouched fields do not
  become overrides during unrelated NPC saves. Changing Core Profile updates only
  inherited switches; Use Core Profile removes only that switch's override.
- These controls use existing `content.diary.automatic_enabled` and
  `automatic_wait_enabled` runtime fields. The existing resolver preserves the
  Core Profile's generation enable, Diary LLM, interval and other policy leaves.
  The help reflects Lorkhan's timer/sleep/wait behavior and generation prerequisites;
  no extra connector, runtime setting or provider request was introduced.
- Existing HTTP tests cover true/false, inheritance restoration, preserved interval
  and context flags, invalid values and no provider invocation. A resolver check
  confirms explicit false and independent leaves. Full lint, 338 server checks,
  98 protocol checks, management HTTP, integration, migrations and schema passed;
  the final HTTP rerun and JavaScript syntax also passed.
- Compared actual extracted Herika diary markup/CSS against the actual Lorkhan
  template in an isolated fixture. Checked desktop/390px, default/override profile
  changes, reset and focus restoration. No live NPC diary policy was changed.
- Corrected the Actions tab's category: Herika offers Visit and Teleport/Return,
  not AI policy settings. Replaced the unrelated Inherited behavior block with the
  matching two-card layout, with disabled controls and an explicit unsupported
  notice. Compared extracted reference cards/styles and desktop/390px rendering.
- Native capability gap remains open: existing `player.teleport` accepts coordinates;
  `target.teleport.to_player` affects the game's current target and cannot safely
  select this arbitrary profile or restore its saved position. No movement command
  was issued. These disabled cards are presentation progress, not working movement
  parity or an acceptance of the whole NPC editor.
- Create-form diary defaults also follow the selected installation. An isolated
  two-installation check confirmed inherited true/false values switch correctly and
  explicit NPC overrides remain unchanged. Full create-form Core Profile option and
  model-summary switching across installations still belongs to the remaining
  General editor review; it is not claimed complete here.
- Deployed and inspected Fargoth without saving: both diary switches show inherited
  disabled defaults, and Visit/Teleport are explicitly disabled. Final runtime sync
  matches all 694 source files, preserves configuration/credential/voice hashes,
  and passes private-path 403 and unauthenticated native 401 probes.
  Final code rollback: lorkhanserver-code.KKo39K.

### Prompts Manager: default/custom editor and safe Clear

- Removed the inline raw-document editor from each table row. The new 1200px reader
  follows Herika's Description & File Location, read-only Default Prompt, Custom
  Prompt, Cancel and Save Custom Prompt arrangement. Compared extracted reference
  markup and final computed styles: 92px custom field, fonts, padding, default
  reader, header/footer and dialog widths. Gold remains the accent; headings are white.
- Rows now use actual Default/Custom status, description, bounded active preview,
  matching badges and Edit/Clear controls. Search includes status and description
  and reports no matches. Closed native dialogs are explicitly hidden so Bootstrap
  cannot accidentally expose their contents or alter page width.
- Clear restores the stored server baseline; it no longer deletes the document.
  It opens the editor with an empty custom field for explicit confirmation by Save.
  Assigned profiles remain assigned. Saving changes only custom instruction/moods,
  preserves other document fields, ignores submitted baselines and checks revision.
  Asynchronous saves return JSON, retain failed drafts and report stale revisions.
- CSV import now writes the same custom/default fields as the editor. It can replace
  a previously saved override or clear it, instead of leaving stale custom text active.
  Create uses plain Prompt instructions, with optional extra JSON behind a disclosure.
  Lorkhan mood fields and revision/clone/export/delete tools remain collapsed; deletion
  is named separately and remains blocked for assigned prompts.
- Existing HTTP tests cover assigned-prompt Clear, immutable defaults, preserved mood
  fields, stale revision, JSON response, CSV replacement/clear, clone/import identity
  and creation. Full lint, 338 server checks, 98 protocol checks, HTTP, integration,
  migrations and schema passed. Final JSON/CSV HTTP rerun passed after fixing the
  route's HTML-versus-JSON response detection; JavaScript syntax passed.
- Actual-template browser fixtures covered Default/Custom and empty tables, search
  and reset, successful mocked saves, retained failed drafts, Clear cancellation and
  restoration, Escape focus and 390px readers. Full markup fixtures also checked the
  create panel and empty/narrow page. These contain synthetic records only.
- This is not whole-page acceptance: final header/CSV-transfer counterpart checks
  remain. Lorkhan's installation-scoped prompt documents, additional document fields
  and player mood templates are retained; unsupported Skyrim prompt keys are not
  represented as working Lorkhan runtime instructions. No live prompt was edited.
- Deployed locally and reviewed the live `roleplay_dialogue` Default row and reader,
  then cancelled without saving. All 695 runtime files hash-match source, no extras
  or old source paths remain, private-file probes return 403 and unauthenticated
  native requests return 401. Configuration, credential and voice hashes are
  unchanged. Code rollback: lorkhanserver-code.ZQr7fa.

### Prompts Manager: complete page layout follow-up

- Replaced separate transfer cards with Herika's shared three-column CSV panel and
  blue guidance inset. Search now has its own framed panel and a 540px field. At
  1280px the header, transfer and search heights (43.98, 161.28 and 120.69px),
  positions and widths match the extracted reference. Gold branding is retained.
- Import starts disabled until a file is selected; its accessible file control
  displays the selected name. Lorkhan's Create/JSON tools remain available inside
  a compact Prompt documents disclosure. Opening focuses the appropriate field;
  Escape/Close returns focus. Existing drafts are retained while switching panels.
- Empty installations show a separate No Prompts Found notice with an accurate
  creation instruction. Table framing, typography and centered actions follow the
  counterpart. Preview markup deliberately does not add Herika's template-indent
  whitespace to actual prompt text.
- Compared populated and empty actual-template fixtures at 1280px and 390px,
  including reader, no-match search/reset, Create/JSON controls and focus behavior.
  The responsive CSV panel follows the reference's 1100/768px breakpoints. These
  embedded fixtures use synthetic data; the native operating-system file picker
  was not automated. Real multipart CSV import/export is covered by the existing
  management HTTP integration tests, not claimed from a mocked browser submission.
- Full lint, 338 server checks, 98 protocol checks, HTTP, integration, migrations
  and schema passed again. JavaScript syntax and diff whitespace checks passed.
  Local deployment matches all 695 runtime files, without extra/old paths; private
  access and native authentication probes pass. Configuration, credential and voice
  hashes are unchanged. Reviewed the deployed row and cancelled its reader without
  saving. Code rollback: lorkhanserver-code.BEl7XC.

### Player Management: toolbar, biography and speech presentation

- Compared the pinned page together with its actual `player-narration.css`, not
  just its older inline styles. Embedded padding, compact header, top Save/Export/
  Import toolbar, card widths, 82px fields and 12px gutters now match. The stats
  grid uses the reference's equal columns. Gold replaces orange; card headings
  retain the reference's light text.
- Moved portable export/import to the top toolbar. Import uses a bounded native
  dialog with the existing protected JSON form, file/paste controls, Cancel/Escape
  and focus restoration. Removed the duplicate bottom Save row; revision/message
  metadata remains on the Save button. Draft tracking and backend forms are kept.
- Biography visibility uses the reference's inline checkbox with short help rather
  than a large switch card. Player Autochat and TTS includes the selected connector's
  Enabled/Disabled draft indicator and matching VoiceID, Respeech and Speech Style
  labels. Existing language and extra roleplay fields remain in disclosures.
- No Character Stats or Skills cards are fabricated when those context sections
  are absent. Populated fixtures retain recorded inventory/equipment, health,
  magicka, fatigue, encumbrance, Morrowind attributes and skills. Empty installation
  and first-profile creation states remain explicit and do not call the game.
- Actual-template browser checks covered populated and empty game-data states,
  first profile/no installation, desktop/390px layouts, import Cancel/Escape/focus,
  disabled generation without inputs, selected TTS status and unsaved indication.
  No provider generation, live settings import/save or operating-system file picker
  was exercised. Existing real HTTP tests cover player saves, biography visibility,
  portable import/export and protected field preservation. A stale copy-only smoke
  assertion now checks that the real create/revise form is present.
- Full PHP lint, 338 server checks, 98 protocol checks, management HTTP, integration,
  migrations and schema passed. JavaScript syntax and diff checks passed.
- Remaining parity is explicit: editable existing-player name, optional AI speech
  generation guidance, per-player ElevenLabs overrides, and an audit of unset/
  inherited connector selection versus effective routing. These are not accepted
  exceptions or represented by nonfunctional controls. Import continues to preserve
  identity, connector/voice routing, autochat, diaries and live game state.
- Deployed and reviewed the live Player page and import Cancel without saving.
  The existing Inworld connector remains selected and the form remains clean.
  All 695 runtime files match source; configuration, credential and voice hashes,
  protected-path probes and native authentication checks pass. Code rollback:
  lorkhanserver-code.YKnc8T. Live inventory still falls back to record IDs when
  names were not supplied, and compound skill labels need catalog formatting;
  these data-label gaps remain in the Player follow-up scope.

### Narrator Management: toolbar, switches and profile connector summary

- Compared the pinned reference narrator page including its player-narration.css
  overrides, rather than treating shared CSS as whole-page acceptance.
- Matched the embedded 8px/14px shell, top Save/Export/Import toolbar, switch/help
  spacing, textarea spacing and selected Dynamic Profile field-chip styling.
  Kept the reference's second Save button at the bottom. Dynamic Profile uses an
  accessible switch and its working choices no longer look disabled.
- Import opens a native dialog outside the profile save form. Cancel and Escape
  return focus to its opener without changing the unsaved profile selection.
  The existing scoped, CSRF-protected revisioned import/export handlers remain.
- Selected Profile Connectors now updates on Profile changes. Its six supported
  connector rows show scoped display labels only, with an em dash for unconfigured
  slots. Diary no longer falsely claims Use Standard. The speech row describes
  the selected Core Profile; explicit narrator overrides are explained separately.
- Browser proof: populated fixture with two different profiles, unset Diary/TTS,
  import Cancel/Escape, keyboard switches/chips, initial-create/no-installation
  states, desktop and 390px page/dialog. Synthetic fixtures do not call providers
  or modify the live database. Native file selection was not exercised.
- Existing validation passed: 338 server checks, 98 protocol manifest checks,
  PHP lint, management HTTP forms, integration slice, migrations and durable jobs,
  JavaScript syntax and diff checks. Schema inventory remains 166 relations with
  hash 95ef442e3fe13f3f7e1ce7d036a5c240226c7db9dc588bc2dc4d4655cf498819.
- Deployed locally with 695 runtime files matching source, no extra/old paths,
  health/auth/private-path checks passing and configuration/credentials/voice files
  preserved. Rollback: /var/backups/lorkhanserver-code.uquaWK. The live page is in
  create state (no narrator selected), so live import is unavailable; dialog proof
  is from fixtures and existing HTTP tests. No narrator was created for testing.
- Still pending: embedded Actions and Prompts editors, editable display identity,
  Oghma tags, narrator-only diary access/latest diary context, and exact book/context
  policy mapping. Existing book-event and profile-context switches are not equivalent
  to Herika's narrator-only book summary and hide-spoken-lines controls. This is an
  incremental correction, not completed Narrator or all-page parity.

### Narrator Management: shared inline event-prompt editors

- Replaced the Advanced Prompts shortcut with the reference's five-column inline
  table (key, description, status, preview, actions), default/custom badges,
  scrolling previews, Edit and Clear, and its 1200px/90vh reader. The shared dialog
  partial also serves Prompts Manager; Narrator retains the counterpart's plain
  labels, 300px monospace textarea and compact title/close layout.
- Added shared, revisioned overrides for the five event instructions actually
  supported by OpenMW: welcome, random narration, bored narration, journal comments
  and book narration. Four keys match Herika; narrator_book_prompt is the existing
  OpenMW book-event instruction. Defaults preserve previous runtime wording exactly.
  {PLAYER_NAME} resolves from the accepted turn, without template evaluation.
- Both pages use the same prompt records, with factory rows rendered without database
  writes. CSV import/export works through Prompts Manager. The first explicit save
  creates a prompt document; subsequent saves and Clear use revision checks and a
  transaction-scoped per-installation/key lock. CSRF and unknown-key checks remain.
- Event documents are tagged and excluded from normal Core/NPC prompt selectors and
  fallback selection. This prevents an alphabetically earlier event prompt from
  becoming an NPC's general roleplay prompt. The selected installation's overrides
  enter the existing immutable provider snapshot before queueing, never a worker-time
  lookup. Existing queued inputs remain unchanged.
- Inline saves update the row and editor revision without reloading Narrator Management.
  A browser fixture verified Save/Custom, Clear/Default, preserved unsaved Core Summary,
  Cancel/Escape focus, no nested forms and a contained scrolling dialog at 390px.
  The reference and Lorkhan desktop headers both measured 71px; screenshot comparisons
  included the populated table and actual reference reader, using synthetic text.
- Catalog/runtime audit found that ActionCatalogRepository marks every current action
  unavailable to the narrator, and narrator event requests suppress actions. The
  Narration Actions disclosure now shows the real zero-count/empty state. No dummy
  action rows, ineffective toggles or game capability changes were introduced.
- Remaining narrator scope: the four inline-narration prompt templates and player
  speech-style template are not yet editable here; display identity, Oghma tags,
  diary/context/book policy differences from the previous checkpoint remain. The
  empty action catalog does not establish functional narrator-action parity.
- Validation: 348 server checks, 98 protocol manifest files, PHP lint, management
  HTTP forms, integration slice, migration/durable-job checks, JavaScript syntax and
  diff checks passed. Existing tests now cover factory first-save, CSRF rejection,
  stale revisions, shared edits, Clear, CSV, runtime default/custom event instructions
  and protection of roleplay prompt selection. Schema is unchanged (166 relations,
  95ef442e3fe13f3f7e1ce7d036a5c240226c7db9dc588bc2dc4d4655cf498819).
- Deployed locally; all 698 runtime files match source, with no extras or legacy
  paths. Authentication, health and private-file probes pass. Configurations,
  credentials and voice files were preserved. Live inspection opened and cancelled
  the reader and checked both pages: five factory entries plus the existing roleplay
  document, while the database retained its original single prompt row. No live
  save, provider call or game action was used as a test.
- Previous-feature rollback: /var/backups/lorkhanserver-code.suR0qi. Final alphabetical
  prompt-list refresh rollback: /var/backups/lorkhanserver-code.HYTXD5.

### Request Logs — LLM attempt table and readers

- Replaced the generic prompt-trace table with the reference `ui/request_logs.php`
  hierarchy: Request to LLM Services Log, Previous/Next, Limit 100/200, Clear Log,
  record/page count, and ID / Time (UTC) / Connector-Model / Status / Tokens / URL /
  Request / Result / Error columns. Default page size is 50, matching the reference.
- Uses individual LLM attempts, including installation-scoped jobs, rather than a
  turn-level projection that can pick a TTS attempt. Model names use the explicit
  attempt column or the worker's recorded model metadata. Error codes are shown;
  raw error detail, provider configurations and arbitrary metadata are excluded.
- Separate native Request/Result dialogs retain Escape and focus restoration.
  Request text is the existing bounded role/message projection; section coverage
  remains expandable inside its reader. Result text is accepted dialogue from the
  latest successful complete-turn attempt, never a failed retry's result.
- Data limits are explicit: endpoint URLs and raw HTTP payloads are not retained;
  jobs without recorded messages show Not recorded. UUID attempt identifiers are
  retained. Missing token metrics are not invented. These are not claims of raw
  provider audit-payload parity.
- Clear Log is a CSRF-protected, confirmed POST scoped to one installation. The
  reversible 088 migration adds visibility markers, retaining provider accounting,
  source events and conversation history. Pending attempts, active turns and
  queued/leased jobs stay visible. All-installation clearing is disabled. Tests
  exercise rejection, scope, idempotency and preservation using isolated data.
- Synthetic source/reference screenshots compared populated and empty tables and
  the Request reader. At 1280x720, both table frames start at x33/y177.656 with
  width1214/height270 and both titles at x33/y41 with height36. Filters occupy the
  unused right side of the record-count row. At 390x844 the wide table scrolls
  inside its region; the dialog fits and restores focus. Escaped script-like text,
  Result opening, Clear cancellation and filter controls were checked.
- Validation passed: 348 server checks, 98 protocol files, PHP lint, management
  HTTP forms, integration slice, migration/durable-job checks, JavaScript syntax
  and diff checks. Regenerated inventory: 167 relations,
  30920ad27f5044216661c0cf9e98b3abf317d31ac13849244af1ef266d3b5f7a.
  The final model-metadata projection additionally passed PHP lint and live GET.
- Deployed locally: 703 runtime files match source; no extra files or legacy paths.
  Health, authentication and private-file probes pass. The live page shows 49 LLM
  attempts and 49 model names; 49 Request readers and 48 Result readers are
  available. Opened both reader types, checked 11 recorded coverage sections,
  navigated Limit 200, and cancelled Clear without executing it.
- Counts remain 540 provider attempts, 517 source events and 285 utterances;
  visibility markers remain zero. Configurations, credentials and voice files were
  preserved. No provider call, game launch or game command was used for validation.
- Previous-feature rollback: /var/backups/lorkhanserver-code.E7FNLF. Final model
  mapping refresh rollback: /var/backups/lorkhanserver-code.dTyQHj.
- The all-pages goal remains active. Response Queue's reference entry redirects to
  `ai-response.php`; its real counterpart must be inspected next, not inferred
  from the filename or treated as covered by this Request Logs work.

### Response Queue — actual Control Panel counterpart

- The preceding Request Logs checkpoint identified the old `response_queue.php`
  shortcut. Following the actual reference Control Panel iframe revealed the real
  counterpart: `index.php?table=responselog`, rendered by `print_array_as_table()`
  in `lib/misc_ui_functions.php`, not the separate AI Responses reader.
- Replaced the speech-history table with the scoped `public.responselog` projection:
  localts, sent, actor, text, action, tag, rowid, in ascending row order. Dialogue,
  action and lifecycle messages now appear. Raw internal payloads are not exposed.
- Matched the plain heading, full-width bordered/striped table, typography and row
  controls. The populated 1280x720 fixtures share heading x12/y8, table frame
  x12/y54.390625, width1256/height208.75, and row height47.1875. The reference
  fixture includes its real dark HTML theme; missing Bootstrap icon font is not
  treated as a product difference. Lorkhan uses its existing Unicode trash mark.
- Bounded paging, filters, CSV and actual playback details remain under Filters and
  tools; published/sent is explicitly distinguished from played. Timestamps and
  row IDs do not split across lines. Narrow tables scroll inside their region.
  Empty filters show a quiet no-records message instead of an unexplained blank.
- Confirmed row removal is a browser-session/CSRF-protected POST. It removes only
  the completed projection and companion metadata. Immutable/native response
  events and the typed queue remain intact; unsent records and active unfinished
  turns/dialogue/actions are protected. This deliberately does not cancel native
  game delivery. No live row deletion was performed.
- Existing checks passed: 348 server checks, 98 protocol files, management HTTP,
  integration, migration/durable jobs and schema inventory; final PHP/JavaScript
  syntax and diff checks passed. Added cases to existing tests for method/CSRF/type
  rejection, wrong installation, unsent protection and retained native history.
  Inventory remains 167 relations; source-reference hash is
  ce4b70b508eb1c7e63a095e1f16c1fc09832bfdcda359a19f36650739345e6f0.
- Compared populated/empty fixtures and 390x844 scrolling, pending disabled control,
  removal confirmation, Escape/focus restoration and playback details. Live GET
  shows 1,000 records / 10 pages; Next moves IDs1-100 to101-200. Standalone and
  Control Panel iframe expose the same seven columns. Live empty-filter and CSV
  probes pass (100 data rows with the seven expected headers).
- Deployed locally; all 706 runtime files match source, no extra/old paths, and
  health/auth/private-file checks pass. Counts remain 1,000 public queue entries,
  1,000 typed queue entries, 1,000 native response events and 517 source events.
  Configurations, credentials and voices were preserved. No provider/game calls.
- Previous-feature rollback: /var/backups/lorkhanserver-code.BcYzCo. Final timestamp
  wrapping refresh rollback: /var/backups/lorkhanserver-code.3BLvlB.
- The all-pages goal remains active. Audio Cache and the remaining matrix entries
  still need their own counterpart and populated-state work.

### Audio Cache — compact file list and protected playback

- Replaced the five-column diagnostics table and outer card with the reference
  cache heading, file-list panel, filename / format-size / date / player grid.
  At 1280x720, source and reference both use a 35px title line, 41px panel heading,
  51px first row, 50px last row and 220x32px dark inline players. Fonts and row
  spacing match; source uses the full width because Soulgaze is excluded.
- The title remains Audio Cache. No image panel, gallery iframe, private filesystem
  path or public-folder shortcut is introduced. Lorkhan media is deliberately
  stored outside the web root and served through its existing authenticated,
  installation-scoped, expiry/integrity-checked endpoint. Standalone navigation
  retains its existing body offset without adding a second 80px blank gap.
- Filters are secondary, with available clips/all time/100 rows as defaults.
  Paging replaces Herika's hard 300-file scan ceiling. Expired/deleted records
  remain inspectable without playable elements. Filenames use opaque media IDs;
  speaker, duration and expiry are available in accessible filename descriptions.
  Menu dialogue now resolves its recorded actor instead of falling through to
  Unknown because it has no regular dialogue-utterance foreign key.
- Compared actual source/reference templates using synthetic populated/empty
  fixtures and local silent WAV data. Checked 390x844 rows and expanded filters
  (document width equals scroll width), keyboard Enter/focus on the disclosure,
  user-started playback, and immediate handoff: first player paused at 0.012765s
  while the second was playing. A missing-file fixture displays the accessible
  unavailable/expired status. No generated speech or external provider was used.
- Existing checks pass: 348 server checks, 98 protocol files, management HTTP,
  integration, migrations/durable jobs, PHP/JS syntax and diff checks. Existing
  HTTP tests now cover empty/default/all-state rendering and authenticated missing
  audio, method rejection and unauthenticated denial. The audio-serving endpoint
  itself was unchanged; these new HTTP cases do not claim positive range-playback
  coverage. Schema inventory remains 167 relations, source-reference hash
  a6174bb20347333db434d2118c90590df99ae66a6a4297335762fcfd6a37d59c.
- Deployed standalone and Control Panel cache views were inspected. Runtime has
  385 expired clips, including 129 menu-dialogue clips, and no playable current
  clips; those counts were unchanged. Live history renders 25 rows / 16 pages
  with recorded speakers, while the default correctly shows the empty state.
  All 708 runtime files match source; no extras/old paths, private-file/health/auth
  checks pass, and config, credentials and voices were preserved.
- Previous-feature rollback: /var/backups/lorkhanserver-code.1YqZs0. Final standalone
  spacing refresh rollback: /var/backups/lorkhanserver-code.6a52hM.
- The all-pages goal remains active; this completes only the Audio Cache row.

### Server Logs — actual embedded debugger counterpart

- Traced Herika Control Panel's Server Logs iframe to
  `/Dwemer-Dashboard/distro_debugger.php?embed=1&tab=chim`, not the older
  `ui/api/chim_debugger_logs.php`. Reference dashboard source was clean at
  7c19d3ddb7fa7aaf9cbd71abc41a1cdb4b7f9758; neither reference repository was edited.
- Replaced three generic preformatted cards with the reference log-panel structure:
  source label, per-source search, All/None and counted severity checkboxes,
  newest-first timestamp/level/message rows, expanded searchable readers, refresh,
  visible-entry text download and persistent UTC/local browser-time selection.
  Error/warning are selected by default; unclassified/raw lines remain visible.
- The source/reference populated and empty 1280x720 fixtures share panel y78,
  x20/438.65625/857.328125, widths approximately402.66 and height620. Compared
  actual debugger panel/render functions and scoped CSS. Gold branding replaces
  dashboard accents; semantic severity colors are retained. Shared form rules
  no longer enlarge the debugger's 30px expand and 24px filter controls.
- Checked combined search/severity, All (three fixture entries), None (zero),
  expanded search including initially hidden INFO, Escape/focus restoration,
  UTC/local timestamps and 390x844 layouts (375px document and scroll width).
  Downloaded fixture `lorkhan_logs_2026-09-06T16-08-08-966Z.txt` was inspected:
  the worker section was empty after None; only visible Apache errors and raw
  request lines were present. No live log export was performed.
- Real logs retain the existing 256KiB/200-line bound. Partial first lines and
  invalid UTF-8 are handled, JSON worker output is readable, multiline errors
  stay together, and timestamps without timezone evidence are not falsely
  converted to UTC. Credential redaction now handles complete Bearer/Basic,
  JSON/quoted and URL credentials before rendering or downloading; the previous
  simple token pattern could leave a Bearer token after hiding only its prefix.
- Actual Lorkhan sources are worker, Apache/PHP errors and Apache requests.
  CHIM DLL/service log files do not exist here; recorded LLM prompts/results
  remain in Request Logs rather than inventing raw provider-log files. This page
  does not import Distro's cross-product tabs, MCP assistant, credential/config
  helpers or raw private filesystem paths. Native dialogs retain keyboard focus
  handling. No server log deletion, arbitrary path selector or provider call exists.
- Existing checks pass: 352 server checks, 98 protocol files, management HTTP,
  integration, migrations/durable jobs and syntax/diff checks. Four focused cases
  extend the existing server suite for redaction, row parsing and bounded tails.
  Schema inventory remains 167 relations with unchanged source-reference hash
  a6174bb20347333db434d2118c90590df99ae66a6a4297335762fcfd6a37d59c.
- Local standalone and Control Panel views render actual logs (200 worker,
  109 Apache/PHP entries and 200 request lines at the final dense-log check).
  Live review caught and fixed flex-shrinking headers and narrow unclassified
  worker rows: headers now remain 30px and unclassified summaries use full width.
  HTTP refresh was exercised; only ordinary access-log appends are expected.
- Previous-feature rollback: /var/backups/lorkhanserver-code.hE711f; dense-log
  fix rollback: /var/backups/lorkhanserver-code.HIG0gO. Final preference refresh
  rollback: /var/backups/lorkhanserver-code.nGLWtF. All 712 deployed files match
  source; no extra/old paths, health/auth/private-file checks pass, and configuration,
  credentials and voices were preserved. Live timezone selection survived Refresh
  Logs and was restored to UTC after testing. All-pages work remains active.
- Final redaction review also covers the existing `provider_key` credential spelling;
  all 352 server checks were rerun and deployed hashes rechecked after that addition.
  Final redaction-refresh rollback: /var/backups/lorkhanserver-code.BMeyBk.

### Oghma Audit — metadata and retrieval trace presentation

- Replaced custom summary metrics and collapsed technical cards with pinned
  HerikaServer 529364c's actual `ui/oghma_audit.php` structure: header, current-page
  search, Only Matched, 25/50/100 rows, pager, nine metadata pills, and five visible
  Input / Extracted Topics / Signals Used For Ranking / Ranking Notes / Context
  Snapshot sections. Gold branding is retained.
- Populated fixtures use the actual reference template/helpers and two synthetic
  matched/unmatched records. Desktop source/reference measurements agree: header
  y20/h113.890625, toolbar y151.890625/h44.21875, pager y210.109375/h36.5,
  first card y260.609375, metadata pill h58.796875, first trace y459.78125/h42.390625.
  Native details add 34.5px beneath the five sections. Empty cards were compared
  visually too. At 390px both stack metadata into one column; source document and
  scroll widths are 375px. Secondary More filters can wrap the pager to another
  line on narrow screens; its expanded form stays within the viewport.
- Exercised current-page matching (Caius only), no-result feedback and keyboard
  expansion of native decisions. More filters retains installation, server-wide
  search, matched/unmatched and extractor-status filtering without dominating
  the reference toolbar. Invalid installation/row-count inputs are normalized;
  existing HTTP coverage exercises these and reference `matched=1` links.
- Corrected the reader's native data mapping: PostgreSQL selected UUID arrays now
  decode as JSON arrays; the original turn text is Input, while the extracted
  retrieval query stays in details. Recorded target names and input kind come
  from the same turn. Historical reason/topic names survive removal of a current
  knowledge article. Scores are actual recorded relevance; elapsed time was never
  recorded, so the page explicitly says so. No timings or CHIM event types are
  invented. Full grounded matches, rejections, tags and decisions remain available.
- Reused the existing server, management HTTP and Oghma integration suites; no new
  test file or harness. The integration case checks both selected IDs and original
  recorded input. This is presentation-only: no database migration, provider call,
  retrieval-policy change, game launch or game command.
- Final checks: 354 server checks, 98 protocol files, management HTTP, full
  integration, migrations/durable jobs, PHP/JS syntax and diff checks passed.
  Schema inventory remains 167 relations, hash
  a6174bb20347333db434d2118c90590df99ae66a6a4297335762fcfd6a37d59c.
- Deployed locally with rollback `/var/backups/lorkhanserver-code.2PgXRI`;
  all 716 runtime files match source, no extra/old paths, health/auth/private-file
  probes pass. Configuration, credential and voice hashes were preserved.
- Verified actual 49 historical traces in standalone embedded view and Control
  Panel. Per-page 25 renders 25 records, Next renders the remaining 24, and Only
  Matched resets to page 1 while retaining page size. Current-page Llandras search
  shows six cards. Oghma trace count remains 49 before/after this read-only review.
  Relationship Logs and the remaining all-pages matrix are still pending.

### Relationship LLM Logs — evaluation-first presentation

- Replaced the editor-first Relationship Audit page with the actual pinned
  HerikaServer 529364c `ui/relationship_logs.php` presentation: Total Evaluations,
  Last Hour, Type filter, Refresh, age/all cleanup, top/bottom pagination, and
  Time / Type / NPC / Changes & Context table. Manual records, Build with AI,
  editing, deletion and detailed change history remain under a secondary
  Manage relationships & change history disclosure, including their existing
  revision, CSRF, scope and private Custom Info behavior.
- Reads actual `evaluate_relationship` / `build_relationships` provider attempts;
  manual edits are never counted as LLM evaluations. Automatic NPC-to-NPC type
  comes from the recorded interlocutor kind. Applied evaluation deltas/reasons
  and history-build counts come from their committed receipts, not guesses from
  current mutable relationship scores. Pending, failed and cancelled attempts
  are distinguished from missing retained results.
- Source context reads only retained, unsuppressed played conversations, bounded
  to 100 source IDs and 64KiB per attempt, with one batched source query per row.
  It does not select provider configuration or Custom Info. Context is explicitly
  labelled as source conversation rather than an exact unrecorded model prompt.
- Remaining recording work is NOT an accepted parity exception: retain bounded,
  safe per-attempt request/proposal and committed per-target build/type-change
  evidence for future calls; expose these using the same context/change layout.
  Historical build receipts only store aggregate counts. Do not fabricate missing
  historical changes or equate a provider proposal with an applied result.
- Actual source/reference templates were compared using synthetic evaluation,
  NPC-to-NPC and build rows, plus empty states. At 1280px, both headers are
  y10/h65.25, cleanup bars y221.40625/h66, tables y351.90625, first evaluation
  rows y391.09375/h92.640625, and inline context links h16.3125. Source's aggregate
  build row differs because its individual historical results are unavailable.
  Gold replaces the orange primary accent; semantic type/delta colors remain.
- Exercised context with Enter, cleanup confirmation without submitting, Escape
  and focus restoration to Delete Old. At 390px controls wrap and the table
  scrolls within a labelled keyboard-focusable region (355px viewport / 630px
  table); document/scroll widths are both375px. The reference instead overflows
  the document and clips cleanup controls, which is not copied.
- Log cleanup extends the existing request-log visibility mechanism with an
  allowlisted relationship-operation and age scope. Only completed attempts
  with terminal parent jobs/turns are hidden, consistently in Relationship Logs
  and Request Logs. Saved relationships, relationship audit, source history and
  provider accounting remain untouched. Confirmation and CSRF are required.
- Existing checks: 354 server checks, 98 protocol files, management HTTP forms,
  full integration, migrations/durable jobs, PHP/JS syntax and diff checks pass.
  Added focused cases in the existing suites for evaluation/build projections,
  private-note exclusion, cleanup CSRF/age validation, and old/recent/pending/
  unrelated-operation/other-installation cleanup boundaries. No new test file.
  Schema inventory remains167 relations; source-reference hash is now
  2ae8a2f8027d65103de8e1fd2ca0c1cdaeaa989b8e9045cd5b5b225828864fb4.
- Deployed locally; rollback `/var/backups/lorkhanserver-code.GOUnv6`. All721
  runtime files match source, no extras/old paths, and health/auth/private-file
  probes pass. Configuration, credentials and voices were preserved.
- The actual local installation has zero evaluation/build attempts, relationship
  records, relationship audit entries and hidden request logs before/after review.
  Verified the live Control Panel empty layout, NPC-to-NPC filter GET navigation,
  Refresh preserving that filter, and keyboard opening of the retained tools.
  No live log cleanup, AI build, provider call or game interaction was performed.
  Populated evidence is from synthetic fixtures and isolated worker integration,
  not claimed as a live populated test. Keyboard ArrowRight scrolls the narrow
  table by40px without scrolling the page.
- Keep the matrix row partial until recording gaps and final placement of the
  existing relationship management tools versus Herika's NPC editor are resolved.

### Relationship LLM Logs — recorded request and committed changes

- New evaluation/build attempts retain only bounded system/user message text and
  strictly validated proposals. The real provider observer receives messages,
  never HTTP headers, credentials or connector options. Mock input is labelled
  separately. Existing historical attempts are not assigned invented prompts.
- Applied per-target affinity/disposition deltas and accepted type transitions
  are recorded within the existing fenced save transaction. Rejected type
  proposals, cancelled work and zero-change builds remain distinguishable.
- The context reader shows recorded input and an expandable request/proposal
  reader. Proposals are explicitly not proof of applied changes. If any source
  exchange or utterance is hidden/removed, frozen request/proposal content is
  withheld so the reader cannot restore deleted conversation text.
- Actual reference/source templates were compared with synthetic evaluation,
  multi-target build, type-change, cancelled and no-change rows. Keyboard opens
  both context and nested evidence. At 390px the document remains375px wide;
  the expanded reader's client/scroll widths are both284px inside the existing
  horizontal table region. These populated fixtures are not live provider proof.
- Existing checks pass:355 server checks,98 protocol files, management HTTP,
  integration, migrations/durable jobs and PHP syntax. The existing integration
  suite verifies rejected versus accepted types, two committed build targets,
  cancellation without applied evidence and post-capture source suppression.
  The provider-observer test stops before networking. No new test file or schema
  migration. Inventory stays167 relations, hash
  2ae8a2f8027d65103de8e1fd2ca0c1cdaeaa989b8e9045cd5b5b225828864fb4.
- Relationship tool placement and the remaining page matrix are still pending.
- Deployed locally with rollback `/var/backups/lorkhanserver-code.f6xIij`.
  All721 runtime files match source, no extra/old paths, health/auth/private-file
  probes pass, and the restarted worker reports running. Configuration,
  credentials and voice contents were preserved. Relationship attempts, records,
  audit entries and hidden-log counts remain zero before/after deployment.
  No live provider or game call was used for this verification.

### NPC Relationships — affinity table and native editing

- Replaced the Relationships text-box/build-link placeholder with the pinned
  Herika editor's Target / Affinity / Tier / Type / Signals table, inline score
  and type controls, best/worst signals, details, Add, Build with AI and custom
  type dialogs. Legacy biography relationship text is secondary. Added the NPC's
  Recent Relationship Changes list with recorded UTC dates and accepted deltas;
  deletion is labelled without inventing a score decrease.
- Rows/history are filtered by installation, NPC profile and selected playthrough
  in SQL before the existing100-row bound. Actor names are display labels, not
  identity keys. Add chooses another bound OpenMW actor; existing targets retain
  their record identity. Affinity tiers and built-in type ordering/icons match
  the reference. Custom labels remain supported by existing validation.
- Existing CSRF-protected save/delete/build handlers are reused. Row saves retain
  expected_revision; successful saves and conflicts return to the same NPC tab.
  Relationship rows save separately from profile revisions, with explicit copy.
  Both row controls and NPC profile edits now participate in the unsaved-edit
  guard, including controls linked to a form outside their DOM parent.
- Actual source/reference components were rendered with synthetic populated and
  empty records. Their affinity fields are60px wide and populated row heights
  are72.25px. Enter opens details and dialogs; custom labels become selectable,
  affinity85 shows Devoted, and edits mark the owning form dirty. Escape/Cancel
  closes native dialogs and restores their trigger. Private-note text and its
  associated row form were checked in the isolated fixture.
- At390px the source document/client/scroll widths are375px. The table scrolls
  inside its own375px region; the build dialog is337.5px wide and remains inside
  the viewport. Fixed an absolutely positioned hidden table heading escaping
  its scroll container. No live relationship write or provider request was used
  for browser checks; populated screenshots are synthetic.
- This tab remains PARTIAL: move Relationship Lock into its counterpart position,
  add protected Clear All, complete details/direction field parity, resolve
  staged versus separate-save behavior and the100-record reader bound, and then
  remove superseded management tools from Relationship LLM Logs. These are not
  declared OpenMW exceptions. The rest of the all-pages matrix remains active.
- Verification:355 server checks,98 protocol files, management HTTP, full
  integration, migrations/durable jobs and syntax checks passed. The final
  isolated HTTP run also saved through the new NPC row form, returned to the
  editor, and verified the persisted audit entry. Its outer shell wrapper had
  an exit-argument quoting error after the suite's explicit pass marker; the
  test log independently confirms completion. No new test file was added.
- Deployed locally with rollback `/var/backups/lorkhanserver-code.B5SRm1`.
  All722 runtime files match source, with no extras/old paths; health, private
  file and unauthorized-session checks pass. Worker running. Configuration,
  credential and voice hashes preserved. Live Fargoth opens on Relationships
  using rel_profile/rel_playthrough; Escape closes Build without closing the
  NPC editor and restores the trigger. No form was submitted on the live server.
  Relationship attempts/records/audit/hidden counts remain zero before and after.

### NPC Relationships — Lock and Clear All

- Relationship Lock now sits above the affinity editor, following the reference
  label and styling. It saves the native relationship.locked NPC override with
  the profile; Use Core Profile removes only that override. The shared inherited
  checkbox controller also keeps diary switches working and marks reset/toggle
  changes as unsaved, including externally associated form controls.
- Clear All uses a native confirmation dialog naming the NPC, playthrough and
  full active-record count. It requires the exact word Clear and existing CSRF
  authentication. The snapshot covers all active records, not just 100 table
  rows. Clearing locks the confirmed rows, checks their IDs/revisions, and uses
  the existing soft-delete/audit path in one transaction. A stale snapshot clears
  nothing; new actors arriving after the snapshot are not silently deleted.
  Private notes and change history remain retained on deleted records.
- Existing integration coverage passes with more than 100 records, a stale
  snapshot, another NPC's records and retained private notes. Management HTTP
  coverage passes for clear/save, CSRF rejection without mutation, required
  confirmation, stale confirmation, and lock true/false/inherit/invalid values.
  The existing unauthenticated-form path redirects to Home, so that exact path
  and the unchanged snapshot are checked instead of assuming a 403 response.
- 355 server checks, 98 protocol files, management HTTP, full integration,
  migrations/durable jobs, PHP/JS syntax and diff checks passed. Schema inventory
  remains 167 relations with hash
  2ae8a2f8027d65103de8e1fd2ca0c1cdaeaa989b8e9045cd5b5b225828864fb4.
- Browser fixtures confirm inherited true -> explicit false -> inherited true,
  dirty tracking, empty-state Clear All disabled, keyboard dialog opening and
  cancel/Escape focus restoration. At 390px the document/client/scroll widths
  are 375px; the confirmation reader client/scroll widths are both 336px.
  A blank confirmation is invalid; Clear is valid. No destructive action was
  submitted through the browser and no provider/game request was made.
- Remaining: staged versus separate saves, full details/direction fields,
  recent build status and full history/paging. Keep the old log-page management
  tools until those capabilities have replacements; their removal is not yet
  complete. The all-pages goal remains active.

- Final deployment: rollback /var/backups/lorkhanserver-code.hYvg64; all 722
  runtime files match source with no extras or old paths. Health, private-file
  protection and unauthorized-session checks pass. Configuration, credentials
  and voice file hashes are preserved. The live Fargoth Relationships tab opens
  with inherited Lock off and Clear All disabled for its empty state. Desktop
  review caught and fixed an inherited column layout on the lock label; the
  deployed checkbox now sits beside its text. At 390px the deployed document
  client/scroll widths are both 390px. No live form was submitted. Populated
  mutation coverage is isolated; this is not in-game validation or full parity.

### NPC Relationships — Build direction and outcome

- Compared the Build with AI dialog against the pinned Herika component. Added
  Direction (optional), matched its 96px rendered textarea, compact description,
  merge warning and right-aligned Cancel/Build controls. Kept the native bounded
  recent-conversation selector and gold branding. Cancel/Escape clears unsent
  direction and restores the trigger; opening the dialog never starts a job.
- Direction reaches the durable build payload and the recorded provider input as
  user_direction. It is optional, trimmed, UTF-8 text bounded to 2,000 characters;
  the full model-input byte limit still applies. Retrying an existing request with
  different direction is rejected. Older jobs without direction still use empty
  guidance. The model contract distinguishes player guidance from witnessed
  events and retains the supplied actor/type constraints and private-note guard.
- The NPC tab now shows the latest scoped build outcome from the existing job and
  receipt reader. A queued/leased request is not called applied; only a committed
  receipt shows the updated-record count. A completed job without a receipt is
  shown as stopped without saving. Reload for status keeps NPC/playthrough and
  list filters without putting direction text in the URL. The older log-page
  builder has the same direction input until its remaining tools are relocated.
- Existing tests: 362 server checks, 98 protocol files, management HTTP, full
  integration, migrations/durable jobs and syntax checks passed. The mock build
  worker verifies exact direction delivery, retry conflicts, atomic saves,
  cancellation outcome and retained private Custom Info. The provider request
  observer checks the real message construction before any network request.
- Actual component fixtures cover populated completed status and empty state.
  Browser review compared source/reference dialogs, tested direction entry,
  Cancel/Escape and focus restoration. At 390px the source document/client/scroll
  widths are 375px; the dialog is 337.5px wide and its client/scroll widths are
  both 336px. Populated build status is synthetic, not a live provider test.
- Remaining NPC parity includes the complete relationship details dialog,
  profile-level staged saves, all-record/history access, General/Info and the
  full editor/list review. The all-pages matrix and goal remain active.
- Deployed locally with rollback /var/backups/lorkhanserver-code.bQcUBk.
  All 722 runtime files match source; no extras or old paths remain. Health,
  private-file protection and unauthorized-session checks pass. Configuration,
  credentials and voice hashes were preserved. Live Fargoth displays the new
  direction dialog and Cancel closes it without saving. There are no live build
  outcomes to display: relationship records/audit/provider-attempt counts remain
  0/0/0 before and after deployment and read-only browser inspection.

### NPC Relationships — staged manual edits and atomic NPC saves

- The enhanced NPC editor no longer exposes per-row Save buttons. Affinity/type
  changes, current detail fields, additions, removals and confirmed Clear All
  remain local drafts until the NPC header Save is clicked, following Herika's
  manual-edit workflow. New drafts reuse the saved-row template, including tier
  feedback, details and custom types. Clear All includes previously undisplayed
  records only through the existing confirmed scope snapshot.
- Only changed rows are submitted. The batch uses authoritative NPC installation
  scope, an expected profile revision and each edited/deleted row's revision.
  The NPC content, Core Profile assignment and all relationship writes share one
  transaction. Stale rows/profiles, invalid later operations and foreign-owner
  deletions roll back the whole save. A staged clear still requires the exact
  Clear confirmation and snapshot token; audit history and saved private notes
  remain retained. There is no schema change or new runtime asset.
- Submission disables controls while pending. A failed/unconfirmed response keeps
  the draft rows and dirty state visible; a confirmed save returns to the same
  NPC and Relationships tab. Ordinary NPC saves without relationship changes,
  legacy row endpoints and log-page tools remain compatible.
- Existing HTTP coverage exercises valid add/update/delete/clear batches, stale
  profile and relationship revisions, a late invalid addition rolling back the
  NPC revision, and a foreign-owner delete rolling back. The existing page parser
  gained an optional external-form reader so these tests submit the actual
  labelled controls, including textarea values, instead of only hidden metadata.
- Browser component fixtures verify staged affinity/type feedback, removals,
  adding from empty state, new-row details/private notes, Clear All counts and
  empty-state controls. A synthetic unconfirmed save response leaves a new row's
  affinity at 42, editable, with the draft marked unsaved and no navigation.
  At 390px the document/client/scroll widths are 375px; the 704px table scrolls
  inside its own 375px region. These are synthetic states, not live-game writes.
- Remaining: full relationship detail fields/dialog, all-record/history access,
  and staging AI build proposals before NPC Save. The existing AI history build
  still applies its fenced result directly; it is not declared a parity exception.
  General/Info, full NPC-list review and the rest of the all-pages matrix remain.- Verification: 362 server checks, 98 protocol files, browser-like management HTTP
  forms and integration/migration checks passed. Schema remains 167 relations
  (hash 2ae8a2f8027d65103de8e1fd2ca0c1cdaeaa989b8e9045cd5b5b225828864fb4).
  PHP lint, JavaScript syntax and diff checks passed. Local deployment rollback is
  /var/backups/lorkhanserver-code.Bk109k. All 722 runtime files match, with no
  extra files or old paths; health, private-file protection and management access
  checks passed. Configuration, credentials and voice hashes were preserved.
  The deployed Fargoth Relationships tab shows the NPC-save guidance, empty state
  and disabled Clear All. No live form was submitted; relationship records,
  audit rows and relationship provider attempts remain 0/0/0.

### NPC Relationships — full Details dialog and persisted role/memories

- Replaced the inline edit panel in the enhanced editor with Herika's Details
  dialog: Relationship Detail, suggestions, Recent Interaction, Best Memory,
  Worst Memory and Custom Info, in that order. OpenMW disposition remains in
  a collapsed product-specific control. Typography was compared against the
  pinned reference: 500px shell, 22px heading, 12.75px bold labels, 13.44px input
  text, 40px inputs and a 96px textarea. Gold branding remains unchanged.
- Dialog typing edits a temporary copy. Cancel/Escape discard it and restore
  focus. Dialog Save updates the local row, signals and NPC dirty state. NPC Save
  commits the existing atomic/revisioned batch; no individual row request occurs.
  Explicitly clearing a memory persists an empty string rather than resurrecting
  the previous audit reason. New rows use the same dialog and save path.
- Migration 089 adds a bounded JSON object to the internal relationship record,
  separate from Custom Info. Fields use the reference relation/note/best/worst
  names; each accepts up to 1024 UTF-8 characters. Existing score-only writers
  preserve them. Runtime relationships, the dialogue prompt and relationship
  build/evaluation inputs include these details; Custom Info remains excluded.
  Older exports preserve existing details, explicit exports/restores retain them,
  and conflicting restores cannot replace local edits. Downgrade refuses to
  discard nonempty details, including soft-deleted records.
- Until details have been edited, the dialog seeds recent/best/worst from existing
  scoped audit signals. These become editable context on save, while original
  audit rows remain immutable. Automatic build/evaluation still only produce
  scores/types; generation of these fields and AI proposal staging remain pending.
- Browser fixtures verify Cancel leaves the NPC clean, Save marks it dirty,
  suggestion selection, explicit memory clearing, new-row editing and Escape
  focus return. Desktop screenshots were compared with the actual pinned Herika
  component. At 390px viewport the document is 375px wide with no overflow;
  the dialog is 337.5px wide, with equal 336px client/scroll widths. No live
  relationship was edited or provider invoked for these checks.
- Verification: 370 server checks and 98 protocol files passed. Existing
  browser-like HTTP tests cover the real staged details fields, invalid oversized
  input and explicit clearing. Integration, migration and durable-job checks pass,
  including older exports, conflicting restores, private-note exclusion and
  details in the actual provider input. Schema inventory remains 167 relations
  with hash c9bac48593dd9036d47da521683e2b0eee958f3114214e06dd217efa267452a8.
  PHP lint, JavaScript syntax and diff checks pass. A no-op dialog Save leaves the
  NPC clean and retains recorded delta signals; changing only the role marks it
  dirty without altering those signals.
- Deployed locally with rollback /var/backups/lorkhanserver-code.pPEC5X. All 725
  runtime files match, with no extras or old paths. Health, private-file access
  and unauthorized-session checks pass. Configuration, credential and voice
  hashes were preserved. The live empty Fargoth editor contains all five detail
  labels and the NPC-save guidance. Migration 089 is present; relationship
  records/audit/provider-attempt counts remain 0/0/0. No live form was submitted.

### Voice Studio — consolidated provider cache structure

- XTTS, Chatterbox, PocketTTS, OmniVoice, Cartesia and Inworld now have one
  primary Voice Cache instead of separate provider and local-library sections.
  Provider discovery, connector selection and default-voice controls remain
  available inside a collapsed Provider Voice Browser. Existing authenticated
  uploads, tests, deletion safeguards and explicit provider requests are retained.
- Cached local voices display the actual cached status rather than a cross based
  on upload capability. Unconfigured connectors show an unknown marker, not a
  success check; PocketTTS audio.cpp is labelled local-only. OmniVoice cached
  status is filtered by the selected language. Cloud voice IDs sit under the
  name so long IDs do not squeeze the name to one character. These are cached
  observations, not live provider-health checks.
- Copy-name buttons are keyboard-accessible. Action buttons use the pinned
  reference's compact gray treatment. One scoped approval checkbox enables only
  the individual cloud-upload controls; revoking it disables them again. Backend
  upload consent is still mandatory. Batch approval remains separate.
- Batch sections remain visible for empty and unconfigured providers, with
  missing counts, Generate/Import/Process labels, local-only readiness or a
  connector setup link. Cloud tabs also show the automatic-generation explanation
  and an explicit warning when no connector exists.
- The upload panel now leads with the file chooser. Optional custom naming is
  collapsed; a single WAV uses its validated filename when no override is given.
  WAV/ZIP limits and persistent storage are unchanged. Multiple individual files
  and automatic upload-and-provider-sync still differ from the reference.
- Compared the actual pinned Inworld component with synthetic populated source
  states, including the combined cache, ID/action arrangement and batch section.
  UI checks cover consent enable/revoke and provider browser expansion. At 390px
  viewport the document client/scroll widths are both 375px and cards have equal
  326px client/scroll widths. No provider action was submitted in the browser.
- Still pending: provider-side forget/reclone/delete semantics and managed-clone
  ownership, automatic clone-cache visibility, provider-readiness checks,
  OmniVoice's dedicated language library, full provider-by-provider comparison,
  multiple-file uploads, batch progress parity, and Fallback/Pronunciations states.
  Those are open parity gaps, not accepted product exceptions.
- Also checked synthetic empty, unconfigured and XTTS populated states. The
  unconfigured cloud page shows its connector warning and no clone controls.
  The deployed Inworld page has one combined cache with 22 existing samples,
  a collapsed provider browser and a batch section. This was a read-only visit;
  no uploads, previews, refreshes, deletions or provider requests were submitted.
- Verification: 370 server checks, 98 protocol files, management HTTP forms,
  integration, migrations and durable jobs passed. PHP/JavaScript syntax and
  diff checks passed. The deployed manifest contains 725 matching files, with
  no extras or old paths; protected files return 403 on all three checked ports
  and unauthenticated session requests return 401. Configuration, credential and
  voice-file hashes remain unchanged. Rollback:
  `/var/backups/lorkhanserver-code.0k03pa`. No game was launched or controlled.

### Voice Studio — multi-file uploads and batch progress

- The shared provider upload picker accepts multiple WAVs/ZIPs, matching the
  reference control. All received samples are validated in a private staging
  directory before publication. Mixed selections retain the existing 64-WAV /
  128-MiB aggregate and 16-MiB-per-WAV limits, within PHP's upload limits. A
  client file count detects parser-truncated selections with JavaScript enabled.
  Existing samples are not overwritten; invalid/duplicate selections roll back.
  Custom naming is available for one WAV, not a multiple-file selection.
- Replaced the one-line batch status with the pinned reference's Progress count,
  30px bar, named success/failure log, ETA and Cancel control. The batch/upload
  buttons use its compact neutral treatment; the progress fill remains gold.
  A provider-discovered plan freezes a bounded queue. Named steps process each
  voice once, even when the provider's speaker list lags behind uploads.
- Cancelling finishes the active request and starts no further voices. Ordinary
  failures stay visible while the queue continues; HTTP 429 stops it. Inworld
  and Cartesia retain the reference's 3-second and 2-second inter-request delays.
  Missing consent and expired sessions return structured errors before uploads.
  Unconfirmed responses stop without automatic retry. Batch script URLs are
  versioned so stale cached JavaScript cannot drive the previous loop.
- Compared the actual pinned Inworld progress markup and the actual Lorkhan
  script with synthetic provider responses: complete (two distinct named
  requests), cancel (one request only), ordinary failure (next voice processed),
  rate limit (next voice not started), and expired session (plan only). These are
  UI fixture results, not live provider proof. At a 390px viewport the document
  client/scroll widths are 375/375 and progress panel widths are 326/326.
- Extended the existing management HTTP test: mixed WAV/ZIP success, invalid
  selection rollback, duplicate/existing-sample protection, multipart count
  mismatch, no upload during planning, consent/session rejection, named success,
  already-known skip, missing sample, traversal rejection and provider 429.
  The final HTTP run passed. The existing full check passed 370 server checks,
  98 protocol files, integration, migrations and durable jobs; schema remains
  167 relations with summary `c9bac48593dd9036d47da521683e2b0eee958f3114214e06dd217efa267452a8`.
- Still pending: automatic upload-to-provider sync, automatic successful-batch
  cache refresh (current results remain visible with a refresh link), managed
  clone controls/cache visibility, readiness checks, OmniVoice language UI and
  remaining provider/Fallback/Pronunciation state comparisons. This checkpoint
  does not finish Voice Studio or the all-pages goal.
- Final deployment: all 725 runtime files match source, without extra files or
  old paths; protected files return 403 on the three checked ports, and session
  creation without authentication returns 401. Configuration, credential and
  voice-file hashes are preserved. Rollback: `/var/backups/lorkhanserver-code.RfWgYB`.
  The live Inworld page exposes the multi-file input, hidden-until-start progress
  panel, 3000ms delay and versioned batch asset. Its batch button matches the
  reference's `#383838` background, `#505050` border, 13.12px font, 7px/12px padding
  and 36px height. No live form/provider action was submitted and no game was
  launched or controlled.

### Global voice tabs — fallback fields and built-in pronunciation editing

- Fallback Voices now has the reference's separate description/resolution-order
  paragraphs, uppercase gender labels, field typography and voice-ID suggestions.
  Suggestions include installed samples, connector-specific preview voices and
  currently saved fallback values; they do not restrict custom voice IDs. The
  ten TES3 race cards and global ownership remain unchanged.
- Pronunciations now exposes built-in Apply, Edit/Cancel, Save and confirmed
  Delete controls. Editing focuses/selects the spoken-text field; Cancel restores
  its saved spelling. Preview reads the current draft rather than the old static
  label. Original terms and access scopes remain server-protected. Custom entries
  retain their existing editable terms/scopes and per-row Save/Delete forms.
- The repository updates only spoken text/enabled state for built-ins. Deletion
  accepts built-in or custom IDs through the existing authenticated CSRF-protected
  POST. Dictionary cache invalidation makes edits/deletions visible to speech
  rewriting. There is no migration or deployment-time dictionary rewrite.
- Corrected actual geometry differences: five pronunciation columns now match
  the pinned reference at `161.094 / 185.25 / 241.641 / 96 / 210px` in the desktop
  fixture. The built-in editor row is 107px high; inputs are 36.15625px high with
  7px/10px padding. Scope and action cells are vertically centered. Repeated grid
  labels stay accessible but hidden until the rows stack on narrow screens.
- Fallback cards measure 474 x 147.1875px in both rendered fixtures. Inputs are
  42.5px high with 15px text; gender labels use 12px uppercase text. The source
  keeps gold accents and red destructive controls. At a 390px viewport both tabs
  have 375/375 document client/scroll widths and 326/326 row/card widths.
- Compared populated source/reference views, built-in edit and Cancel, edited
  preview request/error feedback, custom tag-filter empty state, fully empty
  dictionary, unavailable-preview state and blank fallback editing. Missing TTS
  configuration disables preview but does not block dictionary editing. Browser
  fixtures used synthetic responses; no paid provider or live voice was tested.
- Existing HTTP checks now cover built-in save, invalid/unauthorized saves,
  immutable term/scope, custom add/edit/filter, and deleting either entry type.
  Integration checks confirm edited built-ins retain scope and disabled/deleted
  entries stop applying. The full suite passed: 370 server checks, 98 protocol
  files, HTTP, integration, migrations and durable jobs. PHP/JavaScript syntax and
  diff checks passed; schema remains 167 relations with no schema changes.
- Remaining Voice Studio work is still tracked in the matrix. This does not
  finish the provider-specific workflows or the all-pages parity goal.
- Deployment proof: 725 runtime files match source, no extra/old paths, protected
  files return 403 on all checked ports, and unauthenticated sessions return 401.
  Rollback: `/var/backups/lorkhanserver-code.aEVxiE`. The live page has 46 closed
  built-in editors, confirmed delete forms, and suggestions on all 20 fallback
  fields. Visits were read-only. Before/after row fingerprints are unchanged:
  pronunciation rows `46:f94825bbd5072a897c4d999cf84514fc`, fallback rows
  `20:3e9bfc3426d9f96e2edc63235bd28134`. Configuration, credential and voice-file
  hashes are also unchanged. No game was launched or controlled.

## Cost Breakdown: request-type pie and date/week controls

- Replaced the generic metric-card/horizontal-bar dashboard with the pinned
  Herika `ui/audit.php` hierarchy: centered title, period and total, Today/date/
  week filters, and a 700px request-type pie with bottom legend and cost tooltips.
  The chart uses locally vendored Chart.js 4.5.1, with the source/license recorded
  in `THIRD_PARTY_NOTICES.md`. Lorkhan's first series remains gold.
- At the same desktop width, both actual template fixtures measured a
  1138.5 x 154.78125px header at y=20, a 1138.5 x 92px filter panel at
  y=204.78125, and a 700 x 700px chart at y=357.78125. Apply buttons now use
  the reference's gray surface rather than save-action green.
- The primary chart groups all matching attempts by request type. The existing
  provider/model/token breakdown and CSV remain available under collapsed
  details; their 100-group limit does not truncate the chart or total. Exact
  six-decimal costs are available in an accessible request-type table. Missing,
  negative or nonnumeric pricing remains unknown, with partial-total coverage
  disclosed. Empty, unknown-cost and explicitly zero-cost states are distinct.
- Today is the default. Date and ISO-week filters use inclusive UTC starts and
  exclusive ends, including year-end weeks. Existing period URLs still work.
  Explicit All Installations stays unscoped through the shared helper. Forms and
  export retain the current scope. A follow-up review removed a redundant local
  guard: the helper already handled this case before the Cost Breakdown change.
- Compared populated and empty pinned reference/source fixtures and source
  unknown/zero-cost states, opened both detail sections and checked date/week
  button URLs. At 390px, Lorkhan keeps 375/375 document client/scroll widths,
  wraps filter controls and uses a 291px square chart. This intentionally fixes
  the reference's 405px page overflow and stretched 291 x 500px pie. The dense
  details table retains readable columns in a keyboard-scrollable region;
  ArrowRight moved its scroll position to 40px without widening the document.
- Existing HTTP tests use historical synthetic attempts to prove exact day and
  ISO-week bounds, invalid-date fallback, installation isolation, unknown prices,
  a $103 daily total across 105 attempts, a $112 week total and a 100-row CSV
  without truncating chart totals. No live provider was called. PHP/JavaScript
  syntax, 370 server checks, 98 protocol files, management HTTP, integration,
  migrations and durable jobs passed. Schema remains 167 relations at
  `c9bac48593dd9036d47da521683e2b0eee958f3114214e06dd217efa267452a8`.
- This completes this page's structural comparison, not the all-pages goal.
- Deployment verified 730 exact runtime file hashes, no extra/old paths, private
  files returning 403 on all three checked ports and unauthenticated sessions
  returning 401. Configuration, credential and voice-file hashes were unchanged.
  Rollback: `/var/backups/lorkhanserver-code.0uuIh8`.
- Live browser proof covered the default empty day, all-time populated pie and
  expanded exact values, Today/date/week submissions, and Control Panel embedding.
  The live all-time scope had 402 attempts, of which 9 recorded $0.006215 total;
  the two unpriced TTS operation groups remained Unknown. These were read-only
  visits. No provider call or game action was issued.

## Playthrough Manager: structural checkpoint, snapshot workflow still incomplete

- Replaced the generic metric-card grid with the pinned Herika page's centered
  explanatory header, highlighted overview, paired content sections and bounded
  record list. The source/reference desktop panels measure 1012px overall and
  491px per paired column, with 20px header and 25px panel padding. Both use
  337.5px panels with 15px padding at a 390px viewport. Lorkhan retains gold and
  the reference's semantic green highlights.
- Added an installation selector and read-only playthrough selection. The SQL
  applies installation scope before the existing 100-row limit, then orders by
  latest session and creation time. Import destination fields are derived from
  the selected playthrough and owning profile, avoiding three independent
  selectors that could name an inconsistent scope. Selection does not activate
  a session, switch the game or modify records.
- Corrected misleading export/import descriptions after tracing
  `ProductService::exportPlaythrough` and `ProductRepository::exportScope`:
  existing exports contain only the owning profile's memories, relationships and
  narratives. They do not include conversations, source events, knowledge,
  configuration, audio or other profiles. Import is a merge with relationship
  conflict checks, not complete database restoration. Whole-playthrough summary
  counts are explicitly distinguished from that narrower export scope.
- This is NOT full Playthrough Manager parity. Remaining work includes a
  playthrough-wide multi-profile snapshot contract and stored snapshot creation,
  notes, listing, safe restore/switch, automatic pre-switch backups, deletion,
  rollback/Dragon Break handling and the timeline. The current 100-row list also
  needs access to older records. No fake stored-snapshot or game-switch buttons
  were added, and these missing features are not recorded as product exceptions.
- Kept creation/import as secondary expandable tools. Empty and no-installation
  states explain prerequisites; unusable actions are absent or disabled. File
  selection now clears stale JSON, rejects oversized files, blocks submission
  while reading and ignores superseded asynchronous reads. Manual JSON input
  clears stale picker errors. Existing authenticated export/import routes and
  relationship protections are unchanged.
- Compared actual populated/empty source and pinned reference fixtures,
  no-installation state, long escaped names, the import editor, empty-submit
  feedback/focus and narrow layouts. Source document client/scroll widths both
  measured 375px at a 390px viewport. The import picker guards passed a temporary
  JavaScript probe for oversized, pending, superseded, invalid and empty input.
  Existing HTTP tests passed scoped creation, selected/invalid route handling,
  export and import. PHP/JavaScript syntax, 370 server checks, 98 protocol files,
  HTTP, integration, migrations and durable jobs passed; no schema changed.
- Live verification caught and corrected a real timezone mismatch: the
  PostgreSQL session returned `2026-09-05 19:35:12+02`, now explicitly rendered
  as `2026-09-05 17:35:12` UTC. The deployed overview showed 71 sessions, 49 turns,
  285 responses and 283 memories. Standalone and Control Panel embedding, the
  selected export link and visible import destination were checked read-only.
- Final deployment: 732 runtime files matched source, no extra/old paths,
  protected files returned 403 on all checked ports and unauthenticated sessions
  returned 401. Configuration, credential and voice-file hashes were unchanged.
  Rollback: `/var/backups/lorkhanserver-code.bPE5s4`. No live snapshot import,
  provider call or game action was performed.

## NPC Biographies: dialogs, reader and scoped save checkpoint

- Compared the actual source presentation with pinned HerikaServer
  `ui/npc_upload.php` using synthetic populated and empty fixtures. Restored the
  centered header and reference page gutters. Summary Bio now displays `core`
  rather than the separate detailed biography. Long summaries are truncated by
  Unicode characters, not bytes.
- Replaced the incomplete Add Entry expander with the reference modal workflow.
  Add/Edit share the complete field sequence: name, Oghma tags, core, extended
  biography fields, voice and metadata. OpenMW creation additionally requires
  the content file and stable record ID. Edit preserves identity. Both dialogs
  measured 800px wide and 521px high at the desktop comparison viewport, matching
  the reference, with its scrolling body and sticky Save/Cancel footer.
- Replaced the divergent two-column Extended Profiles cards with the reference
  stacked reader, section order, typography, content panels and scrolling footer.
  Compared the rendered result directly with the reference reader. Gold accents,
  visible focus outlines and white headings remain.
- Save-path testing exposed a pre-existing split: imported/new templates lived
  in installation-owned revisioned profiles, while the page only listed the
  global combined template table. Installation templates now appear in the same
  table with their own source label. Details and edits use the stable profile ID
  plus installation; they are not published as global factory overrides. Edits
  update that same revisioned record, so CSV export and NPC initialization use
  the edited content. Stale revisions are rejected; record identity is immutable.
- Existing HTTP coverage now exercises creation with all 16 CSV fields, summary
  vs detailed biography, scoped detail retrieval, editing, updated CSV export,
  stale-write rejection, wrong-installation lookup and record-ID tampering.
  Global factory/custom override editing retains its previous storage path.
- Checked Add/Edit and Extended Profiles, search/no-match, alphabet filtering,
  empty and no-installation states, Escape/Cancel focus restoration and reverse
  Tab wrapping. At a 390px viewport the open dialog measured 374px wide; the
  ordinary document client/scroll widths both measured 375px. The 920px table
  remains inside its focusable horizontal scrolling region.
- This is a checkpoint, not full page parity. Inline Oghma reading still links
  away, factory reset is absent, global custom overrides are not included in the
  installation CSV export, and access beyond each existing 5,000-row list cap
  remains unfinished. Batch-help presentation also differs. These are tracked
  gaps, not product exceptions. No factory reset, live edit/import, provider call
  or game action was performed during this work.
- Final checks passed: PHP/JavaScript syntax, 370 server checks, 98 protocol
  files, management HTTP, integration, migrations and durable jobs. Schema
  inventory remained 167 relations with the existing summary hash.
- Local deployment verified 733 runtime files with no mismatches, extras or old
  paths. Protected files returned 403 on all checked ports; unauthenticated
  session creation returned 401. Configuration, credential and voice-file hashes
  were unchanged. Rollback: `/var/backups/lorkhanserver-code.FEEMKT`.
- The live page showed 5,001 rows: the existing 5,000-row global cap plus one
  installation template. The previously omitted Dagoth Ur template appeared,
  and its scoped Edit dialog loaded the saved name and distinct core/detail
  fields. Cancelled without saving. One browser Search click timed out on this
  large DOM; Enter applied the same filter successfully. Large-catalog paging
  and server-side filtering therefore remain a concrete usability priority.

## NPC Biographies: full-catalog browsing and inline Oghma

- Replaced browser filtering of the first 5,000 records with database search and
  alphabet links over all global and installation-owned templates. Results use
  stable ordering and 50-row pages; out-of-range pages clamp to the final page.
  Search treats percent/underscore literally, preserves installation and embedded
  state, and works without JavaScript. No rows are silently excluded by a cap.
- Added the actual inline Oghma reader instead of linking away to the catalog.
  Compared its three-column article table, search/category controls, badges,
  scrolling body and footer against pinned Herika fixtures. Both desktop dialogs
  measure 800x521px; the filter block matches the reference's 125.5px height.
  Gold replaces orange; fixed emoji remain legible instead of copying the
  reference file's broken encoded symbols. Pagination preserves access to every
  matching article without an unbounded browser payload.
- Biography previews use the template's own tags and the selected installation's
  effective catalog. They do not activate NPCs or include profile/playthrough
  articles. Shared runtime access decisions resolve advanced/basic/denied access.
  Corrected the existing NPC viewer's field mapping: the access checker expects
  `topic_desc`, while repository candidates expose `content`. This had silently
  downgraded advanced articles. The shared viewer now passes the right field.
- Basic previews return only the permitted basic description; advanced text is
  absent from the JSON, and description search cannot reveal that hidden text.
  Both viewer paths retain the same effective override resolution. Article text
  and metadata are rendered through DOM text nodes, never provider-supplied HTML.
- Existing HTTP checks now seed 5,005 catalog records and prove the final record
  is searchable, alphabet-filtered and reachable on page 101. A separate literal
  percent/underscore name tests escaping. The same suite covers 53 basic articles,
  one advanced article and one denied article; description/category filtering,
  second-page results, hidden-text exclusion and wrong-installation lookup pass.
- Compared populated views against the actual reference; exercised filtering,
  no results, a simulated 503, Clear/retry, and closing a delayed request. A late
  response did not reopen or populate the closed reader. At a 390px viewport the
  dialog is 374px wide without document overflow; its table supports keyboard
  horizontal scrolling (ArrowRight moved 40px). No game or provider was invoked.
- PHP/JavaScript syntax, 370 server checks, 98 protocol files, management HTTP,
  integration, migrations and durable jobs passed. No schema changes. Remaining
  biography gaps are factory reset, a complete global custom export/import
  contract and batch-help presentation; the all-pages goal remains incomplete.
- Live deployment proof: the complete biography catalog has 12,687 templates,
  shown in 254 pages. Page 254 contained the final 37 rows, including
  `zeno_faustus`; searching Fargoth returned both existing case-distinct records.
  The factory Fargoth preview loaded 3,327 accessible articles in 67 pages;
  Next reached page 2. A Balmora description search returned 30 articles,
  comprising six Advanced and 24 Basic results. Closed without editing data.
- Runtime verification: all 733 files matched source, with no extras or old
  paths; protected files returned 403 and unauthenticated session creation 401.
  Configuration, credentials and voice files retained their hashes. Rollback:
  `/var/backups/lorkhanserver-code.dheiIb`. No game interaction or paid provider
  call was used for verification.

## Description Manager presentation checkpoint

- Adapted the actual pinned `description_upload.php` structure: compact title and
  subtitle, paired Batch Upload / Database Management panels, five-column table,
  alphabet/search toolbar, and shared Add/Edit dialog. Removed the divergent
  inline editors and Source column. Installation summary, source/plugin filters
  and discovered OpenMW items remain in a secondary details section.
- Table previews stop at 200 Unicode characters; Edit receives the full escaped
  description. Plugin and stable record ID are read-only while editing. Factory
  rows create scoped overrides and do not expose Delete; custom rows retain
  the existing confirmed Delete action. No backend or schema contract changed.
- Compared populated and empty fixtures with the actual Herika presentation.
  Checked Add defaults, factory/custom Edit, a 413-character description,
  Cancel/Escape, focus restoration and focus trapping. At 390px the main table
  stays in a 353px scroll region; keyboard ArrowRight moved it 40px without
  document overflow. The dialog fields and controls remain reachable by scroll.
  Without an installation, Upload, Add and Reset are disabled.
- Extended the existing HTTP suite for full Unicode description creation,
  editing, CSV preservation and deletion. Existing import and reset checks pass
  using the modal row data rather than the removed inline forms. PHP/JS syntax,
  370 server checks, 98 protocol files, management HTTP, integration, migrations
  and durable jobs passed. Schema inventory remains unchanged.
- Real product differences: OpenMW source plugin/stable record keys replace
  Skyrim FormIDs; overrides remain installation-scoped. Storage guidance names
  the actual internal table and does not advertise an unavailable SQL browser.
  Existing mandatory Name and non-empty Description are still stricter than
  Herika's optional fields; this is an outstanding contract difference, not a
  claimed OpenMW requirement. The all-pages goal remains incomplete.
- Local deployment verified all 733 runtime files against source with no extra
  files or old paths; protected files returned 403, and unauthenticated session
  creation returned 401. Configuration, credentials and voice files retained
  their hashes. Rollback: `/var/backups/lorkhanserver-code.NZr627`.
- Live Description Manager showed 3,429 records in 69 pages; searching `glass`
  returned 54 records in two pages. Opened the existing Blue Glass Pot editor
  (127-character description, read-only Tribunal.esm / misc_de_pot_blue_01) and
  cancelled. No live description data was changed and no game was launched.

## Oghma article page presentation checkpoint

- Replaced the short summary header with the reference encyclopedia introduction,
  topic-format guidance and four Article Search Logic steps. Retained Morrowind
  wording and actual NPC knowledge-access behavior. The populated fixture header
  measured 757.71875px in both products at the same desktop viewport.
- Corrected the upload panel, accurate internal storage guidance, compact factory
  sync controls, left-aligned category buttons, table font/borders, knowledge
  badges and compact pagination. The reference's missing ninth-column width
  collapses its Action header; Lorkhan reserves space for Edit and keeps the
  table inside a keyboard-scrollable region instead of copying that defect.
- Add/Edit dialogs now match the reference 800px width and 554.5px height at
  1280x720, with the 420px scrolling body. Removed negative footer margins that
  caused horizontal overflow. Add uses five-row description fields and Save;
  Edit retains eight-row fields. Kept clear word spacing in modal titles.
- Compared source-derived populated/empty fixtures against the actual pinned
  reference. Checked factory protection and Save as Custom Article, custom Edit
  with all 374 description characters, Add fields, final Category access,
  Shift+Tab trapping, Escape/Cancel and focus restoration. At 390px the dialog
  is 374px wide and the page stays within the viewport; the article region
  accepts keyboard horizontal scrolling. These were synthetic fixtures, with no
  provider calls or writes to the live catalog.
- Existing 370 server checks, 98 protocol files, management HTTP, integration,
  migrations and durable jobs passed. The integration suite already covers
  factory/custom revision, override deletion and revealing the factory article.
  Final PHP/JS syntax and diff checks passed; no backend or schema was changed.
- Outstanding differences are not treated as product exceptions: Dynamic Oghma,
  Delete All / destructive Factory Reset, optional Category validation and
  Herika-style substring search still need work. Lorkhan's existing non-destructive
  factory sync, installation scoping, full-catalog pagination and runtime article
  tags are preserved. The full all-pages goal remains incomplete.
- Local deployment verified all 733 runtime files against source, with no extra
  files or old paths. Protected files returned 403 and unauthenticated session
  creation returned 401; private configuration, credential and voice hashes were
  preserved. Rollback: `/var/backups/lorkhanserver-code.HceBjq`.
- Live catalog proof: 3,741 articles across eight pages. Searching `balmora`
  returned 47 articles; the Bitter Coast category reduced it to six, and
  Descending retained the search/category and reversed topic order. Opened the
  factory `seyda_neen` editor with its 671-character description and cancelled;
  no horizontal dialog overflow and no live catalog changes.

## NPC Oghma knowledge reader checkpoint

- Replaced the divergent metric strip and large article cards with the actual
  Herika `npc_upload.php` knowledge reader's three-column table: Topic,
  Knowledge Level and Description. Added matching category/class/tag chips,
  title typography, search/category controls, gray Apply/Clear actions and empty
  state. Reference and source tables measured 713px wide; titles used the same
  26px / 39px Futura/Arial typography at the desktop comparison size.
- This entry remains an existing standalone NPC page, not a new modal. Its
  browser navigation and scoped 50-row pagination remain. Access-level filtering,
  effective tags and Advanced/Basic/Denied counts moved into a compact Knowledge
  access disclosure rather than being removed. Aliases remain topic metadata.
- Description search now uses the same effective-text path as the biography
  reader. Access is resolved before searching; a Basic article cannot match
  its hidden advanced text and denied articles cannot appear in results.
- Extended the existing management HTTP suite with the already-seeded knowledge
  fixture: all 53 permitted basic descriptions were searchable; page two held
  the remaining three rows. Protected advanced text returned no results, neither
  protected text marker appeared in the populated HTML, and the wrong
  installation returned 404. No new test file or fixture database was added.
- Compared populated and empty states with the real reference renderer using
  identical synthetic articles. At 390px, the 600px table stayed inside a 307px
  region without document overflow, ArrowRight moved it 40px, and access controls
  remained readable. Clear preserves NPC/installation/embed while resetting all
  filters and pagination. The reader needs no new JavaScript.
- PHP/diff checks, 370 server checks, 98 protocol files, management HTTP,
  integration, migrations and durable jobs passed. No schema changes. The
  broader all-pages goal remains active.
- Deployment verified all 733 runtime files with no hash mismatches, extras or
  old paths. Protected files returned 403 and unauthenticated session creation
  returned 401; configuration, credential and voice hashes were preserved.
  Rollback: `/var/backups/lorkhanserver-code.Jt9rpu`.
- Live Fargoth reader showed 3,327 accessible articles over 67 pages. Balmora
  description search returned 30 articles (six Advanced, 24 Basic); selecting
  Basic returned exactly 24. Clear restored all access levels and Next opened
  page two with 50 rows. No profile, catalog, provider or game state was changed.

### Provider Attempts and Workers & Jobs — operational presentation checkpoint

- Replaced both generic auto-column tables with the pinned Herika Request Logs
  header, toolbar, metadata, table and status-pill presentation. No exact CHIM
  all-provider/durable-job page exists; columns retain Lorkhan's actual metadata.
- Removed the latest-100 display cap through bounded 50/100/200-row server
  pagination. Installation, period, literal search and status filters preserve
  embedded navigation; Refresh preserves filters. CSV exports only the displayed
  allowlisted metadata page. No payload, raw error, credentials or job mutation
  controls were introduced. Default visibility remains all installations/time.
- Actual source-rendered populated and empty fixtures were compared with pinned
  `ui/request_logs.php`: both had 30px title, 12px table text, 9px/10px header
  padding and 20px/12px/40px embedded main padding. At 390px the table remained
  inside its 307px region with 1,124px scroll width; ArrowRight moved it 40px.
  Queued, running, success, dead-letter, pending, error and cancelled states were
  visually inspected. No provider calls or live data writes were made.
- PHP lint, 370 server checks, 98 protocol files, management HTTP forms,
  integration vertical slice and migration/durable-job checks passed. Extended
  existing HTTP coverage verified 110 historical/unassigned attempts across three
  pages, page CSV, literal-percent search and installation/period/status filters.
- Deployment verified all 735 runtime files without mismatches, extras or old
  paths. Protected files returned 403 and unauthenticated session creation 401;
  configuration, credentials and voice hashes were preserved. Rollback:
  `/var/backups/lorkhanserver-code.yIvZ2n`.
- Live pages showed 540 provider attempts over 11 pages and 1,105 jobs over 23.
  Next opened page two on each; failed attempts returned 107 records, dead-letter
  jobs returned 29, and Refresh retained the selected job filter. This completes
  these two presentation rows, not the remaining all-page parity goal.

### Database Manager — actual embedded counterpart and native backup layout

- Herika Control Panel embeds Dwemer-Dashboard `database_manager.php`; the old
  `conf_wizardbackup.php` is a configuration editor, not its database page.
  Reference dashboard remained clean at `7c19d3ddb7fa7aaf9cbd71abc41a1cdb4b7f9758`.
- Replaced generic Lorkhan cards and auto-generated migration columns with the
  reference header, tool panels, stat tiles, selectable backup-file cards,
  restore guidance, empty state and version table. Preserved the actual native
  configuration-only JSON format, typed Backup/Restore confirmations and
  authenticated fixed-ID downloads. Full database backup is not claimed.
- Configuration backup kind is filtered before 25-row paging, rather than after
  a generic latest-100 query; all applied migrations are accessible in the bounded
  scroll region. Dates are explicitly formatted in UTC. No schema changes.
- Compared actual source-template populated/empty/no-installation fixtures with
  extracted reference markup and CSS without executing either reference controller.
  Corrected inherited subtitle alignment, heading sizes and button typography:
  32px title, 22px panel headings, 15px buttons, 14px stat labels, 20px card padding
  and 20px/24px header padding. Restore cards retain reference selection indicators.
- At 390px the tools stack in a 351px column without body overflow. Clicking the
  second backup selected it; ArrowUp returned selection to the first radio with
  keyboard focus intact. Restore guidance expanded; the 307px migration region
  scrolled 40px horizontally by keyboard across its 760px table. No live restore,
  backup creation, maintenance or provider/game action was performed.
- Existing HTTP tests passed backup create/download, wrong-confirmation rejection
  and restoration of settings/Core Profile assignments. PHP lint, 370 server
  checks, 98 protocol files, HTTP forms, integration and migration checks passed.
- Local deployment verified 737 manifest files without mismatches, extras or old
  paths; protected routes returned 403 and unauthenticated session creation 401.
  Configuration, credentials and voice files were preserved. Rollback:
  `/var/backups/lorkhanserver-code.dKfzjD`. Live embedded page showed schema 89,
  all 89 migration rows and zero configuration backups, matching its empty state.
- This is a presentation checkpoint. CHIM's full SQL backup/import, automatic
  backups, database access, maintenance/reset and version-reset controls remain
  missing. They are tracked as outstanding parity work, not product exceptions.

### Narrative Manager — diary-style entry table and dialogs

- Replaced the always-expanded generation/create forms and narrative cards with
  a compact Author/Content/Time/Actions table. Author, title/content, installation,
  period and kind can be searched/filtered before 50-row server pagination;
  metadata-backed entries no longer disappear behind a latest-100 view limit.
- Pinned Herika `diarylog.php` and `diary_adventure.css` supplied the table/editor
  reference. Herika has no exact standalone manual narrator/summary manager:
  Lorkhan's existing kinds and scoped create/generate controls remain as dialogs.
  Editing starts with the content; title/kind/provenance stay in expandable
  metadata. Delete has a separate confirmation initially focused on Cancel.
- Source-template populated/empty/no-scope fixtures and actual reference editor
  markup were rendered without reference controllers. Compared 800px desktop
  editor width, y80 placement, 400px textarea, 26px heading, 13.44px textarea font,
  dark panel/border and right-aligned footer. Native bounded scrolling keeps the
  footer visible where the reference dialog extends below a 720px viewport.
- Tested Cancel and Escape focus restoration, discarded-edit reset on reopen,
  literal script-looking text, metadata expansion, keyboard focus wrap and
  browser validation reopening a collapsed required Title field. Two-installation
  fixtures verified that creation and diary generation both restrict profile and
  playthrough choices to the selected installation. No mutation/provider calls
  were made through these browser fixtures.
- At 390px the populated table remains in a horizontal scroll region, while
  metadata and provenance fields and save/cancel remain reachable inside the
  editor. The empty-table minimum width is removed so its message wraps instead
  of requiring horizontal scrolling. Missing-scope create/generate buttons are
  disabled. The main table retains native title/kind information and omits a
  fabricated Tamrielic timestamp; the dedicated diary calendar retains that view.
- Existing HTTP tests verified create/edit, kind/title search, diary calendar
  visibility/export, and disabled-diary generation refusal without provider calls.
  PHP lint, 370 server checks, 98 protocol files, management HTTP, integration and
  migration/durable-job checks passed. No database migration was introduced.
- Deployment verified 740 files, no mismatches/extras/old paths, 403 for protected
  files and 401 for unauthenticated session creation. Configuration, credentials
  and voices were preserved. Final rollback: `/var/backups/lorkhanserver-code.EVCOTZ`.
  Live page correctly showed no entries; generation/create dialogs exposed one
  installation, 13 available profiles and one playthrough, then were cancelled.
  No live narrative was created/deleted, no generation was queued, and no game
  was launched or controlled. The remaining all-page goal is still active.

## Server Health and Backup Health checkpoint

- Replaced generic output with the shared Herika Request Logs presentation:
  compact toolbar, explicit columns, UTC timestamps, page navigation, filters and
  displayed-page CSV. Audit scope exports only allowlisted identifier keys;
  arbitrary scope/detail and backup paths/payloads are not rendered.
- Dashboard-derived summary tiles report database connectivity and recorded
  counts. Backup status is recorded metadata, not a filesystem integrity check.
- Retention remains the existing server-wide operation. The panel explains that
  days apply to request deduplication, while rate-limit/session cleanup has its
  existing rules. Backups, narratives, memories, voices and saves are retained.
  A native dialog requires explicit confirmation; no retention operation was run.
- Compared source-rendered populated and empty states at desktop and 390px with
  pinned operational styles and the actual Dashboard summary/panel CSS fixture.
  Scope identifiers have no spurious leading line. Narrow tables scroll locally;
  empty messages wrap. The confirmation displays edited days, initially focuses
  Cancel, supports Tab and Escape, and restores focus. The earlier browser-native
  confirmation stall was replaced with this verified in-page dialog.
- PHP lint, 98 protocol files, 370 server checks, management HTTP, integration,
  migrations and durable-job checks passed. After the dialog replacement, Node
  syntax, PHP template lint and management HTTP were rerun successfully. Existing
  HTTP coverage verifies safe audit CSV, historic UTC filtering, backup creation
  visibility and retention markup without invoking retention.
- Local deployment verified 742 matching runtime files, no extras/old paths,
  protected paths returning 403 and unauthenticated session creation returning
  401. Configuration, credentials and voices were preserved. Rollback:
  `/var/backups/lorkhanserver-code.ORYbMF`. Live health and backup readers showed
  their actual empty audit/backup states and database snapshot correctly.
  No game launch/control, provider request or live retention was performed.
  These native pages have no exact CHIM health counterpart; their presentation
  follows the referenced operational components. The all-page goal remains open.

## Game Debug checkpoint

- Replaced specialist widgets with the shared operational header, compact session
  row, paired command sections and readable command history. Help is collapsible;
  On/Off controls have distinct accessible names. All 19 existing command buttons
  retain their command/parameter attributes and existing authenticated dispatch.
- History uses the attributed Request Logs table and status pills, explicit UTC
  display and a wrapping empty state outside the wide populated table. DOM text
  rendering still treats script-looking result text literally.
- Actual PHP/JavaScript fixtures used a synthetic local GET-only history endpoint,
  never the game server. Populated success/queued/failure/rejected/expired history,
  offline and unsupported-client states were inspected. Both disabled states
  disable all 19 commands and Refresh state. Help disclosure and narrow keyboard
  table scrolling passed (40px movement in the 800px table inside its own region).
- Compared desktop and 390px screenshots with the pinned Request Logs reference:
  both use 30px headings, 12px table text and 9px/10px header padding. OpenMW command
  groups are native extensions; no exact CHIM command page is claimed.
- Node syntax, all PHP lint, 98 protocol files, 370 server checks, management HTTP,
  integration, migration and durable-job checks passed. Existing HTTP coverage
  now includes the actual debug page, preserved command count, accessible names,
  empty markup and UTC label. No new test file or backend command change.
- Server-only deployment verified 742 identical runtime files, no extras/old paths,
  protected-file 403 and unauthenticated-session 401. Configuration, credentials
  and voices were preserved. Rollback: `/var/backups/lorkhanserver-code.OpG6ZH`.
  Live embedded Game Debug rendered its available session, 19 controls and empty
  command history without feedback errors. This is read-only page verification,
  not proof of game-command execution. No game was launched or controlled, and
  no command/provider request was issued. Remaining page parity stays open.

## Separate diary reader/editor checkpoint

- Replaced the combined Read/Edit dialog with the actual diary row interaction:
  click full content to read, with separate Play, Edit and Delete controls. Removed
  the 700-character preview limit. Automatic column layout, 13px table text,
  9px/10px cell padding and compact actions match the pinned diary row fixture.
  Fixed doubled paragraph spacing caused by pre-wrap combined with HTML breaks.
- Added a dedicated 800px content-first editor with 400px textarea, reference
  typography and Save Changes/Cancel footer. Lorkhan's required title remains in
  secondary Entry details; identity, kind, CSRF and revision provenance are kept.
  Delete has a separate native confirmation with Cancel initially focused.
- Paper reading no longer contains mutation forms. It uses the reference's
  handwritten face, paper asset, 40px padding, internal scroll area and fixed
  audio footer. Explicit Close and Export remain accessible Lorkhan controls.
  At 390px the reader/editor remain within the viewport, and the empty table
  wraps without horizontal scrolling. Long populated tables retain local scrolling.
- Source-rendered populated/empty/long fixtures were compared with the pinned
  diarylog.php edit/read markup and actual emitted row markup, not screenshots
  of a generic substitute. Cancel discards drafts, Escape restores focus, Tab
  wraps within dialogs, and validation reveals a missing required title inside
  collapsed metadata. Save failure appears inside the editor and re-enables Save.
- Table and modal Play controls use the existing authenticated sentence-preview
  lane. Playback feedback is moved to the visible table dock or open reader;
  closing restores the dock and cancels pending playback. Plain-text newlines
  remain available while the reader is closed. Isolated non-audio endpoint
  failures verified both feedback locations without calling a speech provider.
- Existing HTTP coverage now reads the dedicated diary form and uses its identity
  and scoped endpoint for the revision test. The first new assertion incorrectly
  expected the existing test parser to collect textarea values; it was removed,
  while browser value checks and actual revision HTTP coverage remain. PHP lint,
  Node syntax, 98 protocol files, 370 server checks, management HTTP, integration,
  migration and durable-job checks passed. The final HTTP rerun after the last
  table markup changes also passed. No new test file or backend persistence change
  was added.
- Deployment verified 743 matching runtime files, no extras or old paths,
  protected files returning 403 and unauthenticated session creation returning
  401. Configuration, credentials and voices were preserved. Rollback:
  `/var/backups/lorkhanserver-code.uAT5UB`. The live Diaries page rendered the
  actual empty state with the new table layout. Populated edits and playback
  feedback were verified in isolated fixtures, not through live data mutations.
  No diary was generated, edited or deleted in the live server, no paid speech
  provider was called, and no game was launched or controlled. All-page parity
  remains open for the other rows in this matrix.

## Adventure Log chronological presentation checkpoint

- Replaced generic event-kind rows with the actual Adventure Log structure:
  Context, Nearby People, Tamrielic Time and Time (UTC), with initial/current
  location and location-change dividers and contiguous speaker bands. Removed
  duplicate location-context suffixes only in presentation/export; immutable
  source data remains untouched. OpenMW-only recorded event types remain usable.
- The query now orders Adventure rows oldest-first before paging, with numeric
  event IDs as the deterministic tie-breaker. Other readers keep their existing
  sort order. CSV uses the counterpart's four columns and shares formatting with
  the table, retaining the full location plus recorded game date and formula-safe
  values. Entire-scope export is not restricted to the displayed page.
- Native Morrowind dates and full cell names are preserved, including names such
  as Balmora, South Wall Cornerclub. Missing location/date stays explicit rather
  than parsing Skyrim-specific location strings or inventing dates. Calendar day
  links jump to the table after filtering, and the calendar reference link is kept.
- Compared actual source-rendered populated, empty and long rows with the pinned
  Adventure row markup and diary_adventure.css. Both use 13px table text,
  9px/10px cells and 55/20/15/10 column proportions. Fixed an inherited 47% people
  column conflict and ensured ordinary zebra striping cannot override speaker
  grouping. The reference's subtle #232323/#202020 speaker bands and left-aligned
  location dividers are retained. Lorkhan's accent remains gold.
- At 390px the table scrolls within its keyboard-focusable region (40px ArrowRight
  movement), with no document overflow. Empty messages wrap in the viewport;
  1,869-character event content remains complete and script-looking text stays
  text. Calendar and native scope controls remain available.
- Existing HTTP coverage seeds 25 isolated projection rows across two UTC days and verifies numeric
  chronological ordering across two pages, location grouping, escaped content,
  complete four-column CSV, UTC/game dates, other-playthrough exclusion and an
  empty selected date. The first ordering assertion incorrectly included the
  hidden Events tab, whose newest-first order is intentional; the assertion now
  inspects the Adventure table itself. Fixture projections are cleaned afterward.
  No new test file or live database mutation was introduced.
- The initial view now follows Herika's date-selection contract: calendar counts
  remain visible, but the table asks the user to select a date. Current Date
  download uses the selected day, falling back to the latest recorded UTC day
  when none is selected. Entire Adventure Log explicitly selects the full scope.
  Tests distinguish the 24-row selected day, one-row latest day and 25-row full
  export. No-date and selected-empty states are separate, with matching messages.
- Final full check passed: PHP lint, 98 protocol files, 370 server checks,
  management HTTP and integration/migration checks. The generated schema evidence
  was restored because this checkpoint makes no schema change.
- Final deployment rollback is `/var/backups/lorkhanserver-code.7fZ2m1`.
  All 744 runtime files match source, with no extra files or old paths; protected
  files return 403 on all three checked Apache ports and an unauthenticated
  session returns 401. Configuration, credentials and voice files were preserved.
- Live browser verification confirmed the unselected-date instruction, then
  followed an observed calendar URL for 16 Last Seed, 3E 427. The selected date
  contains 93 entries across five pages; the first page renders 20 events in
  ascending UTC order, with the full `Dagoth Ur, Facility Cavern` cell name and
  contiguous speaker bands. The document stays within the 1280px viewport.
  No live data was changed, no speech provider was called and no game was launched
  or controlled. The remaining all-page matrix stays open.

## Knowledge editor field and search contract checkpoint

- Compared the pinned Description Manager and regular Oghma forms with the
  current source-rendered dialogs. Both use the existing 800px dialog shell;
  Name/Description are optional for items, and only Topic/Advanced Description
  are required for Oghma. Removed the invented initial `lore` category and the
  extra Category requirement. Item plugin and record ID remain mandatory because
  OpenMW identity has no Skyrim legacy wildcard-key equivalent.
- Updated form mapping, service validation and migration 090 together. Explicit
  blank names, descriptions, categories and Basic Description survive individual
  saves, edits and CSV imports. Native field-size, UTF-8, scope and identity checks
  remain. No existing catalog values are rewritten. The downgrade refuses saved
  blanks instead of manufacturing replacement names or knowledge.
- Regular Oghma editor search now applies case-insensitive substring matching to
  Topic and Aliases, like the reference. Description, Basic Description, title and
  tags do not create unrelated results. Filters run after factory/custom override
  resolution and before paging. Runtime retrieval and NPC permission decisions
  are unchanged.
- Existing HTTP coverage checks blank save/readback/CSV paths, partial topic and
  alias matches, description/tag-only exclusions, category/scope filters and
  rejection of missing required fields. A restricted NPC cannot read advanced
  text when Basic Description is blank: the existing access policy omits that
  article entirely. The first new assertion incorrectly expected an empty Basic
  row; it was corrected after inspecting the access policy, without changing it.
- The existing 1,300-article pagination test now searches a real topic substring
  rather than a full-text-only title phrase. It still verifies the 500-row cap,
  every matching row and clamping beyond the last page. Added a small downgrade
  refusal check to the existing migration suite; no new test file.
- Browser comparisons covered populated and empty source fixtures, required-field
  validity, blank optional values, Cancel focus restoration and 390px editor
  scrolling. Buttons and blank fields remain reachable without document overflow.
  These were isolated presentation fixtures, not mutations of live catalogs.
- Final full check passed: PHP lint, 98 protocol files, 370 server checks,
  management HTTP, integration vertical slice and migration/durable-job tests.
  Updated schema evidence records the four relaxed constraints; relation/column
  counts remain 167/1,562.
- Deployed with rollback `/var/backups/lorkhanserver-code.4gZDUB`. All 746 runtime
  files match source with no extras or old paths. Private files return 403 on
  the three checked Apache ports; unauthenticated sessions return 401.
  Configuration, credentials and voice file hashes were preserved.
- Live PostgreSQL inspection confirms all four optional-field constraints now
  allow zero length while keeping their existing upper bounds. Live Oghma search
  for `dag` finds 19 topic/alias matches, including canonical `ash_vampires`
  through alias `Dagoth Ash Vampires`. Both deployed Add dialogs expose the
  expected required/optional fields; Category starts blank and Cancel works.
  No live article or item was created, edited or deleted for these checks, no
  speech provider was called, and no game was launched or controlled. Dynamic
  Oghma, its destructive controls and other incomplete matrix rows remain open.

## Biography batch, export and reset checkpoint

- Compared the pinned `npc_upload.php` batch section and its actual custom-table
  export/reset handlers. Restored the three-button Upload/Example/Export Custom
  NPCs order, visible Relationships/import/export guidance and Factory Reset
  control. Corrected the file picker to 55px, paragraph line-height to 20.88px
  with 10px bottom margins, and button-group margins to 15px above/below. Native
  OpenMW identity guidance replaces Skyrim underscore/FormID instructions.
- Full custom export now includes every global custom override plus portable
  templates for the selected installation, including extended fields and voice
  IDs. An explicit final `scope` column preserves global vs installation storage;
  global rows do not invent a content filename. Existing 16-column files and
  the example remain installation-scoped. Imports accept either header and
  restore both scopes atomically; duplicate or invalid rows cannot partially save.
- The restore probe exposed existing templates with blank Core text. Herika
  allows this field to be empty, so the shared Add/Edit fields and CSV/service
  validation now do too. Names and native OpenMW identity stay required. Blank
  Core values are preserved rather than replaced with generated text.
- Factory Reset deletes global custom overrides and soft-deletes the selected
  installation's portable biography templates. It preserves factory rows,
  instantiated NPCs, revision/history data, other installations and legacy
  fallback profiles without portable OpenMW identity. The dialog explains the
  global effect and backup requirement. Cancel is initially focused; Escape,
  backdrop close and Tab wrapping use the existing dialog behavior. The final
  destructive button is red, not the inherited green submit style.
- Browser fixtures covered populated, empty and no-installation states and
  desktop/narrow confirmation layout. At 390px all warning text and both buttons
  are visible with no horizontal document overflow; Cancel restores its trigger.
  No-installation state omits import/reset controls and disables Add.
- Existing HTTP tests cover complete mixed-scope CSV export/reset/restore, voice
  preservation, atomic rollback after a late invalid global row, missing reset
  confirmation, invalid CSRF, invalid installation and embedded redirect state.
  Form CSRF rejection uses the existing redirect to Home, unlike the JSON API's
  401 response; the test checks that redirect and unchanged backup data.
- The existing integration suite separately checks that resetting one installation
  preserves a live NPC's full content, another installation's template, factory
  count and legacy fallback profiles, then restores both exported scopes inside
  an isolated transaction. Upload-size and 1,000-row import bounds remain explicit.
  No live reset/import/export or game operation is used as a test fixture.
- Final checks passed: PHP lint, 98 protocol files, 370 server checks, management
  HTTP, integration vertical slice and migration/durable-job tests. Schema
  inventory remains 167 relations / 1,562 columns with its prior summary hash.
- Deployed server-only with rollback `/var/backups/lorkhanserver-code.YJoe9A`.
  All 746 runtime files match source; there are no extra files or old paths.
  Private files return 403 on all three checked ports; unauthenticated sessions
  return 401. Configuration, credential and voice contents remain unchanged.
- Live browser verification confirmed the Export Custom NPCs label, reset warning,
  initial Cancel focus and cancellation without submission. Add exposes optional
  Core while name, record ID and content file remain required. No live import,
  export, reset, profile save, speech-provider call or game action was performed.
  This closes the biography batch checkpoint, not the remaining all-pages goal.

## Oghma catalog maintenance checkpoint

- Replaced the disabled Delete All placeholder and primary factory-sync button
  with the reference's Delete All Entries / Factory Reset Database order. Routine
  sync remains available in a secondary details section. Matched the reference's
  36px toolbar buttons, 7px/12px padding and 700-weight 13.12px Futura typography.
  Final destructive confirmation retains a red button and initially focuses Cancel.
- Factory-entry editors now expose Delete, as the reference does. The confirmation
  removes the complete effective topic, including older shared custom/factory
  versions, rather than revealing an older article unexpectedly. Cancelling it
  restores the unchanged editor and focuses Delete; Escape/backdrop close and
  Tab wrapping use the same dialog lifecycle. Native scoped API deletion retains
  its existing separate override-removal contract.
- Delete All soft-deletes shared catalog records for the selected installation.
  Factory Reset removes custom shared entries and restores the active verified
  factory catalog. Both preserve profile/playthrough-scoped knowledge, immutable
  event history and other installations. No live maintenance is used for testing.
- Migration 091 stores canonical deleted topics separately from replaceable factory
  projections. Routine sync, installation provisioning and catalog replacement
  skip these topics; explicit Factory Reset clears the choices. Its downgrade
  refuses to discard saved deletion choices silently. No credentials, voices or
  game state are stored in the new table.
- Source-rendered populated/empty fixtures and the pinned Herika toolbar were
  compared. At 390px the full reset warning and both buttons fit with no horizontal
  document overflow; focus wraps between Cancel and the destructive submit.
  The native confirmation adds explicit ownership warnings absent from the
  reference's browser confirm. Dynamic Oghma remains a separate unfinished row.
- Validation passed: PHP lint, JavaScript syntax, 98 protocol files, 370 server
  checks, browser-like management HTTP, integration vertical slice and migration /
  durable-job tests. The maintenance regression checks reject invalid CSRF,
  confirmation, installation, action and profile-scoped document IDs; they exercise
  single factory-topic deletion, sync/provision persistence, Delete All and the
  full 3,741-article reset inside an isolated transaction. One initial assertion
  incorrectly used the active-only reader to inspect a deleted row; the corrected
  SQL assertion and final integration/migration run passed. Schema inventory now
  records 168 relations / 1,565 columns.
- Deployed server-only with rollback `/var/backups/lorkhanserver-code.CnXM5a`.
  All 748 runtime files match source; no extras or old paths remain. Private paths
  return 403 on the three checked ports and unauthenticated sessions return 401.
  Existing configuration, credential and voice file hashes are unchanged.
- Live Oghma still shows 3,741 articles. Opened its reset dialog, confirmed the
  warning and initial Cancel focus, then cancelled. The new deletion table has
  zero live rows. No live delete/reset/import, provider call or game operation
  was performed. The overall page-parity goal remains active.

## Dynamic Oghma UI and runtime — verified and deployed

- Added the real second tab, contextual header, paired upload/database panels,
  category filters, ten-column table, pagination and Add/Edit/Delete dialogs.
  Nine-column CSV matches Herika's quest/stage/topic patch fields. CRUD is scoped
  by installation, stale editor revisions fail, CSV upserts are atomic, and bulk
  deletion requires explicit confirmation. Blank preserves a field; `clearall`
  clears it. Deleting rules stops future changes without erasing story knowledge.
- Compared populated/empty/no-installation fixtures and the actual pinned Herika
  editor. The 800px editor, 42.156px fields, 119.422px textareas, Futura typography
  and 36px footer buttons match measured reference geometry. At 390px the header
  stacks and body scrolls; nested Delete cancellation preserves values and focus.
- Migration 092 stores rules and per-playthrough application receipts. Each rule
  revision applies once, after its immutable source is persisted. The Router uses
  a read-only frozen patch plan before prompt assembly and acceptance persists
  that same plan; neither replay nor repeated snapshots overwrite later edits.
  New topics and clearing advanced content are supported. Shared/factory records
  and other playthroughs remain untouched.
- A read-only live journal check caught a false assumption in the earlier plan:
  `id` is an oversized journal-line identifier, not a quest stage. The client fix
  matches it to the pinned OpenMW quest record's info ID and reads `questStage`.
  It adds optional `stage` to the existing bounded context; missing record data
  omits the stage. The server never interprets `id` as a stage. Standalone typed
  `gamedata.journal` is now accepted by the PHP validator as its schema specifies.
- NPC Oghma Knowledge resolves the active/last-played story by default, accepts a
  validated same-installation playthrough and retains it across filters/pages.
  This exposes applied story knowledge without mixing playthroughs. Biography
  previews and the regular catalog continue to use shared knowledge.
- Fixed an existing advanced-access mapping defect: native knowledge rows store
  `content`, but the access decision expected `topic_desc`. Prompt selection now
  uses the same mapping already used by the NPC reader. The new first-turn test
  caught this because its advanced article had no basic fallback.
- Runtime integration and migration tests passed before the final reader changes:
  first-prompt visibility, replay/repeated snapshots, clearall, new topics and
  playthrough isolation. The expanded final suite also covers typed journal
  validation and the scoped reader. Final full-suite/deployment results follow.
- Client: `D:/wt/lorkhan-journal-stages`, `codex/journal-stages`, based on current
  main `2275873`. All 67 Lua tests pass. The structural Python check's one failure
  is pre-existing: its three-panel literal omits the `settings=true` already on
  main. No engine code changed and no game was launched or controlled.
- The page-parity goal remains active. This is one checkpoint, not proof that
  every page or every outstanding editor state has reached parity.

- Final validation passed: PHP lint, 98 protocol files, 379 server checks,
  browser-like HTTP forms, integration, migrations and durable jobs. Schema:
  170 relations / 1,587 columns, inventory hash
  `b8099463e3d26547c1467feef76847ede606e1c49b3135ec6d760ecdc878b795`.
  The preview also retains higher-priority NPC-specific story overrides, matching
  the SQL resolver used after persistence.
- Deployed server-only with rollback `/var/backups/lorkhanserver-code.lgY3Ce`.
  All 752 runtime files match source; no extras or old paths remain. Private files
  return 403 on all three checked ports; unauthenticated sessions return 401.
  Existing configuration, credential and voice file hashes remain unchanged.
- Live Dynamic Oghma opens directly with the correct selected tab and empty table.
  Opened Add and cancelled; initial focus was Quest ID. No live rules or
  application receipts were created (both counts remain zero). The NPC reader
  shows the current playthrough, and a read-only search retains that scope in
  filter/navigation links. No provider or game operation was performed.
- Client commit `7925957b94ff8759a8decc7c87ef7e9456539de5` is pushed to main.
  The deployed adapter matched the old main blob before replacement; only that
  Lua file was copied to `C:/Modlists/LORKHAN/Data/scripts/LORKHAN/adapters/openmw.lua`.
  SHA-256: `a161992b70ea8daca2229fe70f097b09c1c267327f1093ddd9e4adb51850f4c3`.
  Backup: `C:/Users/reece/AppData/Local/Temp/lorkhan-journal-stage-backup-7925957.lua`.
  A normal game restart loads the new Lua; no engine rebuild or game launch occurred.

### Embedded settings and Profile Slots checkpoint

- Compared the actual Configuration hub and its Oghma, Profiles, Global Settings,
  Player and Narration entry views against pinned Herika `529364c`. The Oghma
  iframe geometry is identical at 1280 × 720: x=12, y=189.234375, width=1256,
  height=520.765625. This is entry-view evidence, not a pass for every child state.
- Profiles now puts Profile Slots inside the same scrolling list as the cards.
  Removed the additional sidebar disclosure that displaced the reference layout;
  the settings-only portability warning remains beside the Import fields and in
  Import/Export help. The larger profile import/export semantic gap is still open.
- Assigned slots are native keyboard-accessible links to the corresponding editor,
  preserving installation and embed query parameters. Empty slots are dimmed to
  the reference opacity, and anchor hover/focus states remain visible. Clicked the
  deployed Default slot and verified the matching editor and Save All control;
  no profile was saved. Toolbar anchor margins and the information marker font
  now match the reference button and span metrics.
- Player and Narration inherited a 1.5 line-height unlike Herika's normal line
  height. Labels measured 19.6875px versus 15px, despite identical font sizes.
  Scoped the corrected inheritance to those pages. Restored the reference toggle
  row box model so its 38px content minimum includes the same padding/border.
  Provider choices, identity, stored settings and event semantics are unchanged.
- Rendered the actual hub markup and JavaScript with existing synthetic Player
  and Narration forms. Unsaved Player Diary changed from unchecked to checked
  and stayed checked through Narration and Global Settings. Unsaved Narrator
  enablement changed from checked to unchecked and stayed unchecked through
  Player and Profiles. No form submission, provider call or live write occurred.
  The browser's iframe text-fill operation failed; checkbox state was verified
  directly instead, and text-entry retention is not claimed from that failed call.
- Responsive browser override calls did not alter the measured 1280px viewport.
  Used explicit 390 × 844 iframe documents for the narrow comparison instead.
  Lorkhan correctly disallows cross-origin framing, so its side uses source-
  rendered fixture markup and a current Profiles response with CSRF values and
  scripts removed. Security headers were not changed. Narrow Player cards and
  toolbar wrap without horizontal overflow (344px child client/scroll width).
  Profiles stacks its editor at this width while Herika retains a horizontal
  sidebar/editor. Full narrow hub/child-state parity remains open.
- Existing PHP lint, 98-file protocol manifest, 379 server checks, browser-like
  management HTTP forms, integration, migrations and durable job tests passed.
  Schema remains 170 relations / 1,587 columns with hash
  `b8099463e3d26547c1467feef76847ede606e1c49b3135ec6d760ecdc878b795`.
- Local server deployed; configuration, credential and voice hashes preserved.
  Final deployment/hash verification follows this checkpoint. No client changes,
  game launch or game interaction were needed. The all-pages goal remains active.
- Final live measurements: Profile Slots top=180.984375px, height=165px and
  title height=29px in both products; Player labels=15px and toggle rows=54px.
  Final rollback: `/var/backups/lorkhanserver-code.tJ392E`. All 752 runtime files
  match source, with no extra files or old paths. Private files return 403 on
  8090/8088/8083, unauthenticated sessions return 401 and health is valid.

### OpenRouter model catalogue checkpoint

- Ported the model-field dropdown and information panel from pinned Herika
  `529364c`, retaining its dark header, list rows, pricing/context details and
  selected-model recap. Manual model entry and the existing explicit Save form
  remain authoritative; selecting a catalogue entry only edits that form's model
  value and dispatches its normal input/change events.
- Opens only for the direct OpenRouter chat-completions endpoint. Configured,
  mock and other direct services retain an ordinary text field. No discovery is
  performed on page load. The browser uses an authenticated same-origin route with a
  ten-second timeout, shared pending request and cached successful results. The
  server reads one fixed public URL without API credentials or redirects; TLS
  verification stays enabled, with an eight-second timeout and 8 MiB response cap.
  Errors permit manual IDs and later retries.
  No account keys, model prompts or other connector settings enter this request.
- Added Arrow Up/Down, Enter and Escape with combobox/listbox semantics, preserving
  manual focus. Closing the list or changing service cannot be undone by a late
  catalogue response. Provider strings are text nodes, never HTML or link targets.
  Catalogue payloads over 5,000 entries are rejected, and model IDs keep the
  existing 256-character form limit. Missing prices/context are not invented.
- Compared the actual source editor against the exact reference dropdown script
  and styles in isolated fixtures. Verified three-row population, ID/name search,
  no matches, mouse selection, both keyboard directions, information panel,
  zero-price display, malformed/missing prices and literal markup-like names.
  The markup fixture created no image elements. Catalogue requests were zero on
  initial load and one after repeated cached use; switching to OpenAI restored
  ordinary textbox semantics and did not issue another catalogue request.
- Failure fixture retained a manually entered model ID. A delayed response stayed
  closed after Escape. The initial fixture had a JavaScript syntax error in its
  fetch stub, so its first discovery reached the real public OpenRouter catalogue
  successfully; no credentials or paid inference were sent. Corrected the fixture
  before asserting mocked-data results. No live connector was saved.
- The narrow fixture caught a scrollbar-width error in the first implementation.
  Positioning now uses the document client width, not window innerWidth. At a
  390px frame with a scrollbar, the popup fits x=8..367 in a 375px client/scroll
  width, with no horizontal overflow. Desktop uses the reference content-based
  minimum width; small screens clamp that width rather than clipping options.
- Other service catalogues (including Groq), provider preference, additional
  request controls and full connector import/editor parity remain open. This
  completes the OpenRouter discovery workflow, not the entire LLM page or goal.
- The first live-page probe exposed its strict `connect-src self` policy, which
  correctly blocked direct browser discovery. Added authenticated GET
  `/manage/api/v1/llm-models` rather than relaxing CSP. The endpoint accepts no
  query parameters or provider URL, cannot attach stored provider keys, and
  projects only bounded model IDs, names, descriptions, prices and context.
  Added four existing-suite checks for Unicode bounds, free/invalid prices,
  unknown-field removal, empty payloads and catalogue size/shape rejection.
  The HTTP suite checks missing-session rejection and rejects caller-supplied
  URLs before any network request. Final tests and live proxy evidence follow.
- Final validation passed: JavaScript syntax, PHP lint, 98 protocol files,
  383 server checks, management HTTP forms (including the new session/query
  guards), integration, migrations and durable jobs. No schema change.
- Deployed with rollback `/var/backups/lorkhanserver-code.AbUycS`. All 752 runtime
  files match source, with no extra files or old paths. Configuration, credential
  and voice hashes are preserved. Live unauthenticated catalogue access returns
  401, and the page still uses `connect-src self`.
- Live authenticated discovery returned 430 model choices through the proxy.
  Filtering selected exactly one `deepseek/deepseek-chat-v3-0324` entry; clicking
  it populated the existing Model input and its pricing/context panel and closed
  the dropdown. No connector was saved and no inference or game operation ran.
  The empty-catalogue fixture also rendered No matches without disabling manual
  input. The broader page-parity goal remains active.

### LLM Provider preference and catalogue checkpoint

- Added the Provider field directly below Model with Herika's label and empty
  placeholder. OpenRouter gets the same provider-slug/name rows, Privacy/TOS
  metadata, dropdown header, note, borders, padding and typography. Custom
  compatible endpoints retain manual entry; incompatible services hide and
  disable the field without clearing its unsaved value. Configured OpenRouter
  runtimes now get both catalogues without exposing their endpoint or credential.
- The ordered, comma-separated form value is stored as typed `provider_order`
  and mapped to `provider.order` by the existing shared request-options path.
  Save/export/import, clearing the preference and a real local HTTP adapter were
  checked in the existing management suite. Empty direct preferences emit no
  provider hint. Configured connectors retain the existing default/inheritance
  rules. Fallback providers remain enabled, as in Herika's basic Provider field.
- Deliberate correction to the pinned Herika picker: a model publisher prefix
  is not assumed to be its hosting provider. The catalogue lists actual provider
  slugs and model edits never overwrite a user's explicit preference. This uses
  the documented OpenRouter `provider.order` contract:
  https://openrouter.ai/docs/guides/routing/provider-selection .
- `/manage/api/v1/llm-providers` shares the fixed, public, credential-free proxy
  with model discovery. Authentication and query rejection remain mandatory;
  no user URL, redirect, key or relaxation of `connect-src self` is involved.
  Slugs and public display fields are bounded; provider text creates no markup
  or navigable links. Existing tests cover malformed/oversized catalogues and
  preferences, trimming, ordered mapping and absence of raw option keys on wire.
- Visual evidence: captured the exact pinned provider-picker fixture and the
  deployed editor. Live Herika and Lorkhan both inherit 15px/22.5px Futura
  CondensedLight; headers, rows and metadata use the same spacing and sizes.
  At a real 390px iframe viewport the dropdown spans x=8..367 in a 375px client
  width, with no horizontal overflow. Keyboard filtering/selection, cached
  discovery, model edits, service round trips, literal markup/URL text, empty
  results, failed lookup/manual entry and delayed Escape dismissal were checked.
  Fixture-only missing service icons are not evidence of a live asset problem;
  the deployed screenshot shows all service icons loaded.
- JavaScript syntax, PHP lint, 98 protocol files, 395 server checks, management
  HTTP, integration, migrations and durable-job checks passed. Schema inventory
  stayed at 170 relations with hash
  `b8099463e3d26547c1467feef76847ede606e1c49b3135ec6d760ecdc878b795`.
- Deployed with rollback `/var/backups/lorkhanserver-code.VNxnQQ`; all 752 runtime
  files match, with no extra files or old paths. Private paths remain 403,
  unauthenticated sessions 401, health valid. Configuration, credential and
  voice-file hashes are preserved. Live public discovery returned 106 providers;
  filtering and selecting Together populated the field and closed the picker.
  No live connector was saved, no paid inference ran, and the game was untouched.
- This closes the Provider preference gap only. Other service catalogues,
  request controls, import-editor presentation and the wider page matrix remain
  open; this is not a claim of full LLM or whole-site parity.

### LLM API-key selection and help checkpoint

- Replaced the undifferentiated key list with Herika's alphabetical configured
  keys first, green status markers, disabled Missing Key divider and red No key
  labels. Credential references and defaults are unchanged. The existing
  CredentialStore status projection supplies availability; no key value enters
  the HTML or changes because of this work.
- Matched the compact 12px/18px Futura notice with 6px top spacing and Herika's
  warning colour. Missing selection and missing stored key have explicit notices;
  selecting a configured key clears the previous notice. This fixes the pinned
  reference script's stale warning text after a selection change. Configured
  means stored/environment-present, not provider-validated.
- Existing HTTP coverage now checks missing-key markup, configured-first order,
  selected key retention and non-exposure of the disposable test key. PHP lint,
  JavaScript syntax, diff whitespace and the management HTTP suite passed. The
  preceding 395-check/full integration result still covers the unchanged backend;
  the later change is confined to the editor and its presentation.
- Compared the live Herika key selector and deployed Lorkhan form. Checked missing,
  no-key and configured selections without saving. At 390px, the warning spans
  x=34..341 inside a 375px client width with no horizontal overflow. The visual
  check exposed simultaneous hover/focus tooltips; focused controls now take
  precedence. Narrow fixture and deployed keyboard checks show one tooltip;
  Escape dismisses it and Shift+Tab restores the prior control's help.
- Final deployment rollback: `/var/backups/lorkhanserver-code.BGXV06`. All 752
  runtime files match source; no extra files or old paths. Configuration, key and
  voice hashes are unchanged; private paths remain 403, unauthenticated sessions
  401 and health valid. No game, live save or paid inference operation ran.

### LLM common editor structure and request-switch checkpoint

- Removed the prominent Direct connection panel and moved its common API Key
  field directly below Provider. Endpoint URL appears above Model for Custom
  endpoints; fixed service endpoints remain unchanged submitted values. Runtime
  service identity is displayed from a bounded public service code, without
  rendering the inherited endpoint or credential. Choosing a service still only
  edits the form until Save.
- Reordered common switches to Reasoning Model Fix, Enforce JSON and Disable
  Streaming, with the pinned Herika checkbox presentation. The existing native
  three-state selects remain the no-JavaScript fallback and submitted controls.
  Enhancement leaves untouched values blank; checking Disable Streaming maps
  to `option_stream=false`. Runtime and direct defaults are displayed correctly
  without materializing overrides. Reset request switches restores inheritance.
- Moved Mode, inherited connection explanation, timeout, Disable reasoning,
  endpoint rules and mock prefix into Connection options. The alternative
  Max completion tokens field is also secondary, while Max Tokens and Temperature
  head the right column as in Herika. Existing alternative-token connectors open
  those options automatically. These controls retain native capabilities; they
  do not claim that missing Herika request controls are implemented.
- Actual reference/source desktop measurements now match: 440px/411px columns
  with a 16px gap, 56px service icons, 13.44px/20.16px switch labels, 8px/6px label
  margins, 22.15625px label boxes and 28.8px scaled checkboxes. A global 2px button
  margin was shrinking icons to 52px; the service row now explicitly removes it
  and matches the reference's 8px corner radius. Sampling labels use Herika's
  220px column where space permits, and the editor stacks at 1000px.
- Intermediate-width review caught a 26px numeric input at a 1120px viewport.
  The label column now yields enough width to retain a 100px input there. At
  960px the editor stacks, and at 390px the input remains 101px wide. Client and
  scroll widths match (945/945, 1105/1105 and 375/375 respectively). This responsive
  guard preserves usable inputs rather than reproducing reference compression.
- Isolated browser checks covered inherited defaults, explicit false values,
  model/service and configured/direct/mock round trips, inactive-field disabling,
  reset to actual runtime/direct defaults, exact custom endpoint preservation and
  the existing two-token-limit conflict. The deployed create form still has four
  blank submitted switch values, hidden fixed-service endpoint and loaded icons;
  its live desktop columns match the reference. No live form was saved.
- PHP lint, JavaScript syntax, diff whitespace, all 395 server checks and the
  final management HTTP suite passed. Existing HTTP coverage also verifies switch
  ordering, inverted stream metadata and automatic opening of alternative-token
  controls. Final deployment rollback is `/var/backups/lorkhanserver-code.yIhehf`.
  All 752 runtime files match source, with no extra files or old paths; configuration,
  credentials and voice hashes remain unchanged. Private paths return 403,
  unauthenticated sessions 401 and health is valid. The game was untouched.
- The LLM page remains open for the specifically named missing controls/catalogues
  in the matrix. Neither this page nor the whole-site goal is marked complete.

### LLM JSON Schema and Prefill JSON checkpoint

- Added JSON Schema and Prefill JSON in the reference order between Enforce JSON
  and Disable Streaming. Both retain native three-state fallback and runtime
  inheritance; existing connectors do not acquire new overrides. Hover/focus help
  explains provider support requirements. JSON Schema is sent only while Enforce
  JSON is on; turning it off does not erase the stored schema preference.
- Traced pinned Herika `connector/openrouterjson.php` and
  `functions/json_response.php` before adapting request behavior. Dialogue schemas
  contain only negotiated actions and exact utterance objects. Profile, diary,
  memory, evolution, player chat, relationship and Oghma jobs retain their own
  contracts. Relationship evaluation uses an empty type for no change, which the
  existing RelationshipType policy already accepts. Runtime validators still run.
- Prefill appends an assistant continuation to actual requests. Final decoding
  accepts either continuation or full JSON without duplicating the prefix; raw
  sentence streaming is unchanged. Relationship request auditing accepts only a
  bounded trailing prefill after system/user messages and retains its active-attempt
  fence. No transport options or credentials enter those recorded messages.
- Reference Remove Action Prompt is saved metadata in the pinned editor only:
  a repository-wide PHP search finds no runtime reader. No inert control was added,
  and Lorkhan's negotiated action contract was not removed to simulate one.
- Compared the reference lower editor and deployed create form visually. New
  labels retain 13.44px/20.16px typography, 8px/6px vertical margins and 28.8px
  checkbox boxes. Isolated browser checks covered adjacent mouse toggles, keyboard
  Space/Tab, configured/direct/mock round trips, disabled mock controls, reset to
  inheritance and a 390px frame (375px content/scroll width). The rendered narrow
  controls were inspected, not inferred from overflow measurements. Fixture icon
  images are absent; the actual deployed service icons load.
- Browser testing found help reappearing over the next switch after pointer exit
  from a focused toggle. Completed toggles now dismiss help until focus leaves,
  fixing adjacent clicks while retaining hover/focus help and Escape dismissal.
- Final checks: 403 server checks, PHP lint, JavaScript syntax, management HTTP
  forms, integration vertical slice, migrations and durable jobs passed. The HTTP
  suite uses local mocks for all adapters, streamed/buffered continuation and full
  responses, schema gating, saved/exported switches and invalid-output rejection.
  Schema inventory remains 170 relations with the existing summary hash.
- Final code deployment rollback: `/var/backups/lorkhanserver-code.mREK5J`.
  Configuration, credential and voice file hashes were preserved. No live form
  saves, paid inference requests, game launch or game control were performed.
  This is an LLM-editor checkpoint, not completion of the full page matrix.

### LLM direct import and advanced reset checkpoint

- The sidebar Import action now opens a file picker and processes selected
  connector files, following pinned Herika's direct import flow. The existing
  paste/import page remains the link fallback without JavaScript. Portable
  Lorkhan JSON remains the data format; Herika's CSV is not presented as a
  compatible Lorkhan slot. Imports still use the existing authenticated,
  credential-stripping endpoint and never call an LLM.
- Up to 20 files of 1 MiB each are read and JSON/schema-identifier checked before
  the first request. Server validation remains authoritative. Imports are
  sequential; a rejected or unconfirmed response stops the remaining requests.
  Partial successes report their confirmed count. Lost responses/timeouts are
  not retried because the server might already have committed the import.
- Clear advanced settings matches the reference's seven sampling fields. It
  blanks their submitted values and moves sliders to their minimum without
  changing Temperature, Max Tokens, connection options or request switches.
  Clearing edits the form only; Save is still required. Keyboard and pointer
  use were exercised. Field help now dismisses after editing a focused input,
  preventing the Top a tooltip from intercepting the reset button.
- Actual browser file-selection fixtures covered two successful imports,
  malformed JSON, wrong schema, oversized/count-limited selections, partial
  failure, lost response, missing saved receipt and a literal HTML-like filename.
  Requests were mocked and redirects stayed in the fixture. Malformed selections
  made zero requests; a lost reply made exactly one with no retry. Both successful
  requests retained the selected installation. No live connector was imported.
- Desktop reset checks preserved Temperature 0.7 and Max Tokens 750 while all
  seven overrides became blank. A 390px frame displayed wrapped error text and
  the connector sidebar without horizontal overflow (375px content/scroll width);
  the error state was inspected in a screenshot, with literal filename text and
  no injected image. Existing fixture service icons remain absent, separate from
  the live server's loaded assets.
- Current pinned editor source has OpenRouter and Groq model pickers. OpenRouter
  is implemented; the matrix now names Groq specifically instead of implying
  catalogues for every other service. YAML body controls also remain unfinished.
- Final checks passed: PHP lint, JavaScript syntax, 403 server checks, and the
  management HTTP suite including a real multipart import into its disposable
  database, saved redirect receipt and credential-stripping assertions. This UI
  checkpoint makes no backend/schema changes; the previous checkpoint's full
  integration/migration evidence is not claimed as a fresh run here.
- Final local deployment rollback is `/var/backups/lorkhanserver-code.fLsnne`.
  All 752 runtime files match source with no extras or legacy paths. Existing
  configuration, credentials and voice hashes are unchanged; private routes
  remain 403, unauthenticated session creation 401 and health is valid. The live
  create form was inspected: seven service icons load, the multi-file picker is
  hidden until invoked, the reset button is visible, and all six stored switch
  values remain blank/inherited. No live import, paid inference or game action
  was performed. Full-page parity remains open in the matrix.

### Groq model catalogue checkpoint

- Added the pinned Herika Groq dropdown presentation: model ID, owner/context
  rows, selection hint, filtering and keyboard selection. Uses the existing
  catalogue styles, with Lorkhan gold accents. Screenshots compared the actual
  source dropdown with the pinned standalone reference script using mock data.
  The 390px fixture also displayed all rows without clipping the dropdown.
- Groq discovery uses a management-session and CSRF-protected POST. The browser
  sends only the selected credential reference. Server credential resolution is
  shared with the provider factory; runtime discovery requires the configured
  endpoint to be Groq. The outbound URL is fixed, HTTPS verified, redirects
  disabled, time/body limits bounded, and only public model fields returned.
  Arbitrary endpoints, environment-variable names and malformed requests are
  rejected. Upstream failures never return credentials or raw upstream bodies.
- Browser fixtures covered direct and configured-runtime credentials, changed
  key references, no-key selection, empty results, loading, provider failure,
  manual entry after failure and keyboard selection. Switching to OpenRouter
  during a delayed Groq response retained the OpenRouter results. Model and
  provider selection were regression checked. All provider requests were mocked;
  no live credentials, saves or paid provider calls were used.
- Literal HTML-like owner text renders as text with zero injected images. The
  same hostile fixture exposed an alert in the pinned reference implementation;
  that behavior was not copied. A fresh safe reference fixture was used for the
  visual comparison. Missing service icons in isolated fixtures are not live
  asset failures.
- PHP lint, JavaScript syntax, 411 server checks, management HTTP, full integration,
  migrations and durable-job checks passed. The 170-relation schema inventory is
  unchanged. Local deployment rollback: `/var/backups/lorkhanserver-code.wRmT65`.
  All 752 deployed runtime files match source, with no extras or legacy paths.
  Configuration, credentials and voice hashes were preserved. Private routes
  remain 403, unauthenticated session creation 401, health valid and NPC reader
  statuses 200/404. No game was started or controlled.
- This closes the Groq picker gap only. YAML controls and the other open matrix
  rows remain; this is not full-page or whole-goal completion.

### YAML body-parameter editor checkpoint

- Added Include Body Parameters (YAML), Enable YAML Body Parameters and the
  counterpart 120px Ace/Ambiance editor below the seven sampling controls.
  Compared populated and empty source panels with the pinned reference's editor
  markup/theme, plus a 390px source frame. The editor retains line numbers,
  syntax highlighting, internal scrolling and its separate enable switch.
  Escape moves focus to Clear advanced settings; that reset only clears sampling
  fields, preserving YAML and its switch. Mock/direct/runtime switching preserves
  unsaved YAML; the isolated submit receipt confirmed the edited value and switch.
- Ace is bundled locally at the reference's 1.23.4 version, with its BSD license.
  Its unmodified editor/theme CSS is linked as a local file, and strict-CSP mode
  prevents injected styles. Live default editor rendering passed with the existing
  content-security policy unchanged. If Ace is unavailable, the labelled native
  textarea remains editable and submits through the same server validation.
- The raw YAML and enable preference use the existing revisioned connector options
  and portable exports. Off is the default and disabled YAML is retained, not sent.
  An untouched absent YAML override stays absent; editing and clearing explicitly
  stores an empty override. Existing configured-runtime inheritance is preserved.
- Enabled parameters feed the shared request builder for dialogue, profile jobs
  and Oghma extraction. They replace sampling/provider body values before Enforce
  JSON and Disable reasoning, which retain precedence. YAML cannot replace native
  messages, model, stream, tools/actions, response count/audio, credentials or
  transport controls. These protect the existing OpenMW audited response/delivery
  contract, rather than creating unsupported alternate provider pipelines.
- Symfony Yaml 7.4.18 is bundled with its MIT license and deprecation dependency;
  native ext-ctype is declared. Parsing is bounded to 16 KiB, 16 nesting levels
  and 1,024 nodes. Unsafe tags, aliases, non-finite values, invalid root types,
  duplicate keys and protected parameter names are rejected without exposing the
  input in an error. Comments, quoted commas, nested maps/lists and empty maps are
  preserved; HTML-like values remain literal text in the editor.
- Browser fixtures made no live writes or paid provider calls. Existing HTTP
  tests exercise actual requests against their disposable local provider, not a
  paid service. No game was launched or controlled. This checkpoint does not close
  the overall page matrix; final full-editor/hub comparison and other rows remain.
- Final verification: PHP lint, JavaScript syntax, 434 server checks, management
  HTTP (including dialogue/profile/Oghma wire assertions, failed-save preservation
  and credential-stripped YAML import), integration, migrations and durable jobs
  passed. Schema inventory remains 170 relations with unchanged summary hash.
  Final deployment rollback: `/var/backups/lorkhanserver-code.r6ly9V`. All 779
  runtime files match source with no extras or legacy paths; configuration,
  credentials and voice hashes are unchanged. Private routes remain 403,
  unauthenticated session creation 401, health valid and NPC reader states 200/404.
  Live final GET confirmed a 120px editor, disabled default, loaded service icons
  and Escape focus transfer. No live form was saved or provider called.
- Git whitespace warnings are confined to unchanged upstream Ace assets and their extracted CSS; authored product changes pass the whitespace check.

### TTS API Badge checkpoint

- Replaced the disabled badge placeholder with the pinned Herika selector:
  configured-first choices, green/red status markers, Missing Key divider,
  identical configured/missing/none notices and cloud-provider visibility.
  Compared the live Herika Inworld editor without saving it. API Badge now sits
  on the left under Name, as in the reference. Local URLs remain in the primary
  grid; cloud URLs move into the existing Advanced connector options section,
  preserving the one submitted endpoint field and custom endpoint support.
- The browser remembers unsaved badge choices per service, initializes the proper
  default even if the service changes before deferred initialization, and makes
  no discovery or provider request when selecting a badge. Desktop configured,
  missing and None states, local/cloud round trips, isolated submission and 390px
  layout were checked. The narrow frame had 375px content/scroll width and a
  fully visible warning. A final live saved Inworld GET retained its configured
  default badge and used the advanced URL location; no live form was saved.
- Speech connector validation now accepts the same allowlisted server credential
  references as STT. Existing connectors without a reference still resolve the
  same provider default. Explicit None sends no key and does not fall back.
  ProviderFactory passes the selection into speech and Inworld/Cartesia discovery,
  cloning and keyed sample-cache resolution. TTS Studio's standalone global
  provider-library operations keep their existing global-account behavior.
- CRUD, rename and rollback secret guards permit only the typed top-level
  credential reference; nested credentials and raw keys remain rejected. Same-
  installation cloning and private configuration backups retain the reference,
  not its key value. Portable TTS export/import strips the reference to None,
  including supplied import references, so imports cannot acquire local keys.
- Existing tests cover all nine key-backed cloud synthesis adapters, selected-key
  clone/discovery headers, cache separation, None refusing global fallback and
  unrelated environment-variable rejection. HTTP checks cover saved selection,
  configured-first ordering, clone preservation, import stripping, rejected
  edits and private backup reference retention with secret-value exclusion.
  A former backup assertion rejected the public name's API_KEY suffix; it now
  distinguishes a credential reference from an actual key field/value.
- Updated the feature registry to mark the implemented TTS badge and the already
  deployed JSON Schema/Prefill/YAML controls live rather than planned/replaced.
  Provider-specific field mapping is still open; this is not whole-page parity.
- Final verification passed: PHP lint, JavaScript syntax, 449 server checks,
  management HTTP, integration, migrations and durable jobs. Schema inventory is
  unchanged at 170 relations. Deployment rollback is
  `/var/backups/lorkhanserver-code.v1n3w4`; all 779 runtime files match source,
  with no extras or legacy paths. Configuration, credential and voice hashes are
  preserved, private routes remain 403, session creation without auth is 401 and
  health/NPC reader checks passed. Browser fixtures and local mock transports
  were used for interaction/provider tests; no paid service or game was called.


### TTS provider-field structure and Inworld workspace checkpoint

- Replaced the shared Model / Default Voice / Language / Timeout grid with
  provider-specific primary grids. Inworld follows Workspace/Language,
  Model Id/Temperature, then Speed; PocketTTS shows Model; Cartesia follows
  Language/Model Id, then Speed. Labels, choices, descriptions and section
  titles derive from pinned Herika `529364c` and map to native typed fields.
- Existing extra runtime controls, default voices, timeouts and raw options are
  retained under Advanced. Existing custom model/language values are retained
  as selected choices rather than silently replaced. No saved connector was
  migrated or edited during verification; the live Inworld connector retains
  its saved `inworld-tts-1` model.
- Each service owns its unsaved field draft. Browser switching across all 22
  supported services proved exactly one active group, no duplicate enabled
  field names and one enabled URL. Inworld workspace/model/language and Cartesia
  model drafts survived switching away and back. Endpoint and badge drafts
  remain per-service. Only active fields participate in submission.
- Added the Inworld Workspace field with strict ID normalization. Discovery
  ignores another workspace's voices, cloning uses the selected workspace path,
  and cache identity includes workspace plus the already selected credential.
  Blank workspace preserves the existing account-default route/cache. Explicit
  provider voice IDs remain explicit; no existing clone is deleted or changed.
- Existing tests cover wrong-workspace discovery, selected-workspace clone
  routing, cache reuse/separation and invalid path/URL/non-string rejection.
  Management HTTP verifies normalization, custom model/language round trips,
  rejected edits and driver switching. All provider calls use mock transports.
- Visually compared Inworld and PocketTTS against live Herika and the pinned
  source. Compared Cartesia field order/choices and corrected its help text to
  the pinned counterpart. Narrow Inworld at 390px has 375px content/scroll width,
  readable wrapped help and stacked controls. These are scoped comparisons,
  not proof that every remaining TTS provider is complete.

Remaining TTS provider work is concrete, not a product exception:

| Provider | Missing counterpart controls / review |
| --- | --- |
| Chatterbox, XTTS | Paralinguistic enable/prompt/list controls, selected-connector prompt wiring and case-insensitive speech filtering implemented and compared below; live expressiveness remains untested |
| OmniVoice | Prepared-language selector, preparation workflow and language library states |
| OpenAI | Instructions editor/request mapping completed below; automatic mood-to-instructions routing is still absent from the native speech context |
| ElevenLabs | Editor controls and model-specific request mapping completed below; live provider synthesis remains untested |
| Azure | Fixed mood, region, volume, rate and contour now have matching primary fields and native SSML routing. Automatic mood/Validmoods remains open with the shared speech-style audit |
| Mimic3 | Primary Rate/default/help and paired desktop review completed. Reference Volume is only consumed by legacy `ttsMimicOld`, not the active provider, so an inert control is not copied |
| Kokoro | Speed editor/request mapping completed below; native endpoint and effective speed defaults preserved |
| Deepgram | Bitrate/sample-rate field and native WAV request mapping implemented and visually compared below; no live synthesis claimed |
| Zonos | All 109 reference language choices and field labels/help implemented and compared; dynamic tones, cache-path ownership and full defaults remain open |
| xVASynth, Piper, Melo | Primary labels/defaults/help and paired desktop review completed; narrow layouts and preserved provider drafts checked. Native optional values and Morrowind routing retained; no live provider synthesis claimed |
| Native extra drivers | Keep supported XTTS/Coqui/Convai/GCP/StyleTTS behavior; finish presentation review against applicable reference schemas |

The full page matrix and goal remain open. No game, live TTS request, paid
provider operation or live settings save was performed in this checkpoint.

Verification: 455 server checks, PHP lint, JavaScript syntax, management HTTP,
integration, migrations and durable jobs passed. Schema stays at 170 relations
with the existing inventory hash. Final local deployment rollback is
`/var/backups/lorkhanserver-code.qECBfs`; all 780 runtime files match source,
with no extra or legacy paths. Persistent configuration, credential and voice
hashes are unchanged. Private routes return 403, unauthenticated session
creation returns 401, and health/NPC-reader probes pass. The final Cartesia
screenshot includes the full reference help text and the native effective
`normal` speed default rather than replacing it with an unsaved form's first
choice. No provider synthesis was invoked.


### OpenAI, ElevenLabs and Kokoro TTS controls checkpoint

- Added OpenAI Instructions beside Model Id, with Herika's textarea, help text
  and model choices. The saved text reaches gpt-4o-mini-tts; tts-1 and tts-1-hd
  omit it. Kokoro now exposes the reference Speed field and sends its value in
  the WAV request. Other OpenAI-compatible adapters receive no new options.
- ElevenLabs now follows all ten visible reference fields in their original
  two-column order: latency/model, stability/similarity, style/speed,
  speaker boost/text normalization, language normalization/v3 audio tags.
  Labels/help, Enabled/Disabled choices and the multiline textarea match the
  pinned schema. New-service drafts use the reference voice-setting defaults;
  existing connector values and the native model default are not migrated.
- Latency goes into the query, speed into voice_settings, normalization into
  the request body, and v3 tags only prefix eleven_v3 speech. V3 omits speaker
  boost like Herika. No event/subtitle text is rewritten. WAV output remains
  fixed and tags cannot expand the native request past its text bound.
- Added typed validation for newly exposed options across forms, API revisions
  and imports. Instructions are bounded to 4096 bytes and v3 tags to 1024;
  invalid ranges, enums and boolean/string substitutions are rejected.
- Existing HTTP fixtures now inspect actual OpenAI/Kokoro requests and WAV
  responses through the factory, without credentials or remote calls. They
  also save/reload multiline instructions longer than 512 bytes, reject an
  oversized edit, round-trip ElevenLabs options and clear inactive-driver
  options. The first run exposed a test-parser limitation: its ordinary form
  reader ignored textareas. The test now uses its existing external-form
  textarea reader; the saved UI and provider code were not bypassed.
- Browser comparisons used live Herika unsaved forms and an isolated Lorkhan
  fixture: empty OpenAI and populated ElevenLabs screenshots, full ElevenLabs
  counterpart screenshot, and paired Kokoro screenshots. Instructions survive
  switching away and back. ElevenLabs at 390px has 375px content/scroll width,
  readable wrapped help, a 335px textarea, and keyboard Tab reaches Advanced.
- Source audit identified a separate remaining speech-style gap: native
  speechContext currently carries voice/language, not Herika's automatic mood
  prefix. The explicit Instructions field works; automatic emotional routing
  must not be inferred from that. This remains with the broader speech-style
  audit and is not presented as a completed behavior.
- Current official ElevenLabs documentation still lists latency optimization as
  deprecated and optional. No latency improvement is claimed; tuning remains
  user-selected. Reference: https://elevenlabs.io/docs/api-reference/text-to-speech/convert .

467 server checks, PHP lint, JavaScript syntax, management HTTP, integration,
migration and durable-job checks passed. Schema remains 170 relations with its
existing inventory hash. No game, live provider test, user configuration edit
or credential change was performed. Whole-page and whole-site parity remain
open, including the other provider rows in the preceding table.

Final deployment rollback: `/var/backups/lorkhanserver-code.IycZiR`. All 780
runtime files match source, with no extra or legacy paths. Configuration,
credential and voice hashes are preserved. Private routes remain 403,
unauthenticated session creation is 401, and health/NPC-reader probes passed.

## Azure provider controls checkpoint

Azure now follows the reference's Fixedmood/Region, Volume/Rate and Countour
primary field ordering. New connector defaults match the reference; existing
connectors with absent settings retain unstyled speech. Explicit regions are
normalized to a Microsoft speech hostname before the provider host policy runs;
blank region preserves the advanced endpoint. XML text and attribute values
are escaped, with fixed style and prosody only emitted when configured.
Microsoft's SSML reference was checked:
https://learn.microsoft.com/en-us/azure/ai-services/speech-service/speech-synthesis-markup-voice .

The reference's Mimic3 Volume setting is consumed only by `ttsMimicOld`;
its active TTS closure uses plain text and does not apply Volume. It is not
copied as an inert control. Azure Validmoods is still outstanding with the
shared automatic speech-style routing gap; this checkpoint does not claim
complete Azure, TTS-editor or whole-site parity.

474 server checks, PHP lint, management HTTP save/reload and invalid-region
checks, integration, migrations and durable-job checks passed. The schema
inventory remains 170 relations with the existing hash. Paired desktop
screenshots show the matching primary Azure grid. Unsaved Fixedmood survives
switching drivers. At 390px the field grid stacks, labels/help remain readable,
and body content/scroll width both measure 375px. These are source/deployed-form
fixtures; no real connector was saved and no remote speech request was made.

Local code deployment preserved configuration, credentials and voice files.
Rollback: `/var/backups/lorkhanserver-code.sd45lb`. No game was launched or
controlled. Whole-site goal remains active against the full matrix above.

## Local TTS provider presentation checkpoint

Piper and xVASynth now use the pinned reference's primary labels and help text.
Mimic3 and MeloTTS show their effective native speed defaults. xVASynth displays
its native model type, version, pace, distro and Morrowind game defaults;
optional vocoder/model paths remain absent rather than copying unverified
installation paths. Service labels now match Mimic3, Azure, KoboldCPP and Zonos.
No provider request code or saved user configuration changed.

Paired full-page screenshots compared Piper, xVASynth, MeloTTS and Mimic3 against
Herika's unsaved editor. Piper's optional fields were reviewed empty, xVASynth
and MeloTTS with effective defaults, and a populated Piper draft survived a
provider round trip. All four narrow fixtures have 375px content/scroll width
inside a 390px frame. The narrow Piper screenshot confirms wrapped descriptions
and accessible controls. The documented inert Mimic3 Volume difference remains.

474 server checks, PHP lint, management HTTP, integration, migrations and
durable-job checks passed. Schema inventory remains 170 relations with the
same hash. Deployment preserved configuration, credentials and voice contents;
rollback is `/var/backups/lorkhanserver-code.VX1jac`. No live Save/Test action or
game control was used. Full TTS-editor and whole-site parity remain open.

Next source-audit finding: pinned `tts/tts-deepgram.php` calls its field `bitrate`
but sends it as `sample_rate` with `linear16`, default 32000. This is a functional
sample-rate control, not incompatible compressed-audio bitrate; its native
mapping and matching primary field still need implementation/review.

## Deepgram and Zonos selector checkpoint

Deepgram now presents the reference Bitrate field in its primary grid and maps
it to sample_rate, preserving linear16/WAV output. Five exact supported integer
rates are accepted; unsupported values and numeric strings at JSON ingress are
rejected. New editors use the reference runtime default 32000, while existing
connectors without an option retain provider-default behavior. Help explains
sample-rate units instead of copying the reference's incorrect 'Model' text.
Official format reference: https://developers.deepgram.com/docs/tts-media-output-settings .

Zonos now has the reference language selector, Pitch Std/Speaking Rate/Cfg Scale
labels and help, with native bounds and connector-specific scope retained.
Browser comparison verified all 109 reference choices and preservation of the
existing custom 'en' value. Desktop screenshots compared both provider forms;
48000 and en-gb-scotland survived switching away and back. Both narrow fixtures
measure 375px content/scroll width in a 390px frame. Dynamic tones and cache-path
presentation remain open; this is not complete Zonos or whole-editor parity.

482 server checks, PHP lint, management HTTP, integration, migration and durable
job checks passed. Request-shape tests cover every supported sample rate,
absent-option behavior and invalid ingress without network calls. No live TTS
request, live settings save or game control was used. Deployment preserved
configuration, credentials and voice contents; rollback is
`/var/backups/lorkhanserver-code.68nNSR`. Full page matrix and goal remain active.

## Chatterbox and XTTS expressive-speech checkpoint

The three paralinguistic controls now follow the reference's two-column
Enabled/Prompt row and Tag List row, including descriptions, enabled/disabled
choices, multiline prompt and default tag list. The selected actor's speech
connector contributes only its public style options to prompt assembly. Its
ID/revision are trace metadata; endpoints and credential references are omitted.
Enabled prompt text participates in the existing bounded system-prompt assembly.

Both adapters preserve configured bracket cues case-insensitively and strip
other bracket cues before synthesis. Explicitly disabled settings strip bracket
cues; existing connectors with no setting retain their prior behavior. Original
history/subtitle text is not rewritten. Provider capability and chosen tags still
determine audible expressiveness; no live provider result is claimed.

487 server checks passed, including enabled/disabled prompt assembly and tag
filtering. Existing HTTP tests exercised both actual adapters against a local
WAV mock and confirmed filtered text in each request. PHP lint, management HTTP,
integration, migrations and durable jobs passed. Schema remains 170 relations
with its prior inventory hash. The existing option-catalog assertion was updated
for the three new controls; no new test harness was introduced.

Paired desktop screenshots compared empty Chatterbox and populated XTTS forms.
The 4096-character multiline prompt survives driver switching. Narrow Chatterbox
shows readable stacked fields and wrapped help, with 375px content/scroll width
inside a 390px frame. Native endpoints and advanced tuning remain preserved.
Deployment preserved configuration, credentials and voices; rollback is
`/var/backups/lorkhanserver-code.jBEU6u`. No game, live settings save or paid
provider call was used. The full-site matrix and goal remain open.

## Relationship Logs final placement review

Reviewed deployed code at d7eda90 against live Herika and regenerated pinned
populated/empty fixtures. No product-code change was necessary: the header,
stats and full-width Type/Refresh arrangement match the reference, and native
management tools remain below the reader in a collapsed disclosure. Expanding
them exposes Build with AI, Add relationship, Current records and Recent changes
without changing the primary log layout. Opening Build with AI exposes only the
GET scope selection until an NPC/playthrough is chosen; no build was submitted.

The live Control Panel Relationship Logs frame preserves its expanded disclosure
through Request Logs and back. Its body content/scroll widths both measured
1241px. Paired populated fixtures cover positive/negative affinity, type changes,
cancelled work and no-change results. The native request/proposal disclosure
remains nested inside Context and clearly distinguishes proposals from applied
changes. Narrow content/scroll widths both measured 375px; the 630px table scrolls
inside a 355px keyboard-focusable region. The Delete All confirmation was opened
and cancelled in the isolated fixture, restoring focus to its triggering button.
No deletion, AI call, live save or game control occurred.

This closes the matrix's outstanding native-management placement review for
Relationship Logs. Runtime source is unchanged; the previous 487-check deployed
checkpoint remains the code under review. Other page/editor gaps remain open.

## Control Panel embedding checkpoint — 2026-09-07

- Opened all 12 live Control Panel tabs and inspected their expected headings:
  Server Logs, Request Logs, Oghma Audit, Relationship Logs, Cost Breakdown,
  Response Queue, Provider Attempts, Workers & Jobs, Game Debug, Audio Cache,
  Playthrough Manager and Database Manager. Desktop screenshots inspected for
  the 11 non-default entry views; their iframe bodies had no horizontal overflow.
- A temporary Server Logs search value survived the complete tab cycle without
  submission or changes to live configuration. Prior Relationship Logs disclosure
  retention evidence remains applicable. This does not close unfinished child-page
  features or establish every child's interactive-state parity.
- Compared live Herika Control Panel Request Logs at 1280x720. Both products
  inherited the standalone `100vh - 450px` table cap inside a roughly 500px iframe,
  leaving almost no visible rows. Lorkhan now uses `max(200px,100vh - 240px)` only
  for embedded desktop request-style readers. Standalone and <=900px rules are
  unchanged; table columns, typography, colours and payload readers are unchanged.
- Deployed Request Logs table measured 279px high instead of roughly 70px.
  Visually inspected populated rows and opened/closed its Request Payload reader.
  Provider Attempts and Workers & Jobs inherit the same scoped reader CSS.
- 487 existing server checks and `git diff --check` passed. CSS-only checkpoint;
  the prior full PHP/HTTP/integration/migration run remains the latest full suite.
  Deployment preserved configuration, credentials and voice contents; rollback:
  `/var/backups/lorkhanserver-code.aL6YbQ`. All 781 runtime hashes matched, no extra
  files/legacy paths, private routes 403, unauthenticated session 401, health valid.
- No game launched or commanded; no live settings, provider calls or deletion.
  Full page matrix remains incomplete and the goal stays active.

## LLM service guidance checkpoint — 2026-09-07

- Compared the live reference full LLM editor with Lorkhan's Config Hub populated
  editor and fresh unsaved connector. Header, sidebar, provider icons, primary
  fields, advanced sampling controls and YAML editor were visually inspected.
- Added the missing service signup and guidance panels directly below the icons,
  using the reference note styling and gold link colour. Signup URLs follow the
  pinned reference's five online providers. Custom shows endpoint guidance;
  Player2 and unknown/runtime services do not invent signup URLs.
- Kept terms guidance factual and neutral instead of copying the reference's
  time-sensitive claim of recently stricter enforcement. Its reference terms
  link remains unchanged. No external link was opened and no key was transmitted.
- Deployed UI checks switched OpenRouter, Groq, Custom and Player2 without saving:
  links and notice visibility updated correctly; hidden signup links lose href.
  A populated LLM name draft survived switching to Prompts Manager and back.
  After a subsequent create-form navigation the browser tool lost iframe access;
  a fresh standalone editor was used for the deployed full-page/notice checks.
  This is not proof of all embedded editor interactions; final review remains open.
- 487 existing server checks, JavaScript syntax and diff whitespace checks passed.
  Local deploy preserved configuration, credentials and voice contents. Rollback
  `/var/backups/lorkhanserver-code.rzRMij`; all 781 runtime hashes matched, no extra
  files/legacy paths, private routes 403, unauthenticated session 401, health valid.
  No live connector save/test, game launch or game command was performed.

## LLM unset-slider checkpoint — 2026-09-07

- Aligned empty numeric override thumb positions with the pinned reference:
  temperature/repetition 1, top-p/min-p/top-a 0.5, top-k 50, penalties 0.
  Numeric form values remain empty; these positions are not provider defaults.
  Native validated ranges are retained (including top-k through 1000), so the
  top-k thumb percentage differs from Herika's 0–100 slider.
- Individual keyboard clearing and Clear advanced settings restore the empty
  thumb positions. Existing shared sliders without the new optional attribute
  retain their prior behavior. Numeric stored values and backend options unchanged.
- Full checks passed: 98 protocol files, 487 server checks, PHP lint, management
  HTTP forms, integration vertical slice, migration and durable jobs. Schema
  inventory remains 170 relations and the prior inventory hash is unchanged.
- Narrow write-disabled rendered fixture measured 375px body and scroll width;
  primary/advanced stacking, tooltip and YAML placement inspected. Initial fixture
  lacked provider images; its asset copy was corrected. Do not count those initial
  broken-image screenshots as provider-icon parity proof (live icons were already
  compared in the prior checkpoint). Cross-origin live embedding was refused,
  as intended, so narrow rendering uses a local copy rather than relaxing headers.
- Keyboard selection/backspace cleared top-p from 0.8 to an empty field with thumb
  0.5. Clear advanced settings left all eight numeric values empty with the expected
  thumb positions. The browser tool's empty-string fill did not clear the number;
  keyboard clearing supplied actual evidence instead.
- Deployed locally; rollback `/var/backups/lorkhanserver-code.pYBwIw`.
  All 781 runtime file hashes match; private routes 403, unauthenticated session
  401 and health valid. Configuration, credentials and voice contents preserved.
  No live saves/provider tests or game commands. Full page parity remains open.

## Core Profile full-form comparison — 2026-09-07

- Live populated Default profile compared against Herika edit=1. Confirmed gaps,
  rather than treating existing green tests as full profile parity:
  - Profile Preset toolbar: Default/Local LLM/Follower/Passive, Apply, Save as new,
    Overwrite, Export/Import and both confirmation/name dialogs are absent.
  - Dynamic Profile toggle and editable-field chips are absent.
  - RPG comment types/chance, STM maximum summaries, language controls, Bored
    Event, Combat and Quest groups and Global Settings Overrides are absent.
    Skyrim-only event types must not be imported as unsupported OpenMW controls.
  - Formatter connector and Rechat calculator need native behavior mapping.
    Physical Diary needs supported OpenMW behavior proof before an enabled switch.
- Existing Diary context count moved into Context alongside regular history.
  Diary Prompt now precedes Automatic Diary Cooldown, matching reference order.
  Renamed Diary Instruction to Diary Prompt, including Copy to all's accessible
  label and confirmation. Field names, values, validation and persistence unchanged.
- Live deployed Context/Diary screenshot inspected. Copy Diary Prompt opened the
  correct scoped confirmation and was cancelled; no profile change was submitted.
- Implementation constraint for next profile work: EffectiveSettingsResolver only
  merges Core Profile rechat fields, four memory fields, diary and response today.
  ManagementRouter::coreProfileContent builds exactly that subset. Adding controls
  alone would create inert overrides. Extend validation, save mapping, effective
  layering and consumed worker/client behavior together for each supported field.
- Named Global Settings presets already have revision-checked private persistence
  in ManagementRepository and migration 087; reuse its pattern, not its global-only
  payloads/table semantics, for installation-scoped Core Profile presets. Existing
  profile import creates an unassigned profile; it is not Apply-to-current parity.
- 487 server checks, template PHP lint and whitespace checks passed. The preceding
  full HTTP/integration/migration suite is still the latest complete run.
  Deployment rollback `/var/backups/lorkhanserver-code.pJlxY2`; 781 runtime hashes
  matched, private routes 403, unauthenticated session 401, health valid. Secrets,
  config and voices preserved. No game launch/commands or live provider tests.
  Core Profile row and overall goal remain incomplete.

## Core Profile Dynamic Profile controls — 2026-09-07

- Added the reference Dynamic Profile toggle to Profiles & Memories and the
  Dynamic Profile Fields card above the settings columns. Editable field chips
  match the reference order: personality, occupation, skills, speechstyle, goals.
  Lorkhan stores speech_style internally. Gold branding is retained.
- Core Profile settings_overrides.profile_evolution is a validated server-only
  discovery default. New actor profiles inherit enabled/fields; explicit template
  or NPC choices win. Editing the Core Profile never rewrites existing NPCs.
  Player, Narrator and template creation do not receive actor defaults. No protocol
  extension, migration, live profile rewrite or new timer was introduced.
- Occupation and skills are wired through NPC editing, the existing 20-minute
  witnessed-history queue, provider JSON schema and revision-fenced worker.
  The worker still changes only selected fields and respects locked profiles.
- Portable Core Profile export/import retains the new defaults. Existing HTTP
  tests now cover save, invalid-field rejection, export and import. Existing
  integration tests cover inheritance/explicit choices and selected-field updates.
- Compared live populated Core Profile against Herika edit=1, including screenshots.
  A write-disabled rendered copy at 390px verified wrapping and Space-key selection.
  This found stale On/Off labels; fixed shared Core Profile toggle feedback and
  visually rechecked that checking Dynamic Profile changes Off to On. No live
  profile save or provider request was submitted during browser review.
- Passed: 493 server checks, 98-file protocol manifest, PHP lint, browser-like HTTP
  forms (including a second run with the new assertions), database integration,
  migration/durable jobs, JavaScript syntax and whitespace checks. Schema remains
  170 relations, hash b8099463e3d26547c1467feef76847ede606e1c49b3135ec6d760ecdc878b795.
- Final local deployment rollback: /var/backups/lorkhanserver-code.NXyg1J. All 781
  runtime files match; no extra files or old paths. Private paths 403, unauthenticated
  session 401, health valid, NPC routes 200/404. Configuration, secrets and voices
  preserved. No game launch, game commands or live AI evolution proof.
- Core Profile parity is still open: presets, other supported settings and their
  runtime mappings remain in the prior full-form audit. Overall goal remains active.

## Core Profile Rechat calculator — 2026-09-07

- Added the missing Rechat Response Calculator above the rounds/probability
  controls. Card geometry, heading, inline percentages/separators and probability
  colours derive from pinned Herika 529364c4c12b3a8bd4cc12a481f400ce19b3a344,
  ui/core/core_profiles.php and ui/core/tmpl/metadata_json_editor.php. Gold replaces
  the brand orange; semantic green/amber/red remain.
- Native counting distinction is explicit: RechatCoordinator::preRollBudget rolls
  each continuation, and rechat_max_depth counts continuations after the original
  response. The calculator includes the original response plus that many rounds,
  with probabilities 100, p, p^2, etc. Herika's UI treats its count as total replies.
  No runtime settings, queue behavior, saved values or existing conversation changed.
- Calculator listens to number edits, keyboard changes, sliders and form reset.
  Zero remains zero (the reference's parseInt(value)||50 would incorrectly replace
  0). Invalid/empty inputs produce guidance instead of invented probabilities.
- Inspected populated deployed HTML through a write-disabled copy at desktop and
  390px. Screenshots confirmed card layout/wrapping. Verified 50%, 0%, 100%, maximum
  20 continuations (21 displayed replies), empty-input guidance, and slider Home-key
  changes. Desktop document width/scrollWidth both 1265px. No live save/provider call.
- 493 server checks, PHP template lint, JavaScript syntax and whitespace passed.
  The preceding full integration/HTTP/migration run remains current for unchanged
  backend behavior. Local rollback /var/backups/lorkhanserver-code.nbE53L; all 781
  runtime hashes match. Private paths 403, unauthenticated session 401, health valid.
  Secrets/config/voices preserved; no game launched or controlled. Overall parity
  remains incomplete; the remaining Core Profile sections stay on the audit list.

## Core Profile named preset foundation and remaining implementation — 2026-09-07

- Traced ui/core/core_profiles.php toolbar/dialogs and its JSON operations in
  ui/api/chim_profile_manager.php, plus lib/core/settings_presets.php at the pinned
  reference. Selecting only previews; Apply changes the selected saved profile;
  Save as new/Overwrite capture unsaved editable metadata. Import stores a named
  preset; Export exports the selected preset. These differ from Lorkhan's existing
  profile-level Import, which creates a new unassigned Core Profile.
- Added CoreProfilePreset capture/validate/apply policy. Its strict server-owned
  payload permits only supported editable override fields and the randomizer/
  fallback booleans. Profile prompts, connector bindings, IDs, slots and default
  ownership are excluded. Applying preserves unrelated values and replaces field
  lists as a whole, avoiding array-merge leftovers. No action route or UI calls it yet.
- Added migration 093 and installation-scoped named preset storage in
  ManagementRepository. Writes serialize per installation, names are unique without
  case distinctions, four built-in names are reserved, catalogues are bounded to 64,
  and overwrite requires a matching revision. Validation precedes persistence;
  audit records contain IDs only. Down migration refuses to erase nonempty presets.
- Extended existing tests: round-trip, stale overwrite, duplicate names, installation
  isolation, connector/prompt exclusion, invalid keys, selected-field list replacement.
  No new test file or live profile mutation.
- Required next implementation, not completion claims:
  1. Add a CSRF-protected, installation-scoped named Core Profile action endpoint.
     Catalogue/read/export do not mutate profiles; save/overwrite/import mutate only
     the named catalogue; Apply requires confirmation and expected profile revision,
     invokes CoreProfilePreset::apply, and writes one profile revision. Reject stale
     preset overwrites and conflicting profile Apply rather than losing edits.
  2. Add reference toolbar above Profile Core: Profile Preset select, Apply, Save as
     new, Overwrite, Export, Import. Preserve draft controls while selecting/cancelling.
     Confirm Apply/Overwrite; use the separate name dialog for Save/import. Built-ins
     cannot be overwritten. Native dialogs must escape the container and restore
     focus, provide busy/error feedback, and remain usable at narrow widths.
  3. Capture unsaved controls using coreProfileContent mapping; exclude label, slot,
     default status, prompt and connector UUIDs. Catalogue import needs a strict
     named-preset document; do not repurpose the profile-level import action.
  4. Map built-ins only after consuming the remaining reference settings. Default,
     Local LLM, Follower and Passive include more than currently supported overrides:
     RPG comments/chance, boredom, combat cooldown and quest controls. Follower also
     requests 150 diary context events (native limit currently 100), physical diary,
     and dynamic-profile context length. Do not silently advertise partial built-ins
     as full parity; extend supported runtime paths or document real game exceptions.
  5. Add browser-like API/form tests for the complete workflow and visually compare
     populated/empty catalogue, selection, both dialogs, cancel/error/busy states,
     import/export and narrow/keyboard behavior before closing the preset matrix gap.
- Passed 498 server checks, 98-file protocol manifest, PHP lint, browser-like HTTP,
  database integration, migration/durable jobs and whitespace. Schema inventory now
 171 relations; hash 23877dfce7525e35984ac9fad0a90035f6da6a988f010d17c4d33062e41a6908.
- Deployed locally: rollback /var/backups/lorkhanserver-code.GuD3mX; 784 runtime files
  match, no extras/old paths, private 403, unauthenticated session 401, valid health.
  Read-only live DB probe confirms core_profile_presets exists and has zero rows.
  Configuration, credentials, voices and existing profile data preserved. No game.
  This is backend groundwork; no new visual parity or finished preset workflow is
  claimed. Core Profile and the all-pages goal remain open.

## Core Profile custom preset workflow — 2026-09-07

- Added CSRF-protected /forms/core-profile-preset operations: catalogue, save_new,
  overwrite, import, export and apply. Installation scope is explicit. Catalogue
  changes never alter profiles. Apply requires confirmation, a matching preset
  revision and expected profile revision; one content revision preserves saved name,
  slot, default status, prompt and connector IDs. Preset payload/revision are read
  together, and concurrent overwrites retain the repository revision fence.
- Added the reference Profile Preset toolbar directly below the editor header:
  selector, Apply, Save as new, Overwrite, Export and Import. Only existing custom
  presets are exposed for now. Named file import stores a preset; the separate
  profile-level import still creates an unassigned Core Profile. The named file
  schema is lorkhan.named-core-preset-file.v1 with exact name/preset/schema keys.
- Added native confirmation/name dialog modes, initial name selection, busy/error
  feedback, focus restoration and narrow wrapping. Dropdown/file selection does not
  mark the profile dirty. Apply explicitly discards the draft only after confirmed
  server success, using a dedicated draft event rather than synthetic form submit.
  Save/overwrite captures draft controls; other requests omit unnecessary draft data.
- Existing browser-like HTTP suite covers rejected CSRF, saved/unsaved separation,
  catalogue HTML, all five toolbar controls, export/import round-trip, excluded
  connector/prompt data, required confirmation, stale preset/profile revisions,
  cross-installation reads, and preserved target identity/routing after Apply.
- Visual comparison: inspected Herika's actual Save as new dialog without saving;
  compared desktop Lorkhan rendered-copy toolbar/dialog and 390px populated fixture.
  Verified empty-catalogue disabled actions, selection, Save failure feedback,
  cancel/focus restoration and no dirty marker, plus Apply/Overwrite/Save modes at
  narrow width. Offline fixtures block fetch/submission. Their sanitiser initially
  rewrote data-action attributes; corrected it to match only actual form action
  attributes and repeated checks. No production preset/profile mutation or provider
  request was submitted from the browser. Populated screenshot is an explicit
  fixture, not a claim that a real user preset was created.
- Passed 498 server checks, 98-file protocol manifest, PHP lint, full HTTP/integration/
  migration suite and a final HTTP rerun with toolbar assertions. JS syntax and
  whitespace passed. Schema stays 171 relations with hash
  23877dfce7525e35984ac9fad0a90035f6da6a988f010d17c4d33062e41a6908.
- Final local rollback /var/backups/lorkhanserver-code.zKeQ4a. All 786 runtime files
  match; no extras/old paths. Private 403, unauthenticated session 401, health valid;
  configuration, credentials and voice contents preserved. No game launch/control.
- Remaining before preset parity closes: four built-ins and their missing runtime
  setting mappings from the preceding audit; actual browser file-picker/download
  round-trip and successful browser-save/Apply navigation (HTTP success is already
  covered). Overall Core Profile/all-pages goal remains open.
