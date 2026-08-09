# ALMSIVI protocol v1 server contract

The canonical behavior is shared with sibling `ALMSIVI/docs/PROTOCOL.md`. JSON Schemas and fixtures
are duplicated for independent release but their manifest hashes must be identical in CI.

## Transport and authentication

- Base URL: `http://127.0.0.1:8089/ALMSIVIserver/api/v1`.
- Native client uses `hmac-sha256-v1` request MAC headers binding installation, timestamp, unique nonce, body digest, canonical method/target/content type; the 256-bit pairing key is not transmitted routinely and bearer authentication is rejected.
- JSON content type is strict UTF-8. STT uses authenticated `application/octet-stream` WAV bodies plus typed `X-ALMSIVI-*` metadata headers.
- Responses use ordered bounded long polling at `/events`, not an unbounded server socket.
- Media uses authenticated `/media/{opaque_id}` and a stored descriptor/hash, never a supplied path.
- POST idempotency key equals message/request/event ID.

Apache/PHP enforces request body/header/time limits before application parsing. App middleware then
enforces token, rate, content type, schema, IDs, active session/generation, runtime/capability,
idempotency and endpoint-specific limits.

## Common envelope

Every gameplay message includes strict schema name, message/request/turn IDs as applicable,
installation/profile/playthrough/session IDs, generation, UTC creation timestamp, runtime block,
content fingerprint and typed payload. Runtime block contains `game=tes3`, `variant=openmw`, exact
OpenMW version/commit, Lua API revision, client version/platform and negotiated capabilities.

Unknown fields/enums are rejected in v1. Strings are valid UTF-8, numbers/arrays/objects have schema
limits and total JSON is at most 2 MiB (context at most 128 KiB). Negotiation may lower limits only.

## Canonical input, event, response, and game-data split

`almsivi.input.v1` is the normalized player-text/STT input envelope and `almsivi.event.v1` is the
typed immutable source-event envelope. `almsivi.response.v1` contains `ok`, ordered bounded `lines`,
`close`, an error string, and complete installation/profile/playthrough/session/turn/request plus
response/runtime generation correlation. Each strict `almsivi.response.line.v1` is `say` or
`rolecommand` and carries stable speaker/listener/rechat identities, request/utterance IDs, and bounded
text, TTS/media/cache, command, and metadata fields. Provider output is normalized once; database
projections, client events, TTS, actions, delivery receipts, diagnostics, and rechat consume it.

`almsivi.gamedata.v1` accepts only typed TES3 actor, inventory, nearby-actor, world, Journal,
captured-dialogue, and prompt-bridge payloads. AI quests, boredom, greetings, combat barks, ITT, and
Background Life have no accepted variants. `almsivi.events.v1.autonomy` remains required for v1 wire
compatibility but must always be empty. Rechat is a normal correlated turn. Canonical envelopes require
both response generation and runtime generation values greater than zero.

## Object identity

TES3/OpenMW object identity is a typed record ID, runtime RefNum/FormId when exposed, source content
file and its order/index, cell identity and display-name snapshot. It is accompanied by a normalized
ordered content fingerprint. Display name is never a lookup key. Ambiguous, missing, wrong-content or
stale references fail explicitly.

## API

| Endpoint | Behavior |
| --- | --- |
| `GET /health` | Minimal service/schema status; no secrets/provider details. |
| `POST /sessions` | Authenticate, validate runtime/content, bind profile/playthrough, negotiate caps. |
| `DELETE /sessions/{id}` | End/cancel current session generation idempotently. |
| `POST /turns` | Persist validated source turn, enqueue/process provider pipeline, return acceptance/cursor. |
| `POST /controls/query` | Return safe revisioned model slots, NPC profiles, narrator ID, and target-effective settings for the active session. |
| `POST /controls/select` | Idempotently select a session model slot, bind an NPC profile, or queue revision-safe bound-NPC/narrator generation. |
| `POST /stt` | Authenticate and persist a bounded WAV request, enqueue durable transcription, and return `almsivi.stt.accepted.v1`. |
| `GET /events` | Return current session events after cursor, optionally wait at most 15 seconds. |
| `POST /action-results` | Persist exactly one terminal result for an emitted current action. |
| `POST /interruptions` | Cancel current turn/media/action continuation and emit terminal states. |
| `GET /media/{id}` | Stream owned, unexpired allowlisted audio with fixed headers/hash/length. |

Contracted event types are `turn.accepted`, `dialogue.delta`, `dialogue.complete`, `speech.ready`, `stt.transcript`, `stt.failed`, `action.intent`,
`turn.complete`, `turn.failed`, and `turn.cancelled`. The required `autonomy` array is always empty. Future variants such as status/notices require
an atomic shared-schema revision before use. Bounded `dialogue.delta` text is display-only progress; `dialogue.complete` remains the validated durable utterance. TTS runs as a separate durable job, and `speech.ready` includes the matching `dialogue_message_id` so delayed group speech cannot bind to the wrong speaker. Each event has a monotonically increasing session sequence
and unique message ID. Cursor gaps use bounded replay or `cursor_expired`; the client never guesses.

`ui_source=almsivi_rechat` denotes a playback-driven continuation, not timer autonomy. Its bounded
context carries chain/origin IDs, monotonic depth and previous speaker/listener identities. The server
accepts it only in the same active session/generation, stores one scoped chain, discards provider
actions, and closes or cancels the chain at its configured depth, on new player input, or on failure.
The client submits continuation only after every preceding utterance is terminal and the final
delivery result is `played`.

## Actions

An intent contains schema/action/turn IDs, name, integer tier, resolved actor/optional target,
strict parameters and expiry. Server emits only catalog actions enabled by installation/profile,
advertised by the current client and allowed by turn limits. It never emits code, file path, URL or
free-form engine command.

An authenticated `POST /turns` may include a typed `payload.action_request` from the in-game action
menu. The server derives its actor from the turn target and normally derives its target from the player
speaker. Only `ai.face`, `combat.start`, and `combat.stop` may supply a different explicit target, and that identity
must be present in the bounded nearby-actor context and differ from the acting NPC. The server applies
the same catalog, negotiated capability, profile policy, tier, and parameter checks, and bypasses the
language-model provider. Success emits `turn.accepted`, `action.intent`, and `turn.complete`.

The direct `ai.travel` and `ai.escort` actions accept only bounded destination coordinates plus a
canonical cell key captured by the player's in-game camera ray. The acting client rechecks that cell
before starting the native OpenMW package; arbitrary free-form movement commands are not accepted.

Controls are authenticated and generation-scoped. They expose no credentials or endpoints. A
`configured` provider slot freezes only a selected model plus configuration revision; the worker keeps
its endpoint, allowlist, timeout, and API-key environment in server process configuration. NPC profile
bindings use stable OpenMW identity within one installation/playthrough; special player/narrator profiles are
excluded from the binding list. Narrator generation accepts only the installation narrator ID returned by the
same authenticated control query. The selected actor profile may
change profile/prompt sources, but session-profile memory and relationship scope is retained. Turn
acceptance freezes the assembled prompt and provider slot snapshot so later admin edits cannot alter an
already accepted job.

The controls response also returns a strict `almsivi.effective-settings.v1` snapshot for the active target.
It contains the resolved memory, narrator, safety, and routing values, their Global/Core Profile/NPC source
map, bound profile revisions, and a deterministic change token. Client-local presentation settings are not
part of this target-effective document. Layered rechat enable/depth is included; timer scheduling,
boredom, greetings, combat barks, ITT, and Background Life are excluded. STT is an installation-global connector and never participates in the layered profile resolver.

Client returns exactly one terminal status: `succeeded`, `failed`, `rejected`, `timed_out` or
`cancelled`, plus stable reason code, bounded observed fields and completion timestamp. Server states
distinguish created, emitted, delivered and terminal; no UI/prompt calls an action successful earlier.

## Errors and idempotency

Stable codes include `invalid_schema`, `payload_too_large`, `unauthorized`, `forbidden`,
`rate_limited`, `unknown_session`, `stale_generation`, `duplicate_conflict`, `cursor_expired`,
`provider_unavailable`, `provider_timeout`, `media_unavailable`, `action_disabled`, `internal_error`.
Response states whether retry is safe and optional bounded `retry_after_ms`; messages are generic.

The same idempotency key and byte-equivalent semantic request returns the original acceptance/result.
The same key with different content returns `duplicate_conflict`. Turns/actions are not automatically
retried by the client; health/media may use bounded retries.

## Media and retention

Speech descriptor contains opaque ID, SHA-256, actual bytes, codec, duration and expiry. Route ignores
any client path/name, checks authenticated ownership/session, and serves from private storage. Default
max is 32 MiB. Generated media has quota/expiry. Metadata and
source response remain auditable after bytes expire according to retention policy.

## Local management operations

The CHIM-style Control Panel uses ALMSIVI-native data rather than the Herika/Dialectic database
manager. Server Logs reads only fixed ALMSIVI worker and Apache files, caps each tail at 256 KiB and
200 lines, and redacts common credential forms before rendering. Database Manager exposes applied
schema migrations, bounded retention, and server-generated installation configuration backups without
accepting filesystem paths. Backup downloads and restores require browser authentication, verify stored
byte count and SHA-256, and restore only to the originating installation as new auditable revisions.
Configuration backups include profiles, prompts, model slots, TTS/STT presets, action policies,
connector selections, and profile safety preferences; they exclude secrets, portraits, voices, and
runtime playthrough state.

LLM model slots and TTS/STT preset pages support portable single-preset JSON export, import, and
same-installation cloning. Portable documents contain only the preset kind where needed, name, and
validated public configuration; installation ownership, revision history, runtime endpoints, and
credentials are excluded. Active speech connectors, profile-assigned TTS connectors, and model slots
selected by an active session or assigned to a profile cannot be deleted until their use is removed.
Prompt Manager uses the same ownership-free JSON boundary for individual prompt export, import, and
same-installation cloning; imports still pass normal prompt validation and become independent revisions.
Action Editor exposes labelled policy enable, maximum-tier, and per-action permission controls over the
immutable server catalog. These policies can only further restrict catalog rows and OpenMW-negotiated
capabilities; the UI cannot rewrite action names, client capabilities, or parameter/result schemas.
Player Management can queue a durable analysis of at most the latest 200 real player turns. The job
updates only the player profile's `speech_style` when its base revision is still current; it does not
enable player TTS or synthesize unsupported OpenMW player-respeech behavior.
Playthrough export/restore remains scoped and transactional; management pages do not expose private
media bytes, credential files, provider keys, database passwords, or arbitrary log paths.
Playthrough Manager derives bounded session, turn, response, memory, relationship, narrative, and
knowledge counts from the selected playthrough and reports its latest session without mutating state.

## Versioning and fixtures

Breaking changes use new route/schema major. Because v1 is strict, any additive field requires both
schema repositories and fixtures to update atomically with backward/forward compatibility policy.
Unsupported client/server versions return a visible mismatch; no legacy tuple/file fallback exists.

Required fixtures cover every endpoint/event/action; min/max/boundary Unicode; all identities;
duplicates/cursors; malformed/unknown/oversized/overflow data; auth/session/generation failures;
provider/media failures; and every terminal action status.
