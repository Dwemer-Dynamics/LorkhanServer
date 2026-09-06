-- Clearing the reader must not erase provider accounting or immutable turn history.
CREATE TABLE lorkhan_internal.request_log_hidden (
    provider_attempt_id uuid PRIMARY KEY REFERENCES lorkhan_internal.provider_attempts(provider_attempt_id) ON DELETE CASCADE,
    cleared_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
