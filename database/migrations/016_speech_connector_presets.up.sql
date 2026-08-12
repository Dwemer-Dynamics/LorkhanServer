ALTER TABLE configuration_sets
    DROP CONSTRAINT IF EXISTS configuration_sets_kind_check;

ALTER TABLE configuration_sets
    ADD CONSTRAINT configuration_sets_kind_check
    CHECK (kind IN ('prompt', 'provider', 'tts_provider', 'stt_provider', 'action_policy'));

CREATE TABLE installation_provider_selections (
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    provider_kind text NOT NULL CHECK (provider_kind IN ('tts_provider', 'stt_provider')),
    configuration_id uuid NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id, provider_kind),
    FOREIGN KEY (configuration_id, installation_id)
        REFERENCES configuration_sets(configuration_id, installation_id)
);

CREATE INDEX installation_provider_selections_configuration
    ON installation_provider_selections (configuration_id);
