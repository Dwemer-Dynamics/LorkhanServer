ALTER TABLE almsivi_internal.knowledge_documents
    ADD COLUMN topic varchar(256),
    ADD COLUMN aliases text NOT NULL DEFAULT '',
    ADD COLUMN topic_desc_basic text,
    ADD COLUMN knowledge_class text NOT NULL DEFAULT '',
    ADD COLUMN knowledge_class_basic text NOT NULL DEFAULT '',
    ADD COLUMN tags text NOT NULL DEFAULT '',
    ADD COLUMN category text NOT NULL DEFAULT '';

UPDATE almsivi_internal.knowledge_documents
SET topic=title,topic_desc_basic=content,category=COALESCE(provenance->>'category','ALMSIVI')
WHERE topic IS NULL;

ALTER TABLE almsivi_internal.knowledge_documents
    ALTER COLUMN topic SET NOT NULL,
    ALTER COLUMN topic_desc_basic SET NOT NULL,
    ADD CONSTRAINT knowledge_topic_bytes CHECK (octet_length(topic) BETWEEN 1 AND 256),
    ADD CONSTRAINT knowledge_basic_bytes CHECK (octet_length(topic_desc_basic) BETWEEN 1 AND 131072),
    ADD CONSTRAINT knowledge_category_bytes CHECK (octet_length(category) BETWEEN 1 AND 128);

CREATE TABLE almsivi_internal.oghma_installation_settings (
    installation_id uuid PRIMARY KEY REFERENCES almsivi_internal.installations(installation_id) ON DELETE CASCADE,
    knowledge_tags text NOT NULL DEFAULT 'common' CHECK (octet_length(knowledge_tags) BETWEEN 0 AND 4096),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE UNIQUE INDEX knowledge_factory_topic_uq ON almsivi_internal.knowledge_documents (
    installation_id,lower(topic)
) WHERE deleted_at IS NULL AND provenance->>'source'='factory-oghma';

CREATE TABLE almsivi_internal.oghma_catalogs (
    catalog_id uuid PRIMARY KEY,
    catalog_version varchar(128) NOT NULL UNIQUE,
    format_version text NOT NULL,
    articles_sha256 char(64) NOT NULL,
    manifest_sha256 char(64) NOT NULL,
    ontology_sha256 char(64) NOT NULL,
    topic_seeds_sha256 char(64) NOT NULL,
    generator_sha256 char(64) NOT NULL,
    builder_sha256 char(64) NOT NULL,
    official_content_sha256 jsonb NOT NULL CHECK (jsonb_typeof(official_content_sha256)='object'),
    row_count integer NOT NULL CHECK (row_count BETWEEN 1 AND 1000),
    state text NOT NULL CHECK (state IN ('active','superseded')),
    previous_catalog_id uuid REFERENCES almsivi_internal.oghma_catalogs(catalog_id),
    imported_at timestamptz NOT NULL,
    activated_at timestamptz NOT NULL,
    superseded_at timestamptz,
    CHECK (catalog_version=btrim(catalog_version) AND length(catalog_version) BETWEEN 1 AND 128)
);
CREATE UNIQUE INDEX oghma_catalogs_one_active_uq ON almsivi_internal.oghma_catalogs ((state)) WHERE state='active';
CREATE INDEX oghma_catalogs_recent_idx ON almsivi_internal.oghma_catalogs (activated_at DESC,catalog_id);

CREATE TABLE almsivi_internal.oghma_catalog_entries (
    catalog_id uuid NOT NULL REFERENCES almsivi_internal.oghma_catalogs(catalog_id) ON DELETE CASCADE,
    topic varchar(256) NOT NULL,
    title varchar(256) NOT NULL,
    aliases text NOT NULL DEFAULT '',
    topic_desc text NOT NULL,
    knowledge_class text NOT NULL,
    topic_desc_basic text NOT NULL,
    knowledge_class_basic text NOT NULL,
    tags text NOT NULL,
    category text NOT NULL,
    PRIMARY KEY (catalog_id,topic),
    CHECK (octet_length(topic) BETWEEN 1 AND 256),
    CHECK (octet_length(title) BETWEEN 1 AND 256),
    CHECK (octet_length(topic_desc) BETWEEN 1 AND 131072),
    CHECK (octet_length(topic_desc_basic) BETWEEN 1 AND 131072),
    CHECK (octet_length(category) BETWEEN 1 AND 128)
);
CREATE UNIQUE INDEX oghma_catalog_entries_topic_uq ON almsivi_internal.oghma_catalog_entries (catalog_id,lower(topic));

CREATE TABLE almsivi_internal.oghma_factory_documents (
    installation_id uuid NOT NULL REFERENCES almsivi_internal.installations(installation_id) ON DELETE CASCADE,
    topic varchar(256) NOT NULL,
    document_id uuid NOT NULL UNIQUE REFERENCES almsivi_internal.knowledge_documents(document_id) ON DELETE CASCADE,
    catalog_id uuid NOT NULL REFERENCES almsivi_internal.oghma_catalogs(catalog_id),
    PRIMARY KEY (installation_id,topic)
);
CREATE INDEX oghma_factory_documents_catalog_idx ON almsivi_internal.oghma_factory_documents (catalog_id,installation_id);

CREATE OR REPLACE FUNCTION almsivi_internal.sync_knowledge_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = public, almsivi_internal, pg_temp
AS $function$
DECLARE projected_topic text;
BEGIN
    SELECT topic INTO projected_topic FROM oghma_metadata WHERE document_id=NEW.document_id;
    IF NEW.deleted_at IS NOT NULL THEN
        IF projected_topic IS NOT NULL THEN
            DELETE FROM oghma_metadata WHERE topic=projected_topic;
            DELETE FROM oghma WHERE topic=projected_topic;
        END IF;
        RETURN NEW;
    END IF;
    IF projected_topic IS NULL THEN
        projected_topic := NEW.topic;
        IF EXISTS (SELECT 1 FROM oghma WHERE topic=projected_topic) THEN
            projected_topic := NEW.topic||' ['||left(NEW.document_id::text,8)||']';
        END IF;
        INSERT INTO oghma(topic,topic_desc,native_vector,knowledge_class,topic_desc_basic,
            knowledge_class_basic,tags,category,aliases)
        VALUES(projected_topic,NEW.content,to_tsvector('simple',concat_ws(' ',NEW.topic,NEW.aliases,NEW.content,NEW.tags)),
            NEW.knowledge_class,NEW.topic_desc_basic,NEW.knowledge_class_basic,NEW.tags,NEW.category,NEW.aliases);
        INSERT INTO oghma_metadata(topic,document_id,installation_id,profile_id,playthrough_id)
        VALUES(projected_topic,NEW.document_id,NEW.installation_id,NEW.profile_id,NEW.playthrough_id);
    ELSE
        UPDATE oghma SET topic_desc=NEW.content,
            native_vector=to_tsvector('simple',concat_ws(' ',NEW.topic,NEW.aliases,NEW.content,NEW.tags)),
            knowledge_class=NEW.knowledge_class,topic_desc_basic=NEW.topic_desc_basic,
            knowledge_class_basic=NEW.knowledge_class_basic,tags=NEW.tags,category=NEW.category,aliases=NEW.aliases
        WHERE topic=projected_topic;
        UPDATE oghma_metadata SET installation_id=NEW.installation_id,profile_id=NEW.profile_id,
            playthrough_id=NEW.playthrough_id WHERE topic=projected_topic;
    END IF;
    RETURN NEW;
END
$function$;
