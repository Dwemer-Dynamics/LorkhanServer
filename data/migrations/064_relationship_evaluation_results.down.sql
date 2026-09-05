DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.relationship_evaluation_results)
        OR EXISTS (SELECT 1 FROM lorkhan_internal.durable_jobs WHERE job_type='relationship.evaluate') THEN
        RAISE EXCEPTION 'Cannot remove relationship evaluation receipts or queued work.';
    END IF;
END $$;
DROP TABLE lorkhan_internal.relationship_evaluation_results;
DROP INDEX lorkhan_internal.relationship_identity_scope;
CREATE INDEX relationship_identity_scope ON lorkhan_internal.relationship_records
    (installation_id,profile_id,playthrough_id,md5(lorkhan_internal.relationship_identity_key(actor_identity)::text))
    WHERE deleted_at IS NULL;
