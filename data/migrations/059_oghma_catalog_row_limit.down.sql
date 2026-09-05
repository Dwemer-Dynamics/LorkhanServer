DO $body$
BEGIN
    IF EXISTS (
        SELECT 1 FROM lorkhan_internal.oghma_catalogs WHERE row_count > 2000
    ) THEN
        RAISE EXCEPTION 'Cannot restore the 2,000-row Oghma limit while a larger catalog exists.';
    END IF;
END
$body$;

ALTER TABLE lorkhan_internal.oghma_catalogs
    DROP CONSTRAINT oghma_catalogs_row_count_check;

ALTER TABLE lorkhan_internal.oghma_catalogs
    ADD CONSTRAINT oghma_catalogs_row_count_check CHECK (row_count BETWEEN 1 AND 2000);
