ALTER TABLE lorkhan_internal.oghma_catalogs
    DROP CONSTRAINT oghma_catalogs_row_count_check;

ALTER TABLE lorkhan_internal.oghma_catalogs
    ADD CONSTRAINT oghma_catalogs_row_count_check CHECK (row_count BETWEEN 1 AND 2000);
