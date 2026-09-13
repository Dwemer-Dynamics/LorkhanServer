-- Reject incompatible snapshots before committing any imported state.
DO $restore$
BEGIN
    IF EXISTS ((SELECT version,checksum FROM pg_temp.restore_schema EXCEPT SELECT version,checksum FROM lorkhan_internal.schema_migrations)
        UNION ALL (SELECT version,checksum FROM lorkhan_internal.schema_migrations EXCEPT SELECT version,checksum FROM pg_temp.restore_schema)) THEN
        RAISE EXCEPTION 'restore_schema_mismatch';
    END IF;
    IF EXISTS ((SELECT installation_id FROM pg_temp.restore_installations EXCEPT SELECT installation_id FROM lorkhan_internal.installations)
        UNION ALL (SELECT installation_id FROM lorkhan_internal.installations EXCEPT SELECT installation_id FROM pg_temp.restore_installations)) THEN
        RAISE EXCEPTION 'restore_installation_mismatch';
    END IF;
END
$restore$;

DELETE FROM lorkhan_internal.pairing_tokens;
INSERT INTO lorkhan_internal.pairing_tokens SELECT * FROM pg_temp.restore_pairing;
INSERT INTO lorkhan_internal.request_mac_nonces SELECT * FROM pg_temp.restore_nonces;
UPDATE lorkhan_internal.installations i SET token_fingerprint=r.token_fingerprint,revoked_at=r.revoked_at
    FROM pg_temp.restore_installations r WHERE i.installation_id=r.installation_id;
DELETE FROM lorkhan_internal.browser_sessions;
INSERT INTO lorkhan_internal.browser_sessions SELECT * FROM pg_temp.restore_browser;
DELETE FROM lorkhan_internal.backup_records;
INSERT INTO lorkhan_internal.backup_records SELECT * FROM pg_temp.restore_backups;
DELETE FROM lorkhan_internal.database_backup_settings;
INSERT INTO lorkhan_internal.database_backup_settings SELECT * FROM pg_temp.restore_backup_settings;

-- Publish the newly captured generation under the previous playthrough's name only
-- if the switch commits. Archives remain immutable; the old generation stays recoverable.
UPDATE lorkhan_internal.backup_records b SET scope=(b.scope-'snapshot')||
    jsonb_build_object('superseded_snapshot',s.snapshot,'superseded_by',:'rollback_id')
    FROM pg_temp.restore_named_source s WHERE b.backup_id=s.backup_id;
UPDATE lorkhan_internal.backup_records b SET scope=b.scope||
    jsonb_build_object('snapshot',s.snapshot,'previous_generation',s.backup_id)
    FROM pg_temp.restore_named_source s WHERE b.backup_id=:'rollback_id';

-- Restored history is retained, but old queued game/provider work must never be resumed.
UPDATE lorkhan_internal.sessions SET state='ended',ended_at=clock_timestamp() WHERE state='active';
UPDATE lorkhan_internal.turns SET state='cancelled',completed_at=clock_timestamp() WHERE state IN ('accepted','processing');
UPDATE lorkhan_internal.stt_requests SET state='failed',completed_at=clock_timestamp(),provider_error_code='database_restored' WHERE state IN ('accepted','processing');
UPDATE lorkhan_internal.dialogue_utterances SET delivery_state='interrupted' WHERE delivery_state='pending';
UPDATE lorkhan_internal.provider_attempts SET state='cancelled',finished_at=clock_timestamp(),error_code='database_restored' WHERE state='started';
UPDATE lorkhan_internal.durable_jobs SET state='dead',last_error_code='database_restored',last_error_detail=NULL,
    completed_at=clock_timestamp(),updated_at=clock_timestamp(),lease_owner=NULL,lease_token=NULL,leased_at=NULL,
    lease_expires_at=NULL,heartbeat_at=NULL WHERE state IN ('queued','leased');
UPDATE lorkhan_internal.durable_job_attempts SET outcome='dead',finished_at=clock_timestamp(),error_code='database_restored',error_detail=NULL
    WHERE finished_at IS NULL;

-- Preserve this job and its lease so the normal worker can acknowledge success after COMMIT.
INSERT INTO lorkhan_internal.durable_jobs SELECT * FROM pg_temp.restore_job;
SELECT pg_catalog.setval(pg_get_serial_sequence('lorkhan_internal.durable_job_attempts','attempt_id'),
    GREATEST(1,(SELECT COALESCE(max(attempt_id),0) FROM lorkhan_internal.durable_job_attempts)),true);
INSERT INTO lorkhan_internal.durable_job_attempts
    (job_id,attempt_number,lease_token,worker_id,started_at,heartbeat_at,finished_at,outcome,error_code,error_detail)
    SELECT job_id,attempt_number,lease_token,worker_id,started_at,heartbeat_at,finished_at,outcome,error_code,error_detail FROM pg_temp.restore_attempt;
UPDATE lorkhan_internal.backup_records SET state='restored',restored_at=clock_timestamp() WHERE backup_id=:'backup_id';
UPDATE lorkhan_internal.database_snapshot_source SET backup_id=:'backup_id',
    name=(SELECT scope#>>'{snapshot,name}' FROM lorkhan_internal.backup_records WHERE backup_id=:'backup_id'),
    copied_at=clock_timestamp() WHERE singleton;
