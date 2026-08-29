-- Exact Herika runtime/audit tables staged beside LORKHAN's immutable protocol transport.
CREATE SEQUENCE herika_compat.audit_request_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
CREATE TABLE herika_compat.audit_request (
    request text,
    result text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    rowid bigint DEFAULT nextval('herika_compat.audit_request_rowid_seq'::regclass) NOT NULL,
    url text,
    connector text,
    usage jsonb,
    response text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.audit_request ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.audit_request_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.audit_request ADD CONSTRAINT audit_request_primary PRIMARY KEY (rowid);

CREATE TABLE herika_compat.audit_memory (
    input text,
    keywords text,
    rank_any numeric(20,10),
    rank_all numeric(20,10),
    memory text,
    "time" text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    recall_candidates jsonb
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE INDEX chim_harness_audit_memory_created_at_idx ON herika_compat.audit_memory USING btree (created_at);

CREATE TABLE herika_compat.log (
    localts bigint NOT NULL,
    prompt text,
    response text,
    url text,
    rowid bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.log_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.log_rowid_seq OWNED BY herika_compat.log.rowid;
ALTER TABLE ONLY herika_compat.log ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.log_rowid_seq'::regclass);

CREATE TABLE herika_compat.actions_issued (
    action text,
    fullcall text,
    actorname text,
    ts numeric,
    localts numeric,
    gamets numeric,
    original text,
    rowid integer NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.actions_issued_rowid_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.actions_issued_rowid_seq OWNED BY herika_compat.actions_issued.rowid;
ALTER TABLE ONLY herika_compat.actions_issued ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.actions_issued_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.actions_issued ADD CONSTRAINT actions_issued_pkey PRIMARY KEY (rowid);

CREATE TABLE herika_compat.moods_issued (
    sess character varying(1024),
    speaker text,
    mood text,
    listener text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint DEFAULT nextval('herika_compat.speech_rowid_seq'::regclass) NOT NULL,
    emotion text,
    emotion_intensity text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.moods_issued ADD CONSTRAINT moods_issued_pkey PRIMARY KEY (rowid);

CREATE TABLE herika_compat.rolemaster (
    localts bigint NOT NULL,
    ttl bigint NOT NULL,
    type character varying(128),
    data text,
    rowid bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.rolemaster_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.rolemaster_rowid_seq OWNED BY herika_compat.rolemaster.rowid;
ALTER TABLE ONLY herika_compat.rolemaster ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.rolemaster_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.rolemaster ADD CONSTRAINT rolemaster_pk PRIMARY KEY (rowid);

CREATE TABLE herika_compat.conf_opts (
    id text NOT NULL,
    value text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.conf_opts ADD CONSTRAINT pid PRIMARY KEY (id);

CREATE TABLE herika_compat.database_versioning (
    tablename text NOT NULL,
    version bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.database_versioning ADD CONSTRAINT database_versioning_pkey PRIMARY KEY (tablename);

CREATE TABLE herika_compat.audit_request_metadata (
    rowid bigint PRIMARY KEY REFERENCES herika_compat.audit_request(rowid) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES public.profiles(profile_id) ON DELETE SET NULL,
    session_id uuid REFERENCES public.sessions(session_id) ON DELETE SET NULL,
    turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL,
    request_id uuid,
    prompt_trace_id uuid REFERENCES public.prompt_traces(prompt_trace_id) ON DELETE SET NULL,
    provider_attempt_id uuid REFERENCES public.provider_attempts(provider_attempt_id) ON DELETE SET NULL
);
CREATE TABLE herika_compat.log_metadata (
    rowid bigint PRIMARY KEY,
    turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL,
    request_id uuid,
    prompt_trace_id uuid REFERENCES public.prompt_traces(prompt_trace_id) ON DELETE SET NULL
);
CREATE TABLE herika_compat.action_issued_metadata (
    rowid integer PRIMARY KEY REFERENCES herika_compat.actions_issued(rowid) ON DELETE CASCADE,
    action_id uuid NOT NULL REFERENCES public.action_intents(action_id) ON DELETE CASCADE,
    session_id uuid REFERENCES public.sessions(session_id) ON DELETE SET NULL,
    turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL,
    request_id uuid,
    state text NOT NULL,
    UNIQUE (action_id)
);

WITH rows AS (
    SELECT row_number() OVER (ORDER BY s.created_at,s.turn_id)::bigint AS rowid,
           s.turn_id,s.source_manifest,s.created_at,t.request_id,t.session_id,
           se.installation_id,ss.playthrough_id,ss.profile_id,pt.prompt_trace_id,
           pa.provider_attempt_id,pa.state AS provider_state,pa.provider_name,pa.model,
           pa.input_bytes,pa.output_bytes,pa.duration_ms,pa.error_code,
           (SELECT string_agg(u.text,E'\n' ORDER BY u.utterance_index) FROM public.dialogue_utterances u WHERE u.turn_id=s.turn_id) AS response
    FROM public.turn_provider_snapshots s
    JOIN public.turns t ON t.turn_id=s.turn_id
    JOIN public.sessions ss ON ss.session_id=t.session_id
    LEFT JOIN public.source_events se ON se.turn_id=t.turn_id AND se.event_kind IN ('turn.requested','rechat')
    LEFT JOIN public.prompt_traces pt ON pt.turn_id=s.turn_id
    LEFT JOIN LATERAL (
        SELECT p.* FROM public.provider_attempts p WHERE p.turn_id=s.turn_id
        ORDER BY p.attempt_number DESC,p.started_at DESC LIMIT 1
    ) pa ON true
)
INSERT INTO herika_compat.audit_request (rowid,request,result,created_at,url,connector,usage,response)
SELECT rowid,(source_manifest->'message'->'_prompt')::text,
       COALESCE(error_code,provider_state,'accepted'),created_at AT TIME ZONE 'UTC',NULL,
       provider_name,jsonb_strip_nulls(jsonb_build_object('model',model,'input_bytes',input_bytes,
           'output_bytes',output_bytes,'duration_ms',duration_ms,'state',provider_state)),response
FROM rows ORDER BY rowid;

INSERT INTO herika_compat.audit_request_metadata (
    rowid,installation_id,playthrough_id,profile_id,session_id,turn_id,request_id,prompt_trace_id,provider_attempt_id
)
SELECT a.rowid,pt.installation_id,pt.playthrough_id,pt.profile_id,pt.session_id,pt.turn_id,pt.request_id,
       pt.prompt_trace_id,pa.provider_attempt_id
FROM herika_compat.audit_request a
JOIN (
    SELECT row_number() OVER (ORDER BY s.created_at,s.turn_id)::bigint AS rowid,s.turn_id
    FROM public.turn_provider_snapshots s
) source_rows ON source_rows.rowid=a.rowid
JOIN public.prompt_traces pt ON pt.turn_id=source_rows.turn_id
LEFT JOIN LATERAL (
    SELECT p.provider_attempt_id FROM public.provider_attempts p WHERE p.turn_id=pt.turn_id
    ORDER BY p.attempt_number DESC,p.started_at DESC LIMIT 1
) pa ON true;
SELECT setval('herika_compat.audit_request_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.audit_request),1),
              EXISTS(SELECT 1 FROM herika_compat.audit_request));

INSERT INTO herika_compat.log (rowid,localts,prompt,response,url)
SELECT row_number() OVER (ORDER BY s.created_at,s.turn_id)::bigint,
       extract(epoch FROM s.created_at)::bigint,(s.source_manifest->'message'->'_prompt')::text,
       (SELECT string_agg(u.text,E'\n' ORDER BY u.utterance_index) FROM public.dialogue_utterances u WHERE u.turn_id=s.turn_id),NULL
FROM public.turn_provider_snapshots s ORDER BY s.created_at,s.turn_id;
INSERT INTO herika_compat.log_metadata (rowid,turn_id,request_id,prompt_trace_id)
SELECT l.rowid,s.turn_id,t.request_id,pt.prompt_trace_id
FROM herika_compat.log l
JOIN (
    SELECT row_number() OVER (ORDER BY s.created_at,s.turn_id)::bigint AS rowid,s.turn_id
    FROM public.turn_provider_snapshots s
) source_rows ON source_rows.rowid=l.rowid
JOIN public.turn_provider_snapshots s ON s.turn_id=source_rows.turn_id
JOIN public.turns t ON t.turn_id=s.turn_id
LEFT JOIN public.prompt_traces pt ON pt.turn_id=s.turn_id;
SELECT setval('herika_compat.log_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.log),1),
              EXISTS(SELECT 1 FROM herika_compat.log));

INSERT INTO herika_compat.actions_issued (rowid,action,fullcall,actorname,ts,localts,gamets,original)
SELECT row_number() OVER (ORDER BY emitted_at,action_id)::integer,action_name,parameters::text,
       COALESCE(actor->>'display_name',actor->>'record_id'),extract(epoch FROM emitted_at),
       extract(epoch FROM emitted_at),0,jsonb_build_object('actor',actor,'target',target,'parameters',parameters)::text
FROM public.action_intents ORDER BY emitted_at,action_id;
INSERT INTO herika_compat.action_issued_metadata (rowid,action_id,session_id,turn_id,request_id,state)
SELECT h.rowid,a.action_id,a.session_id,a.turn_id,a.request_id,a.state
FROM herika_compat.actions_issued h
JOIN (
    SELECT row_number() OVER (ORDER BY emitted_at,action_id)::integer AS rowid,a.*
    FROM public.action_intents a
) a ON a.rowid=h.rowid;
SELECT setval('herika_compat.actions_issued_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.actions_issued),1),
              EXISTS(SELECT 1 FROM herika_compat.actions_issued));

INSERT INTO herika_compat.moods_issued (sess,speaker,mood,listener,localts,gamets,ts,rowid,emotion,emotion_intensity)
SELECT sess,speaker,mood,listener,localts,gamets,ts,rowid,emotion,emotion_intensity
FROM herika_compat.speech
WHERE mood IS NOT NULL OR emotion IS NOT NULL OR emotion_intensity IS NOT NULL;

INSERT INTO herika_compat.conf_opts (id,value)
SELECT 'PLAYER_NAME',p.name FROM public.profiles p
WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='player'
ORDER BY p.created_at DESC LIMIT 1;
INSERT INTO herika_compat.conf_opts (id,value)
SELECT 'LORKHAN_GLOBAL_SETTINGS',r.content::text
FROM public.configuration_sets c
JOIN public.configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
WHERE c.kind='global_settings' AND c.deleted_at IS NULL
ORDER BY c.created_at DESC LIMIT 1;

INSERT INTO herika_compat.database_versioning (tablename,version)
SELECT 'LORKHANserver',max(version) FROM lorkhan_internal.schema_migrations;
