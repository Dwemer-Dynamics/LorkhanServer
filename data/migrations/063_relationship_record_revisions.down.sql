DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.relationship_records WHERE revision > 1) THEN
        RAISE EXCEPTION 'Cannot remove relationship revision protection after records have been edited.';
    END IF;
END $$;
DROP TRIGGER relationship_revision ON lorkhan_internal.relationship_records;
DROP FUNCTION lorkhan_internal.advance_relationship_revision();
DROP INDEX lorkhan_internal.relationship_identity_scope;
DROP FUNCTION lorkhan_internal.relationship_identity_key(jsonb);
ALTER TABLE lorkhan_internal.relationship_records DROP COLUMN revision;
ALTER TABLE lorkhan_internal.relationship_audit DROP COLUMN audit_sequence;
