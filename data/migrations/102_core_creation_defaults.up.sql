ALTER TABLE lorkhan_internal.installation_profile_preferences
    ADD COLUMN core_creation_preset jsonb
        CHECK (core_creation_preset IS NULL OR jsonb_typeof(core_creation_preset) = 'object');
