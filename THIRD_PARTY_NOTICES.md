# Third-party notices

## Home dashboard word cloud

The Home dashboard vendors D3 7.9.0 (ISC) and d3-cloud 1.2.7 (BSD-3-Clause)
to use HerikaServer's word-cloud layout without external runtime scripts.
Unmodified distributions and their full licenses are in `ui/lib/ui/d3/`.

- D3: https://github.com/d3/d3/tree/v7.9.0
- d3-cloud: https://github.com/jasondavies/d3-cloud/tree/v1.2.7

## Dwemer Dynamics shared server UI

NPC relationship table, affinity tiers, type icons, signals, build/custom-type
dialogs, lock/clear controls and recent-change presentation derive from HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`,
`ext/relationship_system/relationship_editor.php`, `lib/relationship_manager.php`
and `ui/core/npc_master.php`. Lorkhan retains actor identities, playthrough scope,
revisioned staged manual saves, private notes and authenticated build requests.

Relationship LLM Logs header/statistics, type controls, cleanup bar, four-column
table and inline context-reader presentation derive from HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`, `ui/relationship_logs.php`.
Lorkhan retains native evaluation/build receipts, explicit missing-recording
labels, keyboard-accessible context/confirmation controls and protected log removal.

Oghma Audit's header, toolbar, metadata grid and five trace sections derive from
HerikaServer `529364c4c12b3a8bd4cc12a481f400ce19b3a344`, `ui/oghma_audit.php`.
Lorkhan retains typed retrieval evidence, recorded turn input and secondary native
filters/details; absent elapsed timings are explicitly marked as not recorded.

Server Logs panel geometry, search/severity controls, toolbar icons and expanded
reader presentation derive from Dwemer-Dashboard `7c19d3ddb7fa7aaf9cbd71abc41a1cdb4b7f9758`,
`distro_debugger.php` and `css/distro-debugger.css`, the actual embedded counterpart
used by HerikaServer's Control Panel. Only Lorkhan log sources are read; the
dashboard's cross-product navigation, MCP chat and credential helpers are not imported.

`ui/database_manager.php`, `ui/tmpl/database_manager.html.php` and
`ui/css/database-manager.css` derive their header, tool panels, selectable backup
cards and version table presentation from that same Dwemer-Dashboard revision's
`database_manager.php`, the Database Manager embedded by HerikaServer. Lorkhan's
configuration-only backup format, typed confirmations and migration history remain
native; dashboard database credentials and cross-product operations are not copied.

Audio Cache's heading, panel, file-list columns and inline player sizing derive from
HerikaServer `529364c4c12b3a8bd4cc12a481f400ce19b3a344`, `ui/cache_browser.php`.
Lorkhan keeps authenticated opaque media access and expiry, omits the excluded
Soulgaze image panel, and places installation/status filtering in secondary tools.

Response Queue's heading, seven-column striped table and row control presentation
derive from the same pinned HerikaServer revision's `ui/control_panel.php`,
`ui/index.php?table=responselog` and `lib/misc_ui_functions.php`. Lorkhan retains
typed response events and protects pending work from presentation-log removal.

Request Logs table, toolbar and payload-reader geometry derive from HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`, `ui/request_logs.php`.
Lorkhan retains its LLM attempt identities, safe recorded-message projections,
native keyboard-accessible dialogs and scoped presentation-only clearing.

Quickstart's section/card geometry and typography derive from HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`, `ui/quickstart.php`, with
Lorkhan colors, protected forms and existing connector identities retained.

API Keys card/section layout, custom editor controls and test-reader geometry derive
from the same revision's `ui/core/api_badge.php` and `ui/core/tests/apikey_test.php`.
Lorkhan retains status-only credential rendering and fixed authentication probes.

Action Editor table, Behavior controls and advanced/active reader presentation derive
from HerikaServer `529364c4c12b3a8bd4cc12a481f400ce19b3a344`,
`ui/function_editor.php`. Lorkhan retains its typed OpenMW action catalog, immutable
negotiated schemas and revision-checked policy persistence.

NPC Management's mass-profile dialog, Profile LLMs summary, editor tabs, diary
controls, movement cards and Roleplay fields derive from the same revision's `ui/core/npc_master.php`. Lorkhan
retains scoped Core Profile identities and excludes unsupported Skyrim features.

Prompts Manager's header, CSV/search panels, default/custom table and editor dialog derive from that revision's
`ui/prompts_manager.php`. Lorkhan retains revision checks, installation scope and
its supported prompt documents and player mood templates.

Player Management's toolbar, biography checkbox, TTS status and compact card layout
derive from that revision's `ui/core/player_management.php` and
`ui/css/player-narration.css`. Lorkhan keeps scoped player identity, revisioned
portable settings and typed OpenMW game statistics.

Narrator Management's toolbar, switch/field-chip presentation and selected profile
connector summary derive from that revision's `ui/core/narrator_management.php`
and `ui/css/player-narration.css`. Lorkhan preserves its supported narrator routes
and scoped, revisioned import forms.
Its inline Advanced Prompts table and reader also derive from that narrator page,
using the shared Lorkhan prompt dialog for both management surfaces. Event prompt
defaults remain Lorkhan's existing OpenMW instructions.

The LorkhanServer management interface derives its presentation structure and selected CSS, font,
Bootstrap, and image assets from the maintained Dwemer Dynamics server UI lineage used by
DialecticServer and StobeServer. The shared StobeServer distribution records this UI lineage under
the MIT License:

Copyright (c) 2026 Dwemer Dynamics

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
associated documentation files (the "Software"), to deal in the Software without restriction,
including without limitation the rights to use, copy, modify, merge, publish, distribute,
sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial
portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT
NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT
OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## UESP Morrowind reference material

The optional offline biography-generation tool can enrich official Morrowind NPC identity records
with material retrieved from the Unofficial Elder Scrolls Pages (UESP). UESP's MediaWiki rights
metadata identifies this material as Attribution-ShareAlike 2.5. Generated catalog artifacts retain
the exact source page and revision identifiers used for each character. UESP material is paraphrased;
raw page caches are local build inputs and are not distributed with LorkhanServer.

Source: https://en.uesp.net/wiki/UESPWiki:Copyright_and_Ownership

The NPC relationship Details dialog, field order, relation suggestions and staged
Save/Cancel interaction in `ui/tmpl/npc_relationships.html.php`,
`ui/js/resource-page.js` and `ui/css/herika-npcs.css` are adapted from the same
pinned HerikaServer `ext/relationship_system/relationship_editor.php` under its
MIT license. Lorkhan's native identity, private notes, revisioned storage and
OpenMW disposition handling are retained.

Voice Studio's primary cache, upload/cache/batch section hierarchy, status/ID
presentation and compact action styling in `ui/core/tmpl/voice_library_studio.php`
and `ui/css/herika-tts-studio.css` are adapted from the same pinned HerikaServer
`ui/xtts_clone.php` under its MIT license. Lorkhan keeps scoped connectors,
protected persistent samples and explicit cloud-upload authorization.

The multi-file picker and batch progress/count/result-log presentation in the
Voice Studio template, stylesheet and `ui/js/voice-batch.js` also follow the same
pinned `ui/xtts_clone.php` under its MIT license. Lorkhan retains authenticated
POST requests, bounded upload validation and protected sample storage.

The built-in pronunciation Edit/Cancel/Save/Delete interaction, pronunciation
column geometry and fallback field presentation in the Voice Studio template,
`ui/js/pronunciation-preview.js` and `ui/css/herika-tts-studio.css` follow the
same pinned HerikaServer `ui/tmpl/tts_pronunciations.php`,
`ui/css/tts-pronunciations.css` and `ui/xtts_clone.php` under its MIT license.

The Cost Breakdown template, `ui/css/herika-cost-breakdown.css` and
`ui/js/cost-breakdown.js` adapt the date/week controls and pie-chart presentation
from HerikaServer `ui/audit.php` at the same pinned revision under its MIT license.
Lorkhan retains its measured provider-attempt costs, installation scope and UTC
date boundaries.

`ui/lib/ui/chartjs/chart.umd.min.js` is Chart.js 4.5.1, distributed under the MIT
license reproduced in `ui/lib/ui/chartjs/LICENSE.md`. Copyright 2014-2024 Chart.js
Contributors. Source: https://github.com/chartjs/Chart.js/tree/v4.5.1.
The npm release archive was verified against its published SHA-512 integrity
before extracting these files; no package installation scripts were run.

`ui/tmpl/playthrough_manager.html.php` and `ui/css/herika-playthroughs.css`
adapt the header, active-state summary, paired panels and bounded backup-list
presentation from the same pinned HerikaServer `ui/playthrough_manager.php`
under its MIT license. Lorkhan's profile-scoped export/import semantics are
explicit; these controls do not implement Herika's full-schema snapshot switch.

The biography page `ui/core/npc_biographies.php`, shared dialog fields in
`ui/tmpl/biography_fields.php`, `ui/css/herika-biographies.css` and
`ui/js/biographies.js` adapt the page header, biography table, Add/Edit dialogs
and stacked Extended Profiles reader from pinned HerikaServer `ui/npc_upload.php`
under the same MIT license. Lorkhan preserves stable OpenMW record identity and
installation-owned imported templates instead of publishing those records into
global factory overrides.
The batch-upload guidance, complete custom-export control and factory-reset
control also follow that reference. Lorkhan's CSV adds explicit global/installation
ownership; its confirmation preserves active NPCs and non-template profile data.

The same biography page now adapts the inline Oghma viewer and alphabet/search
controls from pinned `ui/npc_upload.php`, with the article result shape described
by HerikaServer `ui/oghma_knowledge.php`. Lorkhan retains its runtime access
decisions and installation scope, safely renders article text, and pages the
catalog rather than truncating it or loading every row into the browser.

`ui/description_manager.php`, `ui/css/herika-descriptions.css` and
`ui/js/descriptions.js` adapt the compact header, paired panels, table and
Add/Edit dialog presentation from pinned HerikaServer
`ui/description_upload.php` under the same MIT license. Lorkhan retains its
installation-scoped OpenMW record keys. Optional Name/Description fields and CSV
handling now follow the reference editor contract.

The updated `ui/worldknowledge_upload.php`, `ui/css/herika-oghma.css` and
`ui/js/oghma.js` adapt the encyclopedia header, Article Search Logic panels,
filters, table badges and regular Add/Edit dialogs from pinned HerikaServer
`ui/oghma_upload.php` under the same MIT license. OpenMW knowledge scope and
factory override semantics remain Lorkhan's existing implementation.
The topic/alias substring search and optional Basic Description/Category fields
also follow this reference; runtime retrieval and knowledge access are unchanged.
The Delete All / Factory Reset toolbar order and entry-editor Delete control also
follow the reference. Confirmation dialogs, installation scope and persistent
factory-topic deletion handling are native Lorkhan adaptations.
The Dynamic Oghma tab, batch/category/table structure and quest-stage
Add/Edit fields in `ui/tmpl/oghma_dynamic.php` also derive from the same pinned
`ui/oghma_upload.php`. Native scope, revisions, confirmations and persistence do
not import Herika's database credentials or global quest ownership assumptions.

`ui/oghma_knowledge.php` and `ui/css/herika-oghma-runtime.css` adapt the
three-column knowledge reader, metadata chips, filter controls and typography
from the pinned HerikaServer `ui/npc_upload.php` Oghma modal under its MIT
license. Lorkhan retains standalone NPC navigation, scoped pagination and
runtime knowledge-access decisions.

`ui/tmpl/operational_log.html.php` adapts the pinned HerikaServer
`ui/request_logs.php` toolbar, metadata, table and status-pill presentation
under the same MIT license. `ui/provider_attempts.php`, `ui/jobs.php` and
`ui/css/operational-log.css` retain Lorkhan-specific monitoring data and
column sizing while reusing the already attributed Request Logs stylesheet.

`ui/narrative_manager.php`, `ui/tmpl/narrative_manager.html.php`,
`ui/css/narrative-manager.css` and `ui/js/narrative-manager.js` adapt the entry
table and content editor presentation from pinned HerikaServer `ui/diarylog.php`
and `ui/css/diary_adventure.css` under the same MIT license. Lorkhan retains its
native narrator/diary/summary kinds, scoped creation and diary generation, with
accessible native dialogs and the existing authenticated mutation endpoints.

`ui/diagnostics.php`, `ui/backup_health.php`, `ui/tmpl/backup_retention.html.php`
and `ui/js/backup-retention.js`
reuse the attributed Herika Request Logs operational reader. Summary tiles and
the retention instruction panel in `ui/css/operational-log.css` adapt the
Dwemer-Dashboard `database_manager.php` presentation at the revision above.
These are native Lorkhan diagnostic pages, not copies of a CHIM health API;
only recorded metadata and database counts are presented.

`ui/game_debug.php`, `ui/css/game-debug.css` and `ui/js/game-debug.js` use the
attributed Herika Request Logs header, compact buttons, table and status-pill
presentation. The fixed OpenMW command groups remain native Lorkhan controls;
HerikaServer has no equivalent game-command page.

`ui/tmpl/roleplay_reader.php`, `ui/tmpl/diary_entry_editor.php`,
`ui/css/roleplay-reader.css` and `ui/js/roleplay-reader.js` adapt the diary row,
separate content editor and paper/audio reader from pinned HerikaServer
`ui/diarylog.php` and `ui/css/diary_adventure.css` under the same MIT license.
Lorkhan retains scoped revisioned forms, escaped plain-text entries, native
accessible dialogs and the existing authenticated sentence-preview audio lane.

`ui/tmpl/adventure_log.php` and the Adventure-specific changes in
`ui/tmpl/roleplay_reader.php`, `ui/tmpl/roleplay_calendar.php` and
`ui/css/roleplay-reader.css` adapt the chronological table, location dividers,
speaker bands, calendar anchor and CSV presentation from pinned HerikaServer
`ui/adventurelog.php` and `ui/css/diary_adventure.css`, under the same MIT license.
Recorded OpenMW dates and full cell names replace Skyrim-specific date/location
parsing; source events remain unchanged.

OpenRouter model/provider catalogue dropdown and information-panel presentation in
`ui/js/llm-connectors.js` and `ui/css/herika-llm.css` derive from HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`, `ui/core/llm_connectors.php`.
Lorkhan retains its typed connector form, private credentials and explicit saves;
keyboard selection and viewport bounds are added to the shared presentation.
The configured-first API-key list, missing-key divider, status markers and
compact notice in the same LLM editor also adapt that pinned Herika source.
Its common request-switch checkbox presentation and field ordering follow the
same source while retaining Lorkhan's unchanged inherited option values.
JSON Schema and Prefill JSON also follow that editor and the request behavior in
pinned `connector/openrouterjson.php` and `functions/json_response.php`.
Their Lorkhan implementations use operation-specific OpenMW response contracts,
restore assistant continuations without duplicating full JSON responses, and
retain the existing response/action validation and private request audit.
The sidebar file-picker import flow and Clear advanced settings button also
follow the same pinned editor. Lorkhan retains portable JSON documents and its
credential-stripping import endpoint; browser feedback bounds file selections
and reports partial or unconfirmed imports without automatic retries.
Groq model-picker presentation follows the same editor's standalone Groq
dropdown and `ui/cmd/action_groq_get_models.php`: model ID, owner, context,
filtering and key selection. Lorkhan keeps a fixed authenticated discovery URL,
server-only credentials, bounded response fields and text-only rendering.

YAML body-parameter controls derive from the same pinned Herika editor and
`lib/core/llm_connector.class.php`. Lorkhan retains native message/delivery/action
ownership, JSON validation, CSRF and private credential storage.

Bundled YAML parser: Symfony Yaml v7.4.18, MIT, under
`lib/ThirdParty/SymfonyYaml`, with Symfony Deprecation Contracts v3.7.1
`function.php` and MIT license under `lib/ThirdParty/SymfonyDeprecation`.
Upstream: https://github.com/symfony/yaml/tree/v7.4.18 and
https://github.com/symfony/deprecation-contracts/tree/v3.7.1 .
Files are unmodified; native ext-ctype supplies character classification.
Parsing rejects aliases and unsafe tags and bounds input size, nesting and nodes.

Bundled editor: Ace Builds v1.23.4, BSD 3-Clause, matching the pinned reference's
Ace version. `ui/js/ace` contains unmodified ace.js, mode-yaml.js,
theme-ambiance.js and LICENSE from https://github.com/ajaxorg/ace-builds/tree/v1.23.4 .
Assets are served locally, with workers disabled and no CDN dependency.
The companion `editor-ambiance.css` contains the unmodified editor and Ambiance
CSS strings extracted from those pinned Ace modules. Lorkhan enables Ace's
strict-CSP mode and links this stylesheet instead of allowing injected styles.
TTS API Badge presentation in `ui/core/tts_connectors.php`, `ui/css/herika-tts.css`
and `ui/js/tts-connector-test.js` follows the same pinned Herika TTS editor:
configured-first labels, missing-key divider, status notice and cloud-only field.
Lorkhan stores only allowlisted server credential references and applies the same
selection to synthesis, discovery, cloning and account-scoped voice caches.

The provider-specific field order, labels, descriptions and choices in
`ui/core/tmpl/tts_provider_settings.php` derive from the same pinned Herika
`conf/conf_schema.json` and `ui/core/tts_connectors.php`. Inworld workspace
normalization, scoped discovery and clone routing adapt `tts/tts-inworld.php`
to the existing private credential library and bounded local voice cache.

OpenAI Instructions, Kokoro Speed and the complete ElevenLabs provider field
presentation in `ui/core/tmpl/tts_provider_settings.php` follow that same pinned
schema/editor. Their bounded native WAV request mappings adapt the matching
`tts/tts-openai.php`, `tts/tts-kokoro.php` and `tts/tts-11labs.php` behavior,
including Eleven v3 tags, normalization, latency and speaker-boost handling.

Azure Fixedmood, Region, Volume, Rate and Countour presentation follows the same
pinned schema/editor. The native SSML prosody and fixed-style mapping adapts
`tts/tts-azure.php`, preserving escaped text and the existing WAV transport.

Mimic3, MeloTTS, Piper and xVASynth field labels and descriptions in the same
provider template derive from the pinned schema. xVASynth examples use
Morrowind, and displayed defaults reflect the native Lorkhan adapters.

Deepgram Bitrate presentation and sample-rate mapping derive from the pinned
schema and `tts/tts-deepgram.php`. Zonos language choices, labels and descriptions
derive from `TTS.ZONOS_GRADIO`; native bounds and connector scope are preserved.

Chatterbox/XTTS paralinguistic controls derive from the pinned schema/editor.
`tts/ParalinguisticSpeech.php` adapts the enabled prompt and case-insensitive tag
allowlist behavior from Herika `main.php` and `lib/chat_helper_functions.php`;
native prompt provenance, bounded fields and provider-only text handling remain.

Core Profile Dynamic Profile toggle, Editable Fields card and field chips derive
from the pinned `ui/core/core_profiles.php`. Native discovery defaults seed NPC
content once and the existing bounded evolution worker supports all five fields;
Lorkhan branding and the OpenMW controls contract are preserved.

Core Profile named-preset toolbar, confirmation/name dialog presentation and
selection/save/overwrite/import/export flow derive from the same pinned
ui/core/core_profiles.php and ui/api/chim_profile_manager.php. Native CSRF,
installation scope, revision fences and strict portable payloads are retained.

## Bootstrap Icons trash icon

`ui/images/trash.svg` is from https://github.com/twbs/icons/tree/v1.10.5 (the icon version used by the reference Events page).

The MIT License (MIT)

Copyright (c) 2019-2023 The Bootstrap Authors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
