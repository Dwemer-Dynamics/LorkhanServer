-- Refuse a lossy rollback rather than invent names, categories or public basic knowledge.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.item_descriptions WHERE display_name='' OR description='')
       OR EXISTS (SELECT 1 FROM lorkhan_internal.knowledge_documents WHERE topic_desc_basic='' OR category='') THEN
        RAISE EXCEPTION 'Cannot restore required catalog fields while blank values are saved.';
    END IF;
END $$;

ALTER TABLE lorkhan_internal.item_descriptions
    DROP CONSTRAINT item_descriptions_display_name_check,
    DROP CONSTRAINT item_descriptions_description_check,
    ADD CONSTRAINT item_descriptions_display_name_check CHECK (length(display_name) BETWEEN 1 AND 256),
    ADD CONSTRAINT item_descriptions_description_check CHECK (length(description) BETWEEN 1 AND 8192);

ALTER TABLE lorkhan_internal.knowledge_documents
    DROP CONSTRAINT knowledge_basic_bytes,
    DROP CONSTRAINT knowledge_category_bytes,
    ADD CONSTRAINT knowledge_basic_bytes CHECK (octet_length(topic_desc_basic) BETWEEN 1 AND 131072),
    ADD CONSTRAINT knowledge_category_bytes CHECK (octet_length(category) BETWEEN 1 AND 128);
