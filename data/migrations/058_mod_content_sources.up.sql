ALTER TABLE lorkhan_internal.oghma_catalog_entries
    ADD COLUMN mod_source varchar(256),
    ADD CONSTRAINT oghma_catalog_entries_mod_source_check CHECK (
        mod_source IS NULL OR (
            mod_source=btrim(mod_source)
            AND mod_source ~* '^[^/\\]+\.(esm|esp|omwaddon)$'
        )
    );

CREATE INDEX knowledge_factory_mod_source_idx
    ON lorkhan_internal.knowledge_documents (
        installation_id,
        lower(COALESCE(provenance->>'mod_source',''))
    )
    WHERE deleted_at IS NULL AND provenance->>'source'='factory-oghma';

ALTER TABLE lorkhan_internal.biography_catalogs
    DROP CONSTRAINT biography_catalogs_row_count_check;

ALTER TABLE lorkhan_internal.biography_catalogs
    ADD CONSTRAINT biography_catalogs_row_count_check CHECK (row_count BETWEEN 0 AND 10000);
