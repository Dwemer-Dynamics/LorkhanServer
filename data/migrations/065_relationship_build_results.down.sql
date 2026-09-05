DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.relationship_build_results)
        OR EXISTS (SELECT 1 FROM lorkhan_internal.durable_jobs WHERE job_type='relationship.build') THEN
        RAISE EXCEPTION 'Cannot remove relationship build receipts or queued work.';
    END IF;
END $$;
DROP INDEX lorkhan_internal.relationship_build_scope_order;
DROP TABLE lorkhan_internal.relationship_build_results;
