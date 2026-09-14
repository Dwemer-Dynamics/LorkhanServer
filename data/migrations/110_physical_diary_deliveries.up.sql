-- Diary delivery is independent from operator commands and model action intents.
CREATE TABLE lorkhan_internal.physical_diary_deliveries (
    delivery_id uuid PRIMARY KEY,
    book_id uuid NOT NULL,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
    profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles(profile_id),
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id),
    session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id),
    generation bigint NOT NULL CHECK (generation >= 0),
    snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot)='object' AND octet_length(snapshot::text)<=16384),
    content_hash char(64) NOT NULL CHECK (content_hash ~ '^[0-9a-f]{64}$'),
    state text NOT NULL DEFAULT 'delivered' CHECK (state IN ('delivered','succeeded','failed','superseded')),
    result_message_id uuid,
    result_fingerprint char(64),
    reason_code text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    checked_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    completed_at timestamptz,
    retry_after timestamptz,
    CHECK ((result_message_id IS NULL) = (result_fingerprint IS NULL))
);
CREATE UNIQUE INDEX physical_diary_one_pending ON lorkhan_internal.physical_diary_deliveries(session_id,book_id) WHERE state='delivered';
CREATE INDEX physical_diary_session_profile ON lorkhan_internal.physical_diary_deliveries(session_id,profile_id,created_at DESC);
