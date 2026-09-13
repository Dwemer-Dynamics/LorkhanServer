DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.profile_assignment_rules WHERE core_profile_id IS NULL) THEN
        RAISE EXCEPTION 'Assign or remove action-only profile rules before reverting migration 104';
    END IF;
END $$;
ALTER TABLE lorkhan_internal.profile_assignment_rules ALTER COLUMN core_profile_id SET NOT NULL;
