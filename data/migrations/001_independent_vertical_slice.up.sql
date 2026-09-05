CREATE TABLE installations (
    installation_id uuid PRIMARY KEY,
    token_fingerprint char(64) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    last_seen_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE TABLE sessions (
    session_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id),
    profile_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    generation bigint NOT NULL CHECK (generation >= 0),
    content_fingerprint varchar(71) NOT NULL CHECK (content_fingerprint ~ '^sha256:[0-9a-f]{64}$'),
    openmw_version text NOT NULL,
    openmw_commit char(40) NOT NULL,
    lua_api_revision integer NOT NULL,
    client_version text NOT NULL,
    platform text NOT NULL,
    capabilities text[] NOT NULL DEFAULT '{}',
    enabled_actions text[] NOT NULL DEFAULT '{}',
    event_sequence bigint NOT NULL DEFAULT 0 CHECK (event_sequence >= 0),
    state text NOT NULL DEFAULT 'active' CHECK (state IN ('active', 'ended', 'replaced')),
    created_at timestamptz NOT NULL,
    ended_at timestamptz,
    UNIQUE (installation_id, generation)
);
CREATE UNIQUE INDEX sessions_one_active_installation ON sessions (installation_id) WHERE state = 'active';

CREATE TABLE idempotency_requests (
    installation_id uuid NOT NULL REFERENCES installations(installation_id),
    idempotency_key uuid NOT NULL,
    route text NOT NULL,
    semantic_hash char(64) NOT NULL,
    http_status smallint NOT NULL,
    response_body jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id, idempotency_key, route)
);

CREATE TABLE source_events (
    source_event_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id),
    session_id uuid REFERENCES sessions(session_id),
    generation bigint,
    event_kind text NOT NULL,
    occurred_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    schema_name text NOT NULL,
    request_id uuid,
    turn_id uuid,
    action_id uuid,
    payload jsonb NOT NULL,
    CHECK ((session_id IS NULL AND generation IS NULL) OR (session_id IS NOT NULL AND generation >= 0))
);
CREATE INDEX source_events_session_order ON source_events (session_id, received_at, source_event_id);

CREATE TABLE turns (
    turn_id uuid PRIMARY KEY,
    request_id uuid NOT NULL UNIQUE,
    message_id uuid NOT NULL UNIQUE,
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    generation bigint NOT NULL CHECK (generation >= 0),
    input_kind text NOT NULL CHECK (input_kind IN ('text', 'stt')),
    input_language text NOT NULL,
    input_text text NOT NULL CHECK (octet_length(input_text) <= 16384),
    speaker jsonb NOT NULL,
    target jsonb NOT NULL,
    audience jsonb NOT NULL,
    context jsonb NOT NULL,
    state text NOT NULL CHECK (state IN ('accepted', 'processing', 'complete', 'failed', 'cancelled')),
    accepted_at timestamptz NOT NULL,
    completed_at timestamptz
);
CREATE INDEX turns_session_order ON turns (session_id, accepted_at, turn_id);

CREATE TABLE response_events (
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    sequence bigint NOT NULL CHECK (sequence > 0),
    message_id uuid NOT NULL UNIQUE,
    request_id uuid NOT NULL,
    generation bigint NOT NULL CHECK (generation >= 0),
    turn_id uuid NOT NULL REFERENCES turns(turn_id),
    event_type text NOT NULL CHECK (event_type IN (
        'turn.accepted', 'dialogue.complete', 'speech.ready', 'action.intent',
        'turn.complete', 'turn.failed', 'turn.cancelled'
    )),
    payload jsonb NOT NULL,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (session_id, sequence)
);

CREATE TABLE action_intents (
    action_id uuid PRIMARY KEY,
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    turn_id uuid NOT NULL REFERENCES turns(turn_id),
    request_id uuid NOT NULL,
    generation bigint NOT NULL CHECK (generation >= 0),
    action_name text NOT NULL,
    tier integer NOT NULL CHECK (tier BETWEEN 0 AND 3),
    actor jsonb NOT NULL,
    target jsonb,
    parameters jsonb NOT NULL,
    expires_at timestamptz NOT NULL,
    state text NOT NULL DEFAULT 'emitted' CHECK (state IN ('created', 'emitted', 'delivered', 'terminal')),
    emitted_at timestamptz NOT NULL
);
CREATE INDEX action_intents_session_turn ON action_intents (session_id, turn_id);

CREATE TABLE action_results (
    action_id uuid PRIMARY KEY REFERENCES action_intents(action_id),
    source_event_id uuid NOT NULL UNIQUE REFERENCES source_events(source_event_id),
    message_id uuid NOT NULL UNIQUE,
    request_id uuid NOT NULL,
    status text NOT NULL CHECK (status IN ('succeeded', 'failed', 'rejected', 'timed_out', 'cancelled')),
    reason_code text NOT NULL,
    observed jsonb NOT NULL,
    completed_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE TABLE interruptions (
    message_id uuid PRIMARY KEY,
    request_id uuid NOT NULL UNIQUE,
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    turn_id uuid NOT NULL REFERENCES turns(turn_id),
    generation bigint NOT NULL CHECK (generation >= 0),
    reason text NOT NULL,
    created_at timestamptz NOT NULL
);

CREATE TABLE rate_limit_buckets (
    bucket_key char(64) PRIMARY KEY,
    window_started_at timestamptz NOT NULL,
    request_count integer NOT NULL CHECK (request_count >= 0)
);
