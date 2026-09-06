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
| `home.php` | `home.php` | Pending structural and populated-state comparison |
| `quickstart.php` | `quickstart.php` | Pending structural and populated-state comparison |
| `core/config_hub.php` | Same path | Shared geometry corrected; embedded child state comparisons pending |
| `global_settings.php` | Same path | Prompt/preset toolbar, grouped context selections, Oghma, connector cards/test dialog and blacklist browsers aligned; profile-affecting built-ins pending |
| `core/core_profiles.php` | Same path | In progress: response word limit, profile memory grouping, stacked settings/range controls, Copy to all and visible sticky toolbar; presets and additional profile fields remain |
| `core/npc_master.php` | Same path | Pending structural and populated-state comparison |
| `core/player_management.php` | Same path | Pending structural and populated-state comparison |
| `narrator_management.php` | `core/narrator_management.php` | Pending structural and populated-state comparison |
| `core/api_keys.php` | `core/api_badge.php` | Pending structural and populated-state comparison |
| `core/llm_connectors.php` | Same path | Connection/sampling column structure, service icons, numeric sliders, editable Name and compact help corrected; populated desktop and isolated create/narrow states checked; model browser, provider preference and additional request controls remain |
| `core/tts_connectors.php` | Same path | Populated Inworld/list layout compared; playable Test dialog, provider grouping/field identity, editable Name and collapsed raw options corrected; API-key selection and complete provider field mapping remain |
| `core/stt_connectors.php` | `stt_connectors.php` | Fixed-sample test/result reader, provider-specific fields, functional API Badge and independent service drafts compared; editable Name implemented; Google Free STT still pending |
| `core/voice_library.php` | `xtts_clone.php` | Pending structural and populated-state comparison |
| `core/npc_biographies.php` | `npc_upload.php` | Pending structural and populated-state comparison |
| `function_editor.php` | Same path | Pending structural and populated-state comparison |
| `prompts_manager.php` | Same path | Pending structural and populated-state comparison |
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
