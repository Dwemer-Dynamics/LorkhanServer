DELETE FROM almsivi_internal.knowledge_documents AS document
USING almsivi_internal.oghma_factory_documents AS factory
WHERE document.document_id=factory.document_id;
DROP TABLE IF EXISTS almsivi_internal.oghma_factory_documents;
DROP TABLE IF EXISTS almsivi_internal.oghma_catalog_entries;
DROP TABLE IF EXISTS almsivi_internal.oghma_catalogs;
DROP TABLE IF EXISTS almsivi_internal.oghma_installation_settings;
DROP INDEX IF EXISTS almsivi_internal.knowledge_factory_topic_uq;
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
        projected_topic := NEW.title;
        IF EXISTS (SELECT 1 FROM oghma WHERE topic=projected_topic) THEN
            projected_topic := NEW.title||' ['||left(NEW.document_id::text,8)||']';
        END IF;
        INSERT INTO oghma(topic,topic_desc,native_vector,knowledge_class,topic_desc_basic,
            knowledge_class_basic,tags,category,aliases)
        VALUES(projected_topic,NEW.content,to_tsvector('simple',NEW.content),'Morrowind',left(NEW.content,1000),
            'Morrowind',array_to_string(NEW.lexical_terms,','),COALESCE(NEW.provenance->>'category','ALMSIVI'),'');
        INSERT INTO oghma_metadata(topic,document_id,installation_id,profile_id,playthrough_id)
        VALUES(projected_topic,NEW.document_id,NEW.installation_id,NEW.profile_id,NEW.playthrough_id);
    ELSE
        UPDATE oghma SET topic_desc=NEW.content,native_vector=to_tsvector('simple',NEW.content),
            knowledge_class='Morrowind',topic_desc_basic=left(NEW.content,1000),knowledge_class_basic='Morrowind',
            tags=array_to_string(NEW.lexical_terms,','),category=COALESCE(NEW.provenance->>'category','ALMSIVI')
        WHERE topic=projected_topic;
        UPDATE oghma_metadata SET installation_id=NEW.installation_id,profile_id=NEW.profile_id,
            playthrough_id=NEW.playthrough_id WHERE topic=projected_topic;
    END IF;
    RETURN NEW;
END
$function$;
ALTER TABLE almsivi_internal.knowledge_documents
    DROP CONSTRAINT IF EXISTS knowledge_category_bytes,
    DROP CONSTRAINT IF EXISTS knowledge_basic_bytes,
    DROP CONSTRAINT IF EXISTS knowledge_topic_bytes,
    DROP COLUMN IF EXISTS category,
    DROP COLUMN IF EXISTS tags,
    DROP COLUMN IF EXISTS knowledge_class_basic,
    DROP COLUMN IF EXISTS knowledge_class,
    DROP COLUMN IF EXISTS topic_desc_basic,
    DROP COLUMN IF EXISTS aliases,
    DROP COLUMN IF EXISTS topic;
