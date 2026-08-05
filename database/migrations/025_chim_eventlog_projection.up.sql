-- CHIM-compatible roleplay event log projection over immutable ALMSIVI source records.
-- The compact eventlog table is intentionally presentation/prompt history; typed ownership and
-- correlation live in eventlog_metadata so multiple installations can safely share one database.
CREATE TABLE eventlog_metadata (
    rowid bigint PRIMARY KEY REFERENCES eventlog(rowid) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES profiles(profile_id) ON DELETE SET NULL,
    session_id uuid REFERENCES sessions(session_id) ON DELETE SET NULL,
    source_event_id uuid REFERENCES source_events(source_event_id) ON DELETE SET NULL,
    dialogue_message_id uuid REFERENCES dialogue_utterances(dialogue_message_id) ON DELETE SET NULL,
    request_id uuid,
    turn_id uuid,
    projection_kind varchar(32) NOT NULL,
    projection_key text NOT NULL,
    speaker jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(speaker) = 'object'),
    target jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(target) = 'object'),
    audience jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(audience) = 'array'),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    suppressed_at timestamptz,
    suppression_reason text,
    UNIQUE (projection_kind, projection_key)
);
CREATE INDEX eventlog_metadata_scope_order ON eventlog_metadata (installation_id, playthrough_id, rowid DESC)
    WHERE suppressed_at IS NULL;
CREATE INDEX eventlog_metadata_turn_order ON eventlog_metadata (turn_id, rowid) WHERE turn_id IS NOT NULL;
CREATE INDEX eventlog_metadata_source ON eventlog_metadata (source_event_id) WHERE source_event_id IS NOT NULL;
CREATE UNIQUE INDEX eventlog_metadata_dialogue ON eventlog_metadata (dialogue_message_id)
    WHERE dialogue_message_id IS NOT NULL;

CREATE TABLE eventlog_hidden_types (
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    event_type varchar(128) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id, event_type)
);

-- Raw transport projections are reconstructible from source_events. Replace them with meaningful
-- CHIM-style player and NPC conversation rows before removing ALMSIVI-only inline columns.
TRUNCATE TABLE eventlog RESTART IDENTITY CASCADE;

INSERT INTO eventlog (
    installation_id,playthrough_id,profile_id,session_id,source_event_id,request_id,turn_id,
    type,data,sess,gamets,localts,ts,people,location,party,utterance_id,delivery_state,
    speaker,target,audience,payload,created_at
)
SELECT s.installation_id,s.playthrough_id,s.profile_id,t.session_id,se.source_event_id,t.request_id,t.turn_id,
       CASE WHEN se.event_kind='rechat' OR t.context ? 'rechat' THEN 'rechat' ELSE 'inputtext' END,
       COALESCE(NULLIF(t.speaker->>'display_name',''),NULLIF(t.speaker->>'record_id',''),'Player')||': '||t.input_text,
       t.session_id::text,
       CASE WHEN jsonb_typeof(t.context#>'{world,game_time}')='number'
            THEN floor((t.context#>>'{world,game_time}')::numeric)::bigint ELSE 0 END,
       extract(epoch FROM t.accepted_at)::bigint,
       (extract(epoch FROM t.accepted_at)*1000)::bigint,
       '|'||concat_ws('|',
           COALESCE(NULLIF(t.speaker->>'display_name',''),NULLIF(t.speaker->>'record_id','')),
           COALESCE(NULLIF(t.target->>'display_name',''),NULLIF(t.target->>'record_id',''))
       )||'|',
       NULLIF(t.context#>>'{world,cell}',''),NULL,NULL,NULL,
       t.speaker,t.target,t.audience,
       jsonb_build_object('input',jsonb_build_object('kind',t.input_kind,'language',t.input_language,'text',t.input_text),
           'context',t.context),t.accepted_at
FROM turns t
JOIN sessions s ON s.session_id=t.session_id
LEFT JOIN source_events se ON se.source_event_id=t.message_id;

INSERT INTO eventlog (
    installation_id,playthrough_id,profile_id,session_id,source_event_id,request_id,turn_id,
    type,data,sess,gamets,localts,ts,people,location,party,utterance_id,delivery_state,
    speaker,target,audience,payload,created_at
)
SELECT s.installation_id,s.playthrough_id,s.profile_id,u.session_id,NULL,u.request_id,u.turn_id,
       'chat',
       COALESCE(NULLIF(u.speaker->>'display_name',''),NULLIF(u.speaker->>'record_id',''),'NPC')||': '||u.text,
       u.session_id::text,
       CASE WHEN jsonb_typeof(t.context#>'{world,game_time}')='number'
            THEN floor((t.context#>>'{world,game_time}')::numeric)::bigint ELSE 0 END,
       extract(epoch FROM u.emitted_at)::bigint,
       (extract(epoch FROM u.emitted_at)*1000)::bigint,
       '|'||concat_ws('|',
           COALESCE(NULLIF(u.speaker->>'display_name',''),NULLIF(u.speaker->>'record_id','')),
           COALESCE(NULLIF(u.addressee->>'display_name',''),NULLIF(u.addressee->>'record_id',''))
       )||'|',
       NULLIF(t.context#>>'{world,cell}',''),NULL,u.dialogue_message_id::text,u.delivery_state,
       u.speaker,u.addressee,u.audience,
       jsonb_build_object('text',u.text,'speaker',u.speaker,'addressee',u.addressee,'audience',u.audience),u.emitted_at
FROM dialogue_utterances u
JOIN turns t ON t.turn_id=u.turn_id
JOIN sessions s ON s.session_id=u.session_id;

INSERT INTO eventlog_metadata (
    rowid,installation_id,playthrough_id,profile_id,session_id,source_event_id,dialogue_message_id,
    request_id,turn_id,projection_kind,projection_key,speaker,target,audience,payload,created_at
)
SELECT rowid,installation_id,playthrough_id,profile_id,session_id,source_event_id,
       CASE WHEN type='chat' AND utterance_id ~ '^[0-9a-f-]{36}$' THEN utterance_id::uuid ELSE NULL END,
       request_id,turn_id,
       CASE WHEN type='chat' THEN 'dialogue' ELSE 'turn' END,
       CASE WHEN type='chat' THEN 'dialogue:'||utterance_id ELSE 'turn:'||turn_id::text END,
       speaker,target,audience,payload,created_at
FROM eventlog;

DROP VIEW eventlog_view;
DROP INDEX IF EXISTS eventlog_playthrough_order;
DROP INDEX IF EXISTS eventlog_turn_order;
DROP INDEX IF EXISTS eventlog_type_order;
DROP INDEX IF EXISTS eventlog_utterance;

ALTER TABLE eventlog
    DROP COLUMN installation_id,
    DROP COLUMN playthrough_id,
    DROP COLUMN profile_id,
    DROP COLUMN session_id,
    DROP COLUMN source_event_id,
    DROP COLUMN request_id,
    DROP COLUMN turn_id,
    DROP COLUMN speaker,
    DROP COLUMN target,
    DROP COLUMN audience,
    DROP COLUMN payload,
    DROP COLUMN created_at;

CREATE INDEX eventlog_chim_order ON eventlog (gamets DESC,ts DESC,localts DESC,rowid DESC);
CREATE INDEX eventlog_chim_type_order ON eventlog (type,rowid DESC);
CREATE INDEX eventlog_chim_utterance ON eventlog (utterance_id) WHERE utterance_id IS NOT NULL;

CREATE VIEW eventlog_view AS
SELECT e.*,
       to_timestamp(e.localts) AT TIME ZONE 'UTC' AS local_datetime,
       CASE WHEN e.ts IS NULL THEN NULL ELSE to_timestamp(e.ts / 1000.0) AT TIME ZONE 'UTC' END AS event_datetime
FROM eventlog e;
