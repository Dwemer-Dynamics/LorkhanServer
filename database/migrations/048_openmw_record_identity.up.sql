-- Persist the active OpenMW load order and every canonical item identity observed in bounded turn context.
CREATE TABLE almsivi_internal.content_manifests (
    installation_id uuid PRIMARY KEY REFERENCES almsivi_internal.installations(installation_id) ON DELETE CASCADE,
    content_fingerprint text NOT NULL,
    source_session_id uuid REFERENCES almsivi_internal.sessions(session_id) ON DELETE SET NULL,
    source_turn_id uuid REFERENCES almsivi_internal.turns(turn_id) ON DELETE SET NULL,
    file_count integer NOT NULL CHECK (file_count BETWEEN 0 AND 256),
    observed_at timestamptz NOT NULL
);

CREATE TABLE almsivi_internal.content_manifest_files (
    installation_id uuid NOT NULL REFERENCES almsivi_internal.installations(installation_id) ON DELETE CASCADE,
    content_file varchar(256) NOT NULL,
    load_order integer NOT NULL CHECK (load_order BETWEEN 0 AND 255),
    active boolean NOT NULL DEFAULT true,
    first_seen_at timestamptz NOT NULL,
    last_seen_at timestamptz NOT NULL,
    source_session_id uuid REFERENCES almsivi_internal.sessions(session_id) ON DELETE SET NULL,
    source_turn_id uuid REFERENCES almsivi_internal.turns(turn_id) ON DELETE SET NULL,
    PRIMARY KEY (installation_id,content_file),
    CHECK (content_file=lower(btrim(content_file)) AND length(content_file) BETWEEN 1 AND 256)
);
CREATE UNIQUE INDEX content_manifest_files_active_order_uq
    ON almsivi_internal.content_manifest_files(installation_id,load_order) WHERE active;
CREATE INDEX content_manifest_files_active_name_idx
    ON almsivi_internal.content_manifest_files(installation_id,lower(content_file)) WHERE active;

CREATE TABLE almsivi_internal.discovered_items (
    installation_id uuid NOT NULL REFERENCES almsivi_internal.installations(installation_id) ON DELETE CASCADE,
    content_file varchar(256) NOT NULL,
    record_id varchar(256) NOT NULL,
    record_kind varchar(32) NOT NULL DEFAULT 'item',
    display_name varchar(256),
    reference_content_file varchar(256),
    observed_sources jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(observed_sources)='array'),
    first_seen_at timestamptz NOT NULL,
    last_seen_at timestamptz NOT NULL,
    source_session_id uuid REFERENCES almsivi_internal.sessions(session_id) ON DELETE SET NULL,
    source_turn_id uuid REFERENCES almsivi_internal.turns(turn_id) ON DELETE SET NULL,
    observation_count bigint NOT NULL DEFAULT 1 CHECK (observation_count>0),
    PRIMARY KEY (installation_id,content_file,record_id),
    CHECK (content_file=lower(btrim(content_file)) AND length(content_file) BETWEEN 1 AND 256),
    CHECK (record_id=lower(btrim(record_id)) AND length(record_id) BETWEEN 1 AND 256),
    CHECK (display_name IS NULL OR length(display_name) BETWEEN 1 AND 256)
);
CREATE INDEX discovered_items_recent_idx ON almsivi_internal.discovered_items(installation_id,last_seen_at DESC);

CREATE FUNCTION almsivi_internal.observe_openmw_item(source_installation uuid,source_session uuid,source_turn uuid,
    source_time timestamptz,source_name text,item jsonb)
RETURNS void LANGUAGE plpgsql SET search_path=almsivi_internal,public,pg_temp AS $function$
DECLARE
    item_content text:=lower(btrim(COALESCE(item->>'content_file','')));
    item_record text:=lower(btrim(COALESCE(item->>'record_id','')));
    item_name text:=NULLIF(btrim(COALESCE(item->>'display_name',item->>'name','')),'');
    item_kind text:=lower(btrim(COALESCE(item->>'kind','item')));
    reference_content text:=NULLIF(lower(btrim(COALESCE(item->>'reference_content_file',''))),'');
BEGIN
    IF jsonb_typeof(item)<>'object' OR item_content='' OR item_record=''
       OR length(item_content)>256 OR length(item_record)>256 OR length(COALESCE(item_name,''))>256 THEN RETURN; END IF;
    IF item_kind!~'^[a-z][a-z0-9_-]{0,31}$' THEN item_kind:='item'; END IF;
    INSERT INTO discovered_items(installation_id,content_file,record_id,record_kind,display_name,
        reference_content_file,observed_sources,first_seen_at,last_seen_at,source_session_id,source_turn_id)
    VALUES(source_installation,item_content,item_record,item_kind,item_name,reference_content,
        jsonb_build_array(source_name),source_time,source_time,source_session,source_turn)
    ON CONFLICT(installation_id,content_file,record_id) DO UPDATE SET
        record_kind=EXCLUDED.record_kind,
        display_name=COALESCE(EXCLUDED.display_name,discovered_items.display_name),
        reference_content_file=COALESCE(EXCLUDED.reference_content_file,discovered_items.reference_content_file),
        observed_sources=(SELECT jsonb_agg(value ORDER BY value) FROM (
            SELECT DISTINCT value FROM jsonb_array_elements(discovered_items.observed_sources||EXCLUDED.observed_sources)
        ) sources),last_seen_at=EXCLUDED.last_seen_at,source_session_id=EXCLUDED.source_session_id,
        source_turn_id=EXCLUDED.source_turn_id,observation_count=discovered_items.observation_count+1;
END
$function$;

CREATE FUNCTION almsivi_internal.sync_openmw_record_identity()
RETURNS trigger LANGUAGE plpgsql SET search_path=almsivi_internal,public,pg_temp AS $function$
DECLARE
    session_row record;
    file_name text;
    file_order integer:=0;
    item jsonb;
    actor jsonb;
    files jsonb:=COALESCE(NEW.context#>'{contentFiles,items}','[]'::jsonb);
BEGIN
    SELECT installation_id,content_fingerprint INTO session_row FROM sessions WHERE session_id=NEW.session_id;
    IF NOT FOUND THEN RETURN NEW; END IF;

    IF NEW.context ? 'contentFiles' AND jsonb_typeof(files)='array' THEN
        UPDATE content_manifest_files SET active=false WHERE installation_id=session_row.installation_id AND active;
        FOR file_name IN SELECT lower(btrim(value)) FROM jsonb_array_elements_text(files)
        LOOP
            IF file_name<>'' AND length(file_name)<=256 AND file_order<256 THEN
                IF EXISTS(SELECT 1 FROM content_manifest_files WHERE installation_id=session_row.installation_id
                    AND content_file=file_name AND active) THEN CONTINUE; END IF;
                INSERT INTO content_manifest_files(installation_id,content_file,load_order,active,first_seen_at,last_seen_at,
                    source_session_id,source_turn_id)
                VALUES(session_row.installation_id,file_name,file_order,true,NEW.accepted_at,NEW.accepted_at,NEW.session_id,NEW.turn_id)
                ON CONFLICT(installation_id,content_file) DO UPDATE SET load_order=EXCLUDED.load_order,active=true,
                    last_seen_at=EXCLUDED.last_seen_at,source_session_id=EXCLUDED.source_session_id,source_turn_id=EXCLUDED.source_turn_id;
                file_order:=file_order+1;
            END IF;
        END LOOP;
        INSERT INTO content_manifests(installation_id,content_fingerprint,source_session_id,source_turn_id,file_count,observed_at)
        VALUES(session_row.installation_id,session_row.content_fingerprint,NEW.session_id,NEW.turn_id,file_order,NEW.accepted_at)
        ON CONFLICT(installation_id) DO UPDATE SET content_fingerprint=EXCLUDED.content_fingerprint,
            source_session_id=EXCLUDED.source_session_id,source_turn_id=EXCLUDED.source_turn_id,
            file_count=EXCLUDED.file_count,observed_at=EXCLUDED.observed_at;
    END IF;

    FOR item IN SELECT value FROM jsonb_array_elements(COALESCE(NEW.context#>'{inventory,items}','[]'::jsonb)) LOOP
        PERFORM observe_openmw_item(session_row.installation_id,NEW.session_id,NEW.turn_id,NEW.accepted_at,'player.inventory',item); END LOOP;
    FOR item IN SELECT value FROM jsonb_array_elements(COALESCE(NEW.context#>'{nearbyObjects,items}','[]'::jsonb)) LOOP
        PERFORM observe_openmw_item(session_row.installation_id,NEW.session_id,NEW.turn_id,NEW.accepted_at,'world.nearby',item); END LOOP;
    FOR item IN SELECT value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(NEW.context#>'{playerState,equipment}')='array' THEN NEW.context#>'{playerState,equipment}' ELSE '[]'::jsonb END) LOOP
        PERFORM observe_openmw_item(session_row.installation_id,NEW.session_id,NEW.turn_id,NEW.accepted_at,'player.equipment',item); END LOOP;
    FOR item IN SELECT value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(NEW.context#>'{targetState,equipment}')='array' THEN NEW.context#>'{targetState,equipment}' ELSE '[]'::jsonb END) LOOP
        PERFORM observe_openmw_item(session_row.installation_id,NEW.session_id,NEW.turn_id,NEW.accepted_at,'target.equipment',item); END LOOP;
    FOR actor IN SELECT value FROM jsonb_array_elements(COALESCE(NEW.context#>'{nearbyActors,items}','[]'::jsonb)) LOOP
        FOR item IN SELECT value FROM jsonb_array_elements(CASE WHEN jsonb_typeof(actor->'equipment')='array' THEN actor->'equipment' ELSE '[]'::jsonb END) LOOP
            PERFORM observe_openmw_item(session_row.installation_id,NEW.session_id,NEW.turn_id,NEW.accepted_at,'nearby_actor.equipment',item); END LOOP;
    END LOOP;
    RETURN NEW;
END
$function$;

CREATE TRIGGER turns_openmw_record_identity
AFTER INSERT ON almsivi_internal.turns FOR EACH ROW EXECUTE FUNCTION almsivi_internal.sync_openmw_record_identity();

UPDATE almsivi_internal.action_catalog SET parameter_schema=
    '{"type":"object","properties":{"record_id":{"type":"string","minLength":1,"maxLength":128},"content_file":{"type":"string","minLength":1,"maxLength":256}},"required":["record_id","content_file"],"additionalProperties":false}'::jsonb
WHERE action_name='item.use';
UPDATE almsivi_internal.action_catalog SET parameter_schema=
    '{"type":"object","properties":{"record_id":{"type":"string","minLength":1,"maxLength":128},"content_file":{"type":"string","minLength":1,"maxLength":256},"slot":{"type":"string","enum":["helmet","cuirass","greaves","left_pauldron","right_pauldron","left_gauntlet","right_gauntlet","boots","shirt","pants","skirt","robe","left_ring","right_ring","amulet","belt","carried_right","carried_left","ammunition"]}},"required":["record_id","content_file","slot"],"additionalProperties":false}'::jsonb
WHERE action_name='item.equip';
