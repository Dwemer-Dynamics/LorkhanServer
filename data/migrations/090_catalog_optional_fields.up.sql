-- Match the catalog editors: identity/advanced content remain required; descriptive fields may be blank.
ALTER TABLE lorkhan_internal.item_descriptions
    DROP CONSTRAINT item_descriptions_display_name_check,
    DROP CONSTRAINT item_descriptions_description_check,
    ADD CONSTRAINT item_descriptions_display_name_check CHECK (length(display_name) BETWEEN 0 AND 256),
    ADD CONSTRAINT item_descriptions_description_check CHECK (length(description) BETWEEN 0 AND 8192);

ALTER TABLE lorkhan_internal.knowledge_documents
    DROP CONSTRAINT knowledge_basic_bytes,
    DROP CONSTRAINT knowledge_category_bytes,
    ADD CONSTRAINT knowledge_basic_bytes CHECK (octet_length(topic_desc_basic) BETWEEN 0 AND 131072),
    ADD CONSTRAINT knowledge_category_bytes CHECK (octet_length(category) BETWEEN 0 AND 128);
