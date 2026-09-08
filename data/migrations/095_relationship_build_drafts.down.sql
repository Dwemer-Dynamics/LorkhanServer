DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.relationship_build_results WHERE draft IS NOT NULL) THEN
        RAISE EXCEPTION 'Review and remove relationship build drafts before reverting migration 095';
    END IF;
END $$;
ALTER TABLE lorkhan_internal.relationship_build_results DROP COLUMN draft;
