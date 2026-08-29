-- Keep one receipt for the complete, atomic set of manual history updates.
CREATE TABLE almsivi_internal.relationship_build_results (
    job_id uuid PRIMARY KEY REFERENCES almsivi_internal.durable_jobs(job_id),
    source_count integer NOT NULL CHECK (source_count BETWEEN 1 AND 100),
    target_count integer NOT NULL CHECK (target_count BETWEEN 1 AND 20),
    changed_count integer NOT NULL CHECK (changed_count BETWEEN 0 AND 20),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
-- The audit page reads ten jobs for one scope without scanning unrelated worker history.
CREATE INDEX relationship_build_scope_order ON almsivi_internal.durable_jobs
    ((payload->>'installation_id'),(payload->>'profile_id'),(payload->>'playthrough_id'),created_at DESC,job_id DESC)
    WHERE job_type='relationship.build';
