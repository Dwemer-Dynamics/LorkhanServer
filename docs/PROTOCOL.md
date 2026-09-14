# LORKHAN protocol v1 server contract

The canonical behavior is shared with sibling `LORKHAN/docs/PROTOCOL.md`. JSON Schemas and fixtures
are duplicated for independent release but their manifest hashes must be identical in CI.

## Transport and authentication

- Windows client base URL: `http://127.0.0.1:7514/LorkhanServer/api/v1` through DwemerDistro Launcher; Apache listens on WSL port `8090`.
- Native client uses `hmac-sha256-v1` request MAC headers binding installation, timestamp, unique nonce, body digest, canonical method/target/content type; the 256-bit pairing key is not transmitted routinely and bearer authentication is rejected.
- JSON content type is strict UTF-8. STT uses authenticated `application/octet-stream` WAV bodies plus typed `X-LORKHAN-*` metadata headers.
- Responses use ordered bounded long polling at `/events`, not an unbounded server socket.
- Media uses authenticated `/media/{opaque_id}` and a stored descriptor/hash, never a supplied path.
- POST idempotency key equals message/request/event ID.

Apache/PHP enforces request body/header/time limits before application parsing. App middleware then
enforces token, rate, content type, schema, IDs, active session/generation, runtime/capability,
idempotency and endpoint-specific limits.

## Common envelope

Every gameplay message includes strict schema name, message/request/turn IDs as applicable,
installation/profile/playthrough/session IDs, session generation, client runtime generation, UTC creation timestamp, runtime block,
content fingerprint and typed payload. Runtime block contains `game=tes3`, `variant=openmw`, exact
OpenMW version/commit, Lua API revision, client version/platform and negotiated capabilities.

Unknown fields/enums are rejected in v1. Strings are valid UTF-8, numbers/arrays/objects have schema
limits and total JSON is at most 2 MiB (context at most 128 KiB). Negotiation may lower limits only.

## Canonical input, event, response, and game-data split

`lorkhan.input.v1` is the normalized player-text/STT input envelope and `lorkhan.event.v1` is the
typed immutable source-event envelope. `lorkhan.response.v1` contains `ok`, ordered bounded `lines`,
`close`, an error string, and complete installation/profile/playthrough/session/turn/request plus
response/runtime generation correlation. Each strict `lorkhan.response.line.v1` is `say` or
`rolecommand` and carries stable speaker/listener/rechat identities, request/utterance IDs, and bounded
text, TTS/media/cache, command, and metadata fields. Provider output is normalized once; database
projections, client events, TTS, actions, delivery receipts, diagnostics, and rechat consume it.
The immutable response envelope is stored on the turn. Its line and utterance IDs are reused by
`response_events`, `dialogue_utterances`, `speech`, and `responselog`; failed and cancelled turns store
the same envelope with `ok=false`, no lines, and a bounded terminal error.

`lorkhan.gamedata.v1` accepts only typed TES3 actor, inventory, nearby-actor, world, Journal,
captured-dialogue, and prompt-bridge payloads. Greetings, boredom remarks, and combat barks are ordinary
bounded turns scheduled by the game client. AI quests, ITT, and
Background Life have no accepted variants. `lorkhan.events.v1.autonomy` remains required for v1 wire
compatibility but must always be empty. Rechat is a normal correlated turn. Canonical envelopes require
both response generation and runtime generation values greater than zero.

## Object identity

TES3/OpenMW object identity is a typed record ID, runtime RefNum/FormId when exposed, source content
file and its order/index, cell identity and display-name snapshot. It is accompanied by a normalized
ordered content fingerprint. Display name is never a lookup key. Ambiguous, missing, wrong-content or
stale references fail explicitly.

Inventory, equipment, and nearby-object records use `(content_file, record_id)` as their canonical
base identity. `content_file` is the final loaded file that supplies the winning record definition;
an optional `reference_content_file` records a different placed/runtime reference source. Item
actions carry both canonical fields and fail closed if the current inventory winner no longer matches.

## API

| Endpoint | Behavior |
| --- | --- |
| `GET /health` | Minimal service/schema status; no secrets/provider details. |
| `POST /sessions` | Authenticate, validate runtime/content, bind profile/playthrough, negotiate caps. |
| `DELETE /sessions/{id}` | End/cancel current session generation idempotently. |
| `POST /turns` | Persist validated source turn, enqueue/process provider pipeline, return acceptance/cursor. |
| `POST /controls/query` | Return four semantic profile model slots, NPC profiles, narrator ID, and target-effective settings for the active session. |
| `POST /controls/select` | Idempotently select an installation model preference, bind an NPC profile, or queue revision-safe bound-NPC/narrator generation. |
| `POST /stt` | Authenticate and persist a bounded WAV request, enqueue durable transcription, and return `lorkhan.stt.accepted.v1`. |
| `POST /menu-dialogue-tts` | Synthesize one authenticated regular Morrowind dialogue response with the actor's normal TTS route and return short-lived media. |
| `GET /events` | Return current session events after cursor, optionally wait at most 15 seconds. |
| `POST /action-results` | Persist exactly one terminal result for an emitted current action. |
| `POST /interruptions` | Cancel current turn/media/action continuation and emit terminal states. |
| `GET /media/{id}` | Stream owned, unexpired allowlisted audio with fixed headers/hash/length. |

Menu dialogue TTS is isolated from AI turns, response events, delivery receipts, memory, and rechat.
Its request and media provenance remain session/generation scoped and expire through the existing
private media lifecycle.

Contracted event types are `turn.accepted`, `dialogue.delta`, `dialogue.complete`, `speech.ready`, `stt.transcript`, `stt.failed`, `action.intent`,
`turn.complete`, `turn.failed`, and `turn.cancelled`. The required `autonomy` array is always empty. Future variants such as status/notices require
an atomic shared-schema revision before use. Bounded `dialogue.delta` text is display-only progress; `dialogue.complete` remains the validated durable utterance. TTS runs as a separate durable job, and `speech.ready` includes the matching `dialogue_message_id` so delayed group speech cannot bind to the wrong speaker. Each event has a monotonically increasing session sequence
and unique message ID. Cursor gaps use bounded replay or `cursor_expired`; the client never guesses.

`ui_source=lorkhan_rechat` denotes a playback-driven continuation, not timer autonomy. Its bounded
context carries chain/origin IDs, monotonic depth and previous speaker/listener identities. The server
accepts it only in the same active session/generation, stores one scoped chain, discards provider
actions, and closes or cancels the chain at its configured depth, on new player input, or on failure.
The client submits continuation only after every preceding utterance is terminal and the final
delivery result is `played`. A newer client may also submit an optional, unique, bounded
`participant_states` list covering the previous speaker and candidates. Each row must match an identity
already present in the turn and use `active`, `busy`, `sleeping`, `unconscious`, or `inactive`. The
server requires fresh proof for the previous speaker, excludes missing/busy/unconscious/inactive
candidates, allows a sleeping actor only when directly addressed, and rechecks the selected NPC's
effective `behavior.rechat` setting. Omitting the list preserves the existing client contract.
Close mode rechat stays inside the submitted turn audience, names that bounded audience in the
current-turn prompt, and preserves it on every reply. Whisper never admits a rechat continuation.

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

The negotiated API-129 catalog contains Tier 0 `inspect.report` and `inventory.inspect`; Tier 1
`ai.follow`, `ai.stop`, `ai.approach`, `ai.wait`, `ai.travel`, `ai.escort`, `ai.face`,
`ai.wander`, `combat.stop`, and `animation.play`; and Tier 2 `combat.start`, `item.use`,
`item.equip`, and `item.unequip`. Approach is same-cell only; wait and wander accept only whole-hour
durations from 3600 through 86400 seconds because API 129 stores Wander duration in game hours; inventory
inspection is read-only; and Halt/session/generation replacement cancels owned work.
The frozen-catalog disposition is recorded in `docs/evidence/openmw-action-parity-audit.md`.

Controls are authenticated and generation-scoped. They expose no credentials or endpoints. The fixed
semantic keys Standard, Fast, Powerful, and Experimental persist per installation and resolve through
the active target profile. Random LLM takes precedence; an empty selected slot falls back to the first
configured profile slot. A resolved `configured` provider freezes only its selected model plus configuration
revision; the worker keeps its endpoint, allowlist, timeout, and API-key environment in server process configuration. NPC profile
bindings use stable OpenMW identity within one installation/playthrough; special player/narrator profiles are
excluded from the binding list. Narrator generation accepts only the installation narrator ID returned by the
same authenticated control query. The selected actor profile owns relationship and manual-memory
context; source-derived memories additionally require witnessed-source eligibility. An unbound target
does not inherit another actor's relationships or manual memories from the session profile. Turn
acceptance freezes the assembled prompt and provider slot snapshot so later admin edits cannot alter an
already accepted job.

The controls response also returns a strict `lorkhan.effective-settings.v1` snapshot for the active target.
It contains resolved automatic-dialogue, rechat, memory, narrator, safety, and client-visible routing values, their
Global/Core Profile/NPC source map, bound profile revisions, and a deterministic change token.
The unchanged v1 wire shape retains compiled presentation and remaining legacy behavior defaults for
strict older parsers. These compatibility fields never replace local OpenMW preferences or enable
server-owned timer scheduling, ITT, or Background Life. The Lua bridge receives
the automatic-dialogue controls plus the seven playback-gated rechat fields; its safety gates require both local and
server permission. Server-only Oghma tags, diary settings, and Oghma/profile/diary-generation routes
are not exposed.
The source map omits excluded/internal paths and compatibility-only defaults. Model-slot driver
labels are the v1 categories `mock` or `configured`, not provider credentials or endpoints.
STT is an installation-global connector and never participates in the layered profile resolver.

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

Narratives can queue one manually requested diary through the authenticated browser form or
`POST /manage/api/v1/narratives/generate`. The request supplies installation, profile, playthrough,
and a UUID request ID. The selected profile must explicitly enable manual diary generation and resolve
a dedicated diary provider from its Core Profile/NPC inheritance; the default is disabled and a missing
route fails closed. Queueing reads only visible witnessed event projections for that actor and
playthrough, excludes undelivered chat, and bounds input to the configured 1-100 turns, 64 KiB of
context, and 128 KiB total provider input. The durable `narrative.generate` job freezes the profile and
provider revisions, source turn IDs, instruction, and context. Replaying the same request is idempotent;
a semantic mismatch is rejected. A successful worker writes one scoped `diary` narrative with exact
provenance. `include_in_context` defaults on; disabling it removes only diary narratives from prompt
context. Saving settings never calls a provider or queues work. No timer, sleep, wait, Background Life,
automatic narrator/player diary, or physical OpenMW book path is introduced.

Relationship management uses native source records scoped to installation, owning profile and
playthrough. New records require an actor kind, record ID, content file and runtime RefNum; display
names and current cells are not identity keys. Concurrent creates for the same scope and stable
identity are serialized and return `relationship_already_exists` instead of silently updating a row.
Edits must supply `relationship_id` and positive integer `expected_revision`; deletions require the
same revision fence. A stale write returns HTTP 409 to management API callers. Browser forms return
to the same page or embedded frame with the latest values and a conflict notice. An ID-based edit
preserves the stored identity, including incomplete legacy identities, without guessing replacements.

Migration 063 preserves existing duplicate/legacy records and audit entries. It starts revisions at
1 and advances them for every database update. Rollback is refused after any record has been edited.
The Relationship Audit page reads actual before/after history, including soft-deleted relationships,
and shows at most 100 current records and 100 recent changes for the selected installation. New audit
sequence values order same-timestamp writes; historical timestamp ties cannot recover an unknown
original order. The compatibility NPC projection is not authoritative relationship storage. This
foundation adds no automatic evaluation, provider calls or relationship-specific lock policy.

The CHIM-style Control Panel uses LORKHAN-native data rather than the Herika/Dialectic database
manager. Server Logs reads only fixed LORKHAN worker and Apache files, caps each tail at 256 KiB and
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
credentials are excluded. Active speech connectors and profile-assigned TTS or model connectors cannot
be deleted until their use is removed. The semantic LLM preference does not reference a connector directly.
Prompt Manager uses the same ownership-free JSON boundary for individual prompt export, import, and
same-installation cloning; imports still pass normal prompt validation and become independent revisions.
Core Profile settings presets use `lorkhan.core-profile-settings.v1` and carry only a name plus the
validated `settings_overrides` tree. Import creates a new unassigned, non-default Core Profile with no
slot, prompt text, connector routing, identifiers, revision history, or NPC assignments.
Global Settings presets use `lorkhan.global-settings-preset.v1` and carry one strict
`lorkhan.client-settings.v1` document plus a display name and export timestamp. Import applies that
document as a new revision of the selected installation's singleton Global Settings resource. The
portable file excludes installation ownership, revision history, Core/NPC overrides, connector routing,
API keys, Oghma catalog/access settings, Auto Lock Profile, and NPC assignments. Restoring an earlier
Global Settings revision also creates a new revision; local OpenMW HUD, transcript, and TTS preferences
remain client-owned even though the strict compatibility document retains their fields.
Revisioned Prompt documents may set `format` to `xml` or `markdown`; an absent value keeps the
existing XML presentation. The selected prompt revision owns and freezes this choice. Compact Markdown
changes ordinary context presentation only: the typed JSON response contract, negotiated action contract,
and the frozen `oghma-parity-v1` fragment remain structured XML, while trace inclusion continues to use
the canonical ordered XML sections.
Action Editor exposes labelled policy enable, maximum-tier, and per-action permission controls over the
immutable server catalog. These policies can only further restrict catalog rows and OpenMW-negotiated
capabilities; the UI cannot rewrite action names, client capabilities, or parameter/result schemas.
Player Management can queue a durable analysis of at most the latest 200 real player turns. The job
updates only the player profile's `speech_style` when its base revision is still current. A separate
Player Auto Chat connector accepts one authenticated bounded intent at `/player-autochat` and returns
one correlated spoken line. The client subtitles and speaks that line before submitting it through the
ordinary typed-turn contract, so the generated line becomes the real conversation input. With Auto Chat
off, typed messages remain unchanged. Player TTS keeps its independent profile route.
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

### RPG responder policy correlation

RPG payloads optionally carry `responder`, a strict NPC/creature identity. The
server resolves that actor's Core Profile before the single idempotent event/chance
decision. Legacy player-only payloads retain global policy. Supported event kinds
are levelup, combat_end, sleep and wait; lockpick is not accepted until the client
can prove the actor and actual lockpick use rather than a generic Unlock event.

The client freezes an eligible nearby responder when observing the event. A bounded
32-entry, 30-second request map correlates acceptance to the same identity and
session/generation. Changed targets, expired/duplicate acknowledgements and busy
speech/combat/input lanes cannot substitute another NPC. The global handoff checks
that identity again. A successful RPG turn starts a 60-second game-owned real-time
scheduler cooldown; lifecycle invalidation clears it. Existing native serialization
and acknowledgement fields are unchanged; no native engine modification is needed.

### Loaded-save calendar and pre-replacement snapshots

`lorkhan.session.init.v1` optionally includes `loaded_save`. Normal startup,
reconnect and new-game initialization omit it. Only an actual loaded save includes
an object with integer `year` (1..9999), zero-based `month` (0..11), valid fixed-calendar
`day`, and numeric `hour` (0 inclusive, 24 exclusive). Null records an unavailable
calendar without inventing a date. Unknown fields and invalid dates are rejected.

GLOBAL Lua fences the native session restart at `onLoad`, then calls the typed
`finishLoadedSave(calendar)` when the loaded player is present. Native code freezes
the four scalar fields in the init DTO; it performs no network work on the main
thread. A known-calendar loaded-save init has a 20-second first-byte budget within
the existing 30-second total request budget. Normal requests keep their deadlines.

After authentication, schema and generation checks, but before replacing the old
session, the server compares the calendar with the latest recorded turn in that
installation/playthrough/profile. A rollback of at least three game days attempts
an immutable full database snapshot. The archive retains committed pre-replacement
state; duplicate calendar transitions reuse the stored record. Capture failures
are logged and audited without intentionally rejecting a valid session. This does
not restore the database or prune immutable future history automatically.


### Independent inventory observations

The typed gamedata inventory observation keeps exact owner identity and a bounded
item list. Native and server admission accept only physical NPC, creature or player
owners; Narrator is rejected. Item `condition` is optional: report a normalized 0-1 value only when
OpenMW provides a degrading condition and its maximum. Items without durability
omit it. Item `content_file` is also optional when the engine cannot establish the
record origin; no content file is guessed from the owner. Present values retain
the existing type, length and range checks. This does not relax action identities:
item actions still require their canonical record/content-file pair.

Inventory observations have no recorded game calendar. Consumers use only the
current exact session/generation/playthrough and never carry them across a loaded
save. Current-turn observed inventory, including an empty list, wins over fallback.
Deploy the paired server schema before a client that emits the optional fields.

### Successful spell and pickup observations

`gamedata` types `spell_cast` and `item_pickup` record bounded successful engine
observations without scheduling a model turn. Native capture and Lua delivery retain
the originating session and generation; stale observations are discarded. At most
twelve nearby witnesses are attached. Witnesses describe proximity, not line of sight.

Spell observations contain the caster, spell ID/name, game time, and optional cast
target. A target is not proof of a hit. Failed or scripted casts are not captured.
Pickup observations contain the player, item ID/name, transferred count, unit gold
value, game time, and source kind (`world`, `container`, or `actor`). Optional source
ID/name is descriptive text only, never an actor routing identity. Successful normal
world pickups, container/actor transfers, and harvesting are supported; barter,
crafting, console/script additions, and cancelled transfers are not pickup observations.
Gold is normalized once to its acquired inventory quantity and canonical unit value.

The server retains original observations and projects `spellcast`, `npcspellcast`,
and `itemfound` event history. Scoped conversation context respects the existing
Infoaction category, location/item/magic blacklists, Detect Magic Events (default on),
and Item Pickup Detection Value (default 500 total gold, count times unit value).
Global, Core, and NPC overrides affect prompt inclusion; they do not erase event logs.

New observations also carry the capture-time calendar using the existing loaded-save
shape (zero-based month). Loading an earlier save retires observations at or beyond
its cutoff while preserving earlier dated observations. Legacy undated spell/pickup
records are retained as evidence but cannot cross session boundaries into context;
the DaysPassed clock is never guessed to be an absolute calendar timestamp.


## Physical NPC diary books (2026-09-13)

The user approved a narrow exception to the record-creation boundary for physical NPC diaries.
This does not authorize model-selected record properties, scripts, console commands, paths, assets,
or arbitrary record creation. No content addon or bundled game asset is introduced.

The opt-in Core Profile/NPC setting `diary.materialize_enabled` is labelled **Physical Diary**.
The server selects completed generated diary entries for the exact NPC/creature and playthrough,
combines the latest five into a bounded plain-text book, and excludes invalidated future sources.
Player and Narrator profiles are excluded. Editing a generated entry updates its book contents.

Clients advertise `diary.books.v1`. Authenticated `POST /diary-books/query` returns a nullable
`lorkhan.diary-book.v1` delivery, correlated by request/session/generation. A stable book UUID
identifies one NPC/playthrough diary; a delivery UUID identifies the current content snapshot.
Titles are bounded to 128 UTF-8 bytes; content to 2048 characters and 8192 UTF-8 bytes, with a
SHA-256 content hash. `POST /diary-book-results` records only correlated terminal execution results.
An accepted HTTP request is not evidence that a book was created.

The native client retains the authenticated snapshot and exposes only typed diary materialization.
The exact NPC reference is checked again on the main thread. A deterministic saved dynamic book
record is created once and updated in place, including when its inventory item has moved or been
dropped. Book properties are fixed by native code; visuals reuse an installed mundane book. Text is
neutralized for the TES3 book parser, including literal game-variable markers.
Loading an older save allows the eligible current snapshot to be reconciled without a global
server receipt incorrectly claiming that the book still exists in that save.

This exception is separate from the deferred original content-addon deliverable. In-game reading,
movement, dropping, and save/reload acceptance require user testing; builds do not prove gameplay.
