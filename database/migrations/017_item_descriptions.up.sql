CREATE TABLE item_descriptions (
    description_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    content_file varchar(256) NOT NULL,
    record_id varchar(256) NOT NULL,
    display_name varchar(256) NOT NULL,
    description text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz NULL,
    CHECK (length(content_file) BETWEEN 1 AND 256),
    CHECK (length(record_id) BETWEEN 1 AND 256),
    CHECK (length(display_name) BETWEEN 1 AND 256),
    CHECK (length(description) BETWEEN 1 AND 8192)
);
CREATE UNIQUE INDEX item_descriptions_identity_active_uq
    ON item_descriptions (installation_id,lower(content_file),lower(record_id)) WHERE deleted_at IS NULL;
CREATE INDEX item_descriptions_lookup_idx
    ON item_descriptions (installation_id,lower(record_id)) WHERE deleted_at IS NULL;
