# ALMSIVI protocol v1 server contract

The canonical behavior is shared with sibling `ALMSIVI/docs/PROTOCOL.md`. JSON Schemas and fixtures
are duplicated for independent release but their manifest hashes must be identical in CI.

## Transport and authentication

- Base URL: `http://127.0.0.1:8089/ALMSIVIserver/api/v1`.
- Native client uses `hmac-sha256-v1` request MAC headers binding installation, timestamp, unique nonce, body digest, canonical method/target/content type; the 256-bit pairing key is not transmitted routinely and bearer authentication is rejected.
- JSON content type is strict UTF-8. STT accepts only allowlisted bounded audio types.
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
| `POST /stt` | Validate metadata/audio and produce transcript/failure event. |
| `GET /events` | Return current session events after cursor, optionally wait at most 15 seconds. |
| `POST /action-results` | Persist exactly one terminal result for an emitted current action. |
| `POST /interruptions` | Cancel current turn/media/action continuation and emit terminal states. |
| `GET /media/{id}` | Stream owned, unexpired allowlisted audio with fixed headers/hash/length. |

Contracted event types are `turn.accepted`, `dialogue.complete`, `speech.ready`, `action.intent`,
`turn.complete`, `turn.failed`, `turn.cancelled`, `stt.transcript`, and `stt.failed`. Future variants such as deltas/status/notices require
an atomic shared-schema revision before use. Each event has a monotonically increasing session sequence
and unique message ID. Cursor gaps use bounded replay or `cursor_expired`; the client never guesses.

## Actions

An intent contains schema/action/turn IDs, name, integer tier, resolved actor/optional target,
strict parameters and expiry. Server emits only catalog actions enabled by installation/profile,
advertised by the current client and allowed by turn limits. It never emits code, file path, URL or
free-form engine command.

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
max is 32 MiB. Raw STT audio retention defaults off; generated media has quota/expiry. Metadata and
source response remain auditable after bytes expire according to retention policy.

## Versioning and fixtures

Breaking changes use new route/schema major. Because v1 is strict, any additive field requires both
schema repositories and fixtures to update atomically with backward/forward compatibility policy.
Unsupported client/server versions return a visible mismatch; no legacy tuple/file fallback exists.

Required fixtures cover every endpoint/event/action; min/max/boundary Unicode; all identities;
duplicates/cursors; malformed/unknown/oversized/overflow data; auth/session/generation failures;
provider/media failures; and every terminal action status.
