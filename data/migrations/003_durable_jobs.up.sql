CREATE TABLE durable_jobs (
    job_id uuid PRIMARY KEY,
    job_type text NOT NULL CHECK (job_type ~ '^[a-z][a-z0-9_.-]{0,127}$'),
    schema_version integer NOT NULL CHECK (schema_version > 0),
    idempotency_key text NOT NULL CHECK (octet_length(idempotency_key) BETWEEN 1 AND 512),
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
    state text NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'leased', 'succeeded', 'dead')),
    priority smallint NOT NULL DEFAULT 0,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    max_attempts integer NOT NULL DEFAULT 5 CHECK (max_attempts BETWEEN 1 AND 100),
    next_run_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    lease_owner text,
    lease_token uuid,
    leased_at timestamptz,
    lease_expires_at timestamptz,
    heartbeat_at timestamptz,
    last_error_code text,
    last_error_detail text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    completed_at timestamptz,
    UNIQUE (job_type, idempotency_key),
    CHECK (
        (state = 'leased' AND lease_owner IS NOT NULL AND lease_token IS NOT NULL
            AND leased_at IS NOT NULL AND lease_expires_at IS NOT NULL AND heartbeat_at IS NOT NULL)
        OR
        (state <> 'leased' AND lease_owner IS NULL AND lease_token IS NULL
            AND leased_at IS NULL AND lease_expires_at IS NULL AND heartbeat_at IS NULL)
    )
);
CREATE INDEX durable_jobs_claim_order ON durable_jobs (priority DESC, next_run_at, created_at, job_id)
    WHERE state IN ('queued', 'leased');

CREATE TABLE durable_job_attempts (
    attempt_id bigserial PRIMARY KEY,
    job_id uuid NOT NULL REFERENCES durable_jobs(job_id) ON DELETE CASCADE,
    attempt_number integer NOT NULL CHECK (attempt_number > 0),
    lease_token uuid NOT NULL UNIQUE,
    worker_id text NOT NULL,
    started_at timestamptz NOT NULL,
    heartbeat_at timestamptz NOT NULL,
    finished_at timestamptz,
    outcome text CHECK (outcome IN ('succeeded', 'retry', 'dead', 'lease_expired')),
    error_code text,
    error_detail text,
    UNIQUE (job_id, attempt_number),
    CHECK ((finished_at IS NULL AND outcome IS NULL) OR (finished_at IS NOT NULL AND outcome IS NOT NULL))
);
CREATE INDEX durable_job_attempts_job_order ON durable_job_attempts (job_id, attempt_number DESC);

CREATE TABLE durable_job_dead_letters (
    dead_letter_id bigserial PRIMARY KEY,
    job_id uuid NOT NULL REFERENCES durable_jobs(job_id) ON DELETE CASCADE,
    failed_attempt_number integer NOT NULL CHECK (failed_attempt_number > 0),
    error_code text NOT NULL,
    error_detail text,
    dead_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    replayed_at timestamptz,
    replay_job_id uuid REFERENCES durable_jobs(job_id),
    UNIQUE (job_id),
    CHECK ((replayed_at IS NULL AND replay_job_id IS NULL) OR (replayed_at IS NOT NULL AND replay_job_id IS NOT NULL))
);

CREATE TABLE provider_attempts (
    provider_attempt_id uuid PRIMARY KEY,
    provider_kind text NOT NULL CHECK (provider_kind IN ('llm', 'stt', 'tts')),
    provider_name text NOT NULL,
    operation text NOT NULL,
    request_id uuid,
    turn_id uuid REFERENCES turns(turn_id),
    job_id uuid REFERENCES durable_jobs(job_id),
    attempt_number integer NOT NULL CHECK (attempt_number > 0),
    state text NOT NULL CHECK (state IN ('started', 'succeeded', 'failed', 'cancelled')),
    model text,
    config_revision text,
    input_bytes integer CHECK (input_bytes IS NULL OR input_bytes >= 0),
    output_bytes integer CHECK (output_bytes IS NULL OR output_bytes >= 0),
    started_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    finished_at timestamptz,
    duration_ms integer CHECK (duration_ms IS NULL OR duration_ms >= 0),
    error_code text,
    error_detail text,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(metadata) = 'object'),
    CHECK ((state = 'started' AND finished_at IS NULL) OR (state <> 'started' AND finished_at IS NOT NULL)),
    UNIQUE (provider_kind, provider_name, operation, request_id, attempt_number)
);
CREATE INDEX provider_attempts_request_order ON provider_attempts (request_id, started_at, provider_attempt_id);
CREATE INDEX provider_attempts_job_order ON provider_attempts (job_id, started_at, provider_attempt_id) WHERE job_id IS NOT NULL;
