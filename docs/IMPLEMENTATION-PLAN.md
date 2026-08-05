# ALMSIVIserver implementation plan

## Outcome and definition of done

Deliver a clean-installable local backend with the complete applicable Synthserver feature set,
TES3/OpenMW semantics throughout, strict sibling protocol parity, mock-provider E2E, management UI,
workers/backups/diagnostics and no unexplained provenance or legacy game leakage.

The direct seed is deliberately the final Synthserver, not today's planning repo. Its final SHA and
green test manifest are runtime inputs captured after SYNTH's completion gate.

## Workstream 0: controlled source intake

- Verify both SYNTH repos are clean/completed and record exact commits/licenses/tests.
- Snapshot final Synthserver behavior/routes/schema/providers/UI/workers/tests before editing.
- Produce file-level provenance and a preserve/adapt/replace/exclude semantic map.
- Import in one recognizable commit, run baseline tests, and preserve a reference migration tag.

Gate: imported baseline behavior is green or every pre-existing failure is recorded; no file has
unknown provenance; no reference repo changed.

## Workstream 1: product and schema migration

- Rename product, namespaces/config/routes/database schemas/tables/fixtures/UI copy and package names.
- Replace Fallout/Sole Survivor/Commonwealth/FormID/plugin/runtime terms with Morrowind/Nerevarine/
  Tamriel/OpenMW/TES3 content identity where semantically correct.
- Add installation, content manifest/fingerprint, OpenMW runtime/API and TES3 object identity models.
- Use ordered source-controlled migrations, constraints/indexes/foreign keys and typed access.
- Add a forbidden-term audit allowing legacy terms only in explicit migration/reference documentation.

Gate: fresh and migrated test DBs pass; UI/routes/schema contain no misleading game semantics; source
events can represent vanilla and mod-added TES3 objects without display-name keys.

## Workstream 2: protocol and health vertical slice

- Install shared strict schemas/fixtures/manifest and game API middleware.
- Add token generation/hash/rotation, loopback setup, rate/size/content/auth/idempotency enforcement.
- Implement health, session init/end, capabilities, current generation and event cursor.
- Prove sibling fake client init -> persisted event -> visible management trace.

Gate: positive/negative cross-repo E2E passes, including stale generation, bad auth, malformed/
oversized data, duplicate conflicts, server restart and version mismatch.

## Workstream 3: complete conversation pipeline

- Persist source turn before provider work; build bounded traceable prompt from profile/context/memory.
- Port mock and final vetted LLM connectors behind one typed contract with timeout/cancel/retry policy.
- Persist ordered deltas/final response and expose bounded long-poll events with replay/cursor expiry.
- Add explicit speaker/addressee/audience, group speaker selection and delivery completion/failure.
- Retain STT schemas and fixtures only as compatibility scaffolding; do not expose an STT route,
  controls, capture, or request path. Implement TTS generation/private media/hash/expiry/serve lifecycle.

Gate: mock solo/group/text/TTS/interruption E2E and provider failure/redaction tests pass; partial
output never becomes a completed utterance or memory source.

## Workstream 4: profiles, memory, relationships and knowledge

- Port base/dynamic profiles, prompt templates/revisions and per-profile provider/feature settings.
- Port recent/middle/long memory, embeddings/retrieval, source trace, rebuild/delete/retention controls.
- Port relationships, world knowledge, narrator, diary, playback-driven rechat and playthrough
  summaries/export/restore, translated to TES3. Rechat uses scoped CHIM-style event/speech/response/
  prompt records, advances only after final client playback, and cannot emit actions.
- Exclude timer autonomy, boredom, automatic greeting, combat barks, STT, ITT and Background Life.
- Ensure workers are idempotent, source-event-derived and visible in job/dead-letter UI.

Gate: deterministic fixtures prove create/derive/retrieve/edit/delete/rebuild/restore and prompt source
trace for each domain. Cross-playthrough/profile leakage tests pass.

## Workstream 5: typed actions and terminal results

- Port action definitions/editor/policy revisions with OpenMW capability and tier metadata.
- Validate server allowlist, installation/profile enablement, actor/target identity, parameter bounds,
  per-turn action count and expiry before emitting intent.
- Persist client terminal result exactly once; distinguish accepted/emitted/delivered/succeeded states.
- Add a capped one-turn result-aware continuation and halt/cancel behavior.

Gate: inspect action succeeds in fake E2E; every catalog action has valid/invalid fixtures; duplicates,
timeouts, rejection, stale generations and capability mismatch are proven.

## Workstream 6: management UI and operations

- Quickstart/setup status: database, pairing, client/runtime/schema, providers, workers, media, backup.
- CRUD/revision/rollback for profiles, prompts, actions and provider-safe configuration.
- Search/detail pages for events/turns/provider traces, characters, memory, relationships, knowledge,
  playthroughs, jobs/dead letters, media metadata and audits.
- Redacted diagnostics bundle, health metrics, retention/deletion, import/export, backup/restore drill.
- Responsive accessible UI; no game/stream overlay is hosted here.

Gate: browser route/API auth/CSRF/accessibility/build tests pass and a clean WSL setup can perform the
mock conversation flow using UI-created configuration.

## Workstream 7: hardening and handoff

- Static analysis, unit/integration/E2E/security/migration/frontend tests and coverage/risk audit.
- Provider SSRF/URL/secret/redaction, request abuse, token rotation, media access and DB failure tests.
- Clean install/upgrade/rollback/backup/restore and worker restart/lease recovery tests.
- License/provenance/SBOM/secret/user-data/package scans and sibling protocol manifest parity.
- Exact commands, hashes and evidence linked from completion ledger.

Gate: all non-game server rows pass from a clean clone/database with mock providers. Windows in-game
rows remain explicitly unproven until the sibling package executes them.

## CI and evidence matrix

| Surface | Required command/artifact to create |
| --- | --- |
| Formatting/static types | repository formatter/linter/static analyzer, zero unexplained warnings |
| Unit | domain/application/provider fake tests |
| Integration | isolated PostgreSQL migrations/repositories/HTTP/media/workers |
| Contract | all schemas and hostile fixtures + sibling manifest hash |
| E2E | fake client -> HTTP -> DB/provider fake -> events/media -> action result |
| UI | typecheck/lint/unit/build plus route/API security tests |
| Operations | WSL bootstrap check, backup/restore, migration forward/rollback policy, worker restart |
| Security | auth/rate/size/SSRF/CSRF/session/media/redaction/secret scans |
| Provenance | import ledger/license/SBOM and forbidden-content audit |

## Closed choices

Stack, ports, transport, auth, schemas, source timing, identity, media, worker model, management scope,
provider boundary, action-result semantics and completion evidence are fixed by the docs. Provider
accounts/keys and final predecessor SHAs are supplied through deterministic setup gates. There are no
unresolved architecture questions.
