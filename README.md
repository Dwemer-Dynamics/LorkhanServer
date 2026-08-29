# ALMSIVIserver

ALMSIVIserver is the local Apache/PHP/PostgreSQL backend and browser management application for
`RANGROO/ALMSIVI`, designed around TES3/OpenMW semantics and the shared CHIM/Dialectic product model.

## Status

The complete local provider vertical slice is implemented and deployed: authenticated sessions,
turns and ordered events, dialogue/TTS media, action/result delivery, profiles and prompts, memory,
relationships, knowledge, narrative, durable jobs, diagnostics/backups, and the
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

## LLM connectors

The connector editor supports three modes:

- **Configured runtime** keeps the server endpoint and key. Existing model-only slots need no changes.
- **Direct OpenAI-compatible endpoint** uses a complete chat-completions URL and a named server-held key.
  Public hosts require HTTPS; plain HTTP is limited to `localhost` or `127.*`. URLs cannot contain
  credentials, a query string, or a fragment. DNS/private-address checks run when connecting, not saving.
- **Deterministic mock** makes no provider request.

Optional sampling controls include temperature, one token limit (`max_tokens` or
`max_completion_tokens`), top-p/top-k/min-p/top-a, and repetition/frequency/presence penalties.
Blank sampling fields use the provider's defaults for direct connectors, or inherit server settings
for configured connectors. Explicit zero and false values are preserved. Provider support varies;
an unsupported parameter may cause a test failure. See the [OpenRouter parameter reference](https://openrouter.ai/docs/api_reference/parameters).
Streaming affects dialogue only. Turning JSON mode off removes the provider hint, not ALMSIVI's
strict response validation. Reasoning Model Fix can remove one leading balanced reasoning-tag block before
that validation; it is opt-in and separate from Disable reasoning. Arbitrary request bodies and JSON prefill remain unsupported.

API Keys manages Default, OpenAI LLM, OpenRouter LLM, and Custom LLM keys separately. Existing default
and speech key values are not moved. Direct connectors start without a key. Export/import clears
the key selection so an imported endpoint cannot acquire a local credential automatically; select
the intended key after reviewing the endpoint. Local cloning and revision rollback preserve key references.
Explicit direct connections bypass environment proxies and pin validated DNS answers; configured
runtime connections retain the operator's proxy configuration. Neither Save nor Import calls a provider.
Test and subsequent use of an assigned connector can incur provider charges.

### Profile generation routing

Core Profiles can select a **Profile Generation LLM** for requested NPC/narrator generation and
player speech-style analysis. Individual profiles can inherit that choice, select another connector,
or choose **Use server runtime**. Leaving the Core Profile choice unset retains the existing runtime
provider. This does not enable automatic generation or schedule additional requests.

New jobs keep the selected connector ID and immutable revision; later connector edits do not change
those jobs. Credentials remain server-held and are resolved when the worker runs. Pending jobs prevent
connector deletion. Existing queued jobs without a selected connector retain runtime behavior.
Profile locks, stale-edit checks, cancellation, and strict generated-content validation still apply.
Saving routing settings makes no provider call; requesting generation can incur provider charges.

## Local deployment

The normal developer deploy mirrors the active source to `/var/www/html/ALMSIVIserver`, keeps
database credentials and pairing secrets under `/etc/almsiviserver`, and preserves media/log state
under `/var/lib/almsiviserver` and `/var/log/almsiviserver`. Run the sibling client's
`scripts/deploy/full-local.ps1` for the Herika-style two-stage server plus game-client deployment.

`scripts/deploy-wsl.sh` remains the immutable release/rollback installer described in
`docs/WSL-APACHE-SETUP.md`; `scripts/deploy-local-wsl.sh` is the stable-path local development sync.
Both installers idempotently backfill missing CHIM connector defaults without replacing saved routes
or active TTS selections. STT is installation-global; ITT, Background Life, and timer autonomy are
not provisioned and their pre-beta compatibility schema has been retired.

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
