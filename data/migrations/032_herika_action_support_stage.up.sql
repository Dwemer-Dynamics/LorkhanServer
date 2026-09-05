-- Exact Herika action, animation, import, translation, and supporting profile tables.
-- Unsupported Skyrim data remains empty; LORKHAN's negotiated OpenMW actions project
-- into the custom action layer used by the copied Herika UI.

CREATE TABLE herika_compat.animations (
    mood character varying(128) NOT NULL,
    animations character varying(65535),
    npc character varying(256)
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE TABLE herika_compat.animations_custom (
    mood character varying(128) NOT NULL,
    animations character varying(65535),
    npc character varying(256)
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE VIEW herika_compat.combined_animations AS
SELECT c.mood,c.animations,c.npc FROM herika_compat.animations_custom c
UNION ALL
SELECT t.mood,t.animations,t.npc
FROM herika_compat.animations t
LEFT JOIN herika_compat.animations_custom c ON t.mood=c.mood
WHERE c.mood IS NULL;

CREATE TABLE herika_compat.core_action (
    id integer NOT NULL,
    code_name character varying(128) NOT NULL,
    action_name character varying(255) NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    return_message text DEFAULT ''::text NOT NULL,
    available_to_npc boolean DEFAULT false NOT NULL,
    available_to_followers boolean DEFAULT false NOT NULL,
    available_to_narrator boolean DEFAULT false NOT NULL,
    is_activated boolean DEFAULT true NOT NULL,
    parameters_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    game_function boolean DEFAULT true NOT NULL,
    import_version bigint DEFAULT 0 NOT NULL,
    script_proxy_program jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.core_action_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.core_action_id_seq OWNED BY herika_compat.core_action.id;
ALTER TABLE ONLY herika_compat.core_action ALTER COLUMN id SET DEFAULT nextval('herika_compat.core_action_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_action ADD CONSTRAINT core_action_code_name_key UNIQUE (code_name);
ALTER TABLE ONLY herika_compat.core_action ADD CONSTRAINT core_action_pkey PRIMARY KEY (id);
CREATE INDEX idx_core_action_action_name_lower ON herika_compat.core_action USING btree (lower((action_name)::text));
CREATE INDEX idx_core_action_available_to_followers ON herika_compat.core_action USING btree (available_to_followers);
CREATE INDEX idx_core_action_available_to_narrator ON herika_compat.core_action USING btree (available_to_narrator);
CREATE INDEX idx_core_action_available_to_npc ON herika_compat.core_action USING btree (available_to_npc);
CREATE INDEX idx_core_action_code_name_lower ON herika_compat.core_action USING btree (lower((code_name)::text));
CREATE INDEX idx_core_action_game_function ON herika_compat.core_action USING btree (game_function);
CREATE INDEX idx_core_action_is_activated ON herika_compat.core_action USING btree (is_activated);

CREATE TABLE herika_compat.core_action_custom (
    id integer NOT NULL,
    code_name character varying(128) NOT NULL,
    action_name character varying(255) NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    return_message text DEFAULT ''::text NOT NULL,
    available_to_npc boolean DEFAULT false NOT NULL,
    available_to_followers boolean DEFAULT false NOT NULL,
    available_to_narrator boolean DEFAULT false NOT NULL,
    is_activated boolean DEFAULT true NOT NULL,
    parameters_json jsonb DEFAULT '{}'::jsonb NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    game_function boolean DEFAULT true NOT NULL,
    import_version bigint DEFAULT 0 NOT NULL,
    script_proxy_program jsonb,
    created_at timestamp without time zone DEFAULT now(),
    updated_at timestamp without time zone DEFAULT now()
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.core_action_custom_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.core_action_custom_id_seq OWNED BY herika_compat.core_action_custom.id;
ALTER TABLE ONLY herika_compat.core_action_custom ALTER COLUMN id SET DEFAULT nextval('herika_compat.core_action_custom_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.core_action_custom ADD CONSTRAINT core_action_custom_code_name_key UNIQUE (code_name);
ALTER TABLE ONLY herika_compat.core_action_custom ADD CONSTRAINT core_action_custom_pkey PRIMARY KEY (id);
CREATE INDEX idx_core_action_custom_action_name_lower ON herika_compat.core_action_custom USING btree (lower((action_name)::text));
CREATE INDEX idx_core_action_custom_available_to_followers ON herika_compat.core_action_custom USING btree (available_to_followers);
CREATE INDEX idx_core_action_custom_available_to_narrator ON herika_compat.core_action_custom USING btree (available_to_narrator);
CREATE INDEX idx_core_action_custom_available_to_npc ON herika_compat.core_action_custom USING btree (available_to_npc);
CREATE INDEX idx_core_action_custom_code_name_lower ON herika_compat.core_action_custom USING btree (lower((code_name)::text));
CREATE INDEX idx_core_action_custom_game_function ON herika_compat.core_action_custom USING btree (game_function);
CREATE INDEX idx_core_action_custom_is_activated ON herika_compat.core_action_custom USING btree (is_activated);
CREATE VIEW herika_compat.combined_core_action AS
SELECT c.* FROM herika_compat.core_action_custom c
UNION ALL
SELECT b.* FROM herika_compat.core_action b
LEFT JOIN herika_compat.core_action_custom c ON lower(b.code_name)=lower(c.code_name)
WHERE c.code_name IS NULL;

CREATE TABLE herika_compat.dynamic_bio (
    id integer NOT NULL,
    prompt text NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.dynamic_bio_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.dynamic_bio_id_seq OWNED BY herika_compat.dynamic_bio.id;
ALTER TABLE ONLY herika_compat.dynamic_bio ALTER COLUMN id SET DEFAULT nextval('herika_compat.dynamic_bio_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.dynamic_bio ADD CONSTRAINT dynamic_bio_pkey PRIMARY KEY (id);

CREATE TABLE herika_compat.import_rules (
    id integer NOT NULL,
    description text NOT NULL,
    match_name text,
    match_race text,
    match_gender text,
    match_base text,
    match_mods text[],
    action jsonb,
    profile integer,
    priority integer DEFAULT 0,
    enabled boolean DEFAULT true,
    match_faction text
);
CREATE SEQUENCE herika_compat.import_rules_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.import_rules_id_seq OWNED BY herika_compat.import_rules.id;
ALTER TABLE ONLY herika_compat.import_rules ALTER COLUMN id SET DEFAULT nextval('herika_compat.import_rules_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.import_rules ADD CONSTRAINT import_rules_pkey PRIMARY KEY (id);
ALTER TABLE ONLY herika_compat.import_rules ADD CONSTRAINT import_rules_profile_fkey FOREIGN KEY (profile) REFERENCES herika_compat.core_profiles(id);

CREATE TABLE herika_compat.json_personalities (
    npc_name character varying(256) NOT NULL,
    personality jsonb
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.json_personalities ADD CONSTRAINT json_personalities_pkey PRIMARY KEY (npc_name);

CREATE TABLE herika_compat.translations (
    source_word text NOT NULL,
    translated_word text,
    expansion text,
    sense text NOT NULL,
    id integer NOT NULL
);
CREATE SEQUENCE herika_compat.translations_id_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.translations_id_seq OWNED BY herika_compat.translations.id;
ALTER TABLE ONLY herika_compat.translations ALTER COLUMN id SET DEFAULT nextval('herika_compat.translations_id_seq'::regclass);
ALTER TABLE ONLY herika_compat.translations ADD CONSTRAINT translation_pk PRIMARY KEY (id);
CREATE INDEX search_idx ON herika_compat.translations USING btree (source_word);

CREATE TABLE herika_compat.action_catalog_metadata (
    action_id integer PRIMARY KEY REFERENCES herika_compat.core_action_custom(id) ON DELETE CASCADE,
    source_action_name text NOT NULL UNIQUE,
    tier integer NOT NULL,
    client_capability text NOT NULL
);

CREATE OR REPLACE FUNCTION herika_compat.sync_action_catalog_projection(source_name text)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE source_row record;
DECLARE projected_id integer;
DECLARE source_found boolean;
BEGIN
    SELECT * INTO source_row FROM public.action_catalog WHERE action_name=source_name;
    source_found := FOUND;
    SELECT action_id INTO projected_id FROM action_catalog_metadata
    WHERE source_action_name=source_name;
    IF NOT source_found THEN
        IF projected_id IS NOT NULL THEN
            DELETE FROM action_catalog_metadata WHERE action_id=projected_id;
            DELETE FROM core_action_custom WHERE id=projected_id;
        END IF;
        RETURN;
    END IF;
    IF projected_id IS NULL THEN
        INSERT INTO core_action_custom (
            code_name,action_name,description,return_message,available_to_npc,
            available_to_followers,available_to_narrator,is_activated,parameters_json,
            metadata,game_function,import_version
        ) VALUES (
            source_row.action_name,source_row.action_name,source_row.description,'',true,false,false,
            source_row.enabled,source_row.parameter_schema,
            jsonb_build_object('tier',source_row.tier,'client_capability',source_row.client_capability,
                'result_schema',source_row.result_schema,'max_parameter_bytes',source_row.max_parameter_bytes,
                'terminal_result_required',source_row.terminal_result_required,
                'continuation_capable',source_row.continuation_capable),true,1
        ) RETURNING id INTO projected_id;
        INSERT INTO action_catalog_metadata (action_id,source_action_name,tier,client_capability)
        VALUES (projected_id,source_row.action_name,source_row.tier,source_row.client_capability);
    ELSE
        UPDATE core_action_custom SET
            code_name=source_row.action_name,action_name=source_row.action_name,
            description=source_row.description,is_activated=source_row.enabled,
            parameters_json=source_row.parameter_schema,
            metadata=jsonb_build_object('tier',source_row.tier,'client_capability',source_row.client_capability,
                'result_schema',source_row.result_schema,'max_parameter_bytes',source_row.max_parameter_bytes,
                'terminal_result_required',source_row.terminal_result_required,
                'continuation_capable',source_row.continuation_capable),updated_at=now()
        WHERE id=projected_id;
        UPDATE action_catalog_metadata SET
            tier=source_row.tier,client_capability=source_row.client_capability
        WHERE action_id=projected_id;
    END IF;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_action_catalog_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    IF TG_OP='DELETE' THEN
        PERFORM sync_action_catalog_projection(OLD.action_name);
        RETURN OLD;
    END IF;
    IF TG_OP='UPDATE' AND OLD.action_name IS DISTINCT FROM NEW.action_name THEN
        PERFORM sync_action_catalog_projection(OLD.action_name);
    END IF;
    PERFORM sync_action_catalog_projection(NEW.action_name);
    RETURN NEW;
END
$function$;

CREATE TRIGGER herika_project_action_catalog
AFTER INSERT OR UPDATE OR DELETE ON public.action_catalog
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_action_catalog_projection();

SELECT herika_compat.sync_action_catalog_projection(action_name)
FROM public.action_catalog ORDER BY action_name;
