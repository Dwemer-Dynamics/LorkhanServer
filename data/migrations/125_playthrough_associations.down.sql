DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM lorkhan_internal.playthrough_associations) THEN RAISE EXCEPTION 'playthrough_associations_rollback_requires_export'; END IF;
END $$;
DROP TABLE lorkhan_internal.playthrough_associations;
