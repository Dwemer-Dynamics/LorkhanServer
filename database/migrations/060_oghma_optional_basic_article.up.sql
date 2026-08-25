ALTER TABLE almsivi_internal.knowledge_documents
    DROP CONSTRAINT knowledge_basic_bytes,
    ADD CONSTRAINT knowledge_basic_bytes CHECK (octet_length(topic_desc_basic) BETWEEN 0 AND 131072);

ALTER TABLE almsivi_internal.oghma_catalog_entries
    DROP CONSTRAINT oghma_catalog_entries_topic_desc_basic_check,
    ADD CONSTRAINT oghma_catalog_entries_topic_desc_basic_check
        CHECK (octet_length(topic_desc_basic) BETWEEN 0 AND 131072);
