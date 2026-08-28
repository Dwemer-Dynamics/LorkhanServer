-- A receipt survives worker retries and prevents the same response applying score changes twice.
CREATE TABLE almsivi_internal.relationship_evaluation_results (
    job_id uuid PRIMARY KEY REFERENCES almsivi_internal.durable_jobs(job_id),
    source_event_id uuid NOT NULL REFERENCES almsivi_internal.source_events(source_event_id),
    relationship_id uuid REFERENCES almsivi_internal.relationship_records(relationship_id),
    disposition_delta integer NOT NULL CHECK (disposition_delta BETWEEN -10 AND 10),
    affinity_delta integer NOT NULL CHECK (affinity_delta BETWEEN -10 AND 10),
    reason text NOT NULL CHECK (octet_length(reason) BETWEEN 1 AND 1024),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

-- Include tombstones so a create/delete during provider I/O also invalidates an absent-row snapshot.
DROP INDEX almsivi_internal.relationship_identity_scope;
CREATE INDEX relationship_identity_scope ON almsivi_internal.relationship_records
    (installation_id,profile_id,playthrough_id,md5(almsivi_internal.relationship_identity_key(actor_identity)::text));
