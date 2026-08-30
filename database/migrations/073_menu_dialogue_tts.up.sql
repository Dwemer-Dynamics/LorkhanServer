CREATE TABLE lorkhan_internal.menu_dialogue_tts_requests (
    message_id uuid PRIMARY KEY,
    request_id uuid NOT NULL UNIQUE,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id),
    session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id),
    generation bigint NOT NULL CHECK (generation >= 0),
    actor jsonb NOT NULL CHECK (jsonb_typeof(actor) = 'object'),
    text_sha256 char(64) NOT NULL CHECK (text_sha256 ~ '^[0-9a-f]{64}$'),
    created_at timestamptz NOT NULL,
    completed_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

ALTER TABLE lorkhan_internal.media_objects ALTER COLUMN turn_id DROP NOT NULL;
ALTER TABLE lorkhan_internal.media_objects ADD COLUMN menu_dialogue_message_id uuid
    REFERENCES lorkhan_internal.menu_dialogue_tts_requests(message_id);
ALTER TABLE lorkhan_internal.media_objects ADD CONSTRAINT media_objects_single_source_check CHECK (
    (turn_id IS NOT NULL AND menu_dialogue_message_id IS NULL)
    OR (turn_id IS NULL AND menu_dialogue_message_id IS NOT NULL)
);
CREATE UNIQUE INDEX media_objects_menu_dialogue_message_unique
    ON lorkhan_internal.media_objects (menu_dialogue_message_id) WHERE menu_dialogue_message_id IS NOT NULL;
