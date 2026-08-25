UPDATE almsivi_internal.knowledge_documents
SET topic_desc_basic = content,
    knowledge_class_basic = CASE WHEN btrim(knowledge_class_basic) = '' THEN 'common' ELSE knowledge_class_basic END
WHERE topic_desc_basic = '';

UPDATE almsivi_internal.oghma_catalog_entries
SET topic_desc_basic = topic_desc,
    knowledge_class_basic = CASE WHEN btrim(knowledge_class_basic) = '' THEN 'common' ELSE knowledge_class_basic END
WHERE topic_desc_basic = '';

ALTER TABLE almsivi_internal.knowledge_documents
    DROP CONSTRAINT knowledge_basic_bytes,
    ADD CONSTRAINT knowledge_basic_bytes CHECK (octet_length(topic_desc_basic) BETWEEN 1 AND 131072);

ALTER TABLE almsivi_internal.oghma_catalog_entries
    DROP CONSTRAINT oghma_catalog_entries_topic_desc_basic_check,
    ADD CONSTRAINT oghma_catalog_entries_topic_desc_basic_check
        CHECK (octet_length(topic_desc_basic) BETWEEN 1 AND 131072);
