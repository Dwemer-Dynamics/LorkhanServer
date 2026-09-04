# LorkhanServer feature parity matrix

Parity means the user outcome works end to end and is observable/persisted, not that a similarly named
route or table exists. The implementation ledger adds state and evidence to every row.

| Domain | Required capability |
| --- | --- |
| Setup | Clean WSL install, DB migrations, pairing, mock quickstart, health and upgrade/rollback. |
| Sessions | Install/profile/playthrough binding, OpenMW/API/content caps, generation replacement. |
| Ingress | Strict auth/schema/size/rate/idempotency, immutable source event and correlation. |
| Dialogue | Solo/group turns, speaker/addressee/audience, ordered deltas/final, delivery result, playback-gated action-free rechat continuation, and optional bounded player mood cues projected into prompts/history without rewriting authored text. |
| Providers | Vetted LLM/TTS set plus installation-global Deepgram, Parakeet, Whisper, LocalWhisper, Gemini, Azure, Inworld, and Disabled STT; mock mode; health; timeout/cancel/redaction; API Badge integration; secret-free portable presets; dynamically labelled controls; connector-level fallback voices; bounded voice sample management; and durable catalogs. |
| Media | Private opaque storage, hash/type/size/ownership/expiry, authenticated serving and quota. |
| Profiles | Base/dynamic character profiles with CHIM-style prompt head, core identity, biography, gender/race, skills, moods, explicit dialogue-prompt routing, Standard/Fast/Powerful/Experimental LLM slots, deterministic per-turn slot randomization, an explicit one-attempt fallback LLM, per-profile TTS routing and connector-level male/female fallback voices, search/favorites/locking, independent cloning, private portraits, bulk operations, revision-safe individual and bounded installation-batch NPC generation, narrator generation and player speech-style analysis with inherited or per-profile generation connectors frozen at enqueue, plus default-on automatic backfill of empty unlocked NPC profiles after 40 completed actor turns. Backfill freezes at most 100 turns / 64 KiB, is revision-fenced, idempotent per profile revision, and can be disabled or triggered from 10 to 100 turns in Global Settings. Portable prompt templates with revision/rollback, settings-only Core Profile presets, playthrough scope, and in-use protection for routed connectors and local TTS samples remain supported. |
| Memory | Recent/middle/long memory, deterministic retrieval, source provenance, revisioned rebuild/edit/delete, and delivery-gated derivation. Confirmed `played` dialogue queues one idempotent recent-memory job; event-driven durable consolidation turns each chronological group of four eligible recent records into one middle record and four eligible middle records into one long record. Deterministic IDs, source ranges, source memory/event IDs, scope, revision, and first-party model metadata are retained. Failed, expired, interrupted, stale, partial, and unplayed output cannot satisfy the eligibility guard. |
| Relationships | Scoped directed NPC-to-player/NPC records, revision-checked manual edit/delete, audit and prompt integration. Optional `relationship.evaluate` jobs run only after a fully played exchange, with explicit Core/NPC connector, chance (default 0; 100 means every eligible response), and independent relationship lock. Jobs freeze provider/policy revisions and reject hidden, stale-session or manually changed records; atomic receipts prevent double application. Explicit Build with AI queues `relationship.build` for one NPC/playthrough using its saved Relationship LLM, independently of automatic chance (including 0). It checks up to 100 recent completed conversation candidates, keeps only fully played visible exchanges, and rejects ambiguous owners/records or input beyond 20 known interlocutors / 64 KiB. Absolute scores and one receipt save atomically; omitted targets stay unchanged. Ended sessions are supported, but subsequent load/start/stop, profile/relationship edits or hidden sources invalidate queued output. Relationship Audit shows ten recent build statuses without polling. Player-only Custom Info is excluded from model context and preserved by score updates. Playthrough restore reconciles stable TES3 identities, keeps matching rows idempotent, imports incomplete legacy rows by their source ID, audits new rows, and rejects conflicting or deleted local state atomically. Bulk relationship-text conversion remains planned. |
| World knowledge | Scoped documents/facts, ingestion validation, retrieval trace and deletion. |
| Narrative | Scoped narrator, diary, and playthrough summary records with export/restore. Manual diary generation is opt-in per inherited profile, requires a dedicated revisioned LLM route, and queues one idempotent `narrative.generate` job only after a person submits a request. The job freezes the profile/provider revisions plus at most 100 witnessed turns / 64 KiB, stores exact source-turn provenance, and writes one typed diary narrative. Diary context remains on by default and can be disabled without hiding summaries. Saving settings never queues work. Timer/sleep/wait generation and physical books remain excluded. |
| Rechat | Included only as a depth-bounded continuation of a player-started conversation after final playback; an optional bounded client state snapshot filters unavailable participants and the selected responder's effective Global/Core Profile/NPC enablement is rechecked. It cannot emit actions. |
| Autonomy | Excluded. Timer scheduling, boredom, greetings, combat barks, ITT and Background Life cannot initiate model work. ITT and Soulgaze surfaces are removed from the beta UI and schema. |
| Actions | Immutable 16-action API-129 catalog/tier boundary, labelled allow/deny policy editor with advanced JSON/revisions, capability validation, bounded intent, cancellation, terminal result and recorded Not Applicable reasons for unsafe frozen actions. |
| Events/traces | Search/detail scoped CHIM-style `eventlog`, `speech`, `responselog`, revisioned `prompts`, turns, prompt/provider attempts, response/action/result, and rechat-chain state. Each accepted roleplay turn persists the ten ordered XML section keys, source references/revisions/timestamps/scope, sizes, redacted previews and hashes; memory retrieval records its ranked IDs and reasons against the same turn. |
| Workers | Durable idempotent jobs, leases, retry, dead letter, status/replay. |
| Operations | Structured health/metrics/logs, diagnostics, scoped roleplay exports, hash-verified same-installation configuration backup/restore, retention/deletion. |
| Management UI | Quickstart, providers, profiles, prompts/actions, events, memory, relationships, knowledge, playthrough activity statistics, JSON file-picker imports, workers, backup, health. |
| Security | Separate game/browser auth, CSRF, SSRF/URL safety, secret redaction, abuse limits. |
| Contract | Byte-identical sibling schemas/fixtures manifest and fake-client E2E. |
| Migration | Final Synthserver provenance retained; all Fallout/Skyrim semantics translated/audited. |

## Completion evidence

Each row requires unit/integration or UI tests as applicable, a mock-provider cross-boundary path,
and a linked run artifact. Database-only behavior requires migration/repository proof; worker behavior
requires retry/idempotency proof; UI requires browser/API proof. Game delivery/action rows remain
`AUTOMATED` with a fake client until exact LORKHAN Windows in-game evidence promotes them.

## Exclusions

- Hosting an externally exposed multi-user SaaS.
- Network multiplayer or shared authoritative game worlds.
- Storing/distributing Bethesda data, saves or third-party mod files.
- Client/provider credentials in browser/game payloads.
- Arbitrary model code/SQL/URL/file/console/Lua/MWScript execution.
- Fallout/Skyrim-specific UI, entities or actions except explicit migration documentation/tests.

## CHIM management-page disposition

| CHIM outcome | LORKHAN destination or decision |
| --- | --- |
| Home dashboard and shared navbar | `home.php` plus the same Home, Roleplay, Configuration, and Control Panel navbar/hub structure; no login interstitial. |
| NPC Master, NPC Report, profiles, player and narrator | Character Manager, Profiles, NPC Biographies, Player Management, and Narration, including revisions, lock/favorite/search, portraits, clone/import/export, bulk unlock/switch/delete/generate, LLM/TTS routing, target generation, and narrator generation. |
| LLM, TTS, STT and API badges | Revisioned LLM/TTS slots, one installation-global STT connector, provider-specific controls/defaults, isolated API-key management, testing, cloning, portable presets, active selection, and in-use deletion guards. LLM slots support explicit OpenAI-compatible endpoints, named credential references, bounded generation controls, and runtime inheritance; portable imports clear credential selection. |
| XTTS cloning and voice utilities | TTS Studio WAV/ZIP management, compatible local-provider sample sync, explicit voice discovery, durable provider catalogs, profile voice selection, connector default voice, and male/female fallback voices. |
| Global/configuration settings | Global Settings plus focused revisioned editors; portable strict Global Settings presets create auditable revisions and expose bounded rollback without carrying installation, connector, Oghma, profile or assignment ownership. Rechat enable/depth and manual diary policy follow Global -> Core Profile -> NPC inheritance, while the diary route is server-only and selected on Core Profiles. Automatic profile backfill is installation-global. Other autonomy controls remain visible and disabled, while HUD, transcript and TTS gain remain local OpenMW settings. |
| Prompt and function editors | Prompts Manager and Action Editor with labelled controls, advanced JSON compatibility, revisions, policy validation and negotiated OpenMW capabilities. Each selected Prompt revision may retain the default XML presentation or opt into compact Markdown while preserving typed response/action contracts and the frozen Oghma fragment. |
| Events, memories, relationships, requests and response queue | Roleplay and Control Panel tabs with bounded create/edit/delete/rebuild operations. Memory cards expose revision/eligibility state, Request Logs expose correlated ten-section prompt traces, and Oghma Audit includes both knowledge and prompt-memory retrieval reasons without exposing prompt bodies. |
| Oghma/world knowledge, descriptions and cache | Oghma Infinium, Oghma Audit, Description Manager and Cache Browser without exposing private media or filesystem paths. |
| Playthrough, database, diagnostics and logs | Playthrough Manager, Database Manager, Server Health, Server Logs, provider usage/attempts, jobs and request traces with configuration backup/restore and bounded retention. |
| Server plugins | Removed from the beta browser UI; game capability exchange remains part of the typed OpenMW runtime protocol. |
| Timer autonomy, boredom, greetings, combat barks, ITT and Background Life/automatic diary systems | Explicitly excluded from the requested LORKHAN scope. ITT has no beta page, profile control, API-key landmark, or database relation. Playback-driven rechat, authored narrator/diary/summary records, explicitly requested one-shot diary generation, and player-triggered STT remain available. |
| Soulgaze gallery, map view, AI Quest Manager and Skyrim teleport/return tools | Skyrim/Prisma/FormID-specific outcomes with no safe OpenMW equivalent in the current authority model. Soulgaze is removed from the beta navigation and schema; the remaining unsupported outcomes stay inert where retained for presentation parity. |
| Web updater, raw DB import/export and executable test pages | Replaced by the guarded local deploy skill, schema migrations, secret-free configuration backup/restore and focused diagnostics; arbitrary file/SQL/test execution is intentionally not exposed. |
