CREATE TABLE almsivi_internal.description_catalogs (
    catalog_id uuid PRIMARY KEY,
    catalog_version varchar(128) NOT NULL UNIQUE,
    source_kind text NOT NULL CHECK (source_kind IN ('imported','legacy_snapshot')),
    format_version text,
    prompt_sha256 char(64),
    model text,
    csv_sha256 char(64) NOT NULL,
    manifest_sha256 char(64),
    official_content_sha256 jsonb NOT NULL DEFAULT '{}'::jsonb
        CHECK (jsonb_typeof(official_content_sha256)='object'),
    row_count integer NOT NULL CHECK (row_count BETWEEN 0 AND 5000),
    state text NOT NULL CHECK (state IN ('active','superseded')),
    previous_catalog_id uuid REFERENCES almsivi_internal.description_catalogs(catalog_id),
    imported_at timestamptz NOT NULL,
    activated_at timestamptz NOT NULL,
    superseded_at timestamptz,
    CHECK (catalog_version=btrim(catalog_version) AND length(catalog_version) BETWEEN 1 AND 128),
    CHECK (source_kind='legacy_snapshot' OR (
        format_version IS NOT NULL AND prompt_sha256 IS NOT NULL AND model IS NOT NULL
        AND manifest_sha256 IS NOT NULL
    ))
);
CREATE UNIQUE INDEX description_catalogs_one_active_uq
    ON almsivi_internal.description_catalogs ((state)) WHERE state='active';
CREATE INDEX description_catalogs_recent_idx
    ON almsivi_internal.description_catalogs (activated_at DESC,catalog_id);

CREATE TABLE almsivi_internal.description_catalog_entries (
    catalog_id uuid NOT NULL REFERENCES almsivi_internal.description_catalogs(catalog_id) ON DELETE CASCADE,
    plugin text NOT NULL,
    baseid varchar(128) NOT NULL,
    name text,
    description text,
    PRIMARY KEY (catalog_id,plugin,baseid),
    CHECK (plugin IN ('Morrowind.esm','Tribunal.esm','Bloodmoon.esm')),
    CHECK (length(baseid) BETWEEN 1 AND 128)
);
CREATE UNIQUE INDEX description_catalog_entries_canonical_uq
    ON almsivi_internal.description_catalog_entries (catalog_id,lower(plugin),lower(baseid));
