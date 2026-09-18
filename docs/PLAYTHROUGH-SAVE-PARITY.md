# Gameplay save parity

Reference: HerikaServer `origin/unstable` at `5a97fe2f`, inspected 2026-09-18.

## Behaviour

- Manual and Dragon Break saves capture the selected playthrough's gameplay, not the full database.
- Core Profiles, connectors, API keys and reusable global settings remain live shared data.
- Local saves additionally capture Oghma catalogues, shared articles, deletion overrides, dynamic/context rules, gameplay-only settings and active compatibility views. They are staged with an inactive copy and applied atomically on its next character handshake. The outgoing world's state is retained for switching back.
- The mixed settings filter follows CHIM: unknown `conf_opts` keys are gameplay unless registered as global; unknown `general_settings` keys are configuration. Player and automation-state keys are gameplay in both.
- Generated memory summaries, profile-evolution clocks/counts, request logs and retrieval history are retained. Counted event IDs are remapped; pending manual jobs are not replayed. Scoped memory/Oghma displays and every saved NPC revision are reconstructed.
- Local copies preserve NPC Core Profile assignments and connector references. Portable exports still strip connector routing.
- Before loading a copy, current progress is saved. The copy is inactive until the saved character's next handshake; the previous world remains intact.
- Copied sessions, actions and speech cannot resume as live work.
- Dragon Break capture is always attempted at the configured rollback threshold: default 3 game days, range 1–3650 as in CHIM.
- Capture failure skips timeline rollback without rejecting the new session. Request identity deduplicates retries, without treating later visits to the same dates as the same save.
- The first manager visit creates one protected default gameplay save per world.
- Full SQL backups remain separate in Database Manager. Existing SQL snapshots are retained, not silently converted or deleted.

## Implementation boundaries

OpenMW keeps character/world bindings rather than CHIM's one active PostgreSQL schema. Loading a copy therefore uses the existing next-load association protocol, not a live schema swap. Local saves have a 128 MiB document limit, with at most 50,000 graph rows and 50,000 additional active-state rows. Portable archives retain their previous 16 MiB / 50,000-row format and narrower coverage. Embeddings regenerate; external game saves and audio are not included. Old 44-table local saves remain readable with their original checksum/schema validation; missing new state is not invented. This does not claim identical storage or unlimited CHIM-scale archival.

Installation-wide public gameplay views are single-installation storage. Applying their saved state refuses multiple non-revoked installations rather than overwriting another installation's data. Shared Oghma documents/rules absent from the restored state are soft-deleted, and later catalogues are retired rather than cascade-deleting records referenced by historical worlds. Preparing a copy does not change these active tables.

## Verification

Coverage expansion (migration 135): 1,598 checks pass. On an isolated copy using the runtime role, full manager capture/load-copy creates recovery and a pending association; real repository handshakes restore saved gameplay and switch back. Checks cover exact Oghma catalogue/article/deletion/rule contents, generated summaries, remapped evolution events, no manual-job replay, rebuilt memory/Oghma displays, protected global settings, unchanged connector revisions, old-save imports and migration down/up. The full manager probe peaked at 219.3 MiB PHP memory for the approximately 22 MB fixture. Local hosting must have enough PHP memory for bounded JSON save processing.

The fresh broad integration suite still stops at its unrelated `active_playthrough_required` fixture at line 379, before archive tests. Focused database probes are the runtime evidence for this change; live game restoration is not claimed.

- 1,587 existing server checks passed; changed PHP and JavaScript syntax checks passed.
- Migration 133 tested on a fresh schema and an isolated copy of the deployed database, including down/up.
- Twenty isolated database checks passed, repeated using the actual runtime database role: capture, protected default, all shared-table hashes unchanged, pre-copy recovery, inert sessions/actions, relationship/diary recovery, Core Profile assignments, duplicate-switch rejection, next-handshake association, rollback threshold capture, request deduplication, repeated-date capture and capture-failure continuation without timeline pruning.
- Live manager created a manual gameplay save successfully. No restore, character switch or game launch was performed against the user's running game.
- The broad integration runner currently stops before this feature's tests at its existing `active_playthrough_required` fixture failure (`tests/integration.php`, profile setup). It is not reported as passing.

New gameplay-save metadata is operational storage, excluded from its own capture policy. No retention job deletes these saved documents automatically; individual non-default copies can be removed from the manager.

## Loaded-save rollback detection

Automatic recovery capture uses the highest non-retired game-calendar observation in the selected installation/playthrough, rechecked under the capture lock. Later-arriving older observations cannot hide a rollback. Retired turns and previous loaded-save observations do not keep triggering recovery copies.

The three-day default controls recovery capture only. Every accepted dated loaded-save handshake retires abandoned-future history, including shorter rollbacks. Source-event-linked diaries and response/action projections are included. Immutable transport/audit evidence remains retained but is excluded from active history. Generated profile and relationship rollback respects manual edits and the never-clear-relationships setting.

OpenMW cleanup runs on the actual loaded-save handshake, not an assumed last-save timestamp on death. This is not a complete database snapshot restore: dynamic Oghma patches and other state without rollback provenance still need a separate parity audit. Manual gameplay-save restore remains the full captured-state recovery route.

Focused transactional database probes passed for highest-time selection, playthrough isolation, one-day rollback, past/future memory and diary handling, repeated-load idempotency and retirement of previous loaded-save times. No live game load or restoration was performed.
