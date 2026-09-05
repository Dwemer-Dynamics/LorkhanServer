CREATE TABLE media_objects (
    media_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id),
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    turn_id uuid NOT NULL REFERENCES turns(turn_id),
    generation bigint NOT NULL CHECK (generation >= 0),
    sha256 char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    byte_count integer NOT NULL CHECK (byte_count BETWEEN 1 AND 33554432),
    codec text NOT NULL CHECK (codec IN ('wav', 'ogg', 'mp3')),
    mime_type text NOT NULL CHECK (mime_type IN ('audio/wav', 'audio/ogg', 'audio/mpeg')),
    duration_ms integer NOT NULL CHECK (duration_ms > 0),
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz,
    UNIQUE (turn_id)
);
CREATE INDEX media_objects_owner_expiry ON media_objects (installation_id, session_id, generation, expires_at)
    WHERE deleted_at IS NULL;

ALTER TABLE response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE response_events ADD CONSTRAINT response_events_event_type_check CHECK (event_type IN (
    'turn.accepted', 'dialogue.complete', 'speech.ready', 'action.intent',
    'turn.complete', 'turn.failed', 'turn.cancelled'
));
