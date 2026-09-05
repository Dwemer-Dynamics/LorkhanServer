CREATE TABLE stt_requests (
    message_id uuid PRIMARY KEY,
    request_id uuid NOT NULL UNIQUE,
    turn_id uuid NOT NULL,
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    generation bigint NOT NULL CHECK (generation >= 0),
    codec text NOT NULL CHECK (codec = 'wav'),
    language text NOT NULL CHECK (octet_length(language) BETWEEN 2 AND 35),
    audio_bytes integer NOT NULL CHECK (audio_bytes BETWEEN 1 AND 16777216),
    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    state text NOT NULL CHECK (state IN ('accepted','transcribed','failed')),
    transcript text CHECK (transcript IS NULL OR octet_length(transcript) BETWEEN 1 AND 16384),
    created_at timestamptz NOT NULL,
    completed_at timestamptz
);
CREATE TABLE dialogue_delivery_results (
    dialogue_message_id uuid PRIMARY KEY,
    source_event_id uuid NOT NULL UNIQUE REFERENCES source_events(source_event_id),
    message_id uuid NOT NULL UNIQUE,
    request_id uuid NOT NULL,
    turn_id uuid NOT NULL REFERENCES turns(turn_id),
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    generation bigint NOT NULL CHECK (generation >= 0),
    speaker jsonb NOT NULL CHECK (jsonb_typeof(speaker)='object'),
    status text NOT NULL CHECK (status IN ('expired','failed','interrupted','played')),
    reason_code text NOT NULL CHECK (octet_length(reason_code) BETWEEN 1 AND 128),
    completed_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE response_events ADD CONSTRAINT response_events_event_type_check CHECK (event_type IN (
    'turn.accepted','dialogue.complete','speech.ready','action.intent','turn.complete','turn.failed','turn.cancelled',
    'stt.transcript','stt.failed'
));
