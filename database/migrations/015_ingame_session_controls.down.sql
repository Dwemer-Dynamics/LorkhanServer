ALTER TABLE prompt_traces
    DROP CONSTRAINT IF EXISTS prompt_traces_selected_profile_fk,
    DROP COLUMN IF EXISTS selected_profile_revision,
    DROP COLUMN IF EXISTS selected_profile_id;

DROP TABLE IF EXISTS actor_profile_bindings;

ALTER TABLE sessions
    DROP CONSTRAINT IF EXISTS sessions_provider_configuration_fk,
    DROP COLUMN IF EXISTS provider_configuration_id;

ALTER TABLE configuration_sets
    DROP CONSTRAINT IF EXISTS configuration_sets_id_installation_unique;
