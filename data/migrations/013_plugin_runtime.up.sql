-- Client addon registrations belong to one live session generation; a new generation starts empty. Rows are revalidated
-- against plugin_packages on every access, so they never grant anything by themselves.
CREATE TABLE IF NOT EXISTS lorkhan_internal.plugin_registrations (
    session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id) ON DELETE CASCADE,
    generation bigint NOT NULL,
    plugin_id text NOT NULL,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    version text NOT NULL,
    manifest_sha256 character(64) NOT NULL,
    entry jsonb NOT NULL,
    registered_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT plugin_registrations_pkey PRIMARY KEY (session_id, generation, plugin_id),
    CONSTRAINT plugin_registrations_plugin_id_check CHECK (plugin_id ~ '^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]{1,47}$'),
    CONSTRAINT plugin_registrations_generation_check CHECK (generation >= 1),
    CONSTRAINT plugin_registrations_sha_check CHECK (manifest_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT plugin_registrations_entry_check CHECK (jsonb_typeof(entry) = 'object' AND entry->>'plugin_id' = plugin_id)
);
-- Immutable accepted client plugin events. Each row is written before its plugin.event.dispatch job.
CREATE TABLE IF NOT EXISTS lorkhan_internal.plugin_events (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    message_id uuid NOT NULL,
    session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id) ON DELETE CASCADE,
    generation bigint NOT NULL,
    plugin_id text NOT NULL,
    plugin_version text NOT NULL,
    event text NOT NULL,
    message jsonb NOT NULL,
    observed_at timestamp with time zone NOT NULL,
    received_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT plugin_events_pkey PRIMARY KEY (installation_id, message_id),
    CONSTRAINT plugin_events_plugin_id_check CHECK (plugin_id ~ '^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]{1,47}$'),
    CONSTRAINT plugin_events_event_check CHECK (event ~ '^[a-z][a-z0-9_]{0,31}$'),
    CONSTRAINT plugin_events_generation_check CHECK (generation >= 1),
    CONSTRAINT plugin_events_message_check CHECK (jsonb_typeof(message) = 'object' AND octet_length(message::text) <= 32768)
);
CREATE INDEX IF NOT EXISTS plugin_events_session_idx ON lorkhan_internal.plugin_events (session_id, generation, received_at);
CREATE OR REPLACE FUNCTION lorkhan_internal.reject_plugin_event_update()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'plugin_events rows are immutable';
END;
$$;
CREATE OR REPLACE TRIGGER plugin_events_immutable
BEFORE UPDATE ON lorkhan_internal.plugin_events
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.reject_plugin_event_update();
