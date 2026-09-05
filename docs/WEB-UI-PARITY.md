# Web UI presentation parity

## Reference and scope

Reviewed against Dwemer-Dynamics/HerikaServer `unstable` at
`529364c4c12b3a8bd4cc12a481f400ce19b3a344` on 2026-09-05. The reference repository
was not modified. Live comparison used `/HerikaServer` on port 8081; the deployed
reference theme, navbar, hub navigation, Global Settings and TTS Studio matched
that snapshot. The live Home page differs from the snapshot, so its screenshot
is layout evidence, not proof of an identical source revision.

This is presentation parity for supported Lorkhan pages, not replacement of the
OpenMW runtime with Skyrim code. Gold `rgb(188,157,90)`, Lorkhan branding, current
forms, provider choices, data ownership, permission checks and revision controls
remain. Removed release features stay removed: Background Life, AI Quest Manager,
Active Quests, Soulgaze Gallery, ITT and Server Plugins.

## Changes

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

## Page map

Paths below are relative to `ui/`. A shared-style counterpart means there is no
exact supported Herika page for that Lorkhan diagnostic; it does not claim feature
or data parity. Pages already using the current source geometry were retained.

| Lorkhan page | Herika counterpart / treatment |
| --- | --- |
| `home.php` | `home.php`; widget, statistics and table formatting |
| `quickstart.php` | `quickstart.php`; existing wizard and shared theme retained |
| `core/config_hub.php` | Same path; compact hub navigation |
| `global_settings.php` | Same path; current header, four settings tabs, rows and gutters retained |
| `core/core_profiles.php` | Same path; existing list/editor geometry retained |
| `core/npc_master.php` | Same path; toolbar, cards and inline auto-lock control |
| `core/player_management.php` | Same path; existing player sections and shared header |
| `narrator_management.php` | `core/narrator_management.php`; existing narrator sections and shared header |
| `core/api_keys.php` | `core/api_badge.php`; existing provider cards and gutters retained |
| `core/llm_connectors.php` | Same path; connector list/editor and unselected state |
| `core/tts_connectors.php` | Same path; existing connector cards and shared header |
| `core/stt_connectors.php` | `stt_connectors.php`; existing provider cards retained |
| `core/voice_library.php` | `xtts_clone.php`; samples, provider tabs, global fallbacks, pronunciations |
| `core/npc_biographies.php` | `npc_upload.php`; upload/catalog layout retained |
| `function_editor.php` | Same path; summaries, header, filters, actions and dialogs |
| `prompts_manager.php` | Same path; existing compact header, tabs and editor retained |
| `worldknowledge_upload.php` | `oghma_upload.php`; existing knowledge catalog presentation retained |
| `description_manager.php` | `description_upload.php`; existing upload/catalog presentation retained |
| `oghma_knowledge.php` | NPC knowledge viewer; scoped Lorkhan access view using knowledge cards |
| `events-memories.php` | Same path; Roleplay hub and live event panel |
| Roleplay `memory` tab | Herika Memories; existing memory tables and controls |
| Roleplay `responselog` tab | Herika AI Responses; Lorkhan response reader and recorded state retained |
| Roleplay `diaries` tab | Herika CHIM Diaries; existing diary reader retained |
| Roleplay `books` tab | Herika Books; existing reader retained |
| Roleplay `adventure` tab | Herika Adventure Log; existing reader retained |
| Roleplay `journal` tab | Morrowind-only Journal; shared reader styling, not Skyrim Active Quests |
| `control_panel.php` | Same path; compact navigation without excluded tabs |
| `request_logs.php` | Same path; framed log reader and bounded table |
| `response_queue.php` | Herika response log composition; shared reader style with Lorkhan delivery state |
| `cache_browser.php` | Same path; shared cache reader and bounded table |
| `relationship_logs.php` | Same path; compact audit header and existing monitoring table |
| `oghma_audit.php` | Same path; existing retrieval audit cards retained |
| `playthrough_manager.php` | Same path; compact header and existing playthrough cards |
| `server_logs.php` | Herika Server Logs; compact header and bounded log panels |
| `provider_usage.php` | Herika Cost Breakdown; shared reader panels and existing metrics |
| `provider_attempts.php` | Shared operational style; Lorkhan provider-attempt diagnostics |
| `jobs.php` | Shared operational style; Lorkhan durable worker/job diagnostics |
| `game_debug.php` | Shared operational style; OpenMW debug commands unchanged |
| `database_manager.php` | Herika database tooling style; Lorkhan safe backup/restore controls retained |
| `diagnostics.php` | Shared operational style; Lorkhan health snapshots |
| `backup_health.php` | Shared operational style; Lorkhan retention/backup records |
| `narrative_manager.php` | Herika narrative/diary styling; scoped Lorkhan narrative forms retained |

`core/character_manager.php` and `core/global_settings.php` redirect to their
canonical pages. `cache_audio.php` and `core/profile_portrait.php` are media
endpoints, not page designs. `ui_bootstrap.php`, `ui_features.php` and templates
are helpers; `placeholder.php` is not a released navigation destination.

## Evidence and limits

- Existing 317 server checks and 98 protocol files passed.
- Existing management HTTP form integration passed, including the changed header
  markup. Only three existing exact heading assertions needed updating.
- Integration vertical slice, durable jobs, migrations and the 165-relation schema
  inventory passed. No schema/runtime changes were made by this pass.
- Browser render checks covered 43 direct page/tab views at 1280x720 and 390x844:
  no page-wide horizontal overflow or broken visible images. The parameterized
  NPC knowledge viewer is checked separately by a real scoped GET.
- Paired screenshots reviewed dashboard, hubs, actions and monitoring readers;
  additional configuration families were compared during the initial audit.
  TTS pronunciation and Action Editor narrow layouts were inspected visually.
- All four Global Settings tabs switched to the expected sections. Action search
  found the single Follow action, reset restored 16 rows, and View Active Actions
  opened its dialog without saving or running an action.
- All 15 Configuration tabs selected the expected iframe. Each visible embedded
  panel retained 520 pixels of usable height at the 1280x720 desktop viewport.
- This is not a pixel-equality claim for different live data or different supported
  controls. Installations, immutable logs, revisions, OpenMW actions and race voice
  catalogs remain Lorkhan-specific. The word cloud still uses local CSP-safe chips.
- Live provider calls, destructive controls and every populated editor/modal state
  were not exercised. No game was launched or controlled for this UI work.
