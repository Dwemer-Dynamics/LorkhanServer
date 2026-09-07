DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.oghma_dynamic)
       OR EXISTS (SELECT 1 FROM lorkhan_internal.knowledge_documents WHERE content='') THEN
        RAISE EXCEPTION 'Export and remove Dynamic Oghma records before reverting migration 092';
    END IF;
END $$;
ALTER TABLE lorkhan_internal.knowledge_documents
    DROP CONSTRAINT knowledge_documents_content_check,
    ADD CONSTRAINT knowledge_documents_content_check CHECK (octet_length(content) BETWEEN 1 AND 131072);
DROP TABLE lorkhan_internal.oghma_dynamic_applications;
DROP TABLE lorkhan_internal.oghma_dynamic;
