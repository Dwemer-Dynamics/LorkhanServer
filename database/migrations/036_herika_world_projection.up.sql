-- Keep the exact Herika journal/world tables current from newly accepted OpenMW turns.

ALTER TABLE herika_compat.questlog_metadata ADD COLUMN entry_hash char(64);
UPDATE herika_compat.questlog_metadata metadata SET entry_hash=hash.value
FROM (
    SELECT q.rowid,encode(sha256(convert_to(COALESCE(q.data,''),'UTF8')),'hex') AS value
    FROM herika_compat.questlog q
) hash WHERE hash.rowid=metadata.rowid;
ALTER TABLE herika_compat.questlog_metadata ALTER COLUMN entry_hash SET NOT NULL;
CREATE UNIQUE INDEX questlog_metadata_scope_entry_unique
    ON herika_compat.questlog_metadata (installation_id,playthrough_id,journal_id,entry_hash);
CREATE UNIQUE INDEX quest_metadata_scope_journal_unique
    ON herika_compat.quest_metadata (installation_id,playthrough_id,journal_id);
CREATE UNIQUE INDEX book_metadata_scope_record_unique
    ON herika_compat.book_metadata (installation_id,playthrough_id,record_id);

CREATE TABLE herika_compat.currentmission_metadata (
    rowid bigint PRIMARY KEY REFERENCES herika_compat.currentmission(rowid) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    journal_id text NOT NULL,
    source_turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL,
    UNIQUE (installation_id,playthrough_id,journal_id)
);
INSERT INTO herika_compat.currentmission_metadata (
    rowid,installation_id,playthrough_id,journal_id,source_turn_id
)
SELECT mission.rowid,metadata.installation_id,metadata.playthrough_id,metadata.journal_id,metadata.source_turn_id
FROM herika_compat.currentmission mission
JOIN herika_compat.quest_metadata metadata ON metadata.rowid=mission.rowid
ON CONFLICT DO NOTHING;

CREATE OR REPLACE FUNCTION herika_compat.project_turn_world(source_turn uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE source_row record;
DECLARE item jsonb;
DECLARE actor jsonb;
DECLARE record_key text;
DECLARE projected_rowid bigint;
DECLARE projected_log_rowid integer;
DECLARE projected_formid bigint;
DECLARE game_time bigint;
DECLARE entry_hash char(64);
DECLARE file_name text;
DECLARE file_index integer;
BEGIN
    SELECT turn_row.*,session.installation_id,session.playthrough_id,session.content_fingerprint
    INTO source_row
    FROM public.turns turn_row
    JOIN public.sessions session ON session.session_id=turn_row.session_id
    WHERE turn_row.turn_id=source_turn;
    IF NOT FOUND THEN RETURN; END IF;
    game_time := CASE WHEN jsonb_typeof(source_row.context#>'{world,game_time}')='number'
        THEN floor((source_row.context#>>'{world,game_time}')::numeric)::bigint ELSE 0 END;

    FOR item IN SELECT value FROM jsonb_array_elements(COALESCE(source_row.context#>'{books,items}','[]'::jsonb))
    LOOP
        IF jsonb_typeof(item)<>'object' THEN CONTINUE; END IF;
        record_key := COALESCE(item->>'record_id',item->>'id',item->>'title',item->>'name');
        IF NULLIF(record_key,'') IS NULL THEN CONTINUE; END IF;
        SELECT metadata.rowid INTO projected_rowid FROM book_metadata metadata
        WHERE metadata.installation_id=source_row.installation_id
          AND metadata.playthrough_id=source_row.playthrough_id AND metadata.record_id=record_key;
        IF projected_rowid IS NULL THEN
            INSERT INTO books (sess,title,content,localts,gamets,ts)
            VALUES (source_row.session_id::text,COALESCE(item->>'title',item->>'name',record_key),item->>'content',
                    extract(epoch FROM source_row.accepted_at)::bigint,game_time,
                    (extract(epoch FROM source_row.accepted_at)*1000)::bigint)
            RETURNING rowid INTO projected_rowid;
            INSERT INTO book_metadata (
                rowid,installation_id,playthrough_id,session_id,record_id,content_file,source_turn_id
            ) VALUES (
                projected_rowid,source_row.installation_id,source_row.playthrough_id,
                source_row.session_id,record_key,item->>'content_file',source_turn
            );
        ELSE
            UPDATE books SET
                sess=source_row.session_id::text,title=COALESCE(item->>'title',item->>'name',record_key),
                content=item->>'content',localts=extract(epoch FROM source_row.accepted_at)::bigint,
                gamets=game_time,ts=(extract(epoch FROM source_row.accepted_at)*1000)::bigint
            WHERE rowid=projected_rowid;
            UPDATE book_metadata SET
                session_id=source_row.session_id,content_file=item->>'content_file',source_turn_id=source_turn
            WHERE rowid=projected_rowid;
        END IF;
        projected_rowid := NULL;
    END LOOP;

    FOR item IN SELECT value FROM jsonb_array_elements(COALESCE(source_row.context#>'{journal,items}','[]'::jsonb))
    LOOP
        IF jsonb_typeof(item)<>'object' THEN CONTINUE; END IF;
        record_key := COALESCE(item->>'quest_id',item->>'id');
        IF NULLIF(record_key,'') IS NULL THEN CONTINUE; END IF;
        SELECT metadata.rowid INTO projected_rowid FROM quest_metadata metadata
        WHERE metadata.installation_id=source_row.installation_id
          AND metadata.playthrough_id=source_row.playthrough_id AND metadata.journal_id=record_key;
        IF projected_rowid IS NULL THEN
            INSERT INTO quests (
                ts,sess,id_quest,name,editor_id,mod,stage,briefing,briefing2,localts,gamets,data,status
            ) VALUES (
                source_row.accepted_at::text,source_row.session_id::text,record_key,record_key,record_key,
                item->>'content_file',CASE WHEN item->>'id'~'^-?[0-9]+$'
                    AND (item->>'id')::numeric BETWEEN -2147483648 AND 2147483647
                    THEN (item->>'id')::integer END,item->>'text',
                concat_ws(' ',item->>'day',item->>'month',item->>'day_of_month'),
                extract(epoch FROM source_row.accepted_at)::bigint,game_time,item::text,'active'
            ) RETURNING rowid INTO projected_rowid;
            INSERT INTO quest_metadata (
                rowid,installation_id,playthrough_id,session_id,journal_id,source_turn_id
            ) VALUES (
                projected_rowid,source_row.installation_id,source_row.playthrough_id,
                source_row.session_id,record_key,source_turn
            );
        ELSE
            UPDATE quests SET
                ts=source_row.accepted_at::text,sess=source_row.session_id::text,name=record_key,
                editor_id=record_key,mod=item->>'content_file',
                stage=CASE WHEN item->>'id'~'^-?[0-9]+$'
                    AND (item->>'id')::numeric BETWEEN -2147483648 AND 2147483647
                    THEN (item->>'id')::integer END,
                briefing=item->>'text',briefing2=concat_ws(' ',item->>'day',item->>'month',item->>'day_of_month'),
                localts=extract(epoch FROM source_row.accepted_at)::bigint,gamets=game_time,
                data=item::text,status='active'
            WHERE rowid=projected_rowid;
            UPDATE quest_metadata SET session_id=source_row.session_id,source_turn_id=source_turn
            WHERE rowid=projected_rowid;
        END IF;

        entry_hash := encode(sha256(convert_to(item::text,'UTF8')),'hex');
        SELECT metadata.rowid INTO projected_log_rowid FROM questlog_metadata metadata
        WHERE metadata.installation_id=source_row.installation_id
          AND metadata.playthrough_id=source_row.playthrough_id
          AND metadata.journal_id=record_key AND metadata.entry_hash=entry_hash;
        IF projected_log_rowid IS NULL THEN
            INSERT INTO questlog (
                ts,sess,id_quest,name,editor_id,mod,stage,briefing,briefing2,localts,gamets,data,status
            ) VALUES (
                source_row.accepted_at::text,source_row.session_id::text,record_key,record_key,record_key,
                item->>'content_file',CASE WHEN item->>'id'~'^-?[0-9]+$'
                    AND (item->>'id')::numeric BETWEEN -2147483648 AND 2147483647
                    THEN (item->>'id')::integer END,item->>'text',
                concat_ws(' ',item->>'day',item->>'month',item->>'day_of_month'),
                extract(epoch FROM source_row.accepted_at)::bigint,game_time,item::text,'recorded'
            ) RETURNING rowid INTO projected_log_rowid;
            INSERT INTO questlog_metadata (
                rowid,installation_id,playthrough_id,session_id,journal_id,source_turn_id,entry_hash
            ) VALUES (
                projected_log_rowid,source_row.installation_id,source_row.playthrough_id,
                source_row.session_id,record_key,source_turn,entry_hash
            );
        END IF;

        SELECT metadata.rowid INTO projected_rowid FROM currentmission_metadata metadata
        WHERE metadata.installation_id=source_row.installation_id
          AND metadata.playthrough_id=source_row.playthrough_id AND metadata.journal_id=record_key;
        IF projected_rowid IS NULL THEN
            INSERT INTO currentmission (sess,description,localts,gamets,ts)
            VALUES (source_row.session_id::text,item->>'text',extract(epoch FROM source_row.accepted_at)::bigint,
                    game_time,(extract(epoch FROM source_row.accepted_at)*1000)::bigint)
            RETURNING rowid INTO projected_rowid;
            INSERT INTO currentmission_metadata (
                rowid,installation_id,playthrough_id,journal_id,source_turn_id
            ) VALUES (
                projected_rowid,source_row.installation_id,source_row.playthrough_id,record_key,source_turn
            );
        ELSE
            UPDATE currentmission SET
                sess=source_row.session_id::text,description=item->>'text',
                localts=extract(epoch FROM source_row.accepted_at)::bigint,gamets=game_time,
                ts=(extract(epoch FROM source_row.accepted_at)*1000)::bigint
            WHERE rowid=projected_rowid;
            UPDATE currentmission_metadata SET source_turn_id=source_turn WHERE rowid=projected_rowid;
        END IF;
        projected_rowid := NULL;
        projected_log_rowid := NULL;
    END LOOP;

    record_key := NULLIF(source_row.context#>>'{world,cell}','');
    IF record_key IS NOT NULL THEN
        projected_formid := (('x'||substr(md5(record_key),1,16))::bit(64)::bigint);
        IF EXISTS (SELECT 1 FROM location_metadata WHERE formid=projected_formid) THEN
            UPDATE locations SET
                name=record_key,tags='openmw',is_interior=CASE WHEN record_key LIKE 'exterior:%' THEN 0 ELSE 1 END,
                updated_at=source_row.accepted_at AT TIME ZONE 'UTC',world='Morrowind/OpenMW'
            WHERE formid=projected_formid;
            UPDATE location_metadata SET source_turn_id=source_turn WHERE formid=projected_formid;
        ELSE
            INSERT INTO locations (name,formid,tags,is_interior,updated_at,world)
            VALUES (record_key,projected_formid,'openmw',CASE WHEN record_key LIKE 'exterior:%' THEN 0 ELSE 1 END,
                    source_row.accepted_at AT TIME ZONE 'UTC','Morrowind/OpenMW');
            INSERT INTO location_metadata (formid,cell_key,installation_id,source_turn_id)
            VALUES (projected_formid,record_key,source_row.installation_id,source_turn);
        END IF;
    END IF;

    FOR actor,item IN
        SELECT source_row.target,value FROM jsonb_array_elements(COALESCE(source_row.context#>'{targetState,factions}','[]'::jsonb))
        UNION ALL
        SELECT source_row.speaker,value FROM jsonb_array_elements(COALESCE(source_row.context#>'{playerState,factions}','[]'::jsonb))
    LOOP
        IF jsonb_typeof(item)<>'object' OR NULLIF(item->>'id','') IS NULL THEN CONTINUE; END IF;
        record_key := item->>'id';
        INSERT INTO factions (name,formid,player_rank,localts)
        VALUES (record_key,record_key,CASE WHEN item->>'rank'~'^-?[0-9]+(\.[0-9]+)?$' THEN (item->>'rank')::numeric END,
                extract(epoch FROM source_row.accepted_at)::bigint)
        ON CONFLICT (formid) DO UPDATE SET
            name=EXCLUDED.name,player_rank=EXCLUDED.player_rank,localts=EXCLUDED.localts;
        INSERT INTO faction_metadata (formid,installation_id,source_turn_id,source_actor)
        VALUES (record_key,source_row.installation_id,source_turn,actor)
        ON CONFLICT (formid) DO UPDATE SET source_turn_id=EXCLUDED.source_turn_id,source_actor=EXCLUDED.source_actor;
        IF EXISTS (SELECT 1 FROM faction_vanilla WHERE formid=record_key) THEN
            UPDATE faction_vanilla SET name=record_key WHERE formid=record_key;
        ELSE
            INSERT INTO faction_vanilla (name,formid) VALUES (record_key,record_key);
        END IF;
    END LOOP;

    file_index := 0;
    FOR file_name IN SELECT value FROM jsonb_array_elements_text(COALESCE(source_row.context#>'{contentFiles,items}','[]'::jsonb))
    LOOP
        INSERT INTO game_plugins (plugin_name,compile_index,updated_at)
        VALUES (file_name,file_index,source_row.accepted_at AT TIME ZONE 'UTC')
        ON CONFLICT (plugin_name) DO UPDATE SET compile_index=EXCLUDED.compile_index,updated_at=EXCLUDED.updated_at;
        INSERT INTO game_plugin_metadata (plugin_name,installation_id,content_fingerprint,source_turn_id)
        VALUES (file_name,source_row.installation_id,source_row.content_fingerprint,source_turn)
        ON CONFLICT (plugin_name) DO UPDATE SET
            content_fingerprint=EXCLUDED.content_fingerprint,source_turn_id=EXCLUDED.source_turn_id;
        file_index := file_index+1;
    END LOOP;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_turn_world_projection()
RETURNS trigger LANGUAGE plpgsql SET search_path = herika_compat, public, pg_temp AS $function$
BEGIN
    PERFORM project_turn_world(NEW.turn_id);
    RETURN NEW;
END
$function$;
CREATE TRIGGER herika_project_turn_world
AFTER INSERT ON public.turns
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_turn_world_projection();

CREATE OR REPLACE FUNCTION herika_compat.remove_description_projection(source_description uuid)
RETURNS void LANGUAGE plpgsql SET search_path = herika_compat, public, pg_temp AS $function$
DECLARE source_row record;
BEGIN
    SELECT * INTO source_row FROM description_metadata WHERE description_id=source_description;
    IF FOUND THEN
        DELETE FROM descriptions_custom WHERE plugin=source_row.plugin AND baseid=source_row.baseid;
        DELETE FROM market_cache WHERE plugin=source_row.plugin AND baseid=source_row.baseid;
        DELETE FROM description_metadata WHERE description_id=source_description;
    END IF;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_description_projection(source_description uuid)
RETURNS void LANGUAGE plpgsql SET search_path = herika_compat, public, pg_temp AS $function$
DECLARE source_row record;
BEGIN
    SELECT * INTO source_row FROM public.item_descriptions WHERE description_id=source_description;
    IF NOT FOUND OR source_row.deleted_at IS NOT NULL THEN
        PERFORM remove_description_projection(source_description);
        RETURN;
    END IF;
    PERFORM remove_description_projection(source_description);
    INSERT INTO descriptions_custom (plugin,baseid,name,description)
    VALUES (source_row.content_file,source_row.record_id,source_row.display_name,source_row.description)
    ON CONFLICT (plugin,baseid) DO UPDATE SET name=EXCLUDED.name,description=EXCLUDED.description;
    INSERT INTO description_metadata (plugin,baseid,description_id,installation_id)
    VALUES (source_row.content_file,source_row.record_id,source_description,source_row.installation_id);
    INSERT INTO market_cache (baseid,name,description,plugin,enchantment,price)
    VALUES (source_row.record_id,source_row.display_name,source_row.description,source_row.content_file,NULL,NULL)
    ON CONFLICT (baseid,plugin) DO UPDATE SET name=EXCLUDED.name,description=EXCLUDED.description;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_description_projection()
RETURNS trigger LANGUAGE plpgsql SET search_path = herika_compat, public, pg_temp AS $function$
BEGIN
    IF TG_OP='DELETE' THEN
        PERFORM remove_description_projection(OLD.description_id);
        RETURN OLD;
    END IF;
    PERFORM sync_description_projection(NEW.description_id);
    RETURN NEW;
END
$function$;
CREATE TRIGGER herika_project_item_description
AFTER INSERT OR UPDATE OR DELETE ON public.item_descriptions
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_description_projection();
