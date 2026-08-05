# ALMSIVIserver

ALMSIVIserver is the local Apache/PHP/PostgreSQL backend and browser management application for
`RANGROO/ALMSIVI`, designed around TES3/OpenMW semantics and the shared CHIM/Dialectic product model.

## Status

The complete local provider vertical slice is implemented and deployed: authenticated sessions,
turns and ordered events, dialogue/TTS media, action/result delivery, profiles and prompts, memory,
relationships, knowledge, narrative/autonomy, durable jobs, diagnostics/backups, and the
CSRF-protected CHIM-styled management surface. New installations receive CHIM's Standard, Fast,
Powerful, and Experimental OpenRouter model slots plus PocketTTS; the API key remains in protected
server credential storage, and mock providers remain available for deterministic tests. Default
Windows/WSL URL: `http://127.0.0.1:8089/ALMSIVIserver/ui/home.php`. Apache and PostgreSQL are access-controlled for
the local machine and are not exposed as a public service.

## Responsibilities

- authenticated, versioned game-client ingress and ordered response events;
- profiles, prompts, actions, providers, memories, relationships and world knowledge;
- event/turn/action-result persistence with OpenMW content and actor identity;
- TTS/STT/LLM connectors, bounded media, derived-state workers, backups and diagnostics;
- browser setup and management UI.

The management interface follows the shared HerikaServer/DialecticServer PHP page format: physical
`public/ui/*.php` pages include one common head, Bootstrap navbar, and footer; Configuration and
Control Panel use grouped lazy-loaded iframe tabs. Legacy `/manage/*` page URLs redirect to the
canonical PHP pages while `/manage/forms/*` and `/manage/api/v1/*` remain the CSRF-protected backend.

It does not own OpenMW game objects, execute engine actions, store Bethesda game data, or put provider
credentials in the client.

## Local deployment

The normal developer deploy mirrors the active source to `/var/www/html/ALMSIVIserver`, keeps
database credentials and pairing secrets under `/etc/almsiviserver`, and preserves media/log state
under `/var/lib/almsiviserver` and `/var/log/almsiviserver`. Run the sibling client's
`scripts/deploy/full-local.ps1` for the Herika-style two-stage server plus game-client deployment.

`scripts/deploy-wsl.sh` remains the immutable release/rollback installer described in
`docs/WSL-APACHE-SETUP.md`; `scripts/deploy-local-wsl.sh` is the stable-path local development sync.
Both installers idempotently backfill missing CHIM connector defaults without replacing saved routes
or active TTS selections. Fallback LLM, STT, ITT, Background Life, and autonomy are not provisioned.

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
