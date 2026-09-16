DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM lorkhan_internal.sessions WHERE archived) THEN RAISE EXCEPTION 'archival_sessions_rollback_requires_export'; END IF;
END $$;
DROP INDEX lorkhan_internal.sessions_runtime_generation_key;
ALTER TABLE lorkhan_internal.sessions ADD CONSTRAINT sessions_installation_id_generation_key UNIQUE(installation_id,generation);
ALTER TABLE lorkhan_internal.sessions DROP CONSTRAINT sessions_archive_inert;
ALTER TABLE lorkhan_internal.sessions DROP COLUMN archived;
