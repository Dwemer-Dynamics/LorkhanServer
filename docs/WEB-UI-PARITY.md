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

## Current priority presentation checkpoint (2026-09-11)

Latest presentation checkpoint: shared context labels and appearance grouping (de38f77); see the final dated sections for remaining ownership differences. Quickstart Setup / Local LLM cards, all-Core preset application, memory switches and network helpers are implemented. Narrator quest grounding is deployed at server `75984dc` / client `b133b4b`; the client is unchanged by this UI checkpoint.
The dated evidence below supersedes older absence claims. These results establish
only the listed states, not completion of every page or feature.

| Surface | Current comparison evidence | Still separate |
| --- | --- | --- |
| Events | Initial/live Record controls match; duplicate bottom pager removed to match the reference; empty-to-live reconstruction, page change and stopped refresh checked | Whole-table metrics and populated/empty views reviewed; hide/unhide/retry and deletion cancel/failure/success mocked at both widths; reference has no inline event editor |
| AI Responses | Populated and empty table geometry compared at 1280/390; refreshed empty cells match 38.6875px height and 9px/10px padding | Multirole and empty reader geometry, copy/failure/close and escaped-text wrapping verified; cleanup cancel/failure/reload and populated/empty CSV verified; 61-row multi-page export and exact escaped-prompt round trip verified |
| Adventure Log | Actual hub populated columns match; empty-date cells now use reference padding and 39.1875px height | Complete-day table and stale-page links now match; selected/latest/all downloads, calendar switching and empty dates rechecked; leap/non-leap and month/year boundary combinations now verified |
| Diaries | Actual hub populated columns match; empty-date row padding corrected; reader whitespace and editor geometry compared at both widths | Complete day/author sets and corresponding ascending/descending order verified; paired reader/editor refreshed; mock playback/cancellation and cache hit/invalidation are covered; live-provider acceptance remains separate from presentation proof |
| Books | Populated typography and columns compared; missing empty-result panel restored and checked at both widths | Game-time/numeric ordering, 150/1 pagination, filtered/empty results and full export checked with 151 isolated rows; observed-book runtime capture remains unverified |
| API Keys | Standalone and actual hub preset geometry compared at 1280/390; Custom Keys empty/live and matched draft states inspected; failed-save and pending-save draft retention rechecked with mocks | Content-only shell corrected and deployed; live-provider acceptance remains untested |

Core Profiles now includes the RPG Comments card with supported event choices and
probability, bound-responder policy, portable/named presets and built-in probabilities.
Its actual hub desktop card and native 860/390 controls were compared; the complete
Core editor and remaining profile features are still open. The Short Term Memory card now matches the reference desktop geometry, with saved 1-50/default10 controls and native 860/390 interactions verified. Retained exact digest coverage now applies before the scene cap, and complete dated scene text can replace matching live history. Semantic model-summary source coverage remains unfinished runtime parity work.

The reference's extra excluded navigation entries change wrapping and page origins.
Do not add blank tabs or reintroduce excluded features to force absolute offsets.
Lorkhan's Journal remains an OpenMW-specific entry.

Quickstart MiniMe Service is implemented, including its bounded reachability probe.
Managed Local LLM persistence, routing API and partial built-in profile application
and the visible Setup/Local LLM workflow are implemented; see the dated checks below.
Service selection now creates or reuses native connectors. Player2 and complete page closure remain open; the pinned Herika Quickstart does not impose service-availability gates. Do not present
partial or unwired controls as completed counterparts.

Evidence is recorded in the dated checkpoints below. Current API Keys review uses
`apikey-viewport-review.cjs`, `apikey-bottom-review.cjs` and
`apikey-custom-draft-review.cjs` under the local temporary evidence directory.
All credential input values were cleared before screenshots; all non-GET traffic
was blocked. Custom draft comparisons alter browser DOM only, not saved records.

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
  The Local LLM built-in now applies to all Core profiles; remaining connector
  availability and Player2 behavior are still pending, not accepted product exceptions. The Global Connector test dialog is implemented.

## Complete page matrix

Current delivery order: close visible page structure and interactions directly from the pinned Herika templates first. Database-import infrastructure remains unfinished and deferred behind that page pass; it is not evidence of UI completion.

| Lorkhan page | Herika counterpart | Current status |
| --- | --- | --- |
| `home.php` | `home.php` | Widgets, tables, word cloud, observed world/player statistics and drilldowns aligned; read-only worker indicator verified; populated/empty and desktop/narrow layout reviewed. Latest-diary author audio is wired, with populated-template playback/error checks; live-provider acceptance remains open |
| `quickstart.php` | `quickstart.php` | Header/980px shell, editable Player, speech sections, four-card model recap and protected OpenRouter/Deepgram quick keys implemented; MiniMe Service and its bounded reachability probe implemented; visible Setup/Local LLM, all-Core presets and network helpers implemented; Herika service choices now create or reuse installation-owned speech connectors while preserving custom endpoints and badges; Player2 switch, immutable routing overlay and four recap cards are implemented and mock/browser tested; whole-page desktop/narrow structure and complete save-flow checks are complete, including key errors/retry, local setup, service reuse and stale-revision rejection. Live-provider acceptance remains untested. Service process installation is not performed by this form in either product |
| `core/config_hub.php` | Same path | Shared geometry corrected; Oghma, Global Settings, Profiles, Player and Narration embedded entry views compared. Unsaved Player/Narration switches survive shared and ordinary tab changes in isolated rendered fixtures. All 15 shared tab entry views now load and retain mounted documents at 1280/390, with draft values retained in the eleven entry views containing editable fields. Tab names/order/style and keyboard activation match the reference grouping, with excluded tabs absent. Shared shell review is complete; individual editor/runtime gaps remain tracked in their own rows |
| `global_settings.php` | Same path | Prompt/preset toolbar, grouped context selections, Oghma, connector cards/test dialog and blacklist browsers aligned; Context now includes Hide Ambient Combat, Power Awareness, Prompt Timestamp and ground/inventory description-only controls with persistence and prompt checks. Other Context event controls and profile-affecting built-ins remain open; see the dated checkpoints |
| `core/core_profiles.php` | Same path | Response limits, memory/diary groups, settings/range controls, Copy to all, sticky toolbar, assigned slots and portable/named presets are implemented. Embedded LLM/TTS and Dialogue Prompt editors participate in Save All, with duplicate/conflicting shared drafts, revision-aware repeat saves and failed-save retention checked. Advanced metadata is now merged with visible controls; Oghma overrides and knowledge tags reach retrieval and both preset formats. Oghma, Context, Prompt, Relationship and Rechat overrides use the reference inline Global Settings rows, with raw JSON synchronization and inherited values. Core Prompt Head, timestamps and description-only item filters reach prompt assembly; Rechat Mode reaches chain selection, Relationship System controls job eligibility, and Power Awareness uses exact observed actor levels. Metadata Text/Tree/Table switching, immediate serialization, invalid JSON handling and empty-object preservation are verified, including fresh deployed mode-switch checks. Remaining: other runtime-backed Global Settings override categories and page-wide interaction closure. Physical Diary, cumulative memory semantics and Core-slot client selection are separate unfinished runtime features. |
| `core/npc_master.php` | Same path | Mass Core Profile switching, Roleplay/General tabs, staged relationship edits/builds, locks/clear/details, diary switches and exact-target observed Info panels are implemented. Typed per-NPC override leaves now include language, summaries, Diary Prompt/Cooldown/History, Dynamic Profile History, Context, Prompt Head, Oghma, Relationship Enabled/Update Chance, Rechat Mode/Strict Targeting/Open Rechat and End Conversation Cooldown. Prompt assembly, queued evolution history, revisioned save/remove and invalid-input rejection are checked. Equipment and Metadata disclosure presentation corrected. General profile metadata now uses the reference vanilla-jsoneditor tree/text/table library, with revisioned native content and separate immutable Recorded State. Bounded target inventory capture and sorted count/total presentation are deployed in server d09d717 and client 1a35727; empty/unavailable states remain distinct. All 45 currently supported override editors apply typed values to drafts at desktop/narrow widths; grouped search, metadata mode switching and failed-save retention are checked. Remaining: full runtime-backed override catalogue, other page-wide editor/modal interactions, continuous inventory updates and NPC Visit/Teleport/Return. |
| `core/player_management.php` | Same path | Header/toolbar, biography checkbox, TTS status, card geometry and empty/populated stats compared; import reader and narrow controls checked. AI-generation guidance is wired, and generated speech style now stages in the editor until Save, with an isolated successful browser round trip. Player name editing and embedded saves are implemented with revision-conflict protection; ElevenLabs player overrides are copied and wired; generation success, failed/stale jobs, concurrent edits, retry idempotency and empty-result rejection are checked with browser mocks; live-provider acceptance remains untested |
| `narrator_management.php` | `core/narrator_management.php` | Toolbar, switches, dynamic field chips, connector summary and five shared event-prompt rows/editors aligned; Profile & Voice includes wired Oghma tags and native routing in a disclosure; embedded saves stay in the editor. Inline and player speech-style template editors are wired; broader Core semantics and full page acceptance remain pending; current action catalog has no narrator-capable actions |
| `core/api_keys.php` | `core/api_badge.php` | Preset/card geometry, custom Add/Save/Delete editors, replacement autosave and Test reader compared; custom labels are editable with stable connector references; provider presets consolidated to 11 cards with all 32 built-in credential identities editable through saved/optional additional badges; standalone and actual hub preset/custom draft geometry reviewed at 1280/390, with failed-save and concurrent-edit retention rechecked using mocks; content-only shell now matches; live-provider acceptance remains untested |
| `core/llm_connectors.php` | Same path | Common fields flattened, checkbox request switches and measured columns/icons aligned; native runtime/mock/alternative-token controls moved to secondary connection options with inheritance preserved. OpenRouter model/provider catalogues, filtering, selection, pricing/context, ordered provider preferences and configured-first API-key selection implemented and deployed. JSON Schema and Prefill JSON wired to operation-specific requests. Direct multi-file import, Clear advanced settings and Groq model selection now follow the reference; evidence below. YAML body editor, enable switch and request wiring are implemented; vertical populated comparison, service badges and Test accent corrected. Inherited-runtime API-key selection is now wired and browser save/reload tested; hub selection and unsaved draft retention, narrow editor bounds and failed-save Test refusal are verified; provider/advanced interaction review is complete for the six supported presets and Custom, including seven request switches, inherited reset, mode transitions, YAML retention and Player2 model omission. Live-provider acceptance remains untested. Remove Action Prompt is saved UI metadata only in the pinned reference, with no request-side consumer; not copied as an inert switch |
| `core/tts_connectors.php` | Same path | Populated Inworld/list layout compared; playable Test dialog, provider grouping/field identity, editable Name and collapsed raw options corrected; API Badge selection now matches configured/missing/none states and is wired through speech and account-scoped automatic cloning; provider-specific grids replace generic primary fields, with Inworld/PocketTTS/Cartesia visual comparisons and workspace routing wired. Zonos Cached Voice Path is editable, revisioned and applied once to its exact endpoint/sample; lower revision/restore and Delete-cancel interactions are verified; provider-error/retry/close states and all seventeen shared-provider hub-return/draft checks are complete against the pinned baseline. Later live Cartesia schema drift is recorded separately; live-provider acceptance remains untested |
| `core/stt_connectors.php` | `stt_connectors.php` | Fixed-sample test/result reader, provider-specific fields, API Badge, independent service drafts and editable Name are implemented. Browser recognition and the session-fenced player-dialogue injection bridge are implemented with typed receipts, nine languages and Start/Stop controls. Primary fields across eight services and desktop/narrow draft switching, URL validation and Test failure/retry/close flows are checked. Advanced drafts for all eight services and Configuration-hub tab retention are verified; simulated browser recognition and queue/receipt paths pass, but real microphone/provider and in-game acceptance remain untested. |
| `core/voice_library.php` | `xtts_clone.php` | Provider cache/upload/batch structure aligned; Inworld batch states compared. Global Fallback/Pronunciation populated, empty, filtered and built-in edit states corrected and compared below. Provider-side clone management and OmniVoice language/readiness/direct-import/provider-delete flows are implemented with mock HTTP/browser evidence below; all eight visible hub tabs, provider section inventories and direct/outer tab behavior compared at desktop/narrow widths; missing XTTS/Chatterbox/PocketTTS sync and OmniVoice service sections restored. Error and batch states have mock/browser evidence below; live-provider acceptance is untested |
| `core/npc_biographies.php` | `npc_upload.php` | Header, summary, Add/Edit, Extended Profiles, inline Oghma and full-catalog search/paging aligned. Batch guidance, complete global/installation custom export and confirmed factory reset are implemented; ownership and native fallback protections are explicit. Final tests/deployment evidence below. |
| `function_editor.php` | Same path | Summary, filters, editable rows, Behavior controls, scoped saves and both readers aligned; populated/empty and narrow states checked. Negotiated OpenMW parameters remain read-only; see Action Editor evidence below. |
| `prompts_manager.php` | Same path | Full header/CSV/search/table/reader comparison completed; Default/Custom editing, safe Clear, CSV round trip and plain instruction creation implemented; desktop/narrow, populated/empty, search and keyboard controls checked; retained document tools and validation limits documented below |
| `worldknowledge_upload.php` | `oghma_upload.php` | Regular Oghma header/search-logic panels, category filters, table, badges and Add/Edit dialogs compared and corrected. Topic/alias substring search and optional Basic Description/Category match the reference form contract, including CSV. Delete All, Factory Reset and factory-entry Delete are implemented with explicit confirmation and persistent deletion choices; final evidence below. Dynamic Oghma UI and runtime verified and deployed; evidence below. |
| `description_manager.php` | `description_upload.php` | Compact header, paired panels, five-column table, alphabet/search controls and Add/Edit dialogs aligned; populated/empty, full-text editing, keyboard and narrow states compared. Name and Description are now optional in forms/save/CSV, with stable OpenMW plugin/record identity retained. Latest migration/tests/deployment evidence below. |
| `oghma_knowledge.php` | `npc_upload.php` Oghma knowledge reader | Replaced article cards with the reference Topic/Knowledge Level/Description table, metadata chips and filter controls. Populated/empty and narrow states compared; permitted-description search, paging, scope rejection and hidden-text exclusion tested. Existing standalone navigation and access diagnostics retained. |
| `events-memories.php` | Same path | Events note, striped table, record heading, pagination/filter layout and recorded calendar dates corrected; populated live view and AJAX pagination verified |
| Roleplay `memory` tab | Herika Memories | Summary-only table, status/settings strip, scoped sync/delete, Tamrielic dates and compact editor implemented; 67 populated live summaries, empty fixture, Cancel/focus and narrow advanced tools checked |
| Roleplay `responselog` tab | Herika AI Responses | Whole-turn log, prompt dialog, topics, scoped export and protected clean-log workflow implemented; populated live table, multirole/empty prompt reader, clipboard success/refusal, Escape/focus and actual-template escaped-text wrapping checked; cleanup cancel/failure/reload and 49-row/empty CSV verified; 61-row multi-page export and exact escaped-prompt round trip verified |
| Roleplay `diaries` tab | Herika CHIM Diaries | UTC/Tamrielic calendars, person mode, export and bulk delete retained. Full-content rows now use separate Play/Edit/Delete actions; dedicated content editor and paper reader replace combined Read/Edit. Desktop/narrow populated/empty and reader controls compared. Author-voice playback and private entry caching are implemented and mock-tested, including text/author/language invalidation and deleted-entry rejection. Live-provider acceptance remains untested. |
| Roleplay `books` tab | Herika Books | Full-content striped table, game/UTC/TS columns and content dialog implemented; populated long/short fixtures, escaped content reader, filtered empty panel, desktop/narrow and computed neutral header typography compared; shared-theme overrides and forced minimum width removed |
| `diary_book.php` | Same path | Printable chronological parchment book and author-list link implemented; scoped IDs, escaped text, desktop/narrow populated comparisons and print/PDF checks passed (see Diary authors and printable book checkpoint) |
| Roleplay `adventure` tab | Herika Adventure Log | Chronological context/people/game-time/UTC rows, location dividers, contiguous speaker bands and counterpart CSV formatting implemented. Desktop/narrow populated, empty and long fixtures compared; date-selection, selected/latest-day and full exports checked. Full checks and 744-file deployment passed; live populated calendar/table verified. Native dates and complete OpenMW cell names retained. Month navigation now uses measured reference rules, including its narrow-screen clipping limitation; see source-parity checkpoint below. |
| Roleplay `journal` tab | Morrowind-only Journal using Herika's record table | Full-content striped table, Journal ID, game/UTC/TS columns and content dialog implemented; three live records, reader and focus restoration verified |
| `control_panel.php` | Same path | Shared geometry and label typography corrected; all 12 embedded tabs load at desktop/narrow widths and retain mounted frames on switches. Request reader Escape/focus and unsaved filter retention verified. Remaining child feature/interaction comparisons are tracked in their rows; see embedded checkpoint below |
| `request_logs.php` | Same path | Nine-column LLM-attempt table, toolbar, page sizes and separate payload readers aligned; populated/empty, keyboard and narrow fixture states compared. Safe scoped Clear preserves accounting/history/pending work; URL and unretained raw provider payloads remain explicit data limitations. See Request Logs evidence below. |
| `response_queue.php` | Control Panel -> `index.php?table=responselog` | Actual queued-message projection and seven-column striped table aligned; populated/empty, narrow, confirmation, playback details, pagination, CSV and live hub embedding checked. Row removal preserves native delivery/history and protects pending work. |
| `cache_browser.php` | Same path, audio portion | Compact file-list panel, typography and inline players aligned; populated/empty, expired/unavailable, keyboard, narrow and live hub states checked. Private authenticated media replaces public paths; excluded Soulgaze image panel stays absent. |
| `relationship_logs.php` | Same path | Evaluation-first header/filter/table/context/cleanup compared populated and empty; request/proposal and committed per-target/type-change evidence implemented and checked. Historical missing data stays explicit. Final native-tool placement and live Control Panel tab-state review completed below; tools remain in a secondary collapsed disclosure |
| `oghma_audit.php` | Same path | Header, filters/pager, nine metadata pills and five trace sections compared populated/empty at 1280px and narrow 390px; native retrieval evidence retained in secondary details; see Oghma Audit checkpoint |
| `playthrough_manager.php` | Same path | Full named database snapshots support save, copy, download, delete, automatic rollback and live-source provenance. Active Database and paired Save/Stored panels use the reference structure; legacy profile-only tools are separately collapsed. Snapshot player/count/calendar metadata, time ordering, ahead/behind labels and the interactive timeline are implemented. First visit now queues a protected default snapshot; repeat visits reuse pending work and deletion is refused server-side. Restricted-role lifecycle and populated/empty desktop/narrow checks passed. Automatic Dragon Break capture now runs at the loaded-save boundary before session replacement, with a three-day threshold and isolated archive inspection. Remaining: future-history pruning, external/legacy import and full rollback parity. Profile-scoped JSON exports are not substitutes for full database snapshots. |
| `server_logs.php` | Control Panel -> Dwemer Debugger CHIM log panels | Three-column log panels, search/severity controls, expanded readers, refresh, visible-entry download and UTC/local display aligned; populated/empty/narrow fixtures and dense live standalone/hub views checked. Only actual Lorkhan service logs are read; cross-product dashboard/MCP controls are not imported. |
| `provider_usage.php` | `audit.php` (Cost Breakdown) | Date/week header/filter/pie layout aligned; desktop and narrow populated/empty/unknown-cost states compared. Whole-range request-type totals, scoped UTC boundaries and CSV coverage checked; token/provider details remain collapsed. See Cost Breakdown checkpoint below. |
| `provider_attempts.php` | `request_logs.php` operational presentation; no exact all-provider CHIM page | Explicit safe metadata columns, toolbar, status pills, scoped filters, full pagination and page CSV. Populated/empty source-rendered comparison and narrow keyboard scrolling checked. |
| `jobs.php` | `request_logs.php` operational presentation; no exact durable-job CHIM page | Same shared reader, native queued/running/success/dead-letter states and retry/schedule metadata. Populated/empty comparison checked; no invented worker actions or exposed payloads. |
| `game_debug.php` | Herika Request Logs operational components; no equivalent OpenMW command page | Generic widgets replaced with compact session controls, grouped commands and a shared status/UTC history table. Desktop/narrow populated, offline and outdated-client fixtures compared; command names/parameters preserved. Existing checks and local deployment passed; no live commands issued. |
| `database_manager.php` | Dwemer-Dashboard `database_manager.php`, embedded by Herika Control Panel | Configuration backup create/download/restore and migration table are implemented. Confirmed, scoped VACUUM FULL ANALYZE with overlap/cooldown protection and audit outcomes is implemented and tested on an isolated database; web requests now queue durable background maintenance with a 30-minute SQL deadline and a fenced one-hour worker lease; progress, completion and failures are polled on the page. Full SQL backup creation, immutable private storage and streamed authenticated download are implemented with isolated restore proof. Automatic backups now have the reference Home-visit/10-minute cooldown, On/Off and 1-10 retention controls, background dumps and verified-replacement retention. Stored SQL restore is implemented for current-format backups, with automatic rollback capture, transactional schema/installation checks, runtime gating and preserved control state. Automatic backups also have the reference individual-delete control, protected against queued restoration and concurrent maintenance. Version-reset controls now queue confirmed, backed-up atomic source replay with lifecycle status; Database Access uses a validated deployment-owned pgAdmin URL and the reference card layout. The independent factory worker and private artifact deployment now pass restricted-owner reset and rollback-restore checks. Factory reset now has the reference destructive card, verified plan, typed confirmation, protected single-attempt queue and lifecycle status. Remaining: uploaded/legacy SQL import. These are missing features, not completed product exceptions. |
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
  round-trip. Successful browser-save/Apply navigation is now covered below.
  Overall Core Profile/all-pages goal remains open.

## Core Profile preset successful browser workflow — 2026-09-07

- Used the existing management HTTP fixture bootstrap with isolated PostgreSQL and
  mock configuration on temporary ports 55464/58464. No production profile, preset,
  provider credentials or game state was changed.
- In the actual rendered editor, changed Max Words from 0 to 77 and saved a new
  named preset. The catalogue refreshed to preset revision 1, the dialog closed,
  the draft stayed at 77 and the profile stayed at revision 1.
- Changed the draft to 88 and confirmed Overwrite. The catalogue refreshed to
  preset revision 2 while profile revision remained 1 and the draft stayed at 88.
- Changed the draft to 99, then confirmed Apply. Browser navigation completed with
  status=preset-applied, the saved preset value 88 replaced the draft, the profile
  advanced to revision 2, and the name remained Default. Inspected the resulting
  desktop screenshot, including the success banner and preset toolbar.
- No product code changed during this review. File chooser/download browser proof,
  built-in preset mappings and the remaining all-page matrix items remain open.

## LLM unselected editor panel — 2026-09-07

- Compared the deployed Configuration > LLM page with Herika's corresponding hub.
  Found the unselected editor rendered as bare text rather than the reference's
  outer editor panel and inset bordered placeholder. Reused the existing shared
  editor container and connector-placeholder styles; removed obsolete empty-state
  CSS. Provider data, settings, actions and persistence are unchanged.
- Inspected the deployed corrected placeholder screenshot and the populated
  DeepSeek editor inside the hub. The complete editor/hub interaction review is
  still open; this checkpoint does not close the LLM row or all-pages goal.
- Passed 498 server checks, changed PHP lint and whitespace checks. Deployment
  rollback: /var/backups/lorkhanserver-code.6hrunJ. All 786 runtime hashes match;
  no extra files or old paths. Private routes returned 403 and unauthenticated
  session returned 401. Configuration, credentials and voice files were preserved.
  No provider request, live settings save or game control was performed.

## LLM creation toolbar and YAML row — 2026-09-07

- Compared the populated DeepSeek editor and lower advanced/YAML sections in both
  live products. Aligned the YAML enable control to the right edge of its label
  row, matching the reference. Inspected the deployed result visually.
- Aligned the unsaved connector toolbar with Herika's create-form template: Create
  replaces Save, and unavailable Test/Export plus their testing note are absent.
  Existing connectors retain Save/Test/Export. Removed unused placeholder CSS.
  Herika's New action creates a blank database row immediately; did not invoke it
  on the reference server. Lorkhan still creates only on authenticated POST.
- Extended the existing HTTP create workflow with toolbar assertions; its existing
  create/test/revise checks passed. Also passed 498 server checks, 98 protocol
  files, PHP lint, full HTTP/integration/migration tests and whitespace checks.
- Local deployment verified 786 matching runtime files, no extras/old paths,
  protected private routes and unauthenticated session rejection. Configuration,
  credentials and voice files were preserved. No provider requests or live saves
  were made through the browser; no game launch/control. Full LLM interaction and
  all-pages parity remain open.

## Player speech-style generation guidance — 2026-09-07

- Added the reference AI Generation label and optional guidance textarea above
  Generate From Last 200 Inputs, using its grid layout, heading typography and
  Lorkhan accent. Compared both actual rendered fields with keyboard focus.
- The textarea belongs to the separate generation form, not Save Player Settings.
  Guidance is transient generation input rather than profile content. Queue and
  worker reject non-string, invalid UTF-8 or over-4000-byte values. Nonempty guidance
  is frozen in the job and included in its idempotency key; blank guidance preserves
  the existing job key. The worker passes it to the provider, whose prompt treats
  guidance as preferences and observed player messages as data. The output remains
  speech_style only, with existing revision fencing and preservation of other fields.
- Extended existing durable-job tests for payload preservation, duplicate submission
  and invalid guidance. Full checks passed: 498 server checks, 98 protocol files,
  PHP lint, HTTP, integration, migrations and durable jobs. Provider interpretation
  of guidance has not been live-tested; no browser generation request was submitted.
- Local deployment rollback /var/backups/lorkhanserver-code.yLwRZy; all 786 runtime
  files match. Private 403/session 401 and health checks passed. Configuration,
  credentials and voices preserved; no player settings save or game control.
- Player name editing, generated-result staging and per-player provider overrides
  remain open. This does not close the Player page or all-pages goal.

## Player generated speech-style review — 2026-09-07

- Replaced automatic player-profile revision on generation with private draft
  storage (migration 094). The worker stores only a bounded speech_style result,
  fenced by job attempt, active lease and queued profile/revision. Drafts follow
  job/profile deletion; rollback refuses to discard existing drafts.
- Added installation/profile/job-scoped status responses behind the existing CSRF
  form route. Responses expose state and successful draft text only, not job payloads.
  A changed saved revision returns stale. The editor polls the same job, displays
  generating/error/success feedback and places the result in the existing textarea
  with an input event to mark it dirty. It preserves edits made during generation.
  Save Player Settings remains the only step that persists the reviewed text.
- New ui/js/player-speech-style.js follows the pinned Herika Player generation
  interaction, adapted to the existing durable queue. JavaScript-disabled users
  receive an explicit explanation; the generation button cannot silently autosave.
- Actual browser/worker round trip used disposable PostgreSQL 55464 and HTTP58464,
  synthetic player input and an explicit MockProfileGenerationProvider. Before Save,
  database revision stayed 2 and speech_style stayed empty; the browser displayed
  generated text and Unsaved changes. Browser Save advanced to revision 3 and stored
  that text while preserving the biography. The isolated server was stopped cleanly.
  Initial synthetic session insertion lacked its required playthrough; added that
  fixture record and reran successfully. No production settings/provider calls occurred.
- Passed 498 server checks, 98 protocol files, full HTTP/integration/migration/durable
  job checks and a second isolated HTTP run; JS syntax/whitespace passed. Existing
  worker tests assert unchanged profile plus stored draft and cross-installation
  refusal. Schema: 172 relations, summary hash
  fe70674a3ec53cfb7e41a1e94cba64acd690d2fa5650b63a83727ebffaec9afb.
- Local rollback /var/backups/lorkhanserver-code.RjLDKo; all 789 runtime hashes match,
  no extras/old paths, private 403 and unauthenticated session 401. Configuration,
  credentials and voices preserved. No game launch/control. Remaining generation
  edge-state browser checks and current unsaved style as generation context remain
  open alongside Player name/provider overrides and the full page matrix.

## Player current-editor generation input and races — 2026-09-07

- Added Herika's current_speech_style request input from the actual editor value,
  including unsaved text. The queue and worker validate UTF-8/string type and an
  8192-byte limit. Explicit empty text remains distinct from an omitted legacy input.
  The snapshot participates in job idempotency and is passed to the provider as
  existing wording to refine, not witnessed dialogue or higher-priority instructions.
- Extended existing durable-job tests for frozen draft input, identical-request
  deduplication and rejection of oversized/non-string values. Full checks passed:
  498 server checks, 98 protocol files, PHP lint, HTTP/integration/migrations/jobs,
  JS syntax and whitespace. No schema change from migration 094.
- Isolated browser/MockProfileGenerationProvider test confirmed the job stored the
  unsaved pre-generation text. Editing that field while the job ran kept the newer
  text and displayed the edit-preservation message when the worker finished.
  A second request followed by an isolated repository-state revision change showed
  the stale-profile reload warning, again retaining the editor draft. No live
  generation request or production profile change was made. Test server stopped.
- Local rollback /var/backups/lorkhanserver-code.LUaX7v; all 789 runtime files match,
  no extras/old paths, private 403/session 401 and health checks passed. Configuration,
  credentials and voices preserved; no game launch/control. Provider/network-failure
  browser states and the remaining Player/all-page matrix items are still open.

## Player generation failure and deliberate retry — 2026-09-07

- Found that deterministic input-only job keys trapped an unchanged request on its
  terminal failed job. Added optional validated request UUIDs: the editor retains
  one across ambiguous network errors/poll timeouts, but starts a new explicit
  request after a terminal result. UUID generation uses getRandomValues, including
  on HTTP LAN pages. Changed inputs start a separate request; polling only reads.
- Existing worker tests now cover same-request deduplication and distinct explicit
  requests. Passed 498 checks, 98 protocol files, full HTTP/integration/migration/job
  checks, JS syntax and whitespace. Schema remains 172 relations.
- Actual isolated browser test used a deliberately throwing test provider with a
  one-attempt fixture limit. The terminal failure re-enabled Generate and preserved
  the style. A second click with identical inputs created a new queued job beside
  the failed one; the mock worker succeeded and the editor showed an unsaved draft.
  No real provider call or production profile save. The isolated server was stopped.
- Local rollback /var/backups/lorkhanserver-code.7EnqUo; 789 runtime hashes match,
  no extras/old paths, private 403 and session 401 verified. Configuration, credentials
  and voices preserved. No game control. Browser network-outage recovery remains
  unverified; the remaining Player and all-pages matrix remains open.

## Player editable name and embedded save parity — 2026-09-07

- Removed the read-only Player Name field to match the reference editor. Saving
  atomically revises the selected installation player name, display identity and
  edited content, guarded by the displayed profile revision. Other installations,
  non-player profiles and stale forms cannot use this path to overwrite a player.
- Configured player profile names now take precedence over incoming speaker names
  during prompt substitution. This includes the saved default Player persona name;
  with no saved profile name, incoming game identity remains the fallback. Historical
  source events are not rewritten. Quickstart retains its existing rename path.
- Embedded Save retains embed=1 and installation scope rather than rendering a
  second navigation bar inside Configuration Hub.
- Disposable browser/SQL checks verified editable name and content saved together,
  a stale second form failed with revision_conflict without overwriting the name,
  and an embedded save retained its clean embedded layout. No live profile edit or
  real provider call occurred. The isolated process has exited.
- Passed 499 server checks, 98 protocol manifest checks, PHP lint, HTTP forms,
  integration, migrations and durable jobs. Schema remains 172 relations with
  summary hash fe70674a3ec53cfb7e41a1e94cba64acd690d2fa5650b63a83727ebffaec9afb.
  Existing tests cover atomic revision, discovery preservation, stale saves,
  cross-installation refusal and prompt name precedence/fallback.
- Local deployment rollback: /var/backups/lorkhanserver-code.ubtSfT. All 789 runtime
  files match source, no extra files or old paths, protected routes return 403 and
  unauthenticated sessions return 401. Health/NPC route checks passed. Existing
  configuration, credentials and voices were preserved. No game launch/control.
  Provider overrides, network-outage browser proof and the full remaining page
  matrix are still open; this checkpoint does not claim overall completion.

## LLM service badges and complete vertical comparison — 2026-09-07

- Read-only live comparison of GLM 5 in Lorkhan and Herika covered the header,
  selected connector list, toolbar, service icons, model/provider, request switches,
  sampling controls and bottom YAML/editor/reset section. No Save, Test, Clone,
  Delete or reference New action was used; no provider request was issued.
- Found list badges describing every configured connector merely as Runtime.
  Known endpoint services now use their actual display names, matching the reference
  badge purpose without inventing Herika driver identifiers. The connection mode
  remains in the badge tooltip; unknown/custom endpoints retain Direct or Runtime,
  and mock remains Mock. Reused the editor's exact endpoint-service mapping.
- Test now uses the Lorkhan accent rather than Save/Export green, matching the
  reference action distinction. Sampling/YAML geometry was visually compared through
  the footer; native revision history remains a secondary disclosure.
- Newly explicit outstanding LLM gap: configured-runtime mode hides the API Key
  selector while Herika presents it. A real fix needs an inherited-endpoint credential
  override consumed by ProviderFactory and catalogue/test paths; exposing a disabled
  or ignored select would not satisfy parity. Full hub/narrow/interactions remain open.
- Player provider audit: reference fields are ElevenLabs Model ID, Speed, Stability,
  Similarity Boost, Style, Speaker Boost and V3 Enhancers, shown only for ElevenLabs
  with blank meaning inherit. Current profile voice validation accepts only id/language;
  speech jobs construct the connector before obtaining actor speech context. Implement
  a validated per-player override document and apply it before provider construction,
  preserving other actors and default connectors, before adding the reference panel.
- Passed 499 server checks, 98 protocol files, full PHP lint, HTTP, integration,
  migrations and durable jobs; schema unchanged at 172 relations. Final CSS-only
  specificity correction followed an observed global submit-button override.
- Deployed browser confirms all five actual inherited connectors show OpenRouter,
  with Configured runtime retained in the tooltip. Test computes rgb(101,82,41)
  while Save remains rgb(47,113,75); screenshot confirms the distinction.
- Final local rollback /var/backups/lorkhanserver-code.zwJMh4; all 789 files match,
  no extras or old paths, private 403/session 401 and health/NPC checks passed.
  Config, credentials and voices preserved. No game launch/control. No claim of
  full LLM, Player or all-page completion; pending work is recorded above.

## LLM inherited-endpoint API Key selection — 2026-09-07

- Moved the real API Key selector into both non-mock connection modes at the
  reference position below Provider. Old configured slots retain runtime inheritance;
  explicit None or a named key now overrides only the credential, not the endpoint.
  Key values remain private. Direct mode cannot inherit a runtime key and switches
  an inherited selection to None; mock controls remain inactive.
- LlmConnector validates configured credential references. ProviderFactory applies
  them through the shared slot section used by dialogue, profile generation and
  Oghma extraction. Groq catalogue requests and cache identity use the chosen key
  while retaining the fixed runtime endpoint guard and existing CSRF checks.
- Portable exports and imports reset non-mock key bindings to None, including
  configured endpoints, so receiving a file never picks up a recipient's saved key.
  This intentionally also affects legacy configured connector files without a binding;
  ordinary existing saved connectors are not changed.
- Actual disposable browser save/reload preserved configured mode with OpenRouter
  selected; restoring Inherit saved/reloaded correctly. Switching to Direct changed
  the key to None and disabled the Inherit option. Screenshot verified the dropdown
  and missing-key notice in the reference position. No live settings changed and
  no real provider request issued. Isolated server was stopped cleanly.
- Existing unit coverage now checks inherited/None/explicit credentials across three
  adapters and rejects invalid references. Extended existing HTTP cases check save,
  re-open, sanitized export/import and restoring inheritance. The first new HTTP
  assertion used the fixture parser on externally associated form controls; adjusted
  it to inspect selected options and submit explicit fields, matching existing tests.
- Final pass: 510 server checks, 98 protocol files, PHP lint, JavaScript syntax,
  HTTP forms (including the new cases), integration, migrations and durable jobs.
  Schema remains 172 relations and the previous summary hash. Initial misplaced
  validation edit was caught by unit checks and corrected before any deployment.
- Live GLM 5 editor shows the API Key selector in the reference position, retaining
  Inherit runtime API key, with configured/missing choices listed and no secrets.
  No live Save or Test was clicked. Local rollback /var/backups/lorkhanserver-code.DCETAL;
  all 789 deployed files match, no extras/old paths, private 403/session 401 and
  health/NPC checks passed. Config, credentials and voices preserved. No game control.
  Full hub/narrow/interactive editor review and the remaining page matrix stay open.

## LLM Test dialog and embedded reader — 2026-09-07

- Replaced JavaScript-enabled Test navigation with a native dialog using Herika's
  90%/1200px/80vh geometry, dark backdrop, Close/Escape/outside close, loading
  indicator and bordered status panels. The no-JavaScript POST redirect still works.
  API JSON summaries reuse the existing saved-connector test; runtime failures return
  a generic code rather than internal exception text. Test does not save editor drafts.
- Browser exercised the actual disposable MockProvider result, retained an unsaved
  name, and verified Close returned focus to Test. A saved direct fixture targeting
  unused loopback port 9 produced the safe failure reader; Escape closed it. The
  same failure reader was checked inside Configuration Hub without losing the tab.
- 390px fixture used current rendered HTML/CSS/JS with all remote requests blocked
  and a delayed mock test result. Compared against copied pinned Herika reader CSS;
  corrected the reader to 16px body and 13px preformatted text, plus gold heading.
  Narrow content wraps and Close remains visible. No live Test, provider credentials,
  or game interaction. Both temporary fixture servers were stopped.
- This reader intentionally shows validated status and the fixed synthetic test input,
  not fabricated raw/debug payloads. Herika's full request/response/internal-buffer
  diagnostics are not yet captured by Lorkhan; this is still an explicit remaining
  reader gap. Provider autosave-on-Test is also not copied: the UI clearly states
  saved settings, preserving the established non-mutating Test behavior.
- Passed 510 server checks, 98 protocol files, PHP lint, HTTP forms (HTML and JSON
  test summaries), integration, migrations and durable jobs; JS syntax/whitespace
  passed. Final changes after that pass were reader CSS sizing only.
- Local rollback /var/backups/lorkhanserver-code.O1ESk3; all 789 runtime files match,
  no extras/old paths, private 403/session 401 and health/NPC checks passed. Config,
  credentials and voices preserved. Full remaining page/editor matrix stays open.

## LLM Test request and response diagnostic panels — 2026-09-07

- Replaced the placeholder debug panel with reference-style saved connector,
  validated response/actions, actual request payload and usage panels. JSON is
  rendered as text, with explicit unavailable/mock states, not synthetic raw data.
- OpenAiCompatibleProvider accepts an optional diagnostic observer on the existing
  streaming method. It captures the final request body before transport and the
  validated normalized result, only for the explicitly requested management test.
  Ordinary dialogue calls do not collect/copy diagnostics. Transport headers and
  raw internal buffers remain absent; no returned action is executed.
- Diagnostic copies use existing sensitive-key/Bearer redaction plus removal of the
  selected API key from nested array/object string values. Objects remain objects,
  including empty YAML mappings. Each diagnostic body is capped at 128 KiB with
  an explicit truncation marker. The actual provider request is not modified.
- Existing HTTP mock-server test compares the diagnostic request with the body the
  server actually received and checks response text plus key/header exclusion.
  Existing unit coverage tests nested-object key redaction and preserved JSON shape.
  Passed 511 server checks, 98 protocol files, PHP lint, HTTP forms, integration,
  migrations and durable jobs; JavaScript syntax/whitespace passed. No schema change.
- Actual disposable browser test showed mock response data and correctly reported
  no remote request. A direct connector to unused loopback port 9 retained its
  real request on failure and showed response/usage as not captured. Long request
  text wrapped within the scrolling panel; Close remained accessible. Temporary
  server stopped. No live provider request, production edit or game control.
- Local rollback /var/backups/lorkhanserver-code.aglY8H; all 789 runtime hashes match,
  no extras/old paths, private 403/session 401 and health/NPC checks passed. Config,
  credentials and voices preserved. Reference autosave-on-Test behavior, full import
  interactions and remaining page matrix items are not claimed complete here.

## LLM Test save-first interaction parity — 2026-09-07

- Matched the pinned Herika Test handler's save-then-test sequence. The visible
  notice now explicitly says Test saves settings and may incur provider charges.
  This supersedes the earlier saved-settings-only UI choice; standalone API test
  calls still test saved records without implicitly revising them.
- The editor validates native controls, snapshots its associated form, awaits an
  explicit JSON save success and only then submits the existing provider test.
  Save failures/ambiguous replies do not proceed to testing. The dialog distinguishes
  an unconfirmed save from a saved configuration whose provider test failed.
  The no-JavaScript notice instructs users to Save first before the fallback Test.
- Existing provider revision endpoint now negotiates a minimal JSON success for
  this flow, with the same validation/CSRF checks and unchanged HTML redirects.
- Actual isolated browser changed name/model without clicking Save, then Test
  reported the new name/model at revision 2. Invalid YAML entered in the actual
  Ace editor was rejected and produced the save-unconfirmed/test-not-run state.
  No production settings/provider request/game control. Fixture server stopped.
- Passed 511 server checks, 98 protocol files, PHP lint, HTTP forms including JSON
  save success/rejection, integration, migrations and durable jobs; JS syntax and
  whitespace checks passed. Final additional markup only explains the JS-off case.
- Local rollback /var/backups/lorkhanserver-code.GdvRuE; all 789 files match, no
  extras/old paths, private 403/session 401 and health/NPC checks passed. Config,
  credentials and voices preserved. Read-only live editor shows the new notice.
  Import interactions and the remaining full page matrix are still open.

## Embedded LLM editor follow-up — 2026-09-07

- Compared the deployed Lorkhan Configuration > LLM > GLM 5 editor with the
  read-only Herika counterpart in the same 1280 x 720 browser surface.
- Header, sidebar/editor start, two-column boundary, service icons and advanced
  settings panel follow the reference geometry. The Lorkhan gold accent,
  explicit inherited defaults, protected assigned-connector Delete state and
  truthful save-before-Test notice remain intentional differences.
- The hub retains the release exclusions (ITT and Server Plugins); its tab groups
  therefore wrap differently from Herika. No controls or settings were changed
  in either live installation and no provider test was executed.
- This is populated desktop embedded evidence only. File-picker import/export,
  remaining narrow/editor states and other incomplete matrix rows remain open.

## Copy reference presentation, rewire native behavior — 2026-09-07

The user explicitly confirmed the implementation method: use Herika page markup,
styles and interactions as the starting point, then replace reads/saves with
Lorkhan bindings. Do not keep approximating counterpart layouts component by
component. Preserve native security, OpenMW semantics, branding and exclusions.

### Player ElevenLabs overrides

- Copied the pinned Herika Player provider-panel markup and CSS, including field
  order, two-column grid, full-width V3 Enhancers and connector-default choices.
  Adaptations are escaped Lorkhan values, validated limits, a CSS class instead
  of the CSP-blocked inline grid span, gold border and narrow-width stacking.
- The existing Player save writes bounded `player_elevenlabs` profile settings.
  Blank fields remove overrides. The speech context supplies them for player
  identity only; the ElevenLabs request builder applies them without changing
  the shared connector or any other provider's request. Model-specific v3 tags
  and Speaker Boost behavior use the existing adapter rules.
- Existing unit, management HTTP and integration tests extended: 519 checks,
  98 protocol files, forms, integration, migration/durable jobs all pass. Schema
  remains 172 relations with unchanged hash. No new test file or migration.
- Disposable PostgreSQL/HTTP fixture browser: created a synthetic ElevenLabs
  connector without credentials or Test; selected it on Player; saved/reloaded
  model, speed, disabled boost and tags; hid/reopened the panel by changing the
  connector without losing values; keyboard-cleared overrides and saved/reloaded
  blank inheritance. Populated screenshot caught and verified the full-width
  enhancer correction. Live player configuration and providers were untouched.
- Full Player-page completion is not claimed: remaining broad matrix work and
  narrow/reference visual evidence stay open. No game was started or controlled.
- Local deployment completed with rollback `/var/backups/lorkhanserver-code.aNbT3M`.
  All 789 runtime files match source, with no extras or old paths; health and
  protected-route checks passed. Configuration, credentials and voices preserved.

## Shared Player/Narration reference stylesheet — 2026-09-07

- Copied the complete pinned `ui/css/player-narration.css` into Lorkhan and loaded
  it on both pages after their page-specific styles. Only the gold accent and
  selector bindings to native page/field classes are translated. Existing native
  reader/form rules remain; the shared reference rules now control their common
  surfaces, header, labels, inputs, hints, toolbar and responsive layout.
- Corrected the ElevenLabs provider grid breakpoint to the reference's 768px.
- Reviewed real empty Player and Narration fixture pages on desktop. Compared
  the live read-only Herika Player at the same viewport. Also compared 390px
  Player render with Herika in a side-by-side wrapper: one-column cards, header
  wrapping and toolbar sizing work. The Lorkhan side uses disposable rendered
  fixture HTML with submission disabled, because its frame policy forbids
  embedding the active application across origins. No frame policy was weakened.
- 519 server checks, 98 protocol files, management HTTP, integration and migration/
  durable-job checks passed. No schema change. This shared-style checkpoint is
  not full Player/Narration or all-page completion; remaining matrix work remains.
- Deployed runtime verified: 790 files, no hash mismatches/extras/old paths;
  health/protected-route checks passed. Rollback: `/var/backups/lorkhanserver-code.1hk8bI`.

## Narrator Profile & Voice and embedded save — 2026-09-07

- Matched the reference Profile / Voice ID / Oghma Knowledge Tags order and
  primary field markup. Native TTS override, language and generation-routing
  explanation stay under Advanced routing rather than displacing those fields.
- Tags save to `oghma_knowledge_tags`, already consumed by the narrator-selected
  effective settings and Oghma retrieval path. Input is bounded to 4096 UTF-8
  bytes and normalized with existing profile tag rules. Empty means inherited
  global tags. V2 narrator exports carry the field; imports accept and normalize
  it, while older V2 presets without the field preserve the local value.
- Real browser save testing found a nested-Configuration-hub bug. Narrator forms
  now carry embed state; create/revise/generate/import embedded redirects target
  the canonical narrator editor with installation and status, not another hub.
  Standalone legacy redirects remain unchanged.
- Compared the Profile & Voice panel against live read-only Herika; saved a
  disposable narrator with duplicate/article-only tags, confirmed normalized
  values in its hub editor, cleared them, and verified the corrected embedded
  save keeps one hub and the saved editor accessible. No live settings/provider
  requests/game changes. Full Narration and all-page parity remain incomplete.
- Remaining Narration fields are not styling exceptions: the reference exposes
  `books_only_narrator`, `hide_from_context`, `only_diary_access` and
  `latest_diary_context_enabled`. Lorkhan's book-event switch and profile-context
  switch have different semantics; the latter two diary controls are absent.
  These require traced backend wiring before their reference controls can be
  copied truthfully. The current checkpoint does not close that requirement.
- Final checks passed: 519 server checks, 98 protocol files, HTTP forms including
  normalized/oversize tags, export/import, legacy preset preservation and embedded
  redirects; integration, migrations and durable jobs. Schema unchanged at 172
  relations. Runtime verified at 790 files with no hash mismatches/extras/old paths.
  Local rollback: `/var/backups/lorkhanserver-code.AZxXWi`; private routes and health
  pass, and configuration, credentials and voice files are preserved.

## Narrator diary/context dependency audit — 2026-09-07

Authoritative backend comparison uses `git show` / `git grep` at Herika 529364c,
not its current local checkout: the checkout's latest-diary helper still excludes
Narrator, while the pinned revision has the narrator override implementation.
The pinned UI already used throughout this matrix contains that newer control.

### Next implementation: latest diary inheritance, not a standalone checkbox

- Herika `lib/chat_helper_functions.php:17-92` reads the optional narrator value
  from core_narrator, otherwise inherits Core Profile metadata
  `LATEST_DIARY_CONTEXT_ENABLED`. Explicit false overrides an enabled Core
  Profile. Ordinary NPCs sharing that profile ignore the narrator override.
  Selection is the author's latest diary ordered by game timestamp, local time
  and row ID. `unittests/tests/DiaryMemoryRecallTest.php` covers this separation.
- Lorkhan `DiaryGenerationPolicy` currently has `include_in_context` but no
  latest-entry flag. `ProductRepository::promptContext` selects up to 100 narrative
  records from the session and active profile; `PromptAssembler` puts the bounded
  selected narratives in Morrowind context. This is not a guaranteed latest-entry
  block in character context. Renaming that existing flag would be incorrect.
- Implement a validated Core Profile latest-entry default, narrator-only optional
  override and explicit source selection. Copy the reference Core Profile and
  Narrator controls together. Preserve existing narrative inclusion independently;
  do not silently redefine it or mutate shared profiles on Narrator save.
- Select a non-deleted diary from the active author's profile within the same
  installation/playthrough; freeze its identity/content in the existing prompt
  selection and account for it in prompt budgets/source diagnostics. Define the
  native recorded game-time ordering explicitly rather than using wall time
  without checking available diary provenance.
- Required proof: enabled/disabled/inherited/explicit false; NPC sharing the Core
  Profile unaffected; newer unrelated author/playthrough excluded; empty/deleted
  entries; actual final assembled prompt placement and source accounting; copied
  controls, save/reload, export/import and embedded browser state.

### Other controls remain separate work

- `only_diary_access` in pinned Herika filters diary-classified memory recall;
  when false the narrator may recall NPC diaries. Lorkhan's generation handler
  currently writes narrative_records, and its narrative selection remains scoped
  to active/session profiles. Trace recall and diary-derived representations before
  widening selection or copying this toggle; do not treat the existing bounded
  narrative list as full recall parity.
- `hide_from_context` concerns narrator dialogue visibility, unlike Lorkhan's
  profile-context toggle. Filter source events using canonical identity, before
  history limits, while preserving stored events and narrator's own context.
- `books_only_narrator` constrains who summarizes books; Lorkhan's book-event
  enablement switch is not equivalent. It remains a runtime-routing dependency.

This checkpoint records verified implementation requirements only. No product
code, provider calls, live configuration or deployment changed in this audit.

### Latest diary setting foundation (2026-09-07)

- Added the typed `diary.latest_entry_in_context` leaf, default false, separately from existing `include_in_context` narrative recall.
- Existing resolver inheritance now accepts the leaf. Focused checks cover Core Profile true/false defaults, absent author overrides, explicit Narrator true/false, and a second NPC sharing the Core Profile while installation Narrator content is present.
- This is a foundation only: no UI control is exposed and prompt selection/rendering is not implemented yet. Do not count the latest diary feature as complete or deploy this as a working user feature.
- Continue in `ui/core/tmpl/core_profile_fields.php`, Core save/preset mapping in `ManagementRouter`, Narrator save/import/export and its copied control, `ProductRepository::promptContext`, and `PromptAssembler`. Preserve source trace/budget attribution in NPC context; do not misreport the selected diary as ordinary Morrowind narrative context.

### Latest diary controls and prompt wiring (2026-09-07)

- Copied Herika 529364c Core Profile diary-card wording and Narrator switch markup/help, with native field and CSS class bindings. Both controls save through existing authenticated revision forms.
- Core presets retain the new diary leaf. Narrator presets support an optional nullable latest-diary override: missing preserves current state, null restores inheritance, true/false saves an explicit author override. Existing diary generation fields remain separate.
- Prompt selection reads the active author's latest non-deleted diary within the installation/playthrough, independently of the mixed narrative list. Native entries use creation time with a deterministic ID tie-breaker. Empty latest entries produce no block. Unprofiled bystanders cannot borrow the session author's diary.
- Assembly adds the bounded entry and its title to character context and records its existing narrative source in the NPC context trace. The same entry is removed from generic narrative recall to avoid repetition.
- Evidence: 526 server checks, 98 protocol files, management HTTP saves/imports/exports, integration selection and source-attribution checks, migrations/durable jobs, PHP lint and unchanged 172-relation inventory passed. Browser fixture 58464 verified copied switch appearance and save/reopen; Core Profile checked-card rendering inspected. Reference Narrator page remained read-only. Fixture stopped normally.
- This closes the previously absent latest-diary control/runtime path, not the whole Narrator or Core Profile parity row. Narrow layout, additional Narrator diary-access/history/book semantics, full Core built-in presets and the broader page matrix remain open. No paid provider or game calls were made.

### Narrator switch row sizing correction (2026-09-07)

- Live reference measurement: Herika switch row 40px high, border-box, 38px minimum height, 7px/10px padding. Lorkhan was 54px because its page stylesheet explicitly forced content-box.
- Removed the divergent box-sizing override. Deployed browser measurement now matches 40px/border-box at 1280px, with no horizontal overflow; screenshot inspected. This fixes every Narrator switch without changing values or behavior.
- CSS-only validation: diff check, deployed computed styles and visual review; prior 526-check full suite remains the latest backend evidence. No additional backend test claim for this one-line stylesheet deletion.

### Embedded LLM comparison and Player switch correction (2026-09-07)

- Compared populated GLM 4.7 editors inside both live Configuration hubs at 1280px. Header, list/editor column split, editor controls, service-icon row and right-hand parameter panel align. Native connector labels, saved values, release-excluded hub tabs, gold accents and the accurate save-before-Test note remain intentional differences. No connector settings were saved or tested during this read-only comparison.
- Removed the same divergent content-box override from Player switch rows. Deployed browser inspection confirms all three Player diary switches are 40px border-box rows; populated Player screenshot reviewed. This is CSS-only and leaves Player settings untouched.
- Full LLM narrow-layout and import/interaction acceptance remains open; this comparison is desktop populated-hub evidence only. No backend suite rerun was needed for the one-line stylesheet deletion; deployment hashes and browser sizing provide the focused proof.

### Narrator spoken-history visibility (2026-09-07)

- Added Herika's `Hide Narrator from NPC Context` switch and help text in Core Settings, using the existing copied switch renderer. Default true matches pinned 529364c Narrator settings. Existing profile-information visibility remains a separate setting.
- The history query filters canonical Narrator `chat` rows before its candidate and recent-turn limits. Explicit false retains otherwise eligible speech. Narrator-targeted turns retain their own history. World narration events and NPCs merely named The Narrator remain visible. Source events and eventlog records are never changed by this setting.
- Save and portable Narrator preset import/export carry a strictly boolean field; old presets without it preserve current values. Existing HTTP tests cover imported false and older preset shape, while integration probes cover default hiding, false, Narrator own history, world narration, and display-name independence.
- Validation: 526 server checks, 98 protocol files, management HTTP forms, integration, migrations/durable jobs, PHP lint and unchanged 172-relation schema passed. Isolated browser default-on/off switch appearance inspected and Save exercised; fixture stopped normally. No live provider or game calls.
- Remaining reference behavior is not claimed complete: handling player inputs addressed privately to the Narrator and diary-derived recall needs its own scoped audit. This control covers direct spoken-history visibility; it is not a deletion or comprehensive memory-isolation feature.

### Strict response validation diagnosis and pending contract correction (2026-09-07)

- Saved failed GitHub run 34156573898 showed all integration/migration checks passing before Draft 2020-12 validation rejected accepted gamedata type `journal`. The acknowledgement schema had four types while the request schema already had nine.
- Pending schema correction references the request's type definition rather than duplicating its enum. All 119 freshly captured server responses pass jsonschema 4.23.0 with format checking. The full local suite also passes (526 checks, management HTTP, integration, migrations and unchanged schema inventory). Protocol byte counts/hashes were regenerated with LF bytes, matching Git attributes.
- The pending Narrator-addressed input filter also passes: NPC histories omit typed Narrator-targeted input regardless of speech-visibility toggle, Narrator keeps its own input, and an ordinary NPC named The Narrator remains unaffected. Source rows are unchanged.
- Neither pending change has been pushed or deployed. Shared protocol synchronization must be completed before publishing: current LORKHAN origin/main is 7925957b94ff8759a8decc7c87ef7e9456539de5, with its clean checkout at D:/wt/lorkhan-journal-stages. Other inspected local client worktrees were stale. The current client acknowledgement also has the four-type enum. Server manifest byte-parity status is temporarily not-revalidated, not an acceptance claim.
- Do not restore deleted workflow runs or disable future workflows. User-requested cleanup removed 96 completed failed server runs; successful runs remained. No game was launched or controlled.

### Shared contract correction verified (2026-09-07)

- Reconciled the acknowledgement schema in isolated LORKHAN worktree D:/wt/lorkhan-gamedata-ack, based on current main 7925957. No engine or Lua changes.
- Regenerated both MANIFEST.json and SHA256SUMS. Existing strict client validation passes 38 Draft 2020-12 schemas and 60 fixtures; the server comparison proves schemas, fixtures and manifests byte-identical. All 119 captured server responses pass strict response validation.
- This supersedes the pending contract status above. Narrator-addressed input behavior retains the previously recorded full-suite evidence. These changes do not establish completion of the remaining visual page matrix.

### Narrator diary author access (2026-09-07)

- Copied the `Narrator only diary access` switch and help from pinned Herika 529364c into Core Settings, default false. Save, optional V2 preset import/export and old-preset preservation use the existing revision-safe handlers.
- Narrator diary selection now admits NPC authors from the same installation/playthrough when disabled, or only the selected Narrator author when enabled. Deleted authors/entries and Player/template profiles are excluded. Legacy NPC identity documents without an explicit kind follow the existing actor convention. Other narrative kinds and NPC retrieval are unchanged.
- Prompt assembly permits the selected Narrator diary authors while still rejecting foreign installation/playthrough sources. Existing integration fixtures prove both author modes, assembled diary content and both foreign-scope rejections. This retains the native bounded recency selection; broader relevance-ranked diary recall remains an open comparison, not a claimed full Herika memory-bank implementation.
- Existing suite passed: 526 checks, 98 protocol files, management HTTP create/save/import/export including old presets and unchecked false, integration, migrations/jobs and unchanged 172-relation inventory. Strict validation passed the accumulated 400-response capture. Browser fixture 58464 showed the copied switch, saved true and reopened checked; both reference and fixture measure 40px rows. Reference settings were not changed and the fixture stopped normally.
- Earlier server CI run 34158088580 for d55930d finished successfully. Full Narrator layout, book summary routing and the rest of the page matrix remain open. No game or paid-provider test was performed.

### Narrator Core Settings grouping (2026-09-07)

- Moved native context-visibility, automatic wait and timer controls into a collapsed `Additional narration controls` disclosure. Preserved field names, values and server handlers. The main diary sequence now follows Herika: Narrator Diary, Narrator Auto Diary, Narrator only diary access, Include Latest Diary Entry.
- Browser fixture verified keyboard expansion, checked wait and a 240-second timer saving/reopening while the disclosure returns collapsed. Expanded controls screenshot checked against the existing compact row/input styles; PHP lint and diff check passed. This presentation-only change does not claim a new full backend test run or complete narrow-page acceptance.
- Book-summary routing is still open: Herika books_only_narrator controls who handles chatnf_book; native book_events is a different setting transported in client settings. Do not relabel it as the reference switch or claim the settings are equivalent. The inspected client includes narrator-book source markers but no bookEvents consumer was found outside protocol transport/bindings. Continue tracing the actual command producer before changing runtime behavior.

### LLM file-picker round trip and embedded redirects (2026-09-07)

- A real Chromium file chooser against the isolated fixture imported a downloaded-shape JSON connector, retained its mock model/options on re-export and stayed embedded. A batch containing malformed JSON displayed `Invalid JSON; no files were imported.` before submitting any imports. A browser download produced matching connector content. No live keys/providers were involved.
- Found and fixed LLM forms dropping embed state: create, save, rollback, clone, delete and import now carry embed=1 and return to the embedded page. Saved connector IDs reopen their editor; installation selection is retained from the saved record or submitted scope. Standalone redirects are unchanged.
- Existing management HTTP tests pass with an embedded import regression check. Browser create/import/delete redirects and file upload/download were exercised; PHP lint and diff check pass. This does not claim a fresh full integration/migration run for the redirect-only change.
- Independent Chromium screenshots allow actual 390px viewport review without altering the user browser. Lorkhan mock editor stacks at 390px without overflow. Read-only Herika's populated direct-connector editor measured 737px scroll width at that viewport; screenshot reviewed. Different connector modes and the reference overflow mean this is not full narrow-editor parity acceptance. Continue with matching direct modes, advanced controls, empty/list states and the remaining matrix.
- Disposable browser contexts closed and fixture stopped. No game, reference writes, provider tests or live configuration changes.

### Speech connector return-state and provider-view comparison (2026-09-07)

- TTS/STT already had implicit embed=1 in their legacy return routes; unlike LLM, their primary defect was losing the selected connector/installation after form actions. Forms now carry explicit scope/embed state, and the shared connector redirect reopens the saved record. Existing query strings are replaced with the complete return query, avoiding a second question mark. Standalone handling is unchanged.
- Existing management HTTP suite passed with embedded TTS import and STT save checks, including single-query-separator assertions. 526 server checks, PHP lint and diff check passed. Isolated Chromium exercised Inworld TTS create/save and Local Whisper STT save, verifying parsed status=saved and retained edit/installation IDs. No synthesis or transcription request was sent.
- Compared 1280px Parakeet STT views against the read-only live reference and inspected the Inworld TTS saved-editor screenshot. STT centered header, sidebar width, grouped provider rows, two-column Name/Service fields and provider-settings placement agree. Gold branding, synthetic field values, native transport disclosure and the unresolved Google Free control remain visible differences; this is not acceptance of all provider controls or narrow states.
- Browser contexts closed; disposable fixture stopped. No live configuration, reference files, keys or game state changed.


### Response log narrow table follow-up (2026-09-07)

Compared the live Roleplay AI Responses pages at 390px. Herika computes an
`auto` table layout; the native 880px minimum kept nearly every column out of
view. Removed that minimum and the fixed percentage widths for this table,
using the reference automatic column sizing, wrapping and line height. Kept
the scoped horizontal scroll region, prompt reader and immutable data intact.
Restored the receipt icon and non-wrapping View Prompt label, slash pagination
and muted page count. Navigation intrinsic group widths match the pinned
reference CSS; absent excluded tabs are not a reason to stretch the groups.

Source reference: HerikaServer 529364c4c12b3a8bd4cc12a481f400ce19b3a344,
ui/events-memories.php and ui/css/hub-navigation.css. Local screenshots remain
in Temp because they contain private live dialogue. Browser comparison used
read-only live records with the candidate stylesheet intercepted locally; PHP
lint and git diff --check passed. This does not complete the other page matrix
rows or claim populated Books/Diaries equivalence from empty data.


### Calendar visible-state follow-up (2026-09-07)

Compared Adventure Log and Diaries against the pinned Herika calendar source
and live 1280px/390px screenshots. Restored 100px calendar rows at narrow
widths and removed permanent entry counters inside day cells. Counts now use
the reference interaction hint, also exposed on keyboard focus and in each
date link's accessible name. Diary Regular/Tamrielic/Person controls remain
together in one row with wrapping labels. Kept working Morrowind month
navigation rather than reproducing the reference's clipped narrow heading.

Reference-derived files: ui/tmpl/roleplay_calendar.php and
ui/css/roleplay-logs.css; reference ui/css/diary_adventure.css at
529364c4c12b3a8bd4cc12a481f400ce19b3a344. PHP lint and diff check pass.
Read-only deployed browser proof at 1280 and 390 pixels covers populated
Adventure dates, empty Diaries, focus count hints, date selection, Tamrielic
mode, and Diary person mode. Screenshots were visually reviewed and remain
in Temp (lorkhan-calendar-{adventure,diaries}-{1280,390}.png). No live data,
provider calls or game controls were changed. Populated diary entry-reader
comparison remains open; this is not full page-matrix acceptance.


### Populated diary reader comparison (2026-09-07)

Compared the live Herika 2026-08-02 diary reader with a long synthetic diary
in disposable PostgreSQL/HTTP ports 55464/58464 at 1280x900 and 390x900.
Removed the native mobile-only top/padding/height override: parchment now
uses the reference 80px top offset, 98% width, 40px padding and fixed audio
footer at both sizes. Visually inspected both populated readers. The native
long entry scrolls within the paper; Export Text preserved its exact content
and Close dismissed the dialog. No TTS requests were made. Existing Export
and accessible Close actions were preserved. Diff check passed.

Screenshots are local-only Temp/{herika,lorkhan}-populated-diary-{1280,390}.png.
Fixture content is synthetic; reference screenshots are private. This closes
the populated paper-layout comparison, not all Diary workflows. Author-view
search and the reference's Open The Diary book/print reader still need review
and implementation where absent. Reference: ui/css/diary_adventure.css at
529364c4c12b3a8bd4cc12a481f400ce19b3a344.


### Diary authors and printable book (2026-09-07)

Replaced the loose author buttons with Herika's centered search/list/count
presentation. Search is case-insensitive, has an accessible name and shows
an empty result state. Counts include all active diary entries for that
author in the selected installation/playthrough. Added Open The Diary and
ui/diary_book.php with the reference sticky print action, MagicCards title,
chronological parchment entries and print stylesheet. CSS and JavaScript are
external for the existing strict content security policy. Source: Herika
ui/diarylog.php, ui/diary_book.php and ui/css/diary_adventure.css at
529364c4c12b3a8bd4cc12a481f400ce19b3a344.

Book selection uses exact profile, installation and playthrough IDs, not the
reference's substring name match. Missing/invalid scope returns 400/404.
Entries are escaped, exclude soft-deleted/non-diary records, and are read in
chronological order without the paginated calendar limit. Text uses pre-wrap
without extra br elements so stored newlines are not doubled.

Existing management HTTP suite passed, including book content, invalid scope
and cross-installation/playthrough rejection. PHP and JS syntax/diff checks
passed. Disposable browser checks at 1280 and 390 pixels covered search match,
no match, author selection, count, opening the complete book, print-button
handler and print-media control hiding; Chromium generated test PDFs. The
OS print dialog was not opened. Visually compared real Herika author/book
views against synthetic native entries and corrected row typography and
title word spacing. Screenshots/PDFs remain in Temp, not Git. No live diary
records, credentials or game state changed. Other page-matrix rows remain open.


### Populated Books follow-up (2026-09-07)

Compared live Herika Books and isolated native long/short books at 1280 and
390 pixels. Shared theme important rules had overridden the neutral header
and alternating row colors. Restored those with table-scoped selectors,
matched text metrics, removed the forced 650px minimum and used natural
word wrapping so timestamps remain intact. Replaced the empty table grid
with Herika's empty panel; filtered emptiness is described as no matches.
Reader preserves literal HTML-looking text and closes with Escape. Existing
installation/playthrough filters and scoped export remain available.

Reference ui/events-memories.php at 529364c4c12b3a8bd4cc12a481f400ce19b3a344.
PHP lint, diff check and disposable browser interaction checks passed;
screenshots visually inspected and retained privately in Temp as
{herika,lorkhan}-books-populated-{1280,390}.png and lorkhan-books-empty-390.png.
No live books or game data were modified.


### Event Log shared-theme correction (2026-09-07)

Live populated Event Log comparison at 1280 and 390 pixels found the same
shared-theme overrides as Books: headers and striped rows were repainted.
Restored table-scoped neutral headings and alternating row backgrounds,
with the gold game-time heading preserved. Restored the row-delete button
boundary and kept preset/Delete controls together at narrow widths. Paging
and Hide controls wrap as a group. No event behavior or source data changed.

Visually reviewed candidate styles over live read-only data using an isolated
Chromium stylesheet interception. Selected and unselected a row to verify
Delete Selected visibility without submitting any deletion. No event filter
preferences or live-refresh state were changed. Both widths retained scoped
table scrolling and the preset/Delete controls shared one row. Diff check
passed; no backend tests were claimed for this CSS-only change. Reference
ui/events-memories.php at 529364c4c12b3a8bd4cc12a481f400ce19b3a344; local
private screenshots: Temp/lorkhan-events-aligned-{1280,390}.png.


### Config Hub connector comparison (2026-09-07)

Captured 28 live read-only Config Hub states across Profiles, NPCs, LLM, TTS,
TTS Studio, STT and API Keys at 1280/390 pixels. Capturing is not acceptance:
visually reviewed LLM and TTS desktop entry views, TTS Studio desktop and
STT narrow views. Remaining captured pages still need visual inspection.
The reference has excluded ITT/Server Plugins buttons; these stay absent.

Compared the same DeepSeek Chat V3.2 OpenRouter editor in both products at
1280/390. Desktop form hierarchy, service icons, model/provider/API-key rows
and advanced settings columns match; saved values were not changed. Native
mobile stacking stays usable where reference's side-by-side editor clips.
Removed the extra native No connector selected card; retained its instruction
for screen readers. New/import/edit branches are unchanged. PHP lint and diff
check passed. No connector test, key update or provider generation occurred.

Screenshots remain private in Temp/lorkhan-config-hub-review and
Temp/{herika,lorkhan}-matching-llm-{1280,390}.png. The live products select
different speech providers, so TTS Studio controls still need matching-provider
comparison rather than treating these entry screenshots as full acceptance.


### TTS Studio matching-provider upload view (2026-09-07)

Compared Inworld in both products at desktop/narrow widths. Removed hard-coded
provider-tab minimum widths so the reference's content-sized tab packing can
work. Restored the green upload action and file-selector styling, and made
the upload description specific to cloud generation versus local sync. Real
Lorkhan file limits, protected local storage, optional naming and explicit
cloud-generation consent remain unchanged. Active status is not relabelled
Connected without a connectivity probe.

The persistent 16px top gap was not header padding: voice-preview.js prepended
an empty status paragraph. Added a class and hid that paragraph only while
empty; actual status messages still render. Both 1280 and 390 fixture renders
now start the header at 20px, matching Herika. File chooser and custom name
interaction checked without submitting an upload. PHP/JS syntax and diff
checks passed. Screenshots visually compared in Temp as
{herika,lorkhan}-studio-inworld-{1280,390}.png and
lorkhan-studio-aligned-{1280,390}.png. No new upload/clone/generation claim;
provider cache/batch and OmniVoice language states still need their remaining
comparisons. Reference ui/xtts_clone.php at
529364c4c12b3a8bd4cc12a481f400ce19b3a344.


### Inworld cache and pre-batch estimate review (2026-09-07)

Visually compared populated Inworld voice-cache and batch sections at 1280
and 390 pixels. The cache uses the reference three-column/card hierarchy,
with native provider selection and upload consent retained. Reference has
additional regenerate/provider-copy-removal actions for cached cloud voices;
native cached rows currently expose preview but need a separate action audit.
Do not mark cloud cache actions fully accepted from layout alone.

Added the missing pre-start estimate in the reference batch-section position,
using the same delay variable as the browser queue: (cached missing count - 1)
times 3 seconds for Inworld or 2 for Cartesia. It describes request spacing
separately from unknown provider processing, rather than claiming a measured
completion time. No delay for local providers and no spacing estimate for a
single voice. Deployed Inworld with 21 cached missing voices showed 60 seconds
and retained consent/button controls at both widths. No batch was started,
no voices uploaded/generated/deleted. PHP lint and diff checks passed;
screenshots remain private in Temp/{herika,lorkhan}-inworld-{cache,batch}-
{1280,390}.png. Reference ui/xtts_clone.php at
529364c4c12b3a8bd4cc12a481f400ce19b3a344.


### Cloud cache lifecycle audit and connector scope (2026-09-07)

Pinned Herika tts/tts-inworld.php and tts/tts-cartesia.php distinguish forgetting
an ID from deleting a remote managed voice. Rebuild clones and validates audio
before publishing the replacement; it deletes the previous remote copy only
when installation-owned metadata matches that exact ID. Native runtime uses a
credential/workspace-scoped file cache, separate from Studio's connector catalog.
A catalog-only delete or a second upload button would not provide that parity.
Remaining: shared cache lifecycle, ownership metadata, validated replacement,
managed remote deletion, and matching populated/empty button states and interactions.
These remain open; no existing real remote voices were changed by this audit.

Fixed a prerequisite found by this trace: Studio discovery, individual generation
and each batch generation used global cloud credentials, ignoring the selected
connector badge/workspace. They now construct a scoped CloudVoiceLibrary from
that connector content, matching playback's credential defaults and explicit
None behavior. Inworld discovery is workspace filtered and cloning uses the
workspace endpoint. Local provider paths are unchanged. Six assertions added to
the existing unit suite verify selected-account discovery/upload for both cloud
providers, workspace routing/filtering, and blank/None refusing global fallback;
532 server checks and PHP/diff checks passed. No visual structure changed here;
this is necessary backend rewiring, not new screenshot or cache-action acceptance.

The existing isolated management HTTP form suite also passed for this change.


### Cloud cached-ID removal and confirmation wiring (2026-09-07)

Added the reference cached-ID forget action to populated Inworld/Cartesia sample
cards (x control beside preview). The confirmed POST removes the selected
connector catalog mapping and the playback cache under the same credential,
workspace and nonblocking lock as runtime registration. No cloud request, remote
voice deletion or local WAV deletion occurs. Missing local samples and unsupported
connectors are rejected; existing CSRF validation applies. Forgetting an ID can
be followed by rediscovery on the next use, as in Herika; it is not a remote delete.

Browser interaction exposed an existing duplicate lorkhan-management.js include
in Studio (also included by the shared head). Removed the Studio include so
confirmation fires once. Isolated browser checks for both providers exercised
invalid CSRF, cancel, confirm, persisted uncached state, and retained local sample.
Both credential-scoped JSON files and catalog entries were gone afterward; the
WAV remained. Five assertions in the existing unit suite cover busy registration,
idempotent removal, no provider calls, preserved samples and other workspaces,
and selected workspace removal. All 537 server checks passed.

Populated cards visually reviewed at 1280 and 390 pixels; uncached state reviewed
at 390 against the prior pinned-reference cache captures. Screenshots are private
Temp/lorkhan-unsync-{inworld,cartesia}-{1280,390,empty-390}.png. Cache actions are
still not fully accepted: regenerate/validation/owned remote deletion remain
open, as does their final action-row presentation. This change does not claim
that the remaining cache controls or every Studio state matches Herika yet.

The existing isolated management HTTP form suite passed after the cache action
and duplicate-script fix. The browser fixture was stopped and cleaned up.


### Validated cloud lifecycle backend (2026-09-07)

Implemented the backend needed by the remaining regenerate and managed-delete
controls, following pinned Herika tts/tts-inworld.php rebuildInworldVoice /
deleteManagedInworldVoice and Cartesia counterparts. Newly auto-created clones
now write managed=true with their cached ID atomically; discovered voices write
false and legacy caches remain unowned. No existing cache is upgraded merely
because its ID happens to be present. Ownership reads do not create cache files.

The rebuild operation locks the same account/workspace cache, clones the local
WAV using the existing Morrowind transcript, requires valid nonempty WAV audio
from its validation callback, and only then publishes the new managed ID. Failed
validation cleans up the candidate and preserves the previous mapping. A provider
response repeating the live ID is never deleted as cleanup. Only an owned previous
voice is removed after replacement; cleanup failure is returned separately while
the validated replacement remains active. Managed deletion rechecks ownership and
exact ID under the lock and leaves the mapping when the provider rejects deletion.
The provider adapter uses the reference DELETE routes, pinned official hosts,
existing selected credentials, no redirects, bounded responses and redacted errors;
successful empty DELETE responses are accepted.

Twenty-two assertions added within the existing provider test section cover both
services: ownership, stale IDs, validated replacements, invalid audio, candidate
cleanup failure, repeated live IDs, remote delete failure, old-clone cleanup
failure, preserved WAVs, legacy-cache rejection and external predecessor safety.
559 server checks pass. No live cloud requests were made. This checkpoint is
backend implementation only: Studio's regenerate button, owned-delete button,
validation-provider wiring and catalog reconciliation still need integration and
visual/interactive acceptance. Do not mark their matrix rows accepted yet.

Existing isolated management HTTP forms also passed for this backend checkpoint.


### Studio lifecycle controls and cache reconciliation (2026-09-07)

Wired cloud sample cards to the lifecycle backend: Play, Forget ID, Regenerate,
and owned-remote Delete in reference order. Regenerate is also present for cached
voices, with upload consent available even when no samples are missing. Owned
remote deletion is separate from forgetting; the cloud card no longer offers the
unrelated local-WAV delete (local-provider tabs retain local sample management).
Studio reads credential/workspace runtime mappings without creating cache files
or contacting providers, so automatically created clones are visible and only
matching owned IDs receive the remote-delete action.

Single and batch generation now validate through the selected cloud TTS connector
before publishing the runtime mapping, then replace prior ID/name catalog entries.
The validator uses the new ID directly, without calling the automatic resolver
again. Failed old-clone cleanup is shown in the single-action notice or per-voice
batch result. Forget/Delete remove the old ID from the selected catalog as well
as the runtime mapping, including renamed provider display names.

Compatibility constraint discovered during wiring: Lorkhan profiles and connector
defaults may reference a remote ID directly. Regeneration retains a predecessor
that still has explicit ID references and reports why; manual remote deletion
rejects referenced IDs. Otherwise switching a sample's cache could invalidate an
unrelated configured voice. This is a backend binding constraint, not a visual
exception. Two additional existing-suite checks cover retaining such predecessors;
561 server checks pass. Existing mock lifecycle tests cover positive replacement,
delete and failure behavior for both cloud providers.

Isolated browser checks for both providers passed at 1280/390: owned/external/
forgotten states, regeneration consent on client and server, CSRF and stale or
external deletion rejection, cancel, and successful cache forgetting. Reference
cache button structure was inspected at pinned 529364c. Screenshots in private
Temp/lorkhan-lifecycle-{inworld,cartesia}-{1280,390,forgotten-390}.png were reviewed.
No paid provider upload, synthesis or remote deletion was performed. Positive
cloud operations are mock-backend verified, not live-provider browser acceptance;
remaining Studio presentation differences and other provider states stay open.

The management HTTP suite passed after the reference guard. An additional
isolated form POST verified that an explicit connector voice-ID binding rejects
remote deletion. The browser fixture was stopped and cleaned up.


### Voice-card inline structure and typography (2026-09-07)

Replaced the stacked voice-identity wrapper with Herika's sibling sequence:
name, status, abbreviated provider ID, then action group. The name remains a
keyboard-accessible copy button; IDs retain their full value in the title while
displaying the reference 15-character abbreviation. Removed the obsolete wrapper
CSS and narrow-screen padding override. Corrected the shared button theme's
13.12px name font to the reference 15px/22.5px and removed its extra margins/min
height. Action buttons now use the reference 12px horizontal padding and 2px
margins. Native and reference cards measure 66px tall with 12px/16px padding at
1280 and 390; three-action groups measure 127.984px wide and 40px tall.

Normal names retain priority over the inline ID; very long names clip without
pushing actions beyond the card. Full names remain in the copy payload/title.
Isolated owned/external/uncached and long-name sample states visually compared at
1280/390. Keyboard Enter passed through the real copy handler to a fixture-only
clipboard stub (no operating-system clipboard change claimed). Consent, CSRF,
stale/external deletion rejection, cancel and cache forgetting checks passed again.
PHP lint and diff checks passed. No backend behavior changed and no new cloud
request was made. Screenshots: private Temp/lorkhan-card-aligned-{inworld,cartesia}-
{1280,390,forgotten-390}.png; reference source stays pinned at 529364c. Other Studio
provider states and the rest of the page matrix remain open.


### OmniVoice language-library selector and routing (2026-09-07)

Added the dedicated OmniVoice Language Library section above upload, copying the
reference heading, description, label, 420px form and 360px selector geometry.
Choices come from the configured local service's /voice_libraries endpoint;
invalid IDs are skipped and the selected/configured language remains available
when offline. Switching language preserves the connector and embedded route.
The OmniVoice tab now reads its selected speaker library automatically, as Herika
does, using the existing bounded URL policy and JSON fetch; cloud tabs retain
explicit discovery. This supersedes the older blanket no-provider-GET description
for this local-service tab only. No sample data or credentials are sent by these
GETs. The selected library replaces the connector's derived speaker catalog.

Fixed language resetting to the first cached row. The selected value now reaches
refresh, individual sync, batch import and JS preview; the preview endpoint accepts
a validated override only for OmniVoice and retains existing rate limits. Other
connectors keep their configured language. File upload still stages native local
WAVs; reference direct-import/upload presentation and remaining library readiness/
server-only voice states are not accepted by this checkpoint.

Extended the existing HTTP mock with English/French library metadata. Existing
form tests verified French selection and hidden action fields, successful French
WAV preview at the mock provider, invalid language rejection, and the unchanged
preview rate cap. The whole HTTP suite and 561 unit checks passed. A separate
isolated browser fixture compared the selector at 1280/390 with pinned Herika
529364c and verified selection/navigation; private screenshots are
Temp/{herika,lorkhan}-omni-language-{1280,390}.png. Also corrected shared Studio
section-heading line height and plain-paragraph margin/color to the reference,
without overriding colored status messages. Mock service and fixture stopped;
no live TTS generation and no game interaction.


### OmniVoice readiness and combined library (2026-09-07)

The main OmniVoice library now includes server-only voices and local samples,
using provider readiness rather than catalog membership for the ready tick and
Play control. Unready local voices remain eligible for individual and batch
import; server-only voices needing reference text do not offer a broken Play or
local-file deletion. Runtime-ready flags are recognized. Other providers retain
their existing readiness behavior. The heading and selected-language description
follow the reference; Refresh is visible outside connector details, and short
readiness labels align beside the right-hand actions instead of the name.

Evidence: 561 unit checks and the full management HTTP suite passed. Existing
HTTP tests cover ready, runtime-ready flag, needs-reference-text, French batch
import and the transition to ready. An isolated browser fixture repeated local
import through the actual form, confirmed Play appears only after readiness,
retained server-only voices after local sample deletion, and rendered the empty
library. Desktop/narrow screenshots are in private Temp/lorkhan-omni-states-
{1280,390}.png and lorkhan-omni-states-empty-390.png; compared against pinned
Herika 529364c screenshots. No live provider generation or game interaction.

This is not full Studio acceptance. OmniVoice direct upload/import, remote
voice deletion, error states, connector-details placement and exact refresh
button sizing still require comparison/implementation. All other open matrix
rows remain open.


### OmniVoice provider deletion contract (2026-09-07)

Replaced OmniVoice's local-sample delete button with the reference's custom
provider-voice delete action. Provider `can_delete`/`custom_voice` flags determine
availability. The authenticated, CSRF-protected form preserves connector and
language; the handler re-reads that library, rejects stale/non-custom IDs, and
sends DELETE /voices/{id}?language={language} only to the configured endpoint.
IDs follow the reference allowlist, redirects are disabled, and timeouts apply.
Successful deletion removes the derived catalog entry but never the local WAV.
The native POST response hides provider error bodies and reports failure without
claiming removal. Other provider tabs are unchanged.

PHP lint and the full management HTTP suite passed, including custom/non-custom
button availability, selected French DELETE URL, provider 500 failure, successful
204 deletion and stale second-submit rejection without another DELETE. The
existing card assertion was updated to allow its added OmniVoice CSS class.
These are mock HTTP results, not live-provider or browser confirmation-dialog
proof. Final visual/interactive deletion review and direct-upload parity remain
open, along with the full page matrix.


### OmniVoice direct upload and browser lifecycle (2026-09-07)

OmniVoice upload now imports each validated new WAV into the selected connector
and language library immediately. The shared upload validator returns exactly
the created files; other tabs keep local staging. Failed/not-ready imports keep
the local sample and explicitly offer Sync retry, without reporting all samples
as ready. Existing samples are still never overwritten. The form now follows
Herika's description, Import Voice Sample label, filename-based IDs, and three
reference-text requirements; the unrelated custom-name control is hidden here.

PHP lint and the full existing management HTTP suite passed, including French
multipart import success and provider failure with local sample retention. A
separate disposable browser/provider fixture passed actual upload -> ready Play,
delete cancellation, confirmed provider removal, and local WAV -> Sync recovery.
Screenshots at 1280/390 are private Temp/{herika,lorkhan}-omni-upload-{width}.png.
The narrow reference comparison identified and corrected requirements-list
indentation and the upload label. Provider details placement, refresh sizing,
remaining error presentation and other matrix pages are still open; this does
not certify full TTS Studio or whole-site parity. No live voice mutation or game
interaction occurred.


### Shared hub label typography and current matrix review (2026-09-07)

A fresh live Control Panel comparison found label spans inheriting Futura/Arial
from main.css despite MagicCards on their buttons. Added the reference label-level
font, 1.5px letter spacing and 5px word spacing to shared hub-navigation.css.
Computed browser styles now match pinned Herika 529364c for the label. The rule
covers Control Panel, Configuration and Roleplay without changing badge or emoji
fonts, supported tabs, routing, gold accents or data.

A browser-only stylesheet substitution tested current source at 1280 and 390px
on all three live hubs: expected computed styles, no document-level horizontal
overflow, and one selected tab after keyboard navigation. Screenshots are private
Temp/lorkhan-hub-label-{control,config,roleplay}-{1280,390}.png. Control Panel
reference screenshots are Temp/herika-control-review-{1280,390}.png. Narrow
Control Panel and populated Roleplay screenshots were inspected. The reference
Response Queue is empty, so its screenshot cannot validate populated row parity.
No live forms that mutate data were submitted. This CSS-only checkpoint uses
diff checks and browser proof, not a claim of a fresh backend test run.

The priority-page rows retain their earlier populated/empty/reader evidence;
Control Panel embedded child states, configuration children and remaining settings
features still require completion. Updated the stale TTS Studio matrix row to
point to completed lifecycle checkpoints without accepting remaining gaps.


### Control Panel embedded loading and reader state (2026-09-07)

Read-only browser checks against deployed 4e1f33c visited all twelve Control Panel
tabs at 1280 and 390px. Each reached its actual child URL and loaded content;
child document widths remained within the frame, and a temporary in-memory
marker survived switching away and back. The initial probe observed about:blank
before navigation settled and was corrected to await the real child URL; this
was a probe race, not evidence of a product reload defect.

The populated Request Logs reader opened inside the frame at both widths,
closed with Escape, and restored focus to its View button. An unsaved search
field survived a switch to Response Queue and back. No filter was submitted,
no clear/delete/backup/game action was executed, and no provider was generated.
Snapshots: private Temp/lorkhan-control-embedded-{tab}-{1280,390}.png and
lorkhan-control-request-reader-{1280,390}.png. Narrow Database Manager,
desktop Request Logs and narrow request-reader screenshots were inspected.

This proves embedded loading and the specified retained/reader states, not all
child functionality or visual parity of all captured screenshots. Database and
Playthrough Manager feature gaps, other child interactions and the full matrix
remain open. No product code change or runtime deploy was needed for this
verification checkpoint.


### Core Profile dynamic-history field and runtime mapping (2026-09-07)

The built-in preset audit found Herika CONTEXT_HISTORY_DYNAMIC_PROFILE had no
editable native counterpart: NPC and Narrator evolution both hard-coded 50.
Added Context History Dynamic Profile Event Count alongside the two existing
Context controls, using the reference 0-400 slider/number range, default 50.
Save, named/portable presets and revision-fenced Copy to all carry the optional
server-only history_limit leaf. Old profiles/presets without the leaf retain the
previous default. No client contract or migration changes were made.

Both evolution queue paths now read the assigned/default Core Profile limit.
Native retrieval still uses completed witnessed dialogue turns (explicit in the
help text), not a claim of identical Herika eventlog row semantics. Zero supplies
no history and leaves the existing history-unavailable guard in place. Existing
byte budgets and field-selection/locking/cadence safeguards remain.

564 unit checks passed. Existing integration fixtures prove a three-turn bound
for both NPC and Narrator jobs, with selected-field evolution unchanged. The
integration wrapper initially reported a stale inventory hash; its fresh-write
run completed migrations/jobs with 172 relations and only a generated hash diff,
which was reverted. Full management HTTP checks passed save/reload at 20, preset
export, and Copy to all at 12, including the existing authorization/revision tests.
Built-in preset mappings, remaining Core fields and final visual acceptance are
still open; this checkpoint does not certify full Core Profile parity.


### Dynamic-history zero inheritance correction (2026-09-07)

Pinned metadata_json_editor.php explicitly says zero inherits regular
CONTEXT_HISTORY. Corrected the previous checkpoint's zero/no-history behavior:
both evolution paths now fall back to effective memory.recent_turn_limit when
the explicit dynamic-history limit is zero. The UI help is corrected too.
Existing integration coverage proves NPC explicit limit 3 and Narrator zero
inheriting regular history 3. Vertical slice, migration/job and backup-restore
wrapper completed with the known regenerated inventory hash-only difference
reverted; no migration was introduced.

The deployed pre-correction field was inspected at 1280/390 and its number/range
inputs were checked bidirectionally using keyboard arrows without saving a live
profile. Private snapshots: Temp/lorkhan-core-dynamic-history-{1280,390}.png.
The reference editor navigation resolved a profile list item but did not expose
the expected metadata control to the probe, so a rendered counterpart comparison
is still unproven; source label/range/help were inspected instead. Do not treat
this as final visual acceptance or full Core Profile preset parity.


### Diary Context range and inheritance (2026-09-07)

The pinned Core metadata editor permits diary context 0-400 and defines zero as
inherit regular Context History. Native validation/UI previously allowed 1-100.
Updated validation and the paired range/number control to 0-400; the diary job
uses effective regular history for zero. Expanded the bounded candidate query
from 300 to 1600 rows so a larger selected turn limit can be reached, while
retaining the 64 KiB context budget and existing witnessed/delivered filters.
Defaults remain unchanged. Named and portable presets use the same validation.

565 unit checks and the full management HTTP suite passed, including saving,
reopening and exporting/importing diary history 150. The existing diary job
fixture exercises zero inheriting a one-turn regular limit, with an older eligible
event excluded. No provider calls or game actions were made on the live runtime.

Reference editor DOM inspection resolved edit=1 and found Context/Diary controls
but no dynamic-history control in its live field inventory. Its absence differs
from the pinned metadata source and explains the previous screenshot lookup
failure; do not confuse that live-reference gap with a native navigation bug.
The ordinary Context control's range and remaining preset mappings remain open.


### Core field icons, labels and Rechat toggle (2026-09-07)

Compared the desktop Context/Diary area with Herika. Copied the metadata field
icon pattern onto the nine existing native controls, including diary/book,
context/brain and rechat icons. Rechat Response Rounds, Context History Event
Count and Context History Diary Event Count now use the reference labels.
Rechat Probability intentionally retains correct spelling. Rechat Allow Actions
now has the reference larger green checkbox and On/Off state rather than a small
checkbox labelled Enabled. Reused the existing change/reset label synchronizer.
No field names, values, persistence or runtime behavior changed.

PHP lint, JS syntax and full management HTTP suite passed. An isolated browser
fixture verified all nine icons, Space-key On/Off label updates, and rendered
1280/390 screenshots; the narrow screenshot was inspected. Files are private
Temp/lorkhan-core-field-icons-{1280,390}.png. Reference desktop screenshots are
Temp/herika-core-diary-history-1280.png. Lorkhan retains its usable stacked narrow
layout; the observed reference narrow editor overflow is not copied. Bored Event,
Combat, Quest and other missing settings still require runtime mapping, so the
full paired-section structure and Core Profile acceptance remain open.


### Bored Event / Combat runtime dependency audit (2026-09-07)

Authoritative inspected heads: LorkhanServer 7bf81ba and clean LORKHAN checkout
D:/wt/lorkhan-gamedata-ack at 6daab0c, matching origin/main. Herika stays pinned
at 529364c. Neither client code nor protocol was modified in this checkpoint.

The missing sections cannot be completed by exposing the current settings under
Herika labels:

- BORED_EVENT is a 0-100 NPC conversation probability. The native client has
  behavior.boredom and boredom_delay_seconds only. orchestrator.lua:513 checks
  the idle delay, optionally routes to Narrator based on narrator.bored_chance_percent,
  then always requests NPC boredom. Narrator routing probability is not the
  missing NPC probability and must not be reused or relabelled for it.
- COMBAT_BARK_COOLDOWN supports 10-600 in the reference UI. Native
  client-settings.schema.json, SettingsCatalog, both protocol_response.cpp
  settings decoders (703/787), and orchestrator.lua:504 use a 300 maximum.
  A web-only range increase would be rejected or clamped by the current client.
- player_state.applyTargetSettings writes one shared behavior snapshot. The
  scheduler then chooses nextCombatActor/nextBoredActor independently. A profile
  control cannot be claimed actor-specific without resolving the selected
  automatic actor's policy and rejecting stale session/generation/target replies.
- CoreProfilePreset currently captures Rechat but not these behaviors. Default,
  Local LLM, Follower and Passive mappings must include the real supported
  probability/cooldown policy before the built-in presets are called equivalent.

Implementation sequence:
1. Complete the client repository's required reading gate before client edits.
2. Extend the synchronized typed settings contract, validation, DTO/decoder,
   bridge and Lua state for a distinct NPC boredom probability; define omission
   compatibility so the currently deployed client/server pair keeps working.
3. Raise combat cooldown support together in server and client; preserve existing
   saved values instead of silently changing defaults or re-enabling autonomy.
4. Make autonomous candidate policy resolution actor-specific with session and
   generation fencing. Apply the chance once per eligible idle interval, resetting
   the attempt timer on a failed roll so it is not retried every frame.
5. Add the reference Bored Event and Combat sections, safe save/copy/preset paths
   and matched field help. Keep Narrator boredom routing separate.
6. Extend existing native parser/Lua tests for probability 0/100, failed-roll
   cadence, 600-second cooldown, two actors with different profiles and stale
   candidate responses. Verify protocol manifests in both repos, build/deploy
   without launching the game, then compare populated/empty and interactive UI.

This is an implementation-ready dependency audit, not completion of the sections.
The existing deployed runtime remains unchanged. Quest comment mappings and the
rest of the full page matrix also remain open.


### Custom API badge display labels

Reference: HerikaServer 529364c4c12b3a8bd4cc12a481f400ce19b3a344,
ui/core/api_badge.php custom card Label/Save editor. Native custom labels now
remain editable after creation and reload, using that existing card structure.
The private label sidecar changes display metadata only; LLM, TTS and STT keep
their saved credential identifiers. Environment-owned credentials remain read-only.
Native secret inputs deliberately stay blank rather than copying reference secret
prefill behavior. Label writes share the existing serialized browser save queue
and have a bounded timeout; deleting a managed key also removes its display label.

Evidence: 567 unit checks passed, including unchanged credential resolution and
no environment-secret leakage through labels. Isolated browser create, rename,
reload retained the identifier and blank secret field. Desktop 1280 and narrow
390 screenshots were inspected (private Temp/lorkhan-api-label-{width}.png).
Rendered fixture LLM and TTS options showed My Voice & Chat with their original
values. The browser fixture has no STT connector, so that browser check does not
prove STT; the existing populated management HTTP suite covers its selector.
No real credential edits or provider requests were made. Full API provider-badge
consolidation and the remaining page matrix are still open.


### Core Profile Diary Cooldown control

Pinned metadata_json_editor.php and the read-only live reference both define
Diary Cooldown as 10-1200 seconds. The native field now uses that name and range
for normal profiles. The server validation and automatic-diary query accept the
10-second minimum instead of silently enforcing 30 seconds. Player and Narrator
editors also accept that minimum. Existing values above 1200 remain valid (up to
the previous 86400 ceiling) and expand the loaded Core Profile control so a save
does not truncate an existing configuration. The default remains 120 seconds;
no automatic behavior was enabled or saved user values changed.

Native create-state number/slider synchronization at 10 and 1200 passed. Native
1280/390 screenshots and the reference desktop Diary section were visually
inspected; the field uses the same label, slider/number order and minimum/normal
maximum. No reference form was saved. The surrounding Core Profile sections are
still incomplete: absent Bored Event, Combat and Quest controls mean the overall
paired-row layout is not accepted. Current cooldown semantics are automatic
entries only, not proof of parity for manual diary requests or game timer cadence.

Regular Context History is another coordinated client dependency: both native
protocol_response.cpp decoders reject values outside 1-100, matching the server
client-settings/controls schemas. Herika exposes 0-200. Widening only the HTML
control would break accepted settings; extend the synchronized contract and test
zero-history prompt semantics before exposing that range.

The preceding API Keys edit also had three status ellipses misencoded during a
Windows text rewrite. These are corrected to UTF-8; JavaScript syntax passes.

Validation for this checkpoint: 568 unit checks, the management HTTP suite
(including 10-second save/export/import), vertical-slice integration, and migration/
durable-job tests passed. The cooldown regression verifies immediate suppression
and eligibility after a recorded 20-second interval when configured for 10.
The schema writer again produced only the known inventory hash difference with
172 relations; that unrelated generated difference was reverted. No schema,
protocol or client files changed, and no game was launched or controlled.


### Narration player speech-style template and prompt dialog positioning

Added the reference player_speech_style_prompt row to Advanced Prompts, sharing
its revisioned document with Prompts Manager. Its saved template is frozen into
new player speech-style jobs, validated by the worker and used in the provider
system instructions. The JSON speech_style result contract remains mandatory.
Existing queued jobs without the field retain the previous default. Clear restores
the factory template; existing player biography, guidance, editor draft, revision
checks and explicit final Save behavior remain unchanged. This does not yet add
the reference placeholder substitutions or the four inline narration templates.

An actual visual defect was found while testing at a deep page scroll: shared
prompt dialogs inherited absolute positioning and opened above the viewport
(y=-4774.5 in the 390px fixture). They now use fixed viewport positioning. After
the correction the same dialog occupies y=19.5 through 919.5 at a 1000px viewport.
Native 1280/390 dialogs were visually inspected against the read-only live Herika
player speech-style editor: title, description/default/custom order, 300px custom
editor and footer layout agree apart from branding and native prompt text/help.
The isolated browser passed save, reopen, Escape, Clear confirmation via Save,
and restored Default state. No real provider call or reference save was made.

569 unit checks passed, including observation of custom system instructions and
the output contract before network I/O. Management HTTP tests verified the shared
Narration/Prompts Manager save path. Integration and migration/durable-job tests
verified the installation-scoped template is frozen when queueing. The known
schema-inventory hash-only change was inspected and reverted; no migration or
client contract changed.

Quest control dependency: current orchestrator queueNarratorEvent accepts only
Narrator quest/book routing and applies narrator.quest_chance_percent. There is
no equivalent NPC quest-comment route to wire into Core Profile controls. Do not
relabel Narrator probability as an NPC profile setting; the client candidate,
policy, cooldown and generation fencing work remains required, alongside Bored
Event, Combat and regular Context History range changes already documented.


### Narration inline template editor parity

Added all four reference inline keys, in reference table order:
dialogue_line_inline_response_narrator, inline_narration_prompt_narrator,
dialogue_line_inline_response_npc, and inline_narration_prompt_npc. They use the
existing shared Prompt Key/Description/Status/Preview/Actions table and revisioned
Default/Custom editor, including Clear-to-default. Definitions and selection are
derived from HerikaServer 529364c4c12b3a8bd4cc12a481f400ce19b3a344,
ui/core/narrator_management.php and prompts/dialogue_prompt.php; native files are
prompts/NarratorEventPrompts.php, prompts/PromptAssembler.php and the existing
shared UI renderer. Reference repos were not modified.

The enabled Narrator mode selects its two instructions; NPC and Text Only select
the NPC pair. Disabled mode and direct Narrator dialogue omit them, matching the
reference dispatch boundary. Current target name, Narrator name and word limit
are substituted. The native target placeholder is {NPC_NAME}, not a Skyrim product
name. Only relevant custom templates are loaded into each frozen dialogue request;
the player speech-style job likewise loads only its own template. Existing inline
speech routing remains in InlineNarrationRouter, with no client protocol change.

Browser fixture proof covers all four readers: save, reopen, 1280/390 viewport
bounds, Escape and Clear/default restoration. Representative native desktop and
narrow screenshots were visually inspected against the live read-only reference
inline Narrator editor: title, default/custom order, 300px text area and footer
match aside from branding and native wording/help. Screenshots remain private in
Temp/lorkhan-inline-*.png and Temp/herika-inline-narrator-1280.png. No reference
save, real provider call or game interaction was performed.

581 unit checks passed, including all mode selections, direct Narrator exclusion,
and NPC/name/60-word substitutions. The management HTTP suite passed with all
four form keys present. This closes the missing inline editor controls, not the
remaining whole-page Narration/Core Profile acceptance or game audio proof.

Final integration rerun passed the vertical slice and migration/durable-job suite
after restricting template loads. The known hash-only schema inventory difference
was inspected and reverted; there is no schema migration in this checkpoint.


### Narration placeholder support and measured toggle spacing

The player speech-style editor now documents and substitutes the four reference
placeholders from HerikaServer 529364c4c12b3a8bd4cc12a481f400ce19b3a344,
ui/cmd/action_player_generate_speech_style.php: PLAYER_NAME, PLAYER_GUIDANCE,
CURRENT_SPEECH_STYLE and DIALOGUE_SAMPLES (each in braces). Empty guidance/current
style use the reference None provided./None set. fallbacks. Dialogue samples are
formatted as a bullet block. The template is still server-owned and frozen at
queue time; generated output must still satisfy the native JSON speech_style
contract. Expansion size is computed before strtr allocation and limited to 64KiB,
preventing repeated sample placeholders from producing unbounded provider input.

Full-page header/section enumeration and top-of-page screenshots were compared
read-only at 1280px. A visual discrepancy was confirmed by actual bounding boxes:
Herika toggle rows were 54px while native rows were 40px, despite matching padding,
margin, gap and border radius. Herika's 38px content-box minimum includes another
16px padding/border; native global border-box sizing had collapsed that spacing.
The Narration-specific minimum is now 54px. Player-page sizing was not changed.
New native 1280/390 screenshots and a keyboard toggle were checked without saving;
measured boxes are 54px at both widths and the page has no horizontal overflow.
The narrow layout was visually inspected, not accepted solely from metrics.

582 unit checks passed, including exact placeholder substitution in the outgoing
system message and expansion rejection before network I/O. The management HTTP
suite passed. No database/schema/protocol changes or real provider requests.

Whole-page acceptance remains open: importing/exporting before a native Narrator
profile exists is absent from the toolbar, saved Narrator name is still read-only,
book-event enablement is not the reference NPC book-summary restriction, and the
native catalog has no Narrator actions. These are remaining gaps to investigate,
not blanket product-specific exceptions or a completed Narration page.


### Editable Narrator display name

The saved Narrator Name field now remains editable, matching the reference Core
Settings field. It submits the existing display label alongside the persona and
Core Profile assignment. A scoped transaction locks the selected Narrator row,
checks the expected revision, updates only name and actor_identity.display_name,
and saves the content revision. Profile UUID, kind, record ID and historical
source events remain unchanged. A failed route assignment, stale revision or
cross-installation request cannot partially rename the profile. This supersedes
the read-only-name gap recorded in the preceding review.

Browser fixture proof: create Fixture Narrator, rename to The Chronicler, reload,
confirm the same profile ID and editable value, capture 1280/390. The narrow
post-save page was visually inspected; the name remains in the reference Core
Settings position and Export/Import toolbar is present once a profile exists.
No live Narrator name was changed and no game or provider interaction occurred.
HTTP tests passed rename/reload and stale-save rejection, and now reload revisions
between intentional successive edits. The existing 582 unit checks also passed.
The database regression covers preserved record identity and content, stale
revision rejection, and installation scope. Full page acceptance still requires
the remaining book restrictions, empty-profile import lifecycle and action gaps.

The vertical-slice integration and migration/durable-job suites passed. The known
schema inventory hash-only difference was inspected and reverted; no migration
or protocol change is included.


### Narration file-picker import flow

Reference: HerikaServer 529364c4c12b3a8bd4cc12a481f400ce19b3a344,
ui/core/narrator_management.php and ui/js/settings-portability.js. Replaced the
divergent Narration modal/JSON textarea with the reference toolbar file picker,
file-size/JSON/package validation, field-count confirmation, result alert and
reload flow. Native strict preset validation remains authoritative; this does
not imply CHIM package compatibility. Player import is unchanged.

The AJAX endpoint now returns JSON only when requested, preserving ordinary HTML
form redirects. Browser testing caught and fixed an initial redirect that saved
the import but made the browser report a JSON parse error. The existing HTTP
suite now explicitly checks this response contract. Confirmation explains that
unsaved page edits will be discarded; only the import package is posted, and
Narrator name, identity and connector selections are preserved.

Disposable browser fixture: invalid JSON, wrong package schema, cancellation,
actual toolbar file chooser, successful import/reload, and stable name/profile
ID passed. Desktop 1280px and narrow 390px post-import screenshots were visually
inspected. The redundant modal is absent and the toolbar remains usable at both
widths. JavaScript syntax and 582 unit checks passed. No real profile, provider
or game interaction was used.

Remaining Narration gaps include import/export before a profile exists, reference
partial-field import semantics, NPC book-summary restrictions and Narrator
actions. This checkpoint does not establish whole-page or all-page acceptance.

The full management HTTP suite passed with the new AJAX response assertion.



### Partial Narration imports preserve omitted fields

Both native Narration preset versions now accept a subset of supported fields,
matching the reference promise that absent settings keep their current values.
The complete current portable shape is used only for validation; only explicitly
supplied keys are applied to the stored content. This also prevents v1 imports
from resetting newer cooldown/chance settings to defaults. Explicit empty text
still clears its field. Player presets retain their existing behavior.

The confirmation now states the omission behavior. Existing strict type, size,
secret and unknown-key validation remains: unlike Herika's skip-and-report flow,
unknown or invalid keys reject the package. That difference, empty-profile
import/export, prompt portability and remaining Narration features are still
open; this is not full import or page acceptance.

The existing HTTP suite passed partial imports and explicit clears for both v1
and v2, equality of every omitted exported field, and invalid/unknown rejection.
582 unit checks and JavaScript syntax passed. A disposable browser fixture passed
the actual file picker, confirmation, save/reload and equality of all omitted
settings, plus preserved name/profile ID. The updated 390px screenshot was
visually inspected; no layout changes were required. No live profile or game
was changed for these tests.


### Priority AI Responses Prompt Viewer structural correction

A fresh read-only live comparison against Herika exposed a remaining mismatch
that earlier open/close checks did not prove: native boxed message cards and a
centered 1100px reader differed from the reference inset Prompt Viewer with role
color rails. Replaced that presentation using pinned ai-response.php structure
and styles: 90%/1600px dialog, 3% top margin, single scrolling surface, blurred
backdrop, MagicCards title, Copy/close toolbar, 20px inset panel, adjacent role and
index labels, semantic role colors and 13px/1.8 prompt text. Gold action branding
is retained; stored text remains escaped and unchanged. Styles are scoped to AI
Responses so Books and Diary readers are unaffected.

Current live 1280/390 screenshots were inspected after deploying the actual PHP
template and CSS. Open/close and Escape passed at both widths. The new desktop
structure was compared with the live reference screenshot, not just dimensions.
582 unit checks and PHP lint passed. Screenshot files remain private in Temp.
No game, provider operation or reference mutation was performed.

The connector/driver/model badge row remains absent and must be wired from safe
recorded metadata. Empty-prompt and Copy success/failure states still need a fresh
comparison for full reader acceptance. Control Panel responses is Response Queue,
not the Roleplay AI Responses page; these have separate counterpart rows.

The full management HTTP suite passed for this Prompt Viewer checkpoint.


### Prompt Viewer recorded badges and visible Copy feedback

Added driver/model pills from the latest successful complete_turn LLM attempt
for the recorded turn, using the actual fallback attempt when applicable. The
query projects only provider_name and model; it never returns provider settings,
credentials or raw attempt metadata. No join to today's connector name is used:
that historical display label was not recorded and remains a parity gap.

Copy feedback now appears beside the button rather than below the whole prompt.
A read-only browser test substitutes only the page's clipboard API to check exact
copied text equality and denial feedback without writing the desktop clipboard.
Both cases passed at 1280/390; two recorded pills render. The first narrow review
showed feedback squeezing the title, so nonempty feedback now uses a second
header row on narrow screens. Existing 582 checks and full HTTP suite passed.

The empty-prompt state and historical connector label remain open for full reader
acceptance. No live log was edited and no game or provider action was performed.


### Freeze future Prompt Viewer connector labels

Installation/actor connector resolution now includes the name in its server-side
snapshot. Successful LLM attempt metadata retains that frozen name, including
fallback connectors. Prompt Viewer reads only the recorded name and presents it
as the reference label pill before driver/model. Older attempts without a label
are not backfilled from mutable current configuration. No secret/provider body
is selected for this badge.

ProviderFactory initially rejected the additional snapshot field; integration
caught the failure before publication. It now validates an optional bounded UTF-8
name and removes it before processing provider options, while old snapshots remain
valid. The existing fallback integration case verifies both frozen snapshot name
and successful-attempt label. Vertical-slice and migration/durable-job suites
passed, as did 582 unit checks. The generated schema hash-only difference was
inspected and reverted; no schema migration is introduced.

Populated three-badge and empty-prompt visual acceptance remains open. Historical
responses with missing label evidence deliberately retain only recorded badges.
No new live conversation, provider request or game interaction was triggered.


### Prompt Viewer empty and three-badge state review

Rendered the actual roleplay_logs.php function with two synthetic records: one
with all three recorded badges plus system/user/assistant messages, and one with
no recorded prompt or badges. Loaded the generated fragment into the live page
shell with the candidate stylesheet and production dialog script. This is
source-rendered visual fixture proof, not a new database-backed conversation.
The separate integration test above proves snapshot-to-attempt label persistence.

Inspected screenshots at 1280/390 and exercised Escape. Label text containing
literal angle brackets stays escaped; all three pills and role rails render.
The empty state now uses the reference inset preformatted panel rather than an
unstyled paragraph, while accurately stating that frozen messages are absent.
No live dialogue/history/configuration was changed. The disposable management
HTTP suite also passed and its fixture was stopped cleanly.

These checks close the previously recorded empty/three-badge visual gaps for
this reader. They do not establish all-page acceptance or convert missing
historical labels into invented data.


### Roleplay navigation label typography correction

Fresh live comparisons found the inner label-span override forced MagicCards,
1.5px letter spacing and 5px word spacing. Herika's button declares MagicCards
but its child span actually computes Futura CondensedLight, 0.6px letter spacing
and 1px word spacing at 1280px. Added a Roleplay-only rule using Futura and
inheriting the button's responsive spacing. Decorative group headings, icons,
colors and the other hubs are unchanged.

Compared computed styles after serving the candidate CSS in the isolated browser
context: label face, size (12.3px), letter spacing and word spacing now match.
Inspected both 390px screenshots: the same Activity & Logs buttons are readable
in one row. Different group heights follow the intentionally excluded features
and native Journal, not a reason to restore excluded navigation. This supersedes
the earlier assertion that explicitly setting MagicCards on label spans matched
the reference. No backend/data changes. git diff --check passed.


### AI Responses page pagination and column preferences

Restored the reference bottom Previous/Next navigation; native previously showed
only a footer count. Both locations use the same scoped link builder, retaining
playthrough, installation, search, person and date. The source-rendered middle
page fixture verified equal top/bottom destinations and preserved filters at
1280/390. Footer typography and neutral buttons follow the reference, while top
paging uses the primary gold treatment.

Removed the later width:auto override on response headers. Herika has automatic
table layout but retains inline percentage preferences (11/29/16/12/20/7); these
are now restored with 8px narrow cell padding. Earlier auto-layout evidence did
not justify deleting the reference percentages. Actual rendered desktop/narrow
fixture screenshots were inspected. Existing safe HTTP-request summaries and
native filters remain; no raw transport payload is exposed.

PHP lint and diff checks passed. This is presentation/link construction proof,
not a new database pagination fixture or full priority-page completion.


### Events navigation action states

Fresh live Events screenshots at 1280/390 confirmed the table/toolbar structure
but found neutral paging actions where Herika uses primary actions and a neutral
current page. Scoped Events CSS now uses gold for available paging and stopped
Auto Refresh, gray for the current page, and wraps long pagination groups.

Read-only browser proof loaded page two through the production API, selected all
16 visible rows, observed Delete Selected, and unselected them. No Delete button
was pressed and no saved hide filter was changed. Computed current/action colors
match the intended distinction at both widths; the selected narrow screenshot
was visually inspected. Scope disclosure and native context-preservation wording
remain. Saved-filter and empty-state comparisons are not claimed by this check.
CSS diff checks passed; no backend/protocol change.


### Events saved-filter and empty-state proof

Used a disposable PostgreSQL/HTTP fixture with one synthetic chat projection and
its own profile/playthrough. Production UI saved the chat hide filter, reloaded
with its chip intact, showed an empty result, then removed the chip and restored
the original escaped event. No live hide preferences or event data changed.

This exposed two defects: empty results incorrectly said no events had ever been
recorded, and the centered message was outside the narrow viewport because the
empty table retained a 980px minimum. Server and refreshed-client renderers now
say no events match the current playthrough and filters. Empty-only tables drop
the minimum width and hide irrelevant column headers; populated tables retain
their normal structure. The final 390px screenshot was visually inspected and
the message is visible. Hide/reload/unhide passed again after the change.

PHP lint, JavaScript syntax and diff checks passed. This is actual saved-filter
fixture proof; no delete, game command or provider operation was invoked.


### Diary primary toolbar and calendar side inset

Fresh read-only 1280/390 captures reviewed Adventure Log, Diaries and Books in
both live servers. Native Diaries had a fourth Create / Generate Entry action
in the primary download/delete row. Moved that native tool into the existing
Filters and playthrough disclosure, retaining the same narrative_manager route.
The primary row now contains the reference three actions. Calendar readers use
the reference ten-pixel side inset instead of the generic reader's twenty pixels.
Other readers and generation behavior are unchanged.

Deployed-template browser checks at both widths verified two download links plus
the delete control, the tool hidden initially, and the same accessible tool link
after expanding secondary controls. Both screenshots were visually inspected.
PHP lint and diff checks passed. No generation/delete/provider action occurred.

The current native Books view is empty while Herika is populated; this fresh
comparison does not replace the separately recorded populated Books fixtures.
Adventure narrow navigation remains usable with full month label, unlike the
reference's clipped live narrow header; preserve readability rather than copy
that clipping defect. Full matrix acceptance remains open.


### LLM editor hub review and shared navigation labels

Compared the same named DeepSeek connector editor in native/reference standalone
embedded routes at 1280/390 and inside Configuration Hub at 1280. Waited for the
actual selected input before final screenshots, avoiding an initial navigation
transition capture. Inspected populated editor screenshots and enumerated fields.
Existing native connection controls remain secondary; the previously documented
unused reference Remove Action Prompt switch remains absent rather than inert.
Native hub selection survived switching to TTS and back without saving.

The Test dialog was opened directly as a geometry-only check, without invoking
its Test handler, saving settings or calling any provider. It is already fixed
and viewport-contained: 1152x720 at 1280 and 351x720 at 390, including a scrolled
page. The narrow screenshot was inspected. This does not claim a new test-result
round trip; those mocks are covered by earlier evidence.

The hub comparison exposed the remaining shared label-span MagicCards override.
Moved the verified Futura/inherited-spacing correction into hub-navigation.css
for every hub and removed the now-redundant Roleplay-only override. Decorative
headings/icons remain unchanged. Final native/reference hub screenshots were
visually compared after the candidate CSS loaded. No live settings changed.
Full connector edge-state and remaining page acceptance remain open.

## TTS connector toolbar and section geometry checkpoint

Compared saved Inworld editors on both live servers at 1280px and 390px,
using independent read-only browser pages. All eleven visible field labels
match in order. Corrected the native heading font shorthand that reset
line-height to normal: both sections now measure 18px font / 21.6px line height,
matching Herika. Restored reference toolbar geometry across native POST forms:
Save/Test/Export 36px, Clone/Delete 40px, weight 500 and line-height 1.2.
Cloud connector cards omit the extra endpoint line, as in the counterpart;
local connector endpoint information remains. Saved endpoint values are unchanged.

Actual deployed desktop and narrow screenshots were inspected. Narrow layouts
stack controls without horizontal overflow (390px client/scroll width). The
live connectors have different saved models, voices and list counts; these
were not overwritten. Native in-use deletion protection remains. The reference
and native toolbar text widths still vary by approximately 1-2px, and sidebar
button spacing remains a follow-up rather than a claim of exact visual identity.
No Save, Clone, Delete, Import or provider Test was invoked. This checkpoint
is not acceptance of every TTS provider panel or the whole-site parity goal.

Evidence: private Temp/tts-current-review.cjs and paired
{herika,lorkhan}-tts-review.png / -tts-review-narrow.png captures.
PHP lint, git diff --check and 582 existing server checks passed. Code was
deployed locally with configuration, credentials and voice contents preserved.

## TTS test reader presentation and mocked result states

Opened the actual Test controls on both live saved Inworld editors without
submitting reference synthesis. Compared desktop 1280x1000 and narrow 390x900
screenshots. The reference reader computes flat #232323 cards and 13.44px /
19.488px text inputs; native still had gradient cards and 14px / 21px controls.
These now match, as do the Run Test button weight (500) and line-height (1.2).
Close uses the reference secondary styling. Success and error statuses now
use the reference green #9be29b and red #ff9898; generation clears prior state.

Private Temp/tts-test-states.cjs intercepts every preview request in an isolated
browser: 429, 403, 422, 500, 200 non-audio and network failure all show red,
restore Run Test, and never render the synthetic private response payload.
A synthetic silent WAV shows the green success state and player. Escape clears
the audio source; closing a held pending request and reopening resets the form
button and hides stale results. Eight requests were intercepted, none forwarded
to the live API or a provider. This is browser state proof, not synthesis or
server authorization proof. Screenshots tts-test-error-390.png and
tts-test-success-390.png were visually inspected alongside paired modal captures.

The native validated voice picker, charge notice, redacted request preview,
focus-contained dialog and immediate audio cleanup remain. Unlike the reference,
raw provider debug output is not exposed. Native narrow title sizing remains
smaller to keep the close control clear; the whole TTS section is not marked
complete by these checks. PHP/JavaScript syntax, diff checks and all 582 existing
server checks passed. No saved connector values or game state changed.


## NPC Info implementation sequence: confirmed structural gap

Current source and live read-only inspection supersede the vague remaining
General/Info item. Herika's Info panel exposes Emote Moods, Skills, Current
Equipment, Character Stats, Inventory, Spells, Metadata (JSON), and Setting
Overrides (current rows, Add, Edit, Remove and advanced JSON). Native exposes
Emote Moods, Effective NPC settings and sources, Notes and Change Reason.
The native effective-settings disclosure is not a replacement for an editor.

Private Temp/npc-info-review.cjs captured both actual Info panels. The reference
was opened through its observed card data-id and existing edit/partial route;
native was opened in its actual modal. These captures prove missing content,
not a matching-viewport geometry comparison: the reference partial was loaded
standalone. Earlier card-to-iframe attempts timed out and are not evidence.
No settings, favorites, locks, generation or game commands were submitted.

The next implementation must address the contract before adding controls:

1. Replace the old inert-NPC-override contract deliberately. ProductService's
   validateProfile accepts settings_overrides, but EffectiveSettingsResolver's
   NPC layer does not merge them. tests/run.php currently explicitly asserts
   that NPC behavior and routing overrides stay inert. The existing green test
   suite therefore proves the old policy, not Herika parity.
2. Derive an explicit NPC override catalogue from reference supported controls,
   mapping each to native typed leaves and its actual consumer. Start with
   response limits, conversation memory and Rechat, whose effective-setting
   paths already exist; do not mark the full catalogue complete at that subset.
   Trace installation-wide scheduling separately before exposing its leaves.
3. Apply explicit NPC values after global/Core settings, preserving false,
   zero and empty values. Removal restores inheritance. Keep dedicated diary
   and voice controls consistent, preserve unrelated document fields and do
   not let the generic editor overwrite actor identity or system routing.
4. Adapt the reference override component into the actual NPC Info template:
   current rows and Add/Edit/Remove, typed choices/search, advanced JSON and
   source/help text. Stage changes in the existing NPC revisioned Save form.
   The copied ui/core/tmpl/override_editor.php currently is not included by
   the active NPC renderer and contains inline code and a remote JSON-editor
   import; it cannot simply be enabled under the existing CSP.
5. Add observed-data disclosures from installation/profile-scoped immutable
   actor snapshots. Do not present authored biography Skills as in-game skill
   values or fabricate Skyrim slots, actor stats or spells. Confirm native
   snapshot fields and ownership before rendering. Match reference empty
   messages when no observation exists. Keep raw private payloads out of HTML.
6. Extend existing resolver and management HTTP tests: precedence and source,
   false/zero, deletion to inherit, invalid value, stale revision, cross-owner
   refusal and atomic failure preserving the NPC content. Verify actual
   downstream prompt/Rechat behavior, not only stored JSON.
7. Compare populated, empty, add/edit/remove, rejected-save and restored-inherit
   states visually at desktop/narrow and in the real modal. Then publish and
   deploy with the normal preservation checks. No game launch is required for
   source, disposable database and browser evidence; client-specific claims
   remain unproven until separately authorized and tested.

Primary native code: ui/tmpl/resource_page.php (NPC Info and Save composition),
lib/Http/ManagementRouter.php (profile form merge), processor/ProductService.php
(profile validation), lib/Application/EffectiveSettingsResolver.php (precedence),
lib/Infrastructure/ProductRepository.php (effectiveSettingsForProfile), and
processor/RechatCoordinator.php (selected actor behavior). The reference is
pinned core/npc_master.php and core/tmpl/override_editor.php. This checkpoint
records an implementation-ready gap; it does not claim those missing controls
or backend semantics have been implemented.


## NPC override runtime checkpoint (editor still pending)

Implemented the prerequisite ownership change from the NPC Info audit. Nine
explicit NPC leaves now take precedence over Core/global values: Rechat,
Rechat rounds/probability/actions, recent conversation limit, short/mid/long
memory switches, and response word limit. Sources report npc; absent values
inherit. Dedicated NPC diary, special Player/Narrator routing and unrelated
installation-owned settings keep their existing paths. No client schema or
database migration was added. Previously persisted values for these nine
leaves become active; this intentionally replaces their earlier inert policy.

The existing revisioned NPC form accepts npc_settings_overrides_json. Only
catalogue leaves are accepted through that field. Empty object removes those
overrides; ordinary saves preserve them. Unrelated legacy override leaves
are retained but not newly activated. Player/Narrator form behaviour is
unchanged. Existing invalid-value validation and transactional profile saves
remain authoritative; this is not an unrestricted raw configuration import.

PromptAssembler previously read memory switches and response length straight
from Core Profile content. It now consumes resolved memory/response sections;
ProductRepository includes only those sections in prompt selection. The
original Core revision/provenance is not rewritten. Inline narrator word-limit
substitution uses the same resolved value. Legacy assembler callers without
resolved settings retain their Core fallback. RechatCoordinator already reads
the selected actor's resolved behavior; its participant selection and chain
lifetime policy are unchanged, and no in-game Rechat acceptance is claimed.

Existing unit tests now test the intended NPC precedence, false/zero, removal
back to Core, source projection, actual assembled word-limit instruction and
memory-tier inclusion/exclusion. 588 checks pass. Existing management HTTP
coverage adds create/save/reload, ordinary save preservation, removal, and
invalid type/range/foreign-section refusal without saving a concurrent biography
edit. Integration vertical slice and migration/durable-job tests pass. Schema
inventory remains 172 relations / 1599 columns; the known hash-only generated
summary drift was inspected and restored, with no schema changes retained.

This is backend foundation for the reference editor, not UI parity acceptance.
Next: mount current-override rows and Add/Edit/Remove in NPC Info using these
catalogue leaves, stage changes until NPC Save, compare empty/populated/edit/
rejected-save states, then expand the remaining reference catalogue only after
tracing its consumers. The game-data disclosures and complete NPC Info parity
remain outstanding. No live NPC settings, paid providers or game controls were
used in this checkpoint.


## NPC Info override editor checkpoint

The active NPC Info renderer now includes Current Overrides, reference-style
icon/label/value rows with Edit/Remove, full-width Add Override, searchable
setting picker and typed edit dialog. The nine leaves from the runtime
checkpoint are the available catalogue. Advanced JSON is local and typed:
Apply JSON to draft validates known paths/ranges, and only NPC Save persists.
Unsupported sections never get silently applied. Empty rows use the reference
empty message. Gold primary styling and red remove buttons survive shared
management CSS. Native forms use unique labels/IDs and a focus-contained dialog;
Escape closes the chooser without closing the NPC editor.

New derived presentation files are ui/tmpl/npc_setting_overrides.php and
ui/js/npc-setting-overrides.js, with scoped rules in herika-npcs.css. The
reference basis is the pinned core/tmpl/override_editor.php already compared
in the NPC Info audit. No remote JSON editor, inline script or CSP bypass was
added. The raw editor's explicit apply step is a remaining interaction difference
from the reference rich JSON editor, not proof of exact whole-editor parity.

Drafts participate in the existing dirty-form guard. Ordinary override saves
use the same revisioned profile endpoint and require its confirmed redirect;
a rejected/unconfirmed response leaves controls, draft values and visible error
text intact. Relationship batches retain their existing save owner and mirror
status into Info when an override draft is present. No second POST is made by
this component when that handler has already claimed the submission.

Evidence: Temp/npc-overrides-browser.cjs stages false and 17, filters to empty,
edits, removes, rejects unsupported JSON, stages zero, checks Escape and
intercepts a single 409 POST. Draft values remain editable afterwards. Actual
deployed desktop/narrow populated/error screenshots were inspected; initial
shared-CSS overrides and the hidden field label issue were corrected and rerun.
Temp/npc-overrides-save.cjs uses only a disposable database/server on 55678/58678:
actual UI Save persisted 23 words, reload rendered the saved row, and Remove plus
Save restored the empty inherited state. Direct SQL confirmed the original
biography unchanged and a new revision for each successful save. The isolated
fixture was stopped after proof. Live NPC data was not submitted or changed.

588 server checks, PHP/JavaScript syntax, diff checks and the existing management
HTTP suite passed. No new test harness was committed. No paid provider or game
interaction occurred. Remaining: all reference override controls after consumer
mapping, raw editor interaction parity, combined relationship/override browser
save coverage, and the missing observed-data Info panels. The full page matrix
and goal remain open.

## NPC observed-state checkpoint

Added ui/tmpl/npc_observed_state.php, deriving the disclosure order and compact
value presentation from Herika ui/core/npc_master.php at the pinned reference.
Skills, Current Equipment, Character Stats, Inventory, Spells and Metadata (JSON)
now appear in NPC Info before Setting Overrides. Live reference modal inspection
confirmed 18px Futura CondensedLight headings, weight 700, 8px panel padding and
#262626 background; the native disclosure headings were corrected accordingly.
Gold branding remains. The entire surrounding modal is not yet visually equal.

ProductRepository::npcObservedState queries the latest nonempty target state for
the installation and exact actor kind, record, content file and RefNum. It returns
an allowlisted projection and observation timestamp/playthrough, not raw context.
Private fields and player inventory are excluded. Numeric zero remains visible.
The display explicitly describes recorded state rather than claiming live values.
OpenMW Fatigue and recorded current/base values replace inappropriate Skyrim
labels. No schema migration or client changes were made.

Evidence: the existing integration suite now verifies installation/reference
boundaries, private-field exclusion and zero preservation in a rolled-back test.
Temp/npc-observation-proof.cjs passed against the disposable database on 55679 and
HTTP server on 58679. A newer observation for a different RefNum was excluded;
populated and empty profiles, escaped labels, six disclosures and readonly JSON
were exercised. Desktop 1280px and narrow 390px screenshots were inspected after
the typography correction. Temp/herika-info-measure.cjs opened the actual reference
NPC modal read-only and measured its Skills heading. No reference save, paid
provider request or game interaction was performed. 588 server checks and the
management HTTP suite passed.

Remaining work is explicit: the OpenMW actorState producer does not currently
capture NPC inventory; context.inventory is the player's and cannot substitute.
The Inventory disclosure is ready to render recorded items but current capture
is incomplete. Metadata editing needs a revisioned ownership design rather than
rewriting immutable observations. Full catalogue, combined override/relationship
save proof, whole modal layout and all other page-matrix gaps remain open.
## Existing NPC modal toolbar checkpoint

The reference openNpcModal path hides Manual/NPC Biographies for existing edits.
Lorkhan now omits that duplicate strip for existing NPCs, while preserving the
creation strip and secondary biography link. The duplicate toolbar View History
was removed; the History category remains. Diary precedes Profile Versions as in
Herika. Existing form routes and CSRF/revision inputs were not changed.

Live reference measurements from Temp/npc-header-reference.cjs: all eight toolbar
actions are 36px high with 13.12px Futura CondensedLight and 1.2 line height;
Save uses weight 700 and #2f714b, secondary actions weight 600 and #3b3b3b.
Scoped native rules now match those measurements, including overrides for shared
submit-button coloring. Narrow headers wrap below their title instead of squeezing
the title into a side column. Removing the strip also restores body space.

Temp/npc-header-native.cjs passed against the local candidate: absent duplicate
strip/action, eight measured button heights/backgrounds, retained History tab and
Close behavior. Desktop 1280px and narrow 390px screenshots were inspected. No
live NPC form was submitted. 588 server checks and management HTTP forms passed.
The broader modal still differs in outer inset, tags/knowledge controls, tab and
content geometry; this is progress, not whole-modal acceptance. Other matrix gaps
remain open. No game or paid provider was used.

## NPC editor content geometry checkpoint

Live read-only reference modal measurements (Temp/npc-content-reference.cjs)
confirmed a 30px outer inset; 18px Futura content; compact right-aligned Tags
with a 240x24px, 12px Arial input; transparent 42px model summary with 210px
label column; and 13.3333px Arial category buttons. The native editor now uses
that composition. The knowledge link moved from the metadata row to existing
secondary tools with installation/profile IDs preserved. Favorite remains a
labelled keyboard-operable checkbox and has an explicit focus indicator.

Category count remains six because Background Life was explicitly excluded.
The reference row's seventh wrapped label produces 50px height; native tabs
retain the same 40px minimum without inventing an excluded category. Narrow
screens use a 12px inset and wrap model labels and actions. Info labels/textareas
were corrected where shared management styles overrode the reference sizes and
background. Creation behavior and all revisioned save routes remain unchanged.

Temp/npc-content-native.cjs checked compact tag geometry, scoped knowledge URL,
header actions, History switching and Close on the deployed candidate without
submitting profiles. Desktop/narrow screenshots were visually inspected.
588 existing server checks passed. The complete NPC editor, metadata ownership,
remaining override catalogue and other page-matrix gaps are still open; no
whole-page acceptance follows from these geometry checks. No game was touched.

## Priority reader rendered-control recheck

Fresh live screenshots cover Events, AI Responses, Adventure Log, Diaries and
Books in both products (Temp/priority-pages-current.cjs). Live data differs:
Herika has populated Books but no diary/adventure entries in the selected month;
Lorkhan has diary/adventure records and no Books. These captures therefore do not
replace paired populated/empty fixture acceptance for the complete pages.

Visible-table measurements confirmed AI Responses cell typography and padding
already match; querying the first table without visibility incorrectly measures
Lorkhan's hidden Events tab. The real remaining differences were View Prompt
weight/line height and neutral body text color; these are corrected with scoped
response-table rules. Header gold remains the product accent.

Diary toolbar measurements found uneven native action widths, 40px download
links and missing letter spacing. All three download/delete actions now match
Herika's 200x36px geometry, 13.12px/1.2 Futura, weight 500, 7px 12px padding,
2px margin and .3px letter spacing. Calendar mode links use the same text metrics
and 36px minimum, allowing wrapping at narrow widths.

Temp/diary-controls-measure.cjs and diary-spacing-measure.cjs measured both live
products. Temp/diary-mode-current.cjs switched Tamrielic, Regular, Person and back
without writes; desktop/narrow final screenshots were inspected. Existing
Temp/lorkhan-response-live-proof.cjs opened prompts and closed with Escape at
1280/390px. Diary secondary-tool accessibility checks and 588 server checks passed.
No provider request, destructive action, live-data save or game interaction occurred.
Full counterpart acceptance remains open; this checkpoint corrects measured
controls rather than treating different live datasets as visual parity proof.

## Adventure Log control and state recheck

Extended the shared calendar control rules to Adventure Log, retaining its
200px minimum for downloads instead of forcing the longer full-log label to
200px. Temp/adventure-controls-measure.cjs measured exact equality with Herika
for all four controls: width/height, font, line height, padding and margins.
Current Date is 200x36; Entire Adventure Log is 219.234375x36; calendar modes
are 135.640625x36 and 142.046875x36 at the checked desktop viewport.

Temp/adventure-current-proof.cjs selected a populated day, confirmed rendered
rows, checked that both scoped/current-date and whole-log CSV responses contain
the displayed event, switched calendar modes and selected an empty historical
day/month. Desktop/narrow selected-day and empty-state screenshots were inspected.
The wide populated event table remains horizontally scrollable at narrow widths;
the empty table keeps all headings and its empty message in view. This did not
write game/server data or exercise the game. 588 server checks passed. Full-range
export completeness remains supported by prior tests, not claimed from the one
row checked here. The complete page matrix remains open.

## Books primary reading layout checkpoint

Herika's empty Books markup is a centered neutral message, without a card or
single-page toolbar. Native Books now removes the unnecessary minimum tab height
and empty card decoration. Its export link is retained inside the existing Filters
and playthrough disclosure. Pagination appears only when Books has multiple pages;
AI Responses and the Morrowind Journal keep their existing controls unchanged.

Temp/books-current-proof.cjs checked the live empty page, export discoverability,
and source-rendered one/two-page fixtures. A two-page fixture retains Next with
reader_page=2. The populated reader displays literal script markup as escaped text
and Escape closes it. Desktop/narrow screenshots were inspected. The synthetic
fixtures use the actual PHP template plus deployed styles/scripts via intercepted
HTML only; they do not prove persistent book capture or database pagination.
No live book/NPC/game data was changed. 588 existing server checks passed.
The complete page matrix and remaining feature/interaction gaps remain open.

## Events table metrics recheck

Compared live Events tables, not hidden sibling tab tables. Herika cells use
12.8px text, 1.5 line height and 9px 10px padding; native cells had 8px padding.
The scoped Events rules now match, retaining semantic thead headers. Record
buttons use 12.8px bold text with 4px 8px padding and red semantic text, replacing
the smaller 24px controls. Identifier lengths still determine button widths.

Temp/event-table-measure.cjs measured both rendered products. The actual native
AJAX page-two load and select-all/reset passed at desktop/narrow widths in
Temp/events-metrics-proof.cjs. A browser-intercepted empty GET response verified
the existing empty row, hidden headings and narrow visibility; it did not alter
server event/filter data. Its first probe used the wrong API attribute and timed
out; the corrected data-eventlog-api probe passed. Screenshots were inspected.
588 server checks passed; no deletion, preference write, provider or game action
was sent. This does not establish all event operations or whole-page acceptance.
The full matrix remains open.

## OmniVoice prepared-language selector and reference Save audit

Added the local prepared-profile selector using the reference language IDs and
labels. OmniVoiceLanguages reads /home/dwemer/omnivoice-tts/languages without
provider requests, projects only bounded labels/IDs, rejects malformed/placeholder
and oversized files, and does not follow file symlinks. The editor retains an
unavailable saved language and explains it rather than silently choosing another.
An absent catalogue retains the text field and the reference empty-profile notice.

The pinned reference ui/core/tts_connectors.php says Save prepares a language,
but its create/update path in lib/core/tts_connector.class.php only persists
connector metadata. No preparation invocation exists in those pinned paths.
The native selector therefore does not repeat that unsupported Save promise.
Actual service-side preparation is not established by either editor's help text;
remaining provider workflow claims must be based on a traced implementation.

The reference and native selectors each showed 18 local profiles in the browser.
Temp/omni-languages-proof.cjs verified native language draft preservation across
service switches. Paired desktop/narrow screenshots were inspected. Two focused
checks in tests/run.php cover missing catalogue and valid/sorted versus malformed,
placeholder, invalid-ID and oversized profile files. 590 checks and management
HTTP forms passed; no native connector was saved or speech requested.

Reference comparison incident: the reference create_blank GET immediately creates
a database connector. Opening it for the comparison unintentionally created empty
PocketTTS connector 49 (New TTS Connector 3). After verifying its identity, unchanged
default driver/endpoint and zero assignments, Temp/omni-reference-cleanup.cjs removed
only 49 and verified the other connector IDs were unchanged. Future reference
comparisons must open an existing editor, not the create_blank URL. No existing
reference connector settings or credentials were edited. The source worktree's
pre-existing PHPUnit cache change was preserved.

The earlier OmniVoice row's prepared-selector gap is addressed; the claimed
Save-time preparation gap is superseded by this audit, not by a fabricated control.
Language-library states, other provider controls and whole-site acceptance remain
in the full matrix. No game interaction occurred.

## TTS shell and exact toolbar geometry recheck

Fresh existing-Inworld editor comparison found both sidebars already share the
same 90px sticky offset. The earlier apparent top-inset discrepancy came from
scrolling the reference, so no sidebar change was made. Native toolbar labels
lacked the reference .3px letter spacing. Applying that scoped property closes
the remaining measured width differences without changing actions or endpoints.

Temp/tts-shell-final-proof.cjs opens only existing Inworld editors in both
products and asserts exact equality for all five actions' width, height, font and
letter spacing. Desktop/narrow screenshots were inspected; both bodies fit their
viewports. There were no create/save/delete/provider requests. 590 server checks
passed. This proves the compared shell/toolbar state, not all provider workflows
or the full page matrix. The full goal remains active.

### NPC combined Save verification (2026-09-07)

- Tested current `0a6ee03` UI in a disposable PostgreSQL/PHP runtime, not against live NPC data.
- Staged Maximum Response Words = 23 in Info and a new Professional relationship with affinity 36 in Relationships. Header Save issued exactly one POST.
- Intercepted the first POST with HTTP 409: relationship and override drafts remained available, with a conflict notice. Retrying issued one further POST and redirected with `npc_relationships_saved`.
- Reloaded the page and reopened both tabs: the override and relationship values persisted. Independent SQL confirmed the saved override, relationship revision 1, and unchanged biography (`Preserve this biography.`).
- Inspected the populated saved-state screenshot. Private local evidence: `Temp/npc-combined-save.cjs`, `npc-combined-rejected.png`, `npc-combined-saved.png`.
- Early fixture failures were not product defects: the seed lacked a playthrough revision and the target identity lacked `display_name`. Corrected only disposable data. Temporary trace instrumentation was removed byte-for-byte; no runtime code change was needed.
- This closes combined override/add-relationship submission and conflict draft-retention evidence only. It does not close the full NPC counterpart matrix, AI relationship result staging, broader override catalogue, or missing NPC inventory capture.

### AI Responses whole-page introduction comparison

The populated standalone comparison found the native description was shortened,
added an icon absent from the reference, and used denser spacing (13.2px/18.48px,
9px 12px padding, no top margin). Copied the reference introduction and its
13.5px/20.25px typography, 12px 15px padding, 15px vertical margins and accented
heading. Retained Lorkhan gold and its installation/playthrough filters. The
response page now retains 20px inner padding on narrow screens as Herika does;
Books and Journal retain their separate existing responsive rule.

Temp/response-intro-proof.cjs compares the deployed banner text and computed font,
padding and margins against Herika, checks desktop/narrow wrapping at 1280/390,
and opens/closes both Prompt Viewers. Herika requires its visible close control;
Lorkhan's Escape closure is asserted. Inspected both narrow screenshots and the
populated desktop comparison. This is populated introduction/layout evidence;
it is not new empty-state, embedded Control Panel or whole-site acceptance.

590 existing server checks passed. Local deployment verified 797 runtime files
with no hash mismatches, extra files or old paths. Private-file protection, health
and unauthenticated-session checks passed; configuration, credentials and voice
files were preserved. Rollback: /var/backups/lorkhanserver-code.r25xMe.
No game or provider requests were made.

### NPC AI relationship review: durable draft foundation

Pinned Herika `ext/relationship_system/relationship_editor.php` stages single-NPC
Build results in its table and asks the user to Save NPC. Its separate batch
builder writes directly; those are different workflows. Lorkhan's existing
single-NPC durable worker currently uses the immediate-write path.

Added an explicit preview mode to RelationshipBuildRepository. Preview requests
store their owner profile revision and normalized changed rows in a bounded
receipt, without updating relationship records or reporting committed changes.
The scoped draft reader returns only the requested installation/NPC/playthrough's
receipt. Mode is part of retry identity; completed drafts do not regenerate on
worker retry. Migration 095 adds the nullable draft column and refuses downgrade
while drafts exist. Existing callers still use immediate mode, preserving their
behavior until the editor review flow is ready.

Existing integration coverage now checks enqueue mode/revision, mode-conflict
rejection, unmodified saved scores/private notes, scoped result reads, draft_ready
status with zero committed changes, retry receipts and downgrade protection.
Normal immediate-build tests still pass. Schema inventory now has 1600 columns
across 172 relations.

Remaining work before this gap is closed: request preview mode from the NPC
editor, poll/display the completed draft without losing local edits, stage returned
rows in the table (including previously unlisted bound targets), and use the
shared revisioned Save path for review/commit. Handle stale revisions and discarded
or suppressed source data explicitly. The draft repository is not a new public
API and has no browser consumer yet. No visual parity or completed review workflow
is claimed by this backend checkpoint.

Foundation verification: 590 server checks, integration vertical slice, migration
and durable-job tests passed; schema --check matched the updated inventory.
Local deployment verified 799 runtime files with no mismatches/extras/old paths,
private routes remained protected, and configuration/credentials/voice-file hashes
were preserved. Rollback code: /var/backups/lorkhanserver-code.8zPCiG. No game
launch, game commands or live AI generation occurred.

### Relationship preview request and status boundary

Added an authenticated, CSRF-protected `forms/relationship-preview` operation for
explicit generation and scoped status polling. It uses URL-encoded form fields
and JSON responses, like the Player draft editor. Generation always requests
preview mode and requires an ordinary NPC/creature/manual actor profile owned by
the supplied installation. No caller can toggle it into immediate-apply mode.

Completed draft reads now reject a newer NPC revision, changed relationship
records, changed policy or lifecycle scope, and removed/suppressed/changed source
history. Status returns queued/building/ready/stale/failed without raw durable-job
payloads. A stale response contains no proposed relationship rows. Integration
coverage checks queued/ready transitions and rejection after independent NPC,
source-visibility and relationship edits. Existing HTTP tests exercise missing
connectors, invalid limits/operations, unknown jobs and rejected CSRF.

The editor button is still deliberately unchanged: the next step must merge
reviewed proposals into the local rows, preserve edits made during generation,
resolve new targets by exact identity, and submit through the shared revisioned
Save path. This checkpoint adds the safe browser-facing boundary; it does not
claim that the visible Build workflow has review/save parity yet.

Verification: 590 server checks, browser-like management HTTP forms, integration,
migration/durable-job tests and schema --check passed. Local deployment has 799
matching runtime files, no extras/old paths, protected private routes and healthy
API responses. Configuration, credentials and voice-file hashes were preserved.
Rollback: /var/backups/lorkhanserver-code.NuDHXo. No game or live provider requests.

### NPC Build now stages generated relationships for review

The single-NPC Build form now requests preview mode, polls scoped status and
merges completed scores/types into editable relationship rows. Header Save is the
only persistence action. Existing Custom Info and detail fields stay unchanged.
Generated new targets use a job/target-key reference; Save resolves their identity
from the scoped server receipt before advancing the NPC revision. Client-supplied
internal identities are rejected. Stale receipts fail before profile mutation.

The browser keeps edits made while generation is running instead of merging over
them. Failed saves preserve the complete draft. Repeated builds merge previously
generated targets by exact target key instead of appending duplicates. Empty
proposals leave existing rows untouched. A Review result control can resume a
background job or retrieve its receipt after reopening the editor. Results for a
saved relationship absent from the loaded table are rejected rather than silently
partially merged; that unloaded-target edge still needs a complete load/review path.

The Build dialog was compared directly with a read-only existing Herika NPC editor:
matched the 500px content width plus padding, 18px base typography, description/
Direction sizes and Arial button styling. Kept the native bounded history selector
and Lorkhan text/branding. No reference Build was submitted. Claude remained
unavailable from the earlier task attempt; Codex continued the UI fallback.

Temp/relationship-preview-browser.cjs uses a disposable PHP/PostgreSQL runtime and
intercepted provider-preview responses. It verifies existing/new staged targets,
escaped actor labels, preserved private notes, no profile POST before Save, one
rejected Save retaining both rows, repeated-build deduplication, late-result edit
preservation and empty proposals. Desktop/narrow screenshots were inspected.
These browser probes do not claim a real provider call or live-game validation.

Existing integration coverage invokes the actual revisioned NPC Save handler with
one existing and one new target plus a response override, checks durable values
and private-note preservation, and rejects an injected identity. The browser-like
management HTTP suite, 590 server checks, integration, migrations/durable jobs,
schema --check and JavaScript syntax check passed. Full page/site acceptance is
still open; this checkpoint does not mark the goal complete.

Deployment: 799 runtime files matched with no extras, old paths or hash mismatches;
private routes and API health checks passed. Configuration, credentials and voices
were preserved. The deployed NPC Build dialog was opened/cancelled read-only:
preview endpoint, 18px typography and 500px content width confirmed, with zero
POST requests. Rollback: /var/backups/lorkhanserver-code.ZI2gdn.

## Relationship review: saved targets outside the initial editor window

The initial relationship editor loads 100 rows. AI review now fetches the exact
saved candidates within the authenticated installation/NPC/playthrough scope,
checks their revisions, and stages unloaded existing targets as updates rather
than additions. Their private notes and detailed fields are preserved. These
editor-only fields are not copied into the AI proposal receipt or model input.

Existing integration coverage creates 100 newer relationships and confirms the
older candidate still resolves, the wrong NPC scope returns no rows, and private
notes remain absent from the proposal receipt. The disposable browser fixture
loads 100 rows, stages one older update and one new target, preserves notes/details
and revision, and retains all 102 rows after a rejected Save. Preview/provider and
Save responses were intercepted in that browser test; no live provider was called.
Desktop and narrow screenshots were inspected. The disposable fixture was stopped.

Verification: 590 server checks, JavaScript syntax, diff check, integration vertical
slice, migrations/durable jobs, schema inventory and management HTTP forms passed.
Deployment: 799 runtime files matched; no extra files, old paths or mismatches.
Private route and health checks passed. The deployed Build dialog was opened and
cancelled read-only with zero POST requests. Configuration, credentials and voices
were preserved. Rollback: /var/backups/lorkhanserver-code.Wqeumz.

This closes the unloaded-target edge, not full NPC or whole-site parity. The
remaining counterpart matrix stays active. No game was launched or controlled.
### AI Responses table surround and pagination comparison

A fresh live comparison found that cell typography and View Prompt buttons matched,
but the surrounding table had no outer margin, a translucent background, and
smaller pagination labels. Scoped response-only CSS now matches Herika's 20px
vertical table margin, #232323 neutral panel, 15px/22.5px pagination typography,
10px gaps and top/bottom pager margins. Books and Journal retain their own rules.
The native installation/playthrough filter and gold accent remain unchanged.

Temp/response-metrics.cjs read computed styles from the actual reference and
locally deployed pages. Table surround, top pager, label, cell and Prompt button
metrics matched. Temp/response-intro-proof.cjs passed at 1280/390 pixels; screenshots
were inspected and Prompt Viewer open/close still passed. These populated-page
checks do not establish empty-state or entire embedded-hub acceptance.

590 server checks and diff check passed. Deployed 799 matching files, no extras or
old paths; protected-route and health checks passed. Configuration, credentials
and voices were preserved. Rollback: /var/backups/lorkhanserver-code.RDy2Ud.
No provider calls, reference mutations or game control. Full matrix remains active.

### AI Responses empty-table acceptance

Read-only live probes use an out-of-range reference page and a native unmatched
search, without deleting records. Both return the actual rendered empty table.
The comparison exposed a 160px minimum and generic centered 25px cell padding in
Lorkhan. Response-only rules now use natural table height and left-aligned 9px/10px
cell padding. Both desktop empty panels measure 104.375px and use identical cell
font metrics. Lorkhan retains its explicit scoped-filter empty message.

Temp/response-empty-proof.cjs checks a single empty row and zero POST requests and
captures 1280/390 screenshots; those screenshots were inspected. Populated banner
and Prompt Viewer checks were rerun successfully. 590 server checks passed during
this CSS-only pass; diff and final deployment verification passed. Runtime files:
799 matching, no extras or old paths; configuration, credentials and voices kept.
Rollback: /var/backups/lorkhanserver-code.KhTu6N.

AI Responses is the Roleplay responselog tab. Control Panel responses embeds the
separate Response Queue page; its evidence must not be substituted for this log.
This empty-state checkpoint does not close the complete page matrix or assert
new embedded-tab interaction proof. No game or live provider interaction occurred.

### Priority-page group review and calendar shell correction

Fresh live captures covered all five priority pages in both products. Existing
live datasets differ (native Books empty, reference Books populated; calendar
entries differ), so these captures are comparison evidence, not full paired
populated/empty acceptance. Events and Responses remain tracked separately.

The calendar comparison then inspected the actual reference iframe inside
Roleplay, not merely the standalone child route. Native headings were 35px with
extra horizontal padding and an oversized inherited emoji span. They now match
33px/39.6px heading typography, 10px bottom margin and the available content width.
Calendar month labels use normal weight. Adventure month links match weight 500,
18px line height and 5px margin; Diary month links match weight 600 and their
existing 22.5px line height. Gold accents and compact narrow headings remain.

Temp/calendar-shell-metrics.cjs measured identical heading, month label/button,
navigation and calendar geometry in the real Roleplay surfaces at 1280px.
Temp/adventure-current-proof.cjs passed selected-day display, current/full CSV
content, both calendar modes and an empty date. Temp/diary-mode-current.cjs passed
Regular/Tamrielic/Person/back switching; desktop/narrow screenshots were inspected.
590 server checks and diff check passed. No live writes, provider calls or game use.
Deployment verified 799 matching files and protected routes/health; configuration,
credentials and voices preserved. Rollback: /var/backups/lorkhanserver-code.Wb4Ss4.
Full counterpart matrix remains active; these measurements do not close missing
configuration, editor and operational features elsewhere in the site.

### Populated diary reader and Books interaction recheck

The reference diary reader was opened read-only for an existing August entry.
The native current month had no diary records, so a fresh source-rendered isolated
fixture was used rather than creating live records. Paper/font/padding matched,
but native line spacing was 28.8px against Herika's 27px. The diary prose now uses
18px/27px with its existing 40px padding. Desktop/narrow captures were inspected.
The fixture retained literal markup as text; both browser paths issued zero POSTs.
No audio provider call or live diary edit was attempted. Native Export Text/Close
controls remain; this does not assert pixel-identical controls or whole-page parity.

Temp/diary-reader-fixture-proof.cjs reads current deployed CSS and source-rendered
HTML. Temp/books-current-proof.cjs was rerun after regenerating both book fixtures
from the current template: empty tools, single/multi-page layout, scoped Next link,
escaped content reader and Escape passed. Fixtures are not live database proof.
590 server checks, diff and deployment verification passed. 799 runtime files
matched, with no extras/old paths; private routes and health passed. Persistent
configuration, credentials and voices were preserved.
Rollback: /var/backups/lorkhanserver-code.0j73mj. Full parity goal remains active.

### LLM embedded draft and failed-Test acceptance

Read the actual template/runtime and compared an existing DeepSeek connector in
both Configuration hubs. The initially absent-looking description in a capture
was not a missing control: live DOM/computed geometry and a fresh screenshot show
the same description and 13.44px/18.816px typography in both. No speculative CSS
change was made. Read-only selection survives native LLM/TTS/LLM switching.

Temp/llm-unsaved-hub-proof.cjs extended this to unsaved Name and Provider edits,
then checked desktop and 390px field bounds and screenshots. A fresh independent
editor confirmed the saved Name was unchanged. A route-intercepted 409 Save during
Test produced the expected refusal message and exactly one intercepted POST:
no provider test followed. The draft remained intact and closing the result dialog
returned keyboard focus to Test. No live POST or provider call was made.

This is a verification-only checkpoint: no runtime files or configuration changed,
so no redeployment or backend test rerun was necessary. Existing deployed source
remains 2354f52. Full LLM advanced/provider interaction coverage and the remaining
page matrix are still open; this evidence closes only the stated acceptance cases.
### Global API badge selection in LLM connectors

Source audit found that TTS exposes the global CredentialStore status catalogue,
while LLM only offered LLM-specific and custom references. LLM now includes the
remaining global badges in its configured/missing groups. The new badge: reference
resolves only CredentialStore allowlisted names. Existing references, environment
precedence, secret files and saved connector selections remain unchanged. No keys
were merged, copied or silently reassigned; provider-card consolidation remains
separate open work, not claimed complete by this adapter change.

Extended the existing server checks for global badge resolution, configured-slot
selection across dialogue/profile-generation/Oghma factories, and rejection of
arbitrary environment names. 596 checks passed, plus integration, migrations/jobs,
schema inventory and management HTTP forms. The deployed browser selected the
OpenAI speech badge for an unsaved LLM draft, switched tabs, submitted only to an
intercepted failing Save, and verified the badge reference/draft remained. No
provider test followed; focus returned to Test and a fresh editor was unchanged.
Desktop/narrow screenshots of the selected badge were inspected.

Runtime verification: 799 matching files; no extras or old paths. Private route and
health checks passed; credentials, configuration and voice file hashes preserved.
Rollback: /var/backups/lorkhanserver-code.L2dDKD. No real provider request or game
interaction. Full page matrix and provider-badge consolidation remain active.

### Consistent global API badge labels

CredentialStore status metadata now provides shared display names derived from
provider definitions plus explicit labels for independent LLM/speech/runtime keys.
API Keys, LLM, TTS and STT use that catalogue. Removed duplicated TTS/STT label maps.
OpenAI speech key no longer appears as Tts Openai in LLM. Separate saved credentials
remain distinguishable; explicit custom labels and existing references are kept.
No credential values are exposed, copied, renamed or reassigned by this change.

The first management HTTP run caught a fallback-name regression for an unnamed
custom key. Restored the established readable fallback and reran the suite, which
passed. 597 server checks passed, including provider spelling, distinct keys and
existing custom-label secrecy checks. Temp/global-badge-label-proof.cjs confirms
matching OpenAI LLM/speech and Google Gemini STT names across all four deployed
pages and blank password inputs. Temp/llm-global-badge-proof.cjs passed draft/tab,
intercepted Save-failure and focus checks; desktop/narrow captures were inspected.
No live POST or provider request was made by those browser probes.

Deployment verified 799 matching files, no extras or old paths, protected routes
and health. Existing configuration, credentials and voice hashes were preserved.
Rollback: /var/backups/lorkhanserver-code.RfYfSN. Provider-card consolidation and
remaining page acceptance remain open; this does not complete the full goal.

### LLM service-icon badge selection

Pinned Herika syncApiBadge matches the service name against its configured-first
badge list when a service icon is chosen. Lorkhan previously forced the dedicated
LLM reference, even when that key was empty and a matching global key existed.
Explicit service clicks now match the visible provider badge labels in picker
order. Opening an existing connector does not change its stored selection.
Player2/Custom keep their existing defaults; no credential is copied or reassigned
until the user saves an edited connector.

Temp/llm-service-badge-proof.cjs used synthetic configured-status metadata in the
browser DOM, not real key edits. It verified global OpenAI selection, OpenRouter
matching, inherited selection on initial load, Player2/Custom defaults and absent
option handling. Desktop/narrow selected-badge screenshots were inspected. Model
catalogue requests were intercepted and no live Save/provider request was issued.
597 server checks, JavaScript syntax and diff checks passed. Deployment verified
799 matching runtime files, protected routes and health; configuration, credentials
and voice file hashes were unchanged. Rollback: /var/backups/lorkhanserver-code.ImzbiL.
Full provider-card consolidation and the page-by-page goal remain open.

### Remaining preset and speech-style dependency trace

Rechecked the pinned reference built-in presets and current native implementation.
CoreProfilePreset still captures Rechat, memory, diary, evolution and routing only.
Herika Default/Local LLM/Follower/Passive also require NPC boredom probability,
combat cooldown and quest/RPG policies. These remain incomplete, not valid partial
presets to add to the selector. Client AGENTS/CLAUDEX requires the full ordered
reading gate before client implementation; this checkpoint did not modify it.

A further source trace locates the automatic TTS mood gap precisely:
- protocol/schemas/v1/response-line.schema.json permits optional mood/emotion.
- processor/CanonicalResponseNormalizer.php currently constructs metadata with
  rechat depth, speech_enabled and source only; it does not propagate either mood.
- lib/Infrastructure/Repository.php inserts dialogue utterances without mood data;
  claimDialogueForSpeech decodes speaker/addressee/audience only.
- service/SpeechSynthesizeJobHandler.php obtains voice/language context from
  ProductRepository::speechContext. Streamed speech has a separate path in
  service/TurnProcessJobHandler.php which must stay equivalent.
- tts/OpenAiCompatibleSpeechProvider.php builds its payload from text/voice and
  explicit connector instructions. Azure's CloudSpeechConnectorProvider uses
  fixedMood only. Exposing Validmoods alone would not enable automatic styling.

Implementation must first define/validate mood extraction from the existing
compact Markdown response path, then preserve it through streamed and queued
utterances, cancellation/retry fences and provider context. Only then expose
reference mood controls and verify provider request payloads with mocks. Do not
reintroduce XML prompting, wait for the full response before first-sentence audio,
or infer automatic styling from existing explicit Instructions/Fixedmood fields.

This is a source-audit checkpoint, not implemented feature or in-game proof.
No runtime edits, deployment, credentials or reference data changes occurred.
The full page matrix remains active; these dependencies are not exceptions.

## AI Responses frame and action controls recheck

A fresh live comparison against HerikaServer's pinned `529364c4c12b3a8bd4cc12a481f400ce19b3a344`
`ui/ai-response.php` found differences that earlier cell/pager checks did not cover:
Lorkhan retained the generic hub's second border, used 10px instead of 12px outer gutters,
omitted the reference narrow table margins, and rendered the export action at 700/15px
instead of 500/13.12px. The response page now has a dedicated class, a single content
frame, reference outer padding/table border and narrow spacing, and matching action typography.
Changes are scoped to AI Responses; other roleplay pages and their data behavior are unchanged.

Evidence from the deployed runtime:
- `Temp/response-shell-proof.cjs`: real populated pages at 1280 and 390px; exact paired
  main/frame/table horizontal geometry, padding, margins, neutral borders/backgrounds,
  and Export button font, padding, margin, radius, height and letter spacing. Zero mutation requests.
- Inspected both pairs of `Temp/response-shell-{ref,native}-{1280,390}.png` screenshots.
  Different row content/counts, the explicit native scope disclosure, Lorkhan branding and
  excluded navigation entries remain visible; these are not identical-data screenshots.
- `Temp/response-empty-proof.cjs`: actual unmatched native filter and empty reference page,
  one empty row each, matching 104.375px desktop table height and 12.8/19.2px cell font.
- `Temp/response-intro-proof.cjs`: description parity and populated Prompt Viewer open/close
  at both widths; native Escape closes the reader. No provider requests or game control.
- 597 existing server checks passed. Deployment verified all 799 runtime files with no
  hash mismatches, extras or old paths; health, private-file denial and authentication checks passed.
- Local rollback: `/var/backups/lorkhanserver-code.ViCwmC`. Configuration, credentials and
  voice-file hashes were preserved. No database or client changes were required.

This closes the measured frame/action-control differences, not the whole-site goal.
Automatic speech mood transport and the remaining counterpart-matrix gaps stay open;
no inert TTS controls or dialogue response-format changes were introduced in this UI pass.

## Priority Roleplay frames and calendar structure

Continued the full-page comparison after the AI Responses frame fix. The generic Roleplay
hub still added an outer border/background to Events, Books, Adventure Log and Diaries.
Removed that redundant card at its source in `herika-roleplay.css`; AI Responses now shares
that rule rather than overriding it separately. The native calendar tab border is retained:
it corresponds to Herika's iframe border, not an extra content card. Main bottom padding,
Books' narrow inset and calendar content insets now match their counterparts.

The calendar additionally wrapped its table in an overflow container absent from Herika.
That wrapper prevented adjacent margins from collapsing and added a second 20px gap below
month navigation. Removed the wrapper and its unused CSS selector. Narrow calendar headings
now use the measured reference 24px size, and weekday cells retain the reference typography
and ellipsis instead of a separate tiny-font override. No date, event, export or reader
payload behavior changed.

Verification:
- `Temp/priority-shell-proof.cjs`: all four live counterpart pairs at 1280 and 390px,
  exact main/frame geometry, padding and neutral borders/backgrounds; both calendars also
  match heading width and heading-to-table vertical distance. All eight pairs passed with
  zero mutation requests. Paired screenshots were captured; desktop calendar pairs and
  all four narrow page pairs were visually inspected.
- Current native Books has no rows; populated/escaped content, single-page and paged reader
  checks used freshly source-rendered isolated fixtures (`books-current-proof.cjs`). The
  live empty Books filter/tools were also checked. This is not a populated live data claim.
- Events selection/deselection and AJAX page 2 passed, including narrow screenshots,
  without mutation requests (`events-frame-interaction.cjs`). No delete was executed.
- Adventure selected-day records, current/full CSV contents, calendar mode switching and
  an empty date passed; Diary calendar/person mode navigation and return passed.
- AI Responses' paired shell/button checks still pass after sharing the frame rule.
- Final 597 existing server checks and 799-file deployment verification passed. Persistent
  configuration, credentials and voice-file hashes were preserved. Latest rollback:
  `/var/backups/lorkhanserver-code.nJixGO`. No game/client activity or provider calls.

The full goal remains open. In particular, the narrow calendar month controls still use
Lorkhan's fitting layout while Herika's fixed minimum widths clip the labels at 390px;
that difference is visible in the screenshots and is not being declared a completed
parity item or an accepted product-specific exception. Different navigation entries,
current dates and recorded data also prevent whole-page pixel identity. Remaining editor,
provider and lifecycle gaps remain in the counterpart matrix above.

## Events automatic table layout and control typography

Source comparison with pinned Herika `529364c4c12b3a8bd4cc12a481f400ce19b3a344`
`ui/events-memories.php` and `lib/misc_ui_functions.php` showed that the Events table uses
Bootstrap's striped/bordered/small table classes with automatic columns and separate
borders. Lorkhan instead imposed fixed percentage columns, collapsed borders, smaller
checkboxes and forced word splitting. Both its initial PHP table and AJAX replacement
now use the reference classes. Removed the competing percentage/minimum-width rules;
retained the reference People Present width (20%, minimum 300px; 150px on narrow screens),
checkbox column, cell borders and natural word wrapping. Toolbar and pager weights and
letter spacing now match the reference. Identity, escaping, filters, scope, selection,
pagination and deletion code are unchanged.

Evidence:
- `Temp/events-identical-proof.cjs`: inserted the same synthetic text/checkbox table into
  both real page shells, without changing either database. All seven header/data column
  widths, padding/minimum widths, automatic layout and Auto Refresh/Delete/Next font,
  margin, padding, radius and letter spacing match exactly at 1280 and 390px. Final pass
  ran against deployed CSS without `SOURCE_CSS` preview. This isolates layout from the
  products' different data; it is not a same-history or complete colour-state claim.
- Inspected paired narrow synthetic screenshots, populated live AJAX page-2 screenshots
  at both widths and native empty-state screenshots. The source/header/stripe differences
  caused by substituting fixture markup do not establish full visual-state parity.
- `events-frame-interaction.cjs`: real selection/deselection and AJAX page 2 passed without
  mutation requests. `events-empty-render-proof.cjs`: intercepted only the page-fetch GET
  with an empty response fixture; both widths retain readable empty output and the same
  table classes. No delete was executed and no real event data was changed.
- JavaScript syntax and all 597 existing server checks passed. Deployment verification:
  799 runtime files, no mismatches/extras/old paths, health and private/auth checks passed.
  Configuration, credential and voice hashes preserved; final rollback
  `/var/backups/lorkhanserver-code.JyDmi8`. No provider requests or game activity.

Still open: full remaining page matrix and the narrow-calendar control choice raised
with the user. This pass establishes Events table/control geometry, not whole-site
completion or blanket acceptance of native-only differences.

## NPC General field hierarchy checkpoint

The General form now places Gender, Race, TES3 identity and Voice before dynamic
profile controls, following the pinned Herika form hierarchy. Main General/Roleplay
labels use the reference 18px size, and the lock label reads Lock This NPC. The existing
native evolution-field selector remains available in a keyboard-accessible disclosure;
its presence flag, checked values and form association are unchanged. This is not a
claim that the entire NPC modal matches: knowledge-tag placement, profile summaries,
secondary tools and remaining editor states still need counterpart review.

Evidence: Temp/npc-general-order-proof.cjs passed against the live deployment at 1280
and 390px: order, initial collapsed state, Enter expansion/collapse, unchanged FormData,
General/Roleplay switching and Close, with no POSTs. Inspected both deployed General
screenshots. All 597 existing server checks pass. Runtime verification reports 799
files with no mismatches, extras or old paths; health/private/auth checks pass.
Deployment rollback: /var/backups/lorkhanserver-code.aWQIZF. No provider requests,
NPC saves or game activity. The complete page-parity goal remains open.

## NPC Roleplay controls and empty-state parity

Pinned Herika NPC Roleplay has eight prose textareas. Live comparison found matching
heights but native global theme rules overrode their font, background, foreground and
border. Scoped Roleplay rules now use reference monospace 13.3333px/normal typography,
neutral colours and resize behavior, leaving gold labels and other editor panels alone.
All eight fields now carry the reference placeholder wording, including native biography
and speech_style mappings for npc_static_bio and speechstyle. No persistence changes.

Temp/npc-roleplay-proof.cjs compares every textarea's placeholder and 12 computed style
properties at 1280/390px against the live Herika counterpart. All pass. It also captures
shared populated and empty DOM-only fixtures and checks native unsaved edits survive
General/Roleplay switching with zero POSTs. Inspected populated narrow and empty desktop
pairs; the native empty placeholder was missing on the first comparison and was fixed
and rechecked. These are control-level fixtures, not matching database content. Native
modal width still differs (and therefore wraps text differently); full modal acceptance
remains open. Existing 597 checks pass; 799 deployed files match, private/auth/health
checks pass; configuration, credential and voice hashes preserved. Final rollback:
/var/backups/lorkhanserver-code.9Efj1h. No provider requests, saves or game activity.

## NPC modal viewport structure parity

Replaced the content-sized editor body with an explicit scrolling viewport below the
header, matching Herika's frame composition without moving typed forms into an iframe.
The viewport stays at 70vh across tabs. Modal padding, body/header backgrounds, action
gaps, link/button margins and letter spacing now follow the reference; removed the
narrow padding/header-wrap override. The reference's narrow dialog extends beyond the
viewport vertically; native now follows that layout and retains overlay scrolling.
This is reference behavior, not a claimed product-specific exception.

Temp/npc-shell-proof.cjs verifies exact modal/header/body/viewport rectangles against
live Herika at 1280 and 390px, then stable dimensions through all six native tabs,
scrolling to the end and Close. Zero POSTs. Inspected narrow paired screenshots and
native desktop. General form/disclosure and Roleplay fixture checks passed after the
structural change. Existing 597 checks pass; final deployment has 799 matching files,
no extras/old paths and passing private/auth/health checks. Configuration, credential
and voice hashes preserved; rollback /var/backups/lorkhanserver-code.ZmJudj.

The populated contents are different records, and profile-summary composition,
knowledge-tag placement and native secondary tools remain under review. This proves
shell structure/geometry and navigation, not completion of every NPC-editor state or
the whole counterpart matrix. No provider calls, data saves or game activity.

## NPC General Oghma Tags: presentation and save mapping

Added the missing Oghma Tags field beside Voice in General, using pinned Herika's label,
placeholder and lookup-restriction hint. This is the existing NPC content setting, not
the installation-global Oghma field. General previously omitted it, and profileContent
also ignored submitted edits. The mapper now validates text/4096-byte UTF-8 bounds,
uses the existing tag normalizer and removes the old alias only on explicit edits.
Empty submission removes the NPC tags so existing global inheritance applies. Forms
that omit the field preserve tags. Other profile content and global settings are unchanged.

Evidence: 603 server checks, including six targeted assertions in the existing suite
for normalization, clearing, unrelated-content preservation and malformed inputs.
The full management HTTP integration suite passed in its disposable PostgreSQL runtime,
including a real revision POST and export readback of normalized tags. Its stale lock
label expectation was updated to the previously implemented Lock This NPC wording.
Temp/npc-tags-proof.cjs verifies one General field, matching placeholder, empty/populated
values retained across tabs and associated FormData at 1280/390px with zero live POSTs.
Inspected deployed desktop populated and narrow empty screenshots. Source comparison
confirms field placement/text; this is not whole-General pixel parity.

Deployment: 799 files match, no extras or old paths, health/private/auth checks pass;
configuration/credential/voice hashes preserved. Rollback:
/var/backups/lorkhanserver-code.zIn1vj. No live NPC saves, provider calls or game activity.
Remaining full counterpart and NPC-editor gaps remain open.

## NPC General header toggles and formatter-summary distinction

Matched General's primary checkbox geometry to pinned Herika .label-with-toggle:
13px boxes scaled 1.8, reference margins and 10px label gap. This corrects native
unscaled lock/dynamic switches and oversized diary controls. Detailed evolution-field
checkboxes are excluded. No values or handlers changed.

Temp/npc-toggle-proof.cjs compares lock width/height/minimum height/margins/transform/
origin/accent against Herika at 1280/390px, then keyboard Space toggle, tab retention and
restoration; zero POSTs. General disclosure/FormData/Close regression passes at both
widths. Inspected deployed desktop screenshot. 603 existing checks pass; 799 deployed
files match, private/auth/health checks pass and secret/configuration/voice hashes
remain unchanged. Rollback /var/backups/lorkhanserver-code.qEHA4H. No game activity.

The sixth reference Profile LLMs entry is llm_formatter_id (npc_master.php lines
1998/2017), not memory summarization. Do not infer a missing dialogue-model selector
from that count. The existing Markdown-route formatter exception recorded above still
applies; no inert sixth slot added. Profile-summary composition and other remaining
counterpart states are not marked complete by this check.

## Priority-page recheck: calendar month emphasis

Fresh paired Events/Adventure/Diaries/Books shell checks passed at 1280/390px, but visual
review caught a missing child element in both calendars: Herika renders the month inside
span > b, while native rendered plain span text. Earlier container-font measurements
missed that difference. Restored the reference bold markup in the shared calendar renderer;
Morrowind date calculation, escaping and links are unchanged.

Temp/calendar-title-proof.cjs now compares the actual b element's font/weight/line height
for Adventure and Diaries, in Regular and Tamrielic modes, and verifies native Next changes
the month. All four pairs pass without mutation requests. Inspected Regular Adventure and
Tamrielic Diaries navigation pairs; differing game dates remain actual product data.
The first screenshot attempt raced a reference iframe navigation; the final test waits
for that frame's navigation before comparison. Existing 603 checks pass; 799 deployed
files match, private/auth/health checks pass and configuration/credential/voice hashes
are preserved. Rollback /var/backups/lorkhanserver-code.EQf2op. No game activity.

This corrects a concrete false-negative in prior component checks. Full populated/empty
page acceptance and the remaining matrix are still open, including narrow calendar layout.

## Adventure reader table structure and row backgrounds

Fresh populated comparison found collapsed native borders and forced speaker-band
backgrounds diverging from Herika's deployed table. Moved the header row into the same
tbody composition as the reference (retaining th scope attributes), switched to separate
borders with zero spacing, and removed the overriding speaker background rules so the
shared alternating-row theme applies. Location dividers and speaker metadata remain.
Adventure month navigation also now carries the reference 0.3px letter spacing.

Temp/adventure-table-proof.cjs places identical synthetic text/rows in both real page
shells and verifies all column widths, borders, padding and row backgrounds at 1280px.
Inspected the paired table screenshots. Mouse is outside the table to avoid mistaking
hover colour for a stripe. Live adventure-current-proof.cjs passes selected-day entries,
current/full CSV containing the displayed event, calendar mode switches and empty date;
it captures current desktop/narrow readers without writes. The initial audit used an
incorrect nested-table selector; corrected to the actual table ID before comparison.
603 existing checks pass. Deployment has 799 matching files, no extras/old paths and
passing private/auth/health checks; config/credential/voice hashes preserved. Rollback:
/var/backups/lorkhanserver-code.h6BgiN. No live data mutations, provider calls or game use.

Still open: native filter/pagination placement, reference frame versus native page
scrolling, narrow table layout and the complete remaining page matrix. Table geometry
and row-state parity are not whole-page acceptance.

## Calendar reader scrolling composition

Adventure and Diaries now render inside a labelled, keyboard-focusable scroll region,
matching the reference embedded calendar viewport instead of growing the outer page.
Viewport client height follows the reference 78vh desktop / 75vh narrow frame minus
its two border pixels; the native tab retains that border. Inline baseline composition
and the existing content padding are retained. Date-anchor routing keeps the Adventure
entry target inside this region and restores the hub position, matching iframe anchors;
manual outer scrolling is not disabled. Other reader pages are unchanged.

Temp/calendar-scroll-proof.cjs passes both tabs at 1280/390px: reference/native client
heights, keyboard scrolling, date-anchor positioning and hub navigation remaining
visible. Initial narrow checks caught a real anchor scrolling the outer page; fixed
and reran. Keyboard focus may make a small outer adjustment, so the final assertion
checks the actual visible navigation rather than assuming window.scrollY is always zero.
Inspected deployed selected-date desktop/narrow screenshots. Current/full Adventure
exports, populated/empty dates and Diary calendar/person-mode returns pass with zero
POSTs. The priority shell/heading-width checks also passed after adding the viewport.
603 existing checks and JS syntax pass; final runtime has 799 matching files and passing
private/auth/health checks. Configuration, credentials and voice hashes preserved.
Rollback /var/backups/lorkhanserver-code.oxRTFR. No provider calls or game activity.

Still open: filter/pager placement and full diary entry interaction/remaining counterpart
matrix. This checkpoint establishes scrolling composition, not whole-site completion.

## Calendar-to-entry flow and secondary controls

Moved native filters/playthrough selection, refresh/count toolbar and Diary audio-help
disclosure below the entry reader with pagination. Calendars now lead directly into the
reference entry-table composition without those extra blocks in between. Preserved
form fields, GET routes, control selectors, audio dock/status elements and all handlers.
Removed the calendar bottom margin that could not collapse through the native scroll
wrapper; both Adventure and Diaries now have the measured reference 20px table gap.

Temp/calendar-controls-proof.cjs passes both pages at 1280/390px: controls follow the
table in DOM order, explicit-date search gives empty results, Reset clears search and
no POSTs occur. The initial test omitted the date and correctly received Select a date;
the final test sets a date before asserting empty filtered events. Inspected deployed
empty-control screenshots. calendar-table-gap.cjs reports 20px for all four reference/
native desktop cases. Current/full Adventure CSV, dates, calendar modes and viewport
navigation regression passed after moving the controls. Audio generation/save/delete
were not exercised. 603 existing checks pass; 799 deployed files match with passing
private/auth/health checks, preserved config/credential/voice hashes. Rollback:
/var/backups/lorkhanserver-code.Yo0Dmq. No game activity or live data mutation.

The controls remain a secondary native extension; this does not establish full Diary
reader/editor acceptance or close the remaining page matrix.

## Diary editor action-footer composition

Compared pinned Herika diarylog.php editModal to a freshly source-rendered native diary
fixture inserted into the actual deployed hub (retaining its real stylesheets). The
textarea font/height already matched, but padding and footer composition did not. Added
the reference button-group wrapper, 15px vertical group margins, zero footer padding,
500-weight/1.2-line-height/0.3px-spaced actions, and 7px 10px textarea padding. Native
secondary title metadata and typed form handlers remain intact; delete confirmation
footer is unaffected.

Temp/diary-editor-footer.cjs reports matching footer padding/gap and both action fonts,
margins and letter spacing; textarea typography, height and padding also match, with
product accent colours preserved. Source-rendered fixture uses a disabled endpoint,
not real diary records. diary-editor-interaction.cjs passes 1280/390 empty required-field
validation, browser-intercepted 422 retaining unsaved text/open editor, and Cancel.
Inspected paired populated editor and native narrow failure screenshots. No real saves,
deletes or speech/provider requests. Editor heading has matching computed geometry and
visibility, but whole-modal visual acceptance remains open (including title/metadata
composition); do not infer completion from those metrics alone.

603 existing checks pass. Deployment has 799 matching files, private/auth/health checks
pass, and configuration/credential/voice hashes are preserved. Rollback:
/var/backups/lorkhanserver-code.i8722o. No game activity. Remaining matrix stays open.

## Diary editor focus cue and unresolved heading paint

Removed the extra 2px solid/offset textarea focus outline: the shared gold border and
2px translucent shadow already match the reference focus treatment (orange there).
Keyboard focus remains visibly indicated; other controls retain their outlines.
603 server checks pass; empty required-field validation, intercepted 422 retaining
unsaved content, and Cancel pass at 1280/390 with no real saves. Inspected the narrow
failure screenshot. Deployment has 799 matching files and passing private/auth/health
checks; config, credentials and voices preserved. Rollback: /var/backups/lorkhanserver-code.djQRgC.

Heading painting is NOT resolved. Synthetic diary content inside the actual page
sometimes leaves Edit Entry unpainted despite correct computed visibility/geometry.
Positioned heading and transform-free centering previews initially appeared to fix
it, but fresh deployed screenshots did not reproduce that reliably. Those speculative
changes were discarded. Both dynamic subtree replacement and a browser-served full
page fixture were examined; neither proves the cause. Next investigation must isolate
an initial server-rendered populated page from fixture/Chromium painting effects.
No whole-modal acceptance, live data edits, provider requests or game activity claimed.

## Diary table row-action layout

Removed the native 190px minimum-width flex row from Diary actions. Actions now use
normal inline wrapping as in pinned Herika diarylog.php, with matching 2px margins,
36px minimum height, 0.3px letter spacing, and reference line heights/weights (including
500-weight Delete). Existing Play/Edit/Delete handlers and disabled speech behavior
are unchanged. Product gold Play styling is retained.

Temp/diary-actions.cjs compares actual reference buttons with a source-rendered diary
fixture in the deployed native shell: all three widths/heights and eight measured
style properties match. Content differs, so table column widths are not acceptance
proof. Temp/diary-actions-wrap.cjs constrains both action cells to the same 110px block
only within the browser; all three wrap with matching 40px vertical pitch. Inspected
paired crops. This controlled component proof does not establish full table parity.
Editor required-field, intercepted failed save retaining text, and Cancel regressions
pass at 1280/390, without real saves or provider requests. 603 server checks pass.
799 runtime files match, private/auth/health checks pass, configuration/credential/voice
contents preserved. Rollback: /var/backups/lorkhanserver-code.BUOh7w. No game activity.
Whole Diary reader/editor/table acceptance and the heading paint investigation remain open.

## Books table structure and narrow content flow

Live reference inventory confirms Books uses table/table-striped/table-bordered/table-sm,
an in-body primary header, separate borders with zero spacing, and a UESP calendar
help link. Ported that structure specifically for Books; AI Responses and Journal keep
their existing header structures. Removed forced percentage column widths for Books,
restored normal wrapping and reference 12.8px/1.5 typography with 9px 10px cell padding.
Matched visible cell stripes (#212529/#202020), not only the underlying row colors.
Native gold calendar link, safe text reader and scoped filters/export remain available.

Temp/books-structure-proof.cjs passes at 1280/390: source-rendered native fixture in
actual live stylesheets, reader opens with literal markup retained, Escape closes,
calendar help URL matches. Both tables receive the same synthetic two-row text for
comparison; separate-border/zero-spacing and underlying row colors match. Paired narrow
screenshots were inspected and corrected when they exposed non-wrapping timestamps and
incorrect visible cell colors. The final table is readable at 390 without the former
forced columns; exact whole-table geometry and the native reader extension remain open.
These component checks are not full page acceptance. No POSTs or live record edits.
603 checks pass; 799 deployed files match and private/auth/health checks pass.
Config/credentials/voice contents preserved. Rollback: /var/backups/lorkhanserver-code.0NWcFK.
No game activity; full counterpart matrix remains active.

## Books identical-content geometry and live empty flow

Removed only the Books cell top/bottom border widths after measuring reference
0px/1px/0px/1px borders against native 1px on all sides. The extra borders added 2px
to every row. Temp/books-geometry.cjs uses the same header and two synthetic rows in
both live stylesheet environments at 1280/390. Comparison of all 15 cell widths,
heights, fonts, padding, border serialization, box sizing and whitespace now passes,
as do full table width and height. Final narrow table screenshot inspected against
reference; calendar-header gold is intentional branding. This closes the measured
identical-content table geometry discrepancy, not the entire Books page.

Temp/books-empty-flow.cjs verifies the actual deployed empty Books state, GET filter
submission producing No books match this filter, and Reset restoring No books found
at both widths. Narrow filtered screenshot inspected. Zero POSTs. Populated content
remains a source-rendered browser fixture, not a claim about live captured books.
603 checks pass; 799 matching deployed files, private/auth/health checks pass, config,
credential and voice contents preserved. Rollback: /var/backups/lorkhanserver-code.4Cpqxw.
Whole-page placement, reader extension and full counterpart matrix remain open.

## Books primary content flow

Moved the existing Books filters/playthrough/export disclosure after the table in DOM
order, so the intro leads directly to records as in Herika. Captured only the Books
filter markup for its later output; AI Responses and Journal retain their placements.
Matched Books intro 14.4px/1.45 typography and 10px bottom margin. Native scope controls
remain accessible below records, not removed or classified as full parity exceptions.

Temp/books-intro-gap.cjs reports exactly 11px from intro bottom to table top for both
products at 1280/390. Populated fixture table/reader/Escape checks and live empty,
filter, Reset flow pass with zero POSTs; inspected the final narrow filtered page.
603 checks pass and 799 deployed files match; private/auth/health checks pass and
configuration/credential/voice contents are preserved. Rollback:
/var/backups/lorkhanserver-code.MPodbO. No game activity. Whole-navigation differences
and the full page/reader counterpart matrix remain open.

## Diary table column structure and prose sizing

Ported Herika diarylog.php's in-body header and column group: Author 20%, Tamrielic
Time 15%, UTC 10%, with Content/Actions automatic. Added safe UESP calendar header
link and separate zero-spacing borders; row stripes now follow the reference header
index. Diary prose remains a keyboard-operable button, but no longer inherits generic
button font sizing, vertical margins or minimum height. Actions/handlers are unchanged.

Temp/diary-table-proof.cjs uses the freshly rendered native diary fixture and copies
its text into the actual reference table, adapting reference action classes only in
the browser. Temp/diary-table-equal-width.cjs constrains both tables to 1100px for a
controlled comparison: header 39.1875px and populated row 135.125px match, all fonts,
padding, row backgrounds, border collapse/spacing and the three explicit column widths
match. Automatic Content/Actions widths differ by about 0.3px; not claimed exact.
Paired populated crops inspected, with native Play correctly disabled by the fixture's
missing connector. Real speech was not invoked. Editor validation/intercepted failure/
Cancel checks pass at 1280/390. 603 checks pass; 799 matching deployed files and passing
private/auth/health checks; configuration/credentials/voices preserved. Rollback:
/var/backups/lorkhanserver-code.hfMPAF. No live data edits or game activity.
Full Diary page/reader/editor acceptance remains open, including heading paint and
narrow table/calendar behavior; this evidence covers the table component only.

## Diary initial selection and empty-state parity

Diary listing now waits for a date or author before showing entries, matching the
reference calendar flow. Only unselected, non-export, non-search listing queries get
AND FALSE; calendar/author queries run first and retain navigation counts. Explicit
search remains supported across dates and CSV export scope is unchanged. Empty text
now distinguishes initial selection, selected date, selected author and filtered results.

Extended the existing integration calendar fixture with three focused checks: no
selection returns zero rows/count without losing calendar/authors; author selection
works without a date; explicit search works without a date. Full disposable PostgreSQL
integration, schema inventory, backup/restore and migration/durable-job checks passed
(terminal exit 0). 603 server checks pass. Live diary-empty-flow.cjs passes initial,
filtered empty and Reset states at 1280/390, with zero POSTs; scrolled narrow empty
state screenshot inspected. No real diary edits or exports were performed.
799 deployed files match, private/auth/health checks pass, configuration/credentials/
voices preserved. Rollback: /var/backups/lorkhanserver-code.XbPCOw. No game activity.
Full page and reader/editor acceptance remains open.

## Diary day selection scroll target

Diary day links now include #event-table, matching Herika diarylog.php; the native
diary table owns that unique id and reference 200px scroll margin. Extended the
existing calendar viewport anchor handler to an explicit two-id allowlist (Adventure
and Diary) so fragment positioning stays inside the reader and leaves hub tabs visible.
No changes to data selection, author navigation, exports or speech handlers.

Temp/diary-day-anchor.cjs passes Regular/Tamrielic day selection and fragment reload
at 1280/390: URL anchor and unique table target checked, selected-date empty text,
nonzero inner scroll, entire empty table visible within reader, hub tabs visible,
zero POSTs. Inspected the narrow Tamrielic result. calendar-scroll-proof.cjs also
passes reference viewport height and keyboard/Adventure anchor regressions at both
widths. 603 checks pass; 799 deployed files match and private/auth/health checks pass.
Configuration/credential/voice contents preserved. Rollback:
/var/backups/lorkhanserver-code.2xccO4. No game or live data changes. Full matrix remains open.

## Books reader shared content-viewer presentation

Books' optional reader now uses the same shared modal shell styles as the response
viewer (derived from Herika's contentModal), retaining the book title rather than
mislabeling book text Prompt Viewer. Shared base selectors include a distinct
book-content-viewer class; prompt-message styles remain response-only. Book body is
13px/1.8 Consolas/Monaco/Courier, with the 20px padded reading surface and 90vh cap.
Its header wraps for long titles/copy status. Native escaped text and accessible dialog
remain; Herika Books itself has no reader button, so this is shared-viewer alignment,
not a claim that the reference Books interaction exists.

Shared log dialog open/close now preserves and restores body overflow and clears stale
copy status on reopening. Temp/books-reader-proof.cjs passes 1280/390 mocked clipboard
success/failure, status reset, focus return, close/Escape scroll restoration, long-text
scrolling, monospace font/padding/max-height assertions; zero real clipboard/provider/
data writes. The test awaits the native asynchronous close event and inserts long text
inside the existing preformatted content node. Reference contentModal was displayed
with synthetic text only for read-only style inspection; paired viewer screenshots
inspected. 603 checks and Books table/reader regressions passed. 799 deployed files
match, private/auth/health checks pass; config/credentials/voices preserved. Rollback:
/var/backups/lorkhanserver-code.G4JhgU. No game activity. Full page matrix remains open.

## Shared reader backdrop close parity

Added reference-style backdrop dismissal to the existing log dialogs. Clicks must
land on the dialog element outside its bounding rectangle; padding/content clicks
remain open. Existing close handling restores body scrolling for backdrop, explicit
close and Escape. Removed the trailing blank line flagged in the previous CSS diff.

Temp/log-viewer-close-proof.cjs uses fresh source-rendered Books and AI Responses
fixtures inside their actual deployed page styles at 1280/390. Padding click, mocked
Copy, backdrop close, focus/overflow restoration, reopening status reset and Escape
pass with zero POSTs. The initial probe used a nonexistent native ai-response.php;
corrected to events-memories.php?tab=responselog, then all four cases passed. Inspected
the narrow AI prompt viewer screenshot. books-reader-proof.cjs copy failure/long text
regression also passes. No system clipboard, real prompt or provider writes.
603 server checks pass. 799 matching deployed files and private/auth/health checks
pass; config/credentials/voice contents preserved. Rollback:
/var/backups/lorkhanserver-code.PqqJbW. No game activity. Full parity matrix remains open.

## Global Settings Context panel re-audit

Revalidated the broader matrix against native ui/global_settings.php, its tab handler,
and pinned Herika ui/global_settings.php plus lib/core/prisma_settings_catalog.php at
529364c4c12b3a8bd4cc12a481f400ce19b3a344. Raw heading DOM order is NOT a reliable visual
ordering comparison: both products group sections under the same four settings tabs.
No tab reordering or heading-only change was made from that misleading first inventory.

Confirmed gap: reference Context and Context Selections are separate sections in the
Context & Knowledge tab. Native has Oghma and Context Selections only. The missing
Context section contains these reference controls:

| Reference control | Native parity work still required |
| --- | --- |
| DETECT_MAGIC_EVENT | Trace OpenMW spell/effect event production and gate only matching event capture/context. |
| GROUND_ITEMS_DESCRIPTIONS_ONLY | Delivered in `66e948a`: resolved description availability filtering, independent of rendered description text. |
| INVENTORY_ITEMS_DESCRIPTIONS_ONLY | Delivered in `7a4cca5`, after native player-inventory wiring in `b88f6f7`; target inventory capture remains a client gap. |
| HIDE_AMBIENT_COMBAT | Missing client death-event producer confirmed against client remote main `6daab0c`; add typed death attribution/capture before the server history filter. See ambient combat dependency checkpoint. |
| DISABLE_REANIMATION_TRACKING | Establish whether supported OpenMW events carry a reanimation identity; no inert checkbox. |
| TRANSFORMATION_DETECTION | Trace supported actor transformation signals and their context consumer. |
| POWER_AWARENESS_ENABLED | Establish TES3 power/spell identity and observation semantics before exposing a toggle. |
| CHIM_ITEM_PICKUP_EVENTLOG_MIN_VALUE | Map to native item value and pickup projection; preserve immutable source events. |
| PROMPT_TIMESTAMP | Implemented below: saved boolean and relative history dividers; default off. |

A case-insensitive search for these exact reference keys in native lib/ui/tests returns
no matches. This proves no direct key implementation, not absence of every analogous
behavior. Runtime equivalence must be traced independently before implementation.
Implementation sequence: map producer/projection/prompt consumer and existing native
settings for each; add only supported, persisted controls in a distinct Context section;
verify defaults/off states and unchanged unrelated context in existing tests; compare
the full populated/empty tab and saved state. Any actual unsupported OpenMW feature
requires an evidenced product exception, not a placeholder or inferred exclusion.

The existing matrix's Global Settings row is therefore partial: grouped selections and
blacklist UI do not prove parity for this missing panel. Other open families remain
Quickstart provisioning, Core Profile presets/fields, full NPC editor composition,
Narrator semantics, provider-specific connector controls, playthrough snapshots and
SQL database-management operations. No product files, local settings, game state or
reference repository were changed by this audit; deployed server remains 5dda856.

### Context panel: Prompt Timestamp (2026-09-08)

Added the distinct Context section before Context Selections, using the reference
provider-card structure. Its first wired control is Prompt Timestamp. The other eight
Context controls above remain open; one matching card does not establish panel parity.

Reference semantics come from pinned HerikaServer `lib/data_functions.php` and
`conf/conf.sample.php`: default false, nine relative time categories, and dividers only
when the category changes after the first historical entry. Native implementation uses
OpenMW elapsed seconds (`adapters/openmw.lua` world.game_time -> eventlog.gamets ->
ProductRepository history content.game_time), not Herika's Skyrim timestamp multiplier.
The server's existing EventLogRepository::formatGameTime also treats gamets as seconds.
Recorded timestamps remain intact; missing timestamps do not produce invented headings.
Dividers stay attached to their message inside the existing byte budget and do not
replace speaker attribution or affect the original text used for memory coverage.

The typed global document, HTML save route, effective settings and prompt assembler
consume the setting. Older global documents and named presets normalize it to false.
Global Settings help elements now have the IDs referenced by their input controls.

Evidence: 608 existing server checks pass, including optional headings, OpenMW time
conversion, speaker preservation, missing time, strict types and legacy settings/presets.
The full disposable management HTTP suite passes, including enabled/disabled save and
fresh GET verification. Its Diary assertions now explicitly select the created entry
and check the previously implemented empty-state behavior rather than assuming all
entries appear before a date/filter is chosen.

Read-only browser comparison (`Temp/context-timestamp-proof.cjs`) at 1280 and 390 pixels:
reference/native card widths 1186/321 respectively, padding 12px 14px, radius 10px,
title font 15px. Captured both products and visually inspected the narrow screenshots.
Native keyboard Space toggles and restores the checkbox; no POST or provider calls.
Help wording explains the native clock source. Other panel controls and complete page
composition are not accepted by this limited comparison. No game was launched or controlled.

### NPC Relationships navigation and control sizing (2026-09-08)

Fixed three paths in the Relationships partial: playthrough Show form, Relationship
LLM Logs link and build-status Reload link. The partial runs inside the profile-card
renderer, where `$uiRoot` was undefined, producing `/ui/...` instead of the configured
server prefix. It now derives a local root from the passed management path. No actor,
playthrough, relationship revision or provider routing behavior changed.

The full disposable management HTTP suite passes with new checks for rooted form/link
paths and successful GET navigation. The 608 server checks also pass. Live read-only
browser checks at 1280/390 follow Show, retain the NPC editor route, and confirm the
logs destination returns 200, without POSTs. Source review covers the conditional
build-status link; no build was requested on the live installation.

Compared actual Herika and Lorkhan Relationships tabs at both widths. Generic native
form rules were overriding the copied relationship styles. Scoped corrections restore
18px section/empty-state text, 16.2px bold lock label, 13px checkbox, 13.3333px add fields
and 15.3px quick-action buttons. Measured reference/native affinity input and Add button
heights now match at 29px, relationship-type select at 31px and quick actions at 35px.
Semantic button colors match the reference; the product accent remains gold.

Evidence scripts: `Temp/npc-relationship-navigation.cjs`,
`Temp/npc-relationships-reference.cjs`, `Temp/npc-relationship-metrics.cjs`.
Desktop screenshots were inspected before and after; narrow navigation screenshots
were captured and inspected. This live view had no relationship rows. Populated rows,
full history-card composition and the complete NPC modal remain open matrix work.
Native bound-actor selection and playthrough scoping remain functional; this change
does not declare their current placement a final visual exception.

### NPC relationship history cards (2026-09-08)

Replaced the divergent plain history list with the reference hierarchy: section header,
ordered history cards, nested change entries, accessible signed/type badge, stored reason,
target arrow and optional tier chip, then one compact timestamp. Markup and presentation
derive from HerikaServer 529364c `lib/eventlog_helper.php` and `ui/core/npc_master.php`
lines 2266-2402 and 3190-3238. Existing native audit records remain the source; no schema,
relationship writes, provider calls, or history deletion were introduced.

Native audit-only note edits use Change and deletions use Deleted, rather than incorrectly
claiming a type or affinity change. Tier chips appear only for actual tier changes with a
stored reason. All displayed reasons, targets and badges remain escaped. Native history
currently exposes UTC `created_at`; mapping available source-event Morrowind game dates
remains open and is not declared an exception or completed parity.

Verification: 608 server checks and the full disposable management HTTP suite pass,
including real populated NPC audit cards. Temporary PHP fixtures render native code and
the pinned reference helper for gains, losses, type changes, note-only changes, deletion
and literal HTML-like input. Browser checks confirm escaped text/no script nodes and the
five correct native badges. No fixture rows are inserted into the live database.

`Temp/relationship-history-proof.cjs` compares those fixtures inside each actual NPC
editor at 1280/390, first as a source preview and then with deployed CSS. Populated and
empty screenshots were captured; narrow screenshots were visually inspected. Both have
14px card padding, 6px gaps, 12.8px row font, 7px/10px row padding, 3px accent border and
50.5px signed badges. Matching three-row fixture heights are 286.484375px at desktop and
323.4375px narrow; first-row heights are 63.265625px and 80.53125px respectively. Generic
border-box badge sizing and heading line-height overrides were corrected from measured
differences, not guessed.

The broader modal still supplies different widths (1128 versus 1133 desktop; 293 versus
303.5 narrow). This card work does not accept those differences or close the full NPC
modal/page matrix row. Populated relationship editor row controls also remain separate
from this now-ported history-card presentation.

### NPC editor viewport width and responsive thresholds (2026-09-08)

Traced actual parent boxes rather than adjusting history-card widths in isolation.
Herika's iframe document has 5px right margins on both html and body; native had only
one 5px margin. Native editor content now uses the combined 10px margin. The viewport
is a named inline-size container, so the corresponding General form, action card,
LLM summary and tab layout thresholds follow editor width, as the reference
iframe media queries do. The relationship card's narrow width uses that container's
width instead of the outer browser's viewport. Page-level spacing and the outer modal
header remain tied to the browser, and the 70vh editor scroll viewport is unchanged.

`Temp/npc-modal-responsive-proof.cjs` reads both actual editors at 1280/960/780/390.
General columns match 2/1/1/1; both switch to two-column tab layouts at the narrower
editor widths. The reference retains its excluded Background Life tab, so its wide
tab count is seven versus native six. History panel widths now match at 1128px,
840px and 663px. At 390px, reference iframe rounding produces 293px versus native
292.5px; this measured subpixel difference is recorded rather than called exact parity.
The native-only narrow Tags-row stretching rule was removed to preserve reference
right alignment. Source-preview screenshots were captured and the 960px single-column native state was
inspected. No form submission or live data changes were used for this comparison.
The four-width comparison then passed against deployed CSS after the browser harness
waited for the reference iframe's scripts before clicking its tabs. The deployed
populated/empty relationship-card fixture regression also passes, with unchanged
323.4375px narrow populated-card height. The existing 608 server checks pass.

This closes the missing double margin and wrong responsive coordinate-system causes,
not the entire NPC editor matrix. Remaining field placement, populated controls,
metadata semantics and other page families still require their own evidence.

### Diary content-only editor (2026-09-08)

Removed the extra Entry details/title disclosure from the Diary edit dialog. Like the
reference's hidden topic, the native title is retained as a hidden form value; Narrative
Manager still exposes its editable title metadata. The update route, identity, title,
kind and content remain intact. Empty status paragraphs no longer reserve layout space;
an error still becomes visible when its text is populated.

Copied the reference label size and inline help flow, and removed the native-only
narrow edit-dialog sizing override. The separate native deletion confirmation keeps
its existing narrow styling. Fresh screenshots show the native Edit Entry heading;
no speculative heading repaint/position workaround was added for the older observation.

Source-rendered fixture comparison in each actual page at 1280/390 asserts matching
y positions, widths and heights for the complete edit container, heading, label, help,
textarea, footer and button group. Container widths are 800/351px and height is
643.34375px; the textarea remains 400px high. The title is hidden and preserved, and
Cancel submits no request. Native narrow screenshots were visually inspected.

Evidence: `Temp/diary-editor-parity.cjs`, regenerated `diary-current-fixture.php/html`,
608 server checks and the full disposable management HTTP suite, including hidden-title
preservation. This closes the confirmed extra editor field and measured flow/sizing
differences. It does not accept unrelated reader, calendar, deletion or other page states.
The same geometry assertions pass against deployed CSS. The existing browser-mocked
422 check confirms blank content prevents submission, rejected edits retain the typed
content and show their error, and Cancel closes at both widths. No live save occurred.

### Diary backdrop close and audio cancellation (2026-09-08)

Pinned Herika diarylog.php closes its entry and edit modals when the outer backdrop
is clicked; closing the entry calls stopDiaryAudio, including request cancellation.
Native dialogs previously closed only through their buttons or Escape. Added equivalent
backdrop handling for the Diary reader/editor dialogs, using bounds checks to avoid
treating clicks inside dialog padding as outside clicks. Closure reuses the existing
abort/pause/release, audio-dock restoration, focus return and scroll restoration path.

`Temp/diary-backdrop-proof.cjs` runs rendered fixtures in the actual native page at
1280/390. Browser-local fetch and media mocks cover a pending speech request and an
already playing sentence: backdrop close aborts/pauses, clears the audio source and
reading state, and never requests the next sentence. Padding clicks keep the dialog
open. Edit/backdrop/reopen restores saved fixture content; focus and prior body overflow
are restored. Screenshots capture both mocked playback states, with no real POST,
provider call, generated audio, game interaction or live diary mutation. JavaScript
syntax and the existing 608 server checks pass.

This proves the closing/cancellation interaction, not live voice generation or audio
quality, and does not close remaining full reader presentation/data-mapping review.

Deployed rerun passes at both widths. The media fixture uses a valid silent WAV and
asserts the reading state is still active before closing (no decoder-error shortcut).
The screenshot shows the active Stop control. Mocked editor rejection checks also pass.
Runtime verification matches all 799 source files with no extras or legacy paths;
private paths remain forbidden and unauthenticated sessions remain rejected.

### Diary Play/Pause interaction (2026-09-08)

Pinned Herika diarylog.php toggleDiaryAudio reuses the current clip for Pause/Play;
Lorkhan previously restarted generation on every Play click. The shared reader now
synchronizes row and modal buttons, disables them while generating, shows Pause during
playback, and resumes the same sentence without a new request. Stop, close, completion
and failure restore the original labels and connector availability. Sentence queuing
remains intact; this does not change the provider endpoint or narrator selection.

Temp/diary-toggle-proof.cjs uses rendered fixtures and valid silent WAV/media mocks at
1280/390: pause/resume keeps the request count unchanged, both buttons show Pause,
ended advances one sentence, and backdrop close aborts or stops without queuing more.
Screenshots show the active Pause state. No live diary write, provider call or game
interaction occurred. JavaScript syntax and 608 existing server checks pass.

Still open: the native footer includes Export Text, Close, Stop and a media player
where Herika exposes a single toggle and status. Reader opening currently cancels
row playback; Herika retains it. Narrator versus author voice mapping also remains
unresolved. These are not accepted product exceptions or full reader parity proof.

The same interaction assertions pass on the deployed JavaScript. All 799 runtime
files match source; configuration, credentials and voices were preserved by the deploy.

### Diary single-toggle reader footer (2026-09-08)

Replaced the calendar Diary reader's Export Text/Close/Stop/media-player toolbar with
Herika's single audio toggle and status. Escape and backdrop closing remain available;
other non-calendar readers retain their export control. The native audio object remains
behind the toggle for sentence queuing, pause/resume and cancellation. Browser autoplay
failure points to Play rather than a hidden transport. Opening the same playing table
entry moves its status into the reader without canceling or restarting the clip.

Reference diary_adventure.css and rendered diarylog.php show a one-pixel container
border, 61px footer and 36px button. Restored that border and matched button line-height.
Temp/diary-footer-parity.cjs compares actual reference/native pages with synthetic diary
content at 1280/390: outer modal, parchment, footer and button y/width/height, padding,
font size and line-height now match. Screenshots were reviewed. HTML remains escaped
in Lorkhan; fixture content is not inserted as executable markup.
Temp/diary-open-playing-proof.cjs verifies table-to-reader continuity, synchronized
pause/resume without generation, sentence advancement and close cancellation with
browser-local audio/fetch mocks. No real provider, diary write or game operation.

Author-voice mapping, full content-format/data behavior, long/empty content and remaining
calendar/delete states still need review; this checkpoint is not full Diary acceptance.

Deployed reader geometry and playback-continuity probes pass at both widths. The
799-file runtime verification matches source; PHP/JavaScript syntax and 608 checks pass.

### Diary deletion and long/empty reader states (2026-09-08)

Pinned Herika diarylog.php uses confirm('Are you sure you want to delete this entry?')
for row deletion, not an editor modal. Replaced the native extra Delete Entry modal
with that confirmation flow; the row submits the existing scoped, CSRF-protected
narrative-delete form. Cancel sends nothing. A failed delete now reports deletion
rather than saving, restores its button and shows the reference-style alert. Removed
only obsolete Diary delete-modal CSS; Narrative Manager is unaffected.

Temp/diary-remaining-states.cjs checks desktop/narrow Cancel, accepted mock 422 and
retry availability, correct target ID, plus long/empty content, contained scrolling,
visible footer and Escape/focus return. No live delete occurs. The existing disposable
management HTTP suite passes, as do PHP/JS syntax and 608 server checks.
Temp/diary-long-empty-parity.cjs renders the same empty/long plain text in both actual
pages at 1280/390. Modal, parchment, footer and button geometry/padding/type metrics
match in all four cases; narrow screenshots were reviewed. Plain text was assigned
with textContent, not executed as HTML. This verifies these states, not author voice
selection or the remaining page matrix.

Deployed deletion/long/empty probes and editor rejection regression pass at both
widths. All 799 deployed files match source; deployment preserved configuration,
credentials and voices. No game was launched or controlled.

### AI Responses whole-page follow-up (2026-09-08)

Current live reference/native full-page screenshots showed the native scope disclosure
between the introduction and primary toolbar. Moved AI Responses filters below the
bottom pager, preserving its GET fields and native installation/playthrough selection.
Books retains its existing below-table placement; Journal is unaffected. Empty response
rows now use Herika's exact 'No AI response rows found.' text.

Temp/response-current-review.cjs checks the populated pages and prompt readers at
1280/390, verifies the scope disclosure follows the bottom pager and still expands,
and checks native Escape/focus restoration without POSTs. Current empty-page checks
show identical 104.375px table containers and 12.8px/19.2px typography. PHP syntax and
608 server checks pass. Deployed source verification matches all 799 runtime files.

The whole-page layout is not yet fully accepted: the reference has 79 pages and Next,
while native has one page and no pager link. The resulting top-toolbar heights differ
(44/144px reference versus 40/122.5px native at desktop/narrow). A matched pagination
fixture must separate the content-state difference from the nested native nav layout
before further CSS changes. Current data differs, so screenshots are not pixel-equality
proof for table rows or prompt contents. Full goal remains open.

### AI Responses matched pagination states (2026-09-08)

A rendered native template fixture with the reference's 79 pages/3950 rows isolated the
remaining pager difference: native links lacked the reference 5px margin, 7px vertical
padding, 500 weight and distinct top/bottom line heights. Matched those values and made
the native nav wrapper display:contents so Previous/Next participate in the reference's
flat flex flow; the semantic navigation element and scoped URLs remain. The action
cluster now uses margin-left:auto instead of relying on space-between. Books/Journal
pager styling is excluded by the existing book-log-page scope.

Temp/response-pager-fixture.php renders the actual PHP template with synthetic counts
and no database access. response-pager-compare.cjs and its bottom counterpart compare
button text, relative x/y, width/height, margin, padding and font metrics with actual
Herika pages at 1280/780/390. First-page top/bottom, middle-page top (Previous+Next),
and last-page top (Previous) all match exactly. Narrow pager screenshots reviewed.
No export, cleanup, provider call or database mutation was triggered. All 608 server
checks and git diff --check pass. This resolves the pagination mismatch documented
above, not the remaining full page matrix or unequal live response contents.

Deployed last-page comparisons match at all three widths; all 799 runtime files
match source. Deployment preserved configuration, credentials and voice files.

### Prompt Viewer header and metadata correction (2026-09-08)

Compared identical short system messages and label/driver/model metadata in the native
rendered PHP fixture and the reference's existing reader DOM. The native header had a
24px title with normal line height, an extra 12px gap, and generic padded close-button
styles, causing a 74px header at 390px where Herika has 42px. It now uses the reference
26px/1.2 title, inline copy and floated multiplication-sign close control with 28px/1.5
type and no button padding. Kept the semantic accessible close button. Copy weight and
letter spacing match; branding colors remain native gold.

Metadata now carries label/driver/model classes, 3px vertical padding, 13px type and
separate reference neutral colors instead of one generic blue chip. Identical content
checks at 1280/390 show matching header/title/close dimensions and message positions.
Narrow screenshots show the title on one line. Body prose remains escaped plain text.
Temp/prompt-matched-interaction.cjs verifies browser-mocked clipboard success/failure,
literal script text copying, visible failure help and Escape closure. No OS clipboard,
provider, database mutation or game interaction. PHP syntax and 608 checks pass.

Copy feedback still uses the native accessible status instead of Herika's temporary
button-label feedback; this is a remaining interaction difference, not declared parity.

Deployed fixture/clipboard checks pass at both widths. Runtime verification matches
all 799 source files, with persistent configuration, credentials and voices preserved.

### Prompt Viewer copy feedback and fallback (2026-09-08)

Matched Herika's temporary '✅ Copied!' button label, two-second reset, and failure
alert. The accessible success status is retained but visually hidden, avoiding an
extra feedback row that shifts the reader. Both rendered themes override the attempted
green background with their normal accent; verified reference orange/native gold.
Added the reference legacy copy fallback when Clipboard API is absent. Its temporary
textarea lives inside the native modal so it is not inert, is always removed, and
returns focus to Copy. A false legacy-copy result is treated as failure. Closing
resets the button and invalidates late asynchronous results. Non-prompt readers keep
their existing visible copy status.

Temp/prompt-copy-parity.cjs exercises actual reference and native reader handlers at
1280/390 with mocked Clipboard API and execCommand: success/reset, rejection alert,
legacy fallback and temporary-node cleanup pass. Native close/reopen and completion
after close do not leave stale feedback. Narrow success screenshot reviewed: title,
Copy feedback and close control remain on the same row. No OS clipboard or provider
was used. JavaScript syntax, 608 checks and diff whitespace checks pass. This resolves
the copy-feedback difference above, not every remaining page or reader state.

Deployed copy tests pass at both widths. All 799 runtime files match source;
configuration, credentials and voice files remain preserved.

### Prompt Viewer role, long and empty states (2026-09-08)

Compared system/developer/user/assistant/tool messages through the real native template
and pinned Herika prompt formatter (pure formatting functions extracted to a temporary
fixture, with no reference bootstrap/database access). Native developer/tool labels
incorrectly inherited the assistant purple. They now use the reference default yellow;
assistant remains purple and system/user keep their existing colors. The raw placeholder
now has the reference word-break and exact empty-payload wording.

Temp/prompt-state-fixture.php and prompt-state-compare.cjs render roles, long messages
and empty placeholders at 1280/390. Assertions compare font, text/background colors,
padding, margins, width/height and word-break for each role label/body/raw block; all
six comparisons pass. Native modal content has no horizontal overflow. Narrow five-role
screenshot reviewed; literal script-like text remains escaped. Existing provider metadata
is preserved even when messages are absent; no historical prompt is reconstructed.
PHP syntax, 608 server checks and diff whitespace checks pass. No live provider,
clipboard, database mutation or game interaction was used. These state checks do not
prove the remaining page matrix complete.

All six deployed fixture comparisons pass. All 799 runtime files match source,
and deployment preserved configuration, credentials and voice files.

### Books shared viewer controls (2026-09-08)

Pinned Herika events-memories.php Books uses the shared '📜 Prompt Viewer' header,
'📋 Copy' control, multiplication-sign close and the same temporary copy feedback as
AI Responses. Applied that reference header and copy behavior to native Books instead
of leaving its older generic buttons/status flow. Book titles remain in the table;
book text remains escaped in the existing monospace reading surface. Journal remains
outside these shared reference-reader changes.

Temp/books-copy-parity.cjs exercises actual reference handlers and the rendered native
book fixture at 1280/390. Clipboard API success/reset, rejection alert and legacy
execCommand fallback pass under mocks; the fallback removes only its own temporary
textarea (Herika has unrelated hidden textareas). Native close/reopen and late copy
completion checks pass. Narrow copied-state screenshot reviewed. No system clipboard,
provider, data write or game operation occurred. PHP/JS syntax, diff checks and all
608 server checks pass. Existing background reference source is read-only.

Deployed book copy comparisons pass at both widths. All 799 runtime files match
source; deployment preserved configuration, credentials and voice files.

### Events whole-page scope and introduction (2026-09-08)

Current whole-page comparison found native Current playthrough disclosure inserted
between the reference note and toolbar. Moved it after the bottom pager, retaining
installation/playthrough names and immutable-source-trace explanation. The introduction
and blue note now use pinned Herika events-memories.php wording; the raw log is no
longer described as if every event necessarily supplies AI context. No event query,
blacklist, deletion or context behavior changed.

Deployed events-frame-interaction.cjs verifies scope placement/expansion, selection and
deselection, and AJAX page two without mutation requests. events-identical-proof.cjs
compares reference/native Auto Refresh/Delete/Next controls and an identical seven-column
table at 1280/390: all measured styles, widths and padding match. Narrow screenshot
reviewed. PHP syntax, 608 checks and diff checks pass. All 799 deployed files match
source. Persistent configuration, credentials and voices were preserved.

Live rows and page counts differ by game/session, so their whole-page screenshots
are not evidence of identical populated data layout. Remaining full matrix acceptance
is still open; no live delete or game interaction was performed.

### Adventure Log current-state audit (2026-09-08)

Re-ran the deployed selected-date, both CSV export, UTC/Tamrielic mode switching and
empty-day checks. Displayed events appear in both relevant exports and no writes were
performed. Current reference/native table header widths, padding, borders and fonts
match. Download/mode controls also match measured size, margins, padding and typography.
Whole-page screenshots were refreshed at 1280/390, not reused from earlier work.

Unresolved narrow calendar navigation: Herika's 390px embedded page clips both month
links and truncates the heading; native keeps the controls visible by reducing the
heading to 16px under 650px. This is not accepted full visual parity or a Morrowind
product exception. Do not blindly remove the native responsive rule and claim success
from reproducing clipped controls. The remaining work is a source-based month-navigation
comparison, preserving access to previous/next while matching reference type hierarchy,
followed by UTC/Tamrielic and long month-name checks. No new product changes or deploy
were needed for this audit. The previously deployed commit remains 23fe9fb.

### Calendar month-navigation source parity (2026-09-08)

Traced the prior mismatch to pinned diary_adventure.css lines 121-153. Herika uses a
10px gap, 150px minimum-width links, 22.5px month text and a 300px heading with a 200px
minimum plus ellipsis at every width. Removed the separate native compact-navigation
override and restored those exact heading rules; other small-screen calendar/table
rules remain. This was a source/measurement-backed parity choice, not a responsive
redesign. Both Adventure Log and Diaries use the matching shared rules.

Temp/calendar-nav-parity.cjs compares the actual embedded reference and native calendars
at 1280/390. Container and child dimensions, relative positions, fonts, padding, margins,
gap and overflow/ellipsis behavior match exactly in all four comparisons. Narrow
screenshots were inspected. The consequence is explicit: edge month labels are clipped
at 390px just as they are in Herika. This is a shared reference limitation, not a
Morrowind-specific exception or an assertion that mobile usability has improved.
Temp/calendar-nav-keyboard.cjs verifies previous/next links still navigate by keyboard
for both pages and both UTC/Tamrielic modes at 390px with no writes. Existing 608 checks
and whitespace checks pass. A future shared responsive improvement must be considered
separately from the requested literal presentation parity.

All four deployed geometry comparisons and all four keyboard navigation paths pass.
Runtime verification matches all 799 files; configuration, credentials and voices
were preserved. No game or provider was used.

### Route inventory and API Keys panel alignment (2026-09-08)

- Audited tracked top-level and core PHP routes against this matrix: all 37
  canonical user pages are represented. Configuration exposes 15 tab targets,
  Control Panel 12, and Roleplay seven. Templates, bootstrap/feature helpers,
  two media endpoints and two canonical redirects are not separate pages.
  `placeholder.php` remains an unlinked generic feature-state route (no UI
  references); it is not evidence that any unavailable feature is implemented.
  Route coverage does not prove that every nested state has parity.
- Compared the deployed API Keys page with pinned Herika `core/api_badge.php`
  at 1280px and 390px. Removed native-only compact panel padding and toolbar
  alignment overrides. Restored reference Save Keys letter spacing and its
  500px width rule. Moved replacement-key guidance below the preset grid and
  associated it with each preset input via `aria-describedby`; blank keys still
  preserve stored values and saved secrets are never rendered into fields.
- Measured identical toolbar geometry: desktop 1164x40 with 94.359375x36 Save
  button; narrow 318x75.5625 with 169.015625x36 button. Both panels use 25px
  padding; preset grid begins immediately after the reference toolbar spacing.
  Inspected desktop/narrow screenshots after clearing reference input values
  in isolated browser memory. No screenshots contain saved key values.
- Existing 608 checks and PHP syntax passed. Browser checks at 1280/501/500/390px
  verified guidance association, Show/Hide, adding an unsaved custom card and
  confirming its removal, with all non-GET traffic blocked and no write attempts.
  Local server-only deployment preserved configuration, credentials and voices;
  all 799 runtime hashes match, with private-route/authentication checks passing.
- API Keys remains incomplete: duplicated provider/role badges, supported-provider
  grouping and complete custom/test states still need reconciliation. Do not
  treat differing key inventories or native credential security as proof of full
  page parity. The broader page-by-page goal remains active.

### API key test loading reader (2026-09-08)

- Compared pinned `core/api_badge.php` test-modal markup and its live isolated
  loading state against Lorkhan at 1280px and 390px. Replaced the small inline
  spinner with the reference full-reader dark overlay and centered 48px spinner,
  4px border and one-second rotation. The Close control remains above the overlay
  and now matches the reference's rendered neutral background. Gold replaces
  only the reference spinner accent.
- The panel is visually suppressed while pending, matching the empty reference
  iframe, but the live loading announcement remains in the accessibility tree.
  Reduced-motion preferences disable rotation. Results reveal the existing panel;
  completion and close hide the overlay. Existing abort, opener restoration and
  stale-request guards remain in place; no credential or provider request change.
- Inspected deployed/reference screenshots and measured the same spinner size,
  border width and box sizing. Mocked success and HTTP-error replies both dismiss
  loading; Close cancels a pending test, restores its enabled opener and ignores
  the late response. Escape restores focus after results. Both widths passed.
  All test POSTs were intercepted; reference inputs were cleared in browser memory
  before screenshots. No real provider calls or credential writes were made.
- PHP syntax, JavaScript syntax and existing 608 checks passed. Server-only deploy
  preserved configuration, credentials and voices. All 799 deployed files match
  source and private/authentication checks pass. This completes the loading-state
  correction, not API Keys result-detail, custom-editor or provider-group parity.

### Custom API key card structure and controls (2026-09-08)

- Compared the reference's actual Add Custom Key card with the native rendered
  template at 1280px and 390px. Flattened the new Label/input pair to the same
  sibling structure as saved/reference cards, assigning unique `id`/`for` pairs
  for both new-card inputs. Native identifier validation and editable saved labels
  are unchanged.
- Copied the reference button letter spacing, nonwrapping card rows and narrow
  Show sizing. Matched card height 226.625px, 40px toolbar, 20.15625px labels,
  42.15625px inputs and 36px buttons. Desktop/narrow card widths are 576/318px;
  Save/Delete widths are 57.84375/67.1875px. At 390px the reference gives Show
  250px and the key field only 26px. Native now reproduces this limitation; it
  is not a claimed mobile usability improvement or product-specific exception.
- Browser POST mocks exercised failed-save draft retention, retry success,
  clearing/hiding the entered credential after save, saved-label editing without
  changing the identifier, deletion cancellation, confirmed deletion and focus
  return to Add Custom Key. Both labels resolve to their input. Empty-list and
  saved-card states were captured; reference screenshots had all input values
  cleared in browser memory. No real keys were written or exposed.
- PHP/JavaScript syntax and existing 608 checks passed. Deployed server-only,
  preserving configuration, credentials and voices; all 799 deployed hashes and
  private/authentication probes passed. Provider grouping, complete result-detail
  parity and overall API Keys page composition remain pending. The full goal
  remains active.

### API authentication result reader (2026-09-08)

- Replaced the one-line result with the pinned `core/tests/apikey_test.php`
  hierarchy: uppercase provider, success/failure icon and heading, separate HTTP
  line, then safe explanatory text on errors. System-font sizing, heading word
  spacing and state-specific margins now match. Authentication/no-save guidance
  remains the dialog's accessible description instead of adding a visible row.
- The PHP response adds only the actual numeric cURL HTTP status for test calls;
  it still discards provider response bodies and never returns keys. The browser
  accepts only integer status codes 100-599 for display, uses text nodes for
  details, and labels missing/transport status as Test error. No quota/credit or
  generation success is inferred: Lorkhan's existing authentication-only request
  remains unchanged, unlike Herika's billable generation probe.
- Reference result HTML/CSS was reproduced from the pinned source inside its
  actual modal iframe, using synthetic outcomes and no request to a provider.
  Native browser POSTs were intercepted. Success/HTTP401/transport-error cases
  match panel, title and result-row dimensions/fonts at 1280/390px. Panel heights
  are 102/129/103px respectively; title is 99.265625x24px. Screenshots inspected,
  and literal script-like error text remains inert. Native loading, close/abort
  and Escape/focus checks still pass after the result changes.
- A temporary source-extracted cURL fixture exercised actual PHP test-branch
  metadata for 200/401/429/transport failure without network calls. Existing 608
  checks, PHP/JS syntax and the full disposable management HTTP suite passed
  (`api-result-http.txt`). No new repository test harness was added.
- Server-only deployment preserved configuration, credentials and voices; all 799
  deployed hashes and private/authentication probes passed. Provider/badge grouping
  and full-page API composition remain pending. Overall parity remains active.

### API badge consolidation: complete credential coverage audit (2026-09-08)

The preset-card styling is no longer the main remaining issue. A read-only
source-extracted comparison of `CredentialStore::allowedVariables()` and the
actual `$providers` array reports 32 built-in credential variables but only 16
preset editors. The Custom Keys loop accepts only `LORKHAN_CUSTOM_*`, so it does
not cover the other 16 built-ins. This is a confirmed missing management surface,
not an accepted OpenMW difference or a completed provider consolidation.

Missing built-in editors: Default STT, Default TTS, Chatterbox, Convai, Coqui AI,
KoboldCpp, Kokoro, MeloTTS, Mimic 3, OmniVoice, Piper TTS, PocketTTS, StyleTTS2,
XTTS, xVASynth and Zonos. These names come from the allowed source catalog; no
credential values or live store contents were inspected. Runtime default TTS/STT
consumers are in `connector/ProviderFactory.php`; the remaining defaults come
from `connector/ConnectorCatalog.php`.

Implementation requirements for the next structural change:

1. Use the reference's provider preset grid and Custom Keys editor, with one
   primary card per supported preset provider. The five extra role cards now
   interleaved in presets (OpenAI LLM, OpenRouter LLM, Custom LLM, Google LLM,
   Google Gemini STT) must remain reachable as distinct additional credentials.
2. Preserve all existing variable identifiers, aliases, stored values, environment
   precedence and connector references. Do not merge keys merely because their
   provider names match; `LlmConnector::CREDENTIALS` maps OpenAI/OpenRouter/Google
   aliases to independent variables, and `badge:` can refer to any allowed key.
3. Cover the missing built-ins through the additional-badge surface, including
   creating a managed value when absent. Keep environment-owned entries visibly
   protected. Do not invent a provider preset for unsupported Replicate/ITT.
4. Extend labels/save/delete handling coherently for additional built-in badges,
   rather than only changing the loop: `CredentialStore::setLabel()` and status
   labels currently accept custom identifiers only, and `delete_custom` explicitly
   rejects built-ins. Preserve canonical provider labels and default routing.
5. Compare the complete page with identical synthetic inventories: empty defaults,
   primary provider keys, distinct same-provider keys, custom labels, environment
   ownership and missing optional services. Test label/credential changes and
   deletion in the existing disposable HTTP suite, verify all connector pickers
   retain stable references, then verify secret-preserving deployment hashes.

This audit changes the next work from cosmetic card cleanup to credential-editor
coverage and grouping. It does not authorize overwriting credentials, changing
providers, or sending live provider tests. No product/runtime files changed in
this checkpoint; deployment remains the tested `d838c87` code.

### Provider presets and complete additional-badge coverage (2026-09-08)

- Implemented the structural audit: one primary card for each of 11 supported
  reference providers, in reference order. Removed the five role-specific cards
  from the preset grid. Their existing identities now appear with other saved
  additional credentials in Custom Keys. No values, aliases or connector bindings
  were copied, merged, deleted or reassigned by deployment.
- All 32 built-in variables are now represented exactly once: 11 primary plus
  21 additional. Unconfigured optional service/runtime slots are collapsed below
  the custom editor, using the same card controls. Saving an unused slot keeps its
  identifier, clears the replacement and enables label editing. After reload it
  appears among saved additional keys. Environment-owned slots remain protected.
- Added a central primary-provider label map shared by existing badge pickers.
  Additional built-ins support the existing label and deletion operations while
  primary labels/deletion stay protected. LLM aliases and TTS/STT direct variable
  references retain their distinct established formats. Deleting a managed
  additional value leaves its unconfigured built-in slot available again.
- Reference/native synthetic inventories compare all 11 provider cards at
  1280/390px, with matching titles, dimensions, input rows and button typography.
  Reference-only Replicate is absent because Lorkhan has no supported connector;
  ITT remains excluded. Isolated fixtures normalize supported-use text and
  environment ownership, then compare presentation with all POSTs blocked.
  Separate live GET/mocked-save checks verify real 32-slot coverage, blank secret
  fields, collapsed optional slots, successful save and identifier-preserving rename.
- Existing tests extended without adding a test file: 609 checks pass, including
  additional-key label/value/alias preservation. Full disposable management HTTP
  suite passes extra-slot save/rename/delete/reload and all three connector-picker
  references. Early fixture failures incorrectly expected every picker to use a
  `badge:` prefix; corrected to the verified existing LLM aliases and TTS/STT
  variable format, without changing runtime routing.
- Server-only deployment preserved configuration, credentials and voice files;
  all 799 hashes and private/authentication probes pass. No game launch, live
  provider call or live credential edit. Full-page embedded/empty/composition
  acceptance still needs review; neither this page nor the overall goal is marked
  complete from its component checks alone.

### Distinct Configuration and Control Panel hub shells (2026-09-08)

- The embedded API Keys comparison found an unrecorded shared-shell mismatch:
  native's below-780px override changed gutters to 6px and forced 520px content.
  Removed it for Configuration, restoring the reference viewport-filling shell.
  API Keys iframe geometry now matches at 1280x1000 (x12/y189.234375,
  1256x800.765625) and 390x1000 (x12/y491.859375, 366x498.140625).
- Source/live inspection showed Control Panel must not share Configuration's flex
  shell: its reference is a scrolling block page with `100vh - 220px` readers,
  minimum 520px (420px for viewport heights at most 800px). Gave Control Panel a
  scoped class and block content wrapper, copying these rules and its square
  upper-left panel corner. At desktop, main/panel/wrapper/iframe geometry now
  matches the reference exactly; at 390px the reader still matches 366x778px.
  Native's additional monitoring/tool tabs account for its extra navigation row,
  not a forced content-height or gutter override.
- All 12 native Control Panel tabs mount and satisfy the reference reader-width
  and height rules at 1280x1000, 390x1000 and 390x700. Inspected narrow whole-page
  capture. These are shell checks, not a claim that every child page is complete.
- Native API Keys retains a failed-save custom-key draft after Profiles -> API
  Keys at both widths, with the save POST mocked. The reference actually reloads
  this frame on reactivation (`activate` -> `reloadIframe`), confirmed by a
  browser-memory marker and source. Native intentionally retains its established
  draft protection to satisfy the goal's data-preservation requirement. This is
  not reported as equivalent lifecycle behavior or a visual product exception.
- Existing 609 checks and Control Panel PHP syntax passed. Source-only server
  deployment preserved configuration, credentials and voices; all 799 runtime
  hashes and private/authentication probes pass. No game launch or live writes.
  Full page/state acceptance remains open; this checkpoint corrects both hub
  structures rather than certifying the goal from iframe dimensions.

### Quickstart heading and Player structure

- Restored shared main.css, removing the navbar toast's unintended contribution
  to normal document flow. Header and Player now follow the reference's section
  and field grouping. Profile selection remains available after the primary form;
  Save and Continue describes its selected-profile summary accessibly.
- Compared live native/reference at 1280x1000 and 390x1000. Header, Player section,
  heading, label and input rectangles and typography match exactly. Inspected
  the narrow full-page screenshot. Product-specific player help preserves the
  distinction between the persona name and the character's actual game name.
- Browser checks at both widths confirm separate forms, usable profile disclosure,
  preserved player draft after opening/closing it, and the Player Management link.
  No POSTs or provider calls were made by those probes. Existing disposable
  management HTTP forms passed; 609 server checks, PHP syntax and diff checks pass.
- Server deployment verified all 799 runtime files and private/authentication
  probes; rollback /var/backups/lorkhanserver-code.GT3XYk. No game actions.
- This is partial Quickstart parity. Setup Default/Local LLM preset effects,
  local service provisioning, MiniMe and Player2 controls remain open; the native
  saved-connector sections are not claimed equivalent to those missing features.

### Quickstart service field and option grouping

- Derived TTS/STT form-group structure, inline service labels and small help text
  from pinned Herika Quickstart. Existing connector choices now use Recommended
  and Other TTS/STT Services optgroups, with the same recommended driver sets as
  Herika. Saved connector IDs, credential references and selected routes remain
  unchanged; this does not provision services or silently select new defaults.
- Compared both service cards at 1280 and 390 pixels with identical browser-only
  help/option fixtures and the optional Deepgram key hidden on both sides.
  Complete card and child rectangles match exactly; inspected narrow captures.
  These fixtures establish layout equivalence, not provider setup equivalence.
- Actual native choices have unique IDs and correct driver grouping. Selecting
  Keep current selection preserves the current Deepgram key visibility, as the
  existing JavaScript intends. The initial probe incorrectly expected it to hide;
  corrected that assertion without changing product behavior. All writes blocked.
- PHP syntax, diff checks and 609 server checks pass. Source-only deployment
  preserved configuration, credentials and voices; all 799 runtime hashes and
  private/authentication probes pass. Rollback: lorkhanserver-code.JCiSt5.
- Service provisioning, Setup presets and remaining Quickstart controls are still
  incomplete. This checkpoint does not close whole-page or whole-goal acceptance.

### Diary audio routing audit — implementation still required

Source evidence rechecked after 787df86:

- Pinned Herika ui/api/chim_diary_audio.php loads the saved entry and its author,
  resolves NPC/Narrator -> assigned/default Core Profile -> TTS connector -> voice,
  then caches full-entry audio under a content/author/voice/connector-derived key.
  diarylog.php requests it only after Play; row and reader share playback state.
- Native ui/tmpl/roleplay_reader.php and ui/home.php instead publish one global
  Narrator/default connector and voice. roleplay-reader.js posts each sentence's
  browser-supplied text to /api/v1/tts-previews. This is not author-voice parity.
- ManagementRouter::speechPreview uses the pronunciation-preview allowlist and a
  240-character text limit. It does not resolve an author or apply the repository's
  pronunciation dictionary. ManagementRepository::allowTtsPreview limits this lane
  to 30 requests per 60 seconds; sufficiently short diary sentences can exhaust
  that budget. This is a code-path risk, not a measured provider latency claim.
- Existing ProductRepository::speechContext handles profile voices, cloud catalog
  IDs, global race/gender fallbacks and sample-capable adapters. ProviderFactory
  already supplies local/cloud voice resolvers. ttsPronunciationContext and
  applyTtsPronunciation supply scoped speech replacements. Reuse these rules;
  do not add a browser voice override or relax the Studio preview allowlist.

Next implementation boundary:

1. Add a dedicated authenticated, CSRF-protected diary audio request keyed by the
   persisted narrative ID and installation. Reject deleted/non-diary/wrong-scope
   entries before provider work. Read text and author server-side. Preserve the
   recorded profile ID even if the actor's current binding has since changed.
2. Share profile-based voice/pronunciation resolution with the normal speech path
   rather than resolving a diary author by name or falling back to Narrator.
   Missing author/connector/voice must produce a clear safe failure.
3. Use bounded private cached audio, keyed by content and effective speech inputs,
   with duplicate generation protection and authenticated retrieval. Account for
   full-entry size/provider limits without weakening Studio preview protections.
4. Rewire both the diary row/modal and homepage latest-diary Play to the entry
   request. Preserve pause/resume, cancellation, close/focus and stale-request
   protection. Update help/status copy only when author routing actually exists.
5. Extend existing disposable management HTTP coverage: two authors/connectors,
   Narrator author, missing/deleted/wrong-scope entries, profile-binding changes,
   pronunciation, cache hit/invalidation and opaque provider failures. Use mock
   providers only. Browser checks must cover row/modal/home and cancellation.

No product code changed for this audit. The matrix now explicitly reopens home
and diary functional acceptance instead of treating previous layout proof as
proof of author-voice behavior. No provider calls, game actions or runtime writes.

### Diary author resolver groundwork

- Added ProductRepository::diarySpeechPlan, reading the persisted diary and its
  recorded author ID with installation/deletion/kind checks. It resolves that
  profile's effective Core Profile TTS connector, voice and pronunciation text.
  It does not consult the actor's current binding, generate audio or write data.
- Extracted shared private profile-based voice and pronunciation resolvers from
  the existing live speech methods. The diary path uses those same rules for
  cloud/sample voices, global race fallbacks, player overrides and scoped terms.
  No duplicate diary voice settings or browser-controlled voice overrides added.
- Extended the existing database integration suite with an NPC rebound to another
  profile after its entry, NPC race fallback/pronunciation, Narrator explicit
  voice, wrong installation, non-diary, deleted entry and deleted author cases.
  Fixtures are rolled back. Full integration vertical slice, 172-relation schema
  inventory, backup/restore and migration/durable-job runner passed. Existing 609
  checks and PHP syntax/diff checks passed. No live provider or game requests.
- Source-only deployment verified 799 runtime hashes and private/authentication
  probes; secrets and voices preserved. Rollback: lorkhanserver-code.cvnQTd.
- The UI is deliberately not marked fixed: authenticated diary audio generation,
  private cache and homepage/reader wiring are still required. The new resolver
  is internal groundwork; Play currently continues to use the old preview lane.

### Diary entry audio endpoint and reader wiring

- Added authenticated POST /manage/api/v1/diary-audio with the existing browser
  session, CSRF and bounded speech-request budget. The browser supplies only an
  installation and narrative ID; author, connector, voice, pronunciation and text
  are resolved server-side. Missing/deleted/wrong-scope entries are checked before
  cache lookup. Provider failures return opaque codes, never provider payloads.
- Added private DiaryAudioCache beside the configured media directory. Audio is
  keyed by entry/author/effective connector/context/spoken text, expires after
  seven days and is bounded to 64 clips/256 MiB with a 32 MiB per-clip cap. A
  nonblocking generation lock prevents duplicate simultaneous work. Cache reads
  remain behind the authenticated entry request; no public audio URLs are added.
- Diary row/modal Play and homepage latest-diary Play now request saved entries,
  using full-entry playback and caching like Herika. Pause/resume retains its
  browser clip. Existing non-diary readers keep their prior preview behavior.
  Closing the reader stops playback and aborts the browser request; this does not
  claim cancellation of a provider request already executing on the server.
- Existing unit suite: 613 checks, including cache reuse/input invalidation,
  concurrent-generation rejection, non-audio rejection and file-count bounds.
  Full management HTTP suite passed with actual endpoint + mock XTTS: author
  voice, first generation/cache hit, no repeated provider call, cross-installation
  rejection and invalid CSRF. Fixtures restore their settings. Initial failures
  correctly exposed the previously exhausted test rate window and a connector
  from another installation; fixed test setup without relaxing production checks.
- Deployed reader fixture checks at 1280/390 cover entry-ID-only requests, row/modal
  shared playback, pause/resume without regeneration, pending abort, close/stop,
  focus restoration and escaped text. Inspected the narrow playing state. Updated
  the old sentence-queue probe to reflect the full-entry request. No live TTS calls.
- Source-only deployment preserved secrets/voices, with all 800 runtime hashes and
  private/authentication probes verified. Rollback: lorkhanserver-code.L2abKn.
- Live homepage had no diary at both widths: empty state checked, populated
  homepage playback still unverified. Live-provider long-entry limits, changed
  author/connector cache invalidation through HTTP and provider-failure visual
  states still need acceptance; this is not full Diary/page/goal completion.

### Populated homepage diary playback and recoverable errors

- Rendered the current homepage diary fragment with isolated author/content/entry
  data, then inserted it into the actual loaded homepage at 1280 and 390 pixels.
  No live records were created. Mock WAV playback verified entry-ID-only requests,
  pause/resume without another request, Stop clearing audio and escaped markup.
  This closes populated-template interaction coverage, not live populated data or
  a new whole-page comparison. The live homepage remains in its empty diary state.
- Replaced the generic diary failure with allowlisted messages for missing/deleted
  entry or author, empty content, missing connector, missing voice, busy generator
  and oversized entry. Rate-limit guidance remains. Unknown/non-JSON errors retain
  safe generic copy; provider error text is never rendered.
- Eight mocked error states at each width leave Play enabled, audio cleared and
  raw error details undisplayed. Inspected narrow populated and error captures.
  No live provider calls or game actions. Initial temporary browser-probe syntax
  error was corrected before execution; all final probes passed.
- JavaScript syntax, diff checks and 613 server checks pass. Deployed source-only;
  all 800 runtime hashes and private/authentication probes pass, preserving secrets
  and voices. Rollback: lorkhanserver-code.Pwqcgx. Backend is unchanged from the
  preceding passing management HTTP suite. Full goal remains open, including live
  provider/long-entry behavior and changed-author/connector HTTP cache acceptance.

### Diary HTTP cache invalidation and deletion acceptance

- Extended the existing management HTTP test, using its disposable database and
  mock TTS server. A persisted entry text change, a different recorded author ID
  and a connector language change each produce a cache miss and exactly one new
  provider call. The mock observes the changed text and language, not stale inputs.
- After a clip is cached, soft-deleting its entry returns 404 without another
  provider call. Wrong-installation and invalid-CSRF coverage from the preceding
  endpoint test remains in the same run. Temporary author/content/settings changes
  are restored; no production records are involved.
- Full management HTTP suite passed. Python compilation and diff checks passed.
  Only the existing test and this evidence document changed; runtime deployment
  remains 8531d96 with no product-code changes in this checkpoint.
- Changed-author/text/connector cache behavior is no longer an unverified item.
  External provider limits and long-entry audio are still untested; mock HTTP
  acceptance does not establish provider latency or audible voice quality.

### LLM connector test reader structure

- Replaced the whole-dialog scroll/float-Close layout with a dedicated scrolling
  report viewport and an absolutely positioned Close button, following Herika's
  outer modal plus iframe reader structure. Retained native dialog focus handling,
  gold accents, redacted diagnostics and the existing save-before-test behavior.
- Removed the extra title-width reservation and isolated paragraph/preformatted
  report styles from the page's inherited compact typography. Reference test-page
  text is 16px Arial; JSON blocks retain 13px monospace with their 1em margins.
- Compared native live modal to the actual reference modal with synthetic report
  contents and pinned tests/llmtest.php CSS inside its blank iframe. Never loaded
  that test endpoint, which would call an LLM; all non-GET requests were blocked.
  Removed the reference fixture's leftover loader before visual comparison.
- At 1280/390, outer modal, Close rectangle, report width and title dimensions match
  exactly. Matched diagnostic panel heights are 328/481px respectively; paragraph,
  JSON and label typography/margins also match. Inspected narrow captures. Close
  stays fixed during report scrolling; Escape dismisses the native dialog.
- PHP syntax, diff checks and 613 server checks passed. Source-only deployment
  preserved secrets/voices; 800 runtime hashes and private/authentication probes
  pass. Final rollback: lorkhanserver-code.zXSYyu.
- This fixes the report structure, not the full LLM editor acceptance. Remaining
  provider/advanced field interactions and real test lifecycle states stay open.

### LLM test loading and repeated tests

- Pending reports now use Herika's blank reader/background with centered spinner,
  keeping Close available; status remains in the live region. Each new test resets
  the inner report scroll, matching a newly navigated reference iframe.
- At 1280/390, mocked save -> test -> success, repeat test, close while pending and
  failed-save states pass. A failed save sends no test; late completion does not
  reopen a dismissed dialog. Inspected the narrow pending screenshot. No actual
  connector saves or LLM calls were made by these browser probes.
- JavaScript syntax, diff checks and 613 server checks passed. Deployed and verified
  all 800 runtime hashes/private probes; secrets and voices preserved. Rollback:
  lorkhanserver-code.odcg0C. Remaining LLM field/provider review is still open.

### LLM advanced override controls

- Matched Herika's URL label and removed the extra paragraph between the advanced
  heading and its fields. Clear now blanks all seven numeric overrides and moves
  their range thumbs to their minimum, matching the reference Clear handler.
- Compared native and reference at 1280/390 with browser-only drafts and all writes
  blocked. Range-to-number updates, seven-field Clear, retained temperature and
  retained disabled YAML draft pass. Native also synchronizes typed numbers back
  to ranges; the reference does not, even after blur. Kept native synchronization
  rather than copying that stale-display behavior. Provider limits are unchanged.
- Inspected the deployed advanced panel screenshot. Passed JavaScript syntax,
  diff checks and 613 server checks. Verified 800 deployed runtime hashes and
  private/authentication probes; configuration, credentials and voices unchanged.
  Rollback: lorkhanserver-code.kCEIBW. Full provider/editor acceptance remains open.

### Full LLM editor comparison correction

- A fresh full-page capture exposed an error in the previous checkpoint: Herika's
  partial editor omits the advanced hint, but its full editor includes it. Verified
  both branches in pinned source 529364c. Restored the full-editor wording and its
  heading/hint margins; the previous paragraph-removal parity claim is superseded.
- Copied the reference 8px rounded range track and 16px circular thumb geometry,
  retaining gold instead of blue. The prior native browser track was bordered and
  partially filled. This change is scoped to the LLM editor, not global controls.
- Inspected full-page native/reference captures with password fields scrubbed and
  writes blocked. Re-ran advanced interactions at 1280/390; all checks pass with
  no connector saves or provider calls. Full provider switching acceptance remains
  open, as do other matrix items; this is not whole-page acceptance.
- 613 server checks and deployment syntax validation pass. Verified all 800 runtime
  hashes and private/authentication probes; configuration, credentials and voices
  preserved. Rollback: lorkhanserver-code.quTWtd.

### LLM service-switching draft comparison

- Provider now appears only for OpenRouter or Custom, matching reference service
  selection. Its draft value is retained while hidden. Choosing Custom preserves
  the existing URL and API-key choice instead of clearing them, and keeps Custom
  selected while editing the URL. Explicit hosted-service selection still applies
  that service's endpoint and matching API badge without changing the model draft.
- Compared five hosted services, Custom URL/key retention, URL editing and return
  to OpenRouter at 1280/390 in both editors. No saves; catalogue requests mocked.
  Browser draft checks pass. Inspected narrow Custom captures: reference overflows
  horizontally (737px content at 390), native stacks. This is recorded divergence,
  not whole-page parity. Custom driver/IP helpers and Player2 remain open; native
  Player2 still requires model input and must not hide it without backend support.
- Custom selection is an editing state; saved native endpoints are classified from
  their URL on reload. Distinct saved Custom service identity still needs review.
- JavaScript syntax, diff checks and 613 server checks pass. Deployed code verified
  against all 800 runtime hashes; private/authentication probes pass and existing
  configuration, credentials and voices are preserved.

### Saved Custom LLM service identity

- Added optional allowlisted service metadata to direct connector documents and
  revisioned forms. Older documents still infer service from their endpoint.
  Explicit Custom survives save/reload even for a recognised provider URL; sidebar
  badges use the saved identity. Service metadata does not enter request options.
- Saved Custom initialisation keeps URL/Provider editable and suppresses automatic
  provider catalogues, matching reference Custom behaviour. Explicit hosted-service
  selection updates the saved identity. This supersedes the prior checkpoint's
  pending saved-Custom-identity item; Custom driver/IP tools and Player2 stay open.
- Extended existing unit and HTTP suites: legacy documents unchanged, invalid
  service rejected, direct create/revise/reload retains Custom, and the mock LLM
  request contains no service metadata. 615 checks pass. The first HTTP run failed
  in the earlier diary-audio fixture (502) before LLM checks; a full clean rerun
  passed. No production records or real providers were used as fixtures.
- Browser initialisation fixtures at 1280/390 confirm a recognised URL stays Custom,
  remains editable, has no automatic catalogue, and switches explicitly to OpenAI.
  These are browser fixtures plus separate real HTTP persistence proof, not a
  production connector save. Deployment preserved configuration/credentials/voices;
  all 800 runtime hashes and private/authentication probes pass.

### AI Responses fresh populated/empty comparison

- Reopened deployed native/reference response pages and prompt readers at 1280/390
  with all non-GET requests blocked. The toolbar height difference initially seen
  was pagination-dependent, not an action-button spacing defect; no toolbar CSS
  was changed on that evidence.
- Normalised browser-only row contents and pagination to compare table structures.
  Desktop populated row/table heights match (59.5/125.1875px), as do empty states
  (38.6875/104.375px). Narrow empty table heights match (123.5625px). No database
  content was changed. These are presentation fixtures, not production empty data.
- Found and copied missing reference column constraints: response max-width 680px,
  HTTP Request min-width 240px. Narrow requests now keep the same minimum width
  inside the existing scrolling table rather than compressing to about 72px.
  Inspected the deployed narrow populated capture. Populated narrow row wrapping
  still differs (native 96.25px/reference 115.4375px in the matched fixture), and
  the inner table offset differs by 1px; full table acceptance remains open.
- Fresh prompt open, Escape dismissal and focus return checks passed. 615 server
  checks pass; all 800 deployed runtime hashes/private/authentication probes pass.
  Secrets and voices preserved. Rollback: lorkhanserver-code.0gDi3O.

### AI Responses narrow wrapping correction

- Computed-style comparison identified the remaining row-height difference: a
  later native rule replaced reference overflow-wrap:anywhere with break-word.
  Corrected the existing AI Responses rule; other readers are untouched.
- Matched fixtures now have identical populated narrow row/table heights
  (115.4375/200.3125px), desktop heights (59.5/125.1875px), and empty table heights
  (104.375px desktop, 123.5625px narrow). Inspected the new narrow screenshot.
  Small prompt-button column and 1px inner-offset differences remain recorded;
  this resolves wrapping rather than asserting complete page acceptance.
- Live prompt open/Escape/focus-return checks pass at 1280/390 with writes blocked.
  615 server checks and all 800 deployed runtime hashes/private/auth probes pass.
  Configuration, credentials and voices unchanged. Rollback: lorkhanserver-code.gIPmDM.

### Events calendar heading and filter label

- Fresh native/reference Events captures showed the native Tamrielic Time heading
  was plain text, unlike the reference calendar link. Added the same UESP calendar
  destination to both server-rendered and AJAX-rendered headers, retaining gold
  and using noopener/noreferrer for the new tab. No calendar data was changed.
- Matched the Hide label's normal weight, muted colour and 12.75px text instead of
  inheriting the generic bold form label. Existing associated select is retained.
- Browser checks confirm the link and safe target attributes on initial load and
  page 2, plus selection/deselection and scope disclosure. No mutation requests.
  Inspected the refreshed desktop capture; narrow capture also recorded. Full
  matched populated/empty Events acceptance remains open; live datasets differ.
- JavaScript syntax, diff checks, 615 server checks and all 800 deployed hashes /
  private-authentication probes pass. Configuration, credentials and voices
  preserved. Rollback: lorkhanserver-code.47bDlm.

### Events empty-to-live table lifecycle

- Pinned Herika misc_ui_functions.php print_array_as_table returns before emitting
  a table for zero rows. Native now follows that structure in initial and AJAX
  rendering, instead of an empty seven-column table. Zero-result counts and filter
  controls remain visible. No production deletion was used to obtain an empty log.
- The first live result can now build the absent table; later results prepend as
  before. Matched the recurring reference refresh interval of five seconds, keeping
  native's immediate initial check when enabled. Stop Live still clears the timer.
- Mocked empty page -> first live event -> Stop Live at 1280/390 passes. The table
  and calendar link return, literal HTML remains text, and there are no mutation
  requests. Inspected narrow empty-state screenshot. This combines pinned-source
  empty reference evidence with native browser fixtures; not a live empty-reference
  database comparison. Broader filter/refresh failure acceptance remains open.
- JavaScript/deployment syntax, diff checks and 615 server checks pass; all 800
  runtime hashes/private/auth probes verified. Configuration, credentials and voices
  unchanged. Rollback: lorkhanserver-code.fNjaH7.

### Events matched data and filter recovery acceptance

- Normalised event text, people, calendar, UTC and Record IDs in browser-only
  native/reference fixtures. Desktop table/row heights match at 97.5625/56.375px;
  narrow heights match at 249.0625/190.6875px. Remaining Record action width differs
  by about 6.14px after equalising IDs (font icon versus native emoji/button);
  table widths were not changed to conceal this control difference.
- Mocked hide chat -> empty, unhide -> restored row, failed hide -> original row
  preserved, then retry -> empty at 1280/390. Four filter requests per viewport were
  intercepted; no saved filters, production rows or reference data were modified.
  Inspected populated narrow and failure captures. Retry currently requires choosing
  the placeholder before selecting the same failed type again; recorded as open.
- Updated the existing empty Events HTTP assertion to require no table instead of
  old empty-table headings. The full management HTTP suite passes, including its
  real empty server-rendered page. This is a test/evidence-only checkpoint; runtime
  code remains the previously deployed 48d681c. Reverified all 800 runtime hashes
  and private/authentication probes. Full Events/page-matrix acceptance stays open.

### Events same-type filter retry

- Reset the Hide action picker after each attempted filter update. After a failed
  request, selecting the same type now triggers a retry directly; the old state
  required manually selecting the placeholder first. Existing rows/error feedback
  remain until the next successful refresh. No automatic mutation retries added.
- Updated the browser recovery probe to assert the placeholder reset and retry the
  same type immediately at 1280/390. Hide/unhide, failure preservation and recovery
  pass with four mocked requests per viewport and zero unmocked writes. This closes
  the retry issue recorded above; Record icon geometry and full matrix remain open.
- JavaScript syntax, 615 server checks and all 800 runtime hashes/private/auth probes
  pass. Deployment preserved configuration, credentials and voices. Rollback:
  lorkhanserver-code.E9gaCS.

### Adventure Log fresh day/reader review

- Rechecked live populated day selection, empty day, Regular/Tamrielic switches,
  Current Date and Entire Adventure Log exports. Both CSVs contain the displayed
  event; no writes or provider calls. Compared pinned/live reference day table and
  normalised browser-only event/location fixtures at 1280/390.
- Found the shared calendar CSS forcing a 670px minimum on Adventure Log. Removed
  that inherited minimum for Adventure only, matching the reference fluid four-
  column structure. Diary tables are unchanged. Inspected narrow rendered fixture.
- Desktop row/location-divider heights match (58.375/39.1875px). Narrow table now
  fits its 346px content area rather than scrolling a 670px table. Reference direct
  page has 368px available; the 22px native frame difference changes narrow wrapping
  and remains open. Do not treat earlier broad narrow-review notes as exact parity.
- 615 server checks pass; all 800 deployed hashes/private/auth probes verified.
  Configuration, credentials and voices preserved. Rollback: lorkhanserver-code.EoCZwB.

### Adventure hub comparison correction

- Compared the native Adventure tab to Herika's actual embed=1 Adventure iframe,
  not its standalone page. Both hub content widths are already identical: 1236px
  at 1280 and 346px at 390. The previously recorded 22px frame issue was a comparison
  mismatch and is superseded; no frame padding was changed.
- Computed styles isolated the remaining narrow wrapping difference to inherited
  overflow-wrap:anywhere. Applied reference break-word to Adventure cells only.
  With identical browser fixture rows, all four column widths and row heights now
  match exactly in both hubs: 58.375px desktop, 96.75px narrow. Inspected capture.
- 615 server checks, deployment syntax and 800 runtime hashes/private/auth probes
  pass. No provider calls or game control; configuration, credentials and voices
  preserved. Rollback: lorkhanserver-code.XOh3Q9. Full matrix remains active.

### Diaries embedded table refresh

- Compared within both Roleplay hubs. Reference has no current-month diary rows;
  used its actual table with source-shaped synthetic row/action markup and native's
  freshly rendered PHP fixture. No production diary or provider was used as data.
- Removed inherited 670px minimum from diary table and matched reference break-word
  wrapping. At 1280 all five columns and 60.34375px row height match exactly; at 390
  both tables are 386.453125px wide with identical columns and 154.3125px row height.
  The reference's small horizontal overflow is retained, not labelled overflow-free.
  Inspected native narrow populated capture. Reader/edit/audio wiring unchanged.
- Refreshed mocked reader checks at both widths: pending audio abort, playback stop,
  dialog padding versus backdrop behavior, focus/scroll restoration pass. No real
  TTS requests. 615 server checks and all 800 deployed hashes/private/auth probes
  pass; configuration, credentials and voices preserved. Rollback:
  lorkhanserver-code.kLJdwF. Broader matrix and live-provider limits remain open.

### Diary table action styling correction

- Compared computed styles and populated screenshots in both actual Roleplay hubs
  at 1280 and 390. Native Delete incorrectly inherited the green submit style;
  switched its class to the shared btn-danger semantic used by Herika. Native table
  Play now uses the reference neutral grey; the reader audio control stays gold.
- All three table action backgrounds, text/border colours, fonts and padding now
  match the reference. Column widths and row heights remain identical. Inspected
  both narrow captures; existing reference horizontal overflow remains documented.
- 615 server checks and mocked reader abort/stop/backdrop/focus checks pass at both
  widths. All 800 deployed runtime hashes and private/auth/health probes pass;
  configuration, credentials and voices preserved. Rollback: lorkhanserver-code.fns9L6.
  No live TTS or game control. Full page matrix remains active.

### Books content sizing refresh

- Fresh actual-hub comparison retained native PHP table markup and normalized one
  reference/native row. The earlier striping-only probe missed inherited button
  font/margins and a shared 480px cell maximum. Removed those constraints for the
  Books table while retaining the keyboard-accessible content button and reader.
- Identical fixture columns and row heights now match at 1280 (1232px table,
  37.1875px row) and 390 (397.84375px table, 267.4375px row). Inspected populated
  desktop/narrow and empty captures; reference narrow overflow is not hidden.
- Fresh empty tools, pagination fixtures, escaped reader, mocked clipboard success
  and failure alert, focus/scroll restoration, long content and Escape pass. The
  older clipboard probe expected obsolete inline error text; updated the temporary
  probe to assert the current reference-style alert. No production writes or TTS.
- 615 server checks and all 800 deployed hashes/private/auth/health probes pass.
  Configuration, credentials and voices preserved. Rollback: lorkhanserver-code.T5LFKs.
  Full page matrix remains active; these comparisons do not establish completion.

### Google Free STT: rejected exception corrected by source audit

- This is an incomplete parity feature, not an accepted OpenMW exception. The current
  disabled control/registry says browser dictation cannot preserve session/target
  fencing. That is an implementation gap, not proof that a fenced bridge cannot work.
- Pinned Herika ui/stt_connectors.php opens ui/addons/pmstt/index.html inside the
  existing test modal. It provides browser speech recognition, language and pause
  delay, interim/final text and Start/Stop. Final text reaches PlayerSays.php, which
  queues rolecommand|ImpersonatePlayer@...@inputtext for client-side input handling.
  The reference uses a mutation through GET and wildcard CORS; neither is suitable
  for copying into Lorkhan's authenticated management boundary.
- Native Router::createTurn and Repository::acceptTurn require installation, current
  session/generation, playthrough and fingerprint, with speaker/target/context in the
  protocol envelope. The typed debug-command schema contains no transcript/chat
  command. Read-only client inspection at 6daab0c4b2e33b109575e7592deb8e83298b1bd0
  finds player.lua::submitText resolves the target and builds fresh conversation
  context; the debug queue executor does not forward text to this path.
- Implementation dependency: add a dedicated bounded, authenticated management POST
  and idempotent expiring transcript queue scoped to the selected live session and
  generation. Negotiate client support; have the client validate/resolve its current
  target and feed recognized text into the ordinary player-input lane. Do not reuse
  an earlier turn's target/context or expose the native pairing credential in JS.
  Integrate recognition controls into the reference-style modal only once this path
  exists. Clear recognition timers and abort pending capture on Stop, close, navigation
  and session change; render transcripts with textContent and surface send failures.
- Acceptance: mocked recognition interim/final results, pause delay, denied/unavailable
  microphone, Start/Stop/reopen, duplicate delivery, offline/stale generation, changed
  target, busy lane, expiry and exact-once turn submission. Compare modal populated,
  empty and failure states at desktop/narrow widths. No microphone or game was opened
  during this audit. Browser-only transcription is not completion of this feature.
- Reference checkout was observed at 1aa2021d854d7a2811b89c0a06c23e09284e2169; pinned
  529364c still remains the task baseline. Verified the two pmstt files are unchanged
  and the pinned STT page has the same launch path. Future visual comparisons must
  re-establish which source version the live reference is serving.
- Documentation-only checkpoint. Runtime remains c4796ff, with its previously verified
  800-file deployment; no claim of a new deployment or completed STT parity.

### Core Profile preset container layout

- Ported the pinned 529364c Core Profile preset row's 540px container rules:
  stacked label/select/Apply, full-width stacked secondary actions, and wrapping
  status text. Replaced the divergent native viewport-only 600px partial rule.
  Existing llm-right container ownership is unchanged; no profile data was saved.
- Deployed browser checks at 1280, 860, 800 and 390 confirm row layout above the
  breakpoint and equal-width stacked buttons below it. The 800px viewport has a
  524px editor panel and correctly stacks despite its wider viewport. No row
  overflow; Save as new/Escape/focus return pass. Inspected native narrow capture.
- Reference basis for this bounded change is the pinned source CSS, not the current
  live Profiles page: its source has since changed substantially. Full preset
  catalogue, saved semantics and page-wide visual acceptance remain open.
- 615 checks and all 800 deployed hashes/private/auth/health probes pass. Config,
  credentials and voices preserved; rollback lorkhanserver-code.0PnZNj. No provider,
  microphone or game operations. Full parity goal remains active.

### Named Core presets retain latest diary context

- Catalogue audit found the implemented Latest Diary in Context control was captured
  by the profile form but omitted by CoreProfilePreset::FIELDS. Added the same
  latest_entry_in_context boolean to named preset capture/validation/apply. Explicit
  false is retained; older preset documents that omit it leave current state alone.
  Identity, prompts and connector bindings remain outside named presets.
- Extended existing unit and management HTTP tests, without a new test file: enabled
  capture, disabled Apply, legacy omission, Save as new/export, overwrite/export,
  import and confirmed Apply followed by the actual reloaded checkbox state.
- 618 server checks pass. Full management HTTP passed in named-diary-preset-http-3.txt.
  First attempt reached the new readback but used a helper that does not understand
  aria-labelledby; switched to the Page parser already used for this editor. Second
  attempt failed earlier in the diary-audio fixture with 502; retained that evidence
  separately instead of reporting it as a preset failure or ignoring it.
- All 800 deployed runtime hashes/private/auth/health probes pass; config, credentials
  and voices preserved. Rollback: lorkhanserver-code.shhmDB. No live providers or game
  operations. No presentation change or new visual-completion claim in this checkpoint.
- Built-in Default/Local LLM/Follower/Passive catalogue mapping is still incomplete:
  pinned presets also change boredom/combat/quest policy beyond today's named preset
  field set. Do not present a partial settings bundle as full built-in parity.

### Reference checkout correction and preset name modal parity

- Corrected the previous inference that Herika had advanced and removed presets.
  The shared MonoRepo/HerikaServer checkout is on codex/visual-context at 1aa2021d
  (2026-08-23), whereas the pinned comparison is 529364c (2026-09-05). Current remote
  unstable 94518016 retains presets; core_profiles.php, global_settings.php and
  settings_presets.php are unchanged from the pinned baseline. Use the clean pinned
  D:/wt/herika-unstable-deploy-20260906 worktree for further reference source reads.
- Deployed /var/www/html/HerikaServer/ui/core/core_profiles.php SHA256 equals the
  pinned source (79ed1cf31ac399cd1eec6b4832daf4bfbb9f46ec5daf065729122d7f24b47de0).
  The initial GET had no selected editor; its missing preset markup was not evidence
  of removal. Existing read-only ?edit=1 renders the full preset row and dialogs.
- Compared that actual reference with native Save as new at 1280/390. Split the
  native implicit label/input into the same block label and full-width explicit text
  input, preserving hidden-field handling. Matched label/input/error-space geometry
  and description wording; blank feedback space remains during save and resets on
  reopen. Native name length and preset schema/identity protections are unchanged.
- With the same profile label in unsaved browser state, modal dimensions match:
  440 x 250.6875 at 1280; 351 x 269.53125 at 390. Inspected narrow capture. Native
  duplicate-name mock preserves draft, reports error, and closes/reopens with clean
  feedback and returned focus. No real POST, provider or game calls.
- 618 server checks, JS syntax and 800 deployed hashes/private/auth/health checks
  pass. Configuration, credentials and voices preserved; rollback yNgBko. Full
  preset catalogue and whole-page acceptance remain open.

### Core preset feedback states

- Ported pinned core_profiles.php setStatus semantics and matching success/error
  colour rules into the existing native preset row. All selection, pending, import,
  export, save and failure updates now reset both tone classes and the tooltip.
  Status remains an aria-live region and error wording remains visible text.
- Deployed browser probes at 1280/390 cover invalid import, saved/imported success,
  export failure, overwrite/apply revision conflicts, and neutral selection reset.
  Verified reference colours (#ff9b9b error, #8fe0b0 success), exact tooltip text and
  inspected narrow captures. Five intercepted fixture POSTs per viewport; no real
  profile, preset, provider or game writes. This checks feedback, not full built-ins.
- JS syntax, 618 server checks and all 800 runtime hashes/private/auth/health probes
  pass. Config, credentials and voice files preserved. Rollback: 6VdbaX. Full matrix
  and built-in preset/runtime mapping remain open.

### Ground item description filtering checkpoint (2026-09-08)

- Added Context > Ground Items Descriptions Only, default off, matching the reference control's filtering intent. Uses installation-scoped resolved descriptions, not client-provided prose. Description availability remains usable when description text is hidden. Equipment/inventory and points of interest are unchanged.
- Older global documents and named presets normalize the added flag to false. Existing unit coverage now checks blank descriptions, content-file isolation/case normalization, described counts, hidden text and disabled behavior: 625 server checks passed.
- Full management HTTP suite passed (`ground-filter-http.txt`), including checked/unchecked save and reload. Browser checks at 1280 and 390 confirmed the live control defaults off and toggles; narrow screenshot inspected. Initial browser probe timed out because it had not selected the Context & Knowledge tab; corrected navigation passed. This is not a full counterpart visual-parity claim.
- Server-only deployment verified: 800 runtime hashes match, no extra/legacy files; private/auth checks passed; configuration, credentials and voice contents preserved. Rollback: `/var/backups/lorkhanserver-code.Z2X8tX`. No game or live provider calls.
- Inventory description filtering and the remaining Context controls are still open. Full page parity remains incomplete.

### Native inventory context wiring checkpoint (2026-09-08)

- Source tracing found OpenMW emits bounded player inventory in `context.inventory` (`adapters/openmw.lua` / `context.lua`), while the player prompt read only `context.playerState.inventory`. The renderer now uses the actual native lane when nested inventory is absent; an explicitly empty nested inventory remains authoritative.
- Existing equipment/inventory Context selection still gates rendering. Description lookup now also collects nested player/target/nearby-actor inventories, retaining existing row bounds and identity resolution.
- 628 server checks passed, including native inventory/count rendering, disabled selection, and explicit nested-empty precedence. Server-only deployment verified all 800 runtime hashes and private/auth checks, preserving configuration, credentials and voices. Rollback `/var/backups/lorkhanserver-code.XKCfCY`.
- No UI layout or client changes, no game launched. Inventory Items Descriptions Only is still pending; this closes its underlying native inventory wiring gap, not full Context parity.

### Inventory description filtering checkpoint (2026-09-08)

- Added Context > Inventory Items Descriptions Only after the ground-item control, default off, with legacy global/preset normalization. Filters copied player/target inventory using resolved description identity; leaves equipment/ground lists alone. Matches reference `chimFormatInventoryPromptLines` stack cutoff of five. Description availability is independent of whether description prose is displayed.
- Shared description identity predicate is used for ground and inventory filtering. 633 server checks pass; existing coverage includes counts, equipment/ground independence, hidden text, old documents/presets and stack cutoff.
- Full management HTTP suite passed in `inventory-filter-http-diagnostic.txt`, including checked/unchecked persistence. Earlier runs failed before the control tests at diary-audio assertions (one explicit 502). A temporary exception diagnostic was added for the third run, did not reproduce the failure, and was removed before deployment. Root cause of intermittent diary test failure remains unresolved; do not describe it as fixed.
- Live browser toggle probes passed at 1280/390 with no saved production changes; narrow screenshot inspected. These checks do not prove full reference-page geometry parity.
- Final server-only deployment: 800 runtime hashes match; private/auth checks pass; configuration, credentials and voice contents preserved. Rollback `/var/backups/lorkhanserver-code.YHY1Mo`. No game/client/provider calls. Remaining Context event controls and the full page matrix remain open.

### AI Responses prompt-button geometry checkpoint (2026-09-08)

- Fresh live counterpart inspection isolated the residual column-width difference: reference button computed `display:inline-block` and `letter-spacing:.3px`; native used inline-flex and normal tracking. Applied those exact two rules to AI Responses prompt buttons only, preserving the decorative icon's accessible markup and existing reader events.
- Normalized populated fixtures now have identical six-column widths: desktop 136.2/359/198.1/156.8/250.2/86.7; narrow 55.8/82.2/65.1/150.7/240/55.1. Populated/empty table and row heights match. Native narrow screenshot inspected. Fixture changes were browser-only GET-backed comparisons, not production data writes.
- Remaining measured discrepancy: native table is 1px higher relative to its content panel. Full live reader/state parity remains separate; do not count this correction as whole-page completion.
- Server-only deployment verified all 800 hashes, no extra/legacy files, protected private routes/auth and preserved configuration/credentials/voices. Rollback `/var/backups/lorkhanserver-code.v7TKs7`. CSS-only change; no game or provider calls.

### AI Responses reader comparison checkpoint (2026-09-08)

- Opened the actual reference/native prompt readers, normalized the message body to one identical SYSTEM fixture and removed metadata in browser memory only. At 1280: both modal widths 1152, heights 255.171875; at 390: both widths 351 and heights 255.171875. Message row/header/body geometry and fonts match at both widths. Paired narrow screenshots inspected; branding colours remain intentionally different.
- Native clipboard-mock checks at both widths passed: exact multiline literal text, success feedback, close/reopen reset, denied-copy alert, Escape and focus returned to opener. No provider/production POSTs or real clipboard writes. Initial probes were corrected for normal whitespace handling and asynchronous alert dismissal; no product change was needed.
- Evidence: temporary `response-reader-compare.cjs`, `response-reader-copy-proof.cjs`, and paired `response-reader-{ref,native}-{1280,390}.png`. This verifies the normalized single-message reader, not all live metadata/multimessage or raw-unavailable states. The separate 1px table offset and full matrix remain open.
- Documentation-only checkpoint; runtime remains `32fafda`, with no new deployment required.

### AI Responses equivalent-frame correction and multi-role reader (2026-09-08)

- Corrected the earlier 1px table-offset finding: the comparison used reference `#responselog-tab` (with border) against native `.roleplay-log-page` (inside its bordered tab). Comparing equivalent `.tab-content` frames proves matching table Y: 155.25 at 1280 and 308.75 at 390, populated and empty. No product CSS compensation is appropriate. This supersedes earlier notes listing the offset as unfinished work.
- Extended the actual-reader normalized fixture to SYSTEM/USER/ASSISTANT, connector label/driver/model badges and long text. Both modals measure 1152 x 594.46875 at desktop and 351 x 900 at narrow; message geometry and metadata wrapping match. Paired narrow screenshots inspected. Fixture text and metadata were browser-only; no persisted/provider data altered.
- Evidence: `response-frame-matched.cjs`, `response-reader-multi.cjs` and `response-reader-multi-{ref,native}-{1280,390}.png`. Raw-unavailable, all retained-message variants and full embedded acceptance remain distinct checks. Existing clipboard/Escape/focus evidence remains valid.
- Documentation only; deployed product code remains `32fafda`. Full page matrix remains active.


### Ambient combat dependency checkpoint (2026-09-08)

- Pinned reference `lib/data_functions.php` around 2947 filters only `death` events whose content contains `has killed` (case-insensitive); it retains `has defeated` events. Default is false. This is not a blanket combat-event or bark filter.
- Native `EventLogRepository::projectSource` can project `death` source events, but preserves only display text in metadata. `ProductRepository::promptContext` includes death payloads in scoped history. There is no native death producer in the current Lua source: verified clean client checkout `D:/wt/lorkhan-gamedata-ack` at `6daab0c4b2e33b109575e7592deb8e83298b1bd0`, matching remote `RANGROO/LORKHAN` main.
- Required implementation: bounded OpenMW death observation with stable victim/killer identity, death-event deduplication and player/party attribution; preserve typed classification through ingestion/projection; default-off global control filters only ambient deaths from prompt history while retaining immutable source/eventlog records. Test player/party and ambient deaths, unknown killer, repeated snapshots and on/off history selection. Avoid copying a Skyrim prose substring as an OpenMW classifier.
- Client capture and game acceptance are not claimed by this server-only UI goal. Keep the feature visibly tracked as missing, not an accepted product exception or an inert checkbox. Other page work can proceed without launching the game. This checkpoint changed documentation only.

### Playthrough list pagination checkpoint (2026-09-08)

- Replaced the first-100-only manager list with installation-scoped 100-row pages and one-row lookahead. Previous/Next preserve the selected record and embedded state. A directly linked selected playthrough is looked up independently of the list page, retaining installation/deletion guards. Other generic repository consumers retain their existing bounded default.
- Existing HTTP suite extended with 105 isolated playthroughs: first/second page, embedded links, direct selection outside the first page, and out-of-range empty navigation passed. First test placement used a helper before definition; second fixture used raw MD5 UUIDs that failed application validation. Corrected fixtures use PostgreSQL valid random UUIDs; no production validation was relaxed. Full suite passed in `playthrough-pagination-http-3.txt`.
- 633 server checks and PHP lint passed. Actual PHP pager markup rendered with fixture state: desktop/narrow keyboard order, link parameters and bounds passed; narrow screenshot inspected. This is a functional list-access improvement, not proof that the still-missing snapshot storage/switch/delete/timeline UI has parity.
- Server-only deployment verified all 800 runtime hashes, private/auth checks, and preserved configuration/credentials/voices. Rollback `/var/backups/lorkhanserver-code.PNDDPS`. No production fixture records, game launch or live provider calls.

### TTS Test backdrop close parity (2026-09-08)

- Reference TTS connector test closes on backdrop clicks and unloads its test frame. Native handled Close/Escape only. Added outside-bounds backdrop handling to the native dialog, using the existing close cleanup to abort pending preview, stop/release audio, reset the run button and restore opener focus. Inside clicks remain unaffected.
- Live desktop/narrow browser probes at 1280/390 passed: inside click stays open, backdrop closes during a mocked pending preview and signals abort, no audio src remains, focus returns to Test, reopen resets and Escape still closes. All POSTs were browser-mocked/blocked; no real provider calls. JavaScript syntax check passed.
- Azure automatic Validmoods remains a separate missing feature: current provider only consumes fixedMood; response mood transport across streamed/queued speech must precede an active automatic-style control. Existing save-before-testing behavior matches the reference and was not changed.
- Server-only deployment verified 800 matching runtime files and private/auth checks; configuration/credentials/voices preserved. Rollback `/var/backups/lorkhanserver-code.7Ir9Wt`. This closes the backdrop interaction gap, not whole TTS-page parity.

### STT Test backdrop close parity (2026-09-08)

- Reference `ui/stt_connectors.php` closes the test modal on its backdrop and unloads the iframe. Native now closes only for clicks outside dialog bounds, sharing the existing cancellation/sample-reset/focus cleanup. Inside clicks are retained.
- Live desktop/narrow probes at 1280/390 passed: mocked save followed by pending transcription, inside click, backdrop abort, sample paused/reset, opener enabled/focused, reopen and Escape abort. All saves and provider POSTs were mocked; no microphone or provider calls. JavaScript syntax passed.
- Server-only deployment verified 800 hashes, no extra/legacy files, protected private/auth routes, and preserved configuration/credentials/voices. Rollback `/var/backups/lorkhanserver-code.KKBMx3`. Google Free STT and the wider STT/full-page matrix remain incomplete.

### Standalone page inventory and obsolete placeholder removal (2026-09-08)

- Compared PHP entry points declaring page titles against the complete matrix, excluding template fragments. Only shared bootstrap and the obsolete `ui/placeholder.php` were outside it. Cross-checked the current navbar, Configuration groups and all twelve Control Panel tab destinations; this is inventory evidence, not visual acceptance for their content.
- Repository-wide search found no caller/link to `placeholder.php`. The page accepted a feature query and claimed that even live features performed no action. Removed this obsolete unlinked page; no supported feature or navigation entry was removed. Deployed URL with `feature=config.globals` now returns 404.
- 633 server checks passed. Server-only deployment verified 799 runtime hashes, no extra/legacy files, protected private/auth routes and preserved configuration/credentials/voices. Rollback `/var/backups/lorkhanserver-code.xW2qho`. Full matrix remains active; bootstrap is not a user-facing page.

### API Keys environment-status layout checkpoint (2026-09-08)

- Live OpenRouter counterpart cards matched at desktop, but native environment-source text wrapped the narrow header and added 28px. Replaced the persistent source label with a compact focusable lock indicator and explicit Environment managed accessible label/description. Hover/focus shows the full explanation; disabled managed-key controls and server protections are unchanged.
- Native/reference cards now measure 576 x 136.15625 at 1280 and 318 x 136.15625 at 390. Embedded positions match. Native standalone retains its navbar (82px higher card offset versus the standalone reference without that navbar); this is documented rather than claimed as full-page equality.
- Keyboard help visibility, viewport bounds and disabled managed-key fields passed for standalone/embedded at both widths. Password values were cleared in browser memory before captures; no credential POSTs, tests or real key edits. Narrow card screenshot inspected. Full API page/custom states remain tracked separately.
- 633 checks passed. Server-only deployment verified 799 runtime hashes, private/auth gates and preserved configuration/credentials/voices. Rollback `/var/backups/lorkhanserver-code.0WOYzQ`.

### API custom-key draft state checkpoint (2026-09-08)

- New custom-key cards match reference dimensions in standalone/embedded checks: 576 x 226.625 at desktop, 318 x 226.625 narrow. Absolute positions differ with existing custom-key counts and scrolling; no full-page equality claimed from card dimensions.
- Found and fixed missing label-only navigation protection. Saved custom display labels now track their last persisted value; failed saves retain the warning. A newer label edit during a save is preserved and reported as unsaved instead of falsely reported saved. Newly created labels initialize the saved baseline. Key identifiers and server credential storage are unchanged.
- Browser-mocked tests passed at 1280/390: create/clean baseline, changed-label warning, failed-save retention, concurrent newer edit, final save clears warning. No actual credentials written/read in test artifacts; all writes mocked. JavaScript syntax passed.
- Server-only deployment verified 799 hashes and private/auth checks, preserving configuration/credentials/voices. Rollback `/var/backups/lorkhanserver-code.UkgioM`. Full API Keys and broader matrix remain active.

### Player generation edge-state verification (2026-09-08)

- Live Player editor browser probes at 1280/390 exercised the existing generation/status path with every POST mocked: successful generated draft, stale saved-profile result, dead job, and newer manual speech-style edit while generation was pending.
- All passed: successful output stays unsaved with the Save Player Settings instruction; stale/dead results retain the original field; concurrent manual text is retained with explicit feedback; the generate button resets after each case and permits another attempt. No actual generation jobs, provider calls or profile saves occurred.
- Evidence: temporary `player-generation-states.cjs`. These cases required no product changes. Pending-job resumption/timeouts, navigation behavior and the wider Player/full matrix remain separate checks; this does not claim whole-page completion.
- Documentation only; deployed product code remains `2f7da2d`.

### Shared connector-test recovery and overlay checkpoint (2026-09-08)

- Core Profiles and Global Settings now bound plan loading to 15 seconds and connector result waits to 135 seconds. A failed plan exposes Reload plan. Connector timeout feedback explicitly says the server may still finish and the result is unknown; closing/stopping still leaves already-sent requests to finish and prevents queued tests from starting. Response-body aborts propagate rather than being swallowed as malformed JSON.
- Browser testing exposed a separate Core Profiles defect: the fixed navbar (z-index 1030) intercepted clicks on the dialog's Close button (overlay 200). Raised this overlay to 1300, matching the Global Settings modal layer. No provider routing, settings or data changes.
- Actual deployed pages tested at 1280/390 with provider endpoints mocked and timeout signals manually triggered: stalled plan/reload, two pending tests, Stop prevents third test, timeout feedback, enabled retry control, Close and restored opener focus. Narrow screenshot inspected. This proves the listed interactive states, not complete counterpart presentation parity or real provider timing. Reference Core Profiles also stops queued work on close; its overall dialog structure remains a separate comparison.
- JavaScript syntax and 633 server checks passed. Local server-only deploy verified all 799 runtime hashes, no extra/legacy files, private/auth gates, and preserved configuration/credentials/voice contents. Rollback: `/var/backups/lorkhanserver-code.HwQNf0`. No game activity or live provider calls. GitHub server workflow remains manually disabled. Full page matrix and active goal remain incomplete.

### Events Record control counterpart checkpoint (2026-09-08)

- Replaced the platform emoji trash icon in initial PHP rows and live/paginated JavaScript rows with the Bootstrap Icons 1.10.5 trash SVG used by the reference's icon family. The local CSS mask inherits the action color without an external font/CDN dependency. Upstream MIT notice retained in THIRD_PARTY_NOTICES.md.
- Matched Record action gap (4px), border and background to the rendered reference. Preserved the native button, scoped delete handler and confirmation; added a record-specific accessible name and hid the decorative icon from assistive technology.
- Actual deployed/reference controls normalized to the same six-digit record number at 1280/390: height 29.1875px, reference width 77.53125px, native width 77.515625px. The 0.015625px SVG/font rounding difference is documented; no artificial pixel offset applied. Paired control screenshots inspected. Font, gap, border and background match. This closes the previous Record icon discrepancy, not all Events/full-page acceptance.
- Empty-to-live rebuild checks passed at both widths, including escaped event text, five-second polling, icon presence, delegated icon click confirmation and cancel with zero write requests. Provider/game state untouched. PHP/JavaScript syntax and 633 checks passed.
- Server-only deployment verified 800 hashes, no extra/legacy files, private/auth gates and preserved credentials/configuration/voice contents. Rollback `/var/backups/lorkhanserver-code.othxYZ`. Full counterpart matrix remains active.

### Quickstart MiniMe Service implementation checkpoint (2026-09-08)

- Added the missing MiniMe Service section between OpenRouter and speech services using the reference card/status hierarchy. Automatic page-load check shows reachability, HTTP code and measured latency; reload repeats it. Saved endpoint is used when configured, otherwise local port 8082. An `/embed` suffix is removed for the reachability GET. This does not enable semantic recall, generate embeddings or change routing/settings.
- Native endpoint uses authenticated POST + CSRF, strict installation-only request keys, the existing browser diagnostic rate budget, outbound URL/DNS policy, no redirects/credentials, four-second deadline and 64KiB discarded-body bound. No arbitrary URL is accepted from the browser, and no response body/provider error details are returned. Like the reference, HTTP 200–499 establishes reachability only, not embedding readiness.
- Extended the existing isolated HTTP suite: missing CSRF rejected, submitted URL rejected, saved mock endpoint 200/503 reported accurately, zero embedding requests. Initial expectation incorrectly assumed 403 instead of native 401; corrected. A subsequent run exhausted the shared preview budget because new tests reused the long-lived voice-test session; the MiniMe checks now use a separate test browser session. Final full HTTP suite passed (`minime-quickstart-http-3.txt`), without raising or bypassing production rate limits.
- Browser-mocked automatic success, service failure, network failure and page reload passed on deployed Quickstart at 1280/390. Native/reference service-card screenshots inspected; status colors, heading hierarchy and HTTP/latency result presentation match. Native explanatory copy reflects its saved-endpoint behavior. No real provider test, embedding or game activity was used for acceptance.
- PHP/JavaScript syntax and 633 server checks passed. Server-only deploy verified all 800 runtime hashes and private/auth gates; configuration, credentials and voices preserved. Rollback `/var/backups/lorkhanserver-code.4LI16x`.
- MiniMe Service is now implemented; the earlier checkpoint listing it as absent is superseded. Setup/Local LLM, Player2 and remaining full Quickstart acceptance are still open. Full goal remains active.

### Quickstart Setup dependency trace (2026-09-08)

The next work is not a card-only port. Current source establishes these required integration changes:

1. Reference `lib/core/local_llm_setup.php::herikaLocalLlmRouteConnector` assigns all four dialogue slots on the default NPC and Narrator Core Profiles, falling back to the first profile only when neither default exists. Native `ManagementRouter::saveQuickstart` updates just the selected Core Profile. Preserve ordinary selected-profile saves, but implement the explicit Setup operation with reference default-profile scope, revision fences and one transaction; do not silently equate the two operations.
2. Reference background scope also assigns diary/formatter on those profiles and global Player, Summary, Mediumterm, Sceneclassifier, Profiles, Director, Relationship and Oghma routes. Map every supported native consumer before exposing that scope. Merely selecting the same four dialogue models does not implement it.
3. Reference Local LLM accepts localhost and literal RFC1918/ULA addresses, not arbitrary public hosts. Native `LlmConnector::validate` permits HTTP only on IPv4 loopback/localhost; `OutboundUrlPolicy` rejects private network destinations. Its offered Windows-host/WSL addresses would therefore fail. Implement a distinct explicit local-connector transport policy with literal private/loopback address checks, no credentials in URLs, no redirects/proxy resolution, metadata/link-local rejection and bounded requests. Keep existing public connector policy unchanged. IPv6 loopback needs consistent handling as well.
4. Managed Local LLM connector identity and server type/scope metadata must survive reloads. Store optional API keys only through the private credential store, never connector JSON or returned HTML; preserve blank-key semantics and avoid partial key/route updates on revision failure. Support reference model, timeout (5–120s), streaming switch and unsaved-draft Test connection.
5. Native service identity `player2` currently reaches the generic OpenAI-compatible transport. No reference-style Player2 force switch was found in `lib`, `connector` or `ui`. Treat global Player2 routing/override/restoration as pending; a service label alone is not that feature.

Implementation order: explicit local transport and managed connector persistence; transactional default-profile/global task mapping; Setup form and draft connection test; Player2 force behavior and recap; matched default/local/Player2/error visual states and isolated save/reload/provider tests. The reference form's host-IP buttons must use runtime-derived addresses, not hardcoded machine values. No inert fields, relaxed global URL guard, or partial-scope success claim.

Read-only trace; no product/runtime changes this checkpoint. Latest deployed product remains `73472de`. Full goal remains active.

### Explicit Local LLM transport prerequisite (2026-09-08)

- Added saved `service: local` identity for OpenAI-compatible Local LLM connectors. Only this explicit identity selects local network policy; normal Custom/public services retain their existing guards. Local policy accepts localhost and literal loopback/RFC1918/ULA IPs, including IPv6 loopback. It rejects public hosts, arbitrary DNS names, link-local/IPv4-mapped destinations and known metadata addresses. URLs cannot carry credentials, fragments or queries. TLS verification/no redirects remain; local traffic bypasses proxy resolution and localhost is pinned.
- Wired the policy through dialogue, profile generation and Oghma topic adapters. Existing Custom editor presentation is retained, with local identity preserved on load, endpoint edits and Custom clicks. Explicit switches to public services clear that identity. No new icon or inert Quickstart field was added.
- 671 server checks passed, covering all three adapter constructors, allowed/rejected address classes and unchanged legacy/public restrictions. Existing isolated HTTP suite passed (`local-llm-transport-http-3.txt`): saved local connector reopened/exported with correct identity, tested successfully against a disposable provider on the WSL private IPv4 address, then removed. No live model/service or game calls.
- First HTTP run stopped at the already-observed intermittent diary-audio assertion before local tests. Added status/cache/length/four-byte diagnostic context to existing assertions; a temporary indentation error in that test edit was corrected before the passing run. No claim that the diary issue is fixed. Browser-only editor fixtures at 1280/390 passed identity-preservation/service-switch checks with all writes blocked; narrow existing Custom presentation inspected.
- Server-only deploy verified 800 runtime hashes, private/auth gates and preserved configuration/credentials/voices. A notice-file edit during the first deployment was caught by hash verification; final redeploy is clean. Final rollback `/var/backups/lorkhanserver-code.9nPr9j`.
- This completes the explicit local transport prerequisite. Quickstart managed connector persistence, default NPC/Narrator and global task route application, Setup form/draft test, and Player2 remain pending. No Quickstart completion claim; full goal stays active.

### Managed Quickstart Local LLM persistence prerequisite (2026-09-08)

- Added setup normalization for the reference LM Studio/Ollama/llama.cpp/KoboldCPP/Other choices, dialogue/background scope values, model/endpoint, 5–120 second timeout and streaming. Defaults retain reference 512-token/0.7-temperature JSON connector settings. Inputs accept private credential references only; raw API-key fields are rejected.
- Migration 096 adds one installation-owned connector association with a composite installation/connector foreign key. Ownership is not inferred from a display name, so similarly named user connectors are not adopted. Saved server type/scope live beside the association; provider content remains in existing immutable connector revisions. Populated downgrade refuses to discard assignments.
- Repository upsert serializes creation per installation, retains the connector ID across edits, checks expected revision, preserves an omitted credential reference, and refuses to overwrite a managed connector repurposed as a public service. The operation participates in an outer transaction so later routing failure can roll back connector creation/revision and association together.
- 678 checks passed. Existing integration suite verifies create/update identity, options/name/scope persistence, stale-revision rejection, outer rollback and populated downgrade protection. Complete vertical slice, schema inventory check (173 relations), database backup/restore, migration down/up and durable-job tests passed (`managed-local-integration-final.txt`). No new test file or live provider/game activity.
- Server-only deployment verified 803 runtime hashes, no extra/legacy paths, private/auth gates and preserved configuration/credentials/voice contents. Rollback `/var/backups/lorkhanserver-code.EKWqns`.
- This is the managed persistence prerequisite, not the Setup form or applied routing. Default NPC/Narrator/global task routing, credential entry handling, draft connection test and visible Setup/Player2 controls remain open. No visible-page completion claim; full goal remains active.

## Quickstart Local LLM routing transaction — 2026-09-08 (implementation in progress)

The repository now has a revision-fenced, atomic apply operation for its managed Local LLM connector. Dialogue scope targets the four model slots of the default NPC and Narrator Core Profiles; unrelated Core Profiles remain unchanged. The background scope additionally routes the supported diary/player-autochat Core fields, the three existing system routing fields, and the summary policy without enabling summary generation. Global Settings uses the established legacy conversion path.

Existing integration coverage checks target selection, all four dialogue slots, unchanged unrelated Core revisions, no background-policy creation for dialogue scope, stale-form rejection without connector revision changes, background routes, disabled summary preservation, and outer transaction rollback. This is backend groundwork only: the Quickstart Setup controls and management endpoint are not wired yet. It does not establish all reference background-task semantics or visual parity. Keep this row incomplete until that work and populated/empty/interactive browser comparisons are complete.

### Local LLM management API (in progress)

Added authenticated GET/POST `manage/api/v1/quickstart-local-llm`. GET returns only the routing revision snapshot; POST requires browser CSRF, the exact snapshot fingerprint, installation ID and strictly validated setup fields. The response contains the managed connector ID and refreshed routing metadata, not provider configuration or credentials. No inference request is sent by saving.

The reference Setup cards also apply built-in Default/Local LLM behavior presets. Native `CoreProfilePreset` currently implements custom named presets only. Wiring a radio card to connector routing alone would therefore be incomplete parity: the native built-in behavior presets, secret input handling and draft connection test still need implementation alongside the copied presentation. Do not label the Quickstart page complete from this endpoint.

### Built-in profile field mapping — 2026-09-08 (not yet exposed)

`CoreProfilePreset::applyBuiltIn` now applies the shared Default/Local LLM/Follower/Passive values for dialogue/diary/evolution history, max words, Rechat depth/probability/actions, middle-term memory, diary timer/wait/latest-context flags, dynamic evolution and randomizer. It preserves connector bindings, prompt text, evolution field selection and omitted settings. Nine focused checks extend the existing test suite (687 checks passed).

This method is intentionally not wired to Quickstart or the preset selector yet. Full preset behavior remains incomplete: reference BORED_EVENT is not native boredom delay; per-profile RPG_COMMENTS_CHANCE and combat bark cooldown ownership require proper mapping; MATERIALIZE_DIARY is not equivalent to include_in_context; and global connector availability, prompt context selection and global preset defaults must be applied together. Trace native diary master enable and Rechat gates before claiming Follower automation works. Do not substitute a routing-only or partial preset for the reference Setup cards.

Preset scheduler trace correction: native `RechatCoordinator` rejects responders while `behavior.rechat` is false, and `enqueueAutomaticDiaries` requires both `diary.enabled` and `automatic_enabled`. Built-ins now enable Rechat alongside their nonzero budgets; Follower opens the diary master gate. Other presets preserve the manual-generation gate while disabling timer/wait automation. Existing checks now cover these transitions (691 passed). This resolves the gate issue noted above, not the remaining preset ownership and page presentation gaps.

## Diary reader/editor visual comparison — 2026-09-08

Compared current PHP-rendered native diary fixtures with pinned HerikaServer `diarylog.php` at 1280x900 and 390x900, using identical synthetic text and blocking every non-GET request. Both reader and editor already matched dialog bounds, font, content height and padding. Screenshot inspection found native `pre-wrap` inserted a blank paragraph where the reference's normal whitespace collapsed source newlines. Scoped `white-space: normal` to the paper diary reader only; editor content, stored bytes, speech input and HTML escaping remain unchanged.

After correction, narrow reader line wrapping matches the reference visually (gold branding retained). Reader bounds are 1254.390625/382.1875 x 680px; editor bounds are 800/351 x 643.34375px; both start at y=80. Reader font is 18px/27px handwritten with 40px padding. Editor textarea remains 400px tall with 13.44px/19.488px font and 7px/10px padding. Native Escape closes both dialogs; no write/audio request occurred.

Evidence: `C:/Users/reece/AppData/Local/Temp/diary-modal-current.cjs` (before), `diary-modal-fixed.cjs` (source CSS override), and matching `diary-modal-{current,fixed}-{ref,native}-{reader,editor}-{1280,390}.png` screenshots. Inspected reference/native narrow reader and editor images plus corrected narrow reader. This checkpoint covers populated plain-text reader/editor presentation, not audio-provider acceptance, all log empty states, or full page parity.

## Books empty-state presentation — 2026-09-08

Compared the actual reference Books empty branch (rendered into the live hub without database writes) with the current native PHP template rendered with zero rows, at 1280x900 and 390x900. The native empty message had removed the reference panel entirely. Restored its 8px padding, 1px #404040 border, 10px radius, #232323 background, paragraph bottom margin and narrow gutter. Accounted for the native flex wrapper rather than adding reference block margins twice. Scoped parent overflow visibility to the active empty Books tab so its narrow gutter is not clipped.

Measured panel heights now match exactly: 88.875px desktop and 109.75px narrow. Message widths match at 1216/326px; font is 14.4px/20.88px Futura CondensedLight. Panel position relative to the tab top matches at 41.875px at both widths. Inspected reference/native screenshots before and after. Existing Lorkhan navigation exclusions, captured-book wording and scoped filters remain. Populated-table selectors are unchanged.

Evidence scripts and screenshots: `C:/Users/reece/AppData/Local/Temp/books-empty-{compare,styles,fixed,ancestors}.cjs`, `books-empty-{ref,native}-{1280,390}.png`, `books-empty-fixed-{ref,native}-{1280,390}.png`. Reference fixture HTML comes from its empty branch, not a claim that its live database is empty. Other priority-page empty states remain to be checked.

## Diary and Adventure empty-date rows — 2026-09-08

Compared actual hub/iframe styling at 1280x900 and 390x900. Herika's selected empty date was `2000-01-01`; native Adventure used the same read-only date filter, while native Diaries used its current PHP template with zero fixture rows. Standalone native fixture font/width were not equivalent to the hub, so acceptance uses the actual hub shell only.

Both empty rows were 71.1875px high because generic `.log-empty` padding `25px!important` overrode the calendar table. Scoped `9px 10px!important` to calendar empty cells. Both now match reference height 39.1875px, table widths 1236/346px, centered text, #e0e0e0 color and 12.8px/19.2px Futura CondensedLight. Inspected narrow diary table images before/after; Adventure measurements match at both widths. No writes or audio requests were allowed. Populated rows and AI Responses selectors are unchanged.

Evidence: `C:/Users/reece/AppData/Local/Temp/diary-empty-hub.cjs`, `diary-empty-hub-fixed.cjs`, `adventure-empty-compare.cjs`, `adventure-empty-compare-fixed.cjs`; screenshots `diary-empty-{hub,fixed}-{ref,native}-{1280,390}.png` and `adventure-empty-{ref,native}-{1280,390}.png` (the fixed script reused these filenames; they contain the final captures, not preserved before/after pairs). Events/AI Responses empty-state refresh and the wider page matrix remain open.

## Events and AI Responses empty-state refresh — 2026-09-08

AI Responses' real empty query branch matches the reference empty-page branch: identical 104.375px table-container height, 38.6875px row height, 12.8px/19.2px Futura font and 9px/10px cell padding. Inspected narrow screenshots; no CSS change was needed. Reference used an out-of-range read-only page while native used an unmatched search, so pagination totals intentionally differ in this evidence.

Events correctly hides its table for an empty result, matching the reference `print_array_as_table` early return. An intercepted empty API result followed by one synthetic live event restores the table, preserves literal markup as text, uses the 5000ms refresh interval and can stop live mode, with zero mutation requests at 1280/390px. Screenshot inspection and source trace found an extra native bottom pager/count. The pinned reference Events page renders only the top pager; the older native comment claiming otherwise was incorrect. Removed the duplicate footer from the PHP structure, preserving the top pager, filtering, live updates and scoped playthrough details. Added a single-pager assertion to the existing management HTTP test.

Evidence: `C:/Users/reece/AppData/Local/Temp/response-empty-refresh.cjs`, `response-empty-refresh-{ref,native}-{1280,390}.png`, `events-empty-live-refresh.cjs`, `events-empty-reference-refresh.cjs`, `events-empty-reference-refresh-{1280,390}.png`, and `events-empty-{1280,390}.png`. The full matrix and remaining page controls are still incomplete; this is not a whole-goal completion claim.

### API Keys standalone and actual hub refresh (2026-09-08)

- Compared current deployed `8554e60` against the pinned Herika reference at
  1280x1000 and 390x1000, standalone and through the actual Configuration hub.
  Preset card widths match: 576 / 318px standalone, 564.609375 / 294px embedded.
  Content grid gap is 30px and neither page overflows horizontally.
- Inspected desktop embedded preset screenshots, narrow embedded lower-page and
  matched new custom draft screenshots, and standalone desktop matched drafts.
  Custom draft heights match: 226.625px except narrow embedded 231.625px.
  Font is Futura CondensedLight 15px / 22.5px on both products.
- Reference has 12 presets; Lorkhan has 11. The omitted Replicate card is explicitly
  the reference Soulgaze Gallery Processor, excluded from this release. Provider
  usage descriptions omit excluded ITT. Environment locks, replacement-only secret
  inputs and independent extra credential slots preserve native credential safety.
- Refreshed `apikey-label-draft-proof.cjs`: both widths pass clean save baseline,
  label-only dirty warning, failed-save retention, newer edits during a pending save
  and final saved baseline. All writes mocked; no real provider authentication run.
- Evidence: temporary `apikey-viewport-{ref,native}-{1280,390}-{false,true}.png`,
  `apikey-bottom-*` and `apikey-custom-draft-*`; false is standalone, true actual hub.
  Full-height captures were not accepted from truncated tool output. Bounded
  captures were used instead. Stored input values were cleared without events.
- No product change was needed for the compared card structures. Standalone
  scrolled captures show different top-header persistence; that behavior remains
  for source comparison rather than being silently accepted as branding.
  Broader parity goal stays active. GitHub server workflow remains disabled.

### API Keys content-only child shell correction (2026-09-08)

Source comparison resolved the standalone scroll discrepancy: Herika api_badge.php
never includes navbar.php. Lorkhan did, adding a fixed logo/navigation shell and
82px vertical offset. Removed this page's navbar include and zeroed its body top
padding for both standalone and embedded entry. Configuration hub navigation is
unchanged. This is a structural correction, not a branding exception.

The existing HTTP assertion now requires zero current navigation links and no
navbar element on the child page. The first run failed its old one-current-link
expectation; the assertion was corrected to the intended reference structure.
PHP lint, 691 server checks and the full management HTTP suite passed
(`apikey-shell-http-2.txt`). No credentials, profile data or provider routing changed.

Deployed `074ad9c` to `/var/www/html/LorkhanServer`; rollback
`/var/backups/lorkhanserver-code.eVzoQo`. All 803 runtime hashes match with no extras
or legacy paths. Health/authentication/private-route checks pass, and existing
configuration, credentials and voice file content hashes were preserved.

Post-deploy `apikey-shell-deployed.cjs` compared both widths and actual hub/direct
entry: header Y is 10px within each page/frame, with zero child navbar elements,
matching Herika. Card widths and grid gap remain unchanged. Inspected the native
desktop direct screenshot and both 390px direct screenshots: the added 82px band
and fixed logo are gone, and the header/preset origin now matches. Actual hub
navigation remains outside its child frame. Evidence: temporary
`apikey-shell-deployed-{ref,native}-{1280,390}-{false,true}.png`. All non-GET traffic
was blocked and credential inputs cleared before capture. No game/provider tests.
The full page-by-page goal remains active.

### AI Responses reader state refresh (2026-09-08)

Compared current deployed presentation against pinned Herika in populated
system/user/assistant and empty-prompt states at 1280x1000 and 390x1000.
`response-reader-multi.cjs` and `response-reader-empty.cjs` use browser-only matched
text; the empty markup was checked against both actual PHP branches before use.
These are presentation fixtures, not a claim that the database contains empty logs.

- Multirole dialog width matches 1152/351px; height 594.46875/900px. First message
  width matches 1070/269px, height 96.171875px. Message typography is Futura
  CondensedLight 13px / 23.4px. Connector/model metadata wraps within the narrow reader.
- Empty reader height matches 187.390625px at both widths, with a 43.390625px
  empty payload panel and 10px padding. Native outer body inherits Futura while
  reference outer body declares Consolas, but both rendered payload elements use
  Futura; no visible text difference is being excused by that inherited declaration.
- Inspected both empty narrow screenshots, both populated desktop screenshots,
  native multirole narrow and actual-template long-token narrow captures.
- Refreshed `response-reader-copy-proof.cjs`: exact literal text copied, success
  state resets after close/reopen, clipboard refusal displays the reference error,
  Escape closes and restores opener focus. Clipboard is mocked; no OS clipboard
  or saved content modified.
- Generated empty/populated HTML using the current `roleplay_logs.php` PHP renderer
  in `response-reader-render-fixture.php`, then loaded it with intercepted GETs.
  `response-reader-render-proof.cjs` verifies 0/3 actual message elements, literal
  script text without execution, no dialog horizontal overflow and Escape focus
  restoration at both widths. No product source was replaced by fixture output.

No product change was needed for these compared reader states. Existing runtime
stays `074ad9c`; no redeploy required for this evidence-only update. All browser
non-GET requests blocked. No game, real provider or production database writes.
The full matrix remains active; cleanup/export edge states and other pages remain.

### AI Responses export and cleanup completion pass (2026-09-08)

Source audit found that Response Log export still used the generic narrative CSV
columns, omitting prompt and Oghma data. Changed only this tab to the reference six
columns: rowid, time_utc, ai_response, oghma_topic, prompt, http_request. UTC display
format matches the reference. Uses the same scoped Oghma lookup and allowlisted
frozen messages/model/connector label/driver as the prompt reader, never raw
provider configuration. Retains selected playthrough/search scope, all matching
rows beyond the current page, streaming output and spreadsheet formula protection.
Other tabs retain their existing export formats. Native http_request remains the
recorded input kind/text, as shown in the table, not a fabricated Skyrim URL.

Existing management HTTP coverage extended for CSV headers, attachment response,
allowlisted prompt JSON structure and a header-only no-match export. PHP lint and
691 server checks pass; full HTTP/deployment evidence follows below.

`response-clean-proof.cjs` checked desktop/narrow cancellation, HTTP failure,
malformed response and successful reload. All cleanup POSTs were intercepted:
cancel made zero requests; failures restored the control; payload retained scope,
confirmation and CSRF. Narrow error screenshot inspected. Production logs were not
deleted. Herika's destructive GET is not copied; native confirmed authenticated
POST and preservation of source/history/active responses remain required.

Export deployment evidence: full HTTP suite passed (`response-export-http.txt`),
then `2451789` was pushed/deployed. The real populated-download probe caught a gap
not covered by that suite: default PHP backslash escaping made prompt JSON cells
invalid after CSV parsing. Corrected response-only fputcsv to standard quote
doubling with an empty escape character in `6d974fb`. Other tab writers unchanged.
PHP lint and 691 checks passed again after that correction. The full HTTP pass
predates the quoting follow-up; do not attribute a second full-suite pass to it.

Deployed final `6d974fb`, rollback `/var/backups/lorkhanserver-code.b67ig0`.
`response-export-deployed.py` now parses all 49 populated rows and a header-only
no-match result, verifies the six columns and allowlisted prompt/connector/message
keys, and prints counts only. Private exported text was not written to artifacts
or tool output. All 803 runtime hashes match; no extras/legacy paths; private403,
unauthenticated401 and health checks pass. Existing config, credentials and voice
content hashes preserved. No actual cleanup was performed. Large multi-page CSV
coverage remains separate; the source query intentionally omits pagination for
export but a 49-row runtime probe does not prove a larger fixture. Goal active.

### Multi-page response CSV regression and Books reader refresh (2026-09-08)

Extended the existing management HTTP fixture rather than creating a new harness.
A temporary ended session/turn in its isolated database owns 61 projected log rows
and a frozen prompt containing quotes, commas, newlines and literal backslashes.
The snapshot also includes a fake non-message credential marker, while the raw
log fields have separate forbidden export markers. Fixture rows are removed after
checks; the harness drops the isolated database on exit. No live records touched.

The suite proves 50/11 page split, export from page two returns all 61 selected rows
in order, prompt JSON round-trips the exact text, only the allowed message structure
is exported, native input text maps to http_request, and no-match CSV has headers
only. Full suite passed: `response-export-multipage-http.txt`. This also supplies
the previously missing full-suite pass after the CSV quoting fix. The older
49-row-only limitation is superseded by this fixture evidence.

Regenerated `books-current-fixture.html` from the current PHP template and reran
`books-reader-refresh.cjs`. At 1280/390 it verifies mocked clipboard success/refusal,
status reset, opener focus, body scroll restoration, long-content scrolling bounded
to 90vh and Escape. Inspected the narrow copy-failure-state screenshot: literal
markup stays visible text with the expected monospace reader. This refresh is not
a new full reference comparison; earlier counterpart evidence remains separately
recorded. No provider or actual clipboard writes.

Only tests and this matrix changed; deployed product remains `6d974fb`, so no
runtime redeploy is necessary. Full all-page parity goal remains active.

### Quickstart unsaved Local LLM Test implementation (2026-09-08)

Pinned Quickstart source lines 666-791 define Setup as a sibling of OpenRouter,
with Default/Local LLM profile cards, server/model/URL, Windows/WSL helpers, dialogue
scope cards, optional credential, timeout, streaming switch, Test and preview.
Save applies the built-in settings preset before Local LLM routing. The native
saveQuickstart currently updates only selected Core routes, service selection and
player name. The existing routing API cannot be presented as that complete save.

Implemented the missing unsaved-test backend at authenticated POST
`/manage/api/v1/quickstart-local-llm-test`. Strict body accepts installation_id and
setup only. Uses existing QuickstartLocalLlm validation and local-only URL rules,
existing shared preview rate limit, ProviderFactory and diagnoseProvider. The slot
is transient: no connector, snapshot, profile or route is persisted. Sends the
existing fixed diagnostic greeting, not game context. Response is only a bounded
success summary or opaque failure code, never raw provider errors/configuration.
Raw api_key remains rejected; existing credential references are supported.

Existing HTTP suite extended with a separate preview session and disposable LAN
provider. Checks missing CSRF/raw-key rejection, submitted draft model actually
used, no inherited Authorization header, invalid output gives opaque 502, and
routing fingerprint remains identical. PHP lint and 691 checks pass. Full HTTP suite passed (`quickstart-draft-test-http.txt`).
Deployed `7334deb`; rollback `/var/backups/lorkhanserver-code.gOrz0L`. All 803
runtime hashes match with no extra/legacy paths, health/auth/private-route checks
pass, and existing config/credential/voice content hashes were preserved. No live
model call or game test was performed. This backend checkpoint is not visual proof.

Visible Setup remains open, not accepted by this backend work. Remaining dependencies:
complete shared built-in Global/Core effects and apply them in the Quickstart save
transaction; private optional-key handling; actual host/WSL address discovery;
then copy the reference panel and wire its draft validation/test/save/recap states.
Player2 and service provisioning remain separately open. Do not claim routing or
this endpoint alone establishes the visible page's parity.

### TTS Studio embedded shell and upload controls (2026-09-08)

Read-only Configuration hub captures covered STT, TTS and TTS Studio at 1280/390.
Secret-bearing input/textarea values were cleared before capture; no non-GET calls
occurred. Compared TTS Studio on Inworld rather than assuming both installations
selected the same provider. Initial manual reference-frame navigation omitted
embed=1 and added 60px padding; those `voice-hub-inworld-*` initial captures do not
establish matching page origins. Corrected embedded captures follow after deploy.

Aligned the shared upload field label and Submit control with the reference;
OmniVoice keeps its distinct Import action. Added PocketTTS's Standard API/audio.cpp
mode suffix using existing active/saved connector upload-capability detection.
Missing-provider badge now says Not configured. The informational header note uses
Lorkhan gold rather than copied orange. Native configured/active status does not
claim provider reachability. Actual upload size/format rules, optional voice naming,
private storage and file preservation remain unchanged. No uploads were submitted.

PHP lint and 691 server checks passed. The larger provider-specific editor and
Quickstart Setup/preset gaps remain open; these label changes do not close them.

### TTS Studio corrected embedded evidence and file chooser (2026-09-08)

Inspected all four corrected `voice-hub-inworld-deployed-{ref,native}-{1280,390}`
viewport images. Both use embed=1. Header and upload panel origins match on desktop;
mobile header layout matches. Provider status and selected PocketTTS mode reflect
actual local configuration, not forced reference values. Additional excluded hub
entries and differing status-label widths affect wrapping. Optional voice naming
and accurate native storage requirements remain visible product differences.

Deployed a93de6c verified: 803 runtime files, no hash mismatches, extra files or
legacy paths; private paths deny access on all three checked ports, health and
authenticated NPC route probes pass. Rollback: /var/backups/lorkhanserver-code.QoedrZ.

The comparison exposed a remaining file chooser typography mismatch. Matched the
reference 15px field text, 14px/21px button text, 8px/12px button padding and #555
border. Read-only browser CSS interception with visible Inworld controls measures
55px field height and 37px button height on both products; inspected bounded images
`voice-upload-fixed-{ref,native}.png`. The first screenshot probe selected a hidden
reference provider field and timed out; corrected to :visible before acceptance.
691 server checks and diff whitespace checks pass. No uploads or provider calls.
This closes only the measured chooser difference, not the whole Voice Management
page or the remaining global parity goal.

Deployed chooser checkpoint 997ef0c; rollback /var/backups/lorkhanserver-code.MRkred.
Read-only final browser probe without CSS interception reproduces matching field
and button typography/dimensions; inspected voice-upload-final-native.png. All 803
runtime hashes match, with no extra/legacy paths; health/auth/private-route probes
pass. Existing configuration, credentials and voice contents preserved. No game
launch, real upload or paid provider test. GitHub server workflow stays disabled.

### TTS connector editor action-row parity (2026-09-08)

Compared actual Configuration hub TTS lists and the selected Inworld editor at
1280/390. The reference must finish loading #label and native #tts_name before
capture; initial placeholder/incorrect-locator captures were discarded. All
non-GET traffic was blocked and secret-bearing input values cleared before images.
The differing list lengths are saved data, not missing UI. Matching editor origins
require explicit scrollIntoView block:start on narrow screens.

Found and corrected action-row styling differences in herika-tts.css. The sidebar
New/Import row now matches reference margins (6px 0 10px 4px), typography, padding
and radii. Native Export is a safe download link but now has the reference button's
2px margin and 6px radius. Native Clone/Delete remain protected POST controls,
with the reference link geometry: no outer margin and 8px radius. This removes the
4px excess toolbar height that displaced every following editor field.

Read-only CSS interception and visible computed styles confirm 40px sidebar and
editor rows on both products. Inspected paired desktop and narrow screenshots
`tts-editor-fixed-{ref,native}-{1280,390}.png`: action rows and following name,
service, API badge and fallback hierarchy align. Narrow action wrapping matches.
Deletion remains disabled for the active/in-use connector; it is not relaxed to
imitate the unsafe reference availability. Workspace help retains the actual
native optional-workspace semantics. 691 existing server checks and diff checks
pass. No real save, clone, delete, import, export or provider synthesis performed.
Provider-specific lower editor states remain separately open in the matrix.

Deployed a520f3a; rollback /var/backups/lorkhanserver-code.eSVA1m. All 803
runtime files match source, with no extras/legacy paths. Health/private/auth route
probes pass; configuration, credential and voice contents preserved. Final browser
metrics without CSS interception reproduce matching sidebar/editor row sizes and
all seven control font/padding/margin/radius measurements. Native safety and gold
colours remain. No game launched; full provider/page goal remains active.

### Books game-time ordering and full filtered pagination (2026-09-08)

Current pinned Herika events-memories.php:2126 orders Books by gamets DESC,
rowid DESC. Native Books instead used created_at DESC and the textual narrative ID,
so game ordering and same-time numeric ties diverged. The existing world projection
stores OpenMW world.game_time in books.gamets on both insert and update. Added that
column to the internal reader projection and matched the reference ordering only
for Books. Other readers, recorded data, scope ownership and calendar rendering
are unchanged. Existing scoped filters and native all-matching-row export share
the same ordering before pagination.

Extended existing management HTTP tests with 151 disposable book rows in its
isolated database: wall-clock order reverses game order, pairs share game times,
and numeric IDs cross digit boundaries. Checks 150/1 page split, numeric descending
tie-break, full 151-row export from page two, one-result search and empty search.
No live book records are created or deleted. PHP lint and 691 server checks pass.
The first full HTTP run stopped before these tests at the known diary mock-audio
502; it is not counted as a pass. A fresh completed-run retry is recorded below.

Regenerated books-current-fixture.html using the current PHP template and reran
books-reader-refresh.cjs at 1280/390. Mocked copy success/refusal, status reset,
focus return, body scroll restoration, long-content scrolling and Escape passed.
Inspected the narrow copy-failure screenshot with literal markup safely shown as
text. This refresh supplements earlier paired reader comparisons; it is not new
proof of live observed-book capture. No game or provider calls were made.

Full management HTTP suite passed on the second run (books-order-http-2.txt),
including the new 151-book ordering/filter/export checks. The prior diary mock
failure remains documented, not claimed fixed.


Deployed 3597af6; rollback /var/backups/lorkhanserver-code.Yn3Fhc. All 803 runtime
files match source with no extra/legacy paths. Health/auth/private-route checks
pass, and configuration/credential/voice contents remain preserved. Post-deploy
Books reader checks pass at both widths. Ordering proof comes from the isolated
HTTP dataset, not fabricated live game records. Full page parity remains active.

### Adventure Log complete-day table parity (2026-09-08)

Pinned Herika adventurelog.php fetches the whole selected day in localts ASC order
without LIMIT/OFFSET and renders one contiguous table. Native imposed a generic
20-row reader page, restarting speaker bands and location headings across pages.
That was a divergence, not an OpenMW exception. Removed the Adventure-only row
limit/offset and generic reader toolbar/pager; stale reader_page links normalize
to page one and render the same complete selected day. Other tabs keep their
existing limits. Unselected dates still show no events; installation/playthrough,
calendar and text filters remain enforced before rendering. Downloads are unchanged.
Very busy selected days now produce larger HTML, matching the reference behavior.

Updated existing HTTP fixture assertions to expect all 24 same-day events, two real
location headers and one continuous 22-row Caius band. Former page-two requests
must produce the identical table with no generic toolbar or pager. Existing date,
empty, scope-isolation, selected-day/latest-day/all-log CSV checks still pass.
PHP lint, 691 checks and the full management HTTP suite pass (adventure-day-http.txt).

Rendered the current PHP reader with a 24-row isolated fixture; compared against
24 equivalent text rows using the reference's existing table/row structure in its
actual hub. No production records changed. Desktop top and narrow bottom images
were inspected. Initial narrow viewport clipped the final row; separate bounded
adventure-last-{ref,native}-390.png captures show the complete 24th row with matching
cell widths, wrapping and height. The first two speaker rows, location transition,
continuous remaining rows and absence of native paging are separately covered.
Hub origins differ with excluded reference tabs, not fabricated native entries.
No game, provider or data writes during browser comparison.

Deployed 83910a9; rollback /var/backups/lorkhanserver-code.63m95v. All 803 runtime
hashes match, with no extra/legacy paths; health/private/auth probes pass. Existing
configuration, credential and voice contents preserved. Read-only deployed browser
refresh selected a populated date, verified current/all CSV include the displayed
event, switched calendar modes and checked an empty day. No game or data writes.
Full page parity goal remains active; this does not close unrelated Diary limits.

### Diaries complete calendar/author sets and ordering (2026-09-08)

Reference diarylog.php:180 shows all selected-author entries newest-first; its
calendar query at 888 shows all selected-day entries oldest-first. Neither query
has a 20-row limit. Native had generic pagination and newest-first order for both.
Removed the Diary-only limit/pager and matched calendar versus author ordering.
Existing exact author identities, playthrough ownership, filters, revisions and
soft deletion remain. Diary downloads use the same view order. Other paged readers
are unchanged; large author histories now render all matching entries as reference.

Moved the hidden Stop Reading control into the shared calendar audio dock rather
than deleting it with the generic toolbar. The shared script requires that node
for event registration, stopping, modal relocation and scope changes. This also
repairs the missing hidden control after the prior Adventure toolbar removal.
No JavaScript behavior was weakened or replaced with null-tolerant no-ops.

Existing HTTP suite extended with 24 isolated diaries: full-day ascending order,
complete-author descending order, stale page-two equivalence, no generic pager,
one hidden stop control, and both full exports. Initial assertion counted read and
edit triggers as separate entries; corrected to count content buttons. Full suite
then passed (diary-day-http-2.txt), alongside PHP lint and 691 server checks.

Regenerated current PHP fixture. Paired 1280/390 reader/editor modal captures and
computed geometry completed; both narrow pairs were inspected and match apart from
gold branding. Existing mocked delete cancel/rejection, empty/long reader scrolling,
Escape and focus-return checks pass. Focused diary-stop-dock-proof.cjs verifies
mocked provider failure restores Play, hides Stop, closes the modal and returns one
Stop control to its dock with no JS errors at both widths. Its initial stale error
text and immediate pre-close assertion were corrected to the actual message and
completed close event before acceptance. No live diary writes or provider calls.

Deployed 1ce6363; rollback /var/backups/lorkhanserver-code.GgiruP. All 803 runtime
files match source with no extra/legacy paths; health/private/auth checks pass.
Configuration, credentials and voice contents preserved. Fresh deployed Adventure
and Diaries pages each have exactly one hidden Stop control, no generic reader
toolbar/pager, and no JavaScript page errors. No game launched. Full goal active.

### AI Responses same-second ordering (2026-09-08)

Pinned Herika events-memories.php:1612 orders responses by localts DESC, rowid DESC.
Native used wall-clock descending but textual ID ascending, misordering responses
recorded in the same second. Added an explicit numeric descending tie-break only
for responselog. Events already uses matching game/TS/local/numeric ordering and
was not changed. Existing scoped prompt/Oghma retrieval, 50-row pagination and
allowlisted CSV fields remain unchanged.

Strengthened the existing 61-response HTTP export fixture: timestamp pairs now tie,
insert order is explicit, both pages check descending row order, and CSV verifies
all 61 entries in exact sequence. Existing prompt escaping/secret exclusion and
empty-export checks remain. PHP lint and 691 server checks pass. Two full HTTP
runs stopped at the known diary mock-audio 502 before these assertions. A third run
with temporary test-only exception logging completed the full suite successfully
(response-order-http-diagnostic.txt), without reproducing the diary failure.
The diagnostic line was removed; ManagementRouter has no diff and passes lint.
The intermittent diary cause is still unknown and is not claimed fixed.

Regenerated empty/populated current PHP prompt-reader fixtures. Both 1280/390 runs
confirm role counts, escaped literal markup, long-token wrapping and Escape focus
return. Inspected the populated narrow screenshot; prior paired reference evidence
remains separate. No rendering markup or styles changed in this ordering fix.
No production records, prompts, credentials, provider calls or game state changed.

Deployed b549457; rollback /var/backups/lorkhanserver-code.qlk1B8. All 803 runtime
files match source with no extras or legacy paths. Health/private/auth checks pass;
configuration, credentials and voice contents preserved. Full ordering proof is
the isolated 61-row HTTP dataset; no live game records were fabricated. Full goal
remains active, including the unresolved intermittent diary audio test failure.

### Diary audio intermittent failure root cause (2026-09-08)

The prior opaque diary_audio_failed result is now reproduced and traced, not merely
retried. A focused diagnostic repeated uncached diary requests against the existing
isolated mock provider. It failed on requests 11 and 20 in separate runs with
invalid_voice_sample at LocalVoiceResolver.php:32. Diagnostic path flags prove the
cached realpath pointed to a file that no longer existed. The earlier voice-library
HTTP fixture intentionally deletes HTTPBatch*.wav before the diary test. Different
PHP workers retain positive realpath-cache entries from before that deletion,
explaining why fresh workers passed while warm workers rejected the same voice.
The remote provider still owns the voice and no longer needs the local sample.

LocalVoiceResolver now invalidates only the candidate sample path's stat/realpath
cache before resolving it. Missing samples take the existing provider-owned voice
path; real files still undergo the unchanged directory containment, regular-file
and size checks. This covers Voice Studio sample changes across PHP workers without
weakening validation or changing voice selection, credentials or audio caching.

Added eight lines to the existing unit suite: warm the sample realpath, remove the
fixture from a child PHP process, then resolve the provider-owned voice with no
upload. It fails with the exact original exception before the fix and passes after.
692 server checks and PHP lint pass. All temporary diagnostic logging and repeated
HTTP-loop edits were removed; ManagementRouter and management_http.py have no diff.
This is backend acceptance work for diary/TTS pages, not new visual parity proof.
Live paid-provider synthesis and in-game audio remain untested.

Full management HTTP suite passed with the final code and original test flow
(diary-stale-path-fixed-http.txt), including diary generation, cache hit/invalidation,
author changes and subsequent page tests. No retry was needed after the fix.


Deployed 21904d4; rollback /var/backups/lorkhanserver-code.mevLYv. All 803 runtime
files match source, with no extras/legacy paths; health/private/auth checks pass.
Existing configuration, credentials and voice contents preserved. The reproduced
invalid_voice_sample failure now has a deterministic passing regression and full
HTTP acceptance. Historical unresolved-cause notes above are superseded by this
checkpoint, not by a claim that all live TTS errors are solved. Full goal active.

### Local TTS Studio refresh and PocketTTS mode (2026-09-08)

Reference xtts_clone.php exposes Refresh above the cache for XTTS, Chatterbox,
PocketTTS and OmniVoice. Native hid the first three inside connector settings.
Moved the existing protected discovery form submit into that primary position for
supported local connectors; retained the same connector/language/CSRF payload and
kept cloud provider controls unchanged. Narrow refresh buttons retain natural width.
PocketTTS upload and cache now identify the selected connector's actual mode, and
its audio.cpp copy no longer promises server synchronization. An unconfigured
connector reports Not configured. Existing local-only readiness remains unchanged.

Current-template isolated empty-cache fixtures and read-only reference captures
were inspected at 1280/390. Refresh submits exactly one mocked discovery request
with connector, action and CSRF while settings remain collapsed; audio.cpp has no
primary synchronization button. Computed refresh font/padding/line-height match.
The first temporary fixture omitted Bootstrap/UTF-8 and was corrected before visual
acceptance. No saved connector, local voice, provider or game state was changed.
This does not claim whole-cache parity: connector disclosure, populated row actions,
provider error/batch views and remaining service sections still require review.
PHP lint, 692 checks and full management HTTP passed (voice-refresh-http.txt).

Deployed 409452f; rollback /var/backups/lorkhanserver-code.ShZY1w. All 803 runtime
files match source with no extras/legacy paths; health/private/auth checks pass.
Configuration, credentials and voice hashes are preserved. Fresh deployed populated
PocketTTS cache screenshots inspected at 1280/390 show audio.cpp and the retained
local sample grid. Standard API refresh acceptance uses an isolated current-template
fixture and mock POST, not a changed live connector. Final PHP lint and 692 checks
pass; full HTTP preceded the final batch-description-only wording change.
No game launched or controlled. Full parity goal remains active.

### Events deletion controls and remaining-state review (2026-09-08)

Refreshed Events identical-data control/table metrics at 1280/390; all measured
font, padding and column metrics match. Inspected paired narrow identical-data
screens and desktop populated-row screens preserving the actual headers/actions.
Different record-ID lengths naturally change the Record column width. Excluded
navigation, Morrowind Journal, gold branding and native playthrough scope remain.
Reference Events has no inline event editor to copy; its Record action is deletion.

Aligned the selected-delete icon and count-bearing confirmation with Herika, and
restored its full AI-context/irreversibility warning on Delete All. Native scoped,
CSRF-protected DELETE remains; no destructive reference GET was copied. Empty
selection is ignored. Existing event storage/deletion semantics are unchanged.

Fresh isolated browser checks (events-delete-acceptance.cjs) at both widths verify
selected cancel/failure/success, retained checked rows on failure, single-row
removal, Delete All cancel/wrong/exact confirmation, latest-five payload and empty
refresh. Every delete was mocked; CSRF/scope were checked without logging values.
No live events changed. Hide/unhide, failed-filter retry, empty-to-live reconstruction,
escaped text and stopping the live indicator also passed using existing scripts.
The selected button already matches font/padding/margin and 36px height; its missing
icon accounted for the remaining visible label difference. Paired deployed icon
verification follows. No new test files committed. JS syntax, PHP lint, 692 checks
and full management HTTP pass (events-delete-http.txt). Full page goal stays active.

The first deployed button comparison caught a 12px width difference from flex gaps
around the count span. Scoped the visible selected button to inline-block; retained
its hidden attribute behavior. The corrected source-CSS comparison now matches
166.296875 x 36px at both widths, including font, padding, margin and full icon/text.
Paired narrow button images inspected after centering to avoid sticky-header clipping.

Final deployed checkpoint df54711; rollback /var/backups/lorkhanserver-code.X6hNzu.
All 803 runtime files match with no extras/legacy paths; health/private/auth checks
pass and configuration/credentials/voice hashes remain unchanged. Without source
CSS interception the selected button now matches the reference dimensions at both
widths. Mocked deletion acceptance passes again against the deployed page with the
current identical source script. No actual event deletion, provider call or game
control occurred. The matrix records this acceptance without claiming the full
multi-page goal complete.

### Adventure and Diary calendar boundary acceptance (2026-09-08)

Both pinned Herika renderCalendarHTML functions render spans on days without
entries and links only when events exist. Native linked all days. Changed the
shared calendar template to match; positive-count links retain count tooltip,
accessible date label, selected date and scope. The regular date filter still
supports inspecting empty days. Calendar data queries and game dates are unchanged.

Current-PHP-template browser fixtures compared against both reference calendars
for 2024-02, 2025-02, 2026-01, 2026-12, 2026-08 and 2026-02. Exact weekday/day/blank
cell matrices match, covering leap/non-leap February, four/five/six rows and year
rollover. Previous/next native links retain scope and clear selected date. Populated
fixture has exactly two links with counts 2/1 and 27 plain February days; keyboard
focus works. An initial blanket empty-reference assertion was corrected because
Herika has real August entries. No reference records were changed.

Separate read-only live keyboard probes verify Evening Star 3E 427 -> Morning Star
3E 428 -> Evening Star on Adventure and Diaries, with 31 January days. Morrowind's
3E dates and its fixed-year weekday anchor remain the supported game exception to
Skyrim, rather than copying Skyrim's anchor or 4E labels. Paired narrow direct-route
empty-calendar images inspected for day structure; direct versus embedded shell
widths differ, so those images do not establish shell-width parity. Actual hub
comparison follows deployment. PHP lint, 692 checks and full management HTTP pass
(calendar-empty-days-http.txt). No new committed test files or live data writes.

Deployed 290f040; rollback /var/backups/lorkhanserver-code.F9Izyy. All 803 runtime
files match source with no extras/legacy paths. Health/private/auth checks pass;
configuration/credentials/voice hashes preserved. Actual embedded Roleplay calendars
for February 2024 have identical day matrices, row heights and total dimensions:
1236 x 539.1875 at desktop and 346 x 539.1875 at 390px, for both Adventure and Diaries.
Inspected paired narrow Adventure and desktop Diary table screenshots. Both deployed
empty calendars contain no links. Existing filter/reset probe passed at both widths
after correcting its stale diary-empty-text regex; no POSTs occurred. No game or
provider used. These close the listed month-boundary acceptance, not the full goal.

### Quickstart optional Local LLM credential foundation (2026-09-08)

Reconfirmed that pinned Quickstart applies the selected global/profile preset before
Local LLM routing. Native routing-only save is not that full workflow. Keep Setup
cards and Local LLM panel pending until preset policy and coordinated save semantics
are complete; do not describe this backend checkpoint as visible UI parity.

Reference local_llm_setup.php maintains a separate Quickstart Local LLM badge for
its optional key. Added local_llm to the existing authenticated/CSRF-protected
Quickstart key endpoint, using a fixed dedicated custom credential identity and
that display label. It does not overwrite OpenRouter or general custom LLM keys.
Existing private atomic storage, value limits and environment-owned conflict rules
apply unchanged. Nothing is written just by deployment or opening Quickstart.
The local setup binds it through the existing badge: reference syntax; omission
still means no Authorization header. Credential values never enter routing metadata.

Extended the existing mock-provider HTTP test for missing CSRF, status-only save,
explicit badge binding sending the expected Authorization header, secret-free test
result and a subsequent unbound draft sending no Authorization header. The existing
routing-fingerprint assertion also covers these tests. Initial fixture incorrectly
used the raw variable as an LLM reference and was rejected by existing validation;
corrected to badge: without weakening validation. No new test files or real keys.

Remaining connected work is unchanged: full Default/Local LLM preset semantics,
Setup cards, local server URL/host helpers, optional key draft handling, preview,
and one accurate Save and Continue flow. In particular, reference Test can use an
unsaved key; this new persistence endpoint alone does not establish that behavior.

Final PHP lint and 692 server checks pass. Full management HTTP passes with the
corrected fixture (quickstart-local-key-http-2.txt), including the new authenticated
local-provider path and the existing no-routing-mutation assertion.

Deployed deb775f; rollback /var/backups/lorkhanserver-code.Sw8UzL. All 803 runtime
files match with no extras/legacy paths; health/private/auth checks pass. Existing
configuration, credentials and voices are hash-preserved. No local key was created
in the deployed store and no provider/game was used. GitHub server workflow remains
disabled_manually. This is backend preparation only; no new visual parity claim.

### Quickstart unsaved Local LLM key testing (2026-09-08)

Pinned herikaLocalLlmTestDraft uses the typed key for Test without persisting it;
a blank value falls back to the managed connector's badge. The earlier native
endpoint only supported saved badge references. Added optional top-level api_key
to the authenticated local-test request, separate from its persistable setup object.
It must be a string of at most 8192 bytes without control characters. The normalized
local connector supplies endpoint/model/options/timeout to the existing adapter;
the draft key exists only in that adapter, never in configuration, environment,
snapshots or CredentialStore. Local-network URL validation and direct/no-proxy
transport remain enabled. Empty/omitted drafts keep the existing badge resolution.
Persistent setup still rejects embedded raw keys and unrelated request properties.

Extended the existing isolated HTTP mock with unsaved-key override, secret-free
success result, empty-draft fallback to the original stored key, unbound no-key
behavior, null/list/number/oversize/header-injection rejection before provider calls,
CSRF rejection and unchanged routing fingerprint. PHP lint, 692 checks and full
management HTTP pass (quickstart-transient-key-http.txt). No new test files. No real
keys, provider credentials or game context used. No visible UI changed: this closes
the draft-Test credential prerequisite, not Setup preset/save or page parity.

Deployed ed83397; rollback /var/backups/lorkhanserver-code.YpFjN4. All 803 runtime
files match, with no extra/legacy files. Health/private/auth checks pass; existing
configuration, credential and voice hashes are preserved. No production key tests,
provider calls or game control performed. Full presentation goal remains active,
with Quickstart Setup/Local LLM UI and coordinated preset save still incomplete.


## 2026-09-08 — Core Profile Rechat calculator structure

Product commit `89a01c9` moves the calculator outside the Rechat settings card,
matching the reference metadata editor's section/calculator/card hierarchy.
The native continuation explanation is now hover/focus help instead of a permanent
paragraph. Probability calculation and saved round semantics are unchanged.

Evidence: `core-rechat-hub-proof.cjs` in the local temporary evidence directory
opens the actual configuration hub and its selected Core Profile iframe on both
servers, blocking all non-GET requests. At 1280px the reference calculator measures
411 × 61.796875px and native 412 × 61.796875px; paired captures were visually
inspected. This establishes calculator structure and height, not whole-editor parity.
At 390px the reference calculator collapses to 22px width inside its editor; native
remains readable at 300 × 82.59375px. The native narrow default/help captures were
inspected. Do not claim matching narrow reference geometry from this broken state.

Native keyboard focus/Tab blur and hover reveal/hide the explanation. Inputs at
two continuation rounds and 50 percent show three responses ending at 25 percent;
zero probability and invalid empty rounds were checked. No browser errors or
writes occurred. Reference counts two responses for its stored value of two;
Lorkhan still counts initial response plus continuation rounds. Runtime semantic
parity requires a separate coordinator audit, not a cosmetic calculation change.

PHP lint and all 692 server checks passed. Server-only local deployment completed,
rollback `/var/backups/lorkhanserver-code.I6vy06`; 803 runtime hashes matched with
no extras/old paths. Configuration, credentials and voice file contents were
preserved. Private-path 403, unauthenticated 401 and health probes passed. No game
was launched or controlled. Full Core Profile settings/presets and the broader
page matrix remain open. GitHub's server workflow stays manually disabled.


## 2026-09-08 — Whole Core Profile settings inventory and runtime dependencies

Read-only review at main `4993228`; product/runtime remains `89a01c9`.
This expands the pending matrix rather than accepting the editor from the Rechat
calculator-only checkpoint. `core-settings-full-review.cjs` captured actual hub
DOM headings and field labels with every non-GET blocked. Its whole-card images
are clipped by iframe/sticky surfaces and are NOT complete visual acceptance.
`core-feature-review.cjs` then captured individual direct-embed feature cards;
Dynamic Profile Fields on both servers and reference Short Term Memory were
visually inspected. No profile, provider or game state was edited.

| Reference section/control | Current native counterpart | Required implementation before acceptance |
| --- | --- | --- |
| Dynamic Profile Fields | Same five editable field choices | Match checkbox geometry and recheck card layout after restoring adjacent feature cards; preserve native new-NPC versus existing-NPC semantics |
| RPG Comments: Comment Types, Trigger Chance | Installation-global policy only; no Core Profile card | Resolve the actual responding actor's Core Profile at the event decision boundary, persist scoped overrides, connect events/chance controls and presets, and verify global fallback and cooldown behavior |
| Short Term Memory: Max Summaries (1–50) | Toggle filters recent-tier memory records; no summary count control | Port scene-summary boundaries and their relationship to the middle-term digest and verbatim history before wiring the cap; do not relabel the existing ten-item shared memory cap |
| Language: Core Lang, Lang Llm Xtts, Max Words Limit | Max Words only under LLM heading | Add Core Profile language ownership, frozen prompt-language instruction and supported XTTS/Chatterbox language propagation; global DeepL translation is a separate output-processing policy |
| Rechat: rounds, probability, allow actions | Controls present; calculator structure corrected | Compare initial-response versus continuation-round semantics with the coordinator; preserve zero-percent behavior rather than copying reference JavaScript's zero-to-default bug |
| Bored Event: chance | Native global boredom delay in seconds | Add the probability decision and per-profile ownership; delay is not a probability |
| Context: dialogue/diary/dynamic-profile history | Three native controls present | Keep current history consumers; compare count meaning/ranges and full populated/edit states |
| Diary: prompt and cooldown | Native controls present | Recheck complete settings row paired with Combat; physical diary is not Diary In Context |
| Combat: bark cooldown | Native global combat-bark period | Trace client event timing and responding profile, then add working per-profile ownership and matching card |
| Quest: comment and chance | No Core Profile controls | Trace regular quest-comment support separately from the explicitly excluded AI Quest Manager; exclusion of the manager does not establish an exception for all quest commentary |

Reference structure in `ui/core/tmpl/metadata_json_editor.php:88` is paired rows
Language/Rechat, Bored Event/Context, Diary/Combat, then Quest. The feature grid
contains Dynamic Profile Fields, RPG Comments and Short Term Memory. Native only
has Dynamic Profile Fields and pairs LLM/Rechat then Context/Diary. Merely changing
the LLM heading or filling the missing positions with disabled cards is not parity.

Runtime evidence informing the next implementation:
- Herika `lib/data_functions.php:4966` (`DataShortTermMemoryFor`) uses the NPC's
  middle-term digest high-water mark, the oldest summary crossing the live history
  boundary and a configured cap; it also sets the history crop boundary.
- Native `prompts/PromptAssembler.php:91` maps recent/mid/long memory tiers to the
  three toggles. `prompts/MemoryPromptSelection.php` uses textual coverage and a
  shared ten-item limit, not Herika's scene-summary gap selection.
- Native `lib/Infrastructure/ProductRepository.php:2034` selects scoped memory
  records and optional per-record model summaries. Such a model summary is not,
  by itself, a Herika scene bucket or digest boundary.
- Native `lib/Http/Router.php:253` decides RPG comment requests from the global
  policy before returning the event acknowledgement. Adding profile form fields
  without changing that decision path would create inert controls.
- `TranslationPolicy` is installation-scoped DeepL postprocessing. It must not be
  silently reused as the Core Lang setting.

Next implementation priority is the Core Profile Language section and its actual
prompt/TTS consumers, followed by RPG profile ownership and short-term scene
summary boundaries. These dependencies remain part of the full goal, not accepted
product-specific exceptions. No new deployment or test pass is claimed for this
read-only audit; no game was launched and the workflow remains disabled.


## 2026-09-08 - Core Lang selector and localized instructions

Product `98fc2e8`, typography follow-up `a3ace2d`, deployed server-only.
Language now contains Core Lang above Max Words Limit. The live reference choices
and labels are matched: blank, en, de, es, fr, jp. The reference lang directory also
contains pl, but the actual editor does not offer it.

Optional strictly validated `response.core_lang` survives save/reload, portable
export/import, named presets and revision-protected Copy to all. The frozen Core
Profile setting selects native localized roleplay and next-line instructions in
PromptAssembler. Blank/en retain the existing English path. Custom prompts,
Markdown transport, output contract, history, word limits and DeepL are unchanged.
No migration or change to private profiles is required.

This is NOT the complete Language section: Lang Llm Xtts requires model-returned
language metadata through the speech queue and remains absent. The native core
instructions are not a wholesale copy of the reference translated prompt files;
full template coverage and minimal-budget fallback still need review.

Evidence:
- Changed PHP lint and 702 unit checks passed, including localized assembly with
  unchanged word limits, invalid codes and named preset capture.
- Full management HTTP suite passed: `core-language-http-2.txt`. Disposable data
  exercised save/reload, invalid values, export/import and Copy to all. First run
  caught the old exact export assertion; it now requires the saved language too.
- `core-language-browser.cjs` used the actual hub at1280: both selectors have14px
  Futura CondensedLight, height40, padding10/12, radius6; widths385/386. Paired
  desktop rows and native390 were viewed. All choices and keyboard selection
  passed without errors or writes. Native390 selector is274x40.
- Reference390 capture timed out in its collapsed embedded editor. Narrow visual
  equality is not accepted from that state.
- Final rollback `/var/backups/lorkhanserver-code.Y1MycD`; all804 runtime files
  matched, no extras/legacy paths, private403, unauthenticated401, health/NPC probes
  passed. Configuration, credential and voice hashes preserved.
- GitHub server workflow remains disabled_manually. No game or live-provider test.

Remaining: model-returned speech language, complete localized template coverage,
RPG profile ownership and scene-summary boundaries. Full page parity stays active.


## 2026-09-08 - Model speech language through streaming and durable synthesis

Product `c8f7fb7`, checkbox styling `6718356`, deployed server-only. Language now
contains Core Lang, Lang Llm Xtts and Max Words Limit in reference order. The new
boolean defaults off for absent profiles and participates in form saves, portable
export/import, named presets and the existing protected Copy to all operation.

The frozen prompt opt-in requests a leading language field. OpenAI-compatible
structured schema and JSON prefill follow that field order. The streaming callback
can carry its normalized value with the first sentence, without waiting for the
completed response. Completed results put language in internal utterance metadata;
it never becomes dialogue/subtitle text. Metadata survives planning, inline
narration, streamed reconciliation, queued speech and retries. Successfully
translated audio replaces supplied model language with the translation target.

SpeechLanguage applies only to XTTS/XTTS-fastapi/Chatterbox. Missing, invalid or
unsupported metadata leaves the configured language unchanged; other connectors
are untouched. Blank legacy jobs retain their original payload shape. No migration
or client protocol change is needed. Model output without metadata is still usable
when the setting is enabled; disabled mode keeps the original strict envelope.

Evidence:
- PHP lint and 719 existing-suite checks passed, including opt-in/default prompts,
  prefix parsing, invalid metadata, connector boundaries, planning and aliases.
- Full management HTTP suite passed (`llm-speech-language-http.txt`): toggle save,
  reload, export/import and existing profile operations with disposable data.
- Full integration, schema inventory (173 relations), migrations and durable job
  suite passed (`llm-speech-language-integration.txt`). These regression checks do
  not establish every provider-specific retry/failure combination.
- Temporary `llm-language-probe.py`/`.php` exercised the real HTTP LLM adapter and
  XTTS adapter against a local mock. The mock withheld the final LLM chunk until
  receiving first-sentence TTS: stream, JSON prefill, missing/invalid metadata and
  disabled cases all synthesized early. French metadata reached mock TTS; missing,
  invalid and disabled cases retained German configuration. Buffered results also
  carried French. No real credentials, paid providers or game used.
- `speech-language-ui.cjs`: actual hub at1280 and native390; on/off, keyboard,
  Escape/focus and mocked boolean Copy to all passed. Paired desktop Language
  sections and native narrow/on/off captures were viewed. Checkbox dimensions,
  transform, margins, label gap and font match the reference. Full card heights
  and all descriptive text are not declared 1:1; narrow reference remains broken.
- Final rollback `/var/backups/lorkhanserver-code.W2h7oy`; 805 source/runtime hashes
  match, no extras/legacy paths. Private403, unauthorized401, health/NPC probes
  pass; configuration, credentials and voice hashes preserved.

Remaining: full translated-template/minimal-budget coverage, overall Core editor
spacing, RPG profile ownership, scene-summary boundaries and the broader matrix.
Live-provider multilingual quality and in-game audio are untested. Goal stays active;
GitHub workflow stays disabled and no game was launched or controlled.


## 2026-09-08 - Shared Core Profile field presentation and RPG responder audit

Product `6144adf`, baseline-alignment follow-up `f316e5b`, deployed server-only.
Shared labels now use reference gap/margin, descriptions use reference size and
spacing, Dynamic Profile Fields use the reference checkbox sizing/baseline and
label layout, sliders use the reference flex layout with86px numeric controls,
section headings use weight400, and the diary prompt uses four rows and reference
line height/minimum height. Connector descriptions retain the reference minimum
height. This is shared editor styling rather than another isolated control fix.

`core-style-catalog.cjs` compares the computed presentation of16 field/card
selectors. After deployment the only remaining sampled property difference is the
outer feature-card height (Herika402.938px versus native221.375px), because Herika
stretches it beside RPG Comments, which is still missing. Do not use a forced
height to claim that missing section is implemented. Paired Dynamic Profile Fields
captures were viewed; native390 fields and populated diary input were also viewed.

`profile-fields-proof.cjs` passed at1280/860/390: every numeric/range pair syncs in
both directions, checkbox keyboard controls work, diary text accepts four lines,
there is no settings-card overflow and no browser error. All writes were blocked.
PHP lint and719 unit checks passed. No new backend/HTTP behavior was changed by
this styling patch. Final rollback `/var/backups/lorkhanserver-code.XzxXEv`;
805 runtime files match source, no extras/legacy paths, private403, unauthenticated
401, health/NPC checks passed, and config/credential/voice hashes were preserved.

### RPG Comments dependency confirmed from current client main

Read-only client inspection used `RANGROO/LORKHAN` origin/main `6daab0c`. The old
canonical `codex/lorkhanserver-client-route` checkout was not treated as current
implementation and was not edited. No game was launched or controlled.

- Server `Validator::gameData` accepts RPG kind/player/game_time/text, with no
  responder identity. `Router::gameData` therefore uses the global RPG policy.
- Client `player.lua:487` submits that player-only payload. Its RPG comment handler
  at1929 chooses `state.ui.target` after the acknowledgement, then checks distance,
  combat, speech and menu state. The turn has a `[RPG:kind]` text prefix, but parsing
  that later cannot undo a global decision that prevented the request initially.
- The native overlay binding at1250 emits `rpg.comment` with original request_id,
  session/generation, kind and text. `orchestrator.lua:1025` forwards that event.
  This path exists; RPG emission itself is NOT missing.

Required next work: freeze a responder at submission, carry an optional typed
responder identity through the shared RPG schema, resolve that actor's Core Profile
for the probability/event decision, and retain global fallback for older clients.
Use the existing request_id at acknowledgement to select the same still-valid
responder, instead of whichever NPC happens to be targeted later. That correlation
can be owned in Lua; confirm native payload serialization before deciding whether
an engine rebuild is necessary. Bound pending requests and retain session fences.
Then add the real Core Profile RPG card and portable/copy settings. Do not expose
profile controls that only write the global policy or silently filter an already
rolled event a second time. Keep supported OpenMW event differences explicit.

Full Core Profile and overall page parity remain active; RPG controls, scene
summary boundaries and the remaining counterpart matrix are not completed here.

## 2026-09-08 - Core RPG policy inheritance foundation

Product `2709506` is pushed to main and deployed server-only. The effective-settings
resolver now carries the global RPG policy and applies validated Core Profile
`settings_overrides.rpg_comments` fields. Missing fields inherit; zero probability
and an empty event list remain explicit off. NPC overrides do not take ownership.
The shared validator retains the existing four supported events (levelup,
combat_end, sleep, wait), integer 0-100 probability and strict field/type checks.
The server-only section is excluded from the native controls projection.

This is NOT completion of RPG Comments: ingress still accepts the player-only
payload and the Router still rolls the global policy. No new profile controls have
been exposed, and normal RPG behavior is unchanged until responder ingress and
profile selection are connected. Form save/import/export/preset/Copy to all plumbing,
actual reference card rendering and interactive comparisons remain pending.

Evidence: 742 server checks passed, including Core inheritance/explicit off/source
ownership/invalid values/native projection. The full integration vertical slice,
173-relation schema inventory and migration/durable job suite passed in
`rpg-profile-policy-integration.txt`. Rollback is
`/var/backups/lorkhanserver-code.fuQ9nI`. Verification matched all 805 runtime files,
with no extras or legacy paths; private-file403, unauthenticated401, health and NPC
200/404 probes passed. Configuration, credentials and voice hashes were preserved.
These checks prove the foundation, not the missing feature or full UI parity.

Additional client source evidence at `6daab0c`: `beast_transport.cpp:643-663`
serializes the existing GameDataRequest payload unchanged; `requireJsonObject` at330
checks only valid JSON object shape. Combined with the native Lua binding's generic
serialization and existing request-id acknowledgement, adding a typed optional
responder should require Lua/schema/server changes, not native C++ changes. A
runtime Lua-only deployment path and exact deployed client version still need
verification. Isolated client worktree `D:/wt/lorkhan-rpg-profile-responder`, branch
`codex/rpg-profile-responder`, is clean at6daab0c; its required reading is not yet
complete, so no client implementation has been edited.

Next: finish client required reading; add optional responder to both shared schema
copies and validators, freeze/bound/correlate the same eligible actor in Lua across
session/generation changes, and have ingress resolve its Core Profile before the
single existing probability roll. Then expose the working reference-style card.
No game launched or controlled. Workflow remains disabled. Full parity goal active.

## 2026-09-08 - RPG Comments card and responder path implemented

Server `f6118e6` adds the reference-style card and responder-aware ingress; `4230cc5`
adds reference built-in probabilities. Client `1560dbe` adds the typed optional
responder, bounded acknowledgement correlation, global handoff fences and cooldown.
Both repositories are pushed directly to main from `codex/web-ui-parity` and
`codex/rpg-profile-responder`, respectively. This supersedes the prior RPG
foundation-only checkpoint, not the remaining whole-editor/page matrix.

### Behavior and presentation

- Core Profile saves, portable export/import and named presets retain RPG event and
  probability choices. Empty events and zero probability remain explicit off.
  Existing profiles without overrides inherit global policy. Saving the displayed
  card makes its current choices explicit on that profile, like the other controls.
- The authenticated gamedata path resolves the frozen responder's actual Core
  Profile before its single deterministic roll. Idempotent retries retain the
  original decision even if settings change. Legacy player-only observations use
  the global policy. No schema migration or native C++ change was required.
- Lua tracks at most32 recent request identities for30 seconds. Acknowledgements
  are consumed once and must retain session/generation, target and nearby eligibility.
  The global handoff rechecks the target and idle speech/combat/input state. RPG
  turns start a60-second game-owned real-time cooldown, cleared on lifecycle changes.
- The card uses Herika's heading, two setting rows, checkbox chips, probability
  labels/help and range/number layout. Gold branding remains. Eight reference events
  become the four currently supported OpenMW events: levelup, combat_end, sleep,
  wait. Skyrim shout/word/Dragonborn-soul and bleedout behavior is not fabricated;
  lockpick remains pending a provenance-correct observer, not an accepted generic
  Unlock event. These differences explain card height; no forced blank space added.
- Built-in Default/Local LLM/Follower/Passive probabilities now match50/0/75/20.
  They retain selected event types. Other incomplete built-in effects remain pending.
  Reference RPG controls have no per-field Copy to all button, so none was invented.

### Evidence and deployment

-748 PHP checks and69 Lua tests pass. Existing tests cover explicit off, named and
  built-in presets, responder types, bounded/duplicate/expired/stale acknowledgement,
  changed global target, combat/busy fences and cooldown lifecycle.
- `rpg-responder-integration.txt`: full integration/migration/durable-job suite and
 173-relation inventory passed. Opposing global0/Core100 and global100/Core0 cases,
  changed-policy replay and legacy fallback exercise the actual ingress/repository.
- `rpg-comments-management-http-final.txt`: full browser-like HTTP suite passed,
  including save/reload, selected-event export and all-off import. A stale diary
  rows3 assertion was corrected to the previously implemented reference rows4.
-38 schemas/62 fixtures pass the repository validator; installed Python lacked
  jsonschema, so official meta-schema validation is not claimed. All100 shared
  schema/fixture files and both manifests are byte-identical between repositories.
- `rpg-comments-ui.cjs` / `rpg-comments-ui-final.txt`: actual hub1280 reference and
  native comparison, native860/390, both slider directions, keyboard and all-off
  states passed without writes/browser errors/overflow. Paired desktop screenshots
  and native390 all-off were viewed. After whitespace/font corrections, sampled
  chip width/height82.703125/32 and range height43 match reference. Card padding,
  header, body gap, row gap and fonts match. Heights differ with supported events.
  Broken reference narrow iframe remains separately documented, not reproduced.
- Final server rollback `/var/backups/lorkhanserver-code.bVhDHV`;807 deployed files
  match source with no extras/legacy paths. Private403, unauthenticated401, health
  and NPC200/404 probes pass; configuration, credential and voice hashes preserved.
- Client baseline was verified against deployed Lua/data before copying only
  player.lua, player_state.lua, orchestrator.lua and protocol.lua. All28 runtime
  Lua/data files now match client1560dbe (line-ending normalized). Engine/private
  config hashes are unchanged. Rollback:
  `C:/Users/reece/AppData/Local/Temp/lorkhan-rpg-lua-rollback-8995_a6j`.
  No game started/restarted/controlled; Lua changes require the scripts to be loaded
  by the user's next game session. In-game trigger/audio behavior is not claimed.

Next priority: the missing Short Term Memory card and its real scene-summary boundary
semantics, then remaining Core Profile sections and the full counterpart matrix.
Do not present the existing general memory cap as the reference Max Summaries.
Full parity goal remains active. GitHub server workflow remains disabled_manually.

## 2026-09-08 - Short Term Memory source-boundary foundation

Server product `3d5a56e`, branch `codex/web-ui-parity`, pushed to main and deployed.
No UI control or history cropping is enabled by this foundation.

Current reference evidence: `lib/data_functions.php:4945-5078` defines STM as scene
summaries newer than the actor's middle-term digest high-water mark, through the
oldest summary that straddles the live window floor. It limits the newest candidates
by SHORT_TERM_MEMORY_MAX, reverses to chronological order and crops covered live
history only when a returned summary reaches that floor. The Core card exposes a
1-50 Max Summaries control, default10. This is not the native generic ten-item memory
cap or its recent/mid/long switch mapping.

Native consolidation now retains `provenance.source_game_time_range={from,to}`
only when every source has a finite bounded authoritative game timestamp. Recent
records obtain it from their source turn; higher tiers aggregate complete child
ranges. Fractional values are preserved. Missing/legacy/invalid timestamps leave
the metadata absent. Existing UTC source_range and exact-content coverage proofs
remain independent: game bounds describe the inputs, NOT proof that a capped or
model-generated summary retained every input. Nothing starts trimming prompt history,
backfills old rows, changes retention or launches extra provider work in this patch.

Existing migration/job fixtures now exercise four scene ranges, propagation to the
next tier, and an intentionally missing source timestamp. The initial assertion used
PHP strict array comparison against PostgreSQL jsonb key order; it was corrected to
compare the actual boundary fields. `stm-source-time-integration-2.txt` passes the
full vertical slice,173-relation inventory and migration/durable-job suite. Existing
truncation/coverage and idempotency tests remain in that suite.748 server checks and
PHP lint pass. No new test file or test harness was added.

Deployment rollback `/var/backups/lorkhanserver-code.AbDocS`; all807 runtime files
match, no extras/legacy paths, private403/unauthenticated401, health and NPC200/404
probes pass. Existing configuration, credential and voice hashes are preserved.
No game or client change in this checkpoint; GitHub workflow remains disabled.

Next implementation constraints from current source:
- Reuse actor-witnessed eligibility in ProductRepository::promptMemoryCandidates
  (source-event projection, suppression, played delivery, actor/audience checks).
  Do not broaden the consolidation session-profile fence or bypass privacy merely
  to find more summaries. Manual memories remain owned by their NPC profile.
- Add scene-summary selection independently from generic ranked memory selection:
  determine digest high-water, live history floor, straddling bucket and ordered
  bounded candidates before applying the profile Max Summaries setting.
- Carry source references and boundaries into prompt assembly. Only retained,
  authoritative summary coverage may remove overlapping live events; truncated,
  hidden, expired, unknown-time or excluded summaries cannot justify cropping.
- Wire real1-50/default10 Core controls, portable/named presets and actual reference
  card layout only with the selector. Compare populated/empty/disabled states and
  interaction in the actual hub. The full Core editor and overall goal remain open.


## 2026-09-08 - Scene selector connected to actual prompt assembly

Product `9aa6851`, server branch `codex/web-ui-parity`, pushed directly to main and
locally deployed. This is runtime progress toward the Short Term Memory card;
there is no new UI or visual-completion claim in this checkpoint.

- Witness-filtered scene candidates now use authoritative game-time bounds to
  select the newest ten summaries through the oldest bucket reaching the retained
  live-history floor, then render chronologically. The ten-scene quota is separate
  from the existing ten general-memory quota; the shared byte budget still applies.
- Recent-source consolidated scenes belong to the Short Term Memory switch rather
  than the Middle Term Memory switch. Unknown-time/manual records keep the existing
  generic retrieval path. Missing history timestamps do not invent an upper bound.
- Digest coverage is checked against retained text. A truncated digest cannot hide
  uncovered scenes via a blanket timestamp. History removal is NOT implemented:
  unrelated live events remain intact, even inside a selected summary time range.
- If the prompt budget removes history, scene selection runs again without the old
  history floor. Retrieval reasons record candidates outside the scene window.
- No new provider jobs, retention changes, migrations, client changes or game launch.

Evidence: 764 existing-suite PHP checks pass, including actual assembled prompt
selection and toggle ownership, independent quotas, chronological order, strict
bounds, truncated-digest safety and history-free recalculation. Full integration
in `stm-scene-window-integration.txt` passed vertical slice, 173-relation inventory,
and migration/durable-job checks. `git diff --check` passed.

Local rollback `/var/backups/lorkhanserver-code.XhKZrV`; runtime verification found
all 807 expected files matching, no extras or old paths, private routes returning
403, unauthenticated sessions returning 401, and health/NPC probes passing.
Configuration, credential and voice file hashes were preserved.

Still open: expose and persist the real 1-50/default10 Max Summaries setting in the
reference-style Core card and named/portable presets; compare actual-hub populated,
disabled and interactive states. Complete retained-summary/source-event coverage
and safe overlapping-history removal, including model projections and budget
fallbacks. Exact digest coverage currently deduplicates selected scenes, but does
not yet establish the reference digest high-water selection before the scene cap.
These are unfinished parity work, not permanent product exceptions. The remaining
Core sections and every other open counterpart-matrix row remain in the full goal.
GitHub workflow remains manually disabled.


## 2026-09-08 - Short Term Memory card and per-profile summary limit

Product `d0ce6f4`, branch `codex/web-ui-parity`, pushed directly to main and locally
deployed. Core now uses the reference Short Term Memory provider card, title/icon,
Max Summaries label/help and paired range/number controls. Gold branding is retained.
The server-owned field defaults to10, validates integer1-50, survives Core saves,
named presets and portable export/import, and reaches actual prompt scene selection
including the history-removal budget fallback. It does not alter the client schema
or silently become a global/NPC override. No new CSS, test file or migration.

772 PHP checks passed, covering the real three-summary prompt result, resolver
ownership, client projection exclusion, preset application and invalid inputs.
`stm-card-management-http.txt` passed the full browser-like forms suite, including
saving37, rejecting51 and exporting/importing37. `stm-card-integration.txt` passed
vertical slice,173-relation schema inventory and migration/durable-job tests.

Actual hub evidence `stm-card-ui.cjs` / `stm-card-ui.txt`: reference and native1280
card both412.5x196.5625; identical card/head/body/row/range measurements, padding,
gaps, fonts and radius. Paired screenshots were actually viewed; native390 disabled
STM state was also viewed. Native1280/860/390 passed slider-to-number and reverse,
keyboard arrows, minimum1/maximum50, default10, retaining the limit with STM off,
no page errors or card overflow. Non-GET requests were blocked during live visual
checks; saves/imports used isolated HTTP fixtures, not private live profiles.
The reference narrow iframe limitation remains separate from native narrow checks.

Deployment rollback `/var/backups/lorkhanserver-code.idYKU1`; all807 runtime files
match, no extras/old paths, protected routes403 and unauthenticated session401,
health/NPC probes pass. Configuration/credential/voice hashes preserved. No game
launch/control and no client changes. Workflow remains disabled.

Still open: digest coverage/high-water selection before the scene cap, retained
summary/source-event linkage and safe overlapping-history removal, including model
projections and budget fallbacks. This card checkpoint does not close those runtime
requirements or the full Core editor/counterpart matrix. Continue those and then
remaining Core sections (Bored, Combat, Quest comments, physical diary), with actual
OpenMW consumers and corresponding visual comparisons rather than inert controls.


## 2026-09-08 - Retained exact scene overlap and digest-before-cap selection

Product `6655b8e`, server branch `codex/web-ui-parity`, pushed directly to main and
locally deployed. The reference-style card remains unchanged; this checkpoint
implements more of the real behavior behind it, not a new visual completion claim.

Before applying Max Summaries, the selector excludes scenes whose complete text
is covered by actually retained general/digest memory. This fills remaining slots
with uncovered older scenes. It still computes the upper straddling boundary over
all scene candidates: a covered boundary cannot move the window into newer live
history. Truncated digests only prove coverage for complete retained lines.

Prompt assembly removes a live line only when its complete speaker-qualified text
occurs at line boundaries in a fully retained, timestamped scene and the live game
time falls within that scene range. Unrelated events, later repeated words, missing
timestamps, partial history and cut summaries remain live. The next retained line
inherits an otherwise-orphaned temporal heading. Trace history IDs now reflect
actual survivors; removed lines report covered_by_memory. Omitted memory spanning
both retained families reports covered_by_context. Intentional scene exclusions
are no longer mistaken for byte truncation. If prompt budgeting removes history,
selection is recalculated without its floor; minimal fallback cannot claim removed
summaries still cover history.

779 existing-suite PHP checks pass. Added focused cases in tests/run.php cover
actual assembled prompt deduplication and source traces, later repetitions, source
byte truncation, disabled STM restoration, minimum-budget fallback, heading transfer,
combined-family coverage, digest-before-cap refill and a covered straddler.
`stm-overlap-integration.txt` passed the full vertical slice,173-relation inventory
and migration/durable-job suite. `git diff --check` passed. No new test file,
migration, provider call, retention policy, UI or client change.

Deployment rollback `/var/backups/lorkhanserver-code.wVQNlk`; all807 runtime files
match with no extras/old paths. Private403, unauthenticated401 and health/NPC probes
pass. Configuration/credential/voice hashes preserved. Game never launched or
controlled; GitHub workflow remains disabled.

Remaining STM limitation: paraphrased model summaries without literal text coverage
cannot yet replace their source events or establish a semantic digest high-water
boundary. Complete source-event/model-input lineage and final retained projection
coverage must justify that path; do not turn the conservative interim rule into a
permanent parity exception. Continue that work and the remaining Core presentation
sections and complete counterpart matrix. Full page parity is still not complete.


## 2026-09-08 - Whole Core settings group structure review

Product commits `c92814f`, `60c7965`, `d2c8d16`, branch `codex/web-ui-parity`, pushed
directly to main and deployed. Claude's previously reported exhausted-usage fallback
remains in effect for this same UI task; Codex performed this comparison and change.

Actual reference/native hub review (`core-settings-order-review.txt` and
`core-groups-style-review.txt`) confirms the shared section order already matches.
Reference has additional Bored Event, Combat and Quest groups; native Context also
has a Dynamic Profile history control not visible in this reference profile.
Those are open counterpart differences, not excuses to insert empty cards or
remove an existing supported control. Whole-editor parity is not established.

The existing Language/Rechat/Context/Diary sections now use the reference bordered
content-section container, H2 headings and profile-settings-group-card markup.
Card padding is6px12px instead of12px; the container's border corrects desktop group
width from412 to411px. Added the reference Back to top footer control; native behavior
works both standalone and in the actual hub frame, honors reduced motion and does
not submit/reset forms. Restored fuller Rechat explanatory formatting while keeping
native continuation-count semantics explicit. The blank Diary Prompt now shows the
reference Enter value placeholder and matching introductory help.

`core-groups-ui-final.txt` verifies all four native desktop groups match reference
411px width, H2 markup and6px12px padding; native860/390 are492/298px with no group
overflow. Back-to-top keyboard activation moves the actual hub frame to its start,
resets child scroll and retains an unsaved Max Words77. No page errors. All non-GET
requests were blocked for these live visual checks. Paired Language/Rechat/Context/
Diary desktop images were actually viewed; final native390 Rechat and final empty
Diary field were viewed after refinements. Different help text, selected values,
extra/missing controls and row partners still affect total heights; no whole-card
height or whole-editor1:1 claim is made from those structural measurements.

779 PHP checks, node syntax check and git diff check pass. The existing browser-like
management HTTP suite passed after the structural/JS change; subsequent refinements
only changed help text and a placeholder, and final PHP/browser checks passed again.
No new test files or backend/client changes. Final rollback
`/var/backups/lorkhanserver-code.xh0gSc`; all807 runtime files match with no extras or
old paths, private403/unauthenticated401 and health/NPC probes pass. Configuration,
credentials and voices preserved. No game launch/control. Workflow remains disabled.

Next: implement the missing Bored Event, Combat and Quest controls with their real
supported per-profile consumers, review Context's extra control against reference
availability, then continue the complete counterpart matrix. Paraphrased-memory
source coverage remains an open runtime item; it does not replace the all-page
presentation objective. The full parity goal remains active.


## Combat card and native cooldown checkpoint (2026-09-08)

Server `6c90f7e`; client `a7fceb0`. Added the reference Combat group, swords icon,
Combat Bark Cooldown description, slider/number pair and Copy to all. Core
overrides, named/portable presets and global fallback retain their ownership.
The existing global enable switch is unchanged; default remains 20 seconds.
The normal editor range is 10-600; existing 5-9 values remain valid. Native
protocol parsers and the Lua timer now accept the same maximum of 600 seconds.

Evidence:
- 783 PHP checks; management HTTP save/export/import/copy/range cases; full
  integration and durable migration checks passed in the Combat test logs.
- Windows OpenMW build, native bridge tests and Beast loopback tests passed.
  The Beast fixture was refreshed to include the narrator fields already required
  by the parser; production validation was not weakened. Lua: 70 tests passed.
- Protocol copies match. Generator validation covered 100 files, 38 schemas and
  62 fixtures; Python jsonschema was unavailable, so this is not metaschema proof.
- Actual hub screenshots compared: both desktop cards are 411px wide with
  6px 12px padding. Native 860/390 cards are 492/298px wide without overflow.
  Desktop reference/native and native 390 images were visually inspected.
- Full range, keyboard increment, invalid 601, and Copy to all cancellation
  passed at 1280/860/390 without private writes. Evidence: local Temp
  `combat-card-ui-result.txt` and `combat-card-*-Combat.png`.
- Server deployment rollback: `/var/backups/lorkhanserver-code.3HR2CD`. All 807
  runtime files match, no extras/old paths; private file requests return 403 and
  unpaired session requests return 401.
- Client executable and orchestrator deployment hashes verified; all 28 deployed
  non-test data files match source after newline normalization. Configuration
  unchanged; rollback is local Temp `lorkhan-combat-rollback-22xgftf2`.

Still open: the reference Combat card stretches with its neighboring grid card;
Lorkhan currently has a shorter card because the surrounding Core sections are
not complete. Slider track treatment also differs beyond color and must be
reviewed with the whole editor. This is not whole-Core visual parity. No game
was launched or controlled, and in-game timing remains unverified. GitHub server
workflow remains disabled manually.


### Core slider presentation follow-up (2026-09-08)

Replaced the browser-native outlined Core slider track with Herika's explicit
8px solid track and 16px circular thumb, preserving gold and keyboard focus.
The scoped range rules apply to Core range pairs, not unrelated page inputs.
Desktop deployed Combat screenshot inspected against the preceding reference;
1280/860/390 range, keyboard, invalid value and Copy cancellation checks passed
again (`combat-slider-ui.txt`). Server verification again matched all 807 files
and private access checks. This closes the slider track difference above; the
surrounding grid/card-height gap remains open. No game was launched.


### Bored Event implementation boundary audit (2026-09-08)

Current-source trace establishes that this missing card is a behavioral gap, not
a label for the idle interval or narrator chance:
- Reference `ui/core/tmpl/metadata_json_editor.php` declares Bored Event Chance
  as 0-100 under Bored Event, paired with Context in the grouped layout.
- Reference `main.php:1008` applies BORED_EVENT using a 0-99 roll before choosing
  the narrator flow versus the rolemaster/director instruction. Thus the overall
  chance is separate from narrator routing and also gates narrator bored flow.
- Native `orchestrator.lua:514` currently waits for the idle delay, rotates to an
  eligible NPC, then tries narrator probability or unconditionally requests NPC
  boredom. There is no overall Bored Event Chance.
- `nextBoredActor` rotates across eligible registered actors, not necessarily the
  currently selected actor. Reusing the selected target's effective controls
  would incorrectly apply another NPC's Core profile.

Implementation requirements for this pending row:
1. Add overall 0-100 probability with explicit zero preserved, Core/NPC ownership
   and save, Copy to all, named/portable preset handling. Confirm reference preset
   values independently; do not substitute narrator's default25.
2. Resolve the actual chosen actor's policy before rolling, then perform narrator
   routing. Use existing bound-responder controls machinery where appropriate;
   keep generation/session/actor identity checks and do not start a provider turn
   when the roll fails. Reset the idle opportunity after a failed roll so it does
   not retry every frame until success.
3. Keep the idle interval and existing global enable switch separate. Add strict
   optional protocol support if client projection is needed; update both repos.
4. Port the card and grouped position, compare zero/intermediate/100 and saved
   states. Test differing selected/chosen actor profiles, miss cooldown, zero,
   100, narrator routing, presets and stale responses.
5. Separately map the reference rolemaster/director topic generation: a probability
   card alone cannot close full Bored Event runtime parity.

No product behavior changed by this audit. This supersedes any suggestion that
adding a generic probability to the existing selected-target timer is sufficient.


### Bored Event server policy foundation (2026-09-08)

Added server-owned `bored_event.chance_percent` (integer0-100/default50),
normalization for older global documents, Core resolution/source tracking and
named Core/global preset retention. Explicit zero remains zero. This policy is
not projected into the existing strict native controls document. Existing
server suite: 789 checks passed, including zero ownership/preset and invalid
negative, above100, string and null cases.

This is preparation, not runtime parity: no new UI card is exposed and the
chosen-speaker request, probability consumer, narrator ordering and missed-roll
cooldown remain to implement before deployment. NPC override editing, portable
forms and built-in-specific values also remain pending. No game was launched.


### Bored Event card and chosen-speaker request (2026-09-08)

Client `832e287` implements a closed bored_event request with an NPC responder,
server policy reply, idle opportunity reset, 30-second pending timeout, per-
opportunity binding, session/generation guards and single-consumption handling.
Overall chance is evaluated before the existing narrator routing probability.
Core card is now in the reference Bored Event group before Context, with 0-100
slider/number, Copy to all, saved zero and portable import/export support.

Proof: 789 PHP checks; 70 Lua tests; Windows engine build and native/Beast tests;
management HTTP save0/reject101/import0; database-backed integration proves Core
100 overrides global0, Core0 suppresses new opportunities, and retries preserve
the first decision. Full migration/durable checks passed. Schema tooling passed
102 files/38 schemas/64 fixtures without installed jsonschema.

Actual hub comparison: reference/native desktop width411 and padding6px12;
native860/390 width492/298, range endpoints, keyboard, invalid101 and Copy cancel
passed. Desktop screenshots inspected. The initial sleeping icon was corrected
to the reference gear. Reference text about CHIM MCM is omitted because this
control is not exposed in Lorkhan's in-game editor yet. Card heights differ due
to the remaining adjacent Context controls; full Core parity remains open.

Local client deployment verified executable plus3Lua hashes, preserved Config,
backup `lorkhan-bored-rollback-k962qbfa` in local Temp. Server809 runtime files
matched with private403/unpaired401 checks. No game launched or controlled.

Still pending for full Bored Event parity: built-in-specific probabilities, NPC
override editor ownership, in-game setting exposure, rolemaster/director topic
generation, whole-Core layout and actual in-game acceptance. This checkpoint
does not close those items or the full-page goal.


### Built-in bored/combat preset values (2026-09-08)

Compared current reference `lib/core/settings_presets.php` default/local runtime
values and follower/passive overrides. Native built-ins now apply matching
Bored Event Chance / Combat Bark Cooldown pairs: Default30/30, LocalLLM30/100,
Follower50/20, Passive5/120 (percent/seconds). Existing profiles are not rewritten;
these are applied only by choosing a preset. Global enable switches remain
unchanged. Extended the existing four-preset test loop; 793 server checks pass.
This closes the built-in bored/combat values gap, not quest comments, physical
diary or Quickstart presentation. No game launched or controlled.


### NPC Bored Event override and Context audit (2026-09-08)

NPC override catalog now exposes Bored Event Chance as integer0-100. The same
resolver consumed by the bound-actor request applies NPC0 over Core100. Removing
the override restores inheritance; ordinary saves preserve it. 794 server checks
and the existing management HTTP suite passed (save0, ordinary save, remove,
reject101). Native browser add/edit/cancel/remove tests passed1280/390 without
private writes; populated screenshots inspected at both widths. All809 deployed
server files match with private/access checks unchanged. Whole NPC editor visual
parity remains open; this is proof for the new supported override only.

The Context height audit confirmed that reference metadata_json_editor.php
includes CONTEXT_HISTORY_DYNAMIC_PROFILE in its intended Context group, but
core_profiles.php filters through chimPrismaProfileSyncableMetadataKeys. Therefore
the live omission does not establish that the feature is unsupported by Herika.
Lorkhan retains the working control; no field was deleted to force a screenshot
height. The full Context rendering difference remains explicitly open.


### Quest Comment policy foundation (2026-09-08)

Current reference Core editor exposes QUEST_COMMENT plus discrete chance choices
10/25/50/75/100. Lorkhan's existing journal signature scan sends only narrator
event candidates; it is not a per-Core NPC quest-comment consumer.

Added separate server-owned `quest_comments` policy (enabled false, chance10),
Core inheritance/source ownership, strict discrete-choice validation, older
global document normalization, and named Core/global preset retention. Explicit
false overrides global true. It is intentionally absent from strict client
controls and no UI is exposed yet. 800 server checks passed, including policy
ownership, preset false/25 retention and invalid0/101/string/null rejection.

Next: bind a bounded journal update to its actual NPC responder, persist the
observation independently of commentary, prevent initial-load and repeated
signature comments, preserve narrator routing/cooldowns, and connect the actual
Core Quest switch/chance selector. Source/update text must reach the resulting
comment prompt; do not substitute a generic journal-changed message.
Built-in enabled values and portable forms must follow the completed consumer.
No deployment or game launch for this preparation checkpoint.


### Core Quest controls and journal commentary checkpoint (2026-09-08)

Client `3e7d95f`: closed quest_event request with the actual changed journal text,
known stage only, frozen NPC/session/generation and single-consumption callback.
Existing narrator quest routing keeps precedence when enabled; otherwise an
eligible selected NPC uses its Core Quest Comment policy. Busy/no-target cases
do not synthesize a substitute speaker. Observations are persisted as quest
events even when Core commentary is disabled; comment prompts are not counted
as player speech and cannot request actions.

Core editor now contains the reference Quest group, Quest Comment switch and
10/25/50/75/100% selector, Copy to all, portable settings and named preset support.
Follower enables the switch; Default/LocalLLM/Passive disable it, preserving the
existing chance. Help text states the actual independent narrator routing.

Evidence: 800 PHP checks, 72 Lua checks, Windows engine build and native/Beast
tests passed. Management HTTP save/import25 and reject30 passed; full database
integration verified enabled100, disabled, stable retries and exact quest text
in eventlog when commentary is disabled. Migration/durable checks passed.
Protocol tooling verified103 files/38schemas/65fixtures without jsonschema.

Actual hub reference/native desktop width411/padding6px12; native860/390 width
492/298. Paired desktop screenshots inspected, then measured and corrected the
checkbox's reference inline baseline, margin and transform. Final desktop and
native390 off75 screenshots inspected. All chance choices, off-state value
retention, Copy cancellation and mocked409 with numeric75 passed at all widths
(`quest-card-ui-final.txt`). No private profile writes were used in browser proof.

Client deployed executable+3Lua files with hashes verified and Config preserved;
rollback local Temp `lorkhan-quest-rollback-d86vvzwf`. Server810 runtime files
matched, no extra/old paths, private403/unpaired401 checks passed. No game launched.

Still open: end-to-end in-game acceptance, narrator's changed-text prompt parity,
NPC quest override exposure, broader speaker-selection behavior and full Core
editor comparison. This does not close physical diaries or other matrix rows.


### NPC Quest override choices (2026-09-08)

NPC settings now expose Quest Comment and Quest Comment Chance using the same
resolver consumed by quest_event. Explicit NPC false overrides Core true. The
chance picker offers only10/25/50/75/100, stores integers and displays percent
labels; returning to a boolean field rebuilds On/Off options correctly. Removing
the leaves restores Core inheritance. Raw JSON rejects non-choice values.

801 server checks passed; existing HTTP suite verified false/25 round-trip,
ordinary-save preservation, removal, invalid30 and string-false rejection.
Native browser1280/390 verified choice-to-boolean switching, raw JSON rejection
and removal without writes; both populated screenshots inspected. All810
deployed runtime files match; private/access checks passed. This closes the
NPC quest-override exposure gap, not whole-editor visual parity. No game launch.


### Observed quest text in narrator and NPC prompts (2026-09-08)

Client `b133b4b` shares the bounded complete-line formatter with narrator quest
candidates, retaining the text through the queue and context request. Server
PromptAssembler now preserves that observation beside the configured narrator
instruction. Also added the missing NPC quest automatic cue so the quest update
is scene context rather than player speech. Existing narrator templates without
an observed quest marker remain unchanged.

72 Lua checks and803 server checks pass, including retained journal text, bounded
queue input and custom narrator instruction preservation. Full integration and
migration/durable checks passed. Four Lua deployment hashes match, Config stays
unchanged, rollback local Temp `lorkhan-narrator-quest-rollback-vt0524yi`. All810
server runtime hashes and private/access probes passed. No engine rebuild or
game launch. Actual in-game acceptance and broader narrator speaker-selection
parity remain unverified; no whole-page completion claim.


### Quickstart visible Setup and Local LLM form (2026-09-08)

Reference `529364c`, `ui/quickstart.php` Setup sibling section and its scoped CSS.
Added the Default / Local LLM radio cards, nested server/model/URL editor, scope
cards, Advanced key/timeout/streaming controls, connection test and four local
model recap cards. Used the counterpart's actual structures and rules, with gold
branding. The existing protected key endpoint never echoes a saved key. Hidden
Local controls are disabled; returning to Local preserves drafts. Server-type
selection changes the draft port while retaining the entered host and path.

Save now composes the managed connector/default routing transaction with the
selected Core built-in, selected speech routes and player rename. A stale Core,
routing fingerprint or player revision rolls back database changes. Optional key
storage remains the pre-existing separate private autosave, not part of database
rollback. Default affects the selected Core settings; it does not reset global
behavior. Local 'all' additionally routes existing supported background consumers.
Descriptions explicitly state this current scope.

Tests caught and fixed the required `badge:` prefix for the private credential
reference. Browser submission also exposed an existing all-empty key queue race:
requestSubmit was called during the original submit event's microtask checkpoint
and was suppressed by Chrome. Yielding one task before resubmission fixes it.

Evidence:
- 803 server checks and PHP/JavaScript syntax checks pass.
- Existing management HTTP suite passes (`quickstart-setup-http-2.txt`): invalid
  timeout, stale player rollback, full save/reload of model/timeout/scope/streaming,
  and stale form rejection. Updated its existing HTML parser to honor checked
  radio buttons. No new test file.
- Integration vertical slice, 173-relation inventory, migrations and durable job
  checks pass (`quickstart-setup-integration.txt`).
- `quickstart-setup-proof.cjs` compares reference/native Default and expanded Local
  at 1280 and 390. Actual deployed controls exercise both test failure and success
  using mocked responses, keyboard arrow selection, retained drafts, model recaps,
  and full Save button submission/payload using a mocked form receipt. No live
  provider or private settings writes. MiniMe automatic POST was blocked.
- Default and expanded desktop images and expanded mobile images were inspected.
  Section widths match (980 desktop / 370 mobile); native has explicit routing
  scope help and a loopback warning. Sticky nav overlays the top of both mobile
  element captures; these are control-layout evidence, not whole-page screenshots.
- Deployed runtime has 811 files with exact source hashes, no extra/old paths;
  private routes 403, unpaired session 401. Private config, credentials and voice
  contents preserved. No game launched or controlled.

Still OPEN, not product exceptions: global built-in behavior preset application,
Core preset effects on all counterpart default profiles, Windows/WSL address
shortcuts, Player2 switching/lock states, exact recap text/general connectors,
empty-installation provisioning and final complete Quickstart/hub comparison.
The new controls are working progress; this does not close Quickstart or the
all-pages goal. GitHub server workflow remains disabled.


### Quickstart Windows / WSL address shortcuts (2026-09-08)

Added the reference's two Server URL buttons and matching stacked mobile layout.
Reference reads DwemerDistro's Network/HOST_IP and Network/WSL_IP records. Native
has no such shared database ownership, so it reads the local WSL network mode,
default route and interface addresses with fixed, bounded OS commands. No remote
probe, configuration write or dependency on Herika's database. NAT uses the WSL
gateway; mirrored mode uses Windows loopback. Unknown/unavailable host discovery
leaves the shortcut disabled with an explanation. Stored connector URLs stay
untouched; only a new unsaved setup defaults to the detected Windows host.

The buttons preserve scheme, explicit port, path and query, and rebuild an invalid
URL with the selected server's default port. Loopback/WSL-self warnings reflect
mirrored networking. Unit cases cover NAT, mirrored, unknown, missing and invalid
route data; 808 checks pass. Full management HTTP suite passes
(`quickstart-network-http.txt`). Browser `quickstart-network-proof.cjs` checked
both live discovered buttons, custom HTTPS URL preservation, invalid URL recovery,
warning states, model recaps, keyboard and mocked test/save flows at 1280/390.
Desktop/mobile rendered images were inspected against the unchanged reference.
No live model calls or private browser writes; no game launch or control.

Deployment verified all 811 runtime hashes, no extra/old paths, health, 403 private
routes and 401 unpaired session; configuration/credentials/voice content retained.
This closes the address-shortcut gap in the preceding checkpoint, not the full
Quickstart row. Global preset behavior, all default profile effects, Player2,
recap/general connector details and empty provisioning remain open.


### Quickstart Core preset scope (2026-09-08)

Current reference `chimSettingsPresetApplyProfiles` iterates every Core Profile,
not only the selected/default route targets. Native Setup now applies its existing
Default or Local LLM Core preset to every non-deleted Core in the selected
installation. This corrects the narrower scope introduced with the visible panel.
Connector assignments and prompts remain owned by each profile; separate local
routing still targets the selected/default NPC/Narrator profiles. NPC overrides
are not rewritten. Global built-in behavior remains an explicit separate gap.

The Quickstart snapshot now includes all Core IDs/revisions. Both Default and
Local submit it outside the disabled Local fieldset. Applying locks the scoped
profiles, checks that fingerprint, then composes the preset revisions with the
existing connector and player transaction. A non-default Core edited after page
load invalidates the save. Failed later validation rolls back every preset
revision. Scope copy on the cards and profile selection explains the broader
operation before Save. Older open pages without the new fingerprint must reload.

Evidence: 808 checks and PHP/JS syntax pass; full HTTP suite passes
(`quickstart-core-preset-http.txt`). Existing integration fixture expanded to prove
all three Core targets, retained prompt/connector ownership, concurrent non-default
edit rejection and unchanged state after rejection. That fixture now uses the
valid Core document schema rather than the old route-only stub. Full integration,
173-relation inventory, migration and durable-job suite pass
(`quickstart-core-preset-integration-2.txt`).

Deployed browser proof `quickstart-core-preset-proof.cjs` compares Default/Local at
1280/390 and checks keyboard selection, address helpers, mocked connection
failure/success and the actual Save button payload's new fingerprint. Native
Default desktop and Local mobile scope text were visually inspected. No private
browser saves or live provider calls. All 811 deployed files match source; no
extra/old paths, private routes 403 and unpaired session 401. No game launch.

Remaining Quickstart work: global built-in switches/context, Player2 and its lock
states, recap/general connectors, empty provisioning and full final page closure.
The all-pages goal remains active.


### Quickstart shared global preset switches (2026-09-08)

Ported the existing native consumers that map directly to reference Default /
Local built-ins: automatic profile backfill on/off, relationship updates on/off
with 50/0 percent chance, timestamp headings off for both, and ground/inventory
item descriptions-only off/on. The new shared GlobalSettingsPreset method retains
all connector bindings, blacklists, endpoints and unrelated client controls.
Quickstart saves these global values in the same transaction as all Core presets,
local routing and player changes. Existing global rows are locked before checking
the Setup fingerprint. No unrelated sidecar documents are created or rewritten.

UI scope copy states Default enables profile backfill and relationship updates;
Local explains what it disables. No deployed private configuration was changed
merely by opening the page or deploying this code.

Evidence: 813 checks pass, including Local/Default values and preservation checks.
The existing HTTP suite verifies saved Local globals, a subsequent Default save,
and retained system routing (`quickstart-global-http.txt`). Full integration,
173-relation inventory, migration and durable-job checks pass
(`quickstart-global-integration.txt`). Deployed browser counterpart checks at
1280/390 pass (`quickstart-global-proof.txt`); Default desktop and Local mobile
copy was visually inspected. Provider tests and Save receipts are mocked in that
browser proof; actual saves are confined to the disposable HTTP test database.
All 811 runtime hashes match, private routes remain 403, unpaired session 401,
and private configuration/credentials/voices are preserved. No game launched.

This closes only those shared global switches. Memory summary/embedding defaults
still require native provisioning (the validators require an actual connector
and MiniMe endpoint), not blindly enabling an unbound policy. Full context preset
selection, missing connector-availability switches, Player2 and final complete
Quickstart comparison remain open. Excluded release features are not reintroduced.


### Quickstart memory policy defaults (2026-09-08)

Completed the Default/Local preset gates for native memory summaries and MiniMe
semantic recall. Default enables both; Local disables both while retaining saved
connector/endpoint, intervals and timeouts. Only an unbound summary policy adopts
the selected Fast connector, and only an empty MiniMe endpoint gets the existing
DwemerDistro default http://127.0.0.1:8082. No connector catalogue, TTS settings or
configured provider content is replaced. Saving does not probe either provider or
queue historical memory backfill. Subsequent enabled runtime work follows the
existing memory policies. Deployment and GET alone do not enable anything.

Both policy IDs/revisions now participate in the Setup fingerprint and row locks;
saves remain in the profile/global/player transaction. Scope copy states the memory
behavior before saving. Reference CORE_CONNECTOR_SUMMARY_ENABLED and memory
embedding switches are the source; other absent connector availability switches
remain separate, not silently considered implemented.

Evidence: 816 checks pass, covering configured binding retention, Local disable,
and empty binding provisioning. Full HTTP suite verifies saved Local/Default
policies and unchanged provider/endpoint (`quickstart-memory-http.txt`). Full
integration, schema inventory (173 relations), migrations and durable-job checks
pass (`quickstart-memory-integration.txt`). Deployed 1280/390 browser comparisons,
keyboard/input/helpers and mocked test/save flows pass (`quickstart-memory-proof.txt`).
Updated Default desktop and Local mobile copy was visually inspected. All 811
runtime files match source; private-route/auth checks pass and private data is
preserved. No real provider test, private browser save or game launch/control.

Still open: context preset selection, other connector-availability feature gates,
Player2, recap/general connector details, empty-installation setup and complete
Quickstart/all-page closure. The overall goal is not complete.


### Quickstart shared context selections (2026-09-08)

Mapped reference Local's explicit prompt-context allowlists to existing native
consumers. Local omits points of interest, skills, nearby actor profile/appearance/
equipment and ground item prose; retains world/knowledge, nearby actors/items and
nearby activity; groups duplicate items. Default restores those shared optional
details and leaves duplicate grouping off, matching reference's default_enabled
false for that option. User blacklists and separate history/memory selections are
not conflated with character subsections.

Evidence: 819 checks pass. The existing populated context fixture now assembles
both presets through the real PromptAssembler. Local omits the actual door name,
guard biography, saber, skill sentence and mushroom prose; retains location,
activity, item name and responder identity. Default restores the omitted details.
The existing HTTP suite checks persisted Local/Default POI, nearby summary,
activity, description and grouping values (`quickstart-context-http.txt`). Full
integration, 173-relation inventory, migrations and durable jobs pass
(`quickstart-context-integration.txt`). Deployed all 811 runtime hashes match;
private routes/auth pass, configuration/credentials/voice content preserved.

No markup/style changes in this checkpoint, so no new visual parity claim. The
prior Default/Local visual and interaction states remain the presentation baseline.
No game launch/control or real-provider/private-browser save was performed.
Remaining: native equipment/inventory is one switch while reference separates
them; other character/general/appearance fields and connector availability remain
open, alongside Player2 and complete Quickstart/all-page closure. This shared
mapping is progress, not complete context parity.


### Separate equipment and inventory context controls (2026-09-08)

Replaced the combined checkbox with independent `<equipment>` and `<inventory>`
cards, using the reference labels/descriptions and existing context-card geometry.
Prompt assembly now gates equipped items and inventory independently for player
and target NPC state. Quickstart Local keeps equipment and omits inventory;
Default enables both.

Saved global documents, named presets and frozen prompt snapshots using the old
combined key are normalized to both new booleans with the same value. Ambiguous
mixed old/new documents are rejected. Already-open legacy forms retain their
combined checked behavior; new saves use only the two canonical fields. No live
configuration rewrite or database migration required.

Evidence: 828 checks pass, including all four checkbox combinations for actual
player/NPC prompt text and legacy false snapshots/documents/presets. Full HTTP
suite passes (`context-split-http-2.txt`), with the exact context control count
updated from 33 to 34 and Local/Default independent values checked. Integration,
173-relation inventory, migrations and durable jobs pass
(`context-split-integration.txt`).

`context-split-proof.cjs` opens Context & Knowledge on both deployed servers at
1280/390, checks independent mouse/keyboard states without saving, then captures
matching equipment-on/inventory-off states. Reference desktop and final native
desktop/mobile images were inspected. The two cards match in text and structure;
the complete Appearance / State group still differs and remains OPEN. Initial
reference captures timed out because its role=tab Context panel was hidden; the
final proof explicitly selects that tab. No inferred visual success from those
failed captures.

All 811 runtime hashes match source; private routes 403, unpaired session 401,
configuration/credentials/voices preserved. No provider call, private browser save
or game launch/control. Remaining appearance work includes separate activity and
condition, target equipment, spell/effect choices and other context fields, plus
Player2 and whole-page closure. The overall goal remains active.

### Context group placement and shared wording (2026-09-08)

Moved the existing appearance selector to the first card in Appearance / State,
matching the reference grouping. Shared Character, Nearby Actor and Nearby Item
labels and descriptions now use the reference wording. Equipment precedes activity
in Nearby Actor Details. All 34 canonical controls retain their values and save
behavior; this checkpoint changes presentation only.

Evidence: PHP lint and 828 server checks pass; management HTTP forms pass
(context-labels-http.txt). context-labels-proof.cjs checks 1280/390 layouts and
independent equipment/inventory mouse and keyboard states without saving. Desktop
Character and Appearance captures were inspected, along with both Nearby Item
captures and the native mobile Character capture. Nearby Item card geometry and
wording match; its saved checked values differ between installations. The mobile
element capture has the fixed navbar over its first heading and is not evidence
of full-page visual closure. No horizontal overflow was reported by the driver.
All 811 deployed file hashes match; private routes return 403 and unpaired API
requests return 401. No private settings save, provider request or game control.

Character and Appearance groups remain OPEN: combined mood/goals and relationship
controls and missing reference fields still need actual consumer mapping. In
particular, npc_current_state currently gates the target NPC's entire actorStateXml
(including items and magic), while player state bypasses that master gate. Do not
replace it with activity/condition toggles until reference actor ownership and
existing saved false behavior are traced. No dummy controls were added to mimic
the reference's card count. Overall page-by-page parity remains incomplete.
### Context ownership audit and release exceptions (2026-09-08)

Source inspection at reference 529364c4 establishes the following next work.
This is source evidence, not browser or game acceptance.

| Reference control | Authoritative source | Lorkhan mapping / required action |
| --- | --- | --- |
| equipment | lib/data_functions.php buildDynamicBiography reads FOLLOWER_CONF HERIKA_NAME metadata | Speaking NPC equipment; native targetState is the responder snapshot, not automatically the CHIM dialogue target. Existing shared player/NPC switch is not full ownership parity. |
| target_equipment | lib/data_functions.php lines 7500-7537 separately resolves DIALOGUE_TARGET through NpcMaster | Trace actual recipient identity for directed/rechat turns before introducing the selector. Do not label playerState as the recipient for every turn. |
| activity | lib/data_functions.php line 7297 emits activity from speaker metadata | Separate the actual activity consumer from npc_current_state master gating. |
| condition | lib/data_functions.php lines 7425-7432 emits condition | Native observed stats already provide health/magicka/fatigue. Gate condition independently without suppressing equipment or identity. |
| storyline_starring | lib/data_functions.php reads sneq_quests for extended_data starring_in_quest | EXCLUDED: belongs to the explicitly excluded AI Quest Manager, not ordinary observed Morrowind journal updates. |
| quest_topics | same quest builder reads quest_data topics/giver for the starring NPC | EXCLUDED for the same release boundary. Do not add an inert card or reintroduce the quest manager. |

Reference defect found: lib/settings.php uses current_activity/current_condition
as catalog IDs, while buildDynamicBiography emits activity/condition XML tags.
processor/misc.php chimApplyPromptContextOptionsToSystemPrompt passes catalog IDs
directly to chimRemovePromptXmlBlock. Consequently that path does not remove the
emitted tags when these selectors are disabled. Match the visible labels and
intended independent behavior in Lorkhan; do not reproduce this mismatch.

Before removing npc_current_state, preserve the legacy false snapshot semantics:
it currently suppresses all target actorStateXml, including identity, equipment,
inventory and magic, while player state bypasses it. A two-boolean replacement
alone would expose data previously excluded. Keep this conversion explicit and
covered with existing prompt fixtures, including frozen snapshots and presets.
No runtime changes or new validation claims in this audit checkpoint.
### Independent Goals and Relationships selectors (2026-09-08)

Character Subsections now has separate goals and relationships cards, with reference
labels/help and relative ordering. Allowed moods/emotes and notes retain separate
functional selectors instead of being coupled to those fields. Existing combined
saved settings, named presets and frozen snapshots expand to equal booleans; mixed
legacy/new documents are rejected. Already-open checked legacy forms map to both
new controls. No private configuration rewrite is required.

852 server checks pass, including independent actual prompt content and legacy
true/false snapshots. Full integration, migrations and the 173-relation inventory
pass (profile-context-integration.txt). Browser proof profile-context-proof.cjs
checks independent mouse/keyboard states at 1280/390 without saving; reference and
native desktop Character groups and native narrow capture were visually inspected.
The new cards match reference wording/structure; the full group remains OPEN for
factions, RPG skills, memory/group mapping and remaining native-only controls.
The narrow element capture's heading is overlapped by the fixed navbar, as before;
it is not a full-page visual completion claim.

Deployment preserves private configuration, credentials and voices; all 811 runtime
hashes match and private/unpaired access checks remain 403/401. No game or provider
calls. HTTP form suite passed before the additional mixed-selection persistence
assertion; final result is recorded below after that assertion is run.
Final mixed-selection save/reload HTTP assertion passes (profile-context-http-final.txt).

### Observed RPG Skills context card (2026-09-08)

Added the reference rpg_skills card after narrative skills, with matching label and
help. The OpenMW adapter already sends all 27 TES3 skills as base/modified values;
PromptAssembler now renders this selected speaker context using the reference
Novice/Apprentice/Adept/Expert/Master thresholds (25/50/75/100). Categories and names
use TES3 Combat/Magic/Stealth skills instead of Skyrim-only skills. Values are
allowlisted, finite numeric observations only; no invented skills or empty section.

Default preset enables this independent card; Local disables it. Legacy documents,
presets and frozen snapshots lacking the field normalize to false, retaining the
previous omission of observed skills. No live saved-settings rewrite. The existing
narrative skills selector remains independent.

858 checks pass, including all proficiency bands, modified-over-base precedence,
unknown/malformed omission, legacy/disabled output and actual Default/Local prompt
content. HTTP forms (37 unique context controls), integration, 173-relation inventory,
migrations and durable jobs pass: rpg-context-http.txt and rpg-context-integration.txt.
Read-only rpg-context-proof.cjs checks keyboard/mouse at 1280/390. Both desktop
Character groups and native narrow capture were inspected; shared card wording and
structure match, while full-group placement still differs due to missing controls.
Fixed-header overlap in the narrow element capture is not whole-page proof.

All 811 deployed runtime hashes match; private routes 403, unpaired API 401;
configuration, credentials and voices preserved. No game or live-provider calls.
Character group remains OPEN for faction and memory/group consumers and native
extra controls; quest-manager fields remain release exclusions. Goal still active.
### Faction membership selector (2026-09-08)

Added groups after basic_summary with the exact reference label/help. The first
three desktop Character rows now have the same controls and order. Observed TES3
faction IDs are bounded/deduplicated and negative membership ranks omitted. The
responder primary faction is removed from generic current_state output, so disabling
groups does not leak it there. The player's separate identity is unchanged.
Legacy documents derive groups visibility from their current-state choice; enabled
legacy state now includes all observed memberships, not only the primary faction.
Default preset enables groups; Local disables it. No private settings rewrite.

862 checks pass with duplicate/negative-rank cases, disabled-primary leak protection
and legacy visibility. HTTP forms (38 unique context controls), full integration,
173-relation inventory, migrations and durable jobs pass (groups-context-http.txt,
groups-context-integration.txt). Read-only groups-context-proof.cjs exercises the
checkbox with mouse/keyboard at 1280/390. Both desktop groups and native narrow
capture were inspected. No horizontal overflow; narrow element capture retains
the previously documented fixed-header overlap and is not full-page proof.

Deployment preserves private data and all 811 runtime hashes match; private 403,
unpaired 401. No game or live-provider calls. Faction CONTENT parity remains OPEN:
Herika resolves description-backed display names; native output currently uses
observed TES3 record IDs. This is a remaining implementation task, not a permanent
product exception. Lower-row memory/group and native extra controls also remain
open, alongside the larger page matrix. Goal active.
### Core profile group prompt selector (2026-09-08)

Reference main.php lines 2385-2386 wraps PROFILE_PROMPT in group; core_profiles.class.php
sets it from the Core profile prompt field. Native already consumes that field as
core_profile_instructions. Added the matching group card and an independent gate
on that existing content without replacing prompts or connector ownership. Legacy
settings retain inclusion; Default enables it and Local disables it. The first
three Character rows remain aligned; lower rows still differ.

868 checks pass, including enabled/disabled/legacy Core text and preserved identity.
HTTP forms now verify 39 unique context controls; full integration, 173-relation
inventory, migrations and durable jobs pass (coregroup-context-http.txt and
coregroup-context-integration.txt). Read-only coregroup-context-proof.cjs exercises
mouse/keyboard at 1280/390. Both desktop Character groups and native narrow capture
were inspected; matching label/help and card structure, not full-group closure.
Narrow element capture has the documented fixed-navbar overlap. All 811 deployed
runtime hashes match; private 403/unpaired 401; configuration/voices preserved.
No game or live-provider calls.

Important remaining mapping: middle_term_memory is NOT solely Background Life.
Reference main.php lines 2394-2403 reads the latest NPC extended_data digest, excluding
Narrator. service/processors/middleterm/cmd/generate.php accumulates it from scoped
memory_summary records plus prior digest. Native scene selection/model summaries
are not automatically the same artifact. Keep this requirement OPEN; do not omit
its card as an excluded feature or alias it to all memory retrieval. Faction
names/descriptions and native extra controls also remain open. Goal active.
### Cumulative NPC memory digest implementation boundary (2026-09-08)

Read-only runtime-path audit confirms this remains a real missing counterpart.
Reference service/processors/middleterm/cmd/generate.php takes the previous NPC
digest and newer scoped memory_summary records (up to 100), asks for an updated
continuous summary, and stores it keyed by the new game-time high-water mark.
main.php injects only the latest digest into middle_term_memory for an NPC, not
Narrator. This is not excluded merely because the connector also serves Background Life.

Native MemorySummaryJobHandler supplies one frozen memory record to the model.
MemorySummaryRepository keys its output to that record/revision, and ProductRepository
replaces each retrieved candidate's content with its optional model summary.
PromptAssembler's mid_term_enabled filters tiers; consolidated recent scenes are
explicitly treated as short term. None of these paths implements the reference's
previous-digest-plus-new-summaries chain. Do not alias the new context card to the
whole memory section or to the existing mid-tier filter.

Required coherent implementation before this counterpart can close:
1. Persist versioned per-installation/playthrough/NPC digests with previous revision,
   source revisions and a deterministic progress cursor. Preserve all source memories.
2. Extend the existing durable worker/lease and frozen connector-policy pattern to
   generate the next digest from its prior revision and only newer scoped summaries.
   Skip Narrator; fence concurrent jobs and changed/deleted source revisions.
3. Select the latest valid digest separately from ranked scene memories; the
   middle_term_memory card controls only this prompt fragment. Generation policy
   remains distinct from context visibility, and disabling it never deletes data.
4. Expose the saved digest through the matching NPC memory reader/editor, with
   revision-aware save behavior. Do not add a nonfunctional checkbox first.
5. Extend existing unit/integration fixtures for two successive digests, no-new-input,
   duplicate jobs, scope isolation, disabled visibility and preserved scene/history
   context. Use mock generation; no game or paid-provider validation inferred.

This audit changes the next implementation from a checkbox alias to a complete
bounded digest path. No product code or runtime changed in this checkpoint; the
verified deployed baseline remains 8c8c21a. Goal and digest row remain OPEN.

### Priority diary acceptance refresh (2026-09-08)

Reran diary-backdrop-proof.cjs against the current deployed code at 1280/390.
Pending and playing closes passed: abort pending fetch, pause/release audio, clear
reading state, restore opener focus/body scrolling, and retain the dialog on
padding clicks. Editor backdrop cancellation discards its unsaved fixture text.
The driver reports zero real POST requests and mocks fetch/media only in its page.
Both freshly captured playing-state screenshots were inspected. This revalidates
the native reader states, not a new entire-reference comparison.

Current scripts/test/management_http.py lines 1063-1095 include cache miss/hit,
changed diary text, changed author, changed language, and deleted-entry refusal.
These ran in the current passing coregroup-context-http.txt suite; its coverage
supersedes the matrix's stale remaining-cache assertion. The old Temp file named
diary-cache-invalidation-tests.cjs is an editing script, not a standalone test;
it was inspected but NOT executed. No duplicate test insertion was performed.

Updated stale primary rows accordingly. Live paid TTS and observed in-game book
capture remain untested runtime limits, not evidence for or against page geometry.
No runtime code changed or redeployment was needed in this checkpoint. Whole-goal
completion remains unproven; outstanding configuration/editor rows remain open.

### TTS editor provider-field audit (2026-09-08)

Read-only live comparison used tts-current-audit.cjs and tts-fields-audit.cjs.
All non-GET traffic was blocked; password fields were cleared before capture.
Inworld reference/native full desktop images were inspected: shared action, name,
service, badge, fallback and provider grids align. Saved list lengths, names, model
choices and workspace values differ; they were not changed. Native collapsed
advanced/revision sections remain separate operational extensions, not evidence
that every provider editor is complete.

Switching services only in browser produced these visible-label sequences:
- OpenAI both: Name, Service, API Badge, Fallback Male, Fallback Female, Model Id,
  Instructions. There is no missing separate emotion checkbox in this reference.
- Azure reference: the shared fields, Fixedmood, Region, Volume, Rate, Countour,
  Validmoods. Native lacks Validmoods; the other visible labels/order match.

Concrete remaining Azure implementation: reference tts-azure.php parses the first
mood, filters through validMoods, falls back to default, then lets fixedMood override
it when constructing SSML. Native CloudSpeechConnectorProvider currently supports
only fixedMood. Add the matching multi-select with catalog validation, saved array
round trips, and actual per-utterance mood propagation before claiming this row
complete. Do not add an inert dropdown or assume a fixed mood is equivalent.
OpenAI dynamic emotion instructions are likewise a request-side question, not a
missing visible field. Current UI labels alone do not prove runtime provider parity.

No product code, saved settings, live-provider request or deployment changed in
this audit. Existing 8c8c21a runtime remains the baseline. Full goal remains open.
### Azure mood provider boundary in progress (2026-09-08)

Added bounded validMoods filtering to CloudSpeechConnectorProvider's Azure request
builder when explicit mood context is supplied. Rejected moods become default;
fixedMood retains precedence; SSML text/style escaping remains intact. No-context
requests retain prior behavior. 871 checks pass, including direct request-builder
allowlist, fallback and fixed-override assertions. This is partial implementation,
not editor completion: catalog/form multi-select, worker mood metadata propagation,
full reference emote normalization and final browser/persistence checks remain.
Do not expose or mark the Validmoods card complete until those paths are wired.
Not deployed in isolation; runtime remains the last fully verified baseline.
### Azure Validmoods form source (2026-09-08, not deployed)

Added the matching 18-option multi-select to the provider template and its catalog.
Options were read from the live reference meta__azure__validMoods select: angry,
chat, cheerful, customerservice, empathetic, excited, friendly, hopeful,
narration-professional, newscast-casual, newscast-formal, sad, shouting, terrified,
unfriendly, whispering, default, dazed. The reference uses multiple with default
browser size. Form parsing accepts bounded arrays and removes duplicates; JSON
connector validation rejects scalar/nested/unknown choices. Inactive provider
controls retain the existing disabled-draft behavior.

875 checks pass, including exact selected-array retention and invalid input cases.
Existing full management HTTP suite passes (azure-selector-http.txt); a focused
multi-select save/reload browser assertion and final visual comparison remain.
The template is not deployed yet: speech workers do not currently preserve mood
metadata. Repository speech job payloads can carry the optional mood without a
new dialogue table column, but DialoguePlanner/normalizer and streamed delivery
must preserve it first. Do not conflate player-selected input mood with NPC mood.
Remaining reference emote normalization, end-to-end mock proof and deployment are
part of the same unfinished Azure work. No private settings or game changes.
### Optional NPC mood through queued speech (2026-09-08, not deployed)

DialoguePlanner now validates and retains optional bounded mood text separately
from speech. CanonicalResponseNormalizer places it in the existing allowed line
metadata. Queued speech payloads retain it, and SpeechSynthesizeJobHandler validates
and passes it into the provider context. No database or public protocol migration.
878 checks pass, including canonical protocol validation, unchanged speech text and
malformed/oversize mood rejection. Full integration, 173-relation inventory,
migrations and durable jobs pass (azure-propagation-integration.txt).

Still incomplete: OpenAiCompatibleProvider currently validates text-only utterances;
its response schema/contract must accept optional mood, and streaming callbacks
must carry the correct utterance's mood before speech starts. The text-only
StreamingDialogueText API cannot simply attach the last mood found in the whole
buffer: one network chunk can contain multiple utterances with different moods.
Preserve ownership while splitting sentences, including translated/narration paths.
Current tests do not prove that end-to-end mood path yet. Azure editor browser
persistence/visual proof and reference normalization remain pending. Not deployed;
no live settings/provider/game changes. Whole goal remains active.
### Azure Validmoods rendered and deployed checkpoint (2026-09-08)

Supersedes the preceding not-deployed notes for this Azure change chain.
The optional model response contract accepts mood before text; sentence streaming
keeps mood ownership across multiple utterances in one network chunk. Queued,
inline and translated dialogue paths retain that metadata. Azure normalizes the
reference aliases and applies its valid-mood selection without altering text.
Text-only responses remain accepted. At the bounded 32-chunk aggregation limit,
a chunk covering different moods omits mood rather than applying the wrong one.
Narrator splits do not inherit the NPC's mood.

Azure editor comparison: matching visible field order, 18 options, multiple-select,
new-provider defaults whispering/default/dazed, and Allowed voice styles help.
Readonly reference/native comparisons at 1280 and 390 pixels exercised two-choice
selection, provider switch away/back, focus, and no horizontal overflow. Inspected
azure-final-1280-native.png and both azure-final-390 screenshots against the
reference; branding and stored connector content differ as expected. Existing
advanced/revision sections remain separate broader matrix work, not a declaration
that the entire connector editor is identical. No real connector saves or paid
provider calls were made against either deployed installation.

Evidence: 889 server checks; final integration vertical slice, 173-relation schema
inventory, migrations and durable jobs; full management HTTP suite including actual
isolated Azure two-choice save/reload. Logs: azure-stream-final-integration.txt,
azure-stream-final-http.txt; browser driver azure-final-proof.cjs (temporary local
artifacts). Final help-only edit followed by 889 checks and rendered comparison.
Deployment completed with configuration/credential/voice hashes preserved; verifier
reports 811 files, zero mismatches/extras/old paths and expected access protections.

Remaining runtime evidence: no live LLM schema acceptance or paid Azure audio test;
focused full worker mood capture is still outstanding (general integration is not
proof of that specific metadata path). Reference angry-volume/dazed-rate behavior
also remains to audit. These are not recorded as product exclusions. Other page
matrix gaps, cumulative NPC digest and profile/context work remain open. GitHub
server workflow stays manually disabled. No game launched or controlled.

### All-provider field audit and Service ordering (2026-09-08)

Readonly deployed editor audit switched every reference service and compared visible
field labels, help, control types and choice lists (tts-all-fields-audit.cjs/txt).
Service now preserves the exact 17 shared reference driver IDs in reference order;
additional existing supported drivers follow in Others. No driver removed or renamed,
and no saved connector altered. tts-service-order-proof.cjs checks actual deployed
reference/native option ID ordering. PHP lint and 889 server checks pass; deployment
preserved configuration, credentials and voices; 811 runtime hashes match.

Concrete remaining provider discrepancies (not product exclusions):
- Zonos lacks Dynamic Tones and Cached Voice Path controls; language/model defaults
  currently add en/default ahead of reference choices. Pitch/rate/CFG ranges and
  help differ. Trace reference emotion request generation and protected cache
  ownership before adding functional controls; never expose arbitrary local paths.
- Mimic3 lacks the visible Volume field. Reference tts/tts-mimic3.php's active
  TTS_IN_USE GET path ignores volume; only ttsMimicOld SSML reads it. Thus this is a
  presentation mismatch, not evidence of missing active reference volume behavior.
  Do not add a silently inert setting or claim real volume parity from a field.
- OmniVoice language list labels match. The pinned reference help claims preparation
  on save, but its save handler and connector create/update methods only normalize
  and persist metadata; they do not prepare languages. This is stale reference help,
  not a missing runtime feature. Native unavailable saved languages remain selected
  with an explicit warning instead of silently switching to the first available one.
- XTTS FastAPI display label differs from reference XTTS; additional native XTTS
  driver must remain distinguishable if label alignment is made.
- Chatterbox/XTTS/ElevenLabs boolean rows contain a hidden reference INPUT before
  the visible SELECT; the raw first-control difference alone is not a visual gap.
- Inworld optional workspace routing and Azure advanced endpoint help describe
  actual native behavior; Deepgram reference help says Model for Bitrate, which
  must not be copied as misleading help. xVASynth Morrowind example is intentional.

API Badge choices reflect different saved records, not a layout mismatch. Extra
revision/default/advanced editor surfaces still require their own interaction and
placement review; this audit does not declare the whole connector editor complete.
All broader matrix gaps remain active. No provider calls or game controls used.

### Zonos defaults and pitch checkpoint (2026-09-08)

Corrected new-connector defaults from literal model default/language en to
Zyphra/Zonos-v0.1-hybrid/en-us, matching reference conf/conf.sample.php and active
provider fallback values. The live reference's unsaved provider-switch DOM instead
selects its first options (transformer/af); this is not evidence those are configured
runtime defaults. Saved native values remain unchanged and remain selectable.
Expanded pitch_std maximum from 200 to 300 consistently in catalog, request builder
and displayed help. Other existing numeric bounds are preserved pending the full
Zonos editor/runtime alignment; this is not full Zonos parity.

891 checks pass including reference defaults and upper pitch acceptance. Both
1280 and 390 actual rendered editors inspected using zonos-fields-proof.cjs and
zonos-narrow-proof.cjs (temporary screenshots tts-fields[-narrow]-zonos_gradio-*).
Native selectors no longer add invalid placeholder defaults. Missing Dynamic
Tones and Cached Voice Path still shift the grid; do not count these views as
complete. Deployment preserved settings/credentials/voices and all 811 runtime
files match; no live generation, saved connector changes or game control.

Next Zonos dependency: native generation currently fills all eight emotion values
with 0.05. Reference getZonosEmotions reads eight bounded response_tone values,
then applies mood mapping. Native has neither the dynamic_tones configuration nor
response_tone contract/metadata yet. Cached-sample support also needs scoped
sample identity/hash and endpoint ownership before exposing its reference field;
current native uploads every synthesis. Both paths must be implemented with the
controls, not represented by inert UI or declared product exceptions.
Full management HTTP suite passed (zonos-default-http.txt); no paid provider test.

### Zonos mood fallback runtime foundation (2026-09-08)

Zonos now consumes the existing speech context mood rather than always passing
8 x 0.05 to Gradio. Mapping derives from reference getZonosEmotions, retaining its
emotion order, aliases, first pipe-separated mood, and absent/unknown/whispered
fallback behavior. Bounded UTF-8 mood validation occurs before uploads. The existing
streamed/queued mood path supplies this context; no new public schema or UI field.

893 server checks pass. A readonly isolated extraction of reference
getZonosEmotions (no reference bootstrap or provider execution) matched native
results for all 49 switch aliases plus five edge cases: 54 exact vector comparisons
in zonos-mood-differential.php. Local deployment preserves private data; verifier
reports 811 matching runtime files. No live Gradio synthesis or game validation.

This is the default mood-derived foundation for the still-missing Dynamic Tones
control, not implementation of model-provided eight-value tones. That configuration,
response contract, per-utterance streaming metadata and cached-sample editor remain
open. Page layout has not changed in this checkpoint. Whole parity goal is active.

### Zonos Dynamic Tones control and delivery (2026-09-08)

Added the typed dynamic_tones boolean and matching Dynamic Tones grid position
between Model/Language and Pitch Std. Default Disabled preserves mood-only behavior.
The selected actor connector's safe speech_style snapshot includes this flag;
enabled Zonos adds a bounded eight-value tones instruction. Optional tones precede
mood/text in the model schema and validator; text-only responses remain valid.
No change to public game schemas or spoken text. Internal per-utterance vectors
are validated, retained through planning, translation, streaming reconciliation,
inline speech context and queued speech payloads. Zonos consumes them only when
its option is enabled and applies reference mood overrides afterward.

Streaming extracts the complete tones prefix before emitting a first sentence.
Multiple utterances in one network chunk retain independent vectors; the bounded
final aggregate omits mixed vectors instead of assigning the wrong one. Validation
rejects extra/missing keys, strings, non-finite and out-of-range values. No fabricated
provider controls and no paid model/audio call.

Evidence: 904 server checks including on/off prompt instructions, schema acceptance,
invalid vectors, first-sentence prefix streaming, mixed utterance ownership and
planner text separation. Full integration vertical slice/173-relation inventory/
migrations/durable jobs passed (zonos-tones-integration.txt). Full management HTTP
suite passed including isolated dynamic_tones=true and pitch=300 save/reload
(zonos-tones-http.txt). Ref/native 1280/390 screenshots refreshed and inspected:
Dynamic Tones restores the missing grid row. Help omits the misleading reference
Default profile only clause because native connectors bind per profile.

Deployed with existing private settings/credential/voice hashes preserved; all 811
runtime files match. Existing saved Inworld configuration unchanged. No game launch.
Remaining: cached voice state/control, rate/CFG range differences, lower advanced/
revision surfaces, and the wider page matrix. Real LLM/Gradio acceptance and a
focused durable-worker tone capture remain unverified; general integration and
unit metadata tests do not substitute for those specific runtime checks. Goal active.

### Zonos cached upload and field checkpoint (2026-09-08)

Added automatic private upload records keyed by endpoint, sample basename and
SHA-256 sample bytes. Reuse checks the remote file with HEAD; expired/unavailable
cached files re-upload. Sample changes and endpoint changes do not reuse an old
record. Atomic best-effort writes avoid blocking valid speech on cache write failure;
concurrent uploads can duplicate work but cannot share an unrelated sample key.
Records live in the existing protected voice root's .zonos-cache directory. No
remote deletion, arbitrary local path input, credential access or saved profile edit.

Cached Voice Path now appears after Cfg Scale, restoring every reference Zonos
field label/order. Its value comes from the selected connector default sample's
actual upload record; NPC samples retain independent records. It is read-only,
not the reference's freely editable config string. This interaction difference
and whether a safe explicit clear action is needed remain in the editor review;
not declared a completed product exception. No fake populated state persisted.

906 server checks pass, including cache key confinement and sample/endpoint
invalidation. Full management HTTP suite passes (zonos-cache-http.txt).
Temporary zonos-cache-probe.py runs an isolated localhost fake Gradio and four
actual provider synthesis calls: first upload, reuse, changed-sample upload,
expired-remote upload. Three uploads/two HEADs and valid 100ms WAV outputs verified;
also checks all eight Dynamic Tones values in real outgoing JSON. No paid service.
1280/390 empty fields and 390 synthetic populated reference/native screenshots
inspected (zonos-fields-proof, zonos-narrow-proof, zonos-cache-populated-proof).
Synthetic population was browser DOM only, not production cache or saved settings.

Deployed; 811 runtime hashes match; private settings/credentials/voices preserved.
No game control. Remaining Zonos review: read-only cache interaction, rate/CFG
bounds/help and lower advanced/revision surfaces. Broader page matrix remains open.

### Core Profile identity card structural correction (2026-09-08)

Moved Effective Core Profile settings and sources below the edit form so Profile
Core follows the preset toolbar as in Herika. Diagnostics and revision history
remain available; no setting removed or form input renamed. Restored Name help,
Slot help/title and Profile Prompt help, reference compact identity label/hint
spacing, and 1.25 checkbox scaling. Shared subtitle now describes core identity
and runtime options. Slot text says Optional slot 1-4: do not claim the CHIM
Settings Wheel shortcut is implemented. The current client Interact profile list
selects actor_profile IDs; this is not proof of Core slot assignment parity.

906 checks and full management HTTP suite pass (core-identity-unit.txt and
core-identity-http.txt). Final CSS/help corrections followed by deployed browser
inspection and exact 811-file runtime hash verification. 1280 actual selected
editor views inspected; Profile Core is no longer displaced by diagnostics.
390 focused views compared: reference embedded layout clips its right editor to
a narrow column, while native stacks it. Do not reproduce reference overflow.
Browser actions were read-only, no save/provider calls; existing profile data and
private runtime files preserved. Temporary evidence: core-current-audit,
core-editor-audit, core-editor-top-proof, core-identity-focused-proof.

Still open: extra Long Term Memory/Conversation grouping, diary toggle mapping
and missing Physical Diary capability, full connector summaries and inline editors,
metadata/override composition, and the complete Core editor interaction audit.
This first-card correction does not establish complete Core Profiles parity.
Whole page goal remains active; no game launched or controlled.

### Core Profile toggle grouping (2026-09-08)

Top Profile Core groups now follow Profiles & Memories, Diary, LLM. Moved the
existing Rechat enable control into Rechat settings, Long Term Memory into Context,
and Diary Generation/Diary In Context into Diary settings. All original field
names and values remain in the same edit form. Renamed Automatic Diary/Diary After
Waiting to the reference Auto Diary/Auto Diary Wait labels and matched the shared
Dynamic Profile, middle/short memory, and diary icons. No feature deletion or
silent default change. Missing Physical Diary remains an actual unfinished feature;
no inert placeholder added to force the reference's four-card diary layout.

Proof: 906 checks and explicit PHP template lint pass. The deployment preflight
caught an initial syntax error before live files changed; fixed before deployment.
The existing HTTP label assertion was updated for the intentional Auto Diary rename;
full final suite passes (core-groups-verified-http.txt). A deployed before/after
FormData comparison found identical 41 submitted values (excluding CSRF), and all
four relocated checkbox controls updated On/Off and restored their initial values
without saving. Temporary core-groups-form-proof/core-groups-toggle-proof scripts.
Desktop and focused 390 screenshots inspected after final icon updates; reference
narrow clipping is not copied. Runtime verifier: 811 files, zero differences, private
paths protected. Existing settings/credentials/voices preserved. No game activity.

Remaining: full memory/digest semantics, Physical Diary, Core slot client parity,
connector editor/modal mapping and the complete page matrix. Current grouping
matches the shared hierarchy, not every remaining feature or editor state.

### Core Profile embedded connector editors (2026-09-11)

Restored a clean worktree from main d789ae7 after the earlier worktree was removed.
Core Profile Standard/Fast/Powerful/Experimental/Fallback/Diary LLM and TTS choices
now open the existing connector editor in place. Empty/default routes disable Edit.
The editor-only view removes duplicate navigation, headings and connector lists;
save redirects preserve the selected connector, installation and partial mode.
No connector persistence implementation was copied or forked. TTS clone/delete
and runtime selection stay on the full connector page, not inside a profile draft.

Changing routes or closing a dirty editor requires confirmation. Profile submission
is stopped until open connector changes are saved or discarded. LLM Save-and-Test
reports its successful save to the parent; failed requests do not clear dirty state.
Save All does not silently submit child forms: save connector changes explicitly.
This is a documented remaining interaction difference from Herika's bulk save.
Dialogue Prompt remains the existing separate prompt surface.

Proof: 906 server checks; PHP and JavaScript syntax; the complete management HTTP
suite, extended for actual LLM/TTS partial-editor saves and redirects. The initial
HTTP failure was a test selecting the full page before asserting partial redirects;
corrected and rerun successfully. Browser GET-only checks at 1280/390 verified both
editor types, hidden duplicate headings/lists, dirty close refusal and restoration,
unchanged parent form values, zero horizontal document overflow and no script errors.
Temporary evidence: lorkhan-inline-proof.cjs, inline-*-1280/390.png and
lorkhan-inline-http-final.txt. Browser plugin initialization timed out; standalone
local Chrome automation was used. Claude fallback from this goal remains in effect.

This closes missing embedded connector access, not the entire page-parity goal.
The earlier matrix's uncompleted NPC/profile composition, Quickstart/voice/STT,
playthrough/database interactions and separate runtime feature gaps remain open.
No game launch/control, paid synthesis or live provider test was performed.

### Core Profile Save All integration (2026-09-11)

Supersedes the separate-child-save limitation in the preceding checkpoint.
Save All now validates every dirty embedded LLM/TTS editor before writing, saves
through the existing authenticated revision forms, verifies each redirect receipt,
then submits the profile. Failed saves retain the child and parent drafts and stop
profile submission. Completed connector saves remain completed if a later save
fails; this is an ordered workflow, not a cross-record database transaction.
Identical edits to one shared connector are saved once. Conflicting drafts for the
same connector stop before any write. Controls remain inert while saves are pending.

Evidence: PHP/template and JavaScript syntax, 906 existing server checks, and
GET-only live-page browser tests with intercepted writes in lorkhan-save-all-proof.cjs.
Success: two connector saves precede one profile submit. Failure: one rejected
connector save, zero profile submits, draft retained. Conflict: zero writes.
Identical shared drafts: one connector save and one profile submit. All four passed.
These mocked browser responses do not claim live database writes; the preceding
management HTTP suite established the real revision-form redirect contracts.
No game activity or provider requests. Remaining page matrix stays open.

### NPC Info equipment and metadata presentation (2026-09-11)

Compared the pinned Herika NPC Info markup and metadata disclosure rules directly.
Current Equipment now uses its framed slot/value layout instead of generic stat
pills. Lorkhan labels the group Recorded Slots: only exact-target observed slots
are shown, with no invented Skyrim/modded slot inventory or assumption that an
unrecorded slot is empty. Metadata now uses Herika's separate darker disclosure,
10px/12px heading padding, right-side rotating chevron, dividing border and 12px
body padding. Its read-only observation semantics are unchanged; editable general
NPC metadata remains unfinished and is not excused as presentation parity.

Evidence: 906 checks, PHP template lint, GET-only deployed NPC modal inspection at
1280/390, exact disclosure style measurements, no overflow, and keyboard close.
The live NPC had no equipment; a separate PHP-rendered template fixture verified
two populated slots, escaped text and long-label wrapping at both widths. Images
were inspected. Temporary evidence: npc-metadata-parity.cjs, npc-info-fixture.php,
npc-equipment-fixture.cjs and their screenshot outputs. No fixture data was written
to the live database. No game or provider activity. Full NPC editor/modal and
override-catalogue review remains open.

### NPC language and short-term summary overrides (2026-09-11)

Expanded the typed NPC override catalogue with Core Language, LLM Output Language
and Max Summaries. These use the same effective-settings and prompt consumers as
Core Profiles, now with explicit NPC precedence. The language switch asks the LLM
for a spoken-language code for XTTS/Chatterbox; it is not a general translation
switch. The editor explains this, the built-in-instruction language scope and the
1–50 short-term summary limit. Removal restores inherited behavior.

The picker now retains each choice's declared type instead of coercing every
choice to a number. Language codes and blank remain strings; existing quest chances
remain integers with percent labels. Missing language defaults remain blank/false.
No arbitrary extra metadata or global-only controls were exposed. Dynamic-profile
history still reads its Core source in the evolution worker and needs separate
runtime work before an NPC override would be meaningful.

Evidence: 907 existing-suite checks including NPC-resolved French instructions and
a two-summary cap in actual prompt assembly; complete isolated management HTTP
suite covering saved/preserved/removed overrides and rejection of invalid language,
boolean and summary-limit values. GET-only deployed browser edits at 1280/390
verified typed picker results, existing percentages, removal and invalid raw JSON;
no live writes. Temporary npc-language-overrides.cjs, screenshots and
npc-language-overrides-http.txt. No game launch or provider calls. Full catalogue
and page-matrix closure remain unfinished.

### Database maintenance and remaining runtime dependencies (2026-09-11)

Inspected Herika's Google Free STT button and pmstt addon: this is browser
recognition feeding speech into the game via PlayerSays, not a server STT driver.
A dictation-only test would not close that gap; the authenticated, target/session-
fenced Lorkhan bridge remains missing. Also confirmed the playthrough list already
has paging beyond 100, selected-ID lookup and existing HTTP coverage; the matrix's
older claim that list access is still missing is stale. Stored database snapshots,
switch/delete/rollback and snapshot timeline remain missing.

Database Manager now has the reference maintenance operation, VACUUM FULL ANALYZE,
behind an authenticated CSRF-protected POST and explicit Maintenance confirmation.
It targets only catalogue-quoted application tables in this connected database,
checks ownership, prevents concurrent execution and rate-limits starts to once per
minute. It never accepts a database name, table name or SQL from the browser.
Start/completion/failure are audited; errors expose no SQL or credentials. Current
HTTP execution is bounded to 25 seconds with a 3-second lock wait. Large-database
asynchronous execution remains unfinished rather than claiming full maintenance
parity from a small fixture. Failed runs may have compacted some tables; the UI
explains this and tells users to stop the game before explicitly running it.

Proof: 907 checks and the complete isolated management HTTP suite passed, including
real compaction, invalid-confirmation rejection, saved receipt and immediate-repeat
blocking. Deployed GET-only browser checks cover 1280/390, confirmation constraints,
and all outcome messages; no live maintenance was invoked. Temporary evidence:
database-maintenance-http.txt, database-maintenance-proof.cjs and screenshots.
Full SQL backups/import, automatic backups, database access/reset/version reset,
long-running maintenance and the broader matrix remain unfinished. No game control
or paid provider calls.

## Core Profile embedded Dialogue Prompt (2026-09-11)

- The Dialogue Prompt card now opens the selected prompt document in place, using
  the existing Default/Custom and player-mood form and revisioned save endpoint.
  The embedded view omits the separate prompt table, CSV and clone/delete tools.
- Save All includes the prompt draft. A failed or conflicting prompt save prevents
  profile submission and retains the draft. Successful saves update the expected
  revision, so another edit does not use a stale revision. Independent prompt Save
  leaves the unsaved Core Profile intact and clears only the prompt dirty state.
- Checks: 907 server checks; existing management HTTP suite including selected
  partial view, unknown-ID 404, actual JSON save and revision conflict; temporary
  browser mocks for Save All, conflict/draft retention, child Save and repeat save.
  Existing LLM/TTS success/failure/conflicting/duplicate tests still pass.
- Desktop and narrow embedded states were inspected; cramped partial-heading
  typography was corrected. Provider and production mutation requests were blocked
  during browser checks. This closes the inline prompt item, not the whole Core
  Profile or all-page parity goal.

## NPC diary and dynamic-profile history overrides (2026-09-11)

- Added the reference Diary Prompt, Diary Cooldown, Context History Diary Event
  Count and Context History Dynamic Profile Event Count to the existing typed
  NPC override picker. The catalog now exposes nineteen supported leaves.
- Diary controls use the established NPC `diary` owner, alongside Auto Diary
  switches. Ordinary saves preserve them; explicit removal restores inheritance.
  The editor also reads older settings-overrides values and normalizes them into
  that owner only on an explicit override submission. No bulk data rewrite occurs.
- Prompt text accepts newlines and enforces the backend's 8192-byte UTF-8 limit.
  Numeric limits remain 10–86400 seconds for automatic diary cooldown and 0–400
  history records; zero uses regular context history. Neither history setting
  enables diary or profile generation.
- Dynamic Profile History now resolves Core then NPC precedence into the actual
  generation queue. Only this history leaf is allowed in the NPC override editor;
  discovery defaults and the NPC's existing dynamic-field switches remain separate.
  The strict client controls projection is unchanged.
- Unit checks: 912 passed. The existing isolated integration fixture now proves
  NPC history two wins over Core history three in the queued job; the Narrator's
  zero-to-regular-history case remains covered. Existing database backup/restore,
  migrations and durable-job checks pass. Schema inventory was refreshed after
  its stale reference hash was detected, with 173 relations/1604 columns unchanged;
  a subsequent normal check matches.
- The existing management HTTP suite also passes: all four added overrides survive
  save/read, ordinary saves preserve them, explicit removal restores inheritance,
  and invalid text, ranges, types and discovery-switch submissions are rejected.
- Browser checks at 1280/390 covered text, UTF-8 bounds, zero/numeric drafts,
  edit/reopen, removal and invalid raw JSON; previous language, summary and percent
  controls still pass. No production POST or paid provider request was made.
- General observed actor metadata remains read-only; full metadata composition,
  per-NPC combat behavior and other unfinished matrix items are not closed here.

## Quickstart speech-service selection (2026-09-11)

- Compared the pinned Herika Quickstart source and rendered TTS/STT cards. It
  presents five TTS choices (OmniVoice, PocketTTS, Chatterbox, XTTS, Inworld) and
  two STT choices (Parakeet, Deepgram). It does not disable these options based on
  a service-availability probe. Earlier proposed availability gates were not
  established reference behavior and are not being added.
- Lorkhan now presents those same primary choices and groups, including services
  with no existing connector. Save creates a missing connector using the typed
  catalog defaults and credential references, or reuses existing saved settings.
  The form keeps additional custom connectors selectable under Saved connectors.
- Creation is installation-scoped, revisioned, transactional and serialized with
  default connector provisioning. Existing custom endpoints, API badges, options
  and revisions are not overwritten. The form does not install/start services,
  make synthesis/recognition requests or copy secret values into the page.
- Checks: 912 unit checks, full management HTTP suite, isolated connector-create
  and repeat-reuse fixture, full integration/schema/backup-restore/migration/job
  checks. The HTTP suite verifies actual selected UUIDs after service selection
  and rejects a service outside the Quickstart allowlist.
- Read-only browser comparisons at 1280/390 verified counterpart option labels
  and groups. Deepgram badge visibility and switching back to Parakeet were
  checked; passwords were blanked before screenshots and non-GET requests were
  blocked. Evidence: quickstart-services-proof.cjs and quickstart-{product}-{card}
  -{width}.png in the local temporary evidence directory.
- Player2 and remaining whole-page workflows are still open. This change closes
  speech-connector provisioning, not installation of external service processes
  or the complete all-page goal.

## TTS labels and Player generation edge closure (2026-09-11)

- The current XTTS FastAPI connector now displays as XTTS, matching Herika. The
  separate legacy connector displays as XTTS (legacy API). Connector identities,
  endpoints, credentials and speech dispatch are unchanged.
- Six mocked browser cases verify Player generation: successful staging, failed
  job, stale profile, concurrent text edits, transient polling failure followed by
  an idempotent retry, and invalid empty success. Terminal failures allow a new
  request; transport retries reuse the pending request. Existing text survives
  every failure and concurrent edit. Generated text still requires explicit Save.
- The OmniVoice preparation claim was traced through reference tts_connectors.php
  save handling and TTSConnector create/update/default-normalization methods.
  There is no preparation call on that path. Language options display label/id,
  not voice counts. No new background preparation workflow is needed for parity.
- Existing test expectation updated for the XTTS display label. No production
  writes, provider calls or game control were used in browser checks. Evidence:
  player-generation-edge-proof.cjs and tts-service-order-proof.cjs in the temporary
  evidence directory. This closes these specific items, not the all-page goal.

## Core advanced metadata and Oghma overrides (2026-09-11)

- Added the reference Metadata (Advanced JSON) disclosure to Core Profile create
  and edit forms. The existing typed settings validator checks submitted JSON.
  Visible controls take precedence, matching Herika's metadata/visual merge.
- Supported advanced settings survive Save All instead of being discarded when
  the form reconstructs its visible fields. Existing compatibility keys may be
  retained or removed; changing/adding unsupported keys is rejected rather than
  presenting a newly saved setting with no runtime consumer.
- Core Oghma settings and memory.oghma_knowledge_tags now override installation
  defaults in the resolver consumed by knowledge selection. Explicit NPC tags
  still win. The client controls schema and system connector ownership do not
  change. Named and portable presets retain the added Oghma settings and tags.
- Initial HTTP checks caught an independent portable-export allowlist that dropped
  the new values; that path was corrected too. The temporary browser fixture
  covers disclosure close/reopen with a draft and bounds at 1280/390, with all
  non-GET requests blocked. Screenshots: core-metadata-{width}.png.
- This is not full Global Settings override-picker or JSON-editor interaction
  parity. Those controls, other Core runtime gaps and the complete matrix remain
  open. No production settings were saved and no providers or game were invoked.

- Final checks: 915 server checks; full management HTTP suite (save/read,
  visual-control precedence, rejected invalid/unsupported settings and portable
  export); full integration, schema inventory and migration/durable-job suite.
  Deployment verification: 812 source/runtime hashes match, no extra runtime
  files, private routes remain protected. GitHub server workflow remains disabled.

## Core inline global override controls (2026-09-11)

- The actual reference Profile mode renders all available overrides inline, grouped
  by category, with an Override checkbox, disabled inherited control and Global
  value text. Its popup chooser is NPC-only. An initial local chooser was replaced
  before publication; it is not counted as profile UI parity.
- Reused the reference prof-ovr category/row/control styles with gold accents.
  Added eight currently supported Oghma controls to the Core form, including tags.
  Shared labels/help follow the reference; location wording remains OpenMW-specific.
  The reference has additional categories and 80 rows in this installation; those
  are not being marked complete by these eight controls.
- Inline and advanced JSON edits share one submitted draft. Removing an override
  restores the displayed global value. Explicit false and empty tags remain valid.
  Invalid JSON is retained, and invalid numbers/UTF-8 lengths block submission.
  With JavaScript disabled, the inline controls are hidden and the raw editor remains.
- Browser checks at 1280/390 cover toggling/inheritance, number ranges, empty and
  oversized tags, escaped text, invalid JSON retention, synchronization in both
  directions, disclosure draft retention and actual form serialization. Production
  non-GET requests were blocked. Native/reference screenshots were inspected;
  reference narrow-page clipping was not copied.
- Checks: 915 unit checks, full existing management HTTP suite, JavaScript syntax
  and PHP deployment preflight. Temporary evidence: core-inline-overrides-proof.cjs,
  core-inline-reference-proof.cjs, core-inline-http.txt and associated screenshots.
  This closes the supported Oghma inline controls, not the remaining Core categories.

## Core Context and Prompt overrides (2026-09-11)

- Added Prompt Head, Prompt Timestamp, Ground Items Descriptions Only and
  Inventory Items Descriptions Only to the inline Core overrides. Context, Oghma
  and Prompt use the reference grouped layout. Prompt Head is multiline; long
  inherited-value previews are bounded like the reference.
- Core policies override global values in the effective document, with source
  ownership recorded. Prompt assembly and item-description selection already use
  that document. Existing NPC Prompt Head precedence is unchanged. Blank Core
  Prompt Head selects built-in text; removing the override restores the global
  prompt. No XML-format option was reintroduced.
- The strict override validator accepts only these four added Core leaves, with
  boolean types and an 8192-byte UTF-8 prompt limit; NPC override payloads cannot
  use them. Named and portable presets preserve all four values, including false.
- Existing tests now exercise Core-owned Prompt Head in assembled provider input
  and Core timestamp policy in temporal history headings. Browser tests cover all
  twelve inline rows at 1280/390, raw synchronization, multiline/blank prompt,
  UTF-8 limits and restoring inheritance. No production form or provider was run.
- Remaining runtime-backed Context and global override policies are still open; this is not full Core Profile or all-page completion.

- Final proof: 922 unit checks; full management HTTP and integration/schema/
  migration/durable-job suites passed. All 814 deployed files match source; private
  routes remain protected and the GitHub server workflow remains disabled.
  Temporary evidence: core-context-unit.txt, core-context-http.txt,
  core-context-integration.txt, core-context-overrides-proof.cjs and screenshots.

## Core Rechat Mode and Relationship System (2026-09-11)

- Confirmed the pinned Core override list has one Rechat row, Rechat Mode, and a
  Relationship System Enabled row under Context. Added both with reference labels
  and inline controls. Other Context features (power awareness, ambient combat,
  pickups and scene classification) remain separate unfinished work.
- The reference override catalog excludes selectmultiple fields: blacklists and
  context section/detail lists are Global Settings controls, not missing Core
  override rows. Earlier Core planning language included them too broadly.
- New Rechat chains resolve mode from the initiating speaker's Core Profile.
  Existing chains retain their configured/resolved mode snapshot. Global open-
  Rechat restrictions, responder eligibility and other limits remain unchanged.
- Core Relationship System overrides the global enable switch while retaining the
  global evaluation chance and connector. Off resolves to zero chance and prevents
  queuing; actor locks and revision fences remain intact. Legacy compatibility
  fields are not promoted into new user controls.
- Named and portable presets retain the enum and boolean. The strict validator
  rejects invalid modes/types and does not permit the Core relationship switch
  in NPC override payloads. Fourteen inline rows are now exposed.
- Checks: 928 unit checks; full HTTP, integration, schema and migration/job suites.
  Existing integration fixtures prove actual Rechat coordinator mode selection
  and refusal to enqueue a played response when Core relationships are off.
  Browser checks at 1280/390 cover all four mode choices, on/off/inherit, JSON
  synchronization and form serialization, with production non-GETs blocked.
  Evidence: core-mode-unit.txt, core-mode-http.txt, core-mode-integration.txt,
  core-mode-overrides-proof.cjs and screenshots in the temporary evidence folder.

## Power Awareness (2026-09-11)

- Added the reference Power Awareness Enabled Global and Core controls and the
  Nearby Actor Details Power checkbox. The feature defaults off, matching the
  reference; disabled policies perform no additional observation lookup.
- Ported the seven comparison thresholds and descriptions from pinned Herika
  `lib/power_awareness.php`. Prompt output compares the responding actor with the
  player and observed nearby actors, omitting unavailable or invalid levels.
- Current target observations take precedence. Rechat can change the responding
  actor after capture, so it uses recorded observations instead of reusing the
  original target state. Recorded lookups are fenced by installation, playthrough,
  actor kind, record, content source and RefNum. Profile prose is never treated as
  a level. No client or game files changed.
- Core inheritance, strict boolean validation, named/portable presets and actual
  prompt assembly are wired. The existing integration fixture checks current and
  recorded levels, playthrough isolation and different-instance rejection.
- Checks: 946 unit checks; full management HTTP, integration, schema, migration
  and durable-job suites passed. Browser interaction checks at 1280/390 cover
  fifteen Core rows and Global/nearby-detail controls; production writes were
  blocked. The narrow Global screenshot was inspected. No paid provider or game
  test was run. Other Context features and remaining page rows remain open.
- Temporary evidence: power-unit.txt, power-http.txt, power-integration.txt,
  power-core-proof.cjs, power-global-proof.cjs and associated screenshots.
- Final deployment: all 815 runtime files match source, with no extra or old
  paths; private routes remain 403, unauthenticated sessions 401, and the server
  workflow remains disabled. Existing credentials and voice contents preserved.

## Hide Ambient Combat (2026-09-11)

- Matched the actual pinned Herika `buildHistoricContext` predicate: omit death
  events containing the case-insensitive phrase `has killed`. Keep `has defeated`,
  unknown deaths and ordinary speech containing the same words. This filters
  conversational prompt history, not the stored event log or existing memories.
- Added the Global Context control and Core inline override with inheritance,
  strict validation and preset preservation. Default is false; Local LLM preset
  enables it, matching the reference. No game event-capture code changed; this
  checkpoint proves filtering of recorded death events, not new client capture.
- 952 unit checks passed, including assembled prompt filtering, Core precedence
  and retained source history. Browser checks cover all sixteen Core rows and
  the Global checkbox at 1280/390, raw synchronization and form serialization;
  all production writes were blocked. Narrow screenshot inspected.
- Deployment has 815 exact source matches with no extra/old files and protected
  private routes. Credentials, voice files and user data preserved. No provider
  or in-game test was run. Remaining Context/event capture and other page rows
  are still open.
- Temporary evidence: ambient-unit.txt, ambient-http.txt, ambient-core-proof.cjs,
  ambient-global-proof.cjs and corresponding screenshots.
- Final HTTP result: full browser-like management forms suite passed.

## TTS primary editor audit and Zonos guidance (2026-09-11)

- Compared current rendered primary input catalogues for all seventeen shared
  drivers: PocketTTS, Chatterbox, XTTS FastAPI, Inworld, Cartesia, OmniVoice,
  Piper, xVASynth, MeloTTS, Mimic3, Azure, ElevenLabs, OpenAI, Kokoro,
  KoboldCPP, Zonos and Deepgram. Field types and primary field inventory match
  after native name mappings except the two cases below. This is field-inventory
  evidence, not full provider interaction or all-page visual acceptance.
- Zonos Cached Voice Path remains read-only in Lorkhan; the reference renders an
  editable text field. Its runtime checks cached-file availability and falls back
  to upload. A native edit must preserve endpoint/sample/digest and NPC-voice
  isolation; this remains required work, not an accepted parity exception.
- Mimic3 Volume exists in the reference form but is consumed only by
  `ttsMimicOld`, not active `tts`. Do not add an inert setting as functional parity.
- Reference numeric Zonos inputs have no min/max constraints; their help recommends
  speaking rate 5-30 and CFG 1.1-5. Native help now includes these recommendations
  while explicitly retaining its existing accepted ranges (1-40 and 0-20).
  No previously accepted connector setting is invalidated by this presentation fix.
- Native 1280/390 checks prove numeric boundary validity, revised help, advanced
  JSON draft retention on disclosure close/reopen, and the current read-only cache
  behavior. Narrow screenshot inspected. No production POST or provider request.
- PHP deployment preflight passed and all 815 runtime files match source, with
  private routes protected and credentials/voice files preserved. Temporary
  evidence: tts-current-field-audit.cjs/.json, zonos-final-proof.cjs and screenshots.
  Lower revision surfaces, editable cache and broader provider interactions remain
  open. The audit does not close the entire TTS or hub page.

## Zonos editable Cached Voice Path (2026-09-11)

- Replaced the read-only cache value with the reference editable text input in
  the same position. Regular Save and embedded Save All submit the path. Empty
  requests a fresh upload; nonempty selects a cached path on the configured Zonos
  service. Help explains default-voice scope and changing endpoint/voice first.
- Edits are stored in connector revisions with an opaque endpoint/sample/digest
  scope and edit ID. Saving does not call the provider or mutate its upload cache.
  The speech adapter applies each edit once, then retains the successful upload
  path. A missing/expired remote file triggers normal upload; different voices,
  endpoints and replaced sample bytes cannot reuse the edit.
- Strict shape, UTF-8/size and control-character checks preserve the existing
  secret-field guard. The edit marker is named edit_id, not a credential token.
  Save compares with the authoritative saved connector, so clearing a pending
  edit works even when a form client omits advanced JSON.
- 962 unit checks passed. Nine mocked synthesis calls through the real adapter
  verified outgoing paths, reuse, one-time clear, different voice/sample and
  expiration recovery (five uploads and five availability checks). No paid provider
  was invoked. Full integration/schema/migration/durable-job checks passed.
- Browser checks at 1280/390 verify editing, empty serialization, driver-switch
  draft retention, numeric bounds and Advanced disclosure retention. Narrow
  screenshot inspected. Production writes were blocked; no game was launched.
- Evidence: zonos-edit-unit.txt, zonos-edit-probe.py, zonos-edit-proof.cjs,
  zonos-edit-integration.txt and zonos-edit screenshots in the temporary folder.
  This closes the cache-edit interaction, not the remaining full TTS/hub page audit.
- Final checks: full management HTTP suite passed (zonos-edit-http-verified.txt),
  including save/reload, clear and invalid-input rejection. Populated and empty
  narrow editor screenshots inspected. All 815 deployed files match source;
  private routes remain protected, credentials/voices preserved.

## TTS lower editor and revision interaction proof (2026-09-11)

- Rechecked the live lower panel with one revision at 1280/390. The history table
  stays within the page width and correctly omits Restore when no earlier revision
  exists. No browser errors. The reference has no revision panel; this is an existing
  native data-safety extension, not a missing Herika control.
- Extended the existing HTTP connector clone fixture: edit its language, verify
  persistence, restore revision 1 through the rendered form, then compare the
  complete exported content with the original. Full HTTP suite passed. Clone and
  delete continue through their existing checks; no extra test file was added.
- Captured the actual isolated PHP-rendered two-revision editor for browser checks,
  then removed the temporary capture line from the test. At 1280/390 the restored
  revision selector retains its value across disclosure close/reopen, submits
  revision 1 with the correct connector kind, and does not overflow. Delete Cancel
  shows the confirmation and sends no request. All browser POSTs were intercepted;
  no production connector was changed. Narrow screenshot inspected.
- Temporary evidence: tts-revision-http.txt, tts-revision-proof.cjs,
  tts-revision-fixture.html, tts-restore-proof.cjs and screenshots. This closes the
  lower revision-surface review previously left open for TTS. No product-code or
  deployment change was needed; provider-specific error states and full hub review
  are still separate open matrix work.

## STT provider controls and Test interactions (2026-09-11)

- Compared the rendered primary field inventory and input types for all eight
  existing reference services: Deepgram, Parakeet, Whisper, Local Whisper, Gemini,
  Azure, Inworld and Disabled. After native field-name mappings, the only input
  type mismatch was Local Whisper URL. Changed that input from text to url, matching
  the reference and adding browser URL validation without changing server policy.
- Native browser checks at 1280/390 prove independent language drafts survive
  service switches; an invalid Local Whisper URL prevents Test, while that inactive
  draft does not block another service. No production settings were saved.
- Mocked save failure prevents the provider request; mocked provider failure can
  retry successfully. Results render transcript text literally rather than as HTML.
  Close and Escape return focus to Test. No browser errors; narrow success and
  provider-failure screenshots captured, success screenshot inspected.
- PHP deployment preflight passed. Temporary evidence: stt-current-field-audit.cjs
  and .json, stt-interaction-proof.cjs, stt-success and stt-failure screenshots.
  Google Free remains unfinished: it requires browser recognition plus the native
  session/target-fenced game bridge. This audit does not substitute dictation-only
  behavior or close the remaining advanced/hub review.

- Follow-up hub proof: all eight services retain and serialize separate Advanced
  JSON drafts at 1280/390. Switching STT to TTS and back in the actual Configuration
  hub retains both selected service and draft. Compared current native/reference
  embedded Parakeet views and inspected narrow screenshots. Brand width and the
  explicitly excluded reference tabs account for wrapping differences; reference
  clipping was not copied. Evidence: stt-hub-final-proof.cjs,
  stt-hub-ref-final-proof.cjs and corresponding screenshots.
- Deployment verifier: all 815 runtime files match source; no extra or old paths,
  private routes remain protected, existing credentials and voice contents kept.


## 2026-09-11 NPC profile metadata editor checkpoint

- Replaced the missing editable metadata surface with locally pinned vanilla-jsoneditor 3.13.0, the library used by the reference. The native revisioned profile JSON is submitted through existing `base_content_json`; visible controls still take precedence. Recorded actor state remains read-only and separate.
- Preserved strict CSP using a per-response style nonce and three documented vendor style-creation adaptations. No runtime CDN, unsafe-inline or unsafe-eval. Isolated vendor buttons from the app skin; preserved gold accents. Moved pre-existing blocked relationship badge inline colors into CSS.
- Kept a labelled JSON textarea fallback. Object/128 KiB validation prevents invalid submissions; the library validation API flushes pending text edits before Save. The existing HTTP parser now captures form-associated textareas, matching browser submission.
- Proof: all 962 server checks and the full management HTTP suite pass. Read-only browser probes covered initial tree, text synchronization, immediately submitted invalid root rejection, and table mode at desktop/narrow widths, with zero browser/CSP errors. No production NPC save or paid provider calls. Full editor tools and all modal states remain in the broader open review scope.
- Deployment verified 820 tracked runtime files with no hash mismatches, extra files or old paths. Private-file routes remain 403, unauthenticated sessions 401, valid/invalid NPC pages 200/404. Runtime secrets, configuration and voices preserved. Goal remains active; this does not close the remaining page/runtime matrix.


## 2026-09-11 Core Profile metadata editor checkpoint

- Core Profile advanced settings overrides now use the same locally pinned reference editor as NPC metadata. Inline Global Settings override edits update the tree/text draft, and JSON edits update their typed controls. Shared form-data serialization flushes pending editor changes for Save All and preset snapshots; existing server-side field precedence and validation remain unchanged.
- Invalid custom-validity errors reveal the labelled raw textarea instead of leaving an unfocusable hidden control. A failed library load retains a usable textarea and the native form path. Shared editor gold/layout rules moved into the common stylesheet. Two existing blocked `display:block` inline styles became a named CSS class.
- Full management HTTP suite and 962 server checks pass. Browser probes confirmed two-way Context override edits, exact FormData value, initial tree/text and narrow layout, default JSON Query Transform execution, invalid raw draft visibility, and blocked-library fallback serialization. Normal runs had no page/CSP errors. NPC immediate invalid-root regression probe also passed. No production saves or paid providers invoked.
- Verified all 820 runtime files exactly against source; no extra files or old paths. Private routes 403, unauthenticated sessions 401, health and valid/invalid NPC routes as expected. Full metadata tool/modal coverage and other Core runtime categories remain open in the matrix; this is not a full parity completion claim.


## 2026-09-11 NPC global-setting override parity checkpoint

- Reference `npc_master.php` uses `chimGetOverrideableGeneralSettingsCatalog()`, the same catalog as Core Profiles. Native NPC overrides previously rejected Context and Prompt policies and omitted runtime-supported Oghma and relationship/mode leaves.
- Added 15 typed fields for 34 total: five Context booleans, Prompt Head, seven Oghma fields, Relationship System Enabled and Rechat Mode. Existing Oghma Knowledge Tags remain in their dedicated NPC field. This does not claim the still-missing global feature categories are implemented.
- Effective resolution now applies NPC Context/Prompt after Core values with source attribution. NPC Oghma settings reach retrieval and extractor selection, Relationship Enabled gates the existing global evaluation chance, and the initiating NPC's effective Rechat Mode selects new chains while current chains retain their snapshot. Existing explicit false/zero and inheritance semantics remain. Relationship enabled state is retained separately from a zero evaluation chance for accurate editor defaults.
- Prompt Head alone permits blank instructions, matching its built-in fallback; Diary Prompt stays nonblank. Add/Remove overrides continue through the existing revisioned save; no provider is called during editing.
- Proof: 970 unit checks, full management HTTP suite, integration vertical slice, unchanged 173-relation schema inventory, migrations and durable jobs passed. Tests cover actual NPC prompt assembly, inherited-value precedence, Oghma policy leaves, relationship eligibility, save/retain/remove and invalid typed values. Browser checked all 34 catalog entries exist and edited boolean/integer/choice/empty-string types at desktop/narrow widths with zero page/CSP errors; narrow screenshot inspected. No live game or paid-provider acceptance.
- Local deployment verified 820 exact source hashes, no extra/old paths; protected routes and health unchanged. Remaining work stays listed in the primary page matrix.


## 2026-09-11 asynchronous Database Maintenance checkpoint

- The confirmed web action now queues `database.compact` instead of holding the HTTP request open. An advisory lock serializes enqueue/compaction; repeated pending submissions reuse the job. The existing durable worker handles it with one attempt, a one-hour fenced lease and a 30-minute SQL deadline. The three-second lock timeout, catalogue-quoted table list, database/schema boundary, ownership checks, cooldown and audit outcomes remain.
- Added an authenticated, identifier/state/timestamp-only status endpoint and bounded browser polling. Queued/running/succeeded/dead states are explicit. A failed status request does not imply the maintenance job failed or cancelled. The page warns to stop game activity, wait for other work and retain free disk space. No production maintenance was executed.
- Full management HTTP proof: wrong confirmation rejected; request redirects immediately to queued state; duplicate submission reuses the job; a real Worker instance claims and completes compaction against the isolated test database; endpoint reports succeeded. All 970 server checks, integration vertical slice, migrations/durable jobs and unchanged schema inventory pass.
- Browser mocks covered queued-to-running-to-success and terminal failure at 1280/390, with no page errors. Narrow layout inspected. Production status endpoint without a session returns 401. Deployed worker types are unrestricted (`null`) and a current PHP worker started after the handler registry deployment, so the new type is available without interrupting active work.
- Verified all 822 runtime files with no mismatches/extra/old paths. Persistent files preserved and workflow remains disabled. This closes synchronous-maintenance execution, not the remaining full SQL backups/import, scheduled backups, reset/version/access features or whole Database Manager parity. A real large-database 30-minute run was not performed.


## 2026-09-11 full SQL backup creation and download checkpoint

- Added a real `pg_dump` database snapshot, distinct from configuration-only JSON exports. An explicitly confirmed `database.backup` job streams SQL to private storage, renews its lease and records a SHA-256/byte-count identity. Duplicate pending requests share one job; repeated completed work verifies and reuses its immutable file. No shell command interpolation or credential arguments: only the database connection environment reaches pg_dump.
- Plain SQL includes the configured database's schema/data with clean/if-exists and no role ownership/grant restoration. External media, game saves and private credential files are outside this SQL snapshot. Limits are explicit: 10 minutes, 1 GiB per dump, 4 GiB SQL storage; completed backups are never deleted automatically. Failed partial output is removed; failed completed-file metadata commits remain an explicit failed job, not a successful backup.
- Authenticated downloads verify stored identity and stream the file rather than loading the whole dump into PHP memory. The Database Manager has queued/running/completed/failure status, a completion download link, and paged SQL file cards using the reference backup classes. Existing configuration restore remains clearly separate. SQL restore/import controls are still unfinished.
- Deployment now provisions a dedicated `backups/sql` directory owned by `lorkhan:www-data` with group inheritance; worker-created files are private and readable by the web service. A read-only production privilege probe found zero unreadable tables/sequences and zero RLS tables. No production SQL backup or restore was executed.
- Proof: 970 checks and the full management HTTP suite passed. The HTTP test queues/reuses work, runs the real worker, repeats completed work safely, downloads the SQL with exact Content-Length and restores it into a separate temporary database with over 5,000 fixture records. Integration, migrations/durable jobs and schema checks passed. Inventory changed only by adding DatabaseSqlBackup.php as a writer of backup_records; still 173 relations / 1,604 columns, hash d5740c6e7e1a1abb6cd44f1b9b3cf71606ebe63c8b1dd69a45d77203cf495789.
- Browser checks covered actual rendered populated SQL cards and live empty views at 1280/390, plus mocked queued-to-running-to-success and the completion download URL, with no overflow/page errors. Narrow populated screenshot inspected. No downloads of production database contents or paid-provider calls.
- The broader goal remains open: full SQL import/restore UI, automatic backups, reset/access/version controls, playthrough snapshot lifecycle, and the other primary matrix gaps are not completed by this export feature.


## Automatic database backups checkpoint (2026-09-11)

- Compared pinned Herika `lib/automatic_backup.php` and the existing Dashboard database-manager automatic-backup controls. The reference default is off; Home visits check a ten-minute cooldown, despite the reference help describing server startup. Native help states the actual trigger.
- Added migration 097 for typed enable/count/cooldown state. Home only queues; the existing durable SQL worker dumps privately. Concurrent Home visits share pending work. Settings are authenticated/CSRF-protected, with On/Off and 1-10 retention controls; status and retention saves do not overwrite one another.
- Automatic snapshots are marked separately from manual SQL copies. Retention runs only after validating the replacement file and hash; old automatic copies beyond the limit are removed. Manual SQL and configuration backups are untouched. Space/creation failures preserve existing copies; no pre-emptive deletion to make room. Disabling prevents queued automatic jobs from creating a dump. Default remains off locally.
- Proof: 970 server checks; complete existing management HTTP suite with real isolated automatic dumps, duplicate visits, cooldown, successful replacement/deletion, authenticated downloads, manual preservation, invalid settings/CSRF rejection, disabled scheduling, and an intentionally failed replacement retaining existing backups. Fresh schema check and migration/durable-job integration passed: 174 relations, 1608 columns, inventory `d9bc9f6e03724ac2666ecf3be12c507f56c00fb9cb6c3c90f197d30f46264d3e`.
- Browser proof: isolated populated and deployed empty automatic-backup cards at 1280/390, no horizontal overflow or JavaScript errors; both toggle and retention submissions captured/prevented before any production POST. Screenshots and test output are in the local temporary `automatic-backup-*` evidence files. Live reference `ui/import_db.php` redirects to the newer Dashboard `data_manager.php?mod=shared&view=databases`; the pinned automatic-backup source was compared, but that redirect is not paired visual evidence for the pinned page. Whole Database Manager parity remains open.
- Deployed with 826 exact source/runtime hashes, no extra files or old paths, private files denied, health/session/NPC probes passed, private configuration/credential/voice hashes preserved. No live backup, restore, compaction, provider call or game action was triggered. GitHub workflow remains disabled.
- Restore audit remains open: a full SQL restore replaces browser sessions, durable work and metadata. Before exposing native restore, preserve control state, protect rollback, invalidate stale queued game/provider work, and report completion outside replaced state. Do not claim the isolated SQL import test is a production restore implementation.


## Stored SQL restore checkpoint (2026-09-11)

- New backups retain a private PostgreSQL custom archive and generate the downloadable SQL from that same consistent snapshot. Both files are covered by the storage quota; archive hash/size metadata is retained. Automatic retention removes both only after replacement verification. Older SQL-only backups remain downloadable but are not offered for native restore.
- Database Manager has stored-backup radio cards, typed `Restore SQL` confirmation, authenticated/CSRF-protected queuing, and worker status with retry through HTTP 503. The existing reference card/indicator/restore-button treatment is reused with gold branding. Current-format manual and automatic copies use the same restore path; rollback copies are labelled Before restore.
- The worker verifies the SQL and archive, uses PostgreSQL's archive TOC to preserve schemas/extensions and their administrator ownership, creates a separate manual rollback backup, and imports inside one psql transaction. Temporary tables preserve current browser sessions, pairing tokens and anti-replay nonces, installation authentication, backup metadata/preferences and the current durable job/attempt. Current schema checksums and installation-ID sets must match or the entire import rolls back. Old sessions/turns/STT/audio/provider work and queued jobs are ended/cancelled; historical action intents are not fabricated into successful client results. The worker exits after acknowledging restore so a fresh worker reloads profile/handler state.
- Runtime connections use a shared PostgreSQL advisory gate; the restore takes it exclusively. Idle workers release it, and workers do not spin/restart rapidly while it is held. Requests get 503 and workers cannot claim jobs during replacement. No broad database or operating-system privileges were granted.
- Important validation correction: initial isolated restores ran as PostgreSQL administrator. A read-only live ownership probe showed extensions belong to postgres, while the runtime is lorkhan_runtime. The final existing HTTP suite assigns application tables/functions to a nonsuperuser, leaves schemas/extensions administrator-owned, and runs the actual restore worker as that restricted role. It passes successful restore, browser/pairing preservation, stopped sessions/jobs, rollback download, schema mismatch rollback, preservation of a subsequently added installation, and HTTP/worker gating. Evidence: temporary `sql-restore-http-restricted3.txt` (complete suite passed), `sql-restore-unit-complete.txt` (970 checks), and `sql-restore-integration-final.txt` (schema/migration/durable-job integration passed).
- Schema inventory now includes maintained data/restore SQL readers/writers. No schema migration was needed: 174 relations / 1608 columns, inventory `7f15fef80be5c667a14ac3e4e4c9b3bca24bef3911c26d759f7cc316bda597e4`.
- Populated restore form at 1280/390: backup-card selection, invalid/valid confirmation, blocked submission capture and queued -> 503 -> succeeded polling passed without overflow or JS errors. Deployed empty state at both widths has no SQL backups or restore requests. Screenshots: temporary `sql-restore-1280.png`, `sql-restore-390.png`, `sql-restore-live-empty-1280.png`, `sql-restore-live-empty-390.png`. Pinned reference source/CSS is the comparison basis; the live reference import URL currently redirects to a newer Dashboard page, so no new claim of paired pinned-page visual proof is made.
- Final local deployment: 829 runtime files match source exactly, no extras/old paths, private-file/session/health/NPC probes passed. A fresh worker was observed after deploy. Configuration, credential and voice file hashes were preserved. GitHub server workflow remains disabled. No production backup/restore/compaction, paid provider or game action was executed.
- Whole-page/goal closure remains open: external or legacy SQL upload/import, individual backup deletion, database access/reset/version reset and full playthrough snapshot lifecycle are not completed by this checkpoint.


## Automatic backup deletion checkpoint (2026-09-11)

- Ported the Dashboard automatic-backup trash control and irreversible confirmation, using native authenticated POST/CSRF forms rather than destructive GET links. External form ownership keeps deletion separate from the surrounding required restore form. Manual SQL, configuration and before-restore rollback backups cannot be deleted through this endpoint.
- Deletion shares the backup/maintenance lock, rejects queued or leased restore sources, and removes the selected automatic SQL file, compressed archive and record. A partial filesystem failure is reported accurately and can be retried. Both explicit deletion and automatic retention reject/skip a pending restore source; retention may temporarily exceed its configured count to preserve that source.
- Existing full HTTP suite passed: wrong confirmation/CSRF do not delete, manual-file deletion is rejected, queued restore deletion is rejected, retention keeps the protected old source after a replacement, successful deletion removes both files, and unrelated manual/new automatic downloads remain available. The suite still includes restricted-role SQL restoration and rollback/gate checks.
- Browser checks at 1280/390 verified cancel does not submit, acceptance submits only the delete form, and there is no overflow or JS error. A caught global green-submit style was corrected with the danger-button class; computed background matches the reference rgb(166,53,63). Evidence: temporary `backup-delete-http-verified.txt`, `backup-delete-browser.cjs`, `backup-delete-1280.png`, `backup-delete-390.png`.
- 970 server checks and fresh migration/schema/durable-job integration passed. Inventory remains 174 relations / 1608 columns, with changed runtime reader/writer references reflected in hash `16c8f4712a846c0dfd791563e271dd9235760b565fa41e0dd2072b290a1ef0e3`. Local deployment has 829 exact runtime hashes, no extras or old paths, preserved private data and passing health/auth/private-file probes. Workflow remains disabled. No production backup was created, restored or deleted.
- Next dependency traced: the pinned Playthrough Manager has Active Database, Save Current Public Database (name + optional notes), Stored Snapshots and Copy to Public with rollback/timeline. Native profile-scoped JSON export is still not that feature. Reuse the verified full PostgreSQL archive/restore path for named snapshots and preserve the distinct live OpenMW playthrough scopes; do not relabel partial profile exports as full snapshots.

## Named full playthrough snapshots checkpoint (2026-09-11)

- Replaced the primary profile-only export/import panels with Active Database, Save Current Public Database and Stored Snapshots using the pinned reference structure. Existing installation/profile tools remain explicitly separate in a closed disclosure.
- Named saves use the full private PostgreSQL archive/SQL worker. Copy requires confirmation, captures a named rollback snapshot, and uses the existing transactional restricted-role restore. Delete protects pending restore sources and removes only the selected stored files/record. Live-source provenance survives deletion of its stored copy.
- Migration 098 adds typed source provenance. Snapshot names/notes are validated and escaped; duplicate pending saves share a job while a different requested name reports busy. Authenticated status polling and 25-row pagination are wired.
- Proof: 970 server checks, full management HTTP suite including real restricted-role save/copy/delete and rollback, and fresh schema/migration integration passed. Inventory: 175 relations / 1612 columns, hash da6de73744b6bbf54daed219b1d1cbce36ba35433f1999eadbf4227e1f46d062.
- Browser evidence uses deployed empty pages and actual HTML captured from the isolated successful-copy test. Desktop 1280 and narrow 390 views passed required-field, copy/delete accept/cancel, source-badge, overflow and JavaScript checks. Screenshots: temporary snapshot-{empty,populated}-{1280,390}.png; script snapshot-review.cjs. All production POSTs were blocked; status responses in populated fixtures were mocked. Pinned reference source was compared; this is not a new paired visual capture of the redirected live reference.
- Local runtime: 832 exact hashes, no extra files or old paths, protected private files and passing health/auth probes. Private settings were preserved. No production snapshot or provider/game action was executed. Workflow remains disabled.
- Goal remains active. Snapshot timeline/game-time metadata and the other matrix gaps are still open.

## Snapshot game metadata and timeline checkpoint (2026-09-11)

- Captures player, event/Oghma counts and recorded Morrowind calendar for new full database backups, including before-copy rollback snapshots. A separate read-only repeatable-read PostgreSQL transaction exports its snapshot to pg_dump, so metadata and archived rows use the same MVCC view while the worker connection renews its lease. Repeated creation retains existing immutable metadata.
- Added a no-leap Morrowind minute coordinate to the existing calendar parser, including stored hour/minute round trips and year boundaries. Stored copies sort by recorded game time and show reference-style Current Time / days ahead / days behind labels. Old backups without metadata remain explicitly unknown; no historical data is invented.
- Ported the reference timeline track, notches, intermediate date labels, markers and tooltip structure. Stored snapshots retain gold branding; a blue live marker represents current recorded state. Timeline includes snapshots beyond the current list page. Native buttons add keyboard focus, Enter/touch display and Escape dismissal. Dates and names use text nodes/escaped attributes. Global button sizing/radius overrides discovered in browser review were corrected; the reference's empty title prevents overlapping tick labels.
- Proof: 971 server checks; complete management HTTP suite with a real recorded session, metadata surviving archive restore, automatic rollback three game days ahead, and restored event/Oghma counts matching captured metadata. Full existing restore/gating/deletion tests still pass. Fresh integration/migration/schema checks passed: 175 relations / 1612 columns, inventory 9f10670e89bab65a9f9e0334187f09c892edda4a42239a8292d4b1cac5dc653e.
- Browser evidence uses actual successful-copy fixture HTML, plus explicit wide/equal-time DOM variants, at 1280/390. Keyboard tooltip show/dismiss, literal markup, 16px circular markers, equal-time ticks, no horizontal overflow and no JS errors passed. Temporary evidence: snapshot-timeline-review.cjs, snapshot-timeline-*.png and snapshot-timeline-http-complete.txt. Source comparison used the pinned reference; no claim of new paired live-reference capture is made. No production POST or paid provider/game action was executed.
- A test-fixture cleanup initially left no recorded turns. The speculative null-playthrough lookup edit was removed after checking the NOT NULL schema; the final lookup retains its existing equality semantics. Tests now create a valid session and turn instead of weakening the runtime contract.
- Remaining source audit: pinned Herika also auto-captures a protected default snapshot and creates Dragon Break snapshots for a 3+ day save rollback (lib/playthrough_snapshot.php). Neither feature exists in Lorkhan yet. The Playthrough page and whole goal therefore remain open.
- Final deployment verified 832 exact source/runtime files, no extras or old paths, passing health/auth/private-file probes and preserved private configuration/credential/voice contents. GitHub workflow remains disabled.

## Protected initial snapshot checkpoint (2026-09-11)

- Playthrough Manager now checks for existing named snapshots under the maintenance lock. When none exist, it queues a named default backup through the established worker. Repeated visits share pending work; concurrent maintenance does not block viewing the page. Capture timing is explicitly described as worker start, rather than falsely promising the exact HTTP visit instant.
- The default snapshot has Copy to Public / Download SQL and a Protected default label. Its deletion is rejected in the repository even with a forged valid-CSRF request; hiding the button is not the protection. Initial capture does not claim a restore occurred, so Last copied from remains unknown until a real copy.
- Existing full HTTP tests prove initial queuing, duplicate visit reuse, successful worker capture, protected deletion, unchanged downloadable contents and no second initial job. The complete stored restore, timeline, rollback, retention and manual deletion suite also passed. 971 server checks and fresh schema/migration/durable-job integration passed; inventory remains 175 relations / 1612 columns, 9f10670e89bab65a9f9e0334187f09c892edda4a42239a8292d4b1cac5dc653e.
- Browser checks at 1280/390 use isolated populated fixture HTML and the deployed page. The first live visit intentionally created the private baseline backup through the normal feature; its worker reached succeeded. Protected default / Copy / Download controls, no delete form, no overflow and no JS errors were verified. Evidence: temporary snapshot-default-review.cjs, snapshot-default-{fixture,live}-{1280,390}.png and snapshot-default-http.txt. No production restore/delete, game launch/control or paid provider call was executed.
- Dragon Break remains open. Pinned Herika processor/comm.php captures before pruning on init/playerdied and uses a default-on three-day threshold in lib/playthrough_snapshot.php. The current LORKHAN main b133b4bc01a7e34b98f11399a86899d91f231193 session-init wire format has no game calendar. A later dialogue is not equivalent to a load signal, and an unfenced asynchronous dump after rollback would not be a pre-rollback snapshot. Trace/add the typed load observation and capture boundary before implementing this feature; do not add an unwired UI control.
- The canonical client checkout is stale fddccda on codex/lorkhanserver-client-route; its origin/main was refreshed read-only to b133b4b. No client files were changed. Use an isolated current-main client worktree for the eventual protocol work.
- Final local verification: 832 exact runtime hashes, no extras/old paths, preserved private configuration/credential/voice contents and passing health/auth/private-file probes. GitHub workflow remains disabled.

## Snapshot name parity and load-boundary investigation (2026-09-11)

- Match the reference's unique stored snapshot names: saving an existing exact name is rejected under the same maintenance lock used by creation/deletion. Pending duplicate clicks still reuse their job; stored content is never overwritten. The page gives a specific duplicate-name notice.
- Existing full HTTP tests passed, including a duplicate save after real capture with unchanged latest job ID, followed by successful archive copy/rollback and deletion checks. 971 server checks passed. Desktop/narrow duplicate-notice views passed with no overflow/JS errors; production POSTs were blocked. Temporary evidence: snapshot-name-http.txt, snapshot-name-review.cjs and snapshot-name-{1280,390}.png.
- Deployment verified 832 exact source/runtime files, no extras/old paths and passing health/auth/private-file probes. Private configuration/credential/voice contents were preserved. No new production snapshot, restore, deletion, paid provider call or game action was invoked for this check.
- Prepared clean current-main client worktree D:/wt/lorkhan-dragon-break, branch codex/dragon-break-parity at b133b4bc01a7e34b98f11399a86899d91f231193. No client implementation files changed. Its mandatory architecture/toolchain/protocol reading must be completed before implementation; the first combined read was truncated, so it is not a complete reading gate.
- Load-boundary evidence: global.lua has distinct onNewGame/onLoad handlers and a later onPlayerAdded/update path. Both configuration and cancelGeneration call native beginSession, so session init alone is not an unambiguous loaded-save signal. InitRequest has only IDs, runtime, content fingerprint and UTC creation time. The closed native GameDataType/typed Lua entry points must be extended coherently for a real load observation; there is no generic Lua networking shortcut.
- Proposed next implementation boundary, still unimplemented: freeze a typed recorded calendar at the actual load lifecycle, bind it to the accepted session/generation, and acknowledge pre-rollback snapshot handling before new-world dialogue/observations advance the server state. Compare against prior observed state in the same installation/playthrough. Keep exact archive identity/idempotency and bounded failure behavior. Add matching client/server fixtures and prove cancellation, duplicate load, under-threshold, unknown-calendar and successful 3-day rollback paths before enabling it. Do not infer load from a later chat or dump an already-advanced database and call it pre-rollback.
- Dragon Break and the overall parity goal remain open.
## Loaded-save snapshot implementation checkpoint (2026-09-12; unpublished)

- Added an optional strict loaded_save calendar to matching client/server session-init contracts. Only the actual Lua load lifecycle defers the native handshake until the loaded player calendar is available; normal initialization omits it and unknown calendar uses null. Both tracked engine binding copies receive the change without erasing their pre-existing differences.
- Server snapshot callback runs after the installation/generation fence and before old-session replacement. Three-day backwards travel captures a full committed database through the existing SQL backup implementation, deduplicates by scoped calendar transition, and audits success/failure. Capture failure does not intentionally reject the loaded session. Future-history pruning is NOT implemented by this change.
- Loaded-save requests with known calendar receive a 20-second first-byte budget within the existing 30-second total; ordinary requests retain their existing deadline. Server capture heartbeat budget is 15 seconds. Full engine deadline/cancellation proof remains outstanding.
- Evidence: 975 PHP checks; 107 identical protocol files; 38 schemas and 69 fixtures validated; 72 standalone Lua tests; portable native and Beast tests passed. Existing integration suite proved real three-day capture before session replacement, subthreshold rejection, stale-generation callback suppression, repeat deduplication and archive creation. Inventory refreshed to 175 relations / 1612 columns, hash 8777ed2502a7ab1be29ffc549af0b74d0709e384af5fe05c8c0bf21b245d6ac4; migration/job checks passed. Temporary evidence: dragon-integration-write.txt, dragon-integration-check.txt, dragon-windows-python.txt and WSL /tmp/lorkhan-dragon-debug-{build,tests}.log.
- Limits: portable Release hits existing GCC maybe-uninitialized diagnostics; portable Debug transport tests required a local -Wno-error=missing-field-initializers flag for existing CanonicalResponseLine initialization. Broader Windows/Python and Lua structural checks still fail on provenance and older structural expectations. New fixture provenance and overlay digest were updated; remaining existing gaps require review, not blanket suppression.
- Still required before publication/deployment: full Windows engine build, actual GLOBAL load fence/cancellation coverage, restricted HTTP capture/failure paths, archive old-state inspection, fresh inventory recheck, Dragon Break UI presentation, and source/runtime hash verification. No game launch, paid provider call, production restore or current-change deployment occurred. Neither repository has been committed or pushed for this checkpoint. Whole parity goal remains active.

## Loaded-save snapshot integration proof (2026-09-12)

- The exact-pin Windows Release engine and native/Beast executables built successfully from the current client worktree. Both Windows test executables passed; 73 standalone Lua tests include actual GLOBAL handler coverage for deferred player availability, bounded calendar fields, unknown dates and no duplicate/new-game submission. Engine SHA-256: 096691cd213ab048ea632bc684d0994bd01ff78fb97a689d8512818f336c9b7b.
- Database integration now inspects archive session COPY data and proves the previous session is still active in the stored SQL, before replacement. A held maintenance lock produces an audited failure while accepting the new session. Fresh schema/migration checks passed; inventory remains 175 relations / 1612 columns, hash 3d26890f2752fb49e768be4da509e0082ddf502120be84d6f6b7031fc5e23714.
- Full browser-like HTTP suite passed with a real signed native loaded-save init, real archive creation and the resulting Dragon Break card. The accepted load calendar now supersedes older chat dates for both later rollback comparisons and the live timeline, including loads before another conversation occurs. Unknown calendars stay unknown.
- CHIM's distinct Dragon Break card background and How it works entry are wired to actual capture metadata. Real isolated HTTP fixture rendered at 1280 and 390 pixels with no overflow or JavaScript errors, usable copy/delete controls and the expected card styling. Final header text relocation is a presentation-only adjustment. No production write was used for browser proof.
- Evidence in local Temp: dragon-windows-{configure,build}.txt, dragon-timeline-{integration,http}.txt, dragon-ui-review.cjs, dragon-http-fixture.html and dragon-snapshot-{1280,390}.png. Existing broader provenance/structural test failures from the prior checkpoint remain explicit; no release-package or in-game acceptance claim is made.
- Automatic history pruning, legacy/external SQL imports and whole-page parity remain open. This checkpoint implements capture before replacement, not automatic database restoration or deletion of immutable future history.

## NPC inventory observation parity (2026-09-12)

- The client now includes the target NPC inventory in normal context snapshots, with record/display names, stack counts, total unique records and a 48-row bound. Unavailable and known-empty inventories remain distinct. Over-budget context omits rows explicitly; player inventory is unchanged.
- NPC Info sorts recorded item names, right-aligns × counts and shows the reference-style total footer with an explicit captured-row limit. Manual `actor` profiles now resolve recorded `npc` observations with the same exact record, content-file, RefNum and installation checks. No live inventory or continuous update claim is made.
- Passed 975 server checks, 73 Lua tests, database/schema/migration integration and the full management HTTP suite. Actual isolated HTTP output reviewed at 1280/390 pixels: escaped names, counts, totals, no private/player-data leakage, no page errors or horizontal overflow. Evidence: local Temp `npc-inventory-{http,integration,unit}.txt`, `npc-inventory-review.cjs`, `npc-inventory-fixture.html`, `npc-inventory-{1280,390}.png`.
- This supersedes the earlier missing target-inventory capture entries. Continuous inventory updates, full NPC modal/override parity and Visit/Teleport/Return remain open. The narrow modal header is still tall and needs the full modal review. No game or paid provider was invoked.

## NPC narrow header correction and next Quickstart dependency (2026-09-12)

- A later desktop geometry rule overrode the existing narrow header wrapping and padding. Moved narrow overrides after that rule, retaining reference desktop geometry, all eight actions, and native form associations. At 390px the heading occupies its own line, every action stays inside the viewport and the header is below 280px high. The actual isolated populated editor was reviewed at 1280/390; inventory assertions, no-overflow and no-JavaScript-error checks still pass. This does not close the full NPC modal review.
- Player2 remains absent. Pinned Herika `lib/llm_randomizer.php` forces both slot and named-field resolution without replacing saved normal routes; Quickstart hides normal/local controls and uses Player2 recap cards. Default endpoint is loopback port 4315 `/v1/chat/completions`. Its connector sends a `player2-game-key` header. Lorkhan's three OpenAI-compatible adapters currently send Bearer authorization only, so merely pointing an existing connector at that endpoint is not sufficient parity.
- Implementation boundary: add typed Player2 transport identity across dialogue, profile-generation and Oghma adapters; add revisioned installation-level opt-in and owned connector creation; apply override before slot availability/fallback decisions and to background-job provider selection; preserve frozen accepted jobs and all saved ordinary routes; then port the Quickstart toggle, disabled local controls and recap cards. Prove enabled/disabled routing, installation isolation, missing/deleted connector behavior, concurrent saves, transport headers and browser retention with isolated mocks. Do not contact Player2 or any paid provider for these tests.

## Player2 connector transport correction (2026-09-12)

- Clarification: the existing LLM connector editor already offers Player2; the Quickstart force-all policy is missing. That service identity now reaches all three OpenAI-compatible adapters through ProviderFactory. Requests use `player2-game-key` instead of Bearer, with the selected credential as the game key or the LORKHAN application identity when no credential is selected. Ordinary providers retain Bearer behavior; header line breaks are rejected.
- Passed 980 server checks and the full isolated management HTTP suite. The actual saved Player2 connector successfully completes mock streamed dialogue with both default and selected keys, sends no Authorization header, and switches back to the ordinary service. Factory checks cover dialogue, profile generation and Oghma; shared header tests cover defaults/custom keys and injection rejection. No external service acceptance is claimed. Evidence: local Temp `player2-transport-{unit,http}.txt`.
- Force-all policy remains open. `ProductRepository::providerContext` can return before connector resolution when slots are empty; `profileGenerationPayload` and diary enqueue resolve separate fields. Relationship evaluation consumes effective routing. `MemorySummaryRepository::enqueue` joins the provider directly from the revisioned memory policy and validates that relationship again while leasing. Those paths must all honor the forced provider while preserving frozen job revisions and disabled background policies. Changing only `connectorForActor` would leave gaps.

## Player2 Quickstart routing parity (2026-09-12)

- Quickstart now has Herika's Player2 switch, four model recap cards, normal-control visibility and Local LLM warning/disable behavior. Unsaved Local LLM values survive switching Player2 on/off. The checkbox applies through Save and Continue with a separate optimistic revision; it does not launch Player2 or test a paid service.
- Migration 099 records immutable installation-owned routing revisions. Enabling creates/reuses its dedicated Player2 connector without adopting a similarly labelled unrelated connector; disabling reveals the unchanged saved Core/NPC model selections. Active connector deletion or service repurposing is rejected. Profile, diary, Oghma, relationship, player-autochat and all four dialogue routes share the effective overlay. Speech, prompts and disabled background features remain unchanged.
- Memory summaries select the forced connector before queuing, record its policy/provider revisions and validate the frozen policy during leased execution. Relationship evaluation likewise reads its recorded Player2 policy revision. Existing ordinary jobs without the new field retain revision-zero behavior. The override never rewrites queued provider snapshots.
- Passed 980 server checks, complete isolated management HTTP forms, and database/schema/migration tests. Tests cover enable/disable, stale-save rollback, normal-route preservation, all typed LLM route IDs, installation isolation, owned-name collision, active connector guards, queued memory after disabling, and rejection of an unrecorded policy revision. Schema: 176 relations / 1617 columns; summary hash 3561739016d5d1f8510cd47ea721add89aae98d2147f1ef8a63b030d1998a89c.
- Actual saved Quickstart HTML reviewed at 1280/390 with source CSS/JS: switch and four cards visible, normal controls hidden, disabled local controls, retained drafts, no horizontal overflow or page errors. Evidence: local Temp `player2-routing-{unit,http,integration}.txt`, `player2-quickstart-fixture.html`, `player2-quickstart-review.cjs`, `player2-quickstart-{1280,390}.png`.
- This supersedes the missing Player2 entries, not the remaining whole-Quickstart/all-page interaction review. External Player2 acceptance and in-game behavior were not exercised; no paid provider or game was invoked. Deployment leaves Player2 off unless the user saves it on.

## Browser speech implementation checkpoint (2026-09-12)

- Herika's Google Free STT page sends recognized utterances into the game. A standalone microphone transcription preview would not close this parity item.
- The working implementation adds the fixed `player.dialogue.submit` command with text/language only, migration 100, matching client/server protocol fixtures and native parsing. The management POST `/manage/api/v1/browser-speech` requires the existing browser session and CSRF token, an explicit session UUID and stable utterance UUID. Repeating an identical request reuses its queue entry; changing the text under that UUID is rejected.
- Session discovery exposes `browser_speech_supported` only when both debug transport and `speech.browser.v1` were negotiated. The new client advertises the capability and routes speech through normal player-turn orchestration, target selection, player history and player TTS. Stale sessions, expired requests and busy input return rejection receipts. Existing typed drafts remain untouched. Queue acceptance is not dialogue or audio success.
- The STT modal derives layout and controls from pinned Herika `ui/addons/pmstt/index.html`: transcript, nine languages, 1–10 second silence delay, Start/Stop and instructions. LORKHAN adds explicit session selection and queue receipts. Text is rendered safely, microphone recognition starts only after a click, and Stop/Close discard unsent words. Instructions do not suggest disabling browser security.
- Passed 74 Lua tests, Windows Release engine build, native bridge and Beast loopback tests, full isolated management HTTP suite, PHP/JS syntax and desktop/narrow simulated-recognition browser checks. Browser checks cover successful receipts, literal markup in transcripts, Stop discarding buffered words and Close aborting recognition. Evidence: local Temp `browser-speech-{lua,http,browser,engine-build}.txt` and `browser-speech-{1280,390}.png`. No real microphone, external recognition provider or game was invoked.
- Published server `9613fa2` and client `13c9c73` to their respective `main` branches. Local server deployment verified 847 runtime files with no mismatches, extra files or old paths; private paths remain blocked and configuration/credentials/voices were preserved. The deployed OpenMW executable and both changed Lua scripts match their build/source hashes; client configuration is unchanged. Server workflow remains manually disabled. No in-game acceptance or actual Google recognition is claimed. Overall page parity remains active.

## Relationship Update Chance overrides (2026-09-12)

- Herika's managed override catalog includes `RELATIONSHIP_UPDATE_CHANCE` for profiles/NPCs. LORKHAN now exposes the corresponding 0–100 value in Core Global Settings Overrides and the NPC override picker. Previously Core metadata accepted the value but current-format resolution ignored it.
- Chance resolves Global → Core → NPC. Explicit zero stops evaluations without deleting relationship context. Explicit Relationship System off still blocks jobs; enabling an NPC inherits its Core's configured chance. Core enable state is now represented accurately in resolved settings. Relationship connector ownership and manual locks are unchanged.
- Passed 983 server checks, database/migration integration, full management HTTP and 1280/390 Core editor checks. Database tests verify the actual queued-job policy, NPC-zero enqueue refusal and removing the NPC override. Browser checks verify range validation, explicit zero, JSON/FormData synchronization, removing overrides and row bounds. Evidence: local Temp `relationship-chance-{unit,integration,http,browser}.txt` and `relationship-chance-{1280,390}.png`.
- This closes one missing override; the full Core/NPC catalog and page-wide review remain open. No provider or game was invoked.

## Rechat override audit (2026-09-12)

- `ENFORCE_STRICT_RECHAT_RESPONSE` and `OPEN_RECHAT` are included in pinned Herika's managed profile override catalog. LORKHAN now resolves their typed counterparts through Global → Core → NPC and exposes the reference inline Core rows/NPC picker choices. New chains use the initiating speaker's effective Open Rechat setting; the selected responder supplies strict targeting. Existing chain snapshots remain unchanged.
- Separate unresolved runtime gap: Herika `END_CONVERSATION_COOLDOWN` describes an NPC choosing to stop talking. LORKHAN `Repository::rechatCooldownActive` instead checks any recently closed chain in the playthrough, and normal chain exhaustion sets `closed`. There is no corresponding EndConversation action in the current catalog. Do not claim cooldown parity or expose an apparently per-NPC override until end-conversation identity/event handling and the scoped runtime check exist. The existing global behavior is unchanged by this patch.
- Passed 985 server checks, database/migration integration, the full management HTTP suite and 1280/390 rendered editor checks. Coordinator tests exercise listener-only selection and strict targeting; browser tests cover true/false persistence in the metadata draft and removing overrides to restore inheritance. Evidence: local Temp `rechat-overrides-{unit,integration,http,browser}.txt` and `rechat-{open_rechat,rechat_strict_targeting}-{1280,390}.png`. The complete parity goal remains active; no provider or game was invoked.

## End Conversation implementation checkpoint (2026-09-12, automated and Windows build proven)

- Reference trace: Herika seeds `EndConversation`; CHIM `Plugin/Commands.cpp` calls the actor's `setConversationEnded()` and `AIAgentAIMind.EndConversation`, which releases conversation packages. Actor eligibility checks use this actor-specific timer. Normal round-budget exhaustion is not the trigger.
- Working changes add migration 101/catalog entry, the `conversation.end` typed protocol action, native decoding and Lua cleanup of LORKHAN-owned packages. A successful action result stops Rechat/followup continuation. Server cooldown now reads successful action receipts for exact record/content/RefNum identity, across cell/display-name changes, instead of any closed playthrough chain. Direct turns and Rechat candidates use this gate; stale session generations and failed results do not start a cooldown. Core/NPC overrides accept 0–300 seconds, with zero disabling the timer.
- Passed 987 server checks, 76 Lua tests, 111 shared protocol files, database/migration/schema integration and management HTTP integration. The database suite submits the real typed action and signed successful receipt, then verifies the same actor is refused during cooldown. Native and Beast transport tests passed after the Release OpenMW build. Rendered Core controls and Action Editor advanced options passed at 1280/390; range, JSON draft, inheritance, errors and dialog bounds were checked. Evidence: local Temp conversation-end-{integration,http,engine-build}.txt, conversation-end-core-{1280,390}.png and conversation-end-editor-{1280,390}.png. No game or provider was invoked; in-game package release remains unverified. Deployment is recorded separately after exact runtime comparison.
- Separate correction published/deployed as server `3a47d81`: browser speech capability was absent from the server's session allowlist. The regression now creates an authenticated session offering `speech.browser.v1` and verifies management discovery sees it. This closes a hole in the earlier browser-speech tests, which manually appended the capability. Only `Repository.php` was deployed for that correction; uncommitted End Conversation work was not deployed. Runtime file SHA256: `29074290ed50d32bfe46e6ad85c1dd77ed3dcff539aca46f9716cf0f39adce41`.

## Current deployment and checklist reconciliation (2026-09-12)

- End Conversation is published on `RANGROO/LorkhanServer main` at `72aca54` and `RANGROO/LORKHAN main` at `6db3553`. Local server verification found 851 runtime files, no hash mismatches, no extras and no old paths. Private-file probes remain forbidden and unauthenticated native session creation remains unauthorized.
- Client executable and four changed Lua modules were backed up and replaced with exact build/source matches. Executable SHA256: `900b438c82e3337a7d269cc6ab941f76a8dfbfde9599f1adf9e872cd467b7819`. Client configuration and server configuration/credentials/voice contents are unchanged. Server rollback: `/var/backups/lorkhanserver-code.o2sX15`; client rollback: local Temp `conversation-end-client-rollback-3a48436108134458adfafceb450cbb82`. No game was launched. The server GitHub workflow remains `disabled_manually`.
- The primary matrix now reflects implemented Player2 routing, browser speech injection, loaded-save Dragon Break capture and the expanded Core/NPC overrides. Historical unfinished checkpoints remain chronological evidence, not the current acceptance state.
- The full goal remains active. Remaining work includes whole-page/hub interaction closure, further runtime-backed Core/NPC overrides, Physical Diary/cumulative memory/Core-slot selection, continuous NPC inventory and Visit/Teleport/Return, remaining Context observations, Narrator semantics, voice-provider error/batch/hub presentation, uploaded/legacy SQL imports, database access/reset controls and history-pruning/full-rollback behavior. External-provider and in-game acceptance are separate untested limits, not completed checks.
## Core/NPC blacklist and Save All correction (2026-09-12)

- Pinned Herika `lib/settings.php::chimGetManagedGeneralSettingIds` includes Location, Item and Magic Event blacklists. LORKHAN now accepts corresponding typed lists in Core and NPC overrides, uses the existing inline Core rows/NPC picker, and resolves Global → Core → NPC before prompt filtering. Blank lists clear inherited entries; removing an override restores inheritance. Lists retain the global 256-entry/256-UTF-8-byte-per-entry bounds and case-insensitive normalization. No stored history is deleted.
- The actual Core form round trip exposed a missed save gate: `CoreProfilePreset::FIELDS` did not include recently exposed Open Rechat, Strict Rechat Targeting, End Conversation Cooldown or Relationship Update Chance. The resolver/browser draft tests from those earlier checkpoints did not prove a successful Core Save. These fields now participate in that shared gate and named presets, and management HTTP checks submit and inspect their saved values.
- Passed 996 server checks, 111 protocol files, database/migration/durable-job tests and the full management HTTP suite. Unit/prompt checks verify blacklist replacement, explicit empty lists, normalization, invalid values and actual item-description filtering/restoration. Actual saved-form Core/NPC fixtures passed at 1280/390: multiline edits, empty lists, inheritance removal, UTF-8 bounds and no page errors. Narrow screenshots were visually inspected. Evidence: local Temp blacklist-{http,integration}.txt, blacklist-review.cjs, blacklist-core-{item_blacklist,location_blacklist,magic_effects_blacklist}-{1280,390}.png and blacklist-npc-{1280,390}.png. No game or external provider was used.
- Deployment of server f6668e3 verified 851 runtime files with no mismatches, extras or old paths; private configuration/credentials/voices are preserved, workflow disabled. A final keyboard check also covers Enter followed by character typing in each Core list. Draft rendering preserves the focused textarea's unfinished line instead of collapsing it during normalization. No client files changed in this blacklist slice.

## Profile Event Type Filter and Emote Moods (2026-09-12)

- Pinned Herika `lib/settings.php::chimGetManagedGeneralSettingIds` includes `EVENT_TYPE_FILTER` and `EMOTEMOODS`. Both now have typed Core/NPC overrides in the existing inline Core rows and NPC override picker, with named-preset and Core Save support. Event types are restricted to LORKHAN's actual event catalog; unknown names are rejected. Global → Core → NPC inheritance preserves explicit empty lists/strings.
- Event Type Filter is consumed by the existing prompt-history query. An isolated database test saves an empty Core filter, proves returned history is empty while eventlog count is unchanged, and restores inherited history by rolling the override back. Emote Moods reaches prompt assembly as the effective default when the NPC has no custom mood list; it does not change saved NPC content or force a mood on a response.
- Passed 1002 server checks, 111 protocol files, complete database/schema/migration/durable-job tests and management HTTP forms. Actual saved Core/NPC HTML was reviewed at 1280/390, covering multiline keyboard input, event-name validation, empty values, inheritance, UTF-8 bounds and no page errors. Evidence: local Temp filter-{http,integration}.txt, filter-review.cjs, filter-core-{events,moods}-{1280,390}.png and filter-npc-moods-{1280,390}.png. No game, microphone or external provider was invoked. Other Core/NPC runtime and full-page parity gaps remain open.
## Complete displayed Core override save audit (2026-09-12)

- Extended the existing management HTTP suite to derive all 25 displayed Global Settings Override definitions from the actual rendered Core page, submit every value through the real Core form and its preset allowlist, inspect each saved field, and restore the fixture. The complete suite passed. This is stronger than resolver-only or browser-draft proof and will detect future displayed-but-unsavable controls.
- Advanced metadata testing used the same bundled vanilla-jsoneditor integration and default theme as pinned Herika `ui/core/tmpl/metadata_json_editor.php`, retaining gold toolbar accents. Immediate Text-mode edits reach FormData; invalid JSON blocks submission; corrected and empty objects serialize without becoming lists. Desktop/narrow checks passed those paths, with actual source-rendered fixtures and screenshots.
- Full metadata interaction closure remains OPEN: repeated desktop sequences reproduce an intermittent Table → Text switch that leaves the tree visible without a `.cm-content` editor. The library reports a text-mode callback, but the rendered tree remains; no external source-input update was observed during the failing transition. Adding synchronous `onChangeMode`/`updateProps` did not fix it and was removed. No unproven workaround or diagnostic logging remains in product code. Investigate the library focus/validation/mode transition before claiming the whole editor complete.
- Reproduction/evidence: local Temp core-metadata-review.cjs (includes mode callback diagnostics when instrumented), core-metadata-review-narrow.cjs, core-metadata-switch-failure.png, core-metadata-table{,-390}.png, core-catalog-http.txt. The test script intercepts submissions without network writes. Live runtime still matches all 851 files at ff8b511; private files remain blocked and the server workflow is manually disabled. This audit changes only existing tests and this evidence document; no runtime deployment or game/provider invocation was needed.
## Metadata mode-switch fix and editor proof (2026-09-12)

- The previously open switch failure was isolated to editing Text content and then leaving that mode: no-edit switches passed, and the edited failure reproduced without submissions, without LORKHAN form code, and with the unmodified upstream 3.13.0 standalone bundle (SHA256 `2345b95c5756bd7a1183bc6e31d9fca539f5adc70a44c9eb3f1f0082661dd160`). The CSP nonce patch and Save All were not the cause.
- Upstream TextMode flushes pending edits during teardown. The shared integration now uses the supported `onRenderMenu` callback to flush through `validate()` before invoking the existing Tree/Table mode action. It preserves the original actions, modes, library version, data and theme. No delay, remount, mode pinning or diagnostic logging was retained. Upstream trace: https://github.com/josdejong/svelte-jsoneditor/blob/v3.13.0/src/lib/components/modes/JSONEditorRoot.svelte and the adjacent textmode/TextMode.svelte.
- Repeated full Core editor sequences passed three times; six-cycle delayed switching passed both in the isolated wrapper and full Core page. Core and NPC metadata checks passed at 1280/390 for immediate serialization, invalid JSON refusal, correction, empty objects, Tree/Text/Table transitions and editor bounds. NPC metadata remains separate from immutable Recorded State. The default library Table-mode message for a root object is expected, matching the reference; switching back to Text now succeeds reliably in these probes.
- Passed 1002 server checks and JavaScript syntax/diff checks. Evidence: local Temp core-metadata-review{,-narrow}.cjs, core-metadata-modes-delay.cjs, core-metadata-isolated.cjs, core-metadata-original-library.cjs, npc-metadata-review{,-narrow}.cjs and corresponding table screenshots. These checks intercepted form submissions; no live profile was edited. The full parity goal, including remaining runtime and page interactions, stays active.
## Quickstart recap parity (2026-09-12)

- Matched the pinned Herika Local LLM explanatory note, local-model prefix and empty model/endpoint messages. Player2 cards now distinguish the Standard app-selected model from the three slots sharing that connector. Normal, Local and Player2 notes are mutually exclusive; switching does not clear drafts or alter saved routing.
- Added the reference endpoint wrapping behavior for narrow layouts. Passed 1002 server checks, PHP/JavaScript syntax, the complete management HTTP suite and isolated browser checks at 1280/390. Browser checks covered empty fields, long URLs, all four recaps, Player2 precedence, disabled local fields and retained drafts; no mutations or external provider calls were made by the browser probe.
- Evidence: local Temp quickstart-recap-{http,unit}.txt, quickstart-recap-review.cjs, quickstart-recap-fixture.html and quickstart-local-recap-{1280,390}.png. Other Connectors Used and the broader remaining matrix still require runtime-backed review; this is not whole-goal completion.

## Quickstart Other Connectors Used (2026-09-12)

- Added Herika's bordered Other Connectors Used section, list typography and no-additional-connectors state. It reuses the existing read-only global connector inventory for Summaries, Profile Tasks, Custom Oghma and Relationship Management; no excluded products or unsupported scene-classifier route is listed. Connector names are escaped and only installation-scoped labels are rendered, not private configuration payloads.
- Default and Dialogue-only Local mode show the actual saved task connectors. Unlike Herika's fixed OpenRouter label, Lorkhan preserves those task routes when Dialogue only is selected, so the recap must not claim OpenRouter. Dialogue + background tasks shows Local LLM; Player2 takes precedence and shows Player2 Local. The title switches to Other AI tasks in Local mode, matching the reference.
- PHP/JavaScript syntax and 1002 server checks passed. The existing HTTP suite now checks Quickstart's displayed labels against the saved global-route plan and verifies viewing it does not call a provider. Isolated desktop/narrow browser checks cover scope changes, Player2 precedence, retained drafts and long connector-name wrapping. Evidence: local Temp quickstart-general-review.cjs, quickstart-general-saved-{1280,390}.png and quickstart-general-assert-http.txt. Broader page/runtime matrix work remains open.

## Voice Management failure presentation (2026-09-12)

- Replaced visible internal error tokens with actionable messages in the existing error panel, covering upload validation, duplicate/in-use samples, account access, rate limits, discovery, registration, clone validation and cleanup. Safe allowlisted codes remain under a collapsed Error details disclosure; provider response bodies and secrets are never displayed. Local upload failures and provider sync uncertainty have separate guidance. No retry, deletion or provider behavior changed.
- Compared the reference's readable batch/provider error presentation and retained Lorkhan's existing panel styling. The existing HTTP suite verifies actual invalid-WAV and duplicate-name errors show readable text and retain safe diagnostics. Full management HTTP and 1002 server checks passed. Actual source-rendered failure pages passed at 1280/390 for keyboard disclosure, collapsed defaults, messages, page errors and overflow; screenshots were visually inspected.
- Evidence: local Temp voice-errors-http.txt, voice-errors-review.cjs, voice-error-{invalid_voice_sample,voice_sample_exists}.html and matching desktop/narrow screenshots. No game or live provider was called. This closes the raw-error-code presentation gap, not all remaining Voice Management batch/provider/hub acceptance.

## Voice batch endpoint and terminal states (2026-09-12)

- Actual source-rendered batch testing reproduced a functional URL bug: the hidden `name="action"` control shadows `HTMLFormElement.action`, so the browser posted to `/ui/core/[object HTMLInputElement]`. Batch fetch now reads the form's action attribute. The HTTP backend tests did not catch this because they submit the parsed action URL directly.
- Receipt validation now runs inside the per-voice failure boundary. A malformed or wrong-voice result terminates the visible row as outcome unconfirmed instead of leaving it Processing. No uncertain request is retried automatically and no subsequent voice starts after an uncertain result.
- Eight browser scenarios passed at both 1280 and 390 pixels using the actual isolated-server page: mixed uploaded/skipped/failed results, empty queue, rate limit, cancellation during the active request, wrong-voice result, network failure, duplicate queue rejection and cleanup/reference warnings. Checks cover terminal controls, request counts, retained confirmed results, no page errors and overflow. Reference batch controls/log/progress/cancellation were compared; native consent and refresh links remain.
- Full management HTTP and 1002 server checks passed. The existing HTTP suite can now export its real populated batch page for browser verification. Evidence: local Temp voice-batch-http.txt, voice-batch-fixture.html, voice-batch-review.cjs and voice-batch-{scenario}-{1280,390}.png. All uploads were isolated mocks; no live provider voices or game state changed. Other provider-specific presentation and hub coverage remain open.

## Context-selection overrides implementation in progress (2026-09-12)

- Confirmed a missing Core/NPC override boundary: Global Settings already owns context.sections and context.details, and PromptAssembler consumes the resolved context, but validateSettingsOverrides rejected both groups. Added strict complete boolean-map validation, NPC allowlisting and named Core preset preservation. Whole-group replacement follows the existing context merge; removing a group restores inheritance. Unknown keys, omitted keys, lists and non-boolean values are rejected. No client protocol or global defaults changed.
- Existing unit coverage now proves Core group resolution, NPC replacement and unrelated-group inheritance, named preset preservation, malformed group refusal, and actual prompt suppression/restoration of record descriptions. 1011 server checks passed.
- WORKING TREE ONLY: do not consider this deployed or complete. The Core and NPC catalog/renderers still need selection-group controls before publication. Add a checkbox-group type to the existing override editors, with readable labels shared with Global Settings, inherited state, raw JSON validation and group removal. Then run the existing all-displayed-Core Save audit, add NPC persistence and revision checks, inspect desktop/narrow empty/all/sparse groups, and run integration/schema checks before publishing/deploying. Current live/source main remains 4f9559c.

## Context-selection Core and NPC editor closure (2026-09-12)

- Completed the working-tree implementation above. Core Global Settings Overrides and the NPC setting picker now expose Context Sections and Context Details as checkbox groups. Shared labels/help were extracted from Global Settings into ui/tmpl/context_selection_groups.php, preserving its existing content. All-unchecked is explicit exclusion; disabling/removing the override restores inheritance. Mandatory speaker/current-turn/action instructions are unchanged.
- Complete maps are validated in both browser editors and the server. Existing raw JSON drafts, typed NPC submission, Core Save All and named presets retain the boolean groups. The HTTP audit saved all 27 displayed Core definitions; NPC round trips now include all-false sections and sparse details. Server unit checks prove precedence, preset retention, malformed rejection and actual prompt removal/restoration of descriptions.
- Passed 1011 server checks, management HTTP twice, database integration, migrations/durable jobs and unchanged schema inventory (176 relations; 37965e929cfbeffd85272dee8eb3e437ca17038a5217aa2c962e5f2112eb315c). Isolated Core/NPC browser checks at 1280/390 cover all-unchecked, sparse, disabled inheritance, remove/re-add and no browser errors/overflow. Screenshots exposed inherited NPC column styling; checkbox/text rows and duplicate headings were corrected and rechecked.
- Evidence: local Temp context-selection-{http,save-http,integration}.txt, context-selection-{core,npc}.html, context-selection-review.cjs and context-selection-{core,npc}-{sections,details}-{1280,390}.png. No live profile, game or provider was used for acceptance. The wider parity matrix remains open.

## Database Access / reset implementation boundary audit (2026-09-12)

Current source evidence changes the implementation path; these controls remain unfinished, not excluded:

- Reference Dwemer-Dashboard/database_manager.php:2792–2799 has a Database Access tile linking to /pgAdmin/. Local read-only probes returned 200 from http://127.0.0.1:8081/pgAdmin/ and 403 from the same path on Lorkhan port 7514. The dedicated Lorkhan virtual host intentionally does not expose the dashboard administrator. Do not relax that deny rule or copy the reference's fixed login text. Add an explicit deployment-owned administration URL and a reference-shaped link card; preserve pgAdmin's own authentication and do not include database passwords in the page. Prove the configured destination locally and missing/invalid configuration behavior without inventing a working link.
- Reference reset_db_version deletes a public.database_versioning row and relies on restart to replay an update. Native MigrationRunner::status/up instead validates the lorkhan_internal.schema_migrations ledger against source checksums. Native fresh() reverses every applied migration then reapplies the source set; rerun() reverses/reapplies the latest migration. Their apply/revert calls commit separately. Deleting ledger rows or attaching the existing CLI fresh directly to a form would not provide an atomic recoverable reset.
- Existing DatabaseRestoreJobHandler already supplies maintenance/runtime advisory locks (7514,113 and 7514,114), private rollback creation, a bounded subprocess and one transaction. data/restore/before.sql and after.sql preserve browser sessions, pairing/nonces, backup records/preferences and the active job/attempt. They reject schema or installation mismatch. Reset must adapt these protections, not silently disable those checks for arbitrary restore files.

Implementation sequence:

1. Add an explicit reset job type, authenticated CSRF route, typed destructive confirmation and reference card/dialog. Keep it single-attempt and mutually exclusive with backup/restore/compact. The UI must describe exactly which game data/configuration is removed and which credentials/backups remain; do not execute a live reset during acceptance.
2. Create and verify a rollback SQL/archive first. Generate a deterministic factory schema from the source migrations in isolation; validate the complete ledger and seed requirements before opening the target transaction. Do not use unconstrained user SQL or grant the web role database creation/superuser rights.
3. Apply the validated factory state atomically under the existing runtime gate. Preserve the minimum installation identity/pairing, browser login, backup catalog/preferences and reset job/attempt needed for reconnection and rollback, while resetting gameplay/configuration records and default seed data as advertised. External voice/audio/credential files remain untouched. Reconnect game sessions after completion.
4. Version-reset parity needs an explicit dependency-aware migration replay operation, with rollback and a schema/ledger postcondition. Latest-only rerun is not a substitute for the reference per-entry/all controls. Audit each source migration's destructive behavior before exposing any replay choice; errors must roll back ledger and data together.
5. Extend existing database/management integration tests on disposable PostgreSQL instances: unauthorized/unconfirmed requests, overlap, backup failure, mid-reset SQL failure, lost lease, successful reset/reconnection metadata, exact factory inventory and actual rollback restoration. Browser desktop/narrow checks must verify confirmation, progress, success/failure and cancelled dialog behavior. Preserve live data throughout acceptance.

No product code, database contents, admin routing or live settings were changed by this audit. Current published/deployed server remains fa0dada. This is a required implementation plan, not completion evidence for the missing Database Manager controls.

## Atomic migration replay primitive (2026-09-12)

- Added MigrationRunner::replayFrom(version), restricted to applied source-controlled migrations. It reverses the chosen version and all applied dependants, then reapplies them in order inside one transaction. The runner retains checksum/gap validation, its migration advisory lock and per-step search-path ownership. Existing up/down/fresh/rerun behavior is unchanged. An already-open caller transaction is refused.
- Extended the existing migration/job suite with successful dependent replay and invalid-target checks. A real migration 090 downgrade guard is forced after later migrations have been reversed; the test verifies the complete original ledger including timestamps, guarded data, migration 101 index and seeded action remain intact. A separate temporary trigger forces migration 101's up step to fail after down succeeded; ledger, seed and index all survive rollback. Replay succeeds again after a prior rollback.
- Passed 1011 server checks and the complete isolated database/integration/migration/job suite. Schema inventory remains 176 relations with summary hash 37965e929cfbeffd85272dee8eb3e437ca17038a5217aa2c962e5f2112eb315c. Evidence: local Temp atomic-replay-test.txt and atomic-replay-integration.txt.
- This is a backend prerequisite, not completed reset UI. No CLI/web route exposes the new method yet, no live replay/reset was run, and it does not by itself preserve authentication or provide a backup. The forthcoming durable operation must still create a verified rollback archive, preserve operational records, fence runtime access and enforce destructive confirmation before invoking replay. Factory-reset generation remains separate from dependency-aware version replay.

## Replay control-state preservation prerequisite (2026-09-12)

- MigrationRunner replay now accepts trusted internal before/after callbacks inside its single transaction. MigrationReplayState captures installation identities, pairing tokens/nonces, browser sessions, backup records/preferences and only the current job/attempt in transaction-local temporary tables with ON COMMIT DROP. Nothing is returned to the browser or written to a separate credential file.
- Restoration handles both retained and recreated tables. It validates the exact column set, names columns explicitly to survive changed physical column order, upserts captured values only when different, checks that all captured rows match, and advances the job-attempt sequence. The helper refuses use outside the transaction or without the named job. These safeguards do not replace the forthcoming runtime gate and verified rollback archive.
- Extended existing isolated migration/job tests with real pairing/MAC, nonce, login, backup metadata/preferences and a claimed job. Replay from version 3 recreates the protected tables; all survive and the original lease can acknowledge success. A subsequent version 101 replay preserves the already-retained state without duplicate rows. 1011 unit checks and migration/job tests passed.
- The schema reader inventory was regenerated for the new helper (176 relations; summary hash 8e696f753e65336df3249f9e1626666337ee12652dc9f244c0090ad7fe6bb076). Evidence: local Temp replay-state-tests.txt, replay-state-inventory.txt and replay-state-final-integration.txt. The initial full run correctly refused the stale reader inventory; no live schema change was needed.
- Still unfinished: register a single-attempt replay worker; verify a private rollback backup before replay; acquire runtime/maintenance locks; preserve a sufficient bounded lease/deadline; reject stale queued migration fingerprints; stop stale game work; expose authenticated CSRF/typed-confirmation queue/status routes and reference UI only after integration tests. No replay job or web/CLI command is exposed by this prerequisite, and no live replay/reset was performed.

## Protected migration replay worker (2026-09-12)

- Added the source-migration replay worker and factory/autoload registration. It acquires exclusive runtime and maintenance gates, checks the queued source/ledger fingerprint, creates a private SQL/archive rollback backup, preserves operational state inside atomic replay, and ends stale session/provider/job work while retaining its own normal completion lifecycle. Replay steps receive a remaining statement timeout and a rollback-on-expired-deadline check; the reserved lease is one hour.
- Extended the existing disposable migration/job suite with real pg_dump/archive verification, successful latest-version replay through Worker, unchanged source/applied fingerprint, and stale-fingerprint refusal without backup creation or retry. Unit checks: 1011; protocol files: 111. Full isolated integration and migration/job tests passed after regenerating source-reader inventory (176 relations, 1617 columns; cb5fb439420edb57a0c775d42740787eaf8bf21fc36bd1d988e9a2dfe014678f). Evidence: Temp replay-worker-tests.txt and replay-worker-inventory.txt.
- No web reset controls or live replay/reset have been enabled. Queue/status routes, cross-operation pending checks, typed confirmation UI and restricted-role/overlap/failure acceptance remain unfinished. Factory reset is a separate missing feature. This checkpoint does not complete Database Manager or the overall parity goal.
## Database version reset controls and protected ingress (2026-09-12)

- Database Versioning Manager now follows the reference feature/version/action table, with per-version Reset and Reset All Versions controls. Lorkhan keeps its gold accent and uses one keyboard-accessible typed-confirmation dialog. The dialog names the selected version and dependency count, warns about affected history/settings, and requires the exact Replay N confirmation. Reset invokes backed-up atomic source migration replay, never deletion of checksum ledger entries alone. Migration details remain inspectable.
- Added authenticated replay-plan/status endpoints and CSRF-protected form ingress. Stale fingerprints and invalid versions are refused; identical queued requests share one job. Replay refuses pending compact/backup/restore work, and those enqueue paths refuse pending replay. Status exposes only job identity/state/timestamps. The existing maintenance polling handles queued/running/success/failure and the exclusive runtime gate's 503 responses.
- A real restricted-owner worker test caught unconditional schema creation during ledger setup. Existing complete ledgers now skip bootstrap DDL; plan reads explicitly avoid initialization and pass under a SELECT-only role. No deployed role permissions were expanded. Read-only inspection confirmed the deployed lorkhan_runtime role already owns its Lorkhan database.
- Existing HTTP acceptance now covers unauthenticated plan refusal, invalid CSRF redirect without following it, typed confirmation/invalid version/stale source refusal, duplicate submissions, conflicting compact/backup/restore requests, actual replay completion under a nonsuperuser table owner, unchanged plan afterward, and a SELECT-only plan reader. An initial conflict fixture referenced a backup deliberately deleted by an earlier test; it now chooses an existing stored SQL backup. Full management HTTP suite passed (Temp replay-queue-http.txt).
- Extended the existing control-state test from version 3 to version 1, proving a complete source replay retains login, pairing, nonce, backup preferences/catalog and its job on the disposable database. Full integration/migration/job tests and unchanged inventory verification passed (Temp replay-all-tests.txt, replay-controls-final.txt); 1011 server checks and 111 protocol files passed.
- Offline browser acceptance used actual HTTP fixture markup and current assets at 1280/390 widths. Verified row/all dialog scope, focus, exact confirmation blocking, submitted fields, Cancel/Escape, queued/succeeded/dead messages, no page/dialog overflow and no actual POSTs. Visual review fixed the old first-column width and shared green-submit style overriding destructive controls. Evidence: Temp replay-page.html, replay-review.cjs, replay-table-{1280,390}.png and replay-confirm-{1280,390}.png.
- No live migration replay/reset or game operation was performed. Broader Database Manager parity remains open: independent factory reset, configured Database Access and legacy/external SQL import. The wider page/runtime matrix remains active; these controls do not close the overall goal.

## Configured Database Access card (2026-09-12)

- Added the reference Database Access tile and Open Database Manager link, preserving the existing grid/card/button structure. The link opens a separate tab with noopener/noreferrer. Unlike the reference's fixed login text, it leaves authentication to pgAdmin and does not render credentials.
- The private deployment setting database_admin_url is empty by default in the example configuration. The page accepts HTTPS URLs or HTTP on exact loopback hosts, rejecting userinfo, query strings, fragments, unsafe schemes and invalid URLs. Missing/invalid configuration renders a disabled button with configuration guidance; browser input and saved NPC/global settings do not control the destination.
- Existing management HTTP acceptance checks nine configured/missing/unsafe destinations using only fixture HTML rendering; no external link is visited. Full management HTTP tests and 1011 server checks passed. Offline browser checks of actual fixture markup passed at 1280/390 widths for enabled and disabled states, exact href/rel, keyboard focus, no overflow and no page errors. Screenshots were visually reviewed. Evidence: Temp database-admin-http.txt, database-admin-{enabled,disabled}.html, database-admin-review.cjs and database-admin-{enabled,disabled}-{1280,390}.png.
- The existing local pgAdmin endpoint http://127.0.0.1:8081/pgAdmin/ returned HTTP 200. It was assigned in private deployment configuration after creating a restrictive backup and comparing all other configuration values unchanged. No Apache deny rule, database permission or pgAdmin authentication setting was changed. The local card/link and the dedicated Lorkhan port restriction must remain part of deployment verification.
- Database Access is no longer an open feature in the current matrix. Legacy/external SQL import and independent factory reset remain open, along with the wider page/runtime parity scope. No live database reset or game operation was performed.

## Source-built factory archive prerequisite (2026-09-12)

- Traced the reference factory_reset branch: it rebuilds the selected database from database_default.sql, then applies source updates. This is distinct from per-version replay. Lorkhan's prerequisite now builds a clean baseline from all source migrations plus the same bundled Description, Biography and active Oghma catalog importers used by deployment. Installation-specific connectors/profiles still require provisioning after preserved installation identities are restored; deployed private configuration is never loaded by this builder.
- Added scripts/build-factory-database.sh and scripts/factory-database.php. The builder refuses root execution and existing output artifacts, creates a disposable PostgreSQL cluster reachable only through its private Unix-socket directory, applies source migrations/catalogs, and produces a custom archive. It restores that archive into a second disposable database before publishing output. Temporary clusters are stopped/removed on exit; no deployed database or provider is contacted. The PHP entry point is CLI-only.
- The manifest records migration and catalog-source fingerprints, table/seed counts, an exact ordered digest of every seeded table row, and archive hash/size. Build-versus-restored manifests must match. Per-build timestamps make archive/seed hashes vary between separate builds; each archive's own restored data must match exactly. The migration source fingerprint is a5f5bb6e80e68434a7e607e696fb9f288901e6896e23310ba4b58d3c7f2556fa; catalog fingerprint f7d7773bce570825dc4221eb50d18abdc2eb7841a93085a5de50a0940d242e5a.
- Extended the existing full integration shell runner with this build/restore proof. Final acceptance passed for 101 migrations, 167 base application tables and 39,479 seeded rows, with a roughly 9.3 MB archive. The first migration-only prototype had 221 rows; it was expanded to include bundled Morrowind catalogs before publication. Existing output/root refusal checks left the earlier archive hash unchanged. PHP/bash syntax, 1011 server checks, 111 protocol files and the complete integration/migration/job suite passed; schema inventory remained cb5fb439420edb57a0c775d42740787eaf8bf21fc36bd1d988e9a2dfe014678f. Evidence: Temp factory-build-proof.txt, factory-catalog-proof.txt and factory-full-integration.txt.
- This is a build/test prerequisite, not a factory reset implementation. No generated database archive is committed, no factory-reset route/UI is exposed, and no live reset was performed. Still required: private artifact installation during deployment, source/archive verification by a single-attempt reset worker, verified rollback backup, atomic replacement preserving control state, post-reset default connector/profile provisioning, strict confirmation/status UI and disposable failure/restore acceptance. Do not treat version replay or this archive generator as whole factory-reset parity.
## Transaction-ready factory SQL and default-provisioning rollback proof (2026-09-12)

- Factory artifact format 2 now includes INSERT-based factory.sql alongside the native archive, with separate byte counts and SHA-256 hashes. The builder filters schema/extension objects using PostgreSQL's archive table of contents, retaining administrator-owned schemas/extensions at the eventual target. It removes only pg_restore's generated outer framing and replaces it with fixed transaction-local encoding/string/function-body settings. This is a converter for the builder's own source archive, never an uploaded-SQL parser. SQL output is capped at 64 MiB.
- A third disposable database verifies the SQL through PDO in one transaction, matching the connection type used by DefaultConnectorProvisioner and MigrationReplayState. The test drops a pre-existing sentinel, imports the factory SQL, inserts a fixture installation, provisions actual default configurations, and forces a late SQL error. Rollback must recover the sentinel and remove the newly imported schema and provisioning changes. A subsequent successful transaction must reproduce the source seed digest. This proves default provisioning can participate in the reset transaction; it does not implement the reset worker yet.
- Seed-row hashing uses C collation explicitly so comparison does not depend on the deployment database's locale. The generator's psql bootstrap disables user startup files and password prompts. The rendered artifact does not inherit pg_restore's zero-timeout framing; the test supplies its own statement/lock limits.
- Passed PHP/bash syntax, 1011 server checks, 111 protocol files, and the full integration/migration/job suite including native archive restore and atomic SQL/default-provisioning failure recovery. Final baseline: 101 migrations, 167 application base tables, 39,479 seeded rows, approximately 9.5 MB archive and 36.5 MB SQL. Schema inventory remains cb5fb439420edb57a0c775d42740787eaf8bf21fc36bd1d988e9a2dfe014678f. Evidence: Temp factory-sql-proof.txt, factory-atomic-proof.txt and factory-sql-full-integration.txt.
- Factory reset remains unfinished. Next is a private-artifact verifier and single-attempt worker that checks source/hash metadata, makes a verified rollback backup, captures control state, replaces application relations inside its own transaction, verifies factory state, restores installation/pairing/login/backup/job state, and provisions defaults before commit. Artifact deployment and protected queue/status/confirmation UI remain required. No deployed database, permissions, credentials, provider or game was changed by these isolated build tests.
## Factory reset worker and failure recovery (2026-09-12)

- Added a private factory artifact verifier shared with the source-only builder. It checks source fingerprints, sizes, SHA-256 metadata, file permissions and actual SQL bytes before execution; it is not an uploaded SQL parser. The worker rechecks source fingerprints after the rollback backup and inside its transaction.
- Added a registered single-job factory-reset handler behind the exclusive runtime and maintenance locks. It creates a verified rollback backup, captures operational identities, replaces application relations/routines transactionally, verifies the exact factory row digest, restores login/pairing/backup/job state and provisions installation defaults before commit. Extension-owned objects and schemas are retained. Statement/lock timeouts and between-stage deadline checks do not constitute a hard whole-operation wall-time guarantee.
- The disposable worker test found PostgreSQL refusing to drop an identity sequence before its owning table. Reset now drops owning tables before remaining sequences. Tests require the intended source-mismatch and seeded-data-mismatch errors, rather than accepting an unrelated failed import as rollback proof. The successful case checks cleared history, removed old tables, retained operational identities, provisioned defaults and the rollback file hash.
- Factory reset remains unavailable from the UI. Private artifact deployment, restricted-owner acceptance, protected queue/status/typed confirmation and rollback-restore acceptance remain open, alongside the wider webpage/runtime parity matrix. No live reset, game launch or external provider was performed.
- Final checks: 1011 server checks, 111 protocol files, complete integration/archive restore/migration/job suite passed (Temp factory-worker-acceptance.txt). Inventory remains 176 relations and 1617 columns; updated reader metadata hash 26c32d2008627eb58ea101deefb1ae2016451c5abf43c90d9760c2d4538c39f6.

## Private factory deployment and restricted-owner recovery (2026-09-12)

- Local deployment now builds and publishes factory.dump/factory.sql/factory.json outside the web root under root:www-data ownership. The builder runs as PostgreSQL in a disposable socket-only instance using bundled source data. A deployment lock prevents overlapping publication; current switches atomically only after artifact integrity and deployed source fingerprints match. Old immutable releases remain available. This operation does not reset the live database or call providers.
- Verified the deployed worker and web users can read the factory SQL, while the web user cannot write it. The parent is root-owned and not writable by either service. Factory files are 0640 and their root-owned directory is 0750.
- Existing integration coverage now runs factory reset as a nonsuperuser database/table owner with administrator-owned extensions. The fixture releases its old connection's advisory locks before switching identities. Both deliberate failures reach their expected validation stage, valid reset succeeds, and the existing SQL-restore worker successfully restores the factory rollback archive, including original history, old table content and authentication identities.
- Factory queue/status/typed confirmation UI remains unfinished. The full parity goal remains active. No live reset, game launch or paid provider was used.
- Proof: 1011 server checks and 111 protocol files passed; complete restricted-owner reset/rollback, schema, archive restore, migration and job suite passed (Temp factory-owner-rollback.txt). Normal local deployment completed with preserved configuration/credentials/voices (Temp factory-integrated-deploy.txt). Inventory remains 176 relations/1617 columns with hash 26c32d2008627eb58ea101deefb1ae2016451c5abf43c90d9760c2d4538c39f6.

## Factory reset Database Manager controls (2026-09-12)

- Added the reference-style destructive Factory Reset Database card using Lorkhan's existing layout and gold theme. The red border required an explicit override of the shared theme. The dialog requires exact Factory Reset text and explains deleted data, preserved login/pairing/backups, rollback capture and the need to keep the game closed. Missing/unverified artifacts render an unavailable button rather than accepting reset requests.
- Authenticated status and CSRF-protected form ingress queue only the deployment-owned verified factory plan. Confirmation mismatch and stale fingerprints are rejected, duplicate submissions reuse the same single-attempt job, and queued factory resets fence maintenance, backup, restore and migration replay. Source/SQL validation is repeated by the worker; private SQL, configuration and credentials never enter browser responses.
- Complete management HTTP acceptance passed (Temp factory-ui-http.txt), including authentication/CSRF refusal, exact confirmation, stale source refusal, duplicate identity and four conflicting maintenance paths. Full runtime, restricted-owner reset/rollback, archive restore and migration/job suite passed (Temp factory-ui-integration.txt). Inventory stays at 176 relations/1617 columns with hash 26c32d2008627eb58ea101deefb1ae2016451c5abf43c90d9760c2d4538c39f6. 1011 server checks and 111 protocol files passed.
- Captured actual fixture markup and current assets passed offline Playwright acceptance at 1280/390 widths: dialog focus, empty/wrong/exact confirmation, serialized fields, Cancel/Escape/focus return, fresh confirmation on reopening, queued/running/success/failure labels, red warning border, no overflow, no JavaScript errors and no live POST. Screenshots were visually reviewed (Temp factory-card-1280.png, factory-dialog-390.png; runner factory-ui-review.cjs).
- No live reset, game launch or external provider was performed. Database Manager still lacks uploaded/legacy SQL import; the broader page/runtime matrix remains unfinished.

## Isolated SQL import reader (2026-09-12)

- Traced the reference's uploaded/server-file SQL restore and Lorkhan's trusted-archive requirement. External SQL must not be run against the live database or treated as a trusted native archive after sandbox execution. The planned import instead extracts untrusted data in isolation, validates it against source-owned schema, and applies data through the existing backed-up transaction/control-state workflow.
- Added an internal, currently unwired CLI reader using Bubblewrap user/network/PID/mount isolation, a read-only root, minimal account files, no host credentials or writable host mounts, and a 1 GiB scratch tmpfs. It starts a private socket-only PostgreSQL instance and emits a bounded normal JSON-lines stream. Fields use PostgreSQL text representations, preserving numeric precision, arrays, bytea, JSON and null. The caller must independently validate and cap all stdout; sandbox output is never trusted SQL.
- Installed Debian Bubblewrap 0.8.0-2+deb12u1 locally and verified unprivileged namespaces as the deployed worker account. Synthetic SQL could not read /etc/lorkhanserver/server.php or connect to the live loopback web port. Literal SQL-looking text and multiline values exported as data; high-precision numeric, bigint, arrays, bytea and null probes passed (Temp sql-import-sandbox-proof.txt, sql-import-types-proof.txt).
- The existing integration script now exports its actual database backup to SQL and reads it in the sandbox, verifying typed string/null rows and a complete stream. This is extraction proof only. Still required before web ingress: strict parent-side schema/stream validation, aggregate memory/PID/CPU/output limits, transactional data application with source-owned constraints and operational-state preservation, version compatibility, quarantine storage, protected upload/confirmation/status UI and full restore proof. No uploaded SQL or sandbox-produced schema has been applied to the live database.

## Parent-side SQL import data validation (2026-09-12)

- Added SqlImportData to validate a complete seekable quarantine stream before any database mutation. Destination base-table columns come from the existing trusted database, excluding extension-owned tables. The validator never executes SQL or trusts sandbox-provided DDL.
- Requires the fixed header, one section for every destination table, exact ordered declaration columns, row keys matching those columns, string-or-null values, and a terminal completion record. Unknown/duplicate table sections, rows outside the active section, extra fields, numeric JSON coercion, NUL text, malformed JSON, overlong lines and incomplete/trailing records are refused. The reader caps records at 8 MiB and the stream at 1 GiB, returns an exact SHA-256/count summary and rewinds the stream.
- Extended existing unit coverage rather than creating a new harness. The normal integration pipeline now validates its real sandbox-extracted SQL backup against the destination schema and independently compares the stream hash. This only proves structure and exact data transport; typed database constraints, migration compatibility and operational-state checks still belong in the transactional apply stage.
- SQL upload remains unavailable. Aggregate sandbox resource limits, bounded parent process capture, immutable quarantine storage, transactional data application and protected UI remain open. The full webpage/runtime parity goal remains active; no imported data was applied to the live database.
- Checks passed: 1027 server checks, 111 protocol files and the full integration/archive/migration/job suite (Temp sql-import-validation-proof.txt). Schema remains 176 relations/1617 columns with inventory hash 26c32d2008627eb58ea101deefb1ae2016451c5abf43c90d9760c2d4538c39f6.

## Typed transactional SQL import staging (2026-09-12)

- SqlImportData now stages validated string/null values into temporary tables cloned from trusted destination column types, defaults and check constraints. Identifiers resolve only through the destination map and values use prepared bindings. No imported DDL is executed and destination rows are not replaced by staging. Both schema and table identity must match their declared section.
- Staging requires the caller's transaction, rechecks destination columns, periodically checks progress/lease ownership and rehashes the entire quarantine stream before returning. A lost lease or changed stream throws; the caller must roll back. Temporary tables drop on commit/rollback. Unique/foreign-key validation, source-owned schema preservation and operational-state checks still belong to final application, not this staging-only result.
- Existing integration coverage runs as the nonsuperuser destination owner, compares every staged row with the actual SQL-backup source using EXCEPT ALL in both directions, and verifies rollback removes all staging tables without changing destination history. Invalid UUID input is rejected by the destination type. A failed lease callback stops staging and leaves no temporary tables after rollback.
- 1028 server checks, 111 protocol files and the complete integration/archive/import/migration/job suite passed (Temp sql-import-stage-final.txt). Inventory remains 176 relations/1617 columns with hash 26c32d2008627eb58ea101deefb1ae2016451c5abf43c90d9760c2d4538c39f6. No imported rows were applied to the live database. Transactional replacement, aggregate sandbox limits, quarantine/capture lifecycle and protected upload UI remain open; full parity is not complete.

## Transactional SQL data replacement (2026-09-12)

- Added internal replacement of validated staging rows using destination-owned columns, constraints, triggers and sequences. The current source migration ledger and installation identity must match before replacement. Imported SQL definitions are never applied to the destination. The caller still owns runtime/maintenance gates, rollback backup, control-state capture and the transaction commit.
- Preserves current login, pairing, nonce, backup preferences/catalog and the import job through MigrationReplayState. Imported queued/leased work is stopped rather than resumed. Historical attempts survive numeric ID collisions with the current worker through logical job/attempt restoration. Transactional sequence restarts preserve existing counter floors and roll back with failed replacement.
- The existing disposable-database integration covers schema mismatch, invalid foreign keys, forced late failure, successful replacement, unchanged control identities, historical attempt collisions, stopped imported queued work and post-apply schema inventory. SQL upload is not exposed by this change; aggregate sandbox limits, bounded capture/quarantine, job lifecycle, imported sequence provenance, legacy version compatibility and protected upload UI remain unfinished. No imported data is applied to the live database.
- Checks passed: 1028 server checks, 111 protocol files and the complete integration/archive/import/migration/job suite (Temp sql-import-replacement-final.txt). Post-replacement inventory remains 176 relations/1617 columns; source-reader references update its summary hash to be8bfbf9ae6c3097ecd6cbb0e0c362867efc9b161ec39864ee32a24e179748c0.

## Imported sequence provenance (2026-09-12)

- The internal sandbox stream now uses format v2 and includes owned sequence values as exact decimal strings plus boolean called/uncalled state. The destination supplies the required table/column ownership map; absent, unexpected, malformed or coerced metadata is rejected. Internal v1 streams lack necessary counter provenance and are refused; this does not change native backup formats or expose an upload route.
- Transactional application computes each next counter from the imported allocation, current allocation and surviving row maximum, using PostgreSQL numeric arithmetic. Deleted high allocations therefore remain reserved, including values beyond JavaScript's exact-integer range. Destination sequence bounds remain authoritative; invalid/exhausted allocations fail and roll back the replacement.
- Extended existing unit and integration cases. A real SQL dump carries allocation 9007199254740993 through sandbox extraction, validation and replacement as next value 9007199254740994. The destination begins with a lower counter. An out-of-range allocation and a forced late failure leave the original database/control identities and sequence state unchanged. Aggregate sandbox limits, bounded capture/quarantine, job lifecycle, legacy schema compatibility and protected upload UI remain open; full parity is not complete.
- Checks passed: 1034 server checks, 111 protocol files and the complete integration/archive/import/migration/job suite (Temp sql-import-sequences.txt). Both pre- and post-replacement inventory checks match 176 relations/1617 columns and summary hash be8bfbf9ae6c3097ecd6cbb0e0c362867efc9b161ec39864ee32a24e179748c0.

## Parent-bounded SQL sandbox capture (2026-09-12)

- Added an internal source-side capture wrapper around the sandbox. It independently caps stdout at 1 GiB, discarded diagnostics at 64 KiB and elapsed time at 610 seconds. Nonblocking reads drain both pipes; closed pipes and exited leaders with surviving pipe-owning children remain deadline-bound. Raw diagnostics, SQL and paths are not returned to the caller.
- Capture requires a caller-owned directory that is not writable by group/others. Data is written to a private random partial file, synced, made read-only and published without overwriting any existing destination. Failed, empty, oversized and timed-out captures remove their partial files. The result remains untrusted JSON and must pass SqlImportData validation; file existence is not a completed import job.
- An actual sandbox SIGTERM probe found that InterruptedError from the signal handler was swallowed by Python selectors. The handler now raises RuntimeError to unwind cleanup. The permanent existing-script regression verifies termination removes partial output and the owned sandbox process tree. Forced SIGKILL recovery and aggregate memory/CPU/PID containment still belong to the unfinished job/resource lifecycle.
- Checks passed: 1034 server checks, 111 protocol files and the complete integration/archive/import/migration/job suite with real capture (Temp sql-import-capture.txt). The subsequently added actual-CLI cancellation regression and the existing exact-limit, stderr-limit, nonzero/empty, closed-pipe and inherited-pipe timeout cases passed through the same integration-script block (Temp sql-import-capture-regression.py). Inventory remains unchanged. The wrapper is not web-exposed or deployed as a worker entrypoint; aggregate limits, quarantine/job lifecycle, legacy compatibility and upload UI remain open.

## Quickstart reference recap layout (2026-09-12)

- Restored Herika's plain four-card model recap presentation in place of Lorkhan's dropdowns inside every card. Normal, Player2 and Local cards share the reference label/detail typography, 12px padding, 8px corners and desktop/narrow grid. Existing saved-model selectors remain in a collapsed secondary disclosure; nothing was removed from the revisioned save contract. Missing selections open that disclosure. Actual Lorkhan model names populate the cards; reference hardcoded prices are not presented as prices for unrelated configured models.
- Fresh reference and source-rendered fixture screenshots were inspected at 1280/390 widths. Both have 467px desktop columns and 336px narrow cards. Browser checks cover recap modes, hidden/disabled selectors, draft retention, keyboard editing and intercepted successful submission of all four slot values, with no page errors or overflow (Temp quickstart-cards-current.cjs, quickstart-cards-reference.cjs and their PNGs).
- PHP lint, 1034 server checks and 111 protocol files passed. The management HTTP run passed the Quickstart section and captured current rendered HTML, then timed out on an unrelated Database Manager GET at management_http.py line 3538 (Temp quickstart-current-http.txt); the entire HTTP suite is not claimed green. This closes the recap presentation gap, not the entire Quickstart or full parity goal.

## Quickstart Local LLM panel markup (2026-09-12)

- Reused the reference select wrapper and explicit checkbox/label/help markup so the existing copied CSS actually reaches those controls. Removed the native checkbox override that bypassed the reference sizing; the control is 16px with zero margin and uses the gold accent. Moved the Player2 warning below the panel title, matching the reference hierarchy while retaining fieldset disabling.
- Replaced the duplicated footer explanation with the reference-style preset description, adapted to Morrowind. No preset values, endpoints, authentication or save mappings changed.
- Rendered the actual setup template with fixture values and composed it with the existing HTTP page fixture. Desktop/narrow browser checks passed for Local LLM selection, server port changes, explicit streaming-label interaction, Test failure/retry, Player2 warning/disabled state, retained drafts and intercepted local form submission without inactive normal model slots. Screenshots were inspected (Temp quickstart-panel-current.cjs and PNGs). PHP lint, 1034 server checks and 111 protocol files passed. This is scoped panel proof, not a claim that all pages or runtime features are complete.

## Voice Library missing reference sections (2026-09-12)

- Copied the Cloud PocketTTS Sync and OmniVoice Service section structure from pinned Herika ui/xtts_clone.php. Retained gold styling and private sample storage. Service documentation links use the selected connector, reject embedded credentials/non-HTTP URLs, and do not initiate service requests when rendered. Unconfigured and audio.cpp states remain explicit.
- PocketTTS Sync Voice Cache reuses the existing bounded browser queue with an explicit full-cache flag, including samples already reported by the provider. Other providers cannot use that flag; existing missing-only behavior is unchanged. Consent and CSRF checks remain enforced. No cloud provider or game was invoked.
- Desktop/narrow deployed/reference probes now have the same section headings, header geometry, section padding and upload-field measurements for PocketTTS and OmniVoice. Native narrow views have no horizontal overflow; the reference OmniVoice overflow was not copied. Inspected service-card screenshots (Temp voice-service-audit.txt and voice-service-card PNGs).
- PHP lint, 1034 server checks and 111 protocol files passed. Extended the existing HTTP script for full-cache upload, provider restriction and consent. A focused run of its voice section passed against the real isolated PHP/PostgreSQL runtime and mock provider (Temp voice-service-focused.txt). The full HTTP suite timed out on an unrelated five-second database-backup redirect before reaching voice tests; it is not claimed green.
- Deployed the three runtime files with backup /var/backups/lorkhanserver-voice-service.OrHLbP; all 858 deployed runtime files match source, with no extras or old paths. This closes two missing sections, not the whole Voice Library or full parity goal.
- Browser checks on the actual HTTP-rendered PocketTTS form passed at 1280/390 for mixed results, empty queue, rate limit, cancellation, malformed result, network failure, duplicate queue and cleanup warnings (Temp pocket-sync-review.txt, 16 scenarios). Requests were intercepted; no real provider uploads. The mixed-result narrow screenshot was inspected.

## Complete sample-library sync sections and hub comparison (2026-09-12)

- Re-read the raw direct audit instead of relying on its earlier summary: Cloud XTTS Sync and Cloud Chatterbox Sync were also missing. Restored both using the same pinned Herika section markup and native full-cache queue as PocketTTS. Full-cache mode is limited to XTTS, XTTS FastAPI, Chatterbox and PocketTTS; cloud-cloning connectors and OmniVoice retain their separate workflows.
- Extended the existing HTTP voice checks for both services: planning causes no upload, provider-listed local samples are included, each named upload returns one successful receipt, and the rendered section/button is present. Focused isolated PHP/PostgreSQL/mock-provider checks passed (Temp voice-all-sync-http.txt). Both actual HTTP-rendered forms passed mixed-result, empty-queue and cancellation browser cases at 1280/390 (12 scenarios, Temp cache-sync-*-review.txt). No production samples were uploaded.
- Compared all eight tabs inside the actual visible Configuration hub iframe at both widths, correcting the earlier harness that selected the first frame by URL. Native and reference iframe bounds match; all headers are present and all native tabs stay within their width. Fallback and pronunciation section inventories also match. Native/reference header font colors, backgrounds, borders and measured bounds match except the intentional gold note accent (Temp voice-hub-current.json and voice-header-style.cjs). The reference Cartesia heading typo and horizontal-overflow defects were not copied.
- Direct provider switches reload the page in both products (Herika switchTab assigns window.location.href); both discard an unsubmitted file selection. Switching the outer hub to TTS and back retains the selection in both products at both widths. This is verified matching navigation behavior, not a reason to add a new tab framework. No page errors were observed (Temp voice-tab-retention-audit.cjs and voice-hub-current.json). Selected screenshots inspected; provider/network acceptance remains limited to mocks.
- 1034 server checks and 111 protocol files passed. Deployed with backup /var/backups/lorkhanserver-voice-service.S9E1lN; all 858 runtime files match source, with no extras or old paths and private routes protected. GitHub workflow 330702270 remains disabled_manually. Full HTTP suite is not claimed green; the unrelated database-backup five-second timeout remains recorded above.

## TTS error states and full shared-provider hub interaction review (2026-09-12)

- Verified the existing deployed Test dialog at 1280/390 with intercepted 401, 403, 422, 429, 502, empty-audio, non-audio and network-failure responses. Every error returns the Run Test control, hides unusable audio and permits a successful silent-WAV retry. Raw mock provider diagnostics are not rendered. Opening does not submit; unsaved editor text is retained. Escape restores focus and clears audio; closing an in-flight request prevents its late result from restarting playback. No page errors or horizontal overflow (Temp tts-errors-current.cjs/txt; narrow rate-limit screenshot inspected).
- Exercised all seventeen shared service selections inside the actual visible Configuration hub iframe at desktop/narrow widths. Lorkhan retains service selection and shared editor drafts on Global Settings -> TTS return. Additional provider-local text/select drafts, Zonos and Mimic3/Kokoro/Deepgram numeric drafts also survive. KoboldCPP has no provider-primary field beyond the shared settings in the pinned reference. These are browser-only draft checks; no deployed connector was saved or tested against a real provider (Temp tts-hub-current.json, tts-hub-all-drafts.json and tts-hub-numeric-drafts.json).
- Reference hub returns can reset the TTS page to its connector list; Lorkhan's existing draft retention is preserved. Nested iframe tall-element screenshots are clipped by the iframe viewport and are not used as full-editor visual proof. Earlier direct-page field/layout and lower revision-surface comparisons remain the visual evidence, supplemented by the current modal and viewport checks.
- Refreshed the live primary-field inventory. The live Herika schema has drifted from the pinned 529364c reference: it now contains Cartesia Accent and Sonic 3.6 support, absent from the pinned conf/conf_schema.json and tts/tts-cartesia.php. Pinned schema SHA256 is 440c2f193cabf0331eaf88fc9f7060deed9a7b5b95833ce28c24a63d00a5d26e; live is e4304a341fe4a0d1cfd0189f5ef7de51df01732ecbb40fb9258fd8e5da68d11a. Do not count that newer field as a missing control against this goal's recorded baseline or silently copy evolving runtime files. It is a separate later-reference delta. Mimic3 Volume remains unused by the pinned active synthesis function, as traced above. XTTS/XTTS (legacy API) labels are already corrected in the current ConnectorCatalog.
- This closes the previously outstanding TTS provider-error and hub-return review against the recorded baseline. Existing provider/save/revision/embedded Save All checks are recorded in earlier checkpoints. No product-code change or redeploy was needed; live-provider and in-game acceptance remain untested and were not invoked.

## Quickstart whole-page closure and Create NPC context defaults (2026-09-12)

- Compared the complete Quickstart page in Default and Local LLM modes at 1280/390. All nine shared sections have the reference order, width, padding, border and corner measurements; no page errors or horizontal overflow. The native collapsed installation/profile selector remains a separate form after the main form. Existing configured model names, environment-managed key locking and honest service guidance remain intact.
- The full-page visual comparison found one remaining structural difference: final guidance and Save and Continue were outside the LLM Connectors Note card. Moved that closing section tag below the save controls, matching the reference without altering form ownership or handlers. Both widths now have the same 23px right/bottom button inset; no nested forms. Full-page desktop and corrected narrow footer screenshots inspected (Temp quickstart-whole-current.json, quickstart-footer-current.txt and PNGs).
- Current deployed browser checks passed key-write serialization, editing a newer draft while a save is pending, failed-key retention, retry, Unhide/remasking, clearing only the confirmed draft, and the final form submission. The editable Deepgram quick key exercised the shared handler with fake values; the live OpenRouter key is environment-controlled and correctly disabled, not bypassed for testing. All POSTs were intercepted. Fake key strings were absent from the final Quickstart form payload (Temp quickstart-footer-save-current.txt).
- The isolated HTTP suite through the full Quickstart block passed with a 30-second test-client timeout, covering its preceding local setup/preset/key checks plus complete save, stale Core/player revisions, invalid service/player input, service creation/reuse and Player2 enable/disable without overwriting saved model slots. This was a temporary runner using the existing test script, not a change to production timeouts. Evidence: Temp quickstart-http-current-verified.txt. The later portions of the full HTTP suite were not run by this scoped invocation.
- That run exposed fixture leakage from the new voice-cache checks; their three temporary connectors are now deleted before the existing voice-catalog cleanup assertion. It also exposed Create NPC context warnings because no resolved profile exists yet. The context Sections/Details catalog now falls back to typed global defaults only when those resolved maps are absent; existing resolved NPC values are unchanged. Existing HTTP coverage now checks the Create catalog maps. Live read-only confirmation found 13 boolean section defaults and 27 boolean detail defaults (Temp npc-create-context-current.cjs). This does not close the broader NPC override catalogue work.
- PHP lint, 1034 server checks and 111 protocol files passed. Both runtime files were deployed with backup /var/backups/lorkhanserver-quickstart-footer.95WJE5. All 858 runtime files match source; no extra files or old paths, private files remain forbidden and API authentication remains enforced. No live provider, microphone or game was invoked. Combined with the earlier Quickstart checkpoints, this closes its recorded page/interaction review; it is not full-goal completion.

## Core Global Settings Overrides card structure (2026-09-12)

Copied the pinned Herika Core Profile disclosure's provider-card, provider-head,
provider-title/icon and provider-body structure into the existing Lorkhan override
editor. Removed the older connector-card shell and gold bold summary override;
retained the existing catalogue, data attributes, inheritance and save handlers.
The intro now follows the reference small-text placement. No settings or runtime
semantics changed.

Evidence: `core-override-card-review.cjs` compared actual local Herika and deployed
Lorkhan editors at 1280px and 390px. Both report 12px padding, 1px border, 10px
rendered radius, flex headers with 10px gaps and rgb(224,224,224) titles. Lorkhan
has no horizontal overflow or page errors. Override enable/disable and keyboard
collapse/reopen passed without submitting any live changes. The reference page
itself overflows at 390px; that defect was not copied. Inspected the narrow native
screenshot. PHP lint, 1034 existing server checks and 111 protocol checks passed.
Scoped deployment backup: `/var/backups/lorkhanserver-core-override-card.allbPr`.
Runtime verifier: 858 matching files, no extras, old paths or hash mismatches;
private routes remain protected. This closes the card shell gap only, not the
remaining Core override catalogue, runtime features or whole-project parity.

## NPC Create override default values (2026-09-12)

The Create editor had no effective-settings document and guessed zero for numeric
settings or true for booleans. This produced invalid initial overrides, including
Oghma Topic Count and Max Summaries. Empty effective settings now use the existing
runtime resolver's default document before the catalogue is built. Saved NPC
resolved settings remain unchanged. These are server defaults for a new draft,
not a claim of dynamic inheritance preview when its installation/Core selection
changes.

Extended the existing management HTTP assertions to check every Create catalogue
integer range, boolean type and choice membership, plus concrete Oghma/memory
defaults. A temporary invocation of the current HTTP suite through the NPC create
catalogue passed against isolated PostgreSQL/PHP and mock providers (30-second
client timeout; no factory operations in this prefix). Later HTTP cases were not
run. PHP lint and 1034 server checks passed. `npc-defaults-review.cjs` checked all
45 deployed Create definitions at 1280/390 without any POSTs. Exact runtime
verification passed for 858 files and protected private routes. Deployment backup:
`/var/backups/lorkhanserver-npc-defaults.8E0q2L`. No game or paid provider invoked.

## Current LLM provider and advanced review (2026-09-12)

Retested actual deployed assets, removing stale worktree JS/CSS interception from
older temporary probes. `llm-advanced-current.cjs` passed desktop/narrow slider
sync, seven-value Clear, retained Temperature and disabled YAML drafts with no
writes. The live reference still does not sync numeric input back to the slider;
its slider-to-number and Clear behavior passes. `llm-loading-current.cjs` passed
mocked save/Test loading, repeated top position, close-while-pending and failed-save
Test refusal at both widths. No provider requests were sent.

Copied the pinned Core LLM page's Groq JSON Schema visibility rule into the native
service handler. Hidden draft values are preserved; switching away restores the
control. `llm-service-schema-current.cjs` passed native six presets plus Custom
at 1280/390; shared five non-Player2 presets retain model/Custom URL/key in both
products. The live reference keeps JSON Schema visible for Groq despite the pinned
source hide handler (core/llm_connectors.php:2138); this is an observed live/reference
difference, not a claimed equal rendered state.

New concrete remaining gap: pinned Player2 preset hides Model and API Key and clears
the explicit model on user selection (same file:743,766-770). Live reference also
clears the model; native retains a required model and shows these controls. Complete
native Player2 model omission/save/request semantics before copying the hidden-field
presentation. Do not close the LLM row from the five-provider checks above.

JS syntax, 1034 server checks and 111 protocol checks passed. Scoped deployment
backup `/var/backups/lorkhanserver-llm-groq-schema.8qTUmt`; exact runtime verification
passed for 858 files with private-route protection unchanged. Paid providers and the
game were not invoked.

## Player2 app-selected model and editor parity (2026-09-12)

Closed the Player2 gap identified in the preceding LLM review. The editor now
hides Model and API Key for Player2, clears the explicit model, removes its required
constraint, and restores visible/required model editing when switching away.
Server-rendered saved Player2 forms follow the same rule without JavaScript.
Existing game-key references remain server-held; explicit preset selection retains
the established No API key / LORKHAN game-key default.

Player2 saves normalize model to an empty string (including legacy placeholder
revisions); other services still require a nonempty model. All three actual
OpenAI-compatible adapters omit the model property when Player2 is selected:
dialogue, profile/diary/relationship generation, and Oghma extraction. Quickstart's
routing connector no longer creates the placeholder. No migration or live settings
rewrite is needed. Ordinary connector behavior is unchanged.

Evidence: 1036 existing/extended server checks and 111 protocol files passed.
The existing management HTTP suite through the new Player2 wire block passed on
isolated PostgreSQL/PHP with a local mock (30-second client timeout). It verifies
blank-model save/export and successful actual dialogue, generation and extraction
requests without model. The mock was corrected to permit model omission; the first
run failed on its former mandatory-model assumption, not an accepted green result.
Later HTTP cases were not run in this invocation. `player2-model-review.cjs` compared
actual deployed native/reference preset switching at 1280/390; Model/API Key hide,
model clearing and return behavior pass. No browser writes or paid requests. Narrow
native screenshot inspected. PHP/JS syntax passed. Scoped deployment backup:
`/var/backups/lorkhanserver-player2-model.JBpCzc`. Exact deployed comparison: 858
files, no hash mismatches/extras/old paths, private access still protected. Live
Player2-app and game acceptance remain untested; whole-project parity remains open.


## Complete Configuration hub navigation review (2026-09-12)

Reviewed all 15 enabled Configuration entries as one set using current deployed
assets: globals, profiles, NPCs, Player, Narration, LLM, TTS, TTS Studio, STT,
API Keys, NPC Biographies, Oghma, Descriptions, Action Editor and Prompts Manager.
`config-all-current.cjs` loaded each at 1280/390, then returned through every tab.
All documents retained their browser identity; eleven entry views containing
editable fields retained unsaved DOM values. The four list-only entries retained
their documents; editor-specific draft proofs are recorded separately. This is
mount/value retention proof, not a claim of save round trips for all fifteen pages.
All iframe bounds fit, all child documents report no horizontal overflow, exactly
one tab remained selected, and no page errors occurred. Screenshots and measurements
are in temporary `config-all-*.png` and `config-all-current.json`.

`config-nav-current.cjs` compared current reference/native labels, group order and
button styles at both widths. The shared fifteen labels match after the NPC brand
substitution and exclusion of ITT/Server Plugins. Padding is 4px 8px desktop and
4px 7px narrow; font size 12.3px, radius 6px in both. Native Home/End focus movement,
Enter/Space activation and one reachable tab stop per group passed. Inspected the
reference/native narrow navigation and native Action Editor embedding screenshots.
All browser POSTs were blocked; no settings, secrets, providers or game changed.
No product code or redeployment was needed for this review. Individual page/editor
and runtime requirements remain open in their own matrix rows; the shared shell
must not be repeatedly treated as an unreviewed fifteen-page feature gap.

## Narrator Dynamic Profile picker presentation (2026-09-12)

Copied the reference field-selection heading, instruction line and chip-text
markup/style into the Narration Dynamic Profile card. The title is no longer
rendered as muted help. The three checkbox names/values and persistence remain
unchanged; native speech_style maps to reference speechstyle. Added an accessible
name to the checkbox group. Gold branding remains.

Current native/reference browser comparison at 1280/390 reports identical title
size/weight/top margin (14.25px/600/8px) and chip text (12.768px/500). All three
checkboxes toggle and restore with Space in both products, without POSTs. No page
overflow; narrow native screenshot inspected. Probe: narrator-field-picker-review.cjs.
PHP lint, 1036 server checks and 111 protocol checks passed. Scoped final deployment
backup: /var/backups/lorkhanserver-narrator-field-picker.y0DY9P; pre-change backup:
/var/backups/lorkhanserver-narrator-field-picker.h54oz3. Exact runtime verification
passed with 858 files, no mismatches/extras/old paths, and protected private routes.

Investigated the apparent narrator default-Core selector discrepancy first. It was
not a bug: ManagementUiRepository sorts default_npc first, and ProductRepository's
effectiveSettingsForProfile uses the assigned Core or installation default. No
mapping change was made or claimed. Live reference has an extra Voice Filter field
absent from the pinned narrator source; do not silently add that later-reference
feature. Broader Narrator/runtime and whole-project parity remain open.

## NPC grouped override picker (2026-09-12)

Replaced the flat NPC Add Override list with the reference grouped category/name/
description layout from pinned ui/core/tmpl/override_editor.php. The existing 45
supported leaves are grouped under Misc, Rechat, Memory, Prompt, Context and Oghma.
Existing help is shown as a bounded 120-character preview and is included in search;
empty categories disappear under filtering. Text nodes preserve escaping. Options
remain actual buttons with stable accessible names, so keyboard use and existing
edit/save wiring remain intact. This is not an expansion of the supported runtime
catalogue, nor a claim that all reference settings exist.

Copied row padding/background/radius and title/description typography. Initial
visual inspection found global management styles overriding those rules; scoped
picker rules now render 10px padding, #2a2a2a background, 6px radius, 14px names,
12px descriptions and gold category headings. Inspected final narrow screenshot.
`npc-picker-review.cjs` passed all 45 leaves, six categories, description-only
UTF-8 search, category filtering, empty results, Enter-to-edit and Escape/focus
restoration at 1280/390. `npc-overrides-browser.cjs` passed existing add/edit/remove,
invalid JSON, parent-modal Escape and mocked 409 draft preservation (one intercepted
POST, no live settings writes). PHP/JS syntax, 1036 server checks and 111 protocol
files passed. Final scoped deployment backup:
/var/backups/lorkhanserver-npc-picker.j92U3x; original pre-change backup:
/var/backups/lorkhanserver-npc-picker.jTyjl4. Exact runtime verification: 858 matching
files, no extras/old paths, private routes protected. Full NPC/runtime parity remains
open, independently of this picker presentation improvement.

## Full management HTTP checkpoint (2026-09-12)

The complete management HTTP suite passed against isolated PostgreSQL/PHP using
current source. The first run exposed a stale test expecting an empty checkbox
value; Local Quickstart now correctly emits value="1". Updated that assertion
and verified the exported saved connector actually has options.stream=false.
The complete rerun ended with "browser-like management HTTP forms passed" and
exit code 0. Temporary runner used a 30-second client timeout instead of the
suite default 5 seconds; this is not proof of default-timeout CI performance.
Evidence: temporary parity-full-http-verified.txt and parity-full-http.py/.sh.

Fresh deployed metadata-live-current.cjs checks passed four Text/Tree/Table/Text
round trips each for Core and NPC editors at 1280 and 390 pixels, with all writes
blocked. npc-all-editors-current.cjs applied each of the 45 supported override
editors to an unsaved draft at both widths. These are draft interaction checks,
not 45 persisted round trips. Corrected stale matrix metadata-editor gaps; other
runtime catalogue and page-wide interaction requirements remain open.

This checkpoint changes test/documentation files only. Previously deployed
product code is unchanged; no deployment or game launch was needed.

## Core and NPC override category alignment (2026-09-12)

Compared the pinned lib/settings.php category rules and override_editor.php icon
rules with current deployed editors. Core had placed prompt timestamps, blacklists,
event filters and relationship chance under Context, and used one Oghma icon for
every row. Core now groups those controls under Prompt and renders appropriate
icons. Strict Rechat Targeting, Open Rechat and End Conversation Cooldown are Misc
in both Core and NPC, matching their reference runtime keys. Existing paths,
values, inherited defaults and Save All wiring are unchanged. No new setting or
runtime capability was added; unsupported catalogue categories remain open.

core-groups-review.cjs compared categories/icons for 21 shared reference/native
rows at 1280/390. Every native Core row toggles and restores enabled state, with
no writes, page errors or horizontal overflow. Inspected the narrow screenshot.
npc-all-editors-current.cjs rechecked all 45 typed draft editors at both widths.
PHP lint, 1036 server checks, 111 protocol files and git diff --check passed.
Scoped local deployment backup: /var/backups/lorkhanserver-core-override-groups.vPfBCy.
Exact runtime verification reports 858 matching files and no extras/old paths;
private routes remain protected. No provider call or game launch was performed.

## Core Profile whole-page inventory and Rules shell (2026-09-12)

Current deployed reference/native main Core heading inventories match from
Profiles & Memories through Quest at desktop and narrow widths. Corrected the
supported Global Settings Override category order to the reference subset:
Misc, Context, Oghma, Prompt, Rechat. Missing categories remain missing features.

Profile Rules previously used an 820px shell, 22.5px regular title, different
padding, and a footer New Rule button. Copied the reference Profile Rules title,
header action placement and help-panel structure, with native rule semantics
retained in the help. Copied measured shell/header/body typography and geometry:
95vw shell, 90vh maximum, 30px shell padding, 14px radius, 16px 20px header,
30px bold MagicCards title, 16px body padding/text. Narrow header/help wrap instead
of overflowing. Existing matching, priority, CRUD and error handling are unchanged.
Rules list/editor contents still need comparison; this is not whole Rules parity.

rules-layout-inventory.cjs reports matching shell/header/body measurements at
1280/390. profile-rules-shell-review.cjs passed empty/populated list, New Rule,
Cancel/focus, edit, mocked 409 save draft retention, delete cancellation and
Escape/focus at both sizes. One intercepted POST per viewport; no live writes.
Inspected desktop populated list and narrow editor screenshots. PHP lint,
1036 server checks, 111 protocol files and diff whitespace checks passed.
Scoped deployment backup: /var/backups/lorkhanserver-profile-rules-shell.mkcvWm.
858 runtime files match source with no extras/old paths, protected private routes.
No paid provider calls or game launch. Remaining Core/NPC/runtime requirements
stay open; this checkpoint does not close full webpage parity.

## Profile Rules cards and inline drafts (2026-09-12)

Copied the reference rule-card structure: title/status and Edit/Delete actions in
the header, followed by assigned-profile and match chips. Existing native priority
remains visible as a chip; empty matching remains an explicit never-runs warning,
not the reference catch-all behavior. Text uses DOM textContent throughout.
Copied 16px card padding, 8px radius, 20px bold title, status/chip typography,
spacing, borders and backgrounds from the pinned reference.

The existing revisioned editor now mounts inside its selected rule card; New Rule
creates a temporary card. Other rules remain visible. A stable DOM anchor preserves
the single form and its listeners across list refreshes. Card actions are disabled
while a request is pending. Direct Delete opens the existing confirmation without
sending a request. Match APIs, protected ownership, CRUD payloads and backend
matching semantics are unchanged. Advanced regex/action metadata features and
remaining detailed picker/form parity are still open, not completed exceptions.

profile-rule-cards-review.cjs passes empty/populated, New/Edit/Cancel, inline form
mount, mocked 409 retention, direct Delete/Keep, Escape/focus at 1280/390.
rule-card-parity-review.cjs compares actual reference/native rendered card/title/
status/chip padding, size, weight and radius, and verifies mocked successful save,
refresh and reopen at both widths. Inspected the final narrow populated card.
Mocks intercept all mutations; no live rules changed. JS syntax, 1036 server checks,
111 protocol files and diff whitespace checks passed. Original rollback backup:
/var/backups/lorkhanserver-profile-rule-cards.hWdogs; final deployment backup:
/var/backups/lorkhanserver-profile-rule-cards.SIFZgj. Exact runtime file verification
is rerun for final source. Full Core/NPC and project parity remain open.

## Profile Rules match pickers and editor actions (2026-09-12)

Copied the reference detected/custom entry pattern for Factions and Source Mods,
adapted to OpenMW factions/content files. Both offer detected-value dropdowns and
separate typed entry. All six native match fields retain exact case-insensitive
matching, typed values, datalist suggestions and existing limits. Suggestions are
sorted and exclude selected values; removing a value restores its suggestion.
Selected values now use reference rounded chips and an accessible cross button.
Native content-file semantics remain any matching source, not Skyrim's required
mod-set rule. No unsupported regex/metadata action controls were invented.

Save/Cancel now sit in the inline editor header with the form association preserved.
Rule Name/Assign Profile and Match NPCs When follow reference wording. Removed the
extra outer match fieldset border/padding; individual accessible fieldsets remain.
Copied picker input/button/chip styling with Lorkhan branding and narrow wrapping.

rule-pickers-review.cjs passed six-field typed add, case-insensitive duplicate,
remove, Enter, both detected dropdowns, selected-option removal/restoration, editor
header action placement, failed-save retention and cancel/focus at 1280/390.
Existing rule-card-parity-review.cjs also passed mocked save/refresh/reopen before
the final heading-only adjustment. Inspected narrow picker screenshot and removed
its redundant enclosing frame. All mutation requests were intercepted.
JS/PHP syntax, 1036 server checks, 111 protocol files and whitespace checks passed.
Original deployment backup: /var/backups/lorkhanserver-profile-rule-pickers.UOAURA;
final backup: /var/backups/lorkhanserver-profile-rule-pickers.hFGiql. Full runtime
comparison remains 858 files with no drift; no provider or game was invoked.
Remaining rule advanced capabilities, detailed field layout and broader Core/global
runtime catalogue requirements are still open; this is not project completion.


## LLM provider and advanced interaction closure (2026-09-12)

Closed the outstanding supported provider/advanced UI review against current
assets. llm-all-switches-review.cjs verifies all seven request switches in both
states, reset to inherited values, YAML text synchronization, mock read-only mode,
restoration of generation/YAML drafts, and configured-to-direct API-key handling
at 1280/390. No requests mutate live settings.

Reran llm-advanced-current.cjs (seven-field Clear, slider directions, retained
Temperature/YAML), llm-loading-current.cjs (mocked Save/Test, pending close,
repeated scroll position, refusal after failed save), and player2-model-review.cjs
(current native/reference model clearing, hidden fields and return). All passed.
The older service probe initially failed because it expected Player2 to retain
its model; updated that temporary probe to cover the five model-bearing presets
plus Custom, with Player2 covered separately. The corrected native/reference
service/schema probe passes at both widths. Pinned/live Groq schema visibility
and live reference number-to-slider differences remain recorded, not concealed.

Corrected the configured-connection help: endpoints inherit runtime configuration,
but API Key can inherit, select a server-held key or explicitly send none. No
routing behavior changed. Scoped deployment backup:
/var/backups/lorkhanserver-llm-inherited-help.ZurvSn. Existing complete HTTP-suite
checkpoint covers management persistence; current checks here are browser drafts
and intercepted Save/Test, not live provider acceptance. Other page/runtime rows
and the full goal remain open.

## Narration prompt editor interaction verification (2026-09-12)

Reproduced a concrete inline prompt save bug using a mocked success response
without revision: the modal closed and expected_revision became "undefined".
Prompts Manager's inline Narration branch now requires the same positive integer
revision receipt as the embedded Core editor before changing previews, default
values or closing. Missing/malformed receipts retain the draft and previous revision.
The ordinary Prompts Manager reload path is unchanged.

narrator-receipt-probe.cjs reproduces the before state and verifies the fixed
retained editor/error. narrator-all-prompts-review.cjs exercised all ten deployed
Narration prompt dialogs at 1280/390: missing, zero and string revisions rejected;
valid save, reopen, exact custom text, Clear/default restoration, and preservation
of an unsaved Narrator Name. Fifty intercepted requests per viewport, zero live
writes and no page errors. This is UI receipt handling, not paid generation proof.

JS syntax, 1036 server checks, 111 protocol files and diff whitespace checks pass.
Scoped backup: /var/backups/lorkhanserver-narrator-prompt-receipt.gGAcFZ. All 858
runtime files match source with protected private routes and no extra/old paths.
Live Herika's Narrator now exposes additional evolution scheduling controls beyond
the pinned 529364c4 source; those were observed, not silently imported or counted
as existing baseline parity. Broader Narrator Core semantics and page acceptance
remain open independently of the ten prompt editor interaction checks.

## Core portable preset round-trip repair (2026-09-12)

The portable Core Profile allowlist dropped Open Rechat, Strict Targeting,
End Conversation Cooldown and Relationship Update Chance despite saving those
settings in the editor. Export and import now preserve the existing typed values.
The existing management HTTP test now asserts all four fields and re-exports the
imported profile to prove behavior and relationship settings survive the round trip.
No routing assignments, credentials or new settings were added to the format.

PHP lint, 1036 server checks, 111 protocol files and the complete isolated
management HTTP suite passed (temporary HTTP client timeout 30 seconds rather
than the default 5 seconds). Local server deployment completed with all 858
runtime files matching source, no extra/old paths, health responding and private
routes protected. Existing configuration, credential and voice content preservation
was checked by the deployment wrapper. No game or paid provider was exercised.

Visible-page copying and adaptation remain the priority. Missing runtime features
remain separate from layout acceptance; this repair does not close the full matrix.

## Profile Rules simple editor structure (2026-09-12)

Copied the pinned Herika rule-edit-core and picker arrangement into the native
Rules form: Name / Assign Profile / Enabled share the desktop row; the match
heading has an inline explanation, labelled icon cards follow the reference
field order, and native Class matching follows the shared fields. Extra visual
help labels became accessible descriptions. Existing detected/custom pickers,
exact-match semantics, saved values and all six native match keys are preserved.
Priority is a separate disclosure rather than another main-field row. Invalid
priority expands it before focus, and valid collapsed priority still submits.
Editor titles now identify the rule and show its saved enabled status.

rules-fields-compare.cjs compared actual live reference/native name and picker
inputs, remove controls, cards and labels: padding, font size/family, radius and
minimum height agree at desktop. Screenshots inspected at desktop and 390px;
Rules remains a single-column, contained editor at narrow width. Extended existing
temporary rule-pickers-review into rules-fields-review.cjs: both widths pass all
six typed pickers, two detected selectors, duplicate/remove behavior, failed-save
retention, invalid hidden-priority reveal/focus, negative priority submission,
cancellation, delete confirmation and Escape/opener focus, with no live writes.
rule-card-parity-review.cjs also passes measured card checks and mocked successful
Save/reopen at both widths. PHP/JS syntax, 1036 server checks, 111 protocol files
and all 858 exact deployed files pass. Original scoped rollback is
/var/backups/lorkhanserver-profile-rule-fields.is3RYK; final deployment checkpoint
is /var/backups/lorkhanserver-profile-rule-fields.33SJHV.

Advanced regex/action metadata and native source-mod matching differences remain
open runtime work; this does not claim those controls or full matrix completion.

## Narration whole-page layout and draft pass (2026-09-12)

Fresh deployed/reference review confirms the ten main section headings and order,
two-column desktop / one-column narrow layout, and shared grid spacing, section
padding, heading typography, toggle rows, name input and persona text controls.
The native shared density sheet incorrectly reduced outer Narration cards from
10px to 6px corners; a Narrator-only correction restores the reference radius
without changing Player styling. Removed the overridden duplicate radius.

narrator-whole-review.cjs and narrator-whole-styles.cjs exercise every native
checkbox by keyboard on/off/restoration, every available Core Profile selection
and its connector summary, and capture full-page desktop/390px screenshots.
Both viewports have no horizontal page overflow, page errors or non-GET requests.
Measured grid/card/heading/toggle/input properties agree after the correction.
Screenshots inspected; no paid generation, microphone or game activity occurred.
This live native installation currently renders the create-Narrator state, so
saved-profile import/generation acceptance is not inferred from this review.
Existing ten prompt-dialog tests and isolated management persistence tests remain
separate evidence. Live reference Voice Filter/evolution schedule additions and
broader Core semantics remain open features, not silently counted as matched.
Scoped deployment: /var/backups/lorkhanserver-narrator-section-layout.9MQjvu.
