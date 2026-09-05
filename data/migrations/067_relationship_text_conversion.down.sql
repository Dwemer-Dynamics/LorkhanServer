DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.relationship_conversion_results)
        OR EXISTS (SELECT 1 FROM lorkhan_internal.durable_jobs WHERE job_type='relationship.convert') THEN
        RAISE EXCEPTION 'Cannot remove relationship conversion receipts or queued work.';
    END IF;
END $$;
DROP INDEX lorkhan_internal.relationship_conversion_batch_order;
DROP TABLE lorkhan_internal.relationship_conversion_results;
