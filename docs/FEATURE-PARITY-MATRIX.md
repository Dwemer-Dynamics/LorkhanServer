# ALMSIVIserver feature parity matrix

Parity means the user outcome works end to end and is observable/persisted, not that a similarly named
route or table exists. The implementation ledger adds state and evidence to every row.

| Domain | Required capability |
| --- | --- |
| Setup | Clean WSL install, DB migrations, pairing, mock quickstart, health and upgrade/rollback. |
| Sessions | Install/profile/playthrough binding, OpenMW/API/content caps, generation replacement. |
| Ingress | Strict auth/schema/size/rate/idempotency, immutable source event and correlation. |
| Dialogue | Solo/group turns, speaker/addressee/audience, ordered deltas/final, delivery result. |
| Providers | Vetted LLM/TTS set, mock mode, health, timeout/cancel/redaction, secret-free portable presets, dynamically labelled runtime-supported provider controls, connector-level male/female fallback voices, bounded voice sample management, explicit sample sync to compatible local services, and durable per-connector voice catalogs. STT stays visible only as a disabled compatibility placeholder. |
| Media | Private opaque storage, hash/type/size/ownership/expiry, authenticated serving and quota. |
| Profiles | Base/dynamic character profiles with CHIM-style prompt head, core identity, biography, gender/race, skills, moods, explicit dialogue-prompt routing, Standard/Fast/Powerful/Experimental LLM slots, deterministic per-turn slot randomization, an explicit one-attempt fallback LLM, per-profile TTS routing and connector-level male/female fallback voices, search/favorites/locking, independent cloning, private portraits, bulk operations, revision-safe individual and bounded installation-batch NPC generation, narrator generation and player speech-style analysis, portable prompt templates with revision/rollback, playthrough scope, and in-use protection for routed connectors and local TTS samples. |
| Memory | Recent/middle/long memory, embeddings/retrieval, source provenance, rebuild/edit/delete. |
| Relationships | Actor-player state, derived events, manual edit/audit and prompt integration. |
| World knowledge | Scoped documents/facts, ingestion validation, retrieval trace and deletion. |
| Narrative | Narrator, diary, playthrough summary and export/restore. |
| Autonomy | Excluded. Rechat, boredom, greetings, combat barks, schedules, cooldowns and automatic model-triggering remain visible only as disabled compatibility placeholders. |
| Actions | Immutable catalog/tier boundary, labelled allow/deny policy editor with advanced JSON/revisions, capability validation, intent, terminal result and one follow-up. |
| Events/traces | Search/detail source events, turns, prompt/provider attempts, response/action/result. |
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
`AUTOMATED` with a fake client until exact ALMSIVI Windows in-game evidence promotes them.

## Exclusions

- Hosting an externally exposed multi-user SaaS.
- Network multiplayer or shared authoritative game worlds.
- Storing/distributing Bethesda data, saves or third-party mod files.
- Client/provider credentials in browser/game payloads.
- Arbitrary model code/SQL/URL/file/console/Lua/MWScript execution.
- Fallout/Skyrim-specific UI, entities or actions except explicit migration documentation/tests.

## CHIM management-page disposition

| CHIM outcome | ALMSIVI destination or decision |
| --- | --- |
| Home dashboard and shared navbar | `home.php` plus the same Home, Roleplay, Configuration, and Control Panel navbar/hub structure; no login interstitial. |
| NPC Master, NPC Report, profiles, player and narrator | Character Manager, Profiles, NPC Biographies, Player Management, and Narration, including revisions, lock/favorite/search, portraits, clone/import/export, bulk unlock/switch/delete/generate, LLM/TTS routing, target generation, and narrator generation. |
| LLM, TTS, STT and API badges | Revisioned LLM/TTS slots, provider-specific create/edit controls and defaults, isolated API-key management, testing, cloning, portable presets, active selection, and in-use deletion guards. STT remains visible and disabled. |
| XTTS cloning and voice utilities | TTS Studio WAV/ZIP management, compatible local-provider sample sync, explicit voice discovery, durable provider catalogs, profile voice selection, connector default voice, and male/female fallback voices. |
| Global/configuration settings | Global Settings plus focused revisioned editors; autonomy controls remain visible and disabled, while HUD, transcript and TTS gain are labelled as local OpenMW settings. |
| Prompt and function editors | Prompts Manager and Action Editor with labelled controls, advanced JSON compatibility, revisions, policy validation and negotiated OpenMW capabilities. |
| Events, memories, relationships, requests and response queue | Roleplay and Control Panel tabs with bounded create/edit/delete/rebuild operations and redacted traces. |
| Oghma/world knowledge, descriptions and cache | Oghma Infinium, Oghma Audit, Description Manager and Cache Browser without exposing private media or filesystem paths. |
| Playthrough, database, diagnostics and logs | Playthrough Manager, Database Manager, Server Health, Server Logs, provider usage/attempts, jobs and request traces with configuration backup/restore and bounded retention. |
| Server plugins | Read-only plugin inventory only; arbitrary browser-side plugin installation/execution is intentionally not ported. |
| Autonomy, ITT, STT and Background Life/automatic diary systems | Explicitly excluded from the requested ALMSIVI scope. Manual narrator/diary/summary records remain available. |
| Soulgaze gallery, map view, AI Quest Manager and Skyrim teleport/return tools | Skyrim/Prisma/FormID-specific outcomes with no safe OpenMW equivalent in the current authority model; intentionally not ported. |
| Web updater, raw DB import/export and executable test pages | Replaced by the guarded local deploy skill, schema migrations, secret-free configuration backup/restore and focused diagnostics; arbitrary file/SQL/test execution is intentionally not exposed. |
