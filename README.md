# ALMSIVIserver

ALMSIVIserver is the local Apache/PHP/PostgreSQL backend and browser management application for
`RANGROO/ALMSIVI`. It is seeded from the final tested Synthserver only after the Fallout 4 project
completes, then migrated explicitly from Fallout to TES3/OpenMW semantics.

## Status

Planning and Azure handoff are complete. An independently authored PostgreSQL/PHP foundation is present while final predecessor import remains gated. In addition to the strict protocol/media/worker slice, it now includes revisioned profiles/playthroughs/prompts/provider/action policy, deterministic memory and knowledge retrieval with provenance, relationships, narrative/export, autonomy schedules, operational diagnostics, pairing lifecycle, and a separate CSRF-protected server-rendered management surface. Exact remaining behavior and external evidence boundaries are recorded in `docs/evidence/completion-ledger.md`. Default Windows/WSL URL: `http://127.0.0.1:8089/ALMSIVIserver`. Apache and PostgreSQL are not exposed externally by default.

## Responsibilities

- authenticated, versioned game-client ingress and ordered response events;
- profiles, prompts, actions, providers, memories, relationships and world knowledge;
- event/turn/action-result persistence with OpenMW content and actor identity;
- TTS/STT/LLM connectors, bounded media, derived-state workers, backups and diagnostics;
- browser setup and management UI.

It does not own OpenMW game objects, execute engine actions, store Bethesda game data, or put provider
credentials in the client.

## Start here

1. `CLAUDEX-TASK.md`
2. `docs/IMPLEMENTATION-PLAN.md`
3. `docs/REFERENCE-SERVER-DATAFLOW.md`
4. `docs/MIGRATION-SOURCE-AUDIT.md`
5. `docs/ARCHITECTURE.md`
6. `docs/PROTOCOL.md`
7. `docs/FEATURE-PARITY-MATRIX.md`
8. `docs/WSL-APACHE-SETUP.md`

The sibling ALMSIVI task is the parent assignment and owns shared schema reconciliation.
