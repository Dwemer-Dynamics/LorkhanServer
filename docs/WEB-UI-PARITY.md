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
- Books uses a table and content dialog. AI and book exports read the selected
  scope server-side; no credentials or provider configuration are exported.
- Adventure Log now reads recorded game events rather than generated narratives.
- Diaries and Adventure have UTC calendars, date navigation and scoped downloads;
  Diaries also has person selection and entry dialogs with existing revisioned forms.
- Memories replaces record cards with a scope/people/time/summary table and keeps
  revision details, edit/delete and summary controls.
- The semantic Roleplay `nav` inherited a legacy 20px font. Its base now matches
  Herika's 15px hub, fixing the oversized tab labels across the shared navigation.

### Explicit remaining Roleplay work

- Tamrielic calendar: Lorkhan diary projections currently store `gamets=0`. Do not
  fabricate Skyrim dates. Trace and preserve actual recorded Morrowind calendar
  context before implementing the counterpart's game-calendar mode.
- Clean Response Log and bulk diary operations need safe scoped POST semantics;
  do not copy Herika's destructive GET or erase immutable source events.
- Check diary/book populated browser states using isolated fixtures, not production
  seed records. Keep single-entry speech on explicit user clicks.
- Compare remaining Events controls and Journal structure, and finish Memories
  configuration/status hierarchy against current Herika.

## Complete page matrix

| Lorkhan page | Herika counterpart | Current status |
| --- | --- | --- |
| `home.php` | `home.php` | Pending structural and populated-state comparison |
| `quickstart.php` | `quickstart.php` | Pending structural and populated-state comparison |
| `core/config_hub.php` | Same path | Shared geometry corrected; embedded child state comparisons pending |
| `global_settings.php` | Same path | Pending structural and populated-state comparison |
| `core/core_profiles.php` | Same path | Pending structural and populated-state comparison |
| `core/npc_master.php` | Same path | Pending structural and populated-state comparison |
| `core/player_management.php` | Same path | Pending structural and populated-state comparison |
| `narrator_management.php` | `core/narrator_management.php` | Pending structural and populated-state comparison |
| `core/api_keys.php` | `core/api_badge.php` | Pending structural and populated-state comparison |
| `core/llm_connectors.php` | Same path | Pending structural and populated-state comparison |
| `core/tts_connectors.php` | Same path | Pending structural and populated-state comparison |
| `core/stt_connectors.php` | `stt_connectors.php` | Pending structural and populated-state comparison |
| `core/voice_library.php` | `xtts_clone.php` | Pending structural and populated-state comparison |
| `core/npc_biographies.php` | `npc_upload.php` | Pending structural and populated-state comparison |
| `function_editor.php` | Same path | Pending structural and populated-state comparison |
| `prompts_manager.php` | Same path | Pending structural and populated-state comparison |
| `worldknowledge_upload.php` | `oghma_upload.php` | Pending structural and populated-state comparison |
| `description_manager.php` | `description_upload.php` | Pending structural and populated-state comparison |
| `oghma_knowledge.php` | NPC knowledge viewer | Pending structural and populated-state comparison |
| `events-memories.php` | Same path | Pending structural and populated-state comparison |
| Roleplay `memory` tab | Herika Memories | In progress: summary table replaces cards; configuration and revisioned forms preserved; populated comparison pending |
| Roleplay `responselog` tab | Herika AI Responses | In progress: whole-turn log, prompt dialog, topics and scoped export implemented; clean-log workflow pending |
| Roleplay `diaries` tab | Herika CHIM Diaries | In progress: UTC calendar, person mode, entry dialog and export implemented; Tamrielic date mapping and bulk controls pending |
| Roleplay `books` tab | Herika Books | In progress: book table, content dialog and export implemented; populated visual check pending |
| Roleplay `adventure` tab | Herika Adventure Log | In progress: game-event projection, UTC calendar and export implemented; Tamrielic date mapping pending |
| Roleplay `journal` tab | Morrowind-only Journal | Pending structural and populated-state comparison |
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
- Existing checks passed after the Memory table/export refinements: 317 server
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
