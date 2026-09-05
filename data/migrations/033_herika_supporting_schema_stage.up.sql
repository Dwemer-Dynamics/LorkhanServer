-- Exact active Herika supporting tables. Background Life, autonomous commitments,
-- and visual/ITT producers remain functionally excluded and therefore stay empty.

CREATE TABLE herika_compat.bgl_history (
    rowid bigint GENERATED ALWAYS AS IDENTITY (
        SEQUENCE NAME herika_compat.bgl_history_rowid_seq
        START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1
    ),
    npc character varying,
    gamets bigint,
    ts bigint,
    localts bigint,
    data character varying,
    category text
);
ALTER TABLE ONLY herika_compat.bgl_history ADD CONSTRAINT bgl_history_pkey PRIMARY KEY (rowid);

CREATE TABLE herika_compat.core_faction_politics_development (
    id bigint GENERATED ALWAYS AS IDENTITY (
        SEQUENCE NAME herika_compat.core_faction_politics_development_id_seq
        START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1
    ),
    title text NOT NULL,
    summary text NOT NULL,
    faction_keys jsonb DEFAULT '[]'::jsonb NOT NULL,
    status text DEFAULT 'active'::text NOT NULL,
    gamets bigint DEFAULT 0 NOT NULL,
    created_at bigint DEFAULT 0 NOT NULL,
    updated_at bigint DEFAULT 0 NOT NULL
);
ALTER TABLE ONLY herika_compat.core_faction_politics_development
    ADD CONSTRAINT core_faction_politics_development_pkey PRIMARY KEY (id);
CREATE INDEX faction_politics_development_active_idx
    ON herika_compat.core_faction_politics_development USING btree (status,gamets DESC,id DESC);

CREATE TABLE herika_compat.core_faction_politics_relation (
    faction_a_key text NOT NULL,
    faction_a_name text NOT NULL,
    faction_b_key text NOT NULL,
    faction_b_name text NOT NULL,
    stance text DEFAULT 'neutral'::text NOT NULL,
    score smallint DEFAULT 0 NOT NULL,
    summary text DEFAULT ''::text NOT NULL,
    gamets bigint DEFAULT 0 NOT NULL,
    created_at bigint DEFAULT 0 NOT NULL,
    updated_at bigint DEFAULT 0 NOT NULL,
    CONSTRAINT core_faction_politics_relation_check CHECK (faction_a_key<>faction_b_key),
    CONSTRAINT core_faction_politics_relation_score_check CHECK (score>=(-100) AND score<=100)
);
ALTER TABLE ONLY herika_compat.core_faction_politics_relation
    ADD CONSTRAINT core_faction_politics_relation_pkey PRIMARY KEY (faction_a_key,faction_b_key);

CREATE TABLE herika_compat.core_faction_politics_state (
    faction_key text NOT NULL,
    faction_name text NOT NULL,
    status text DEFAULT 'stable'::text NOT NULL,
    influence smallint DEFAULT 0 NOT NULL,
    agenda text DEFAULT ''::text NOT NULL,
    summary text DEFAULT ''::text NOT NULL,
    gamets bigint DEFAULT 0 NOT NULL,
    created_at bigint DEFAULT 0 NOT NULL,
    updated_at bigint DEFAULT 0 NOT NULL,
    CONSTRAINT core_faction_politics_state_influence_check CHECK (influence>=(-100) AND influence<=100)
);
ALTER TABLE ONLY herika_compat.core_faction_politics_state
    ADD CONSTRAINT core_faction_politics_state_pkey PRIMARY KEY (faction_key);

CREATE TABLE herika_compat.faction_vanilla (name text,formid text);

CREATE TABLE herika_compat.market_cache (
    baseid character varying(128) NOT NULL,
    name text,
    description text,
    plugin text NOT NULL,
    enchantment integer,
    price numeric
);
ALTER TABLE ONLY herika_compat.market_cache ADD CONSTRAINT market_cache_pk PRIMARY KEY (baseid,plugin);

CREATE TABLE herika_compat.master_packages (
    mod text NOT NULL,
    formid text NOT NULL,
    name text NOT NULL,
    start text,
    change text,
    "end" text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.master_packages ADD CONSTRAINT master_packages_pk PRIMARY KEY (formid);
CREATE UNIQUE INDEX master_packages_mod_formid_idx ON herika_compat.master_packages USING btree (mod,formid);

CREATE TABLE herika_compat.npc_commitments (
    id bigint NOT NULL,
    actor_name text NOT NULL,
    commitment_type character varying(32) DEFAULT 'other'::character varying NOT NULL,
    subject text NOT NULL,
    counterparty text DEFAULT ''::text NOT NULL,
    location_name text DEFAULT ''::text NOT NULL,
    status character varying(16) DEFAULT 'scheduled'::character varying NOT NULL,
    created_gamets bigint NOT NULL,
    due_gamets bigint NOT NULL,
    resolved_gamets bigint,
    outcome text DEFAULT ''::text NOT NULL,
    payload_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    repeat_interval_gamets bigint DEFAULT 0 NOT NULL,
    occurrence_count integer DEFAULT 0 NOT NULL,
    last_resolved_gamets bigint,
    CONSTRAINT npc_commitments_status_check CHECK (
        status::text=ANY(ARRAY['scheduled','due','completed','failed','cancelled']::text[])
    )
);
CREATE SEQUENCE herika_compat.npc_commitments_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.npc_commitments_id_seq OWNED BY herika_compat.npc_commitments.id;
ALTER TABLE ONLY herika_compat.npc_commitments ALTER COLUMN id SET DEFAULT nextval('herika_compat.npc_commitments_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.npc_commitments ADD CONSTRAINT npc_commitments_pkey PRIMARY KEY (id);
CREATE INDEX idx_npc_commitments_actor_status_due ON herika_compat.npc_commitments USING btree (lower(actor_name),status,due_gamets);
CREATE INDEX idx_npc_commitments_timeline ON herika_compat.npc_commitments USING btree (created_gamets,resolved_gamets);

CREATE TABLE herika_compat.npc_profile_backup (
    name text,
    data text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');

CREATE TABLE herika_compat.oghma_context_rule (
    id bigint NOT NULL,
    label text NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    priority integer DEFAULT 100 NOT NULL,
    selector_type text DEFAULT 'topic'::text NOT NULL,
    selector_value text NOT NULL,
    conditions jsonb DEFAULT '{}'::jsonb NOT NULL,
    max_articles smallint DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT oghma_context_rule_max_articles_check CHECK (max_articles>=1 AND max_articles<=5),
    CONSTRAINT oghma_context_rule_selector_type_check CHECK (selector_type=ANY(ARRAY['topic','tag','category']))
);
CREATE SEQUENCE herika_compat.oghma_context_rule_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.oghma_context_rule_id_seq OWNED BY herika_compat.oghma_context_rule.id;
ALTER TABLE ONLY herika_compat.oghma_context_rule ALTER COLUMN id SET DEFAULT nextval('herika_compat.oghma_context_rule_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.oghma_context_rule ADD CONSTRAINT oghma_context_rule_pkey PRIMARY KEY (id);
CREATE INDEX idx_oghma_context_rule_active ON herika_compat.oghma_context_rule USING btree (enabled,priority,id);

CREATE TABLE herika_compat.visual_context (
    id bigint NOT NULL,
    subject_type text DEFAULT 'scene'::text NOT NULL,
    subject_key text NOT NULL,
    subject_name text DEFAULT ''::text NOT NULL,
    plugin text DEFAULT ''::text NOT NULL,
    baseid text DEFAULT ''::text NOT NULL,
    refid text DEFAULT ''::text NOT NULL,
    cell_id text DEFAULT ''::text NOT NULL,
    location_name text DEFAULT ''::text NOT NULL,
    image_path text DEFAULT ''::text NOT NULL,
    image_sha256 text DEFAULT ''::text NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    perspective text DEFAULT 'first_person'::text NOT NULL,
    provider text DEFAULT ''::text NOT NULL,
    model text DEFAULT ''::text NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    locked boolean DEFAULT false NOT NULL,
    active boolean DEFAULT true NOT NULL,
    user_edited boolean DEFAULT false NOT NULL,
    captured_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);
CREATE SEQUENCE herika_compat.visual_context_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.visual_context_id_seq OWNED BY herika_compat.visual_context.id;
ALTER TABLE ONLY herika_compat.visual_context ALTER COLUMN id SET DEFAULT nextval('herika_compat.visual_context_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.visual_context ADD CONSTRAINT visual_context_pkey PRIMARY KEY (id);
CREATE INDEX visual_context_image_idx ON herika_compat.visual_context USING btree (image_sha256);
CREATE INDEX visual_context_location_idx ON herika_compat.visual_context USING btree (lower(location_name),active,captured_at DESC);
CREATE INDEX visual_context_subject_idx ON herika_compat.visual_context USING btree (subject_type,subject_key,active,captured_at DESC);

-- Reuse known OpenMW descriptions and faction identities in the exact Herika support tables.
INSERT INTO herika_compat.market_cache (baseid,name,description,plugin,enchantment,price)
SELECT baseid,name,description,plugin,NULL,NULL FROM herika_compat.combined_descriptions;
INSERT INTO herika_compat.faction_vanilla (name,formid)
SELECT name,formid FROM herika_compat.factions ORDER BY formid;
