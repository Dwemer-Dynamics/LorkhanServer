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
| `core/config_hub.php` | Same path | Shared geometry corrected; embedded child state comparisons pending |
| `global_settings.php` | Same path | Prompt/preset toolbar, grouped context selections, Oghma, connector cards/test dialog and blacklist browsers aligned; profile-affecting built-ins pending |
| `core/core_profiles.php` | Same path | In progress: response word limit, profile memory grouping, stacked settings/range controls, Copy to all and visible sticky toolbar; presets and additional profile fields remain |
| `core/npc_master.php` | Same path | Mass Core Profile switch, model summary, tabs, Roleplay and General diary controls compared; movement-card layout matches but NPC-targeted Visit/Teleport/Return is unsupported; remaining General, Relationships, Info and full editor/list review remain |
| `core/player_management.php` | Same path | Header/toolbar, biography checkbox, TTS status, card geometry and empty/populated stats compared; import reader and narrow controls checked. Player name editing, AI-generation guidance and per-player provider overrides remain pending |
| `narrator_management.php` | `core/narrator_management.php` | Pending structural and populated-state comparison |
| `core/api_keys.php` | `core/api_badge.php` | Preset/card geometry, custom Add/Save/Delete editors, replacement autosave and Test reader compared; protected credential identities remain separate, editable custom labels and full provider-badge consolidation remain pending |
| `core/llm_connectors.php` | Same path | Connection/sampling column structure, service icons, numeric sliders, editable Name and compact help corrected; populated desktop and isolated create/narrow states checked; model browser, provider preference and additional request controls remain |
| `core/tts_connectors.php` | Same path | Populated Inworld/list layout compared; playable Test dialog, provider grouping/field identity, editable Name and collapsed raw options corrected; API-key selection and complete provider field mapping remain |
| `core/stt_connectors.php` | `stt_connectors.php` | Fixed-sample test/result reader, provider-specific fields, functional API Badge and independent service drafts compared; editable Name implemented; Google Free STT still pending |
| `core/voice_library.php` | `xtts_clone.php` | Pending structural and populated-state comparison |
| `core/npc_biographies.php` | `npc_upload.php` | Pending structural and populated-state comparison |
| `function_editor.php` | Same path | Summary, filters, editable rows, Behavior controls, scoped saves and both readers aligned; populated/empty and narrow states checked. Negotiated OpenMW parameters remain read-only; see Action Editor evidence below. |
| `prompts_manager.php` | Same path | Full header/CSV/search/table/reader comparison completed; Default/Custom editing, safe Clear, CSV round trip and plain instruction creation implemented; desktop/narrow, populated/empty, search and keyboard controls checked; retained document tools and validation limits documented below |
| `worldknowledge_upload.php` | `oghma_upload.php` | Pending structural and populated-state comparison |
| `description_manager.php` | `description_upload.php` | Pending structural and populated-state comparison |
| `oghma_knowledge.php` | NPC knowledge viewer | Pending structural and populated-state comparison |
| `events-memories.php` | Same path | Events note, striped table, record heading, pagination/filter layout and recorded calendar dates corrected; populated live view and AJAX pagination verified |
| Roleplay `memory` tab | Herika Memories | Summary-only table, status/settings strip, scoped sync/delete, Tamrielic dates and compact editor implemented; 67 populated live summaries, empty fixture, Cancel/focus and narrow advanced tools checked |
| Roleplay `responselog` tab | Herika AI Responses | Whole-turn log, prompt dialog, topics, scoped export and protected clean-log workflow implemented; populated live table, prompt dialog and controls checked |
| Roleplay `diaries` tab | Herika CHIM Diaries | UTC/Tamrielic calendars, person mode, paper entry editor, export and bulk delete implemented; populated/empty fixture and existing HTTP checks passed |
| Roleplay `books` tab | Herika Books | Full-content striped table, game/UTC/TS columns and content dialog implemented; populated fixture and focus restoration checked |
| Roleplay `adventure` tab | Herika Adventure Log | Recorded game events, UTC/Tamrielic calendars and export implemented; live Tamrielic date selection verified against 21 matching events |
| Roleplay `journal` tab | Morrowind-only Journal using Herika's record table | Full-content striped table, Journal ID, game/UTC/TS columns and content dialog implemented; three live records, reader and focus restoration verified |
| `control_panel.php` | Same path | Shared geometry corrected; embedded child state comparisons pending |
| `request_logs.php` | Same path | Pending structural and populated-state comparison |
| `response_queue.php` | Herika response log composition | Pending structural and populated-state comparison |
| `cache_browser.php` | Same path | Pending structural and populated-state comparison |
| `relationship_logs.php` | Same path | Pending structural and populated-state comparison |
| `oghma_audit.php` | Same path | Pending structural and populated-state comparison |
| `playthrough_manager.php` | Same path | Pending structural and populated-state comparison |
| `server_logs.php` | Herika Server Logs | Pending structural and populated-state comparison |
| `provider_usage.php` | Herika Cost Breakdown | Pending structural and populated-state comparison |
| `provider_attempts.php` | Shared operational style | Pending structural and populated-state comparison |
| `jobs.php` | Shared operational style | Pending structural and populated-state comparison |
| `game_debug.php` | Shared operational style | Pending structural and populated-state comparison |
| `database_manager.php` | Herika database tooling style | Pending structural and populated-state comparison |
| `diagnostics.php` | Shared operational style | Pending structural and populated-state comparison |
| `backup_health.php` | Shared operational style | Pending structural and populated-state comparison |
| `narrative_manager.php` | Herika narrative/diary styling | Pending structural and populated-state comparison |

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
