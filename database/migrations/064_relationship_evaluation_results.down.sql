DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM almsivi_internal.relationship_evaluation_results)
        OR EXISTS (SELECT 1 FROM almsivi_internal.durable_jobs WHERE job_type='relationship.evaluate') THEN
        RAISE EXCEPTION 'Cannot remove relationship evaluation receipts or queued work.';
    END IF;
END $$;
DROP TABLE almsivi_internal.relationship_evaluation_results;
DROP INDEX almsivi_internal.relationship_identity_scope;
CREATE INDEX relationship_identity_scope ON almsivi_internal.relationship_records
    (installation_id,profile_id,playthrough_id,md5(almsivi_internal.relationship_identity_key(actor_identity)::text))
    WHERE deleted_at IS NULL;
