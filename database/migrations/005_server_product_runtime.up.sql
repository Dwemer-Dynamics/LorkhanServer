CREATE TEMP TABLE migration_005_profile_map ON COMMIT DROP AS
WITH legacy AS (
    SELECT s.installation_id, s.profile_id AS legacy_profile_id,
        min(s.created_at) AS created_at,
        row_number() OVER (PARTITION BY s.profile_id ORDER BY s.installation_id) AS owner_rank
    FROM sessions s
    GROUP BY s.installation_id, s.profile_id
)
SELECT l.*,
    CASE
        WHEN p.installation_id = l.installation_id THEN l.legacy_profile_id
        WHEN p.profile_id IS NULL AND l.owner_rank = 1 THEN l.legacy_profile_id
        ELSE md5('almsivi:legacy-profile:v1:' || l.installation_id::text || ':' || l.legacy_profile_id::text)::uuid
    END AS profile_id
FROM legacy l
LEFT JOIN profiles p ON p.profile_id = l.legacy_profile_id;

INSERT INTO profiles (profile_id, installation_id, name, actor_identity, current_revision, created_at)
SELECT m.profile_id, m.installation_id, 'Legacy profile ' || m.profile_id::text,
    jsonb_build_object('source', 'session_backfill'), 1, m.created_at
FROM migration_005_profile_map m
LEFT JOIN profiles p ON p.profile_id = m.profile_id
WHERE p.profile_id IS NULL;

INSERT INTO profile_revisions (profile_id, revision, content, change_reason, created_at)
SELECT m.profile_id, 1,
    jsonb_build_object('source', 'session_backfill', 'legacy_profile_id', m.legacy_profile_id,
        'installation_id', m.installation_id),
    '005 session ownership backfill', m.created_at
FROM migration_005_profile_map m
ON CONFLICT (profile_id, revision) DO NOTHING;

CREATE TEMP TABLE migration_005_playthrough_map ON COMMIT DROP AS
WITH legacy AS (
    SELECT s.installation_id, s.playthrough_id AS legacy_playthrough_id, m.profile_id,
        min(s.created_at) AS created_at, min(s.content_fingerprint) AS content_fingerprint,
        row_number() OVER (PARTITION BY s.playthrough_id ORDER BY s.installation_id, m.profile_id) AS owner_rank
    FROM sessions s
    JOIN migration_005_profile_map m
      ON m.installation_id = s.installation_id AND m.legacy_profile_id = s.profile_id
    GROUP BY s.installation_id, s.playthrough_id, m.profile_id
)
SELECT l.*,
    CASE
        WHEN p.installation_id = l.installation_id AND p.profile_id = l.profile_id
            THEN l.legacy_playthrough_id
        WHEN p.playthrough_id IS NULL AND l.owner_rank = 1 THEN l.legacy_playthrough_id
        ELSE md5('almsivi:legacy-playthrough:v1:' || l.installation_id::text || ':' ||
            l.profile_id::text || ':' || l.legacy_playthrough_id::text)::uuid
    END AS playthrough_id
FROM legacy l
LEFT JOIN playthroughs p ON p.playthrough_id = l.legacy_playthrough_id;

INSERT INTO playthroughs
    (playthrough_id, installation_id, profile_id, name, content_fingerprint, current_revision, created_at)
SELECT m.playthrough_id, m.installation_id, m.profile_id,
    'Legacy playthrough ' || m.playthrough_id::text, m.content_fingerprint, 1, m.created_at
FROM migration_005_playthrough_map m
LEFT JOIN playthroughs p ON p.playthrough_id = m.playthrough_id
WHERE p.playthrough_id IS NULL;

INSERT INTO playthrough_revisions (playthrough_id, revision, content, change_reason, created_at)
SELECT m.playthrough_id, 1,
    jsonb_build_object('source', 'session_backfill', 'legacy_playthrough_id', m.legacy_playthrough_id,
        'installation_id', m.installation_id, 'profile_id', m.profile_id),
    '005 session ownership backfill', m.created_at
FROM migration_005_playthrough_map m
ON CONFLICT (playthrough_id, revision) DO NOTHING;

UPDATE sessions s
SET profile_id = pm.profile_id, playthrough_id = wm.playthrough_id
FROM migration_005_profile_map pm, migration_005_playthrough_map wm
WHERE pm.installation_id = s.installation_id
  AND pm.legacy_profile_id = s.profile_id
  AND wm.installation_id = s.installation_id
  AND wm.legacy_playthrough_id = s.playthrough_id
  AND wm.profile_id = pm.profile_id;

ALTER TABLE profiles ADD CONSTRAINT profiles_id_installation_unique
    UNIQUE (profile_id, installation_id);
ALTER TABLE playthroughs ADD CONSTRAINT playthroughs_id_installation_profile_unique
    UNIQUE (playthrough_id, installation_id, profile_id);
ALTER TABLE playthroughs ADD CONSTRAINT playthroughs_profile_installation_fk
    FOREIGN KEY (profile_id, installation_id) REFERENCES profiles(profile_id, installation_id);
ALTER TABLE sessions ADD CONSTRAINT sessions_profile_installation_fk
    FOREIGN KEY (profile_id, installation_id) REFERENCES profiles(profile_id, installation_id);
ALTER TABLE sessions ADD CONSTRAINT sessions_playthrough_installation_profile_fk
    FOREIGN KEY (playthrough_id, installation_id, profile_id)
    REFERENCES playthroughs(playthrough_id, installation_id, profile_id);

CREATE TABLE prompt_traces (
    prompt_trace_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    session_id uuid REFERENCES sessions(session_id) ON DELETE SET NULL,
    turn_id uuid REFERENCES turns(turn_id) ON DELETE SET NULL,
    request_id uuid,
    prompt_configuration_id uuid REFERENCES configuration_sets(configuration_id),
    prompt_revision integer CHECK (prompt_revision IS NULL OR prompt_revision > 0),
    algorithm text NOT NULL CHECK (algorithm = 'deterministic-prompt-v1'),
    input_sha256 char(64) NOT NULL CHECK (input_sha256 ~ '^[0-9a-f]{64}$'),
    input_bytes integer NOT NULL CHECK (input_bytes BETWEEN 1 AND 131072),
    truncated boolean NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (turn_id),
    FOREIGN KEY (profile_id, installation_id) REFERENCES profiles(profile_id, installation_id),
    FOREIGN KEY (playthrough_id, installation_id, profile_id)
        REFERENCES playthroughs(playthrough_id, installation_id, profile_id)
);
CREATE TABLE prompt_trace_sources (
    prompt_trace_id uuid NOT NULL REFERENCES prompt_traces(prompt_trace_id) ON DELETE CASCADE,
    ordinal integer NOT NULL CHECK (ordinal BETWEEN 0 AND 1023),
    source_kind text NOT NULL CHECK (source_kind IN (
        'profile','prompt','turn','memory','relationship','knowledge','narrative','action_result','action_catalog'
    )),
    source_id text NOT NULL CHECK (octet_length(source_id) BETWEEN 1 AND 256),
    included boolean NOT NULL,
    reason text NOT NULL CHECK (reason IN ('included','section_limit','byte_limit','expired','deleted','policy_disabled')),
    source_sha256 char(64) NOT NULL CHECK (source_sha256 ~ '^[0-9a-f]{64}$'),
    included_bytes integer NOT NULL CHECK (included_bytes BETWEEN 0 AND 131072),
    redacted_preview text NOT NULL CHECK (octet_length(redacted_preview) <= 256),
    PRIMARY KEY (prompt_trace_id, ordinal)
);

ALTER TABLE memory_records ADD COLUMN derivation_key text;
CREATE UNIQUE INDEX memory_records_derivation_key_unique
    ON memory_records (installation_id, profile_id, playthrough_id, derivation_key)
    WHERE derivation_key IS NOT NULL AND deleted_at IS NULL;
ALTER TABLE narrative_records ADD COLUMN derivation_key text;
CREATE UNIQUE INDEX narrative_records_derivation_key_unique
    ON narrative_records (installation_id, profile_id, playthrough_id, kind, derivation_key)
    WHERE derivation_key IS NOT NULL AND deleted_at IS NULL;

ALTER TABLE action_catalog ADD COLUMN result_schema jsonb NOT NULL
    DEFAULT '{"type":"object","additionalProperties":false}'::jsonb
    CHECK (jsonb_typeof(result_schema) = 'object');
ALTER TABLE action_catalog ADD COLUMN max_parameter_bytes integer NOT NULL DEFAULT 16384
    CHECK (max_parameter_bytes BETWEEN 2 AND 65536);
ALTER TABLE action_catalog ADD COLUMN terminal_result_required boolean NOT NULL DEFAULT true;
ALTER TABLE action_catalog ADD COLUMN continuation_capable boolean NOT NULL DEFAULT false;
ALTER TABLE action_delivery ADD CONSTRAINT action_delivery_single_continuation
    CHECK ((continuation_state = 'consumed' AND continuation_turn_id IS NOT NULL)
        OR (continuation_state <> 'consumed' AND continuation_turn_id IS NULL));
