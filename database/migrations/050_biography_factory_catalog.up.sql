CREATE TABLE almsivi_internal.biography_catalogs (
    catalog_id uuid PRIMARY KEY,
    catalog_version varchar(128) NOT NULL UNIQUE,
    source_kind text NOT NULL CHECK (source_kind IN ('imported','legacy_snapshot')),
    format_version text,
    model text,
    biographies_sha256 char(64) NOT NULL,
    manifest_sha256 char(64),
    generator_sha256 char(64),
    official_content_sha256 jsonb NOT NULL DEFAULT '{}'::jsonb
        CHECK (jsonb_typeof(official_content_sha256)='object'),
    row_count integer NOT NULL CHECK (row_count BETWEEN 0 AND 5000),
    state text NOT NULL CHECK (state IN ('active','superseded')),
    previous_catalog_id uuid REFERENCES almsivi_internal.biography_catalogs(catalog_id),
    imported_at timestamptz NOT NULL,
    activated_at timestamptz NOT NULL,
    superseded_at timestamptz,
    CHECK (catalog_version=btrim(catalog_version) AND length(catalog_version) BETWEEN 1 AND 128),
    CHECK (source_kind='legacy_snapshot' OR (
        format_version IS NOT NULL AND model IS NOT NULL AND manifest_sha256 IS NOT NULL
    ))
);
CREATE UNIQUE INDEX biography_catalogs_one_active_uq
    ON almsivi_internal.biography_catalogs ((state)) WHERE state='active';
CREATE INDEX biography_catalogs_recent_idx
    ON almsivi_internal.biography_catalogs (activated_at DESC,catalog_id);

CREATE TABLE almsivi_internal.biography_catalog_entries (
    catalog_id uuid NOT NULL REFERENCES almsivi_internal.biography_catalogs(catalog_id) ON DELETE CASCADE,
    content_file varchar(256),
    record_id varchar(256) NOT NULL,
    display_name varchar(256) NOT NULL,
    npc_name varchar(128) NOT NULL,
    oghma_knowledge_tags text,
    core text,
    npc_static_bio text,
    appearance text,
    personality text,
    relationships text,
    occupation text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    gender text,
    race text,
    PRIMARY KEY (catalog_id,npc_name),
    CHECK (content_file IS NULL OR length(content_file) BETWEEN 1 AND 256),
    CHECK (length(record_id) BETWEEN 1 AND 256),
    CHECK (length(display_name) BETWEEN 1 AND 256),
    CHECK (relationships IS NULL OR jsonb_typeof(relationships::jsonb)='object')
);
CREATE UNIQUE INDEX biography_catalog_entries_identity_uq
    ON almsivi_internal.biography_catalog_entries (
        catalog_id,lower(COALESCE(content_file,'')),lower(record_id)
    );
CREATE INDEX biography_catalog_entries_lookup_idx
    ON almsivi_internal.biography_catalog_entries (lower(content_file),lower(record_id));
