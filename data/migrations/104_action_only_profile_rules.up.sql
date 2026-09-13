-- Action-only import rules preserve the Core Profile selected by another rule or the installation default.
ALTER TABLE lorkhan_internal.profile_assignment_rules ALTER COLUMN core_profile_id DROP NOT NULL;
