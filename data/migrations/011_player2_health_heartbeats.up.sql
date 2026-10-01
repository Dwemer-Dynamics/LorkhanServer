-- Player2 attributes play from a once-a-minute GET /v1/health. One row per installation holds the last bounded attempt
-- so restarted background workers keep that interval; usage itself is derived from provider_attempts.
CREATE TABLE lorkhan_internal.player2_health_heartbeats (
    installation_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    configuration_id uuid NOT NULL,
    configuration_revision integer NOT NULL,
    attempted_at timestamp with time zone NOT NULL,
    http_status integer,
    succeeded_at timestamp with time zone,
    CONSTRAINT player2_health_heartbeats_pkey PRIMARY KEY (installation_id),
    CONSTRAINT player2_health_heartbeats_generation_check CHECK ((generation >= 0)),
    CONSTRAINT player2_health_heartbeats_revision_check CHECK ((configuration_revision > 0)),
    CONSTRAINT player2_health_heartbeats_http_status_check CHECK (((http_status IS NULL) OR ((http_status >= 0) AND (http_status <= 599))))
);
