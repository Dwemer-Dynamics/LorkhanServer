-- Immutable revisions preserve the provider choice of already queued background jobs.
CREATE TABLE lorkhan_internal.player2_routing (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
    revision integer NOT NULL CHECK (revision > 0),
    enabled boolean NOT NULL,
    configuration_id uuid,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (installation_id, revision),
    FOREIGN KEY (configuration_id, installation_id)
        REFERENCES lorkhan_internal.configuration_sets(configuration_id, installation_id),
    CHECK (NOT enabled OR configuration_id IS NOT NULL)
);
