CREATE TABLE lorkhan_internal.npc_evolution_reports (
    job_id uuid PRIMARY KEY REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE,
    base_revision integer NOT NULL CHECK (base_revision > 0),
    npc_name text NOT NULL,
    history jsonb NOT NULL CHECK (jsonb_typeof(history) = 'array' AND octet_length(history::text) <= 100000),
    report text CHECK (octet_length(report) BETWEEN 1 AND 8192),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX npc_evolution_reports_profile ON lorkhan_internal.npc_evolution_reports(profile_id,created_at);
