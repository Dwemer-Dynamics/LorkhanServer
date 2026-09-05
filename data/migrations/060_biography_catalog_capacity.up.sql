ALTER TABLE lorkhan_internal.biography_catalogs
    DROP CONSTRAINT biography_catalogs_row_count_check;

ALTER TABLE lorkhan_internal.biography_catalogs
    ADD CONSTRAINT biography_catalogs_row_count_check CHECK (row_count BETWEEN 0 AND 20000);
