DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM almsivi_internal.profile_assignment_rules) THEN
        RAISE EXCEPTION 'Cannot remove saved Core Profile assignment rules.';
    END IF;
END $$;

DROP INDEX almsivi_internal.profile_assignment_rules_runtime;
DROP TABLE almsivi_internal.profile_assignment_rules;
