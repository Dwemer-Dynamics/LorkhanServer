# Gameplay save parity

Reference: HerikaServer `origin/unstable` at `5a97fe2f`, inspected 2026-09-18.

## Behaviour

- Manual and Dragon Break saves capture the selected playthrough's gameplay, not the full database.
- Core Profiles, connectors, API keys, global settings and shared catalogues remain live shared data.
- Local copies preserve NPC Core Profile assignments and connector references. Portable exports still strip connector routing.
- Before loading a copy, current progress is saved. The copy is inactive until the saved character's next handshake; the previous world remains intact.
- Copied sessions, actions and speech cannot resume as live work.
- Dragon Break capture is always attempted at the configured rollback threshold: default 3 game days, range 1–3650 as in CHIM.
- Capture failure skips timeline rollback without rejecting the new session. Request identity deduplicates retries, without treating later visits to the same dates as the same save.
- The first manager visit creates one protected default gameplay save per world.
- Full SQL backups remain separate in Database Manager. Existing SQL snapshots are retained, not silently converted or deleted.

## Implementation boundaries

OpenMW keeps character/world bindings rather than CHIM's one active PostgreSQL schema. Loading a copy therefore uses the existing next-load association protocol, not a live schema swap. The snapshot graph is the existing explicitly allowed playthrough archive graph. It currently has the same 16 MiB / 50,000-row safety limits and exact-schema requirement as portable archives. Derived summaries and embeddings regenerate; external game saves and audio are not included. This does not claim identical storage or unlimited CHIM-scale archival.

## Verification

- 1,587 existing server checks passed; changed PHP and JavaScript syntax checks passed.
- Migration 133 tested on a fresh schema and an isolated copy of the deployed database, including down/up.
- Twenty isolated database checks passed, repeated using the actual runtime database role: capture, protected default, all shared-table hashes unchanged, pre-copy recovery, inert sessions/actions, relationship/diary recovery, Core Profile assignments, duplicate-switch rejection, next-handshake association, rollback threshold capture, request deduplication, repeated-date capture and capture-failure continuation without timeline pruning.
- Live manager created a manual gameplay save successfully. No restore, character switch or game launch was performed against the user's running game.
- The broad integration runner currently stops before this feature's tests at its existing `active_playthrough_required` fixture failure (`tests/integration.php`, profile setup). It is not reported as passing.

New gameplay-save metadata is operational storage, excluded from its own capture policy. No retention job deletes these saved documents automatically; individual non-default copies can be removed from the manager.
