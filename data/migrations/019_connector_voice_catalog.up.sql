CREATE TABLE speech_connector_voices (
    configuration_id uuid NOT NULL REFERENCES configuration_sets(configuration_id) ON DELETE CASCADE,
    voice_id text NOT NULL CHECK (octet_length(voice_id) BETWEEN 1 AND 512),
    display_name text NOT NULL CHECK (octet_length(display_name) BETWEEN 1 AND 512),
    language text NOT NULL CHECK (octet_length(language) BETWEEN 2 AND 35),
    provider_status text NOT NULL CHECK (octet_length(provider_status) BETWEEN 1 AND 64),
    custom_voice boolean NOT NULL DEFAULT false,
    discovered_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (configuration_id, voice_id)
);

CREATE INDEX speech_connector_voices_discovered
    ON speech_connector_voices (configuration_id, discovered_at DESC);
