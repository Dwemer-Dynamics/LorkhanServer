DO $body$
BEGIN
    IF EXISTS (
        SELECT 1 FROM lorkhan_internal.biography_catalogs WHERE row_count > 10000
    ) THEN
        RAISE EXCEPTION 'Cannot restore the 10,000-row biography limit while a larger catalog exists.';
    END IF;
END
$body$;

ALTER TABLE lorkhan_internal.biography_catalogs
    DROP CONSTRAINT biography_catalogs_row_count_check;

ALTER TABLE lorkhan_internal.biography_catalogs
    ADD CONSTRAINT biography_catalogs_row_count_check CHECK (row_count BETWEEN 0 AND 10000);
