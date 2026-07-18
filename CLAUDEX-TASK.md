# Azure Sol assignment: build ALMSIVIserver

## Objective

Create the local backend and management UI for ALMSIVI by importing the final completed Synthserver,
preserving its applicable product capabilities, translating all game semantics to TES3/OpenMW, and
proving the strict `almsivi.*.v1` contract against the sibling client fake and later Windows runtime.

## Start gate and baselines

Do not import source until the parent verifies `RANGROO/SYNTH` and `RANGROO/Synthserver` have met their
non-game stop conditions. Record their final SHAs, tests, source licenses and provenance first.

- Working repo: `RANGROO/ALMSIVIserver`; sibling: `RANGROO/ALMSIVI`.
- Direct seed: final tested `RANGROO/Synthserver` main SHA.
- Reference server: `Dwemer-Dynamics/DialecticServer@f447a9c6b59bfc689c788fb0139a0d13c6c6dc51`.
- Lineage/design: `abeiro/HerikaServer@0dbfa3eb4d3197d8159b5ff2c77bfdb5bf98b4d0`.
- Runtime contract: OpenMW 0.51.0 commit `f4bec41444214a7903bebd178389ca22ca13f646`,
  Lua API revision 129.

## Required order

1. Read sibling `CLAUDEX-TASK.md` and all documents in both repos.
2. Create evidence source pins, a file-level provenance/import ledger and a checked semantic map.
3. Import the final Synthserver in one reviewable provenance commit. Preserve tests before renaming.
4. Establish green baseline with mock providers; then rename product/config/routes/schema vocabulary
   and migrate `game=tes3`, `variant=openmw`, TES3 identities and content fingerprints.
5. Install strict shared v1 schemas/fixtures and implement pairing auth, sessions, health, turns,
   event long polling, interruptions, STT, action results and authenticated opaque media.
6. Prove init and one mocked dialogue slice against sibling fake client before feature expansion.
7. Port all applicable connectors, prompts, profiles, memories, relationships, world knowledge,
   narrator, diary, rechat/bored/greetings, playthroughs, action policy and derived workers.
8. Complete management UI: setup, providers, profiles, prompts/actions, events/traces, memory,
   relationships, knowledge, playthroughs, workers/backups, health and redacted diagnostics.
9. Add WSL setup/upgrade/rollback, migrations/restore drill, rate/abuse/security tests, frontend build,
   protocol parity, package/provenance/license/secret scans and cross-repo fake E2E.
10. Later run the exact Windows in-game matrix with sibling ALMSIVI; never infer action success from
    server acceptance.

## Required automated proof

- clean WSL/Linux install from tracked migrations/config examples with mock providers;
- strict valid/invalid schema and size/rate/auth/session/generation/idempotency tests;
- init, solo/group turn, event cursor/replay, STT, TTS/media, interruption, action/result, save/session
  replacement, server restart and malformed/duplicate E2E against sibling fake client;
- event/profile/memory/relationship/knowledge/playthrough data lifecycle and deletion/retention tests;
- provider contract/failure/redaction tests without live credentials;
- worker idempotency/retry/dead-letter and backup/restore migration tests;
- management routes/pages/API authorization, CSRF/session, URL validation and frontend tests;
- forbidden Fallout/Skyrim terminology audit outside explicit migration docs/fixtures;
- protocol schema/fixture manifest parity with sibling repo and source/license/secret scan.

## Boundaries

- Work only in the two ALMSIVI repos and isolated temporary directories.
- Do not push, publish, deploy, expose services, use live provider billing or modify reference repos.
- Never send credentials, game data, saves, production/personal data or unredacted provider payloads
  to Azure/model tooling.
- Preserve user work and database state; use new test databases and migration-backed changes.

## Stop condition

Stop only when every non-game server row is implemented and proven, setup from a clean WSL instance
is reproducible, protocol parity passes, the management surface is complete, and no unexplained
Fallout/Skyrim semantic or provenance gap remains. A true external dependency is documented with
exact failing evidence after all independent work continues.
