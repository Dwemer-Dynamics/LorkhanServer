ALTER TABLE dialogue_utterances DROP COLUMN IF EXISTS expiry_job_id;
DELETE FROM durable_jobs WHERE job_type='dialogue.expire';
ALTER TABLE dialogue_delivery_results DROP CONSTRAINT IF EXISTS dialogue_delivery_results_dialogue_fk;
ALTER TABLE dialogue_delivery_results ADD CONSTRAINT dialogue_delivery_results_dialogue_fk
    FOREIGN KEY(dialogue_message_id) REFERENCES dialogue_utterances(dialogue_message_id) NOT VALID;

-- Migration 006 has no processing state or durable STT metadata. Preserve the 008-only
-- columns for a later reapply and safely return in-flight requests to accepted so they can retry.
CREATE TABLE migration_008_stt_archive (
    message_id uuid PRIMARY KEY,
    storage_media_id uuid,
    semantic_hash char(64),
    accepted_cursor bigint,
    provider_error_code text,
    was_processing boolean NOT NULL
);
INSERT INTO migration_008_stt_archive
    (message_id,storage_media_id,semantic_hash,accepted_cursor,provider_error_code,was_processing)
SELECT message_id,storage_media_id,semantic_hash,accepted_cursor,provider_error_code,state='processing'
FROM stt_requests;
UPDATE stt_requests SET state='accepted' WHERE state='processing';
DROP INDEX IF EXISTS stt_requests_storage_unique;
ALTER TABLE stt_requests DROP CONSTRAINT IF EXISTS stt_requests_state_check;
ALTER TABLE stt_requests ADD CONSTRAINT stt_requests_state_check CHECK (state IN ('accepted','transcribed','failed'));
ALTER TABLE stt_requests DROP COLUMN IF EXISTS cleanup_pending;
ALTER TABLE stt_requests DROP COLUMN IF EXISTS processing_job_attempt;
ALTER TABLE stt_requests DROP COLUMN IF EXISTS processing_lease_token;
ALTER TABLE stt_requests DROP COLUMN IF EXISTS processing_job_id;
ALTER TABLE stt_requests DROP COLUMN IF EXISTS provider_error_code;
ALTER TABLE stt_requests DROP COLUMN IF EXISTS accepted_cursor;
ALTER TABLE stt_requests DROP COLUMN IF EXISTS semantic_hash;
ALTER TABLE stt_requests DROP COLUMN IF EXISTS storage_media_id;

-- Migration 007 permits only one media row per turn. Archive every 008 media row so a
-- multi-utterance turn can be represented by one row while downgraded and restored exactly.
CREATE TABLE migration_008_media_archive (
    media_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL,
    session_id uuid NOT NULL,
    turn_id uuid NOT NULL,
    generation bigint NOT NULL,
    sha256 char(64) NOT NULL,
    byte_count integer NOT NULL,
    codec text NOT NULL,
    mime_type text NOT NULL,
    duration_ms integer NOT NULL,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL,
    deleted_at timestamptz,
    dialogue_message_id uuid
);
INSERT INTO migration_008_media_archive
SELECT media_id,installation_id,session_id,turn_id,generation,sha256,byte_count,codec,mime_type,
    duration_ms,expires_at,created_at,deleted_at,dialogue_message_id
FROM media_objects;
DELETE FROM media_objects m USING (
    SELECT media_id,row_number() OVER (PARTITION BY turn_id ORDER BY created_at,media_id) AS ordinal
    FROM media_objects
) ranked WHERE ranked.media_id=m.media_id AND ranked.ordinal>1;
DROP INDEX IF EXISTS media_objects_dialogue_unique;
ALTER TABLE media_objects DROP COLUMN IF EXISTS dialogue_message_id;
ALTER TABLE media_objects ADD CONSTRAINT media_objects_turn_id_key UNIQUE(turn_id);

-- Pre-turn STT audit/events may legitimately reference the turn that the client creates after
-- transcription. Keep those legacy rows while restoring old FKs for subsequent writes.
ALTER TABLE provider_attempts ADD CONSTRAINT provider_attempts_turn_id_fkey
    FOREIGN KEY(turn_id) REFERENCES turns(turn_id) NOT VALID;
ALTER TABLE response_events ADD CONSTRAINT response_events_turn_id_fkey
    FOREIGN KEY(turn_id) REFERENCES turns(turn_id) NOT VALID;

DROP TABLE IF EXISTS turn_provider_snapshots;
ALTER TABLE turns DROP COLUMN IF EXISTS processing_job_attempt;
ALTER TABLE turns DROP COLUMN IF EXISTS processing_lease_token;
ALTER TABLE turns DROP COLUMN IF EXISTS processing_job_id;
