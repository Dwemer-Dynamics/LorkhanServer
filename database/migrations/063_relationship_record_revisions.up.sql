-- Preserve every legacy row while giving manual edits and future workers a revision fence.
ALTER TABLE almsivi_internal.relationship_records
    ADD COLUMN revision integer NOT NULL DEFAULT 1 CHECK (revision > 0);
ALTER TABLE almsivi_internal.relationship_audit ADD COLUMN audit_sequence bigint GENERATED ALWAYS AS IDENTITY;

-- Match the native actor binding identity: display names and current cells are not stable keys.
CREATE FUNCTION almsivi_internal.relationship_identity_key(identity jsonb)
RETURNS jsonb LANGUAGE sql IMMUTABLE PARALLEL SAFE
AS $$ SELECT jsonb_build_object('kind',identity->'kind','record_id',identity->'record_id',
    'content_file',identity->'content_file','refnum',identity->'refnum') $$;
CREATE INDEX relationship_identity_scope ON almsivi_internal.relationship_records
    (installation_id,profile_id,playthrough_id,md5(almsivi_internal.relationship_identity_key(actor_identity)::text))
    WHERE deleted_at IS NULL;

CREATE FUNCTION almsivi_internal.advance_relationship_revision()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.revision := OLD.revision + 1;
    RETURN NEW;
END $$;
CREATE TRIGGER relationship_revision
BEFORE UPDATE ON almsivi_internal.relationship_records
FOR EACH ROW EXECUTE FUNCTION almsivi_internal.advance_relationship_revision();
