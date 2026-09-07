-- Refuse to silently restore topics that the user explicitly deleted.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.oghma_catalog_deletions) THEN
        RAISE EXCEPTION 'Reset Oghma catalog deletions before reverting migration 091';
    END IF;
END $$;
DROP TABLE lorkhan_internal.oghma_catalog_deletions;
