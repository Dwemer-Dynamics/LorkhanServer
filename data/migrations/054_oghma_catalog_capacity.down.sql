DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.oghma_catalogs WHERE row_count > 1000) THEN
        RAISE EXCEPTION 'cannot restore the 1000-row Oghma limit while larger catalogs exist';
    END IF;
END
$$;

ALTER TABLE lorkhan_internal.oghma_catalogs
    DROP CONSTRAINT oghma_catalogs_row_count_check;

ALTER TABLE lorkhan_internal.oghma_catalogs
    ADD CONSTRAINT oghma_catalogs_row_count_check CHECK (row_count BETWEEN 1 AND 1000);
