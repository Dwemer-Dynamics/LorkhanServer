DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.relationship_revisions) THEN
        RAISE EXCEPTION 'Cannot remove relationship timeline history while revisions exist';
    END IF;
END $$;
DROP TABLE lorkhan_internal.relationship_revisions;
