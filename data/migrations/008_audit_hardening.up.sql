ALTER TABLE turns ADD COLUMN processing_job_id uuid REFERENCES durable_jobs(job_id);
ALTER TABLE turns ADD COLUMN processing_lease_token uuid;
ALTER TABLE turns ADD COLUMN processing_job_attempt integer CHECK (processing_job_attempt IS NULL OR processing_job_attempt > 0);

CREATE TABLE turn_provider_snapshots (
    turn_id uuid PRIMARY KEY REFERENCES turns(turn_id) ON DELETE CASCADE,
    source_manifest jsonb NOT NULL CHECK (jsonb_typeof(source_manifest) = 'object'),
    input_sha256 char(64) NOT NULL CHECK (input_sha256 ~ '^[0-9a-f]{64}$'),
    created_at timestamptz NOT NULL
);

ALTER TABLE media_objects DROP CONSTRAINT media_objects_turn_id_key;
ALTER TABLE media_objects ADD COLUMN dialogue_message_id uuid REFERENCES dialogue_utterances(dialogue_message_id);
CREATE UNIQUE INDEX media_objects_dialogue_unique ON media_objects(dialogue_message_id) WHERE dialogue_message_id IS NOT NULL;
DO $migration$
BEGIN
    IF to_regclass('migration_008_media_archive') IS NOT NULL THEN
        INSERT INTO media_objects
            (media_id,installation_id,session_id,turn_id,generation,sha256,byte_count,codec,mime_type,
             duration_ms,expires_at,created_at,deleted_at,dialogue_message_id)
        SELECT media_id,installation_id,session_id,turn_id,generation,sha256,byte_count,codec,mime_type,
            duration_ms,expires_at,created_at,deleted_at,dialogue_message_id
        FROM migration_008_media_archive
        ON CONFLICT (media_id) DO UPDATE SET dialogue_message_id=EXCLUDED.dialogue_message_id;
        DROP TABLE migration_008_media_archive;
    END IF;
END
$migration$;

ALTER TABLE stt_requests ADD COLUMN storage_media_id uuid;
ALTER TABLE stt_requests ADD COLUMN semantic_hash char(64) CHECK (semantic_hash IS NULL OR semantic_hash ~ '^[0-9a-f]{64}$');
ALTER TABLE stt_requests ADD COLUMN accepted_cursor bigint CHECK (accepted_cursor IS NULL OR accepted_cursor >= 0);
ALTER TABLE stt_requests ADD COLUMN provider_error_code text;
ALTER TABLE stt_requests ADD COLUMN processing_job_id uuid REFERENCES durable_jobs(job_id);
ALTER TABLE stt_requests ADD COLUMN processing_lease_token uuid;
ALTER TABLE stt_requests ADD COLUMN processing_job_attempt integer CHECK(processing_job_attempt IS NULL OR processing_job_attempt>0);
ALTER TABLE stt_requests ADD COLUMN cleanup_pending boolean NOT NULL DEFAULT false;
ALTER TABLE stt_requests DROP CONSTRAINT stt_requests_state_check;
ALTER TABLE stt_requests ADD CONSTRAINT stt_requests_state_check CHECK (state IN ('accepted','processing','transcribed','failed'));
CREATE UNIQUE INDEX stt_requests_storage_unique ON stt_requests(storage_media_id) WHERE storage_media_id IS NOT NULL;
DO $migration$
BEGIN
    IF to_regclass('migration_008_stt_archive') IS NOT NULL THEN
        UPDATE stt_requests s SET storage_media_id=a.storage_media_id,semantic_hash=a.semantic_hash,
            accepted_cursor=a.accepted_cursor,provider_error_code=a.provider_error_code
        FROM migration_008_stt_archive a WHERE a.message_id=s.message_id;
        DROP TABLE migration_008_stt_archive;
    END IF;
END
$migration$;

-- STT is accepted before its turn exists; keep historical rows and permit provider audit plus
-- transcript/failure events to retain the client's future turn correlation.
ALTER TABLE provider_attempts DROP CONSTRAINT provider_attempts_turn_id_fkey;
ALTER TABLE response_events DROP CONSTRAINT response_events_turn_id_fkey;

ALTER TABLE dialogue_delivery_results DROP CONSTRAINT dialogue_delivery_results_dialogue_fk;
ALTER TABLE dialogue_delivery_results ADD CONSTRAINT dialogue_delivery_results_dialogue_fk
    FOREIGN KEY(dialogue_message_id) REFERENCES dialogue_utterances(dialogue_message_id) NOT VALID;

ALTER TABLE dialogue_utterances ADD COLUMN expiry_job_id uuid REFERENCES durable_jobs(job_id);

INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,next_run_at,priority)
SELECT md5('dialogue.expire:v1:'||dialogue_message_id::text)::uuid,'dialogue.expire',1,
    'dialogue:'||dialogue_message_id::text,jsonb_build_object('dialogue_message_id',dialogue_message_id),1,
    delivery_deadline_at,50 FROM dialogue_utterances WHERE delivery_state='pending'
ON CONFLICT(job_type,idempotency_key) DO NOTHING;
UPDATE dialogue_utterances u SET expiry_job_id=j.job_id FROM durable_jobs j
WHERE j.job_type='dialogue.expire' AND j.idempotency_key='dialogue:'||u.dialogue_message_id::text;
