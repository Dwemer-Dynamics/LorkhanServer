ALTER TABLE lorkhan_internal.installation_profile_preferences
    ADD COLUMN IF NOT EXISTS llm_model_slot text NOT NULL DEFAULT 'standard'
        CHECK (llm_model_slot IN ('standard', 'fast', 'powerful', 'experimental'));

ALTER TABLE lorkhan_internal.sessions
    DROP CONSTRAINT IF EXISTS sessions_provider_configuration_fk,
    DROP COLUMN IF EXISTS provider_configuration_id;
