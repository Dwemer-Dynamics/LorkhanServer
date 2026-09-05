DROP TABLE IF EXISTS migration_008_media_archive;
DROP TABLE IF EXISTS migration_008_stt_archive;
DELETE FROM durable_jobs WHERE job_type='turn.process' AND idempotency_key LIKE 'turn:%' AND state='queued';
ALTER TABLE action_delivery RENAME COLUMN emitted_at TO delivered_at;
ALTER TABLE dialogue_delivery_results DROP CONSTRAINT IF EXISTS dialogue_delivery_results_dialogue_fk;
DROP TABLE IF EXISTS dialogue_utterances;
ALTER TABLE turns DROP COLUMN IF EXISTS processing_attempts;
ALTER TABLE turns DROP COLUMN IF EXISTS processing_started_at;
