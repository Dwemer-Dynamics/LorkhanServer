CREATE TABLE lorkhan_internal.playthrough_saves (
    save_id uuid PRIMARY KEY,
    -- Capture runs beside a session transaction holding the installation row lock.
    -- Recovery metadata must not acquire a parent-key lock on that connection.
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    name text NOT NULL CHECK (length(name) BETWEEN 1 AND 128),
    notes text NOT NULL DEFAULT '',
    kind text NOT NULL CHECK (kind IN ('manual','default','dragon_break','before_copy')),
    capture_key text,
    document text NOT NULL,
    metadata jsonb NOT NULL DEFAULT '{}',
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (installation_id, capture_key)
);
CREATE INDEX playthrough_saves_scope ON lorkhan_internal.playthrough_saves(installation_id,created_at DESC);
COMMENT ON TABLE lorkhan_internal.playthrough_saves IS 'Operational recovery storage; excluded from gameplay exports and rollback.';
