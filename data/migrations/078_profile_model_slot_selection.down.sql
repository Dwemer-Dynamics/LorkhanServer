ALTER TABLE lorkhan_internal.sessions
    ADD COLUMN IF NOT EXISTS provider_configuration_id uuid;

ALTER TABLE lorkhan_internal.sessions
    DROP CONSTRAINT IF EXISTS sessions_provider_configuration_fk;

ALTER TABLE lorkhan_internal.sessions
    ADD CONSTRAINT sessions_provider_configuration_fk
    FOREIGN KEY (provider_configuration_id, installation_id)
    REFERENCES lorkhan_internal.configuration_sets(configuration_id, installation_id);

ALTER TABLE lorkhan_internal.installation_profile_preferences
    DROP COLUMN IF EXISTS llm_model_slot;
