ALTER TABLE configuration_sets
    ADD CONSTRAINT configuration_sets_id_installation_unique
    UNIQUE (configuration_id, installation_id);

ALTER TABLE sessions
    ADD COLUMN provider_configuration_id uuid;

ALTER TABLE sessions
    ADD CONSTRAINT sessions_provider_configuration_fk
    FOREIGN KEY (provider_configuration_id, installation_id)
    REFERENCES configuration_sets(configuration_id, installation_id);

CREATE TABLE actor_profile_bindings (
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    actor_key char(64) NOT NULL CHECK (actor_key ~ '^[0-9a-f]{64}$'),
    actor_identity jsonb NOT NULL CHECK (jsonb_typeof(actor_identity) = 'object'),
    profile_id uuid NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id, playthrough_id, actor_key),
    FOREIGN KEY (profile_id, installation_id)
        REFERENCES profiles(profile_id, installation_id)
);

CREATE INDEX actor_profile_bindings_profile
    ON actor_profile_bindings (installation_id, profile_id);

ALTER TABLE prompt_traces
    ADD COLUMN selected_profile_id uuid,
    ADD COLUMN selected_profile_revision integer CHECK (selected_profile_revision IS NULL OR selected_profile_revision > 0);

ALTER TABLE prompt_traces
    ADD CONSTRAINT prompt_traces_selected_profile_fk
    FOREIGN KEY (selected_profile_id, installation_id)
    REFERENCES profiles(profile_id, installation_id);
