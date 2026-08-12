-- Exact Herika Skyrim quest/SNQE schema retained for presentation compatibility.
-- ALMSIVI uses the Morrowind journal tables from migration 029; these producers are
-- Not Applicable and no Skyrim quest engine, action outbox, or autonomous worker runs.

CREATE TABLE herika_compat.quest_asset_packs (
    pack_key text NOT NULL,label text NOT NULL,game text DEFAULT 'SkyrimSE'::text NOT NULL,
    manifest_version text DEFAULT '1'::text NOT NULL,required_plugins_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    source text DEFAULT ''::text NOT NULL,manifest_hash text DEFAULT ''::text NOT NULL,
    active boolean DEFAULT true NOT NULL,note text DEFAULT ''::text NOT NULL,
    imported_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT quest_asset_packs_plugins_is_array CHECK (jsonb_typeof(required_plugins_json)='array')
);
ALTER TABLE ONLY herika_compat.quest_asset_packs ADD CONSTRAINT quest_asset_packs_pkey PRIMARY KEY (pack_key);

CREATE TABLE herika_compat.quest_assets (
    source_pack text NOT NULL,stable_ref text NOT NULL,signature character varying(4) NOT NULL,
    editor_id text DEFAULT ''::text NOT NULL,display_name text DEFAULT ''::text NOT NULL,
    source_plugin text NOT NULL,winning_plugin text DEFAULT ''::text NOT NULL,
    metadata_json jsonb DEFAULT '{}'::jsonb NOT NULL,safety_status text DEFAULT 'review'::text NOT NULL,
    active boolean DEFAULT true NOT NULL,created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT quest_assets_metadata_is_object CHECK (jsonb_typeof(metadata_json)='object'),
    CONSTRAINT quest_assets_safety_status CHECK (safety_status=ANY(ARRAY['approved','review','rejected'])),
    CONSTRAINT quest_assets_stable_ref_format CHECK (stable_ref~'^[^|]+\|[0-9A-Fa-f]{8}$')
);
ALTER TABLE ONLY herika_compat.quest_assets ADD CONSTRAINT quest_assets_pkey PRIMARY KEY (source_pack,stable_ref);
ALTER TABLE ONLY herika_compat.quest_assets ADD CONSTRAINT quest_assets_source_pack_fkey
    FOREIGN KEY (source_pack) REFERENCES herika_compat.quest_asset_packs(pack_key) ON DELETE CASCADE;
CREATE INDEX quest_assets_safety_idx ON herika_compat.quest_assets USING btree (safety_status,active);
CREATE INDEX quest_assets_signature_idx ON herika_compat.quest_assets USING btree (signature);
CREATE INDEX quest_assets_source_pack_idx ON herika_compat.quest_assets USING btree (source_pack);

CREATE TABLE herika_compat.quest_asset_groups (
    dataset_name text NOT NULL,group_key text NOT NULL,label text DEFAULT ''::text NOT NULL,
    description text DEFAULT ''::text NOT NULL,selection_policy_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    source_pack text NOT NULL,active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT quest_asset_groups_dataset CHECK (dataset_name=ANY(ARRAY['item_types','npc_templates','npc_own_templates','outfit','weapons'])),
    CONSTRAINT quest_asset_groups_key_format CHECK (group_key~'^[a-z0-9_]+$'),
    CONSTRAINT quest_asset_groups_policy_is_object CHECK (jsonb_typeof(selection_policy_json)='object')
);
ALTER TABLE ONLY herika_compat.quest_asset_groups ADD CONSTRAINT quest_asset_groups_pkey PRIMARY KEY (source_pack,dataset_name,group_key);
ALTER TABLE ONLY herika_compat.quest_asset_groups ADD CONSTRAINT quest_asset_groups_source_pack_fkey
    FOREIGN KEY (source_pack) REFERENCES herika_compat.quest_asset_packs(pack_key) ON DELETE CASCADE;
CREATE INDEX quest_asset_groups_pack_idx ON herika_compat.quest_asset_groups USING btree (source_pack);

CREATE TABLE herika_compat.quest_asset_group_members (
    dataset_name text NOT NULL,group_key text NOT NULL,stable_ref text NOT NULL,
    weight integer DEFAULT 1 NOT NULL,constraints_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    note text DEFAULT ''::text NOT NULL,source_pack text NOT NULL,active boolean DEFAULT true NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT quest_asset_group_members_constraints_is_object CHECK (jsonb_typeof(constraints_json)='object'),
    CONSTRAINT quest_asset_group_members_weight CHECK (weight>=1 AND weight<=100)
);
ALTER TABLE ONLY herika_compat.quest_asset_group_members ADD CONSTRAINT quest_asset_group_members_pkey
    PRIMARY KEY (source_pack,dataset_name,group_key,stable_ref);
ALTER TABLE ONLY herika_compat.quest_asset_group_members ADD CONSTRAINT quest_asset_group_members_source_pack_dataset_name_group_k_fkey
    FOREIGN KEY (source_pack,dataset_name,group_key)
    REFERENCES herika_compat.quest_asset_groups(source_pack,dataset_name,group_key) ON DELETE CASCADE;
ALTER TABLE ONLY herika_compat.quest_asset_group_members ADD CONSTRAINT quest_asset_group_members_source_pack_fkey
    FOREIGN KEY (source_pack) REFERENCES herika_compat.quest_asset_packs(pack_key) ON DELETE CASCADE;
ALTER TABLE ONLY herika_compat.quest_asset_group_members ADD CONSTRAINT quest_asset_group_members_source_pack_stable_ref_fkey
    FOREIGN KEY (source_pack,stable_ref) REFERENCES herika_compat.quest_assets(source_pack,stable_ref) ON DELETE CASCADE;
CREATE INDEX quest_asset_members_group_idx ON herika_compat.quest_asset_group_members USING btree (dataset_name,group_key,active);
CREATE INDEX quest_asset_members_pack_idx ON herika_compat.quest_asset_group_members USING btree (source_pack);

CREATE TABLE herika_compat.quest_asset_imports (
    id bigint NOT NULL,pack_key text NOT NULL,manifest_version text NOT NULL,manifest_hash text NOT NULL,
    source_file text DEFAULT ''::text NOT NULL,asset_count integer DEFAULT 0 NOT NULL,
    group_count integer DEFAULT 0 NOT NULL,member_count integer DEFAULT 0 NOT NULL,
    imported_at timestamp without time zone DEFAULT now() NOT NULL
);
CREATE SEQUENCE herika_compat.quest_asset_imports_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.quest_asset_imports_id_seq OWNED BY herika_compat.quest_asset_imports.id;
ALTER TABLE ONLY herika_compat.quest_asset_imports ALTER COLUMN id SET DEFAULT nextval('herika_compat.quest_asset_imports_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.quest_asset_imports ADD CONSTRAINT quest_asset_imports_pkey PRIMARY KEY (id);

CREATE TABLE herika_compat.quest_item_types (
    type_key text NOT NULL,active boolean DEFAULT true NOT NULL,note text,
    created_at timestamp with time zone DEFAULT now(),updated_at timestamp with time zone DEFAULT now(),
    formids_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT quest_item_types_formids_json_is_array CHECK (formids_json IS NULL OR jsonb_typeof(formids_json)='array')
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.quest_item_types ADD CONSTRAINT quest_item_types_pk PRIMARY KEY (type_key);
CREATE INDEX idx_quest_item_types_active ON herika_compat.quest_item_types USING btree (active);

CREATE TABLE herika_compat.quest_npc_own_templates (
    template_key text NOT NULL,active boolean DEFAULT true NOT NULL,note text,
    created_at timestamp with time zone DEFAULT now(),updated_at timestamp with time zone DEFAULT now(),
    formids_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT quest_npc_own_templates_formids_json_is_array CHECK (formids_json IS NULL OR jsonb_typeof(formids_json)='array')
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.quest_npc_own_templates ADD CONSTRAINT quest_npc_own_templates_pk PRIMARY KEY (template_key);
CREATE INDEX idx_quest_npc_own_templates_active ON herika_compat.quest_npc_own_templates USING btree (active);

CREATE TABLE herika_compat.quest_npc_templates (
    template_key text NOT NULL,active boolean DEFAULT true NOT NULL,note text,
    created_at timestamp with time zone DEFAULT now(),updated_at timestamp with time zone DEFAULT now(),
    formids_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT quest_npc_templates_formids_json_is_array CHECK (formids_json IS NULL OR jsonb_typeof(formids_json)='array')
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.quest_npc_templates ADD CONSTRAINT quest_npc_templates_pk PRIMARY KEY (template_key);
CREATE INDEX idx_quest_npc_templates_active ON herika_compat.quest_npc_templates USING btree (active);

CREATE TABLE herika_compat.quest_outfits (
    class_key text NOT NULL,active boolean DEFAULT true NOT NULL,note text,
    created_at timestamp with time zone DEFAULT now(),updated_at timestamp with time zone DEFAULT now(),
    formids_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    CONSTRAINT quest_outfits_formids_json_is_array CHECK (formids_json IS NULL OR jsonb_typeof(formids_json)='array')
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.quest_outfits ADD CONSTRAINT quest_outfits_pk PRIMARY KEY (class_key);
CREATE INDEX idx_quest_outfits_active ON herika_compat.quest_outfits USING btree (active);

CREATE TABLE herika_compat.quest_weapons (
    class_key text NOT NULL,formids_json jsonb DEFAULT '[]'::jsonb NOT NULL,
    active boolean DEFAULT true NOT NULL,note text DEFAULT ''::text NOT NULL,
    created_at timestamp without time zone DEFAULT now() NOT NULL,
    updated_at timestamp without time zone DEFAULT now() NOT NULL,
    CONSTRAINT quest_weapons_formids_json_is_array CHECK (jsonb_typeof(formids_json)='array')
);
ALTER TABLE ONLY herika_compat.quest_weapons ADD CONSTRAINT quest_weapons_pkey PRIMARY KEY (class_key);

CREATE OR REPLACE FUNCTION herika_compat.chim_touch_updated_at()
RETURNS trigger LANGUAGE plpgsql AS $function$
BEGIN
    NEW.updated_at=now();
    RETURN NEW;
END
$function$;

CREATE TABLE herika_compat.skyrim_quest_definitions (
    quest_key text NOT NULL,quest_editor_id text NOT NULL,title text NOT NULL,
    source_plugin text,source_form_id text,source_path text,skeleton jsonb DEFAULT '{}'::jsonb NOT NULL,
    active boolean DEFAULT true NOT NULL,created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE ONLY herika_compat.skyrim_quest_definitions ADD CONSTRAINT skyrim_quest_definitions_pkey PRIMARY KEY (quest_key);
CREATE INDEX idx_skyrim_quest_definitions_editor_id ON herika_compat.skyrim_quest_definitions USING btree (quest_editor_id);
CREATE TRIGGER trg_skyrim_quest_definitions_updated_at BEFORE UPDATE ON herika_compat.skyrim_quest_definitions
FOR EACH ROW EXECUTE FUNCTION herika_compat.chim_touch_updated_at();

CREATE TABLE herika_compat.skyrim_quest_instances (
    quest_key text NOT NULL,quest_editor_id text NOT NULL,run_state text DEFAULT 'inactive'::text NOT NULL,
    current_stage integer,last_gamets bigint,state_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,updated_at timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE ONLY herika_compat.skyrim_quest_instances ADD CONSTRAINT skyrim_quest_instances_pkey PRIMARY KEY (quest_key);
ALTER TABLE ONLY herika_compat.skyrim_quest_instances ADD CONSTRAINT skyrim_quest_instances_quest_key_fkey
    FOREIGN KEY (quest_key) REFERENCES herika_compat.skyrim_quest_definitions(quest_key) ON DELETE CASCADE;
CREATE INDEX idx_skyrim_quest_instances_run_state ON herika_compat.skyrim_quest_instances USING btree (run_state);
CREATE TRIGGER trg_skyrim_quest_instances_updated_at BEFORE UPDATE ON herika_compat.skyrim_quest_instances
FOR EACH ROW EXECUTE FUNCTION herika_compat.chim_touch_updated_at();

CREATE TABLE herika_compat.skyrim_quest_beat_state (
    quest_key text NOT NULL,beat_id text NOT NULL,fired boolean DEFAULT false NOT NULL,
    fired_order integer,fired_gamets bigint,evidence_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,updated_at timestamp with time zone DEFAULT now() NOT NULL
);
ALTER TABLE ONLY herika_compat.skyrim_quest_beat_state ADD CONSTRAINT skyrim_quest_beat_state_pkey PRIMARY KEY (quest_key,beat_id);
ALTER TABLE ONLY herika_compat.skyrim_quest_beat_state ADD CONSTRAINT skyrim_quest_beat_state_quest_key_fkey
    FOREIGN KEY (quest_key) REFERENCES herika_compat.skyrim_quest_instances(quest_key) ON DELETE CASCADE;
CREATE TRIGGER trg_skyrim_quest_beat_state_updated_at BEFORE UPDATE ON herika_compat.skyrim_quest_beat_state
FOR EACH ROW EXECUTE FUNCTION herika_compat.chim_touch_updated_at();

CREATE TABLE herika_compat.skyrim_quest_events (
    id bigint NOT NULL,quest_key text,event_type text NOT NULL,event_source text,npc_name text,
    location_name text,gamets bigint,payload_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);
CREATE SEQUENCE herika_compat.skyrim_quest_events_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.skyrim_quest_events_id_seq OWNED BY herika_compat.skyrim_quest_events.id;
ALTER TABLE ONLY herika_compat.skyrim_quest_events ALTER COLUMN id SET DEFAULT nextval('herika_compat.skyrim_quest_events_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.skyrim_quest_events ADD CONSTRAINT skyrim_quest_events_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.skyrim_quest_events ADD CONSTRAINT skyrim_quest_events_quest_key_fkey
    FOREIGN KEY (quest_key) REFERENCES herika_compat.skyrim_quest_definitions(quest_key) ON DELETE SET NULL;
CREATE INDEX idx_skyrim_quest_events_gamets ON herika_compat.skyrim_quest_events USING btree (gamets DESC);
CREATE INDEX idx_skyrim_quest_events_quest_created ON herika_compat.skyrim_quest_events USING btree (quest_key,created_at DESC);
CREATE INDEX idx_skyrim_quest_events_type_created ON herika_compat.skyrim_quest_events USING btree (event_type,created_at DESC);

CREATE TABLE herika_compat.skyrim_quest_action_outbox (
    id bigint NOT NULL,quest_key text NOT NULL,beat_id text,action_type text NOT NULL,
    action_gamets bigint,payload_json jsonb DEFAULT '{}'::jsonb NOT NULL,status text DEFAULT 'pending'::text NOT NULL,
    result_json jsonb DEFAULT '{}'::jsonb NOT NULL,created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,applied_at timestamp with time zone
);
CREATE SEQUENCE herika_compat.skyrim_quest_action_outbox_id_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.skyrim_quest_action_outbox_id_seq OWNED BY herika_compat.skyrim_quest_action_outbox.id;
ALTER TABLE ONLY herika_compat.skyrim_quest_action_outbox ALTER COLUMN id SET DEFAULT nextval('herika_compat.skyrim_quest_action_outbox_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.skyrim_quest_action_outbox ADD CONSTRAINT skyrim_quest_action_outbox_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.skyrim_quest_action_outbox ADD CONSTRAINT skyrim_quest_action_outbox_quest_key_fkey
    FOREIGN KEY (quest_key) REFERENCES herika_compat.skyrim_quest_instances(quest_key) ON DELETE CASCADE;
CREATE INDEX idx_skyrim_quest_action_outbox_gamets ON herika_compat.skyrim_quest_action_outbox USING btree (action_gamets DESC);
CREATE INDEX idx_skyrim_quest_action_outbox_status_created ON herika_compat.skyrim_quest_action_outbox USING btree (status,created_at);
CREATE TRIGGER trg_skyrim_quest_action_outbox_updated_at BEFORE UPDATE ON herika_compat.skyrim_quest_action_outbox
FOR EACH ROW EXECUTE FUNCTION herika_compat.chim_touch_updated_at();

CREATE TABLE herika_compat.sneq_quests (
    quest_id text NOT NULL,code text NOT NULL,quest_run_state text NOT NULL,
    quest_data jsonb DEFAULT '{}'::jsonb,created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now(),title text,stage text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.sneq_quests ADD CONSTRAINT sneq_quests_pkey PRIMARY KEY (quest_id);

CREATE TABLE herika_compat.sneq_quests_saved (
    quest_id text,code text,quest_run_state text,quest_data jsonb,created_at timestamp with time zone,
    updated_at timestamp with time zone,title text,stage text,gamets bigint,state text,history_id integer NOT NULL
);
CREATE SEQUENCE herika_compat.sneq_quests_saved_history_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.sneq_quests_saved_history_id_seq OWNED BY herika_compat.sneq_quests_saved.history_id;
ALTER TABLE ONLY herika_compat.sneq_quests_saved ALTER COLUMN history_id SET DEFAULT nextval('herika_compat.sneq_quests_saved_history_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.sneq_quests_saved ADD CONSTRAINT sneq_quests_saved_id PRIMARY KEY (history_id);
