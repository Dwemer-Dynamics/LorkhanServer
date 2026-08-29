-- Herika memory, knowledge, relationship queue, and manual narrative persistence.
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE herika_compat.memory (
    speaker text,
    message text,
    session text,
    uid integer NOT NULL,
    listener text,
    localts bigint,
    gamets bigint NOT NULL,
    momentum text,
    rowid bigint NOT NULL,
    event character varying(64),
    ts bigint
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.memory_uid_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
CREATE SEQUENCE herika_compat.memory_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.memory_uid_seq OWNED BY herika_compat.memory.uid;
ALTER SEQUENCE herika_compat.memory_rowid_seq OWNED BY herika_compat.memory.rowid;
ALTER TABLE ONLY herika_compat.memory ALTER COLUMN uid SET DEFAULT nextval('herika_compat.memory_uid_seq'::regclass);
ALTER TABLE ONLY herika_compat.memory ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.memory_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.memory ADD CONSTRAINT memory_pidx PRIMARY KEY (rowid);

CREATE TABLE herika_compat.memory_summary (
    gamets_truncated bigint NOT NULL,
    n integer,
    packed_message text,
    summary text,
    classifier text,
    uid integer NOT NULL,
    rowid integer NOT NULL,
    embedding public.vector(384),
    companions text,
    embedding768 public.vector(768),
    tags text,
    native_vec tsvector,
    scope text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.memory_summary_rowid_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.memory_summary_rowid_seq OWNED BY herika_compat.memory_summary.rowid;
ALTER TABLE ONLY herika_compat.memory_summary ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.memory_summary_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.memory_summary ADD CONSTRAINT memory_summary_pidx PRIMARY KEY (rowid);

CREATE VIEW herika_compat.memory_v AS
SELECT subquery.message,subquery.uid,subquery.gamets,subquery.speaker,subquery.listener,subquery.ts
FROM (
    SELECT memory.message,memory.uid,memory.gamets,'-'::text AS speaker,'-'::text AS listener,memory.ts
    FROM herika_compat.memory
    WHERE memory.message NOT LIKE 'Dear Diary%'::text AND memory.message<>''::text
      AND memory.event::text<>'backgroundlife_diary'::text
    UNION
    SELECT '(Context Location:'::text||speech.location||') '::text||speech.speaker||': '::text||speech.speech,
           speech.rowid::integer AS rowid,speech.gamets,speech.speaker,speech.listener,speech.ts
    FROM herika_compat.speech WHERE speech.speech<>''::text
    UNION
    SELECT eventlog.data,eventlog.rowid::integer AS rowid,eventlog.gamets,
           '-'::text AS text,'-'::text AS listener,eventlog.ts
    FROM public.eventlog
    WHERE eventlog.type::text=ANY(ARRAY['death'::character varying::text,'location'::character varying::text])
) subquery
ORDER BY subquery.gamets,subquery.ts;

CREATE TABLE herika_compat.oghma (
    topic character varying NOT NULL,
    topic_desc character varying NOT NULL,
    native_vector tsvector,
    knowledge_class text,
    topic_desc_basic text,
    knowledge_class_basic text,
    tags text,
    category text,
    vector384 public.vector(384),
    aliases text DEFAULT ''::text NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.oghma ADD CONSTRAINT oghma_pkey PRIMARY KEY (topic);
CREATE INDEX oghma_native_vector_idx ON herika_compat.oghma USING gin (native_vector);

CREATE TABLE herika_compat.oghma_dynamic (
    id integer NOT NULL,
    id_quest character varying(1024),
    stage integer,
    topic character varying,
    topic_desc text,
    knowledge_class text,
    topic_desc_basic text,
    knowledge_class_basic text,
    tags text,
    category text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.oghma_dynamic_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.oghma_dynamic_id_seq OWNED BY herika_compat.oghma_dynamic.id;
ALTER TABLE ONLY herika_compat.oghma_dynamic ALTER COLUMN id SET DEFAULT nextval('herika_compat.oghma_dynamic_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.oghma_dynamic ADD CONSTRAINT oghma_dynamic_pkey PRIMARY KEY (id);

CREATE TABLE herika_compat.diarylog (
    ts text NOT NULL,
    sess character varying(1024),
    topic text,
    content text,
    tags text,
    people text,
    localts bigint NOT NULL,
    location text,
    gamets bigint NOT NULL,
    rowid bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.diarylog_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.diarylog_rowid_seq OWNED BY herika_compat.diarylog.rowid;
ALTER TABLE ONLY herika_compat.diarylog ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.diarylog_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.diarylog ADD CONSTRAINT diarylog_pidx PRIMARY KEY (rowid);
CREATE INDEX idx_diarylog_people_gamets ON herika_compat.diarylog USING btree (lower(TRIM(BOTH FROM people)),gamets DESC,localts DESC,rowid DESC);

CREATE TABLE herika_compat.physical_npc_diaries (
    npc_name text NOT NULL,
    title text NOT NULL,
    last_diary_localts bigint DEFAULT 0 NOT NULL,
    created_at bigint DEFAULT 0 NOT NULL,
    updated_at bigint DEFAULT 0 NOT NULL
);
ALTER TABLE ONLY herika_compat.physical_npc_diaries ADD CONSTRAINT physical_npc_diaries_pkey PRIMARY KEY (npc_name);
CREATE INDEX physical_npc_diaries_updated_idx ON herika_compat.physical_npc_diaries USING btree (updated_at DESC);

CREATE TABLE herika_compat.relationship_eval_queue (
    id integer NOT NULL,
    npc_id integer NOT NULL,
    eval_data jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    retry_count integer DEFAULT 0
);
CREATE SEQUENCE herika_compat.relationship_eval_queue_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.relationship_eval_queue_id_seq OWNED BY herika_compat.relationship_eval_queue.id;
ALTER TABLE ONLY herika_compat.relationship_eval_queue ALTER COLUMN id SET DEFAULT nextval('herika_compat.relationship_eval_queue_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.relationship_eval_queue ADD CONSTRAINT relationship_eval_queue_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.relationship_eval_queue ADD CONSTRAINT relationship_eval_queue_npc_id_key UNIQUE (npc_id);

CREATE TABLE herika_compat.relationship_init_queue (
    id integer NOT NULL,
    npc_id integer NOT NULL,
    init_data jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    retry_count integer DEFAULT 0,
    last_error text
);
CREATE SEQUENCE herika_compat.relationship_init_queue_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.relationship_init_queue_id_seq OWNED BY herika_compat.relationship_init_queue.id;
ALTER TABLE ONLY herika_compat.relationship_init_queue ALTER COLUMN id SET DEFAULT nextval('herika_compat.relationship_init_queue_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.relationship_init_queue ADD CONSTRAINT relationship_init_queue_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.relationship_init_queue ADD CONSTRAINT relationship_init_queue_npc_id_key UNIQUE (npc_id);

CREATE TABLE herika_compat.rumors (
    id integer NOT NULL,
    gamets bigint,
    ts bigint,
    hold text,
    content text,
    type text,
    rumor_length_days integer
);
CREATE SEQUENCE herika_compat.rumors_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.rumors_id_seq OWNED BY herika_compat.rumors.id;
ALTER TABLE ONLY herika_compat.rumors ALTER COLUMN id SET DEFAULT nextval('herika_compat.rumors_id_seq'::regclass);
COMMENT ON TABLE herika_compat.rumors IS 'rumors and news per region';

CREATE TABLE herika_compat.memory_metadata (
    rowid bigint PRIMARY KEY REFERENCES herika_compat.memory(rowid) ON DELETE CASCADE,
    memory_id uuid NOT NULL REFERENCES public.memory_records(memory_id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES public.profiles(profile_id) ON DELETE SET NULL,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    tier text NOT NULL,
    source_event_id uuid REFERENCES public.source_events(source_event_id) ON DELETE SET NULL,
    UNIQUE (memory_id)
);
CREATE TABLE herika_compat.memory_summary_metadata (
    rowid integer PRIMARY KEY REFERENCES herika_compat.memory_summary(rowid) ON DELETE CASCADE,
    memory_id uuid NOT NULL REFERENCES public.memory_records(memory_id) ON DELETE CASCADE,
    UNIQUE (memory_id)
);
CREATE TABLE herika_compat.oghma_metadata (
    topic character varying PRIMARY KEY REFERENCES herika_compat.oghma(topic) ON DELETE CASCADE,
    document_id uuid NOT NULL REFERENCES public.knowledge_documents(document_id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES public.profiles(profile_id) ON DELETE SET NULL,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    UNIQUE (document_id)
);
CREATE TABLE herika_compat.diarylog_metadata (
    rowid bigint PRIMARY KEY REFERENCES herika_compat.diarylog(rowid) ON DELETE CASCADE,
    narrative_id uuid NOT NULL REFERENCES public.narrative_records(narrative_id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    profile_id uuid REFERENCES public.profiles(profile_id) ON DELETE SET NULL,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    UNIQUE (narrative_id)
);

WITH active AS (
    SELECT m.*,row_number() OVER (ORDER BY m.occurred_at,m.memory_id)::bigint AS compat_rowid,
           row_number() OVER (ORDER BY m.occurred_at,m.memory_id)::integer AS compat_uid,
           p.name AS profile_name
    FROM public.memory_records m
    LEFT JOIN public.profiles p ON p.profile_id=m.profile_id
    WHERE m.deleted_at IS NULL
)
INSERT INTO herika_compat.memory (rowid,uid,speaker,message,session,listener,localts,gamets,momentum,event,ts)
SELECT compat_rowid,compat_uid,profile_name,content,playthrough_id::text,'Player',
       extract(epoch FROM occurred_at)::bigint,0,tier,tier,(extract(epoch FROM occurred_at)*1000)::bigint
FROM active ORDER BY compat_rowid;
INSERT INTO herika_compat.memory_metadata (rowid,memory_id,installation_id,profile_id,playthrough_id,tier,source_event_id)
SELECT m.compat_rowid,m.memory_id,m.installation_id,m.profile_id,m.playthrough_id,m.tier,m.source_event_id
FROM (
    SELECT source.*,row_number() OVER (ORDER BY source.occurred_at,source.memory_id)::bigint AS compat_rowid
    FROM public.memory_records source WHERE source.deleted_at IS NULL
) m;
SELECT setval('herika_compat.memory_uid_seq',COALESCE((SELECT max(uid) FROM herika_compat.memory),1),EXISTS(SELECT 1 FROM herika_compat.memory));
SELECT setval('herika_compat.memory_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.memory),1),EXISTS(SELECT 1 FROM herika_compat.memory));

INSERT INTO herika_compat.memory_summary (
    rowid,gamets_truncated,n,packed_message,summary,classifier,uid,companions,tags,native_vec,scope
)
SELECT row_number() OVER (ORDER BY m.occurred_at,m.memory_id)::integer,0,1,m.content,m.content,m.tier,
       h.uid,NULL,array_to_string(m.lexical_terms,','),to_tsvector('simple',m.content),m.playthrough_id::text
FROM public.memory_records m
JOIN herika_compat.memory_metadata mm ON mm.memory_id=m.memory_id
JOIN herika_compat.memory h ON h.rowid=mm.rowid
WHERE m.deleted_at IS NULL AND m.tier IN ('mid','long')
ORDER BY m.occurred_at,m.memory_id;
INSERT INTO herika_compat.memory_summary_metadata (rowid,memory_id)
SELECT m.compat_rowid,m.memory_id
FROM (
    SELECT source.*,row_number() OVER (ORDER BY source.occurred_at,source.memory_id)::integer AS compat_rowid
    FROM public.memory_records source
    WHERE source.deleted_at IS NULL AND source.tier IN ('mid','long')
) m;
SELECT setval('herika_compat.memory_summary_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.memory_summary),1),EXISTS(SELECT 1 FROM herika_compat.memory_summary));

WITH documents AS (
    SELECT d.*,row_number() OVER (PARTITION BY d.title ORDER BY d.created_at,d.document_id) AS title_number
    FROM public.knowledge_documents d WHERE d.deleted_at IS NULL
), named AS (
    SELECT d.*,CASE WHEN title_number=1 THEN title ELSE title||' ['||left(document_id::text,8)||']' END AS compat_topic
    FROM documents d
)
INSERT INTO herika_compat.oghma (topic,topic_desc,native_vector,knowledge_class,topic_desc_basic,
                                 knowledge_class_basic,tags,category,aliases)
SELECT compat_topic,content,to_tsvector('simple',content),'Morrowind',left(content,1000),'Morrowind',
       array_to_string(lexical_terms,','),COALESCE(provenance->>'category','LORKHAN'),''
FROM named ORDER BY created_at,document_id;
WITH documents AS (
    SELECT d.*,row_number() OVER (PARTITION BY d.title ORDER BY d.created_at,d.document_id) AS title_number
    FROM public.knowledge_documents d WHERE d.deleted_at IS NULL
), named AS (
    SELECT d.*,CASE WHEN title_number=1 THEN title ELSE title||' ['||left(document_id::text,8)||']' END AS compat_topic
    FROM documents d
)
INSERT INTO herika_compat.oghma_metadata (topic,document_id,installation_id,profile_id,playthrough_id)
SELECT compat_topic,document_id,installation_id,profile_id,playthrough_id FROM named;

INSERT INTO herika_compat.diarylog (rowid,ts,sess,topic,content,tags,people,localts,location,gamets)
SELECT row_number() OVER (ORDER BY n.created_at,n.narrative_id)::bigint,n.created_at::text,
       n.playthrough_id::text,n.title,n.content,n.kind,p.name,extract(epoch FROM n.created_at)::bigint,
       n.provenance->>'location',0
FROM public.narrative_records n LEFT JOIN public.profiles p ON p.profile_id=n.profile_id
WHERE n.deleted_at IS NULL ORDER BY n.created_at,n.narrative_id;
INSERT INTO herika_compat.diarylog_metadata (rowid,narrative_id,installation_id,profile_id,playthrough_id)
SELECT n.compat_rowid,n.narrative_id,n.installation_id,n.profile_id,n.playthrough_id
FROM (
    SELECT source.*,row_number() OVER (ORDER BY source.created_at,source.narrative_id)::bigint AS compat_rowid
    FROM public.narrative_records source WHERE source.deleted_at IS NULL
) n;
SELECT setval('herika_compat.diarylog_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.diarylog),1),EXISTS(SELECT 1 FROM herika_compat.diarylog));

INSERT INTO herika_compat.physical_npc_diaries (npc_name,title,last_diary_localts,created_at,updated_at)
SELECT COALESCE(p.name,'Unknown NPC'),max(n.title),max(extract(epoch FROM n.updated_at)::bigint),
       min(extract(epoch FROM n.created_at)::bigint),max(extract(epoch FROM n.updated_at)::bigint)
FROM public.narrative_records n LEFT JOIN public.profiles p ON p.profile_id=n.profile_id
WHERE n.deleted_at IS NULL AND n.kind='diary'
GROUP BY COALESCE(p.name,'Unknown NPC');

UPDATE herika_compat.core_npc_master npc
SET extended_data=npc.extended_data||jsonb_build_object('relationships',rels.relationships)
FROM (
    SELECT nm.npc_id,jsonb_object_agg(
        COALESCE(r.actor_identity->>'record_id',r.actor_identity->>'display_name',r.relationship_id::text),
        jsonb_build_object('name',COALESCE(r.actor_identity->>'display_name',r.actor_identity->>'record_id'),
                           'affinity',r.affinity,'disposition',r.disposition,'source',r.source_mode)
    ) AS relationships
    FROM public.relationship_records r
    JOIN herika_compat.npc_metadata nm ON nm.source_profile_id=r.profile_id
    WHERE r.deleted_at IS NULL
    GROUP BY nm.npc_id
) rels WHERE rels.npc_id=npc.id;

INSERT INTO herika_compat.audit_memory (input,keywords,rank_any,rank_all,memory,"time",created_at,recall_candidates)
SELECT query,array_to_string(result_ids,','),NULL,NULL,domain,algorithm,created_at AT TIME ZONE 'UTC',
       jsonb_build_object('result_ids',result_ids,'scores',scores)
FROM public.retrieval_traces;
