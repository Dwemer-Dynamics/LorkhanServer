DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.core_profile_presets) THEN
        RAISE EXCEPTION 'Export and remove Core Profile presets before reverting migration 093';
    END IF;
END $$;
DROP TABLE lorkhan_internal.core_profile_presets;
