# ALMSIVIserver CHIM/Dialectic integration plan

Status: finalized long-running server and data plan, audited and user-confirmed 2026-08-09.

The paired client plan is `ALMSIVI/docs/CHIM-DIALECTIC-INTEGRATION-PLAN.md`. This document defines the server cutover needed to preserve HerikaServer's useful product/data shape while using Dialectic's typed JSON response format and ALMSIVI's PostgreSQL, security, identity, and worker model.

## 1. Non-negotiable boundaries

- Keep the existing typed PHP application/services and PostgreSQL repositories.
- Keep installation, playthrough, profile, session, generation, request, turn, utterance, actor, and delivery identity.
- Keep source events immutable and durable jobs idempotent.
- Use HerikaServer/DialecticServer table and page contracts where applicable; do not create alternate ALMSIVI-only representations for the same concept.
- Use Dialectic-shaped JSON envelopes for input, events, responses, response lines, actions, media, and Morrowind game data.
- Preserve the Global -> Core Profile -> NPC inheritance model.
- Rechat is playback-gated continuation, not timer autonomy.
- Do not implement AI Quests, Background Life, timer-driven autonomy, greetings, boredom, combat barks, or ITT.
- Consolidate all current work into the existing client and server draft PR branches, keep the PRs draft, and do not merge them into `main` without a separate explicit instruction.
- Freeze the audited CHIM, HerikaServer, Dialectic, and DialecticServer commits recorded in the paired client plan until implementation completes.
- Preserve exact applicable HerikaServer/DialecticServer public table names, column order, types, defaults, indexes, views, and page formats over the typed ALMSIVI sources.
- Require UTF-8 end to end for source files, PostgreSQL connections/storage, JSON, prompts, profiles, Journal text, subtitles, provider input/output, and browser rendering.

## 2. Current database disposition

The database already has three overlapping layers:

1. Typed source tables from migrations 001-023, including installations, sessions, source events, turns, response events, jobs, provider attempts, profiles, core profiles, memories, relationships, knowledge, prompt traces, media, dialogue utterances, delivery results, and actions.
2. Public CHIM-style runtime surfaces from migration 024 onward: `eventlog`, `eventlog_view`, `speech`, `responselog`, `prompts`, and `rechat_chains`.
3. Staged `herika_compat` tables and projections for connectors, profiles, NPCs, settings, prompts, audits, memories, relationships, knowledge, world data, actions, and historical compatibility.

The target is not a fourth layer. The cutover must assign one authority to every concept and reduce the other representations to exact Herika-compatible views/adapters or excluded compatibility state.

## 3. Canonical table map

| Herika/Dialectic surface | Authoritative ALMSIVI source | Final disposition |
|---|---|---|
| `eventlog`/`eventlog_view` | `source_events`, `response_events`, `action_results`, delivery/STT records | Public chronological projection with established useful columns plus correlation metadata. Never directly authored by browser pages. |
| `speech` | `dialogue_utterances` + `dialogue_delivery_results` + `media_objects` | Public delivered-utterance projection. Include speaker/listener/location/text/audio/utterance/request and delivery state. |
| `responselog` | response events, provider attempts, action intents | Public normalized response-line/action projection. Preserve model/provider outcome without secrets. |
| `prompts` | configuration/profile prompt revisions | Revisioned prompt catalogue/assignment projection. Per-turn prompt text belongs in `prompt_traces`, not in the template table. |
| `audit_request` | turns, prompt traces, provider attempts, operational audit | Read-only correlated request trace; redact secrets and private headers. |
| `memory`/`memory_summary`/`memory_v` | `memory_records` and derivation jobs | Compatibility projections over scoped recent/middle/long records with provenance and revision state. |
| relationship tables/queues | `relationship_records`, `relationship_audit`, durable jobs | Typed relationship source plus administrative audit projection. No Roleplay submenu fork. |
| `core_profiles` | `core_profiles`, `core_profile_revisions` | Typed source is authoritative; compatibility view preserves Herika fields/labels. |
| `core_npc_master` and history | profiles, profile revisions, actor bindings, NPC metadata | Stable content-file/record identity projection with explicit overrides and revision history. |
| `general_settings` | revisioned installation/global configuration | Compatibility view. Do not restore removed global connector selectors or narration duplicates. |
| LLM/TTS/STT connector tables | typed connector presets/selections/voices/API badges | Transactional adapters or views used by the Herika-derived pages. STT remains installation-global; LLM/TTS route through Core Profiles. |
| action catalogue/history | `action_catalog`, `action_delivery`, action intents/results | Typed authority with Herika editor/audit projections. Only negotiated OpenMW actions can be enabled. |
| books, Journal, locations, factions, descriptions, plugins | Morrowind knowledge/context source tables and retrieval projections | Preserve Herika-shaped read/search/edit pages while using TES3 identity and provenance. |
| diaries/narrative records | `narrative_records` | Manual/player-triggered records only; no background scheduler. |
| quest engine tables | staged `herika_compat` quest tables | Excluded and quarantined. No public routes, jobs, seeds, or writes. Journal observation remains separate and live. |
| Background Life/autonomy tables | Retired before beta by migration 047. | No polling, worker, scheduler, capability, writable UI, or retained compatibility state. |

Before changing schema, produce a generated inventory containing table, ordinal column position, type, default, encoding/collation behavior, key, index, foreign key, owner, writer, readers, retention policy, and disposition. Review that inventory against the frozen HerikaServer and DialecticServer definitions. Copy the applicable contract exactly; translate only product/game identity and fields that are deprecated or excluded by this plan.

## 4. Migration strategy

Use additive, idempotent phases:

1. Add missing correlation columns and constraints to typed source tables.
2. Add canonical Dialectic-shaped JSON payload columns where a normalized line/event needs a stable envelope.
3. Backfill from the current source-of-truth rows inside bounded transactions.
4. Create or replace compatibility views/projections with exact useful legacy column names and types.
5. Switch repositories and browser readers to those projections.
6. Add parity assertions comparing source rows with projected rows.
7. Disable old direct writers and prove no dual-write divergence.
8. Quarantine excluded quest/autonomy tables and reject new writes.
9. Remove deprecated columns/tables only in a later explicitly approved migration after upgrade evidence.

Every migration must pass fresh install, released/current upgrade, rerun, backup/restore, and rollback-policy validation. Existing user profiles, keys, voices, memories, playthroughs, and logs must survive.

All migrations and fixtures must be UTF-8. PostgreSQL must report `server_encoding = UTF8`; application connections must use `client_encoding = UTF8`; JSON responses must use RFC 8259 UTF-8 without lossy replacement. Add round-trip fixtures for non-ASCII NPC names, prompt text, Journal text, subtitles, and voice metadata.

## 5. Canonical JSON response model

Add sibling-identical schemas for:

- `almsivi.input.v1`;
- `almsivi.event.v1`;
- `almsivi.response.v1`;
- `almsivi.response.line.v1`;
- `almsivi.gamedata.v1`.

`almsivi.response.v1` must contain `ok`, ordered `lines`, and `close`. Each line must carry the applicable Dialectic fields:

- speaker and display name;
- `say` or `rolecommand` action;
- text, subtitle, and TTS text/media/cache identity;
- request and utterance identity;
- listener and rechat target;
- command name/arguments for actions;
- bounded metadata.

ALMSIVI adds mandatory installation/playthrough/session/turn/generation/runtime correlation outside or alongside the line envelope. Provider output is parsed once into this canonical model. Database projections, client events, browser logs, TTS jobs, action jobs, and rechat must consume that same model.

The current `almsivi.events.v1.autonomy` field remains an empty compatibility field for v1. Any greeting, boredom, or combat-bark directive is rejected. A later coordinated v2 may remove the field.

## 6. Server request and response path

Use one path for typed text and STT transcripts:

1. Authenticate and validate request/session/generation/idempotency.
2. Persist the immutable source input and accepted turn.
3. Resolve target/audience and effective Global -> Core Profile -> NPC settings once.
4. Snapshot connector/profile/prompt revisions for the turn.
5. Assemble ordered CHIM-style XML and persist its source trace.
6. Execute the selected LLM slot and one bounded fallback policy.
7. Normalize provider output into `almsivi.response.v1`.
8. Persist provider attempts, normalized lines, utterances, action intents, and public projections transactionally.
9. Stream/publish correlated events without marking partial output final.
10. Queue TTS per utterance and expose authenticated media.
11. Accept exactly one terminal delivery result per utterance.
12. Advance rechat only after the final successful playback result; emit a normal fresh turn with depth/target fencing and no actions.

Failure, cancellation, stale generation, partial provider output, missing media, and failed playback must become explicit terminal states. They must not create memory or trigger rechat.

## 7. Prompt and trace contract

`PromptAssembler` should produce one deterministic XML document with ordered sections:

1. output contract;
2. NPC identity, biography, boundaries, and speech style;
3. player/narrator identity;
4. Morrowind world/Journal/environment context;
5. relationships/factions;
6. selected memory tiers;
7. delivered conversation and bounded vanilla dialogue;
8. audience/speaker rules;
9. negotiated action policy;
10. current turn or rechat continuation.

For every section, persist:

- section key and order;
- source table/record/revision;
- inclusion reason;
- source timestamp and playthrough scope;
- character/token size;
- redacted preview/hash.

The Roleplay Events, AI Responses, Memories, Journal, and Control Panel Request/Response/Provider/Queue pages must resolve back to these same correlated records.

## 8. Memory and rechat rules

- Only accepted source events and successfully delivered dialogue are eligible memory inputs.
- Recent memory is bounded delivered history; middle/long memory is derived by durable idempotent jobs.
- Every derived memory stores source ranges, actor/playthrough scope, provenance, revision, and model/provider metadata.
- Retrieval traces store selected IDs, ranking/reason, and prompt section.
- Rebuild/edit/delete operations are revisioned, scoped, CSRF-protected, and auditable.
- Rechat begins only after the final dialogue delivery result is successful.
- Rechat retains the original target/playthrough/session chain, increments bounded depth, creates fresh request/turn/generation correlation, and cannot emit actions.
- New player input, target change, interruption, load, cell change, session replacement, delivery failure, or depth exhaustion closes the chain.

## 9. Browser wiring

The Herika-derived browser pages must call the same typed services used by the game path:

- NPC/Profile/Narration pages -> profile, revision, binding, and effective-setting services;
- LLM/TTS/STT/API pages -> connector presets, selections, voices, API badges, and secret-safe tests;
- Prompts/Actions -> revisioned catalogue and assignment services;
- Events/Responses/Memories/Journal -> canonical projections and trace services;
- Queue/Provider Attempts/Workers -> current durable runtime state;
- Playthrough/Database/Logs -> scoped operational services.

Unsupported controls remain visible only when required for 1:1 presentation and use centralized `Planned`, `Excluded`, `Not Applicable`, or `Replaced` metadata. Excluded controls have no writable route behind them.

## 10. Work packages and gates

### S0 - CI and evidence

- Consolidate all current branches into the two existing draft PR heads before feature work continues.
- Remove macOS and redundant client workflow/matrix lanes. Keep one Ubuntu foundation/contract job and one Windows 2022 x64 Release native job.
- Add one server CI workflow for PHP lint, schema parity, the existing tests, migrations, disposable PostgreSQL integration, workers, and management HTTP.
- Refresh completion ledgers against current commits and remove stale `PLANNED` claims.

Gate: branch CI is green and evidence distinguishes automated, deployed, and in-game proof.

### S1 - Schema inventory and exclusions

- Generate the table/column/writer/reader/disposition inventory.
- Remove AI Quest, Background Life, autonomy, and ITT schema/routes/workers before beta.
- Verify Journal observation remains live without the AI Quest engine.

Gate: every table has one owner/disposition and excluded features cannot create jobs or requests.

### S2 - Dialectic JSON contracts

- Add response, response-line, event, input, and game-data schemas/fixtures.
- Add hostile, stale-generation, duplicate, malformed, over-limit, and Morrowind identity fixtures.
- Synchronize protocol manifests with ALMSIVI.

Gate: byte-identical sibling manifests and all positive/negative fixtures pass.

### S3 - Canonical persistence cutover

- Normalize provider output once.
- Transactionally persist typed response lines and CHIM-style projections.
- Switch browser/event/memory readers to the canonical surfaces.
- Eliminate direct/duplicate writers.

Gate: source/projection parity queries pass for text, STT, group, failure, action, TTS, delivery, and rechat turns.

### S4 - Prompt, memory, and rechat parity

- Lock XML ordering and per-section trace metadata.
- Complete memory eligibility, derivation, retrieval, rebuild/edit/delete, and isolation.
- Complete delivery-gated rechat and cancellation rules.

Gate: deterministic fixtures prove exact prompt sources and no failed/stale/unplayed output enters memory or rechat.

### S5 - Operations and UI acceptance

- Validate queue/provider/worker/log/backup/playthrough pages against canonical data.
- Validate all Herika-derived forms, CSRF, revisions, empty/error states, and responsive layouts.
- Run fresh/upgrade migrations, backup/restore, worker restart/lease recovery, and local WSL deployment.

Gate: browser and server matrices pass with preserved user data and no unexplained warnings/retry loops.

### S6 - Paired build, deployment, and runtime acceptance

- Build and deploy the pinned Windows client and WSL server, then validate all automatable client/server correlation for text, STT, group conversation, streaming, TTS, delivery, interruption, rechat, actions, target/session replacement, and provider failure.
- Prove bounded idle polling/request rates automatically and add observed OpenMW frame-rate behavior to the post-goal gameplay checklist.

Gate: builds, schemas, migrations, fake-client flows, deployment hashes, HTTP health, workers, and current logs are clean. Produce a clean-profile/existing-playthrough gameplay checklist, but do not block implementation completion waiting for manual game input and do not claim that unchecked gameplay passed.

## 11. Definition of server parity

Server parity is complete when:

- all applicable CHIM/Herika outcomes use typed ALMSIVI services;
- all applicable JSON uses the agreed Dialectic-shaped contracts;
- every data concept has one write authority and a tested compatibility projection where needed;
- Global -> Core Profile -> NPC settings and connector routing are deterministic and traceable;
- prompt/event/speech/response/memory/rechat records correlate end to end;
- excluded systems have no active capability, route, scheduler, or worker;
- migrations, workers, browser workflows, builds, deployment, and automatable paired runtime behavior have current evidence;
- client and server CI are green.

Do not claim manual in-game proof from schema presence, HTTP 200 responses, builds, or mock-provider tests. Gameplay verification remains a clearly reported post-goal follow-up.
