ALTER TABLE lorkhan_internal.sessions ADD COLUMN archived boolean NOT NULL DEFAULT false;
ALTER TABLE lorkhan_internal.sessions ADD CONSTRAINT sessions_archive_inert CHECK
    (NOT archived OR (state='ended' AND cardinality(capabilities)=0 AND cardinality(enabled_actions)=0 AND character_id IS NULL));
ALTER TABLE lorkhan_internal.sessions DROP CONSTRAINT sessions_installation_id_generation_key;
CREATE UNIQUE INDEX sessions_runtime_generation_key ON lorkhan_internal.sessions(installation_id,generation) WHERE NOT archived;
