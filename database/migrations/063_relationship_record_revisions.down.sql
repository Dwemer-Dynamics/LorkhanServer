DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM almsivi_internal.relationship_records WHERE revision > 1) THEN
        RAISE EXCEPTION 'Cannot remove relationship revision protection after records have been edited.';
    END IF;
END $$;
DROP TRIGGER relationship_revision ON almsivi_internal.relationship_records;
DROP FUNCTION almsivi_internal.advance_relationship_revision();
DROP INDEX almsivi_internal.relationship_identity_scope;
DROP FUNCTION almsivi_internal.relationship_identity_key(jsonb);
ALTER TABLE almsivi_internal.relationship_records DROP COLUMN revision;
ALTER TABLE almsivi_internal.relationship_audit DROP COLUMN audit_sequence;
