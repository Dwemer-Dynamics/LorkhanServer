# ALMSIVIserver feature parity matrix

Parity means the user outcome works end to end and is observable/persisted, not that a similarly named
route or table exists. The implementation ledger adds state and evidence to every row.

| Domain | Required capability |
| --- | --- |
| Setup | Clean WSL install, DB migrations, pairing, mock quickstart, health and upgrade/rollback. |
| Sessions | Install/profile/playthrough binding, OpenMW/API/content caps, generation replacement. |
| Ingress | Strict auth/schema/size/rate/idempotency, immutable source event and correlation. |
| Dialogue | Solo/group turns, speaker/addressee/audience, ordered deltas/final, delivery result. |
| Providers | Vetted final Synthserver LLM/STT/TTS set, mock mode, health, timeout/cancel/redaction. |
| Media | Private opaque storage, hash/type/size/ownership/expiry, authenticated serving and quota. |
| Profiles | Base/dynamic character profiles, prompt template revision/rollback and playthrough scope. |
| Memory | Recent/middle/long memory, embeddings/retrieval, source provenance, rebuild/edit/delete. |
| Relationships | Actor-player state, derived events, manual edit/audit and prompt integration. |
| World knowledge | Scoped documents/facts, ingestion validation, retrieval trace and deletion. |
| Narrative | Narrator, diary, playthrough summary and export/restore. |
| Autonomy | Rechat, boredom and auto-greeting scheduling with client safety/cooldown confirmation. |
| Actions | Catalog/tier/policy/editor, capability validation, intent, terminal result and one follow-up. |
| Events/traces | Search/detail source events, turns, prompt/provider attempts, response/action/result. |
| Workers | Durable idempotent jobs, leases, retry, dead letter, status/replay. |
| Operations | Structured health/metrics/logs, diagnostics, backups/restore drill, retention/deletion. |
| Management UI | Quickstart, providers, profiles, prompts/actions, events, memory, relationships, knowledge, playthroughs, workers, backup, health. |
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
