DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.profile_assignment_rules) THEN
        RAISE EXCEPTION 'Cannot remove saved Core Profile assignment rules.';
    END IF;
END $$;

DROP INDEX lorkhan_internal.profile_assignment_rules_runtime;
DROP TABLE lorkhan_internal.profile_assignment_rules;
