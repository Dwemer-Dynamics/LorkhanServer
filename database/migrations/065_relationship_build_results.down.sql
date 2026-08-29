DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM almsivi_internal.relationship_build_results)
        OR EXISTS (SELECT 1 FROM almsivi_internal.durable_jobs WHERE job_type='relationship.build') THEN
        RAISE EXCEPTION 'Cannot remove relationship build receipts or queued work.';
    END IF;
END $$;
DROP INDEX almsivi_internal.relationship_build_scope_order;
DROP TABLE almsivi_internal.relationship_build_results;
