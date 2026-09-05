-- CHIM-compatible roleplay history, prompt overrides, response queue, and rechat state.
-- Existing typed protocol tables remain the transport/audit authority during the cutover.
ALTER TABLE prompt_traces DROP CONSTRAINT prompt_traces_algorithm_check;
ALTER TABLE prompt_traces ADD CONSTRAINT prompt_traces_algorithm_check
    CHECK (algorithm IN ('deterministic-prompt-v1', 'chim-roleplay-prompt-v1'));
ALTER TABLE prompt_trace_sources DROP CONSTRAINT prompt_trace_sources_source_kind_check;
ALTER TABLE prompt_trace_sources ADD CONSTRAINT prompt_trace_sources_source_kind_check
    CHECK (source_kind IN (
        'profile','core_profile','prompt','history','turn','memory','relationship','knowledge','narrative','action_result','action_catalog'
    ));

CREATE TABLE eventlog (
    rowid bigserial PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES profiles(profile_id) ON DELETE SET NULL,
    session_id uuid REFERENCES sessions(session_id) ON DELETE SET NULL,
    source_event_id uuid UNIQUE REFERENCES source_events(source_event_id) ON DELETE SET NULL,
    request_id uuid,
    turn_id uuid,
    type varchar(128) NOT NULL,
    data text NOT NULL DEFAULT '',
    sess varchar(1024),
    gamets bigint NOT NULL DEFAULT 0,
    localts bigint NOT NULL,
    ts bigint,
    people text,
    location text,
    party text,
    utterance_id text,
    delivery_state text CHECK (delivery_state IS NULL OR delivery_state IN ('emitted','pending','spoken','played','failed','expired','interrupted')),
    speaker jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(speaker) = 'object'),
    target jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(target) = 'object'),
    audience jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(audience) = 'array'),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX eventlog_playthrough_order ON eventlog (installation_id, playthrough_id, gamets, ts, rowid);
CREATE INDEX eventlog_turn_order ON eventlog (turn_id, rowid) WHERE turn_id IS NOT NULL;
CREATE INDEX eventlog_type_order ON eventlog (installation_id, type, rowid DESC);
CREATE INDEX eventlog_utterance ON eventlog (utterance_id) WHERE utterance_id IS NOT NULL;

CREATE VIEW eventlog_view AS
SELECT e.*,
       to_timestamp(e.localts) AT TIME ZONE 'UTC' AS local_datetime,
       CASE WHEN e.ts IS NULL THEN NULL ELSE to_timestamp(e.ts / 1000.0) AT TIME ZONE 'UTC' END AS event_datetime
FROM eventlog e;

CREATE TABLE speech (
    rowid bigserial PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    session_id uuid REFERENCES sessions(session_id) ON DELETE SET NULL,
    turn_id uuid,
    dialogue_message_id uuid UNIQUE REFERENCES dialogue_utterances(dialogue_message_id) ON DELETE SET NULL,
    sess varchar(1024),
    speaker text,
    speech text NOT NULL,
    location text,
    listener text,
    topic text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL DEFAULT 0,
    ts bigint,
    companions text,
    audios text,
    utterance_id text,
    speaker_identity jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(speaker_identity) = 'object'),
    listener_identity jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(listener_identity) = 'object'),
    audience jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(audience) = 'array'),
    delivery_state text NOT NULL DEFAULT 'emitted' CHECK (delivery_state IN ('emitted','pending','spoken','played','failed','expired','interrupted')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX speech_playthrough_order ON speech (installation_id, playthrough_id, gamets, ts, rowid);
CREATE INDEX speech_turn_order ON speech (turn_id, rowid) WHERE turn_id IS NOT NULL;

CREATE TABLE responselog (
    rowid bigserial PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    session_id uuid REFERENCES sessions(session_id) ON DELETE SET NULL,
    turn_id uuid,
    response_message_id uuid UNIQUE,
    localts bigint NOT NULL,
    sent bigint NOT NULL DEFAULT 0 CHECK (sent IN (0, 1)),
    actor text,
    text text,
    action text,
    tag varchar(256),
    actor_identity jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(actor_identity) = 'object'),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    sent_at timestamptz
);
CREATE INDEX responselog_pending ON responselog (installation_id, session_id, rowid) WHERE sent = 0;

CREATE TABLE prompts (
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    prompt_key varchar(128) NOT NULL,
    default_prompt text NOT NULL,
    custom_prompt text,
    description text NOT NULL DEFAULT '',
    source_configuration_id uuid REFERENCES configuration_sets(configuration_id) ON DELETE SET NULL,
    source_revision integer CHECK (source_revision IS NULL OR source_revision > 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id, prompt_key)
);

CREATE TABLE rechat_chains (
    chain_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    session_id uuid NOT NULL REFERENCES sessions(session_id) ON DELETE CASCADE,
    generation bigint NOT NULL CHECK (generation >= 0),
    mode text NOT NULL CHECK (mode IN ('tight','conversational','group','random')),
    state text NOT NULL DEFAULT 'open' CHECK (state IN ('open','awaiting_playback','request_in_flight','closed','cancelled')),
    max_depth smallint NOT NULL CHECK (max_depth BETWEEN 1 AND 32),
    current_depth smallint NOT NULL DEFAULT 0 CHECK (current_depth BETWEEN 0 AND 32 AND current_depth <= max_depth),
    participants jsonb NOT NULL CHECK (jsonb_typeof(participants) = 'array'),
    previous_speaker jsonb CHECK (previous_speaker IS NULL OR jsonb_typeof(previous_speaker) = 'object'),
    next_target jsonb CHECK (next_target IS NULL OR jsonb_typeof(next_target) = 'object'),
    origin_turn_id uuid REFERENCES turns(turn_id) ON DELETE SET NULL,
    latest_turn_id uuid REFERENCES turns(turn_id) ON DELETE SET NULL,
    cancellation_reason text,
    expires_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (session_id, generation, chain_id)
);
CREATE UNIQUE INDEX rechat_one_open_chain_per_session ON rechat_chains (session_id, generation)
WHERE state IN ('open','awaiting_playback','request_in_flight');

-- Backfill protocol source history without inventing game time values that were not captured.
INSERT INTO eventlog (
    installation_id, playthrough_id, profile_id, session_id, source_event_id, request_id, turn_id,
    type, data, sess, gamets, localts, ts, people, location, party, speaker, target, audience, payload, created_at
)
SELECT se.installation_id, s.playthrough_id, s.profile_id, se.session_id, se.source_event_id, se.request_id, se.turn_id,
       se.event_kind, se.payload::text, se.session_id::text, 0, extract(epoch FROM se.occurred_at)::bigint,
       (extract(epoch FROM se.occurred_at) * 1000)::bigint,
       se.payload->>'people', se.payload#>>'{payload,context,location,name}', se.payload->>'party',
       COALESCE(se.payload#>'{payload,speaker}', se.payload->'speaker', '{}'::jsonb),
       COALESCE(se.payload#>'{payload,target}', se.payload->'target', '{}'::jsonb),
       CASE WHEN jsonb_typeof(COALESCE(se.payload#>'{payload,audience}', se.payload->'audience')) = 'array'
            THEN COALESCE(se.payload#>'{payload,audience}', se.payload->'audience') ELSE '[]'::jsonb END,
       se.payload, se.received_at
FROM source_events se
LEFT JOIN sessions s ON s.session_id = se.session_id
ON CONFLICT (source_event_id) DO NOTHING;

-- Materialize generated dialogue separately so prompt history has CHIM-compatible speech rows.
INSERT INTO speech (
    installation_id, playthrough_id, session_id, turn_id, dialogue_message_id, sess, speaker, speech,
    location, listener, localts, gamets, ts, utterance_id, speaker_identity, listener_identity,
    audience, delivery_state, created_at
)
SELECT s.installation_id, s.playthrough_id, u.session_id, u.turn_id, u.dialogue_message_id, u.session_id::text,
       COALESCE(u.speaker->>'display_name', u.speaker->>'record_id'), u.text,
       t.context#>>'{location,name}', COALESCE(u.addressee->>'display_name', u.addressee->>'record_id'),
       extract(epoch FROM u.emitted_at)::bigint, 0, (extract(epoch FROM u.emitted_at) * 1000)::bigint,
       u.dialogue_message_id::text, u.speaker, u.addressee, u.audience,
       CASE u.delivery_state WHEN 'pending' THEN 'emitted' WHEN 'played' THEN 'spoken' ELSE u.delivery_state END,
       u.emitted_at
FROM dialogue_utterances u
JOIN sessions s ON s.session_id = u.session_id
JOIN turns t ON t.turn_id = u.turn_id
ON CONFLICT (dialogue_message_id) DO NOTHING;

INSERT INTO prompts (installation_id, prompt_key, default_prompt, custom_prompt, description, source_configuration_id, source_revision)
SELECT c.installation_id, left(regexp_replace(lower(c.name), '[^a-z0-9_.-]+', '_', 'g'), 128),
       COALESCE(r.content->>'default_prompt', r.content->>'instruction', ''),
       NULLIF(COALESCE(r.content->>'custom_prompt', r.content->>'instruction'), ''),
       COALESCE(r.content->>'description', c.name), c.configuration_id, c.current_revision
FROM configuration_sets c
JOIN configuration_revisions r ON r.configuration_id = c.configuration_id AND r.revision = c.current_revision
WHERE c.kind = 'prompt' AND c.deleted_at IS NULL
ON CONFLICT (installation_id, prompt_key) DO NOTHING;
