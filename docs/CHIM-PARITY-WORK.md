# Supported CHIM parity implementation

## Baseline and scope

Reference: HerikaServer `b08ffba70178abca08d7023c9304f46d57139045` and CHIM
`c4249ef4cc8b70ef412afc157ff23d665532b207` (origin/unstable). Starting points:
LorkhanServer `49af2c167d5cf937cba5761645490540b9d61b08` and LORKHAN
`563519e4de73ec2b7397094d3af88889368d4f5b`. Publish directly to each repository's
main, without new PRs, as requested. Do not launch the game.

The plan was to close missing working controls first, then align page grouping,
readers and tables, then connect the applicable OpenMW settings and events.
The UI delegation attempt stalled; Codex completed the implementation fallback.

## Completed implementation

- [x] Global Settings: semantic memory and summary scheduling controls, minimum
  event count, and independent Core short/mid/long memory switches wired to context.
- [x] Profiles: working independent Core Clone; Narrator Core routing and resolved
  connector labels; four narration filters with existing asterisk defaults preserved.
- [x] TTS Studio: explicit cloud voice discovery/cloning, consent, resumable batch
  synchronization, race/gender fallbacks, catalog voice IDs, and actual test audio.
- [x] Connectors: working endpoint/credential presets, custom credential references,
  bounded credential tests, and removal of inert format controls.
- [x] Roleplay: scoped filters before pagination, readable entries, export/edit/delete
  where applicable, and sequential Narrator read-aloud in the web readers.
- [x] Control: frozen prompt inspection, complete response text and state, real
  provider usage reporting, protected audio playback, and snapshot file selection.
- [x] Biographies and prompts: reusable template creation, Oghma links, and atomic
  prompt CSV import/export through existing typed revision services.
- [x] OpenMW: Interact settings editor for Global/Core/NPC with revision and session
  fences, consistent gold branding, and automatic-diary native request binding.
- [x] Game events: level-up/combat-end and sleep/wait observations, bounded RPG
  comment requests, background event history, and no automatic action authority.
- [x] Books: opt-in Narrator read-aloud with sentence queuing, pause-safe playback,
  and immediate cancellation when the book/scroll menu closes.
- [x] Feature metadata and protocol manifests reconciled with implemented behavior.

## All supported navigation pages

The inventory covers 35 main navigation destinations. Existing shared layouts and
working controls are retained where already aligned; not every page needed a rewrite.
Lorkhan gold remains RGB(188,157,90). Shared tab sizing is consistent across hubs.

| Group | Pages | Treatment |
| --- | --- | --- |
| Home | Home | Retain shared header, navigation and existing dashboard |
| Configuration / Characters | Global Settings, Core Profiles, NPCs, Player, Narrator | Correct ownership, inherited values, compact forms and working controls |
| Configuration / Connectors | LLM, TTS, TTS Studio, STT, API Keys | Retain connector layout; close voice, preset and credential workflow gaps |
| Configuration / Content | NPC Biographies, Oghma, Descriptions, Action Editor, Prompts | Retain existing editors; add missing biography and CSV workflows |
| Roleplay | Events, AI Responses, Adventure, Memories, Diaries, Books, Journal | Shared hub; scoped filters, pagination and entry readers; preserve specialized memory/event controls |
| Control | Server Logs, Request Logs, Oghma Audit, Relationship Logs, Cost Breakdown, Response Queue, Provider Attempts, Jobs, Game Debug, Audio Cache, Playthrough Manager, Database | Shared hub and tables; richer request/queue/cost/cache/snapshot views |

## Deliberate product boundaries

Background Life, AI Quest Manager, Active Quests page, Soulgaze Gallery, ITT
connectors, and server plugins remain removed. The Morrowind Journal is retained.
Do not copy Skyrim-only events or grant models console, code, filesystem or network
authority. Unmerged CHIM features are not part of this reference baseline.

This is supported workflow parity, not literal engine or storage equivalence:

- Typed Lorkhan action/response contracts remain; arbitrary YAML/prefill options are
  not presented as working settings.
- Generated diaries have web read-aloud. They are not materialized as inventory
  books, and there is no new in-game diary reader in this pass.
- No lockpick event is synthesized from generic Unlock observations: OpenMW cannot
  establish player lockpicking versus spell provenance from that hook.
- Snapshot records are configuration snapshots, not OpenMW saves or full database
  restores. Global preset v3 includes memory summary and MiniMe policies; v1/v2
  imports preserve policies they do not carry.
- Cost displays use reported provider usage only; missing historical usage stays
  unknown. Cloud discovery/cloning requires explicit user requests and credentials.

## Evidence and local delivery

Existing checks were reused; no new test files or separate validation project:

- Server: 277 unit checks; existing management HTTP forms suite; database integration,
  migrations/jobs and backup/restore checks; PHP lint and changed JavaScript syntax.
- Client: 66 Lua checks, 38 schemas/60 fixtures, native bridge checks, and the actual
  Windows OpenMW build/deploy. Server protocol manifest matches 98 client files.
- Rendered browser checks: Global Settings and Diaries, not a pixel comparison of
  every page. Full live provider and in-game audio/menu testing was not performed.
- Nine obsolete assertions in the existing HTTP suite were updated for the new
  headings and controls. Authentication, CSRF and persistence checks remain intact.

Local client target: `C:\Modlists\LORKHAN`. Server target:
`DwemerAI4Skyrim3:/var/www/html/LorkhanServer`, backend port 8090, exposed through
`http://127.0.0.1:7514/LorkhanServer/ui/home.php`.

Pre-deployment database backup:
`/var/lib/lorkhanserver/backups/parity-20260904/pre-parity.dump`.
Deployment preserves configuration, keys, database, voices, media, logs and saves.
The game was not launched.

Provider API references used for the bounded cloud adapter:
[Cartesia clone](https://docs.cartesia.ai/api-reference/voices/clone),
[Inworld clone](https://docs.inworld.ai/api-reference/voiceAPI/voiceservice/clone-voice),
[Inworld list](https://docs.inworld.ai/api-reference/voiceAPI/voiceservice/list-voices).

## Follow-up completion (2026-09-04)

- [x] Legacy session handshake fixed in `b068ba7`: normalize persisted v1/v2
  settings before strict client projection. Actual saved revision 1 had seven
  missing Narrator keys; the normalized response validates without modifying it.
- [x] Reset NPC now reapplies non-empty matched biography fields as a new revision,
  with confirmation and an expected-revision check. It uses stable OpenMW identity,
  preserves voice, routing, actor bindings and history, and follows Auto Lock Profile.
  A missing template returns an error without changing the NPC. Profile Versions
  has a separate visible button alongside event history.
- [x] Quickstart page: select an existing Core Profile's Standard/Fast/Powerful/
  Experimental models and installation speech connectors in one atomic save.
  The page links to model, key, TTS, STT and memory setup; it does not contact
  providers, regenerate characters, switch the active game model, or create saves.
- [x] Global preset v3 exports/imports memory summary scheduling and MiniMe settings.
  Import validates all policies and saves them with Global Settings atomically.
  Older preset formats remain accepted and do not overwrite omitted policies.
- [x] Feature descriptions corrected where they inaccurately described implemented
  workflows or reference-only controls. The six user-excluded systems stay removed.

Quickstart is an additional setup destination beyond the original 35-page main
inventory. Its section order, model roles, card hierarchy and links follow the
Herika setup workflow while retaining the typed Lorkhan services and key store.
No new game, game launch, save mutation, profile reset or live provider request is
part of this continuation. In-game dialogue confirmation is separate and remains
unverified until the user next talks in their existing save.

Follow-up evidence: 277 unit checks, the existing management HTTP suite and
PostgreSQL integration/schema checks pass. The HTTP suite exercises preset memory
round-tripping, Quickstart saving and stale-revision rejection in disposable data.
Read-only browser inspection covered Quickstart and the NPC editor, including the
Profile Versions anchor. No real profile was saved or reset during those checks.

### Reference finding: Worst Memory Lifespan

At pinned HerikaServer `b08ffba7`, `PLAYER_WORST_MEMORY_GAME_DAYS` occurs in
`lib/core/prisma_settings_catalog.php`, `ui/cmd/settings_portability.php`, and
`ui/global_settings.php`, but has no runtime reader in that tree. It is not an
implemented source feature to port in this pass. Do not label an inert Lorkhan
control as implemented memory expiry.
