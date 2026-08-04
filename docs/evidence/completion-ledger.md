# ALMSIVIserver implementation ledger

Recorded baseline: 2026-07-19. Updated local WSL/browser acceptance: 2026-08-03 through server presentation commit `38a695e`; see `local-deployment-2026-08-03.md`. `AUTOMATED` means a repeatable local no-game check passed. `PLANNED` means required implementation/proof is not complete. `EXTERNAL-DEFERRED` requires an unavailable external environment or finalized predecessor evidence. `EXCLUDED` is a closed product boundary.

| Feature-matrix row | State | Current implementation / evidence |
| --- | --- | --- |
| Setup | AUTOMATED | Disposable PostgreSQL ordered migration fresh/up/rerun/down, dump/restore, and quickstart checks pass. The clean committed server is deployed to local WSL, Apache and the supervised worker loop are active, health passes, and persistent state is preserved. Production systemd/immutable-release upgrade and rollback remain unproven. |
| Sessions | AUTOMATED | Installation/profile/playthrough/runtime/content binding, monotonic generations, replacement and negotiated capabilities pass PostgreSQL integration. |
| Ingress | AUTOMATED | Pairing auth, query-token rejection, strict bodies, size/rate/idempotency, immutable source rows and correlation pass local tests. |
| Dialogue | AUTOMATED | Solo and deterministic bounded group/multi-utterance mock turns select only target/audience TES3 identities, persist durable utterances, and validate exact terminal delivery replays including speaker and timing. In-game playback remains external/unproven. |
| Providers | AUTOMATED | Turn acceptance atomically enqueues restart-safe worker processing before the immediate HTTP 202. Provider-neutral cooperative cancellation combines durable turn/session state, lease loss and deadlines; deterministic LLM/TTS mocks and redacted provider attempts are locally tested. Live connectors/credentials remain intentionally disabled. |
| Media | AUTOMATED | Deterministic legal WAV generation, private opaque storage, integrity/ownership/expiry serving and bounded first-party cleanup pass local tests; external game playback remains unproven. |
| Profiles | AUTOMATED | Server-owned installation-scoped profiles and playthroughs have immutable revisions, rollback, prompt/provider/action-policy configuration revisions and bounded CRUD APIs. Migration 005 safely materializes legacy session owners, adds scoped FKs without deleting rollback data, and fresh sessions materialize revisioned owners; PostgreSQL upgrade/down coverage is local only. |
| Memory | AUTOMATED | Recent/mid/long records carry provenance, lexical terms and deterministic fake vectors; bounded scoped retrieval records scores/traces, and edit/delete/rebuild/retention foundations pass PostgreSQL tests. Real embedding providers are intentionally absent. |
| Relationships | AUTOMATED | Scoped actor relationships support derived/manual modes and immutable before/after audit; isolation and prompt assembly integration need broader dialogue tests. |
| World knowledge | AUTOMATED | Bounded authored-text ingestion, checksums, provenance, scoped deterministic retrieval, traces and deletion APIs are implemented; remote ingestion is intentionally excluded from the foundation. |
| Narrative | AUTOMATED | Narrator/diary/summary storage, scoped export/restore foundations, and deterministic first-party summary/diary derivation handlers pass local repository/worker tests. |
| Autonomy | EXCLUDED | Model-triggering rechat, boredom, greeting, and background scheduling are outside the active ALMSIVI scope. Compatibility code and tests do not expose shipped controls or runtime entry points. |
| Actions | AUTOMATED | The database catalog and selected action-policy revision validate enabled capability/tier/parameter schemas; terminal client results remain exactly-once/replay-safe, and at most one continuation can be claimed after a terminal result. Engine execution remains explicitly external. |
| Events/traces | AUTOMATED | Immutable source events, cursor behavior and real HTTP terminal events pass; deterministic bounded prompt assembly now records only source IDs/revisions, section metrics, truncation reasons, hashes and redacted previews rather than raw prompts. |
| Workers | AUTOMATED | Durable `turn.process` plus memory derive/rebuild, summary/diary, cleanup, retention and provider-reconciliation handlers use bounded idempotent leases. HTTP/PHP fallback runs one job after flushing acceptance while supervised workers provide restart recovery; errors remain bounded and redacted. |
| Operations | AUTOMATED | Ordered migration 004 passes fresh/up/rerun/down, dump/restore remains green, and authenticated redacted diagnostics/counts plus bounded retention APIs are implemented. Production WSL backup policy remains external. |
| Management UI | AUTOMATED | Herika presentation geometry, hubs, navigation, controls, assets, compact cards, and centralized disabled status badges are wired to ALMSIVI's typed repositories, browser session, CSRF, and PRG flows. The browser-like HTTP workflow passes, and the principal page families were inspected at 1280x720 plus true 390x844 mobile emulation without document-level horizontal overflow. |
| Security | AUTOMATED | Separate HttpOnly/SameSite browser sessions, CSRF on writes, query-token rejection, normalized rate buckets, hash-only pairing config, rotation/overlap/revocation records, secret-field rejection, redacted worker failures and HTTPS/public-address URL policy are implemented and locally tested. Full DNS pinning requires a real HTTP connector. |
| Contract | AUTOMATED | 19 Draft 2020-12 schemas, 31 fixtures and 50 manifest files, including STT/delivery/inspect.report surfaces, validate with the dependency-free validator and are byte-identical across active worktrees. Full jsonschema validation is unavailable because the dependency is not installed and installation is forbidden. |
| Migration | EXTERNAL-DEFERRED | Final predecessor completion gate/pins remain unavailable; no predecessor source was imported. |
| STT | EXCLUDED | No shipped microphone, capture, transcription, or STT management workflow. |
| ITT | EXCLUDED | No shipped image-to-text capture or management workflow. |
| Background Life | EXCLUDED | No shipped background-life scheduler, controls, or worker entry point. |
| Externally hosted SaaS | EXCLUDED | Local loopback product boundary. |
| Multiplayer/shared authority | EXCLUDED | Single-player multi-character boundary. |
| Bethesda/save/mod-file storage | EXCLUDED | Proprietary/game data boundary. |
| Browser/game credential exposure | EXCLUDED | Secret boundary. |
| Arbitrary code/SQL/URL/file/console/Lua/MWScript | EXCLUDED | Permanent action boundary. |

Local WSL deployment, Apache, worker, health, PostgreSQL integration, browser-like HTTP flows, and desktop/mobile browser rendering are now proven for the commits recorded above. Production systemd/immutable-release operation, in-game action/playback success, CI, packaging, publication, and release remain unproven. No local mock or browser check is promoted to game evidence.
