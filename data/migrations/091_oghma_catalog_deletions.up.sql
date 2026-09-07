-- Remember user-deleted topics independently of replaceable factory projections.
CREATE TABLE lorkhan_internal.oghma_catalog_deletions (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    topic varchar(256) NOT NULL CHECK (topic=lower(btrim(topic)) AND length(topic)>0),
    deleted_at timestamptz NOT NULL,
    PRIMARY KEY (installation_id,topic)
);
