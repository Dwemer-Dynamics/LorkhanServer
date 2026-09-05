-- Staged canonical HerikaServer schema. The herika_compat schema allows a lossless backfill and
-- repository cutover before the conflicting public tables are renamed in a later migration.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE SCHEMA herika_compat;

CREATE TABLE herika_compat.core_api_badge (
    id integer NOT NULL,
    label text NOT NULL,
    api_key text NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.api_badge_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.api_badge_id_seq OWNED BY herika_compat.core_api_badge.id;
ALTER TABLE ONLY herika_compat.core_api_badge ALTER COLUMN id SET DEFAULT nextval('herika_compat.api_badge_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_api_badge ADD CONSTRAINT my_table_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.core_api_badge ADD CONSTRAINT core_api_badge_label_unique UNIQUE (label);
CREATE INDEX idx_core_api_badge_label_lower ON herika_compat.core_api_badge USING btree (lower(label));

CREATE TABLE herika_compat.core_llm_connector (
    id integer NOT NULL,
    label text,
    metadata jsonb,
    url text,
    model text,
    provider text,
    driver text,
    reasoning_model integer,
    max_tokens integer,
    enforce_json integer DEFAULT 1,
    prefill_json integer DEFAULT 0,
    api_badge_id integer,
    json_schema integer,
    temperature numeric,
    presence_penalty numeric,
    frequency_penalty numeric,
    repetition_penalty numeric,
    top_p numeric,
    top_k integer,
    min_p numeric,
    top_a numeric,
    service text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.llm_connector_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.llm_connector_id_seq OWNED BY herika_compat.core_llm_connector.id;
ALTER TABLE ONLY herika_compat.core_llm_connector ALTER COLUMN id SET DEFAULT nextval('herika_compat.llm_connector_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_llm_connector ADD CONSTRAINT llm_connector_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.core_llm_connector ADD CONSTRAINT llm_connector_api_badge_id_fkey
    FOREIGN KEY (api_badge_id) REFERENCES herika_compat.core_api_badge(id);

CREATE TABLE herika_compat.core_tts_connector (
    id integer NOT NULL,
    driver text,
    label text,
    metadata jsonb,
    api_badge_id integer,
    url text,
    voice_field text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.tts_connector_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.tts_connector_id_seq OWNED BY herika_compat.core_tts_connector.id;
ALTER TABLE ONLY herika_compat.core_tts_connector ALTER COLUMN id SET DEFAULT nextval('herika_compat.tts_connector_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_tts_connector ADD CONSTRAINT tts_connector_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.core_tts_connector ADD CONSTRAINT tts_connector_api_badge_id_fkey
    FOREIGN KEY (api_badge_id) REFERENCES herika_compat.core_api_badge(id);

CREATE TABLE herika_compat.core_stt_connector (
    id integer NOT NULL,
    driver text,
    label text,
    metadata jsonb,
    api_badge_id integer,
    url text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.stt_connector_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.stt_connector_id_seq OWNED BY herika_compat.core_stt_connector.id;
ALTER TABLE ONLY herika_compat.core_stt_connector ALTER COLUMN id SET DEFAULT nextval('herika_compat.stt_connector_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_stt_connector ADD CONSTRAINT stt_connector_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.core_stt_connector ADD CONSTRAINT stt_connector_api_badge_id_fkey
    FOREIGN KEY (api_badge_id) REFERENCES herika_compat.core_api_badge(id);

CREATE TABLE herika_compat.core_itt_connector (
    id integer NOT NULL,
    driver text,
    label text,
    metadata jsonb,
    api_badge_id integer,
    url text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.itt_connector_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.itt_connector_id_seq OWNED BY herika_compat.core_itt_connector.id;
ALTER TABLE ONLY herika_compat.core_itt_connector ALTER COLUMN id SET DEFAULT nextval('herika_compat.itt_connector_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_itt_connector ADD CONSTRAINT itt_connector_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.core_itt_connector ADD CONSTRAINT itt_connector_api_badge_id_fkey
    FOREIGN KEY (api_badge_id) REFERENCES herika_compat.core_api_badge(id);

CREATE TABLE herika_compat.core_tts_fallback (
    id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    race text NOT NULL,
    gender text NOT NULL,
    voiceid text DEFAULT ''::text NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT core_tts_fallback_gender_check CHECK (gender = ANY (ARRAY['male'::text, 'female'::text])),
    CONSTRAINT core_tts_fallback_race_gender_key UNIQUE (race, gender)
);

CREATE TABLE herika_compat.core_profiles (
    id integer NOT NULL,
    label text,
    default_npc text,
    default_narrator text,
    tts_connector_id integer,
    itt_connector_id integer,
    llm_primary_id integer,
    llm_secondary_id integer,
    llm_tertiary_id integer,
    llm_quaternary_id integer,
    llm_formatter_id integer,
    llm_fallback_id integer,
    metadata jsonb,
    diary_connector_id integer,
    slot integer,
    prompt text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.profiles_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.profiles_id_seq OWNED BY herika_compat.core_profiles.id;
ALTER TABLE ONLY herika_compat.core_profiles ALTER COLUMN id SET DEFAULT nextval('herika_compat.profiles_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_pkey PRIMARY KEY (id);
CREATE UNIQUE INDEX core_profiles_slot_unique_idx ON herika_compat.core_profiles USING btree (slot) WHERE slot IS NOT NULL;
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_tts_connector_id_fkey FOREIGN KEY (tts_connector_id) REFERENCES herika_compat.core_tts_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_itt_connector_id_fkey FOREIGN KEY (itt_connector_id) REFERENCES herika_compat.core_itt_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_llm_primary_id_fkey FOREIGN KEY (llm_primary_id) REFERENCES herika_compat.core_llm_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_llm_secondary_id_fkey FOREIGN KEY (llm_secondary_id) REFERENCES herika_compat.core_llm_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_llm_tertiary_id_fkey FOREIGN KEY (llm_tertiary_id) REFERENCES herika_compat.core_llm_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_llm_quaternary_id_fkey FOREIGN KEY (llm_quaternary_id) REFERENCES herika_compat.core_llm_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_llm_formatter_id_fkey FOREIGN KEY (llm_formatter_id) REFERENCES herika_compat.core_llm_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT profiles_llm_fallback_id_fkey FOREIGN KEY (llm_fallback_id) REFERENCES herika_compat.core_llm_connector(id);
ALTER TABLE ONLY herika_compat.core_profiles ADD CONSTRAINT fk_diary_connector FOREIGN KEY (diary_connector_id) REFERENCES herika_compat.core_llm_connector(id);
COMMENT ON COLUMN herika_compat.core_profiles.llm_fallback_id IS 'Fallback LLM connector used when primary connector fails with network error';
COMMENT ON COLUMN herika_compat.core_profiles.prompt IS 'profile specific prompt, will be added to context';

CREATE TABLE herika_compat.core_npc_master (
    id integer NOT NULL,
    npc_name text NOT NULL,
    npc_favorite integer DEFAULT 0,
    lock_profile integer DEFAULT 0,
    prompt_head text,
    npc_static_bio text,
    oghma_knowledge_tags text,
    emote_moods text,
    personality text,
    relationships text,
    occupation text,
    appearance text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    metadata jsonb,
    gender text,
    race text,
    refid character varying(16),
    profile_id integer,
    dynamic_profile integer,
    extended_data jsonb,
    md5 text,
    gamets_last_updated numeric,
    core text,
    base text,
    tags text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.npc_master_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.npc_master_id_seq OWNED BY herika_compat.core_npc_master.id;
ALTER TABLE ONLY herika_compat.core_npc_master ALTER COLUMN id SET DEFAULT nextval('herika_compat.npc_master_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_npc_master ADD CONSTRAINT npc_master_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.core_npc_master ADD CONSTRAINT npc_master_npc_name_key UNIQUE (npc_name);
ALTER TABLE ONLY herika_compat.core_npc_master ADD CONSTRAINT fk_profile_id FOREIGN KEY (profile_id) REFERENCES herika_compat.core_profiles(id) ON DELETE SET NULL;
COMMENT ON COLUMN herika_compat.core_npc_master.personality IS 'how they behave';
COMMENT ON COLUMN herika_compat.core_npc_master.core IS 'really quick summary of character';
COMMENT ON COLUMN herika_compat.core_npc_master.tags IS 'comma separated,user tags';

CREATE TABLE herika_compat.core_npc_master_history (
    history_id integer NOT NULL,
    npc_id integer NOT NULL,
    npc_name text,
    npc_favorite integer,
    lock_profile integer,
    prompt_head text,
    npc_static_bio text,
    oghma_knowledge_tags text,
    emote_moods text,
    personality text,
    relationships text,
    occupation text,
    appearance text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    metadata jsonb,
    gender text,
    race text,
    refid character varying(16),
    profile_id integer,
    dynamic_profile integer,
    extended_data jsonb,
    md5 text,
    gamets_last_updated numeric,
    created timestamp without time zone DEFAULT now(),
    core text,
    base text,
    tags text
);
CREATE SEQUENCE herika_compat.core_npc_master_history_history_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.core_npc_master_history_history_id_seq OWNED BY herika_compat.core_npc_master_history.history_id;
ALTER TABLE ONLY herika_compat.core_npc_master_history ALTER COLUMN history_id SET DEFAULT nextval('herika_compat.core_npc_master_history_history_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_npc_master_history ADD CONSTRAINT core_npc_master_history_pkey PRIMARY KEY (history_id);

CREATE TABLE herika_compat.core_player (id text PRIMARY KEY, value text) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE TABLE herika_compat.core_narrator (id text PRIMARY KEY, value text) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
COMMENT ON COLUMN herika_compat.core_player.id IS 'Key name such as player_name, appearance, speech_style, or Morrowind stats';
COMMENT ON COLUMN herika_compat.core_player.value IS 'Value for the key';
COMMENT ON COLUMN herika_compat.core_narrator.id IS 'Narrator setting key';
COMMENT ON COLUMN herika_compat.core_narrator.value IS 'Value for the setting';
CREATE TABLE herika_compat.general_settings (
    id text PRIMARY KEY,
    value text,
    description text DEFAULT ''::text,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');

CREATE TABLE herika_compat.prompts (
    prompt_key character varying(128) PRIMARY KEY,
    default_prompt text NOT NULL,
    custom_prompt text,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX idx_prompts_prompt_key_unique ON herika_compat.prompts USING btree (prompt_key);

CREATE TABLE herika_compat.bio_templates (
    npc_name character varying(128) PRIMARY KEY,
    oghma_knowledge_tags text,
    core text,
    npc_static_bio text,
    appearance text,
    personality text,
    relationships text,
    occupation text,
    skills text,
    speechstyle text,
    goals text,
    voiceid text,
    gender text,
    race text,
    refid text
);
CREATE TABLE herika_compat.bio_templates_custom (LIKE herika_compat.bio_templates INCLUDING ALL);
CREATE VIEW herika_compat.combined_bio_templates AS
SELECT c.* FROM herika_compat.bio_templates_custom c
UNION ALL
SELECT b.* FROM herika_compat.bio_templates b
LEFT JOIN herika_compat.bio_templates_custom c ON b.npc_name = c.npc_name
WHERE c.npc_name IS NULL;

CREATE TABLE herika_compat.speech (
    sess character varying(1024), speaker text, speech text, location text, listener text, topic text,
    localts bigint NOT NULL, gamets bigint NOT NULL, ts bigint, rowid bigint NOT NULL,
    companions text, audios text, mood text, emotion text, emotion_intensity text, utterance_id text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.speech_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.speech_rowid_seq OWNED BY herika_compat.speech.rowid;
ALTER TABLE ONLY herika_compat.speech ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.speech_rowid_seq'::regclass);
CREATE INDEX idx_speech_gamets_pos ON herika_compat.speech USING btree (gamets) WHERE gamets > 0;
CREATE INDEX idx_speech_listener_trgm ON herika_compat.speech USING gin (listener public.gin_trgm_ops);
CREATE INDEX idx_speech_speaker_trgm ON herika_compat.speech USING gin (speaker public.gin_trgm_ops);
CREATE INDEX idx_speech_utterance_id ON herika_compat.speech USING btree (utterance_id);
CREATE VIEW herika_compat.speech_view AS
SELECT s.*, to_timestamp(s.localts) AT TIME ZONE 'UTC' AS mw_local_datetime,
       CASE WHEN s.ts IS NULL THEN NULL ELSE to_timestamp(s.ts / 1000.0) AT TIME ZONE 'UTC' END AS mw_event_datetime
FROM herika_compat.speech s;

CREATE TABLE herika_compat.responselog (
    localts bigint NOT NULL,
    sent bigint NOT NULL,
    actor text,
    text text,
    action text,
    tag character varying(256),
    rowid bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.responselog_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.responselog_rowid_seq OWNED BY herika_compat.responselog.rowid;
ALTER TABLE ONLY herika_compat.responselog ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.responselog_rowid_seq'::regclass);

-- Companion mappings retain LORKHAN ownership and exact TES3 identities without changing Herika columns.
CREATE TABLE herika_compat.llm_connector_metadata (
    connector_id integer PRIMARY KEY REFERENCES herika_compat.core_llm_connector(id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    configuration_id uuid NOT NULL REFERENCES public.configuration_sets(configuration_id) ON DELETE CASCADE,
    configuration_revision integer NOT NULL,
    UNIQUE (configuration_id)
);
CREATE TABLE herika_compat.tts_connector_metadata (
    connector_id integer PRIMARY KEY REFERENCES herika_compat.core_tts_connector(id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    configuration_id uuid NOT NULL REFERENCES public.configuration_sets(configuration_id) ON DELETE CASCADE,
    configuration_revision integer NOT NULL,
    UNIQUE (configuration_id)
);
CREATE TABLE herika_compat.core_profile_metadata (
    core_profile_id integer PRIMARY KEY REFERENCES herika_compat.core_profiles(id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    source_core_profile_id uuid NOT NULL REFERENCES public.core_profiles(core_profile_id) ON DELETE CASCADE,
    source_revision integer NOT NULL,
    source_slot smallint,
    UNIQUE (source_core_profile_id)
);
CREATE TABLE herika_compat.npc_metadata (
    npc_id integer PRIMARY KEY REFERENCES herika_compat.core_npc_master(id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    source_profile_id uuid NOT NULL REFERENCES public.profiles(profile_id) ON DELETE CASCADE,
    source_revision integer NOT NULL,
    actor_identity jsonb NOT NULL CHECK (jsonb_typeof(actor_identity) = 'object'),
    UNIQUE (source_profile_id)
);
CREATE TABLE herika_compat.prompt_metadata (
    prompt_key character varying(128) PRIMARY KEY REFERENCES herika_compat.prompts(prompt_key) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    source_configuration_id uuid REFERENCES public.configuration_sets(configuration_id) ON DELETE SET NULL,
    source_revision integer
);
CREATE TABLE herika_compat.speech_metadata (
    rowid bigint PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    session_id uuid REFERENCES public.sessions(session_id) ON DELETE SET NULL,
    turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL,
    dialogue_message_id uuid REFERENCES public.dialogue_utterances(dialogue_message_id) ON DELETE SET NULL,
    speaker_identity jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(speaker_identity) = 'object'),
    listener_identity jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(listener_identity) = 'object'),
    audience jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(audience) = 'array'),
    delivery_state text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (dialogue_message_id)
);
CREATE TABLE herika_compat.responselog_metadata (
    rowid bigint PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    session_id uuid REFERENCES public.sessions(session_id) ON DELETE SET NULL,
    turn_id uuid,
    response_message_id uuid,
    actor_identity jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(actor_identity) = 'object'),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(payload) = 'object'),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    sent_at timestamptz,
    UNIQUE (response_message_id)
);

-- Backfill portable connector rows. API badges stay empty because provider secrets remain external.
INSERT INTO herika_compat.core_llm_connector (label,metadata,url,model,provider,driver,service)
SELECT c.name,r.content,r.content->>'endpoint',r.content->>'model',r.content->>'driver',r.content->>'driver','llm'
FROM public.configuration_sets c
JOIN public.configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
WHERE c.kind='provider' AND c.deleted_at IS NULL
ORDER BY c.created_at,c.configuration_id;
INSERT INTO herika_compat.llm_connector_metadata (connector_id,installation_id,configuration_id,configuration_revision)
SELECT h.id,c.installation_id,c.configuration_id,c.current_revision
FROM public.configuration_sets c
JOIN herika_compat.core_llm_connector h ON h.label=c.name
WHERE c.kind='provider' AND c.deleted_at IS NULL;

INSERT INTO herika_compat.core_tts_connector (driver,label,metadata,url,voice_field)
SELECT r.content->>'driver',c.name,r.content,r.content->>'endpoint','voice'
FROM public.configuration_sets c
JOIN public.configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
WHERE c.kind='tts_provider' AND c.deleted_at IS NULL
ORDER BY c.created_at,c.configuration_id;
INSERT INTO herika_compat.tts_connector_metadata (connector_id,installation_id,configuration_id,configuration_revision)
SELECT h.id,c.installation_id,c.configuration_id,c.current_revision
FROM public.configuration_sets c
JOIN herika_compat.core_tts_connector h ON h.label=c.name
WHERE c.kind='tts_provider' AND c.deleted_at IS NULL;

INSERT INTO herika_compat.core_profiles (
    label,default_npc,default_narrator,tts_connector_id,llm_primary_id,llm_secondary_id,
    llm_tertiary_id,llm_quaternary_id,llm_fallback_id,metadata,slot,prompt
)
SELECT c.label,CASE WHEN c.default_npc THEN '1' ELSE '0' END,'0',tts.connector_id,
       primary_llm.connector_id,fast.connector_id,powerful.connector_id,experimental.connector_id,
         fallback_llm.connector_id,r.content,
         CASE WHEN c.slot IS NULL OR row_number() OVER (
             PARTITION BY c.slot ORDER BY c.created_at,c.core_profile_id
         )=1 THEN c.slot END,
         r.content->>'prompt'
FROM public.core_profiles c
JOIN public.core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision
LEFT JOIN herika_compat.tts_connector_metadata tts ON tts.configuration_id=NULLIF(r.content#>>'{routing,tts_configuration_id}','')::uuid
LEFT JOIN herika_compat.llm_connector_metadata primary_llm ON primary_llm.configuration_id=NULLIF(r.content#>>'{routing,llm_configuration_id}','')::uuid
LEFT JOIN herika_compat.llm_connector_metadata fast ON fast.configuration_id=NULLIF(r.content#>>'{routing,llm_fast_configuration_id}','')::uuid
LEFT JOIN herika_compat.llm_connector_metadata powerful ON powerful.configuration_id=NULLIF(r.content#>>'{routing,llm_powerful_configuration_id}','')::uuid
LEFT JOIN herika_compat.llm_connector_metadata experimental ON experimental.configuration_id=NULLIF(r.content#>>'{routing,llm_experimental_configuration_id}','')::uuid
LEFT JOIN herika_compat.llm_connector_metadata fallback_llm ON fallback_llm.configuration_id=NULLIF(r.content#>>'{routing,llm_fallback_configuration_id}','')::uuid
WHERE c.deleted_at IS NULL
ORDER BY c.created_at,c.core_profile_id;
INSERT INTO herika_compat.core_profile_metadata (core_profile_id,installation_id,source_core_profile_id,source_revision,source_slot)
SELECT h.id,c.installation_id,c.core_profile_id,c.current_revision,c.slot
FROM public.core_profiles c
JOIN herika_compat.core_profiles h ON h.label=c.label AND h.slot IS NOT DISTINCT FROM c.slot
WHERE c.deleted_at IS NULL;

INSERT INTO herika_compat.core_npc_master (
    npc_name,npc_favorite,lock_profile,prompt_head,npc_static_bio,oghma_knowledge_tags,emote_moods,
    personality,relationships,occupation,appearance,skills,speechstyle,goals,voiceid,metadata,gender,
    race,refid,profile_id,dynamic_profile,extended_data,md5,core,base,tags
)
SELECT p.name,
       CASE WHEN COALESCE((r.content#>>'{management,favorite}')::boolean,false) THEN 1 ELSE 0 END,
       CASE WHEN COALESCE((r.content#>>'{management,locked}')::boolean,false) THEN 1 ELSE 0 END,
       r.content->>'prompt_head',r.content->>'biography',r.content->>'oghma_knowledge_tags',r.content->>'emote_moods',
       r.content->>'personality',r.content->>'relationships',r.content->>'occupation',r.content->>'appearance',
       r.content->>'skills',r.content->>'speech_style',r.content->>'goals',r.content#>>'{voice,id}',
       p.actor_identity,r.content->>'gender',r.content->>'race',left(p.actor_identity->>'record_id',16),
       cpm.core_profile_id,0,jsonb_build_object('actor_identity',p.actor_identity,'lorkhan_profile',r.content),
       md5(r.content::text),r.content->>'core',p.actor_identity->>'content_file',r.content->>'tags'
FROM public.profiles p
JOIN public.profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
LEFT JOIN herika_compat.core_profile_metadata cpm ON cpm.source_core_profile_id=p.core_profile_id
WHERE p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor')='npc'
ORDER BY p.created_at,p.profile_id;
INSERT INTO herika_compat.npc_metadata (npc_id,installation_id,source_profile_id,source_revision,actor_identity)
SELECT h.id,p.installation_id,p.profile_id,p.current_revision,p.actor_identity
FROM public.profiles p JOIN herika_compat.core_npc_master h ON h.npc_name=p.name
WHERE p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor')='npc';

INSERT INTO herika_compat.core_npc_master_history (
    npc_id,npc_name,npc_favorite,lock_profile,prompt_head,npc_static_bio,oghma_knowledge_tags,
    emote_moods,personality,relationships,occupation,appearance,skills,speechstyle,goals,voiceid,
    metadata,gender,race,refid,profile_id,dynamic_profile,extended_data,md5,created,core,base,tags
)
SELECT nm.npc_id,p.name,
       CASE WHEN COALESCE((r.content#>>'{management,favorite}')::boolean,false) THEN 1 ELSE 0 END,
       CASE WHEN COALESCE((r.content#>>'{management,locked}')::boolean,false) THEN 1 ELSE 0 END,
       r.content->>'prompt_head',r.content->>'biography',r.content->>'oghma_knowledge_tags',r.content->>'emote_moods',
       r.content->>'personality',r.content->>'relationships',r.content->>'occupation',r.content->>'appearance',
       r.content->>'skills',r.content->>'speech_style',r.content->>'goals',r.content#>>'{voice,id}',p.actor_identity,
       r.content->>'gender',r.content->>'race',left(p.actor_identity->>'record_id',16),cpm.core_profile_id,0,
       jsonb_build_object('actor_identity',p.actor_identity,'lorkhan_profile',r.content,'lorkhan_revision',r.revision),
       md5(r.content::text),r.created_at AT TIME ZONE 'UTC',r.content->>'core',p.actor_identity->>'content_file',r.content->>'tags'
FROM public.profiles p
JOIN public.profile_revisions r ON r.profile_id=p.profile_id
JOIN herika_compat.npc_metadata nm ON nm.source_profile_id=p.profile_id
LEFT JOIN herika_compat.core_profile_metadata cpm ON cpm.source_core_profile_id=p.core_profile_id
WHERE COALESCE(p.actor_identity->>'kind','actor')='npc'
ORDER BY p.profile_id,r.revision;

INSERT INTO herika_compat.core_player (id,value)
SELECT e.key,CASE WHEN jsonb_typeof(e.value)='string' THEN e.value#>>'{}' ELSE e.value::text END
FROM public.profiles p
JOIN public.profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
CROSS JOIN LATERAL jsonb_each(r.content) e
WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='player'
ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value;
INSERT INTO herika_compat.core_narrator (id,value)
SELECT e.key,CASE WHEN jsonb_typeof(e.value)='string' THEN e.value#>>'{}' ELSE e.value::text END
FROM public.profiles p
JOIN public.profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision
CROSS JOIN LATERAL jsonb_each(r.content) e
WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='narrator'
ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value;

INSERT INTO herika_compat.general_settings (id,value,description,updated_at)
SELECT 'lorkhan.'||e.key,e.value::text,'LORKHAN Global Settings '||e.key,r.created_at AT TIME ZONE 'UTC'
FROM public.configuration_sets c
JOIN public.configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
CROSS JOIN LATERAL jsonb_each(r.content) e
WHERE c.kind='global_settings' AND c.deleted_at IS NULL;

INSERT INTO herika_compat.prompts (prompt_key,default_prompt,custom_prompt,description,created_at,updated_at)
SELECT prompt_key,default_prompt,custom_prompt,description,created_at AT TIME ZONE 'UTC',updated_at AT TIME ZONE 'UTC'
FROM public.prompts;
INSERT INTO herika_compat.prompt_metadata (prompt_key,installation_id,source_configuration_id,source_revision)
SELECT prompt_key,installation_id,source_configuration_id,source_revision FROM public.prompts;

INSERT INTO herika_compat.bio_templates_custom
SELECT npc_name,oghma_knowledge_tags,core,npc_static_bio,appearance,personality,relationships,
       occupation,skills,speechstyle,goals,voiceid,gender,race,refid
FROM herika_compat.core_npc_master;

INSERT INTO herika_compat.speech (
    rowid,sess,speaker,speech,location,listener,topic,localts,gamets,ts,companions,audios,
    mood,emotion,emotion_intensity,utterance_id
)
SELECT rowid,sess,speaker,speech,location,listener,topic,localts,gamets,ts,companions,audios,
       NULL,NULL,NULL,utterance_id
FROM public.speech ORDER BY rowid;
INSERT INTO herika_compat.speech_metadata (
    rowid,installation_id,playthrough_id,session_id,turn_id,dialogue_message_id,
    speaker_identity,listener_identity,audience,delivery_state,created_at
)
SELECT rowid,installation_id,playthrough_id,session_id,turn_id,dialogue_message_id,
       speaker_identity,listener_identity,audience,delivery_state,created_at
FROM public.speech;
SELECT setval('herika_compat.speech_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.speech),1),
              EXISTS(SELECT 1 FROM herika_compat.speech));

INSERT INTO herika_compat.responselog (rowid,localts,sent,actor,text,action,tag)
SELECT rowid,localts,sent,actor,text,action,tag FROM public.responselog ORDER BY rowid;
INSERT INTO herika_compat.responselog_metadata (
    rowid,installation_id,playthrough_id,session_id,turn_id,response_message_id,
    actor_identity,payload,created_at,sent_at
)
SELECT rowid,installation_id,playthrough_id,session_id,turn_id,response_message_id,
       actor_identity,payload,created_at,sent_at
FROM public.responselog;
SELECT setval('herika_compat.responselog_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.responselog),1),
              EXISTS(SELECT 1 FROM herika_compat.responselog));
