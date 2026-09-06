# Third-party notices

## Home dashboard word cloud

The Home dashboard vendors D3 7.9.0 (ISC) and d3-cloud 1.2.7 (BSD-3-Clause)
to use HerikaServer's word-cloud layout without external runtime scripts.
Unmodified distributions and their full licenses are in `ui/lib/ui/d3/`.

- D3: https://github.com/d3/d3/tree/v7.9.0
- d3-cloud: https://github.com/jasondavies/d3-cloud/tree/v1.2.7

## Dwemer Dynamics shared server UI

NPC relationship table, affinity tiers, type icons, signals, build/custom-type
dialogs and recent-change presentation derive from HerikaServer
`529364c4c12b3a8bd4cc12a481f400ce19b3a344`,
`ext/relationship_system/relationship_editor.php`, `lib/relationship_manager.php`
and `ui/core/npc_master.php`. Lorkhan retains actor identities, playthrough scope,
revisioned row saves, private notes and authenticated build requests.

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
