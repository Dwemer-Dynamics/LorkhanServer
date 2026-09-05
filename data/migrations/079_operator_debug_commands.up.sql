CREATE TABLE lorkhan_internal.debug_commands (
    command_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
    session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id),
    generation bigint NOT NULL CHECK (generation > 0),
    command_name text NOT NULL CHECK (command_name IN (
        'status.snapshot','god_mode.set','collision.set','ai.set','mwscript.set',
        'render_mode.toggle','shaders.reload','shader_hot_reload.set'
    )),
    parameters jsonb NOT NULL,
    state text NOT NULL DEFAULT 'queued' CHECK (state IN (
        'queued','delivered','succeeded','failed','rejected','expired'
    )),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    expires_at timestamptz NOT NULL,
    delivered_at timestamptz,
    completed_at timestamptz,
    result_message_id uuid UNIQUE,
    reason_code text,
    observed jsonb,
    CHECK (jsonb_typeof(parameters) = 'object'),
    CHECK (observed IS NULL OR jsonb_typeof(observed) = 'object'),
    CHECK (expires_at > created_at)
);

CREATE INDEX debug_commands_session_queue
    ON lorkhan_internal.debug_commands (session_id, generation, state, created_at);
