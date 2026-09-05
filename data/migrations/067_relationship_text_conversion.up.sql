-- One receipt per owner makes explicit bulk conversion retry-safe and auditable.
CREATE TABLE lorkhan_internal.relationship_conversion_results (
    job_id uuid PRIMARY KEY REFERENCES lorkhan_internal.durable_jobs(job_id),
    batch_request_id uuid NOT NULL,
    owner_profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles(profile_id),
    source_bytes integer NOT NULL CHECK (source_bytes BETWEEN 1 AND 32768),
    target_count integer NOT NULL CHECK (target_count BETWEEN 1 AND 20),
    changed_count integer NOT NULL CHECK (changed_count BETWEEN 0 AND 20),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX relationship_conversion_batch_order ON lorkhan_internal.durable_jobs
    ((payload->>'installation_id'),(payload->>'playthrough_id'),(payload->>'request_id'),created_at,job_id)
    WHERE job_type='relationship.convert';
