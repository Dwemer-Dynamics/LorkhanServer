# LorkhanServer implementation ledger

Recorded baseline: 2026-07-19. Updated parity/CI audit: 2026-08-09. `AUTOMATED` means a repeatable local or CI no-game check passed. `PLANNED` means required implementation/proof is not complete. `EXTERNAL-DEFERRED` requires an unavailable external environment. `EXCLUDED` is a closed product boundary.

| Feature-matrix row | State | Current implementation / evidence |
| --- | --- | --- |
| Setup | AUTOMATED | Disposable PostgreSQL ordered migration fresh/up/rerun/down, dump/restore, and quickstart checks pass. The clean committed server is deployed to local WSL, Apache and the supervised worker loop are active, health passes, and persistent state is preserved. Production systemd/immutable-release upgrade and rollback remain unproven. |
| Sessions | AUTOMATED | Installation/profile/playthrough/runtime/content binding, monotonic generations, replacement and negotiated capabilities pass PostgreSQL integration. |
| Ingress | AUTOMATED | Pairing auth, query-token rejection, strict bodies, size/rate/idempotency, immutable source rows and correlation pass local tests. |
| Dialogue | AUTOMATED | Solo and deterministic bounded group/multi-utterance mock turns select only target/audience TES3 identities, persist durable utterances, and validate exact terminal delivery replays including speaker and timing. In-game playback remains external/unproven. |
| Providers | AUTOMATED | Turn acceptance atomically enqueues restart-safe worker processing before the immediate HTTP 202. Provider-neutral cooperative cancellation combines durable turn/session state, lease loss and deadlines; deterministic LLM/TTS mocks and redacted provider attempts are locally tested. Live connectors/credentials remain intentionally disabled. |
| Media | AUTOMATED | Deterministic legal WAV generation, private opaque storage, integrity/ownership/expiry serving and bounded first-party cleanup pass local tests; external game playback remains unproven. |
| Profiles | AUTOMATED | Server-owned installation-scoped profiles and playthroughs have immutable revisions, rollback, prompt/provider/action-policy configuration revisions and bounded CRUD APIs. Empty unlocked NPC profiles automatically queue one idempotent revision-fenced backfill after the installation-global 10-100 turn threshold (default 40). Actor-profile observations persist immutably, and the worker receives only the frozen bounded actor history; manual edits and locks win. PostgreSQL integration covers threshold, queue, source event, worker output, migrations, and backup/restore; live provider and in-game proof remain pending. |
| Memory | AUTOMATED | Successfully played dialogue produces idempotent scoped recent records. Event-driven durable jobs consolidate exact chronological groups of four eligible recent records into middle records and four eligible middle records into long records. An installation-scoped, default-off MiniMe policy can index exact memory and policy revisions and blend valid cosine similarity into prompt ranking; missing or failed vectors preserve the prior deterministic score per memory. Policy save never contacts the provider or queues history, while explicit bounded backfill, trace reasons, stale-work fencing, edit indexing and disabled-policy cancellation pass PostgreSQL and management HTTP tests. Live MiniMe and in-game recall remain external/unproven. |
| Relationships | AUTOMATED | Scoped actor relationships support derived/manual modes and immutable before/after audit; isolation and prompt assembly integration need broader dialogue tests. |
| World knowledge | AUTOMATED | Bounded authored-text ingestion, checksums, provenance, scoped deterministic retrieval, traces and deletion APIs are implemented; remote ingestion is intentionally excluded from the foundation. |
| Narrative | AUTOMATED | Narrator/diary/summary storage, scoped export/restore foundations, and deterministic first-party summary/diary derivation handlers pass local repository/worker tests. |
| Rechat | AUTOMATED | Rechat is a bounded continuation of a player-started conversation and advances only after successful final playback with target/session/generation fencing. Optional unique participant-state snapshots are bounded to 13 submitted identities; integration proves unavailable previous speakers fail, busy/sleeping bystanders are skipped, directly addressed sleeping actors remain eligible, malformed/foreign/duplicate rows fail, legacy clients remain compatible, and the selected responder's effective rechat opt-out wins. It is not timer autonomy; live OpenMW and in-game proof remain pending. |
| Automatic dialogue | COMPLETE | Automatic greetings, boredom remarks, and combat barks use the game-owned idle scheduler and ordinary typed turns. Background Life and unrelated timer-triggered model work remain excluded. |
| Actions | AUTOMATED | The frozen CHIM/Dialectic audit maps every safe API-129 equivalent into a 16-action catalog. Tier 0 inventory inspection, Tier 1 same-cell approach, and bounded wait join the existing movement, combat, animation, equipment, use, and observation actions; provider normalization, prompts, management UI, negotiated capabilities, strict schemas, migrations, and terminal receipts share the same names and bounds. Unsupported catalog entries have recorded authority reasons in `openmw-action-parity-audit.md`. Engine execution remains explicitly external. |
| Events/traces | AUTOMATED | Immutable source events, cursor behavior and real HTTP terminal events pass; deterministic bounded prompt assembly now records only source IDs/revisions, section metrics, truncation reasons, hashes and redacted previews rather than raw prompts. |
| Workers | AUTOMATED | Durable `turn.process` plus memory derive/consolidate/rebuild/embed, summary/diary, cleanup, retention and provider-reconciliation handlers use bounded idempotent leases. Memory consolidation is chained only from eligible memory completion and semantic indexing only from an explicit opt-in policy; neither has a timer or autonomy entry point. HTTP/PHP fallback runs one job after flushing acceptance while supervised workers provide restart recovery; errors remain bounded and redacted. |
| Operations | AUTOMATED | Ordered migration 004 passes fresh/up/rerun/down, dump/restore remains green, and authenticated redacted diagnostics/counts plus bounded retention APIs are implemented. Production WSL backup policy remains external. |
| Management UI | AUTOMATED | Herika presentation geometry, hubs, navigation, controls, assets, compact cards, and centralized disabled status badges are wired to LORKHAN's typed repositories, browser session, CSRF, and PRG flows. The Memory page has separate CSRF-protected MiniMe policy and explicit backfill forms; saving remains side-effect free and failed service calls retain deterministic retrieval. The browser-like HTTP workflow passes, and the semantic controls fit without horizontal overflow at 1280x900 and 390x844 browser viewports. |
| Security | AUTOMATED | Separate HttpOnly/SameSite browser sessions, CSRF on writes, query-token rejection, normalized rate buckets, hash-only pairing config, rotation/overlap/revocation records, secret-field rejection, redacted worker failures and HTTPS/public-address URL policy are implemented and locally tested. Full DNS pinning requires a real HTTP connector. |
| Contract | AUTOMATED | 28 Draft 2020-12 schemas and 49 fixtures across 77 manifest files validate and are byte-identical between the paired worktrees. The canonical input, event, response, response-line, and TES3 game-data split includes UTF-8, stale-generation, duplicate, malformed, over-limit, hostile cache-key, excluded-autonomy, and Morrowind-identity fixtures. Provider results normalize once into ordered response lines; successful, direct-action, failed, interrupted, and session-cancelled turns persist one correlated response envelope and publish a strict `response.complete` event before legacy dialogue/action projections. The deterministic beta schema inventory covers 161 PostgreSQL relations and 1,490 columns with UTF-8, ownership, reader/writer, retention and disposition evidence. |
| Migration | AUTOMATED | Ordered migrations through 069 pass fresh/up/rerun/down where data-safe, populated upgrade, exact Herika public-schema cutover, internal migration authority, canonical response/line/utterance backfill, ordered prompt/memory trace additions, guarded semantic projection removal, idempotent safe-action catalog additions, and destructive pre-beta retirement of excluded compatibility schema in disposable PostgreSQL. |
| STT | AUTOMATED | Authenticated binary WAV ingress, PostgreSQL request/job state, provider-selected durable transcription, transcript/failure events, and the single global Herika-style connector workflow are covered by protocol, unit, migration/job, integration, management HTTP, and local deployment checks. Live cloud credentials and in-game microphone acceptance remain external. |
| ITT | EXCLUDED | No shipped image-to-text capture, management page, profile control, API-key landmark, or database relation. |
| Background Life | EXCLUDED | No shipped background-life scheduler, controls, or worker entry point. |
| Externally hosted SaaS | EXCLUDED | Local loopback product boundary. |
| Multiplayer/shared authority | EXCLUDED | Single-player multi-character boundary. |
| Bethesda/save/mod-file storage | EXCLUDED | Proprietary/game data boundary. |
| Browser/game credential exposure | EXCLUDED | Secret boundary. |
| Arbitrary code/SQL/URL/file/console/Lua/MWScript | EXCLUDED | Permanent action boundary. |

Final local acceptance used implementation commit `414a8435b297c5aedd16ee1e1d9afa895f2b5dfc`.
The private pre-upgrade backup at
`/var/lib/lorkhanserver/backups/pre-parity-schema-v41-before-39cef63.dump` has SHA-256
`10b70f6d6ca4c4f09084126712bd457bea65d6b90445e7a8a92dcc6cba495704`. The deployed database has
47/47 migrations and the same 145-relation, 1,322-column inventory hash
`9dcb6e6c1a46dff766a43ae98953f868c37adb5ac3e54f2e32a523ef2cba9a3a` as a fresh installation.
Apache, the durable worker, typed health, PostgreSQL integration, management HTTP/CSRF, provider/queue
tests, and responsive browser rendering pass. The deployed tree has zero checksum differences from the
pushed implementation source after preserved runtime paths are excluded. Both draft PRs are green.
In-game action/playback success, compatibility, publication, and release remain unproven; no local mock,
schema, build, deployment, or browser check is promoted to game evidence.

## 2026-09-13 observed spell and pickup context

- Successful native casts and player item acquisitions feed immutable source events,
  scoped event history, and prompt context. No observation schedules a model turn.
- Spell targets are cast targets, not hit confirmations. Pickups report transferred
  quantity with canonical inventory gold units; cancelled transfers, barter, crafting,
  and console/script additions are excluded.
- Detect Magic Events defaults on. Item Pickup Detection Value defaults to 500 total
  gold. Global/Core/NPC overrides and existing category/blacklist filters affect
  prompt inclusion; original event records are retained.
- Capture-time calendars use existing zero-based Morrowind months. Loaded-save
  rollback suppresses dated future observations and retires unanchored prior-session
  spell/pickup evidence from active context. Original sources remain immutable.
- Automated Lua and protocol checks pass (81 Lua cases; 38 schemas, 77 fixtures,
  115 paired files). Live gameplay and paid-provider checks have not been performed.
- Server migration 109 records automatic NPC/creature profile revision provenance
  from the leased job. Save rollback appends a restoration revision while preserving
  manual/locked/Narrator/player/unknown-history boundaries. Revision numbers never
  move backwards, and an earlier subsequent load can follow restored ancestry.
- The user approved the physical-diary-only record exception on 2026-09-13.
  The implementation and its current proof are recorded in the checkpoint below.


## 2026-09-13 physical NPC diaries

- User-approved diary-only record exception; no arbitrary commands or general record creation.
- Physical Diary is opt-in in Core Profiles and NPC overrides, with inheritance and preset support.
- Typed capability-gated query/result delivery uses one stable book per NPC/playthrough. Saved
  dynamic records update in place; logical receipt retries preserve idempotence.
- Latest five completed generated entries supply bounded text; invalidated source jobs cannot
  return through edited UI provenance. No new provider request is made by materialization.
- Native text handling accounts for TES3 trailing-tag layout and literal game-variable markers.
- 85 Lua tests and 1265 PHP checks pass; 124 protocol files match across both repositories.
- Windows Release engine, native bridge and Beast loopback tests pass. Full integration, SQL
  backup/import/restore, migration replay and durable jobs pass against fresh factory110
  (183 relations, 1698 columns).
  No game launch, paid provider call, or in-game reading/save-load proof has been performed.

- Local deployment verified: schema110, all920 server runtime files and30 client data files
  match source; health succeeds, private paths403, unauthenticated sessions401. Configuration,
  credential and voice contents preserved. Core/NPC Physical Diary controls visually reviewed
  and toggled without saving; settings remain unchanged. Existing Default Diary LLM is Disabled.
- Engine SHA256: 575E1773656718C87038E946DB7438A7A03FA6F17788FF1369A3804EFCC6A1B5.
  Native overlay refreshed to the maintained/built binding;23 clean-pin patches and10patch tests pass.
- Server rollback: /var/backups/lorkhanserver-code.1ILb9c; live database backup:
  /var/backups/lorkhanserver-before-physical-diary-20260913.dump.
  Client rollback: %TEMP%/lorkhan-physical-diary-de5d33ebcb2d4ce0bc20bf834186cee2.
- No game or paid provider was invoked. Physical reading, dropping/trading, and save/load behavior
  still require in-game acceptance. This checkpoint completes physical diary implementation only.

## 2026-09-14 remaining parity build and deployment

The current implementation and exact automated/deployment evidence are recorded in
../CURRENT-PARITY-PLAN.md. Schema117,944 runtime files,32 client files and the final
Windows engine are deployed and hash-verified. In-game acceptance remains unverified.
