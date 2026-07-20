ALTER TABLE installations ADD COLUMN display_name text NOT NULL DEFAULT 'Local installation';
ALTER TABLE installations ADD COLUMN revoked_at timestamptz;

CREATE TABLE pairing_tokens (
    pairing_token_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    token_hash char(64) NOT NULL UNIQUE CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    state text NOT NULL CHECK (state IN ('active', 'overlap', 'revoked')),
    valid_until timestamptz,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    revoked_at timestamptz,
    CHECK ((state = 'overlap' AND valid_until IS NOT NULL) OR state <> 'overlap')
);
CREATE UNIQUE INDEX pairing_tokens_one_active ON pairing_tokens (installation_id) WHERE state = 'active';

CREATE TABLE browser_sessions (
    session_hash char(64) PRIMARY KEY CHECK (session_hash ~ '^[0-9a-f]{64}$'),
    csrf_hash char(64) NOT NULL CHECK (csrf_hash ~ '^[0-9a-f]{64}$'),
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    last_seen_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    revoked_at timestamptz
);

CREATE TABLE profiles (
    profile_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    name text NOT NULL CHECK (octet_length(name) BETWEEN 1 AND 256),
    actor_identity jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(actor_identity) = 'object'),
    current_revision integer NOT NULL DEFAULT 1 CHECK (current_revision > 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz,
    UNIQUE (installation_id, name)
);
CREATE TABLE profile_revisions (
    profile_id uuid NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision > 0),
    content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
    change_reason text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (profile_id, revision)
);

CREATE TABLE playthroughs (
    playthrough_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES profiles(profile_id),
    name text NOT NULL CHECK (octet_length(name) BETWEEN 1 AND 256),
    content_fingerprint varchar(71) CHECK (content_fingerprint IS NULL OR content_fingerprint ~ '^sha256:[0-9a-f]{64}$'),
    current_revision integer NOT NULL DEFAULT 1 CHECK (current_revision > 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz,
    UNIQUE (installation_id, name)
);
CREATE TABLE playthrough_revisions (
    playthrough_id uuid NOT NULL REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision > 0),
    content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
    change_reason text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (playthrough_id, revision)
);

CREATE TABLE configuration_sets (
    configuration_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES profiles(profile_id) ON DELETE CASCADE,
    kind text NOT NULL CHECK (kind IN ('prompt', 'provider', 'action_policy')),
    name text NOT NULL CHECK (octet_length(name) BETWEEN 1 AND 128),
    current_revision integer NOT NULL DEFAULT 1 CHECK (current_revision > 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz,
    UNIQUE NULLS NOT DISTINCT (installation_id, profile_id, kind, name)
);
CREATE TABLE configuration_revisions (
    configuration_id uuid NOT NULL REFERENCES configuration_sets(configuration_id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision > 0),
    content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
    change_reason text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (configuration_id, revision)
);

CREATE TABLE memory_records (
    memory_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    tier text NOT NULL CHECK (tier IN ('recent', 'mid', 'long')),
    content text NOT NULL CHECK (octet_length(content) BETWEEN 1 AND 16384),
    lexical_terms text[] NOT NULL,
    fake_vector jsonb NOT NULL CHECK (jsonb_typeof(fake_vector) = 'array'),
    source_event_id uuid REFERENCES source_events(source_event_id),
    provenance jsonb NOT NULL CHECK (jsonb_typeof(provenance) = 'object'),
    occurred_at timestamptz NOT NULL,
    expires_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz
);
CREATE INDEX memory_scope_tier ON memory_records (installation_id, profile_id, playthrough_id, tier, occurred_at DESC) WHERE deleted_at IS NULL;

CREATE TABLE relationship_records (
    relationship_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    actor_identity jsonb NOT NULL CHECK (jsonb_typeof(actor_identity) = 'object'),
    disposition integer NOT NULL CHECK (disposition BETWEEN -100 AND 100),
    affinity integer NOT NULL CHECK (affinity BETWEEN -100 AND 100),
    source_mode text NOT NULL CHECK (source_mode IN ('derived', 'manual')),
    source_event_id uuid REFERENCES source_events(source_event_id),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz
);
CREATE TABLE relationship_audit (
    audit_id uuid PRIMARY KEY,
    relationship_id uuid NOT NULL REFERENCES relationship_records(relationship_id) ON DELETE CASCADE,
    mode text NOT NULL CHECK (mode IN ('derived', 'manual')),
    before_value jsonb,
    after_value jsonb NOT NULL,
    reason text NOT NULL,
    source_event_id uuid REFERENCES source_events(source_event_id),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX relationship_scope ON relationship_records (installation_id, profile_id, playthrough_id) WHERE deleted_at IS NULL;

CREATE TABLE knowledge_documents (
    document_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES profiles(profile_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    title text NOT NULL CHECK (octet_length(title) BETWEEN 1 AND 256),
    content text NOT NULL CHECK (octet_length(content) BETWEEN 1 AND 131072),
    content_sha256 char(64) NOT NULL CHECK (content_sha256 ~ '^[0-9a-f]{64}$'),
    lexical_terms text[] NOT NULL,
    provenance jsonb NOT NULL CHECK (jsonb_typeof(provenance) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz
);
CREATE INDEX knowledge_scope ON knowledge_documents (installation_id, profile_id, playthrough_id) WHERE deleted_at IS NULL;
CREATE TABLE retrieval_traces (
    retrieval_trace_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES profiles(profile_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    domain text NOT NULL CHECK (domain IN ('memory', 'knowledge')),
    query text NOT NULL CHECK (octet_length(query) BETWEEN 1 AND 4096),
    result_ids uuid[] NOT NULL,
    scores jsonb NOT NULL CHECK (jsonb_typeof(scores) = 'object'),
    algorithm text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE TABLE narrative_records (
    narrative_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    kind text NOT NULL CHECK (kind IN ('narrator', 'diary', 'summary')),
    title text NOT NULL CHECK (octet_length(title) BETWEEN 1 AND 256),
    content text NOT NULL CHECK (octet_length(content) BETWEEN 1 AND 65536),
    provenance jsonb NOT NULL CHECK (jsonb_typeof(provenance) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz
);
CREATE INDEX narrative_scope ON narrative_records (installation_id, profile_id, playthrough_id, kind) WHERE deleted_at IS NULL;

CREATE TABLE autonomy_schedules (
    schedule_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES profiles(profile_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    kind text NOT NULL CHECK (kind IN ('rechat', 'boredom', 'greeting')),
    enabled boolean NOT NULL DEFAULT false,
    interval_seconds integer NOT NULL CHECK (interval_seconds BETWEEN 30 AND 86400),
    cooldown_seconds integer NOT NULL CHECK (cooldown_seconds BETWEEN 30 AND 86400),
    last_triggered_at timestamptz,
    current_session_id uuid REFERENCES sessions(session_id),
    confirmed_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (installation_id, profile_id, playthrough_id, kind)
);

CREATE TABLE action_catalog (
    action_name text PRIMARY KEY,
    tier integer NOT NULL CHECK (tier BETWEEN 0 AND 3),
    description text NOT NULL,
    parameter_schema jsonb NOT NULL CHECK (jsonb_typeof(parameter_schema) = 'object'),
    client_capability text NOT NULL,
    server_owned boolean NOT NULL DEFAULT true,
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
INSERT INTO action_catalog (action_name, tier, description, parameter_schema, client_capability) VALUES
('inspect.report', 0, 'Read-only client observation report.', '{"type":"object","additionalProperties":false}'::jsonb, 'action.inspect.report'),
('ai.follow', 1, 'Ask one actor to follow the player at the exact negotiated distance.', '{"type":"object","properties":{"distance":{"const":192}},"required":["distance"],"additionalProperties":false}'::jsonb, 'action.ai.follow');
CREATE TABLE action_delivery (
    action_id uuid PRIMARY KEY REFERENCES action_intents(action_id) ON DELETE CASCADE,
    delivered_at timestamptz,
    terminal_at timestamptz,
    continuation_state text NOT NULL DEFAULT 'none' CHECK (continuation_state IN ('none', 'eligible', 'consumed', 'expired')),
    continuation_turn_id uuid REFERENCES turns(turn_id),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE TABLE operational_audit (
    audit_id uuid PRIMARY KEY,
    category text NOT NULL,
    action text NOT NULL,
    scope jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(scope) = 'object'),
    detail jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(detail) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE backup_records (
    backup_id uuid PRIMARY KEY,
    format_version integer NOT NULL DEFAULT 1,
    content_sha256 char(64) NOT NULL CHECK (content_sha256 ~ '^[0-9a-f]{64}$'),
    byte_count integer NOT NULL CHECK (byte_count > 0),
    scope jsonb NOT NULL CHECK (jsonb_typeof(scope) = 'object'),
    state text NOT NULL CHECK (state IN ('created', 'restored', 'failed')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    restored_at timestamptz
);
