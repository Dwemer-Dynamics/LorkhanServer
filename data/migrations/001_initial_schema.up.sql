-- Initial LorkhanServer schema and application seed data.
-- Generated from the clean prototype schema; no installations or user data.
SET LOCAL search_path TO public, pg_temp;
SET LOCAL check_function_bodies = false;

--
-- Name: lorkhan_internal; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA IF NOT EXISTS lorkhan_internal;


--
-- Name: pg_trgm; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;


--
-- Name: EXTENSION pg_trgm; Type: COMMENT; Schema: -; Owner: -
--



--
-- Name: vector; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS vector WITH SCHEMA public;


--
-- Name: EXTENSION vector; Type: COMMENT; Schema: -; Owner: -
--



--
-- Name: advance_relationship_revision(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.advance_relationship_revision() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    NEW.revision := OLD.revision + 1;
    RETURN NEW;
END $$;


--
-- Name: capture_memory_record_initial_revision(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.capture_memory_record_initial_revision() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    INSERT INTO lorkhan_internal.memory_record_revisions
        (memory_id,revision,tier,content,source_event_id,provenance,occurred_at,deleted_at,change_reason,created_at)
    VALUES (NEW.memory_id,NEW.current_revision,NEW.tier,NEW.content,NEW.source_event_id,NEW.provenance,
        NEW.occurred_at,NEW.deleted_at,'created',NEW.created_at);
    RETURN NEW;
END;
$$;


--
-- Name: capture_memory_record_revision(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.capture_memory_record_revision() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.tier IS NOT DISTINCT FROM OLD.tier
       AND NEW.content IS NOT DISTINCT FROM OLD.content
       AND NEW.source_event_id IS NOT DISTINCT FROM OLD.source_event_id
       AND NEW.provenance IS NOT DISTINCT FROM OLD.provenance
       AND NEW.occurred_at IS NOT DISTINCT FROM OLD.occurred_at
       AND NEW.deleted_at IS NOT DISTINCT FROM OLD.deleted_at THEN
        RETURN NEW;
    END IF;
    NEW.current_revision := OLD.current_revision + 1;
    INSERT INTO lorkhan_internal.memory_record_revisions
        (memory_id,revision,tier,content,source_event_id,provenance,occurred_at,deleted_at,change_reason)
    VALUES (NEW.memory_id,NEW.current_revision,NEW.tier,NEW.content,NEW.source_event_id,NEW.provenance,
        NEW.occurred_at,NEW.deleted_at,
        CASE WHEN OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN 'deleted'
             WHEN OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL THEN 'restored'
             ELSE 'revised' END);
    RETURN NEW;
END;
$$;


--
-- Name: guard_timeline_event(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.guard_timeline_event() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'lorkhan_internal', 'public', 'pg_temp'
    AS $$
BEGIN
    IF NEW.suppressed_at IS NOT NULL THEN RETURN NEW; END IF;
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=NEW.turn_id)
       OR EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=NEW.source_event_id) THEN
        NEW.suppressed_at=clock_timestamp(); NEW.suppression_reason='loaded_save_rollback';
    END IF;
    RETURN NEW;
END $$;


--
-- Name: guard_timeline_memory(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.guard_timeline_memory() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'lorkhan_internal', 'public', 'pg_temp'
    AS $$
BEGIN
    IF NEW.deleted_at IS NOT NULL THEN RETURN NEW; END IF;
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=NEW.source_event_id
        OR COALESCE(NEW.provenance->'source_event_ids','[]'::jsonb) @> jsonb_build_array(i.source_event_id::text)) THEN
        NEW.deleted_at=clock_timestamp();
    END IF;
    RETURN NEW;
END $$;


--
-- Name: guard_timeline_narrative(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.guard_timeline_narrative() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'lorkhan_internal', 'public', 'pg_temp'
    AS $$
BEGIN
    IF NEW.deleted_at IS NOT NULL THEN RETURN NEW; END IF;
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i
        WHERE COALESCE(NEW.provenance->'source_turn_ids','[]'::jsonb) @> jsonb_build_array(i.turn_id::text)) THEN
        NEW.deleted_at=clock_timestamp();
    END IF;
    RETURN NEW;
END $$;


--
-- Name: guard_timeline_speech_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.guard_timeline_speech_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'lorkhan_internal', 'public', 'pg_temp'
    AS $$
BEGIN
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=NEW.turn_id) THEN
        DELETE FROM public.speech WHERE rowid=NEW.rowid;
        DELETE FROM speech_metadata WHERE rowid=NEW.rowid;
    END IF;
    RETURN NULL;
END $$;


--
-- Name: guard_timeline_world_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.guard_timeline_world_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'lorkhan_internal', 'public', 'pg_temp'
    AS $_$
BEGIN
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=NEW.source_turn_id) THEN
        EXECUTE format('DELETE FROM public.%I WHERE rowid=$1', CASE TG_TABLE_NAME
            WHEN 'book_metadata' THEN 'books' WHEN 'currentmission_metadata' THEN 'currentmission'
            WHEN 'questlog_metadata' THEN 'questlog' WHEN 'quest_metadata' THEN 'quests' END) USING NEW.rowid;
        EXECUTE format('DELETE FROM lorkhan_internal.%I WHERE rowid=$1',TG_TABLE_NAME) USING NEW.rowid;
    END IF;
    RETURN NULL;
END $_$;


--
-- Name: observe_openmw_item(uuid, uuid, uuid, timestamp with time zone, text, jsonb); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.observe_openmw_item(source_installation uuid, source_session uuid, source_turn uuid, source_time timestamp with time zone, source_name text, item jsonb) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'lorkhan_internal', 'public', 'pg_temp'
    AS $_$
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
$_$;


--
-- Name: project_turn_world(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.project_turn_world(source_turn uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $_$
DECLARE source_row record;
DECLARE item jsonb;
DECLARE actor jsonb;
DECLARE record_key text;
DECLARE projected_rowid bigint;
DECLARE projected_log_rowid integer;
DECLARE projected_formid bigint;
DECLARE game_time bigint;
DECLARE computed_entry_hash char(64);
DECLARE file_name text;
DECLARE file_index integer;
BEGIN
    SELECT turn_row.*,session.installation_id,session.playthrough_id,session.content_fingerprint
    INTO source_row
    FROM lorkhan_internal.turns turn_row
    JOIN lorkhan_internal.sessions session ON session.session_id=turn_row.session_id
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

        computed_entry_hash := encode(sha256(convert_to(item::text,'UTF8')),'hex');
        SELECT metadata.rowid INTO projected_log_rowid FROM questlog_metadata metadata
        WHERE metadata.installation_id=source_row.installation_id
          AND metadata.playthrough_id=source_row.playthrough_id
          AND metadata.journal_id=record_key AND metadata.entry_hash=computed_entry_hash;
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
                source_row.session_id,record_key,source_turn,computed_entry_hash
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
                name=record_key,tags='openmw',is_interior=CASE WHEN source_row.context#>>'{world,cell_identity,kind}'='exterior' THEN 0 ELSE 1 END,
                updated_at=source_row.accepted_at AT TIME ZONE 'UTC',world='Morrowind/OpenMW'
            WHERE formid=projected_formid;
            UPDATE location_metadata SET source_turn_id=source_turn WHERE formid=projected_formid;
        ELSE
            INSERT INTO locations (name,formid,tags,is_interior,updated_at,world)
            VALUES (record_key,projected_formid,'openmw',CASE WHEN source_row.context#>>'{world,cell_identity,kind}'='exterior' THEN 0 ELSE 1 END,
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
$_$;


--
-- Name: rebuild_special_profiles(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.rebuild_special_profiles() RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    DELETE FROM core_player;
    INSERT INTO core_player (id,value)
    SELECT DISTINCT ON (entry.key) entry.key,
           CASE WHEN jsonb_typeof(entry.value)='string' THEN entry.value#>>'{}' ELSE entry.value::text END
    FROM lorkhan_internal.profiles profile
    JOIN lorkhan_internal.profile_revisions revision
      ON revision.profile_id=profile.profile_id AND revision.revision=profile.current_revision
    CROSS JOIN LATERAL jsonb_each(revision.content) entry
    WHERE profile.deleted_at IS NULL AND profile.actor_identity->>'kind'='player'
    ORDER BY entry.key,profile.created_at DESC,profile.profile_id;

    DELETE FROM core_narrator;
    INSERT INTO core_narrator (id,value)
    SELECT DISTINCT ON (entry.key) entry.key,
           CASE WHEN jsonb_typeof(entry.value)='string' THEN entry.value#>>'{}' ELSE entry.value::text END
    FROM lorkhan_internal.profiles profile
    JOIN lorkhan_internal.profile_revisions revision
      ON revision.profile_id=profile.profile_id AND revision.revision=profile.current_revision
    CROSS JOIN LATERAL jsonb_each(revision.content) entry
    WHERE profile.deleted_at IS NULL AND profile.actor_identity->>'kind'='narrator'
    ORDER BY entry.key,profile.created_at DESC,profile.profile_id;
END
$$;


--
-- Name: refresh_npc_relationships(text); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.refresh_npc_relationships(source_profile text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE relationships_json jsonb;
BEGIN
    SELECT jsonb_object_agg(
        COALESCE(r.actor_identity->>'record_id',r.actor_identity->>'display_name',r.relationship_id::text),
        jsonb_build_object(
            'name',COALESCE(r.actor_identity->>'display_name',r.actor_identity->>'record_id'),
            'affinity',r.affinity,'disposition',r.disposition,'type',r.relationship_type,
            'source',r.source_mode
        )
    ) INTO relationships_json
    FROM lorkhan_internal.relationship_records r
    WHERE r.profile_id=source_profile AND r.deleted_at IS NULL;

    UPDATE core_npc_master npc SET
        relationships=CASE WHEN relationships_json IS NULL THEN NULL ELSE relationships_json::text END,
        extended_data=(npc.extended_data-'relationships')||
            CASE WHEN relationships_json IS NULL THEN '{}'::jsonb
                 ELSE jsonb_build_object('relationships',relationships_json) END
    FROM npc_metadata metadata
    WHERE metadata.source_profile_id=source_profile AND npc.id=metadata.npc_id;
END
$$;


--
-- Name: refresh_turn_audit(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.refresh_turn_audit(source_turn uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_rowid bigint;
DECLARE projected_log_rowid bigint;
DECLARE source_row record;
BEGIN
    SELECT snapshot.turn_id,snapshot.source_manifest,snapshot.created_at,
           turn_row.request_id,turn_row.session_id,session_row.installation_id,
           session_row.playthrough_id,session_row.profile_id,trace.prompt_trace_id,
           attempt.provider_attempt_id,attempt.state AS provider_state,
           attempt.provider_name,attempt.model,attempt.input_bytes,attempt.output_bytes,
           attempt.duration_ms,attempt.error_code,
           (SELECT string_agg(utterance.text,E'\n' ORDER BY utterance.utterance_index)
              FROM lorkhan_internal.dialogue_utterances utterance WHERE utterance.turn_id=snapshot.turn_id) AS response
    INTO source_row
    FROM lorkhan_internal.turn_provider_snapshots snapshot
    JOIN lorkhan_internal.turns turn_row ON turn_row.turn_id=snapshot.turn_id
    JOIN lorkhan_internal.sessions session_row ON session_row.session_id=turn_row.session_id
    LEFT JOIN lorkhan_internal.prompt_traces trace ON trace.turn_id=snapshot.turn_id
    LEFT JOIN LATERAL (
        SELECT provider.* FROM lorkhan_internal.provider_attempts provider
        WHERE provider.turn_id=snapshot.turn_id
        ORDER BY provider.attempt_number DESC,provider.started_at DESC LIMIT 1
    ) attempt ON true
    WHERE snapshot.turn_id=source_turn;
    IF NOT FOUND THEN RETURN; END IF;

    SELECT rowid INTO projected_rowid
    FROM audit_request_metadata WHERE turn_id=source_turn;
    IF projected_rowid IS NULL THEN
        INSERT INTO audit_request (
            request,result,created_at,url,connector,usage,response
        ) VALUES (
            (source_row.source_manifest->'message'->'_prompt')::text,
            COALESCE(source_row.error_code,source_row.provider_state,'accepted'),
            source_row.created_at AT TIME ZONE 'UTC',NULL,source_row.provider_name,
            jsonb_strip_nulls(jsonb_build_object(
                'model',source_row.model,'input_bytes',source_row.input_bytes,
                'output_bytes',source_row.output_bytes,'duration_ms',source_row.duration_ms,
                'state',source_row.provider_state
            )),source_row.response
        ) RETURNING rowid INTO projected_rowid;
        INSERT INTO audit_request_metadata (
            rowid,installation_id,playthrough_id,profile_id,session_id,turn_id,request_id,
            prompt_trace_id,provider_attempt_id
        ) VALUES (
            projected_rowid,source_row.installation_id,source_row.playthrough_id,
            source_row.profile_id,source_row.session_id,source_row.turn_id,source_row.request_id,
            source_row.prompt_trace_id,source_row.provider_attempt_id
        );
    ELSE
        UPDATE audit_request SET
            request=(source_row.source_manifest->'message'->'_prompt')::text,
            result=COALESCE(source_row.error_code,source_row.provider_state,'accepted'),
            created_at=source_row.created_at AT TIME ZONE 'UTC',connector=source_row.provider_name,
            usage=jsonb_strip_nulls(jsonb_build_object(
                'model',source_row.model,'input_bytes',source_row.input_bytes,
                'output_bytes',source_row.output_bytes,'duration_ms',source_row.duration_ms,
                'state',source_row.provider_state
            )),response=source_row.response
        WHERE rowid=projected_rowid;
        UPDATE audit_request_metadata SET
            prompt_trace_id=source_row.prompt_trace_id,
            provider_attempt_id=source_row.provider_attempt_id
        WHERE rowid=projected_rowid;
    END IF;

    SELECT rowid INTO projected_log_rowid FROM log_metadata WHERE turn_id=source_turn;
    IF projected_log_rowid IS NULL THEN
        INSERT INTO log (localts,prompt,response,url)
        VALUES (
            extract(epoch FROM source_row.created_at)::bigint,
            (source_row.source_manifest->'message'->'_prompt')::text,source_row.response,NULL
        ) RETURNING rowid INTO projected_log_rowid;
        INSERT INTO log_metadata (rowid,turn_id,request_id,prompt_trace_id)
        VALUES (projected_log_rowid,source_row.turn_id,source_row.request_id,source_row.prompt_trace_id);
    ELSE
        UPDATE log SET
            localts=extract(epoch FROM source_row.created_at)::bigint,
            prompt=(source_row.source_manifest->'message'->'_prompt')::text,
            response=source_row.response
        WHERE rowid=projected_log_rowid;
        UPDATE log_metadata SET prompt_trace_id=source_row.prompt_trace_id
        WHERE rowid=projected_log_rowid;
    END IF;
END
$$;


--
-- Name: relationship_identity_key(jsonb); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.relationship_identity_key(identity jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT jsonb_build_object('kind',identity->'kind','record_id',identity->'record_id',
    'content_file',identity->'content_file','refnum',identity->'refnum') $$;


--
-- Name: remove_action_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_action_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_rowid integer;
BEGIN
    SELECT rowid INTO projected_rowid FROM action_issued_metadata WHERE action_id=OLD.action_id;
    IF projected_rowid IS NOT NULL THEN
        DELETE FROM action_issued_metadata WHERE rowid=projected_rowid;
        DELETE FROM actions_issued WHERE rowid=projected_rowid;
    END IF;
    RETURN OLD;
END
$$;


--
-- Name: remove_configuration_projection(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_configuration_projection(source_configuration uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_connector_id integer;
DECLARE setting_id text;
BEGIN
    SELECT metadata.connector_id INTO projected_connector_id
    FROM llm_connector_metadata metadata
    WHERE metadata.configuration_id=source_configuration;
    IF projected_connector_id IS NOT NULL THEN
        UPDATE core_profiles SET
            llm_primary_id=NULLIF(llm_primary_id,projected_connector_id),
            llm_secondary_id=NULLIF(llm_secondary_id,projected_connector_id),
            llm_tertiary_id=NULLIF(llm_tertiary_id,projected_connector_id),
            llm_quaternary_id=NULLIF(llm_quaternary_id,projected_connector_id),
            llm_formatter_id=NULLIF(llm_formatter_id,projected_connector_id),
            llm_fallback_id=NULLIF(llm_fallback_id,projected_connector_id),
            diary_connector_id=NULLIF(diary_connector_id,projected_connector_id);
        DELETE FROM llm_connector_metadata WHERE configuration_id=source_configuration;
        DELETE FROM core_llm_connector WHERE id=projected_connector_id;
    END IF;

    projected_connector_id := NULL;
    SELECT metadata.connector_id INTO projected_connector_id
    FROM tts_connector_metadata metadata
    WHERE metadata.configuration_id=source_configuration;
    IF projected_connector_id IS NOT NULL THEN
        UPDATE core_profiles SET tts_connector_id=NULLIF(tts_connector_id,projected_connector_id);
        DELETE FROM tts_connector_metadata WHERE configuration_id=source_configuration;
        DELETE FROM core_tts_connector WHERE id=projected_connector_id;
    END IF;

    FOR setting_id IN
        SELECT metadata.id FROM general_setting_metadata metadata
        WHERE metadata.source_configuration_id=source_configuration
    LOOP
        DELETE FROM general_settings WHERE id=setting_id;
    END LOOP;
END
$$;


--
-- Name: remove_core_profile_projection(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_core_profile_projection(source_profile uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_id integer;
BEGIN
    SELECT metadata.core_profile_id INTO projected_id
    FROM core_profile_metadata metadata
    WHERE metadata.source_core_profile_id=source_profile;
    IF projected_id IS NOT NULL THEN
        DELETE FROM core_profile_metadata WHERE core_profile_id=projected_id;
        DELETE FROM core_profiles WHERE id=projected_id;
    END IF;
END
$$;


--
-- Name: remove_description_projection(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_description_projection(source_description uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE source_row record;
BEGIN
    SELECT * INTO source_row FROM description_metadata WHERE description_id=source_description;
    IF FOUND THEN
        DELETE FROM descriptions_custom WHERE plugin=source_row.plugin AND baseid=source_row.baseid;
        DELETE FROM market_cache WHERE plugin=source_row.plugin AND baseid=source_row.baseid;
        DELETE FROM description_metadata WHERE description_id=source_description;
    END IF;
END
$$;


--
-- Name: remove_knowledge_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_knowledge_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_topic text;
BEGIN
    SELECT topic INTO projected_topic FROM oghma_metadata WHERE document_id=OLD.document_id;
    IF projected_topic IS NOT NULL THEN
        DELETE FROM oghma_metadata WHERE topic=projected_topic;
        DELETE FROM oghma WHERE topic=projected_topic;
    END IF;
    RETURN OLD;
END
$$;


--
-- Name: remove_memory_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_memory_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE
    projected_rowid bigint;
BEGIN
    SELECT rowid INTO projected_rowid
    FROM memory_metadata
    WHERE memory_id = OLD.memory_id;
    IF projected_rowid IS NOT NULL THEN
        DELETE FROM memory_summary_metadata WHERE memory_id = OLD.memory_id;
        DELETE FROM memory_metadata WHERE memory_id = OLD.memory_id;
        DELETE FROM memory WHERE rowid = projected_rowid;
    END IF;
    RETURN OLD;
END
$$;


--
-- Name: remove_narrative_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_narrative_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_rowid bigint;
BEGIN
    SELECT rowid INTO projected_rowid FROM diarylog_metadata WHERE narrative_id=OLD.narrative_id;
    IF projected_rowid IS NOT NULL THEN
        DELETE FROM diarylog_metadata WHERE rowid=projected_rowid;
        DELETE FROM diarylog WHERE rowid=projected_rowid;
    END IF;
    RETURN OLD;
END
$$;


--
-- Name: remove_npc_projection(text); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.remove_npc_projection(source_profile text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_id integer;
DECLARE projected_name text;
BEGIN
    SELECT metadata.npc_id,npc.npc_name INTO projected_id,projected_name
    FROM npc_metadata metadata
    JOIN core_npc_master npc ON npc.id=metadata.npc_id
    WHERE metadata.source_profile_id=source_profile;
    IF projected_id IS NOT NULL THEN
        DELETE FROM bio_templates_custom WHERE npc_name=projected_name AND EXISTS (SELECT 1 FROM lorkhan_internal.profiles owner WHERE owner.profile_id=source_profile AND owner.playthrough_id IS NULL);
        DELETE FROM npc_metadata WHERE npc_id=projected_id;
        DELETE FROM core_npc_master WHERE id=projected_id;
    END IF;
END
$$;


--
-- Name: seed_npc_reference_groups(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.seed_npc_reference_groups() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  INSERT INTO lorkhan_internal.npc_reference_groups(installation_id,group_key,name,canonical_ref,aliases)
  VALUES
    (NEW.installation_id,'dagoth-ur','Dagoth Ur','morrowind.esm|258093','["morrowind.esm|262014"]'::jsonb),
    (NEW.installation_id,'almalexia','Almalexia','tribunal.esm|14405','["tribunal.esm|21659"]'::jsonb),
    (NEW.installation_id,'thormoor-gray-wave','Thormoor Gray-Wave','bloodmoon.esm|14571','["bloodmoon.esm|14593","bloodmoon.esm|19353"]'::jsonb);
  RETURN NEW;
END;
$$;


--
-- Name: sync_action_catalog_projection(text); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_action_catalog_projection(source_name text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE source_row record;
DECLARE projected_id integer;
DECLARE source_found boolean;
BEGIN
    SELECT * INTO source_row FROM lorkhan_internal.action_catalog WHERE action_name=source_name;
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
$$;


--
-- Name: sync_action_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_action_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_rowid integer;
DECLARE game_time numeric;
BEGIN
    SELECT rowid INTO projected_rowid FROM action_issued_metadata WHERE action_id=NEW.action_id;
    SELECT COALESCE((se.payload#>>'{context,world,game_time}')::numeric,0)
    INTO game_time
    FROM lorkhan_internal.source_events se
    WHERE se.turn_id=NEW.turn_id
    ORDER BY se.received_at DESC LIMIT 1;
    game_time := COALESCE(game_time,0);

    IF projected_rowid IS NULL THEN
        INSERT INTO actions_issued (
            action,fullcall,actorname,ts,localts,gamets,original
        ) VALUES (
            NEW.action_name,
            jsonb_build_object('name',NEW.action_name,'tier',NEW.tier,'actor',NEW.actor,
                'target',NEW.target,'parameters',NEW.parameters)::text,
            COALESCE(NEW.actor->>'display_name',NEW.actor->>'record_id'),
            extract(epoch FROM NEW.emitted_at)*1000,extract(epoch FROM NEW.emitted_at),game_time,
            jsonb_build_object('action_id',NEW.action_id,'request_id',NEW.request_id,
                'expires_at',NEW.expires_at)::text
        ) RETURNING rowid INTO projected_rowid;
        INSERT INTO action_issued_metadata (
            rowid,action_id,session_id,turn_id,request_id,state
        ) VALUES (
            projected_rowid,NEW.action_id,NEW.session_id,NEW.turn_id,NEW.request_id,NEW.state
        );
    ELSE
        UPDATE actions_issued SET
            action=NEW.action_name,
            fullcall=jsonb_build_object('name',NEW.action_name,'tier',NEW.tier,'actor',NEW.actor,
                'target',NEW.target,'parameters',NEW.parameters)::text,
            actorname=COALESCE(NEW.actor->>'display_name',NEW.actor->>'record_id'),
            ts=extract(epoch FROM NEW.emitted_at)*1000,localts=extract(epoch FROM NEW.emitted_at),
            gamets=game_time,
            original=jsonb_build_object('action_id',NEW.action_id,'request_id',NEW.request_id,
                'expires_at',NEW.expires_at)::text
        WHERE rowid=projected_rowid;
        UPDATE action_issued_metadata SET state=NEW.state WHERE rowid=projected_rowid;
    END IF;
    RETURN NEW;
END
$$;


--
-- Name: sync_configuration_projection(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_configuration_projection(source_configuration uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE source_row record;
DECLARE projected_connector_id integer;
BEGIN
    SELECT configuration.*,revision.content
    INTO source_row
    FROM lorkhan_internal.configuration_sets configuration
    JOIN lorkhan_internal.configuration_revisions revision
      ON revision.configuration_id=configuration.configuration_id
     AND revision.revision=configuration.current_revision
    WHERE configuration.configuration_id=source_configuration;

    IF NOT FOUND OR source_row.deleted_at IS NOT NULL THEN
        PERFORM remove_configuration_projection(source_configuration);
        RETURN;
    END IF;

    IF source_row.kind='provider' THEN
        SELECT metadata.connector_id INTO projected_connector_id
        FROM llm_connector_metadata metadata
        WHERE metadata.configuration_id=source_configuration;
        IF projected_connector_id IS NULL THEN
            INSERT INTO core_llm_connector (
                label,metadata,url,model,provider,driver,service
            ) VALUES (
                source_row.name,source_row.content,source_row.content->>'endpoint',
                source_row.content->>'model',source_row.content->>'driver',
                source_row.content->>'driver','llm'
            ) RETURNING id INTO projected_connector_id;
            INSERT INTO llm_connector_metadata (
                connector_id,installation_id,configuration_id,configuration_revision
            ) VALUES (
                projected_connector_id,source_row.installation_id,source_configuration,source_row.current_revision
            );
        ELSE
            UPDATE core_llm_connector SET
                label=source_row.name,metadata=source_row.content,url=source_row.content->>'endpoint',
                model=source_row.content->>'model',provider=source_row.content->>'driver',
                driver=source_row.content->>'driver',service='llm'
            WHERE id=projected_connector_id;
            UPDATE llm_connector_metadata SET
                installation_id=source_row.installation_id,
                configuration_revision=source_row.current_revision
            WHERE connector_id=projected_connector_id;
        END IF;
    ELSIF source_row.kind='tts_provider' THEN
        projected_connector_id := NULL;
        SELECT metadata.connector_id INTO projected_connector_id
        FROM tts_connector_metadata metadata
        WHERE metadata.configuration_id=source_configuration;
        IF projected_connector_id IS NULL THEN
            INSERT INTO core_tts_connector (driver,label,metadata,url,voice_field)
            VALUES (
                source_row.content->>'driver',source_row.name,source_row.content,
                source_row.content->>'endpoint','voice'
            ) RETURNING id INTO projected_connector_id;
            INSERT INTO tts_connector_metadata (
                connector_id,installation_id,configuration_id,configuration_revision
            ) VALUES (
                projected_connector_id,source_row.installation_id,source_configuration,source_row.current_revision
            );
        ELSE
            UPDATE core_tts_connector SET
                driver=source_row.content->>'driver',label=source_row.name,
                metadata=source_row.content,url=source_row.content->>'endpoint',voice_field='voice'
            WHERE id=projected_connector_id;
            UPDATE tts_connector_metadata SET
                installation_id=source_row.installation_id,
                configuration_revision=source_row.current_revision
            WHERE connector_id=projected_connector_id;
        END IF;
    ELSIF source_row.kind='global_settings' THEN
        DELETE FROM general_settings settings
        USING general_setting_metadata metadata
        WHERE metadata.id=settings.id
          AND metadata.source_configuration_id=source_configuration
          AND NOT (source_row.content ? metadata.setting_key);

        INSERT INTO general_settings (id,value,description,updated_at)
        SELECT 'lorkhan.'||entry.key,entry.value::text,
               'LORKHAN Global Settings '||entry.key,clock_timestamp() AT TIME ZONE 'UTC'
        FROM jsonb_each(source_row.content) entry
        ON CONFLICT (id) DO UPDATE SET
            value=EXCLUDED.value,description=EXCLUDED.description,updated_at=EXCLUDED.updated_at;

        INSERT INTO general_setting_metadata (
            id,installation_id,source_configuration_id,source_revision,setting_key
        )
        SELECT 'lorkhan.'||entry.key,source_row.installation_id,source_configuration,
               source_row.current_revision,entry.key
        FROM jsonb_each(source_row.content) entry
        ON CONFLICT (id) DO UPDATE SET
            installation_id=EXCLUDED.installation_id,
            source_configuration_id=EXCLUDED.source_configuration_id,
            source_revision=EXCLUDED.source_revision,
            setting_key=EXCLUDED.setting_key;
    END IF;
END
$$;


--
-- Name: sync_core_profile_projection(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_core_profile_projection(source_profile uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE source_row record;
DECLARE projected_id integer;
DECLARE tts_id integer;
DECLARE primary_id integer;
DECLARE secondary_id integer;
DECLARE tertiary_id integer;
DECLARE quaternary_id integer;
DECLARE fallback_id integer;
DECLARE compatible_slot integer;
BEGIN
    SELECT profile.*,revision.content
    INTO source_row
    FROM lorkhan_core_profiles_source profile
    JOIN lorkhan_internal.core_profile_revisions revision
      ON revision.core_profile_id=profile.core_profile_id
     AND revision.revision=profile.current_revision
    WHERE profile.core_profile_id=source_profile;
    IF NOT FOUND OR source_row.deleted_at IS NOT NULL THEN
        PERFORM remove_core_profile_projection(source_profile);
        RETURN;
    END IF;

    SELECT connector_id INTO tts_id FROM tts_connector_metadata
    WHERE configuration_id=NULLIF(source_row.content#>>'{routing,tts_configuration_id}','')::uuid;
    SELECT connector_id INTO primary_id FROM llm_connector_metadata
    WHERE configuration_id=NULLIF(source_row.content#>>'{routing,llm_configuration_id}','')::uuid;
    SELECT connector_id INTO secondary_id FROM llm_connector_metadata
    WHERE configuration_id=NULLIF(source_row.content#>>'{routing,llm_fast_configuration_id}','')::uuid;
    SELECT connector_id INTO tertiary_id FROM llm_connector_metadata
    WHERE configuration_id=NULLIF(source_row.content#>>'{routing,llm_powerful_configuration_id}','')::uuid;
    SELECT connector_id INTO quaternary_id FROM llm_connector_metadata
    WHERE configuration_id=NULLIF(source_row.content#>>'{routing,llm_experimental_configuration_id}','')::uuid;
    SELECT connector_id INTO fallback_id FROM llm_connector_metadata
    WHERE configuration_id=NULLIF(source_row.content#>>'{routing,llm_fallback_configuration_id}','')::uuid;

    SELECT metadata.core_profile_id INTO projected_id
    FROM core_profile_metadata metadata
    WHERE metadata.source_core_profile_id=source_profile;
    compatible_slot := source_row.slot;
    IF compatible_slot IS NOT NULL AND EXISTS (
        SELECT 1 FROM core_profiles existing
        WHERE existing.slot=compatible_slot AND existing.id IS DISTINCT FROM projected_id
    ) THEN
        compatible_slot := NULL;
    END IF;
    IF projected_id IS NULL THEN
        INSERT INTO core_profiles (
            label,default_npc,default_narrator,tts_connector_id,llm_primary_id,
            llm_secondary_id,llm_tertiary_id,llm_quaternary_id,llm_fallback_id,
            metadata,slot,prompt
        ) VALUES (
            source_row.label,CASE WHEN source_row.default_npc THEN '1' ELSE '0' END,'0',
            tts_id,primary_id,secondary_id,tertiary_id,quaternary_id,fallback_id,
            source_row.content,compatible_slot,source_row.content->>'prompt'
        ) RETURNING id INTO projected_id;
        INSERT INTO core_profile_metadata (
            core_profile_id,installation_id,source_core_profile_id,source_revision,source_slot
        ) VALUES (
            projected_id,source_row.installation_id,source_profile,source_row.current_revision,source_row.slot
        );
    ELSE
        UPDATE core_profiles SET
            label=source_row.label,
            default_npc=CASE WHEN source_row.default_npc THEN '1' ELSE '0' END,
            default_narrator='0',tts_connector_id=tts_id,llm_primary_id=primary_id,
            llm_secondary_id=secondary_id,llm_tertiary_id=tertiary_id,
            llm_quaternary_id=quaternary_id,llm_fallback_id=fallback_id,
            metadata=source_row.content,slot=compatible_slot,prompt=source_row.content->>'prompt'
        WHERE id=projected_id;
        UPDATE core_profile_metadata SET
            installation_id=source_row.installation_id,source_revision=source_row.current_revision,
            source_slot=source_row.slot
        WHERE core_profile_id=projected_id;
    END IF;
END
$$;


--
-- Name: sync_description_projection(uuid); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_description_projection(source_description uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE source_row record;
BEGIN
    SELECT * INTO source_row FROM lorkhan_internal.item_descriptions WHERE description_id=source_description;
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
$$;


--
-- Name: sync_knowledge_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_knowledge_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_topic text;
BEGIN
    SELECT topic INTO projected_topic FROM oghma_metadata WHERE document_id=NEW.document_id;
    IF NEW.deleted_at IS NOT NULL THEN
        IF projected_topic IS NOT NULL THEN
            DELETE FROM oghma_metadata WHERE topic=projected_topic;
            DELETE FROM oghma WHERE topic=projected_topic;
        END IF;
        RETURN NEW;
    END IF;
    IF projected_topic IS NULL THEN
        projected_topic := NEW.topic;
        IF EXISTS (SELECT 1 FROM oghma WHERE topic=projected_topic) THEN
            projected_topic := NEW.topic||' ['||left(NEW.document_id::text,8)||']';
        END IF;
        INSERT INTO oghma(topic,topic_desc,native_vector,knowledge_class,topic_desc_basic,
            knowledge_class_basic,tags,category,aliases)
        VALUES(projected_topic,NEW.content,to_tsvector('simple',concat_ws(' ',NEW.topic,NEW.aliases,NEW.content,NEW.tags)),
            NEW.knowledge_class,NEW.topic_desc_basic,NEW.knowledge_class_basic,NEW.tags,NEW.category,NEW.aliases);
        INSERT INTO oghma_metadata(topic,document_id,installation_id,profile_id,playthrough_id)
        VALUES(projected_topic,NEW.document_id,NEW.installation_id,NEW.profile_id,NEW.playthrough_id);
    ELSE
        UPDATE oghma SET topic_desc=NEW.content,
            native_vector=to_tsvector('simple',concat_ws(' ',NEW.topic,NEW.aliases,NEW.content,NEW.tags)),
            knowledge_class=NEW.knowledge_class,topic_desc_basic=NEW.topic_desc_basic,
            knowledge_class_basic=NEW.knowledge_class_basic,tags=NEW.tags,category=NEW.category,aliases=NEW.aliases
        WHERE topic=projected_topic;
        UPDATE oghma_metadata SET installation_id=NEW.installation_id,profile_id=NEW.profile_id,
            playthrough_id=NEW.playthrough_id WHERE topic=projected_topic;
    END IF;
    RETURN NEW;
END
$$;


--
-- Name: sync_memory_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_memory_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE
    projected_rowid bigint;
    projected_uid integer;
    summary_rowid integer;
    profile_name text;
BEGIN
    SELECT rowid INTO projected_rowid
    FROM memory_metadata
    WHERE memory_id = NEW.memory_id;

    IF NEW.deleted_at IS NOT NULL THEN
        IF projected_rowid IS NOT NULL THEN
            DELETE FROM memory_summary_metadata WHERE memory_id = NEW.memory_id;
            DELETE FROM memory_metadata WHERE memory_id = NEW.memory_id;
            DELETE FROM memory WHERE rowid = projected_rowid;
        END IF;
        RETURN NEW;
    END IF;

    SELECT name INTO profile_name FROM lorkhan_internal.profiles WHERE profile_id = NEW.profile_id;
    IF projected_rowid IS NULL THEN
        INSERT INTO memory (
            speaker,message,session,listener,localts,gamets,momentum,event,ts
        ) VALUES (
            profile_name,NEW.content,NEW.playthrough_id::text,'Player',
            extract(epoch FROM NEW.occurred_at)::bigint,0,NEW.tier,NEW.tier,
            (extract(epoch FROM NEW.occurred_at)*1000)::bigint
        ) RETURNING rowid,uid INTO projected_rowid,projected_uid;
        INSERT INTO memory_metadata (
            rowid,memory_id,installation_id,profile_id,playthrough_id,tier,source_event_id
        ) VALUES (
            projected_rowid,NEW.memory_id,NEW.installation_id,NEW.profile_id,
            NEW.playthrough_id,NEW.tier,NEW.source_event_id
        );
    ELSE
        UPDATE memory SET
            speaker=profile_name,message=NEW.content,session=NEW.playthrough_id::text,
            listener='Player',localts=extract(epoch FROM NEW.occurred_at)::bigint,
            gamets=0,momentum=NEW.tier,event=NEW.tier,
            ts=(extract(epoch FROM NEW.occurred_at)*1000)::bigint
        WHERE rowid=projected_rowid
        RETURNING uid INTO projected_uid;
        UPDATE memory_metadata SET
            installation_id=NEW.installation_id,profile_id=NEW.profile_id,
            playthrough_id=NEW.playthrough_id,tier=NEW.tier,source_event_id=NEW.source_event_id
        WHERE rowid=projected_rowid;
    END IF;

    IF NEW.tier IN ('mid','long') THEN
        SELECT rowid INTO summary_rowid
        FROM memory_summary_metadata
        WHERE memory_id=NEW.memory_id;
        IF summary_rowid IS NULL THEN
            INSERT INTO memory_summary (
                gamets_truncated,n,packed_message,summary,classifier,uid,companions,tags,native_vec,scope
            ) VALUES (
                0,1,NEW.content,NEW.content,NEW.tier,projected_uid,NULL,
                array_to_string(NEW.lexical_terms,','),to_tsvector('simple',NEW.content),NEW.playthrough_id::text
            ) RETURNING rowid INTO summary_rowid;
            INSERT INTO memory_summary_metadata (rowid,memory_id)
            VALUES (summary_rowid,NEW.memory_id);
        ELSE
            UPDATE memory_summary SET
                gamets_truncated=0,n=1,packed_message=NEW.content,summary=NEW.content,
                classifier=NEW.tier,uid=projected_uid,tags=array_to_string(NEW.lexical_terms,','),
                native_vec=to_tsvector('simple',NEW.content),scope=NEW.playthrough_id::text
            WHERE rowid=summary_rowid;
        END IF;
    ELSE
        SELECT rowid INTO summary_rowid
        FROM memory_summary_metadata
        WHERE memory_id=NEW.memory_id;
        IF summary_rowid IS NOT NULL THEN
            DELETE FROM memory_summary_metadata WHERE rowid=summary_rowid;
            DELETE FROM memory_summary WHERE rowid=summary_rowid;
        END IF;
    END IF;
    RETURN NEW;
END
$$;


--
-- Name: sync_narrative_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_narrative_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE projected_rowid bigint;
DECLARE profile_name text;
BEGIN
    SELECT rowid INTO projected_rowid FROM diarylog_metadata WHERE narrative_id=NEW.narrative_id;
    IF NEW.deleted_at IS NOT NULL THEN
        IF projected_rowid IS NOT NULL THEN
            DELETE FROM diarylog_metadata WHERE rowid=projected_rowid;
            DELETE FROM diarylog WHERE rowid=projected_rowid;
        END IF;
        RETURN NEW;
    END IF;
    SELECT name INTO profile_name FROM lorkhan_internal.profiles WHERE profile_id=NEW.profile_id;
    IF projected_rowid IS NULL THEN
        INSERT INTO diarylog (
            ts,sess,topic,content,tags,people,localts,location,gamets
        ) VALUES (
            NEW.created_at::text,NEW.playthrough_id::text,NEW.title,NEW.content,NEW.kind,
            profile_name,extract(epoch FROM NEW.created_at)::bigint,NEW.provenance->>'location',0
        ) RETURNING rowid INTO projected_rowid;
        INSERT INTO diarylog_metadata (
            rowid,narrative_id,installation_id,profile_id,playthrough_id
        ) VALUES (
            projected_rowid,NEW.narrative_id,NEW.installation_id,NEW.profile_id,NEW.playthrough_id
        );
    ELSE
        UPDATE diarylog SET
            ts=NEW.created_at::text,sess=NEW.playthrough_id::text,topic=NEW.title,
            content=NEW.content,tags=NEW.kind,people=profile_name,
            localts=extract(epoch FROM NEW.updated_at)::bigint,
            location=NEW.provenance->>'location',gamets=0
        WHERE rowid=projected_rowid;
    END IF;

    IF NEW.kind='diary' THEN
        INSERT INTO physical_npc_diaries (
            npc_name,title,last_diary_localts,created_at,updated_at
        ) VALUES (
            COALESCE(profile_name,'Unknown NPC'),NEW.title,
            extract(epoch FROM NEW.updated_at)::bigint,extract(epoch FROM NEW.created_at)::bigint,
            extract(epoch FROM NEW.updated_at)::bigint
        ) ON CONFLICT (npc_name) DO UPDATE SET
            title=EXCLUDED.title,last_diary_localts=EXCLUDED.last_diary_localts,
            updated_at=EXCLUDED.updated_at;
    END IF;
    RETURN NEW;
END
$$;


--
-- Name: sync_openmw_record_identity(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_openmw_record_identity() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'lorkhan_internal', 'public', 'pg_temp'
    AS $$
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
$$;


--
-- Name: sync_playthrough_table_policy(jsonb); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_playthrough_table_policy(policy jsonb) RETURNS void
    LANGUAGE plpgsql
    SET lock_timeout TO '10s'
    AS $$
DECLARE item record;
DECLARE expected text;
BEGIN
    FOR item IN SELECT n.nspname,c.relname,obj_description(c.oid,'pg_class') AS comment
        FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p')
    LOOP
        expected:=CASE WHEN COALESCE((policy->(item.nspname||'.'||item.relname)->>'local_save')::boolean,false)
            THEN 'Playthrough Manager Backed Up' ELSE NULL END;
        IF item.comment IS DISTINCT FROM expected THEN
            EXECUTE format('COMMENT ON TABLE %I.%I IS %L',item.nspname,item.relname,expected);
        END IF;
    END LOOP;
END;
$$;


--
-- Name: sync_profile_projection(text); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_profile_projection(source_profile text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
DECLARE source_row record;
DECLARE projected_id integer;
DECLARE projected_name text;
DECLARE inherited_profile integer;
BEGIN
    SELECT profile.*,revision.content,revision.created_at AS revision_created_at
    INTO source_row
    FROM lorkhan_internal.profiles profile
    JOIN lorkhan_internal.profile_revisions revision
      ON revision.profile_id=profile.profile_id AND revision.revision=profile.current_revision
    WHERE profile.profile_id=source_profile;

    IF NOT FOUND OR source_row.deleted_at IS NOT NULL THEN
        PERFORM remove_npc_projection(source_profile);
        PERFORM rebuild_special_profiles();
        RETURN;
    END IF;

    IF source_row.actor_identity->>'kind' IN ('player','narrator') THEN
        PERFORM remove_npc_projection(source_profile);
        PERFORM rebuild_special_profiles();
        RETURN;
    END IF;
    IF COALESCE(source_row.actor_identity->>'kind','actor') NOT IN ('npc','actor','creature') THEN
        PERFORM remove_npc_projection(source_profile);
        RETURN;
    END IF;

    SELECT core_profile_id INTO inherited_profile
    FROM core_profile_metadata
    WHERE source_core_profile_id=source_row.core_profile_id;
    SELECT metadata.npc_id,npc.npc_name INTO projected_id,projected_name
    FROM npc_metadata metadata
    JOIN core_npc_master npc ON npc.id=metadata.npc_id
    WHERE metadata.source_profile_id=source_profile;

    IF projected_id IS NULL THEN
        INSERT INTO core_npc_master (
            npc_name,npc_favorite,lock_profile,prompt_head,npc_static_bio,
            oghma_knowledge_tags,emote_moods,personality,relationships,occupation,
            appearance,skills,speechstyle,goals,voiceid,metadata,gender,race,refid,
            profile_id,dynamic_profile,extended_data,md5,core,base,tags
        ) VALUES (
            source_row.name,
            CASE WHEN COALESCE((source_row.content#>>'{management,favorite}')::boolean,false) THEN 1 ELSE 0 END,
            CASE WHEN COALESCE((source_row.content#>>'{management,locked}')::boolean,false) THEN 1 ELSE 0 END,
            source_row.content->>'prompt_head',source_row.content->>'biography',
            source_row.content->>'oghma_knowledge_tags',source_row.content->>'emote_moods',
            source_row.content->>'personality',source_row.content->>'relationships',
            source_row.content->>'occupation',source_row.content->>'appearance',
            source_row.content->>'skills',source_row.content->>'speech_style',source_row.content->>'goals',
            source_row.content#>>'{voice,id}',(source_row.actor_identity || jsonb_build_object('mods',CASE WHEN COALESCE(source_row.actor_identity->>'content_file','')='' THEN '[]'::jsonb ELSE jsonb_build_array(source_row.actor_identity->>'content_file') END)),source_row.content->>'gender',
            source_row.content->>'race',(source_row.actor_identity#>>'{refnum,index}'),inherited_profile,0,
            jsonb_build_object('actor_identity',source_row.actor_identity,'lorkhan_profile',source_row.content),
            md5(source_row.content::text),source_row.content->>'core',
            source_row.actor_identity->>'record_id',source_row.content->>'tags'
        ) RETURNING id INTO projected_id;
        INSERT INTO npc_metadata (
            npc_id,installation_id,source_profile_id,source_revision,actor_identity
        ) VALUES (
            projected_id,source_row.installation_id,source_profile,
            source_row.current_revision,source_row.actor_identity
        );
    ELSE
        IF projected_name IS DISTINCT FROM source_row.name THEN
            DELETE FROM bio_templates_custom WHERE npc_name=projected_name AND EXISTS (SELECT 1 FROM lorkhan_internal.profiles owner WHERE owner.profile_id=source_profile AND owner.playthrough_id IS NULL);
        END IF;
        UPDATE core_npc_master SET
            npc_name=source_row.name,
            npc_favorite=CASE WHEN COALESCE((source_row.content#>>'{management,favorite}')::boolean,false) THEN 1 ELSE 0 END,
            lock_profile=CASE WHEN COALESCE((source_row.content#>>'{management,locked}')::boolean,false) THEN 1 ELSE 0 END,
            prompt_head=source_row.content->>'prompt_head',npc_static_bio=source_row.content->>'biography',
            oghma_knowledge_tags=source_row.content->>'oghma_knowledge_tags',
            emote_moods=source_row.content->>'emote_moods',personality=source_row.content->>'personality',
            relationships=source_row.content->>'relationships',occupation=source_row.content->>'occupation',
            appearance=source_row.content->>'appearance',skills=source_row.content->>'skills',
            speechstyle=source_row.content->>'speech_style',goals=source_row.content->>'goals',
            voiceid=source_row.content#>>'{voice,id}',metadata=(source_row.actor_identity || jsonb_build_object('mods',CASE WHEN COALESCE(source_row.actor_identity->>'content_file','')='' THEN '[]'::jsonb ELSE jsonb_build_array(source_row.actor_identity->>'content_file') END)),
            gender=source_row.content->>'gender',race=source_row.content->>'race',
            refid=(source_row.actor_identity#>>'{refnum,index}'),profile_id=inherited_profile,
            dynamic_profile=0,
            extended_data=jsonb_build_object('actor_identity',source_row.actor_identity,'lorkhan_profile',source_row.content),
            md5=md5(source_row.content::text),core=source_row.content->>'core',
            base=source_row.actor_identity->>'record_id',tags=source_row.content->>'tags'
        WHERE id=projected_id;
        UPDATE npc_metadata SET
            installation_id=source_row.installation_id,source_revision=source_row.current_revision,
            actor_identity=source_row.actor_identity
        WHERE npc_id=projected_id;
    END IF;

    INSERT INTO core_npc_master_history (
        npc_id,npc_name,npc_favorite,lock_profile,prompt_head,npc_static_bio,
        oghma_knowledge_tags,emote_moods,personality,relationships,occupation,
        appearance,skills,speechstyle,goals,voiceid,metadata,gender,race,refid,
        profile_id,dynamic_profile,extended_data,md5,created,core,base,tags
    )
    SELECT projected_id,source_row.name,
           CASE WHEN COALESCE((source_row.content#>>'{management,favorite}')::boolean,false) THEN 1 ELSE 0 END,
           CASE WHEN COALESCE((source_row.content#>>'{management,locked}')::boolean,false) THEN 1 ELSE 0 END,
           source_row.content->>'prompt_head',source_row.content->>'biography',
           source_row.content->>'oghma_knowledge_tags',source_row.content->>'emote_moods',
           source_row.content->>'personality',source_row.content->>'relationships',
           source_row.content->>'occupation',source_row.content->>'appearance',
           source_row.content->>'skills',source_row.content->>'speech_style',source_row.content->>'goals',
           source_row.content#>>'{voice,id}',(source_row.actor_identity || jsonb_build_object('mods',CASE WHEN COALESCE(source_row.actor_identity->>'content_file','')='' THEN '[]'::jsonb ELSE jsonb_build_array(source_row.actor_identity->>'content_file') END)),source_row.content->>'gender',
           source_row.content->>'race',(source_row.actor_identity#>>'{refnum,index}'),inherited_profile,0,
           jsonb_build_object('actor_identity',source_row.actor_identity,'lorkhan_profile',source_row.content,
                              'lorkhan_revision',source_row.current_revision),
           md5(source_row.content::text),source_row.revision_created_at AT TIME ZONE 'UTC',
           source_row.content->>'core',source_row.actor_identity->>'record_id',source_row.content->>'tags'
    WHERE NOT EXISTS (
        SELECT 1 FROM core_npc_master_history history
        WHERE history.npc_id=projected_id
          AND history.extended_data->>'lorkhan_revision'=source_row.current_revision::text
    );

    IF source_row.playthrough_id IS NOT NULL THEN
        PERFORM refresh_npc_relationships(source_profile);
        RETURN;
    END IF;

    INSERT INTO bio_templates_custom (
        npc_name,oghma_knowledge_tags,core,npc_static_bio,appearance,personality,
        relationships,occupation,skills,speechstyle,goals,voiceid,gender,race,refid
    ) VALUES (
        source_row.name,source_row.content->>'oghma_knowledge_tags',source_row.content->>'core',
        source_row.content->>'biography',source_row.content->>'appearance',
        source_row.content->>'personality',source_row.content->>'relationships',
        source_row.content->>'occupation',source_row.content->>'skills',
        source_row.content->>'speech_style',source_row.content->>'goals',
        source_row.content#>>'{voice,id}',source_row.content->>'gender',
        source_row.content->>'race',source_row.actor_identity->>'record_id'
    ) ON CONFLICT (npc_name) DO UPDATE SET
        oghma_knowledge_tags=EXCLUDED.oghma_knowledge_tags,core=EXCLUDED.core,
        npc_static_bio=EXCLUDED.npc_static_bio,appearance=EXCLUDED.appearance,
        personality=EXCLUDED.personality,relationships=EXCLUDED.relationships,
        occupation=EXCLUDED.occupation,skills=EXCLUDED.skills,speechstyle=EXCLUDED.speechstyle,
        goals=EXCLUDED.goals,voiceid=EXCLUDED.voiceid,gender=EXCLUDED.gender,
        race=EXCLUDED.race,refid=EXCLUDED.refid;

    PERFORM refresh_npc_relationships(source_profile);
END
$$;


--
-- Name: sync_prompt_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_prompt_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    IF TG_OP='DELETE' THEN
        DELETE FROM prompt_metadata
        WHERE prompt_key=OLD.prompt_key AND installation_id=OLD.installation_id;
        IF NOT EXISTS (SELECT 1 FROM prompt_metadata WHERE prompt_key=OLD.prompt_key) THEN
            DELETE FROM prompts WHERE prompt_key=OLD.prompt_key;
        END IF;
        RETURN OLD;
    END IF;
    INSERT INTO prompts (
        prompt_key,default_prompt,custom_prompt,description,created_at,updated_at
    ) VALUES (
        NEW.prompt_key,NEW.default_prompt,NEW.custom_prompt,NEW.description,
        NEW.created_at AT TIME ZONE 'UTC',NEW.updated_at AT TIME ZONE 'UTC'
    ) ON CONFLICT (prompt_key) DO UPDATE SET
        default_prompt=EXCLUDED.default_prompt,custom_prompt=EXCLUDED.custom_prompt,
        description=EXCLUDED.description,updated_at=EXCLUDED.updated_at;
    INSERT INTO prompt_metadata (
        prompt_key,installation_id,source_configuration_id,source_revision
    ) VALUES (
        NEW.prompt_key,NEW.installation_id,NEW.source_configuration_id,NEW.source_revision
    ) ON CONFLICT (prompt_key) DO UPDATE SET
        installation_id=EXCLUDED.installation_id,
        source_configuration_id=EXCLUDED.source_configuration_id,
        source_revision=EXCLUDED.source_revision;
    RETURN NEW;
END
$$;


--
-- Name: sync_relationship_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_relationship_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    IF TG_OP='DELETE' THEN
        PERFORM refresh_npc_relationships(OLD.profile_id);
        RETURN OLD;
    END IF;
    PERFORM refresh_npc_relationships(NEW.profile_id);
    IF TG_OP='UPDATE' AND OLD.profile_id IS DISTINCT FROM NEW.profile_id THEN
        PERFORM refresh_npc_relationships(OLD.profile_id);
    END IF;
    RETURN NEW;
END
$$;


--
-- Name: sync_responselog_row(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_responselog_row() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        DELETE FROM responselog_metadata WHERE rowid = OLD.rowid;
        DELETE FROM responselog WHERE rowid = OLD.rowid;
        RETURN OLD;
    END IF;

    UPDATE responselog SET
        localts=NEW.localts,sent=NEW.sent,actor=NEW.actor,text=NEW.text,
        action=NEW.action,tag=NEW.tag
    WHERE rowid=NEW.rowid;
    IF NOT FOUND THEN
        INSERT INTO responselog (rowid,localts,sent,actor,text,action,tag)
        VALUES (NEW.rowid,NEW.localts,NEW.sent,NEW.actor,NEW.text,NEW.action,NEW.tag);
    END IF;

    INSERT INTO responselog_metadata (
        rowid,installation_id,playthrough_id,session_id,turn_id,response_message_id,
        actor_identity,payload,created_at,sent_at
    ) VALUES (
        NEW.rowid,NEW.installation_id,NEW.playthrough_id,NEW.session_id,NEW.turn_id,
        NEW.response_message_id,NEW.actor_identity,NEW.payload,NEW.created_at,NEW.sent_at
    )
    ON CONFLICT (rowid) DO UPDATE SET
        installation_id=EXCLUDED.installation_id,playthrough_id=EXCLUDED.playthrough_id,
        session_id=EXCLUDED.session_id,turn_id=EXCLUDED.turn_id,
        response_message_id=EXCLUDED.response_message_id,
        actor_identity=EXCLUDED.actor_identity,payload=EXCLUDED.payload,
        created_at=EXCLUDED.created_at,sent_at=EXCLUDED.sent_at;
    RETURN NEW;
END
$$;


--
-- Name: sync_speech_row(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.sync_speech_row() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        DELETE FROM speech_metadata WHERE rowid = OLD.rowid;
        DELETE FROM speech WHERE rowid = OLD.rowid;
        RETURN OLD;
    END IF;

    UPDATE speech SET
        sess=NEW.sess,speaker=NEW.speaker,speech=NEW.speech,location=NEW.location,
        listener=NEW.listener,topic=NEW.topic,localts=NEW.localts,gamets=NEW.gamets,
        ts=NEW.ts,companions=NEW.companions,audios=NEW.audios,utterance_id=NEW.utterance_id
    WHERE rowid=NEW.rowid;
    IF NOT FOUND THEN
        INSERT INTO speech (
            rowid,sess,speaker,speech,location,listener,topic,localts,gamets,ts,
            companions,audios,mood,emotion,emotion_intensity,utterance_id
        ) VALUES (
            NEW.rowid,NEW.sess,NEW.speaker,NEW.speech,NEW.location,NEW.listener,NEW.topic,
            NEW.localts,NEW.gamets,NEW.ts,NEW.companions,NEW.audios,NULL,NULL,NULL,NEW.utterance_id
        );
    END IF;

    INSERT INTO speech_metadata (
        rowid,installation_id,playthrough_id,session_id,turn_id,dialogue_message_id,
        speaker_identity,listener_identity,audience,delivery_state,created_at
    ) VALUES (
        NEW.rowid,NEW.installation_id,NEW.playthrough_id,NEW.session_id,NEW.turn_id,
        NEW.dialogue_message_id,NEW.speaker_identity,NEW.listener_identity,NEW.audience,
        NEW.delivery_state,NEW.created_at
    )
    ON CONFLICT (rowid) DO UPDATE SET
        installation_id=EXCLUDED.installation_id,playthrough_id=EXCLUDED.playthrough_id,
        session_id=EXCLUDED.session_id,turn_id=EXCLUDED.turn_id,
        dialogue_message_id=EXCLUDED.dialogue_message_id,
        speaker_identity=EXCLUDED.speaker_identity,listener_identity=EXCLUDED.listener_identity,
        audience=EXCLUDED.audience,delivery_state=EXCLUDED.delivery_state,
        created_at=EXCLUDED.created_at;
    RETURN NEW;
END
$$;


--
-- Name: trigger_action_catalog_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_action_catalog_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
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
$$;


--
-- Name: trigger_configuration_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_configuration_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM sync_configuration_projection(NEW.configuration_id);
    RETURN NEW;
END
$$;


--
-- Name: trigger_configuration_removal(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_configuration_removal() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM remove_configuration_projection(OLD.configuration_id);
    RETURN OLD;
END
$$;


--
-- Name: trigger_core_profile_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_core_profile_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM sync_core_profile_projection(NEW.core_profile_id);
    RETURN NEW;
END
$$;


--
-- Name: trigger_core_profile_removal(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_core_profile_removal() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM remove_core_profile_projection(OLD.core_profile_id);
    RETURN OLD;
END
$$;


--
-- Name: trigger_description_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_description_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    IF TG_OP='DELETE' THEN
        PERFORM remove_description_projection(OLD.description_id);
        RETURN OLD;
    END IF;
    PERFORM sync_description_projection(NEW.description_id);
    RETURN NEW;
END
$$;


--
-- Name: trigger_profile_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_profile_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM sync_profile_projection(NEW.profile_id);
    RETURN NEW;
END
$$;


--
-- Name: trigger_profile_removal(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_profile_removal() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM remove_npc_projection(OLD.profile_id);
    RETURN OLD;
END
$$;


--
-- Name: trigger_turn_audit(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_turn_audit() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM refresh_turn_audit(CASE WHEN TG_OP='DELETE' THEN OLD.turn_id ELSE NEW.turn_id END);
    RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END
$$;


--
-- Name: trigger_turn_world_projection(); Type: FUNCTION; Schema: lorkhan_internal; Owner: -
--

CREATE FUNCTION lorkhan_internal.trigger_turn_world_projection() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'public', 'lorkhan_internal', 'pg_temp'
    AS $$
BEGIN
    PERFORM project_turn_world(NEW.turn_id);
    RETURN NEW;
END
$$;


SET LOCAL default_tablespace = '';

SET LOCAL default_table_access_method = heap;

--
-- Name: action_catalog; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.action_catalog (
    action_name text NOT NULL,
    tier integer NOT NULL,
    description text NOT NULL,
    parameter_schema jsonb NOT NULL,
    client_capability text NOT NULL,
    server_owned boolean DEFAULT true NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    result_schema jsonb DEFAULT '{"type": "object", "additionalProperties": false}'::jsonb NOT NULL,
    max_parameter_bytes integer DEFAULT 16384 NOT NULL,
    terminal_result_required boolean DEFAULT true NOT NULL,
    continuation_capable boolean DEFAULT false NOT NULL,
    display_name text NOT NULL,
    category text NOT NULL,
    sort_order integer DEFAULT 100 NOT NULL,
    confirmation_mode text DEFAULT 'optional'::text NOT NULL,
    followup_default boolean DEFAULT false NOT NULL,
    followup_actions_supported boolean DEFAULT false NOT NULL,
    cooldown_seconds integer DEFAULT 0 NOT NULL,
    available_to_narrator boolean DEFAULT false NOT NULL,
    confirmation_default boolean DEFAULT false NOT NULL,
    CONSTRAINT action_catalog_confirmation_mode_check CHECK ((confirmation_mode = ANY (ARRAY['none'::text, 'optional'::text, 'required'::text]))),
    CONSTRAINT action_catalog_cooldown_seconds_check CHECK (((cooldown_seconds >= 0) AND (cooldown_seconds <= 86400))),
    CONSTRAINT action_catalog_max_parameter_bytes_check CHECK (((max_parameter_bytes >= 2) AND (max_parameter_bytes <= 65536))),
    CONSTRAINT action_catalog_parameter_schema_check CHECK ((jsonb_typeof(parameter_schema) = 'object'::text)),
    CONSTRAINT action_catalog_result_schema_check CHECK ((jsonb_typeof(result_schema) = 'object'::text)),
    CONSTRAINT action_catalog_tier_check CHECK (((tier >= 0) AND (tier <= 3)))
);


--
-- Name: action_catalog_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.action_catalog_metadata (
    action_id integer NOT NULL,
    source_action_name text NOT NULL,
    tier integer NOT NULL,
    client_capability text NOT NULL
);


--
-- Name: action_delivery; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.action_delivery (
    action_id uuid NOT NULL,
    emitted_at timestamp with time zone,
    terminal_at timestamp with time zone,
    continuation_state text DEFAULT 'none'::text NOT NULL,
    continuation_turn_id uuid,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT action_delivery_continuation_state_check CHECK ((continuation_state = ANY (ARRAY['none'::text, 'eligible'::text, 'consumed'::text, 'expired'::text]))),
    CONSTRAINT action_delivery_single_continuation CHECK ((((continuation_state = 'consumed'::text) AND (continuation_turn_id IS NOT NULL)) OR ((continuation_state <> 'consumed'::text) AND (continuation_turn_id IS NULL))))
);


--
-- Name: action_intents; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.action_intents (
    action_id uuid NOT NULL,
    session_id uuid NOT NULL,
    turn_id uuid NOT NULL,
    request_id uuid NOT NULL,
    generation bigint NOT NULL,
    action_name text NOT NULL,
    tier integer NOT NULL,
    actor jsonb NOT NULL,
    target jsonb,
    parameters jsonb NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    state text DEFAULT 'emitted'::text NOT NULL,
    emitted_at timestamp with time zone NOT NULL,
    policy_configuration_id uuid,
    policy_revision integer,
    display_name text,
    confirmation_required boolean DEFAULT false NOT NULL,
    followup_enabled boolean DEFAULT false NOT NULL,
    followup_actions_allowed boolean DEFAULT false NOT NULL,
    followup_depth smallint DEFAULT 0 NOT NULL,
    cooldown_seconds integer DEFAULT 0 NOT NULL,
    followup_prompt text DEFAULT ''::text NOT NULL,
    CONSTRAINT action_intents_cooldown_seconds_check CHECK (((cooldown_seconds >= 0) AND (cooldown_seconds <= 86400))),
    CONSTRAINT action_intents_display_name_check CHECK (((display_name IS NULL) OR ((length(display_name) >= 1) AND (length(display_name) <= 128)))),
    CONSTRAINT action_intents_followup_depth_check CHECK (((followup_depth >= 0) AND (followup_depth <= 1))),
    CONSTRAINT action_intents_followup_prompt_check CHECK ((char_length(followup_prompt) <= 2048)),
    CONSTRAINT action_intents_generation_check CHECK ((generation >= 0)),
    CONSTRAINT action_intents_policy_revision_check CHECK (((policy_revision IS NULL) OR (policy_revision > 0))),
    CONSTRAINT action_intents_policy_revision_pair CHECK ((((policy_configuration_id IS NULL) AND (policy_revision IS NULL)) OR ((policy_configuration_id IS NOT NULL) AND (policy_revision IS NOT NULL)))),
    CONSTRAINT action_intents_state_check CHECK ((state = ANY (ARRAY['created'::text, 'emitted'::text, 'delivered'::text, 'terminal'::text]))),
    CONSTRAINT action_intents_tier_check CHECK (((tier >= 0) AND (tier <= 3)))
);


--
-- Name: TABLE action_intents; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.action_intents IS 'Playthrough Manager Backed Up';


--
-- Name: action_issued_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.action_issued_metadata (
    rowid integer NOT NULL,
    action_id uuid NOT NULL,
    session_id uuid,
    turn_id uuid,
    request_id uuid,
    state text NOT NULL
);


--
-- Name: TABLE action_issued_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.action_issued_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: action_results; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.action_results (
    action_id uuid NOT NULL,
    source_event_id uuid NOT NULL,
    message_id uuid NOT NULL,
    request_id uuid NOT NULL,
    status text NOT NULL,
    reason_code text NOT NULL,
    observed jsonb NOT NULL,
    completed_at timestamp with time zone NOT NULL,
    received_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT action_results_status_check CHECK ((status = ANY (ARRAY['succeeded'::text, 'failed'::text, 'rejected'::text, 'timed_out'::text, 'cancelled'::text])))
);


--
-- Name: TABLE action_results; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.action_results IS 'Playthrough Manager Backed Up';


--
-- Name: timeline_invalidated_turns; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.timeline_invalidated_turns (
    turn_id uuid NOT NULL,
    loaded_save_id uuid NOT NULL,
    cutoff_minute bigint NOT NULL,
    invalidated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);


--
-- Name: TABLE timeline_invalidated_turns; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.timeline_invalidated_turns IS 'Playthrough Manager Backed Up';


--
-- Name: turns; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.turns (
    turn_id uuid NOT NULL,
    request_id uuid NOT NULL,
    message_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    input_kind text NOT NULL,
    input_language text NOT NULL,
    input_text text NOT NULL,
    speaker jsonb NOT NULL,
    target jsonb NOT NULL,
    audience jsonb NOT NULL,
    context jsonb NOT NULL,
    state text NOT NULL,
    accepted_at timestamp with time zone NOT NULL,
    completed_at timestamp with time zone,
    processing_started_at timestamp with time zone,
    processing_attempts integer DEFAULT 0 NOT NULL,
    processing_job_id uuid,
    processing_lease_token uuid,
    processing_job_attempt integer,
    runtime_generation bigint DEFAULT 1 NOT NULL,
    response_id uuid,
    response_payload jsonb,
    response_created_at timestamp with time zone,
    CONSTRAINT turns_generation_check CHECK ((generation >= 0)),
    CONSTRAINT turns_input_kind_check CHECK ((input_kind = ANY (ARRAY['text'::text, 'stt'::text]))),
    CONSTRAINT turns_input_text_check CHECK ((octet_length(input_text) <= 16384)),
    CONSTRAINT turns_processing_attempts_check CHECK ((processing_attempts >= 0)),
    CONSTRAINT turns_processing_job_attempt_check CHECK (((processing_job_attempt IS NULL) OR (processing_job_attempt > 0))),
    CONSTRAINT turns_response_projection_shape CHECK ((((response_id IS NULL) AND (response_payload IS NULL) AND (response_created_at IS NULL)) OR ((response_id IS NOT NULL) AND (jsonb_typeof(response_payload) = 'object'::text) AND (response_created_at IS NOT NULL)))),
    CONSTRAINT turns_runtime_generation_check CHECK ((runtime_generation > 0)),
    CONSTRAINT turns_state_check CHECK ((state = ANY (ARRAY['accepted'::text, 'processing'::text, 'complete'::text, 'failed'::text, 'cancelled'::text])))
);


--
-- Name: TABLE turns; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.turns IS 'Playthrough Manager Backed Up';


--
-- Name: active_turns; Type: VIEW; Schema: lorkhan_internal; Owner: -
--

CREATE VIEW lorkhan_internal.active_turns AS
 SELECT t.turn_id,
    t.request_id,
    t.message_id,
    t.session_id,
    t.generation,
    t.input_kind,
    t.input_language,
    t.input_text,
    t.speaker,
    t.target,
    t.audience,
    t.context,
    t.state,
    t.accepted_at,
    t.completed_at,
    t.processing_started_at,
    t.processing_attempts,
    t.processing_job_id,
    t.processing_lease_token,
    t.processing_job_attempt,
    t.runtime_generation,
    t.response_id,
    t.response_payload,
    t.response_created_at
   FROM lorkhan_internal.turns t
  WHERE (NOT (EXISTS ( SELECT 1
           FROM lorkhan_internal.timeline_invalidated_turns i
          WHERE (i.turn_id = t.turn_id))));


--
-- Name: actor_profile_bindings; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.actor_profile_bindings (
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    actor_key character(64) NOT NULL,
    actor_identity jsonb NOT NULL,
    profile_id text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT actor_profile_bindings_actor_identity_check CHECK ((jsonb_typeof(actor_identity) = 'object'::text)),
    CONSTRAINT actor_profile_bindings_actor_key_check CHECK ((actor_key ~ '^[0-9a-f]{64}$'::text))
);


--
-- Name: TABLE actor_profile_bindings; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.actor_profile_bindings IS 'Playthrough Manager Backed Up';


--
-- Name: audit_request_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.audit_request_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    profile_id text,
    session_id uuid,
    turn_id uuid,
    request_id uuid,
    prompt_trace_id uuid,
    provider_attempt_id uuid
);


--
-- Name: TABLE audit_request_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.audit_request_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: backup_records; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.backup_records (
    backup_id uuid NOT NULL,
    format_version integer DEFAULT 1 NOT NULL,
    content_sha256 character(64) NOT NULL,
    byte_count integer NOT NULL,
    scope jsonb NOT NULL,
    state text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    restored_at timestamp with time zone,
    CONSTRAINT backup_records_byte_count_check CHECK ((byte_count > 0)),
    CONSTRAINT backup_records_content_sha256_check CHECK ((content_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT backup_records_scope_check CHECK ((jsonb_typeof(scope) = 'object'::text)),
    CONSTRAINT backup_records_state_check CHECK ((state = ANY (ARRAY['created'::text, 'restored'::text, 'failed'::text])))
);


--
-- Name: biography_catalog_entries; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.biography_catalog_entries (
    catalog_id uuid NOT NULL,
    content_file character varying(256),
    record_id character varying(256) NOT NULL,
    display_name character varying(256) NOT NULL,
    npc_name character varying(128) NOT NULL,
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
    CONSTRAINT biography_catalog_entries_content_file_check CHECK (((content_file IS NULL) OR ((length((content_file)::text) >= 1) AND (length((content_file)::text) <= 256)))),
    CONSTRAINT biography_catalog_entries_display_name_check CHECK (((length((display_name)::text) >= 1) AND (length((display_name)::text) <= 256))),
    CONSTRAINT biography_catalog_entries_record_id_check CHECK (((length((record_id)::text) >= 1) AND (length((record_id)::text) <= 256))),
    CONSTRAINT biography_catalog_entries_relationships_check CHECK (((relationships IS NULL) OR (jsonb_typeof((relationships)::jsonb) = 'object'::text)))
);


--
-- Name: biography_catalogs; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.biography_catalogs (
    catalog_id uuid NOT NULL,
    catalog_version character varying(128) NOT NULL,
    source_kind text NOT NULL,
    format_version text,
    model text,
    biographies_sha256 character(64) NOT NULL,
    manifest_sha256 character(64),
    generator_sha256 character(64),
    official_content_sha256 jsonb DEFAULT '{}'::jsonb NOT NULL,
    row_count integer NOT NULL,
    state text NOT NULL,
    previous_catalog_id uuid,
    imported_at timestamp with time zone NOT NULL,
    activated_at timestamp with time zone NOT NULL,
    superseded_at timestamp with time zone,
    CONSTRAINT biography_catalogs_catalog_version_check CHECK ((((catalog_version)::text = btrim((catalog_version)::text)) AND ((length((catalog_version)::text) >= 1) AND (length((catalog_version)::text) <= 128)))),
    CONSTRAINT biography_catalogs_check CHECK (((source_kind = 'legacy_snapshot'::text) OR ((format_version IS NOT NULL) AND (model IS NOT NULL) AND (manifest_sha256 IS NOT NULL)))),
    CONSTRAINT biography_catalogs_official_content_sha256_check CHECK ((jsonb_typeof(official_content_sha256) = 'object'::text)),
    CONSTRAINT biography_catalogs_row_count_check CHECK (((row_count >= 0) AND (row_count <= 20000))),
    CONSTRAINT biography_catalogs_source_kind_check CHECK ((source_kind = ANY (ARRAY['imported'::text, 'legacy_snapshot'::text]))),
    CONSTRAINT biography_catalogs_state_check CHECK ((state = ANY (ARRAY['active'::text, 'superseded'::text])))
);


--
-- Name: book_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.book_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    session_id uuid,
    record_id text,
    content_file text,
    source_turn_id uuid
);


--
-- Name: TABLE book_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.book_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: browser_sessions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.browser_sessions (
    session_hash character(64) NOT NULL,
    csrf_hash character(64) NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    last_seen_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    revoked_at timestamp with time zone,
    tts_preview_window_started_at timestamp with time zone,
    tts_preview_count integer DEFAULT 0 NOT NULL,
    CONSTRAINT browser_sessions_csrf_hash_check CHECK ((csrf_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT browser_sessions_session_hash_check CHECK ((session_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT browser_sessions_tts_preview_count_check CHECK ((tts_preview_count >= 0))
);


--
-- Name: character_playthrough_bindings; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.character_playthrough_bindings (
    installation_id uuid NOT NULL,
    character_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    binding_mode text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT character_playthrough_bindings_binding_mode_check CHECK ((binding_mode = ANY (ARRAY['new'::text, 'existing'::text])))
);


--
-- Name: configuration_revisions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.configuration_revisions (
    configuration_id uuid NOT NULL,
    revision integer NOT NULL,
    content jsonb NOT NULL,
    change_reason text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT configuration_revisions_content_check CHECK ((jsonb_typeof(content) = 'object'::text)),
    CONSTRAINT configuration_revisions_revision_check CHECK ((revision > 0))
);


--
-- Name: configuration_sets; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.configuration_sets (
    configuration_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text,
    kind text NOT NULL,
    name text NOT NULL,
    current_revision integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT configuration_sets_current_revision_check CHECK ((current_revision > 0)),
    CONSTRAINT configuration_sets_kind_check CHECK ((kind = ANY (ARRAY['prompt'::text, 'provider'::text, 'tts_provider'::text, 'stt_provider'::text, 'action_policy'::text, 'global_settings'::text, 'memory_policy'::text, 'memory_embedding_policy'::text, 'translation_policy'::text]))),
    CONSTRAINT configuration_sets_name_check CHECK (((octet_length(name) >= 1) AND (octet_length(name) <= 128)))
);


--
-- Name: content_manifest_files; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.content_manifest_files (
    installation_id uuid NOT NULL,
    content_file character varying(256) NOT NULL,
    load_order integer NOT NULL,
    active boolean DEFAULT true NOT NULL,
    first_seen_at timestamp with time zone NOT NULL,
    last_seen_at timestamp with time zone NOT NULL,
    source_session_id uuid,
    source_turn_id uuid,
    CONSTRAINT content_manifest_files_content_file_check CHECK ((((content_file)::text = lower(btrim((content_file)::text))) AND ((length((content_file)::text) >= 1) AND (length((content_file)::text) <= 256)))),
    CONSTRAINT content_manifest_files_load_order_check CHECK (((load_order >= 0) AND (load_order <= 255)))
);


--
-- Name: content_manifests; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.content_manifests (
    installation_id uuid NOT NULL,
    content_fingerprint text NOT NULL,
    source_session_id uuid,
    source_turn_id uuid,
    file_count integer NOT NULL,
    observed_at timestamp with time zone NOT NULL,
    CONSTRAINT content_manifests_file_count_check CHECK (((file_count >= 0) AND (file_count <= 256)))
);


--
-- Name: core_profile_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.core_profile_metadata (
    core_profile_id integer NOT NULL,
    installation_id uuid NOT NULL,
    source_core_profile_id uuid NOT NULL,
    source_revision integer NOT NULL,
    source_slot smallint
);


--
-- Name: core_profile_presets; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.core_profile_presets (
    preset_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    name text NOT NULL,
    revision integer DEFAULT 1 NOT NULL,
    payload jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT core_profile_presets_name_check CHECK (((octet_length(name) >= 1) AND (octet_length(name) <= 128))),
    CONSTRAINT core_profile_presets_payload_check CHECK (((jsonb_typeof(payload) = 'object'::text) AND (octet_length((payload)::text) <= 262144))),
    CONSTRAINT core_profile_presets_revision_check CHECK ((revision > 0))
);


--
-- Name: core_profile_revisions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.core_profile_revisions (
    core_profile_id uuid NOT NULL,
    revision integer NOT NULL,
    content jsonb NOT NULL,
    change_reason text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT core_profile_revisions_change_reason_check CHECK (((octet_length(change_reason) >= 1) AND (octet_length(change_reason) <= 512))),
    CONSTRAINT core_profile_revisions_content_check CHECK ((jsonb_typeof(content) = 'object'::text)),
    CONSTRAINT core_profile_revisions_revision_check CHECK ((revision > 0))
);


--
-- Name: core_profiles; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.core_profiles (
    core_profile_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    label text NOT NULL,
    default_npc boolean DEFAULT false NOT NULL,
    slot smallint,
    current_revision integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT core_profiles_current_revision_check CHECK ((current_revision > 0)),
    CONSTRAINT core_profiles_label_check CHECK (((octet_length(label) >= 1) AND (octet_length(label) <= 256))),
    CONSTRAINT core_profiles_slot_check CHECK (((slot IS NULL) OR ((slot >= 1) AND (slot <= 4))))
);


--
-- Name: core_tts_pronunciation; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.core_tts_pronunciation (
    id integer NOT NULL,
    source_text character varying(120) NOT NULL,
    spoken_text character varying(240) NOT NULL,
    npc_names character varying(512) DEFAULT ''::character varying NOT NULL,
    races character varying(512) DEFAULT ''::character varying NOT NULL,
    oghma_tags character varying(512) DEFAULT ''::character varying NOT NULL,
    is_builtin boolean DEFAULT false NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    updated_at timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT core_tts_pronunciation_source_not_blank CHECK ((btrim((source_text)::text) <> ''::text)),
    CONSTRAINT core_tts_pronunciation_spoken_not_blank CHECK ((btrim((spoken_text)::text) <> ''::text))
);


--
-- Name: core_tts_pronunciation_id_seq; Type: SEQUENCE; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE lorkhan_internal.core_tts_pronunciation ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME lorkhan_internal.core_tts_pronunciation_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: currentmission_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.currentmission_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    journal_id text NOT NULL,
    source_turn_id uuid
);


--
-- Name: TABLE currentmission_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.currentmission_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: database_backup_settings; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.database_backup_settings (
    singleton boolean DEFAULT true NOT NULL,
    enabled boolean DEFAULT false NOT NULL,
    max_count integer DEFAULT 5 NOT NULL,
    last_queued_at timestamp with time zone,
    CONSTRAINT database_backup_settings_max_count_check CHECK (((max_count >= 1) AND (max_count <= 10))),
    CONSTRAINT database_backup_settings_singleton_check CHECK (singleton)
);


--
-- Name: database_snapshot_source; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.database_snapshot_source (
    singleton boolean DEFAULT true NOT NULL,
    backup_id uuid,
    name text,
    copied_at timestamp with time zone,
    CONSTRAINT database_snapshot_source_singleton_check CHECK (singleton)
);


--
-- Name: debug_commands; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.debug_commands (
    command_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    command_name text NOT NULL,
    parameters jsonb NOT NULL,
    state text DEFAULT 'queued'::text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    delivered_at timestamp with time zone,
    completed_at timestamp with time zone,
    result_message_id uuid,
    reason_code text,
    observed jsonb,
    CONSTRAINT debug_commands_check CHECK ((expires_at > created_at)),
    CONSTRAINT debug_commands_command_name_check CHECK ((command_name = ANY (ARRAY['status.snapshot'::text, 'god_mode.set'::text, 'collision.set'::text, 'ai.set'::text, 'mwscript.set'::text, 'render_mode.toggle'::text, 'shaders.reload'::text, 'shader_hot_reload.set'::text, 'player.inventory.add'::text, 'player.inventory.remove'::text, 'player.spell.add'::text, 'player.spell.remove'::text, 'player.vitals.restore'::text, 'player.stat.set'::text, 'player.attribute.set'::text, 'player.skill.set'::text, 'player.level.set'::text, 'player.bounty.set'::text, 'player.teleport'::text, 'player.scale.set'::text, 'world.time.advance'::text, 'world.timescale.set'::text, 'world.weather.set'::text, 'target.actor.kill'::text, 'target.actor.restore'::text, 'target.teleport.to_player'::text, 'target.scale.set'::text, 'player.dialogue.submit'::text, 'npc.status'::text, 'npc.visit'::text, 'npc.teleport'::text, 'npc.return'::text]))),
    CONSTRAINT debug_commands_generation_check CHECK ((generation > 0)),
    CONSTRAINT debug_commands_observed_check CHECK (((observed IS NULL) OR (jsonb_typeof(observed) = 'object'::text))),
    CONSTRAINT debug_commands_parameters_check CHECK ((jsonb_typeof(parameters) = 'object'::text)),
    CONSTRAINT debug_commands_state_check CHECK ((state = ANY (ARRAY['queued'::text, 'delivered'::text, 'succeeded'::text, 'failed'::text, 'rejected'::text, 'expired'::text])))
);


--
-- Name: description_catalog_entries; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.description_catalog_entries (
    catalog_id uuid NOT NULL,
    plugin text NOT NULL,
    baseid character varying(128) NOT NULL,
    name text,
    description text,
    CONSTRAINT description_catalog_entries_baseid_check CHECK (((length((baseid)::text) >= 1) AND (length((baseid)::text) <= 128))),
    CONSTRAINT description_catalog_entries_plugin_check CHECK ((plugin = ANY (ARRAY['Morrowind.esm'::text, 'Tribunal.esm'::text, 'Bloodmoon.esm'::text])))
);


--
-- Name: description_catalogs; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.description_catalogs (
    catalog_id uuid NOT NULL,
    catalog_version character varying(128) NOT NULL,
    source_kind text NOT NULL,
    format_version text,
    prompt_sha256 character(64),
    model text,
    csv_sha256 character(64) NOT NULL,
    manifest_sha256 character(64),
    official_content_sha256 jsonb DEFAULT '{}'::jsonb NOT NULL,
    row_count integer NOT NULL,
    state text NOT NULL,
    previous_catalog_id uuid,
    imported_at timestamp with time zone NOT NULL,
    activated_at timestamp with time zone NOT NULL,
    superseded_at timestamp with time zone,
    CONSTRAINT description_catalogs_catalog_version_check CHECK ((((catalog_version)::text = btrim((catalog_version)::text)) AND ((length((catalog_version)::text) >= 1) AND (length((catalog_version)::text) <= 128)))),
    CONSTRAINT description_catalogs_check CHECK (((source_kind = 'legacy_snapshot'::text) OR ((format_version IS NOT NULL) AND (prompt_sha256 IS NOT NULL) AND (model IS NOT NULL) AND (manifest_sha256 IS NOT NULL)))),
    CONSTRAINT description_catalogs_official_content_sha256_check CHECK ((jsonb_typeof(official_content_sha256) = 'object'::text)),
    CONSTRAINT description_catalogs_row_count_check CHECK (((row_count >= 0) AND (row_count <= 5000))),
    CONSTRAINT description_catalogs_source_kind_check CHECK ((source_kind = ANY (ARRAY['imported'::text, 'legacy_snapshot'::text]))),
    CONSTRAINT description_catalogs_state_check CHECK ((state = ANY (ARRAY['active'::text, 'superseded'::text])))
);


--
-- Name: description_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.description_metadata (
    plugin text NOT NULL,
    baseid character varying(128) NOT NULL,
    description_id uuid NOT NULL,
    installation_id uuid NOT NULL
);


--
-- Name: dialogue_delivery_results; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.dialogue_delivery_results (
    dialogue_message_id uuid NOT NULL,
    source_event_id uuid NOT NULL,
    message_id uuid NOT NULL,
    request_id uuid NOT NULL,
    turn_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    speaker jsonb NOT NULL,
    status text NOT NULL,
    reason_code text NOT NULL,
    completed_at timestamp with time zone NOT NULL,
    received_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT dialogue_delivery_results_generation_check CHECK ((generation >= 0)),
    CONSTRAINT dialogue_delivery_results_reason_code_check CHECK (((octet_length(reason_code) >= 1) AND (octet_length(reason_code) <= 128))),
    CONSTRAINT dialogue_delivery_results_speaker_check CHECK ((jsonb_typeof(speaker) = 'object'::text)),
    CONSTRAINT dialogue_delivery_results_status_check CHECK ((status = ANY (ARRAY['expired'::text, 'failed'::text, 'interrupted'::text, 'played'::text])))
);


--
-- Name: TABLE dialogue_delivery_results; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.dialogue_delivery_results IS 'Playthrough Manager Backed Up';


--
-- Name: dialogue_utterances; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.dialogue_utterances (
    dialogue_message_id uuid NOT NULL,
    session_id uuid NOT NULL,
    turn_id uuid NOT NULL,
    request_id uuid NOT NULL,
    generation bigint NOT NULL,
    utterance_index smallint NOT NULL,
    utterance_count smallint NOT NULL,
    speaker jsonb NOT NULL,
    addressee jsonb NOT NULL,
    audience jsonb NOT NULL,
    text text NOT NULL,
    emitted_at timestamp with time zone NOT NULL,
    delivery_deadline_at timestamp with time zone NOT NULL,
    delivery_state text DEFAULT 'pending'::text NOT NULL,
    delivered_at timestamp with time zone,
    expiry_job_id uuid,
    response_line_id uuid NOT NULL,
    utterance_id uuid NOT NULL,
    runtime_generation bigint DEFAULT 1 NOT NULL,
    CONSTRAINT dialogue_utterances_addressee_check CHECK ((jsonb_typeof(addressee) = 'object'::text)),
    CONSTRAINT dialogue_utterances_audience_check CHECK ((jsonb_typeof(audience) = 'array'::text)),
    CONSTRAINT dialogue_utterances_delivery_state_check CHECK ((delivery_state = ANY (ARRAY['pending'::text, 'played'::text, 'failed'::text, 'expired'::text, 'interrupted'::text]))),
    CONSTRAINT dialogue_utterances_generation_check CHECK ((generation >= 0)),
    CONSTRAINT dialogue_utterances_runtime_generation_check CHECK ((runtime_generation > 0)),
    CONSTRAINT dialogue_utterances_speaker_check CHECK ((jsonb_typeof(speaker) = 'object'::text)),
    CONSTRAINT dialogue_utterances_text_check CHECK (((octet_length(text) >= 1) AND (octet_length(text) <= 16384))),
    CONSTRAINT dialogue_utterances_utterance_count_check CHECK (((utterance_count >= 1) AND (utterance_count <= 32))),
    CONSTRAINT dialogue_utterances_utterance_index_check CHECK (((utterance_index >= 1) AND (utterance_index <= 32)))
);


--
-- Name: TABLE dialogue_utterances; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.dialogue_utterances IS 'Playthrough Manager Backed Up';


--
-- Name: diarylog_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.diarylog_metadata (
    rowid bigint NOT NULL,
    narrative_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text,
    playthrough_id uuid
);


--
-- Name: TABLE diarylog_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.diarylog_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: director_instructions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.director_instructions (
    instruction_id uuid NOT NULL,
    plan_id uuid NOT NULL,
    ordinal smallint NOT NULL,
    actor jsonb NOT NULL,
    recipient jsonb NOT NULL,
    instruction text NOT NULL,
    scene_note text NOT NULL,
    child_turn_id uuid,
    authored_response jsonb,
    CONSTRAINT director_instructions_instruction_check CHECK (((octet_length(instruction) >= 1) AND (octet_length(instruction) <= 2000))),
    CONSTRAINT director_instructions_ordinal_check CHECK (((ordinal >= 1) AND (ordinal <= 12))),
    CONSTRAINT director_instructions_scene_note_check CHECK ((octet_length(scene_note) <= 1000))
);


--
-- Name: director_plans; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.director_plans (
    plan_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    session_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    generation bigint NOT NULL,
    origin_turn_id uuid NOT NULL,
    request_id uuid NOT NULL,
    input jsonb NOT NULL,
    state text DEFAULT 'queued'::text NOT NULL,
    expires_at timestamp with time zone DEFAULT (clock_timestamp() + '00:05:00'::interval) NOT NULL,
    CONSTRAINT director_plans_state_check CHECK ((state = ANY (ARRAY['queued'::text, 'delivered'::text, 'cancelled'::text])))
);


--
-- Name: discovered_items; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.discovered_items (
    installation_id uuid NOT NULL,
    content_file character varying(256) NOT NULL,
    record_id character varying(256) NOT NULL,
    record_kind character varying(32) DEFAULT 'item'::character varying NOT NULL,
    display_name character varying(256),
    reference_content_file character varying(256),
    observed_sources jsonb DEFAULT '[]'::jsonb NOT NULL,
    first_seen_at timestamp with time zone NOT NULL,
    last_seen_at timestamp with time zone NOT NULL,
    source_session_id uuid,
    source_turn_id uuid,
    observation_count bigint DEFAULT 1 NOT NULL,
    CONSTRAINT discovered_items_content_file_check CHECK ((((content_file)::text = lower(btrim((content_file)::text))) AND ((length((content_file)::text) >= 1) AND (length((content_file)::text) <= 256)))),
    CONSTRAINT discovered_items_display_name_check CHECK (((display_name IS NULL) OR ((length((display_name)::text) >= 1) AND (length((display_name)::text) <= 256)))),
    CONSTRAINT discovered_items_observation_count_check CHECK ((observation_count > 0)),
    CONSTRAINT discovered_items_observed_sources_check CHECK ((jsonb_typeof(observed_sources) = 'array'::text)),
    CONSTRAINT discovered_items_record_id_check CHECK ((((record_id)::text = lower(btrim((record_id)::text))) AND ((length((record_id)::text) >= 1) AND (length((record_id)::text) <= 256))))
);


--
-- Name: disposition_adjustments; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.disposition_adjustments (
    adjustment_id uuid NOT NULL,
    job_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    turn_id uuid NOT NULL,
    actor_key text NOT NULL,
    player_key text NOT NULL,
    actor jsonb NOT NULL,
    player jsonb NOT NULL,
    delta integer NOT NULL,
    status text DEFAULT 'pending'::text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    confirmed_source_id uuid,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT disposition_adjustments_delta_check CHECK ((((delta >= '-3'::integer) AND (delta <= 3)) AND (delta <> 0))),
    CONSTRAINT disposition_adjustments_status_check CHECK ((status = ANY (ARRAY['pending'::text, 'applied'::text, 'rejected'::text])))
);


--
-- Name: durable_job_attempts; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.durable_job_attempts (
    attempt_id bigint NOT NULL,
    job_id uuid NOT NULL,
    attempt_number integer NOT NULL,
    lease_token uuid NOT NULL,
    worker_id text NOT NULL,
    started_at timestamp with time zone NOT NULL,
    heartbeat_at timestamp with time zone NOT NULL,
    finished_at timestamp with time zone,
    outcome text,
    error_code text,
    error_detail text,
    CONSTRAINT durable_job_attempts_attempt_number_check CHECK ((attempt_number > 0)),
    CONSTRAINT durable_job_attempts_check CHECK ((((finished_at IS NULL) AND (outcome IS NULL)) OR ((finished_at IS NOT NULL) AND (outcome IS NOT NULL)))),
    CONSTRAINT durable_job_attempts_outcome_check CHECK ((outcome = ANY (ARRAY['succeeded'::text, 'retry'::text, 'dead'::text, 'lease_expired'::text])))
);


--
-- Name: durable_job_attempts_attempt_id_seq; Type: SEQUENCE; Schema: lorkhan_internal; Owner: -
--

CREATE SEQUENCE lorkhan_internal.durable_job_attempts_attempt_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: durable_job_attempts_attempt_id_seq; Type: SEQUENCE OWNED BY; Schema: lorkhan_internal; Owner: -
--

ALTER SEQUENCE lorkhan_internal.durable_job_attempts_attempt_id_seq OWNED BY lorkhan_internal.durable_job_attempts.attempt_id;


--
-- Name: durable_job_dead_letters; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.durable_job_dead_letters (
    dead_letter_id bigint NOT NULL,
    job_id uuid NOT NULL,
    failed_attempt_number integer NOT NULL,
    error_code text NOT NULL,
    error_detail text,
    dead_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    replayed_at timestamp with time zone,
    replay_job_id uuid,
    CONSTRAINT durable_job_dead_letters_check CHECK ((((replayed_at IS NULL) AND (replay_job_id IS NULL)) OR ((replayed_at IS NOT NULL) AND (replay_job_id IS NOT NULL)))),
    CONSTRAINT durable_job_dead_letters_failed_attempt_number_check CHECK ((failed_attempt_number > 0))
);


--
-- Name: durable_job_dead_letters_dead_letter_id_seq; Type: SEQUENCE; Schema: lorkhan_internal; Owner: -
--

CREATE SEQUENCE lorkhan_internal.durable_job_dead_letters_dead_letter_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: durable_job_dead_letters_dead_letter_id_seq; Type: SEQUENCE OWNED BY; Schema: lorkhan_internal; Owner: -
--

ALTER SEQUENCE lorkhan_internal.durable_job_dead_letters_dead_letter_id_seq OWNED BY lorkhan_internal.durable_job_dead_letters.dead_letter_id;


--
-- Name: durable_jobs; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.durable_jobs (
    job_id uuid NOT NULL,
    job_type text NOT NULL,
    schema_version integer NOT NULL,
    idempotency_key text NOT NULL,
    payload jsonb NOT NULL,
    state text DEFAULT 'queued'::text NOT NULL,
    priority smallint DEFAULT 0 NOT NULL,
    attempt_count integer DEFAULT 0 NOT NULL,
    max_attempts integer DEFAULT 5 NOT NULL,
    next_run_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    lease_owner text,
    lease_token uuid,
    leased_at timestamp with time zone,
    lease_expires_at timestamp with time zone,
    heartbeat_at timestamp with time zone,
    last_error_code text,
    last_error_detail text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    completed_at timestamp with time zone,
    CONSTRAINT durable_jobs_attempt_count_check CHECK ((attempt_count >= 0)),
    CONSTRAINT durable_jobs_check CHECK ((((state = 'leased'::text) AND (lease_owner IS NOT NULL) AND (lease_token IS NOT NULL) AND (leased_at IS NOT NULL) AND (lease_expires_at IS NOT NULL) AND (heartbeat_at IS NOT NULL)) OR ((state <> 'leased'::text) AND (lease_owner IS NULL) AND (lease_token IS NULL) AND (leased_at IS NULL) AND (lease_expires_at IS NULL) AND (heartbeat_at IS NULL)))),
    CONSTRAINT durable_jobs_idempotency_key_check CHECK (((octet_length(idempotency_key) >= 1) AND (octet_length(idempotency_key) <= 512))),
    CONSTRAINT durable_jobs_job_type_check CHECK ((job_type ~ '^[a-z][a-z0-9_.-]{0,127}$'::text)),
    CONSTRAINT durable_jobs_max_attempts_check CHECK (((max_attempts >= 1) AND (max_attempts <= 100))),
    CONSTRAINT durable_jobs_payload_check CHECK ((jsonb_typeof(payload) = 'object'::text)),
    CONSTRAINT durable_jobs_schema_version_check CHECK ((schema_version > 0)),
    CONSTRAINT durable_jobs_state_check CHECK ((state = ANY (ARRAY['queued'::text, 'leased'::text, 'succeeded'::text, 'dead'::text])))
);


--
-- Name: eventlog_hidden_types; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.eventlog_hidden_types (
    installation_id uuid NOT NULL,
    event_type character varying(128) NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);


--
-- Name: eventlog_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.eventlog_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    profile_id text,
    session_id uuid,
    source_event_id uuid,
    dialogue_message_id uuid,
    request_id uuid,
    turn_id uuid,
    projection_kind character varying(32) NOT NULL,
    projection_key text NOT NULL,
    speaker jsonb DEFAULT '{}'::jsonb NOT NULL,
    target jsonb DEFAULT '{}'::jsonb NOT NULL,
    audience jsonb DEFAULT '[]'::jsonb NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    suppressed_at timestamp with time zone,
    suppression_reason text,
    CONSTRAINT eventlog_metadata_audience_check CHECK ((jsonb_typeof(audience) = 'array'::text)),
    CONSTRAINT eventlog_metadata_payload_check CHECK ((jsonb_typeof(payload) = 'object'::text)),
    CONSTRAINT eventlog_metadata_speaker_check CHECK ((jsonb_typeof(speaker) = 'object'::text)),
    CONSTRAINT eventlog_metadata_target_check CHECK ((jsonb_typeof(target) = 'object'::text))
);


--
-- Name: TABLE eventlog_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.eventlog_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: faction_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.faction_metadata (
    formid text NOT NULL,
    installation_id uuid NOT NULL,
    source_turn_id uuid,
    source_actor jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT faction_metadata_source_actor_check CHECK ((jsonb_typeof(source_actor) = 'object'::text))
);


--
-- Name: TABLE faction_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.faction_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: game_dispositions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.game_dispositions (
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    actor_key text NOT NULL,
    player_key text NOT NULL,
    actor jsonb NOT NULL,
    player jsonb NOT NULL,
    base_disposition integer NOT NULL,
    disposition integer NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    observed_at timestamp with time zone NOT NULL,
    source_event_id uuid NOT NULL,
    CONSTRAINT game_dispositions_disposition_check CHECK (((disposition >= 0) AND (disposition <= 100)))
);


--
-- Name: game_plugin_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.game_plugin_metadata (
    plugin_name text NOT NULL,
    installation_id uuid NOT NULL,
    content_fingerprint text,
    source_turn_id uuid
);


--
-- Name: TABLE game_plugin_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.game_plugin_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: general_setting_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.general_setting_metadata (
    id text NOT NULL,
    installation_id uuid NOT NULL,
    source_configuration_id uuid NOT NULL,
    source_revision integer NOT NULL,
    setting_key text NOT NULL
);


--
-- Name: global_settings_presets; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.global_settings_presets (
    preset_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    name text NOT NULL,
    revision integer DEFAULT 1 NOT NULL,
    payload jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT global_settings_presets_name_check CHECK (((octet_length(name) >= 1) AND (octet_length(name) <= 128))),
    CONSTRAINT global_settings_presets_payload_check CHECK (((jsonb_typeof(payload) = 'object'::text) AND (octet_length((payload)::text) <= 262144))),
    CONSTRAINT global_settings_presets_revision_check CHECK ((revision > 0))
);


--
-- Name: idempotency_requests; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.idempotency_requests (
    installation_id uuid NOT NULL,
    idempotency_key uuid NOT NULL,
    route text NOT NULL,
    semantic_hash character(64) NOT NULL,
    http_status smallint NOT NULL,
    response_body jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);


--
-- Name: installation_profile_preferences; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.installation_profile_preferences (
    installation_id uuid NOT NULL,
    auto_lock_on_edit boolean DEFAULT true NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    llm_model_slot text DEFAULT 'standard'::text NOT NULL,
    core_creation_preset jsonb,
    CONSTRAINT installation_profile_preferences_core_creation_preset_check CHECK (((core_creation_preset IS NULL) OR (jsonb_typeof(core_creation_preset) = 'object'::text))),
    CONSTRAINT installation_profile_preferences_llm_model_slot_check CHECK ((llm_model_slot = ANY (ARRAY['standard'::text, 'fast'::text, 'powerful'::text, 'experimental'::text])))
);


--
-- Name: installation_provider_selections; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.installation_provider_selections (
    installation_id uuid NOT NULL,
    provider_kind text NOT NULL,
    configuration_id uuid NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT installation_provider_selections_provider_kind_check CHECK ((provider_kind = ANY (ARRAY['tts_provider'::text, 'stt_provider'::text])))
);


--
-- Name: installations; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.installations (
    installation_id uuid NOT NULL,
    token_fingerprint character(64) NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    last_seen_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    display_name text DEFAULT 'Local installation'::text NOT NULL,
    revoked_at timestamp with time zone
);


--
-- Name: interruptions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.interruptions (
    message_id uuid NOT NULL,
    request_id uuid NOT NULL,
    session_id uuid NOT NULL,
    turn_id uuid NOT NULL,
    generation bigint NOT NULL,
    reason text NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT interruptions_generation_check CHECK ((generation >= 0))
);


--
-- Name: TABLE interruptions; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.interruptions IS 'Playthrough Manager Backed Up';


--
-- Name: item_descriptions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.item_descriptions (
    description_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    content_file character varying(256) NOT NULL,
    record_id character varying(256) NOT NULL,
    display_name character varying(256) NOT NULL,
    description text NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT item_descriptions_content_file_check CHECK (((length((content_file)::text) >= 1) AND (length((content_file)::text) <= 256))),
    CONSTRAINT item_descriptions_description_check CHECK (((length(description) >= 0) AND (length(description) <= 8192))),
    CONSTRAINT item_descriptions_display_name_check CHECK (((length((display_name)::text) >= 0) AND (length((display_name)::text) <= 256))),
    CONSTRAINT item_descriptions_record_id_check CHECK (((length((record_id)::text) >= 1) AND (length((record_id)::text) <= 256)))
);


--
-- Name: knowledge_documents; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.knowledge_documents (
    document_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text,
    playthrough_id uuid,
    title text NOT NULL,
    content text NOT NULL,
    content_sha256 character(64) NOT NULL,
    lexical_terms text[] NOT NULL,
    provenance jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    topic character varying(256) NOT NULL,
    aliases text DEFAULT ''::text NOT NULL,
    topic_desc_basic text NOT NULL,
    knowledge_class text DEFAULT ''::text NOT NULL,
    knowledge_class_basic text DEFAULT ''::text NOT NULL,
    tags text DEFAULT ''::text NOT NULL,
    category text DEFAULT ''::text NOT NULL,
    CONSTRAINT knowledge_basic_bytes CHECK (((octet_length(topic_desc_basic) >= 0) AND (octet_length(topic_desc_basic) <= 131072))),
    CONSTRAINT knowledge_category_bytes CHECK (((octet_length(category) >= 0) AND (octet_length(category) <= 128))),
    CONSTRAINT knowledge_documents_content_check CHECK (((octet_length(content) >= 0) AND (octet_length(content) <= 131072))),
    CONSTRAINT knowledge_documents_content_sha256_check CHECK ((content_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT knowledge_documents_provenance_check CHECK ((jsonb_typeof(provenance) = 'object'::text)),
    CONSTRAINT knowledge_documents_title_check CHECK (((octet_length(title) >= 1) AND (octet_length(title) <= 256))),
    CONSTRAINT knowledge_topic_bytes CHECK (((octet_length((topic)::text) >= 1) AND (octet_length((topic)::text) <= 256)))
);


--
-- Name: TABLE knowledge_documents; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.knowledge_documents IS 'Playthrough Manager Backed Up';


--
-- Name: llm_connector_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.llm_connector_metadata (
    connector_id integer NOT NULL,
    installation_id uuid NOT NULL,
    configuration_id uuid NOT NULL,
    configuration_revision integer NOT NULL
);


--
-- Name: location_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.location_metadata (
    formid bigint NOT NULL,
    cell_key text NOT NULL,
    installation_id uuid NOT NULL,
    source_turn_id uuid
);


--
-- Name: TABLE location_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.location_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: log_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.log_metadata (
    rowid bigint NOT NULL,
    turn_id uuid,
    request_id uuid,
    prompt_trace_id uuid
);


--
-- Name: TABLE log_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.log_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: lorkhan_core_profiles_source; Type: VIEW; Schema: lorkhan_internal; Owner: -
--

CREATE VIEW lorkhan_internal.lorkhan_core_profiles_source AS
 SELECT core_profiles.core_profile_id,
    core_profiles.installation_id,
    core_profiles.label,
    core_profiles.default_npc,
    core_profiles.slot,
    core_profiles.current_revision,
    core_profiles.created_at,
    core_profiles.deleted_at
   FROM lorkhan_internal.core_profiles;


--
-- Name: media_objects; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.media_objects (
    media_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    session_id uuid NOT NULL,
    turn_id uuid,
    generation bigint NOT NULL,
    sha256 character(64) NOT NULL,
    byte_count integer NOT NULL,
    codec text NOT NULL,
    mime_type text NOT NULL,
    duration_ms integer NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    dialogue_message_id uuid,
    menu_dialogue_message_id uuid,
    CONSTRAINT media_objects_byte_count_check CHECK (((byte_count >= 1) AND (byte_count <= 33554432))),
    CONSTRAINT media_objects_codec_check CHECK ((codec = ANY (ARRAY['wav'::text, 'ogg'::text, 'mp3'::text]))),
    CONSTRAINT media_objects_duration_ms_check CHECK ((duration_ms > 0)),
    CONSTRAINT media_objects_generation_check CHECK ((generation >= 0)),
    CONSTRAINT media_objects_mime_type_check CHECK ((mime_type = ANY (ARRAY['audio/wav'::text, 'audio/ogg'::text, 'audio/mpeg'::text]))),
    CONSTRAINT media_objects_sha256_check CHECK ((sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT media_objects_single_source_check CHECK ((((turn_id IS NOT NULL) AND (menu_dialogue_message_id IS NULL)) OR ((turn_id IS NULL) AND (menu_dialogue_message_id IS NOT NULL))))
);


--
-- Name: memory_embeddings; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.memory_embeddings (
    memory_id uuid NOT NULL,
    memory_revision integer NOT NULL,
    policy_configuration_id uuid NOT NULL,
    policy_revision integer NOT NULL,
    dimensions integer NOT NULL,
    embedding jsonb NOT NULL,
    input_sha256 text NOT NULL,
    model text NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT memory_embeddings_check CHECK (((jsonb_typeof(embedding) = 'array'::text) AND (jsonb_array_length(embedding) = dimensions))),
    CONSTRAINT memory_embeddings_dimensions_check CHECK (((dimensions >= 8) AND (dimensions <= 1536))),
    CONSTRAINT memory_embeddings_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT memory_embeddings_memory_revision_check CHECK ((memory_revision > 0)),
    CONSTRAINT memory_embeddings_model_check CHECK (((octet_length(model) >= 1) AND (octet_length(model) <= 256))),
    CONSTRAINT memory_embeddings_policy_revision_check CHECK ((policy_revision > 0))
);


--
-- Name: memory_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.memory_metadata (
    rowid bigint NOT NULL,
    memory_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text,
    playthrough_id uuid,
    tier text NOT NULL,
    source_event_id uuid
);


--
-- Name: memory_model_summaries; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.memory_model_summaries (
    memory_id uuid NOT NULL,
    memory_revision integer NOT NULL,
    policy_configuration_id uuid NOT NULL,
    policy_revision integer NOT NULL,
    provider_configuration_id uuid NOT NULL,
    provider_revision integer NOT NULL,
    content text NOT NULL,
    input_sha256 text NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT memory_model_summaries_content_check CHECK (((octet_length(content) >= 1) AND (octet_length(content) <= 4096))),
    CONSTRAINT memory_model_summaries_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT memory_model_summaries_memory_revision_check CHECK ((memory_revision > 0))
);


--
-- Name: TABLE memory_model_summaries; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.memory_model_summaries IS 'Playthrough Manager Backed Up';


--
-- Name: memory_record_revisions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.memory_record_revisions (
    memory_id uuid NOT NULL,
    revision integer NOT NULL,
    tier text NOT NULL,
    content text NOT NULL,
    source_event_id uuid,
    provenance jsonb NOT NULL,
    occurred_at timestamp with time zone NOT NULL,
    deleted_at timestamp with time zone,
    change_reason text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT memory_record_revisions_change_reason_check CHECK (((octet_length(change_reason) >= 1) AND (octet_length(change_reason) <= 255))),
    CONSTRAINT memory_record_revisions_content_check CHECK (((octet_length(content) >= 1) AND (octet_length(content) <= 16384))),
    CONSTRAINT memory_record_revisions_provenance_check CHECK ((jsonb_typeof(provenance) = 'object'::text)),
    CONSTRAINT memory_record_revisions_revision_check CHECK ((revision > 0)),
    CONSTRAINT memory_record_revisions_tier_check CHECK ((tier = ANY (ARRAY['recent'::text, 'mid'::text, 'long'::text])))
);


--
-- Name: TABLE memory_record_revisions; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.memory_record_revisions IS 'Playthrough Manager Backed Up';


--
-- Name: memory_records; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.memory_records (
    memory_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    tier text NOT NULL,
    content text NOT NULL,
    lexical_terms text[] NOT NULL,
    fake_vector jsonb NOT NULL,
    source_event_id uuid,
    provenance jsonb NOT NULL,
    occurred_at timestamp with time zone NOT NULL,
    expires_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    derivation_key text,
    current_revision integer DEFAULT 1 NOT NULL,
    CONSTRAINT memory_records_content_check CHECK (((octet_length(content) >= 1) AND (octet_length(content) <= 16384))),
    CONSTRAINT memory_records_current_revision_check CHECK ((current_revision > 0)),
    CONSTRAINT memory_records_fake_vector_check CHECK ((jsonb_typeof(fake_vector) = 'array'::text)),
    CONSTRAINT memory_records_provenance_check CHECK ((jsonb_typeof(provenance) = 'object'::text)),
    CONSTRAINT memory_records_tier_check CHECK ((tier = ANY (ARRAY['recent'::text, 'mid'::text, 'long'::text])))
);


--
-- Name: TABLE memory_records; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.memory_records IS 'Playthrough Manager Backed Up';


--
-- Name: memory_summary_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.memory_summary_metadata (
    rowid integer NOT NULL,
    memory_id uuid NOT NULL
);


--
-- Name: menu_dialogue_tts_requests; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.menu_dialogue_tts_requests (
    message_id uuid NOT NULL,
    request_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    actor jsonb NOT NULL,
    text_sha256 character(64) NOT NULL,
    created_at timestamp with time zone NOT NULL,
    completed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT menu_dialogue_tts_requests_actor_check CHECK ((jsonb_typeof(actor) = 'object'::text)),
    CONSTRAINT menu_dialogue_tts_requests_generation_check CHECK ((generation >= 0)),
    CONSTRAINT menu_dialogue_tts_requests_text_sha256_check CHECK ((text_sha256 ~ '^[0-9a-f]{64}$'::text))
);


--
-- Name: narrative_records; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.narrative_records (
    narrative_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    kind text NOT NULL,
    title text NOT NULL,
    content text NOT NULL,
    provenance jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    derivation_key text,
    CONSTRAINT narrative_records_content_check CHECK (((octet_length(content) >= 1) AND (octet_length(content) <= 65536))),
    CONSTRAINT narrative_records_kind_check CHECK ((kind = ANY (ARRAY['narrator'::text, 'diary'::text, 'summary'::text]))),
    CONSTRAINT narrative_records_provenance_check CHECK ((jsonb_typeof(provenance) = 'object'::text)),
    CONSTRAINT narrative_records_title_check CHECK (((octet_length(title) >= 1) AND (octet_length(title) <= 256)))
);


--
-- Name: TABLE narrative_records; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.narrative_records IS 'Playthrough Manager Backed Up';


--
-- Name: npc_evolution_reports; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.npc_evolution_reports (
    job_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    base_revision integer NOT NULL,
    npc_name text NOT NULL,
    history jsonb NOT NULL,
    report text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT npc_evolution_reports_base_revision_check CHECK ((base_revision > 0)),
    CONSTRAINT npc_evolution_reports_history_check CHECK (((jsonb_typeof(history) = 'array'::text) AND (octet_length((history)::text) <= 100000))),
    CONSTRAINT npc_evolution_reports_report_check CHECK (((octet_length(report) >= 1) AND (octet_length(report) <= 8192)))
);


--
-- Name: npc_memory_digests; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.npc_memory_digests (
    digest_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    profile_id text NOT NULL,
    revision integer NOT NULL,
    previous_digest_id uuid,
    job_id uuid,
    cleared boolean DEFAULT false NOT NULL,
    content text NOT NULL,
    cursor_occurred_at timestamp with time zone NOT NULL,
    cursor_memory_id uuid NOT NULL,
    source_revisions jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT npc_memory_digests_check CHECK (((octet_length(content) <= 16384) AND
CASE
    WHEN cleared THEN (content = ''::text)
    ELSE (octet_length(content) > 0)
END)),
    CONSTRAINT npc_memory_digests_revision_check CHECK ((revision > 0)),
    CONSTRAINT npc_memory_digests_source_revisions_check CHECK (((jsonb_typeof(source_revisions) = 'array'::text) AND (jsonb_array_length(source_revisions) <= 100) AND (octet_length((source_revisions)::text) <= 131072)))
);


--
-- Name: TABLE npc_memory_digests; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.npc_memory_digests IS 'Playthrough Manager Backed Up';


--
-- Name: npc_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.npc_metadata (
    npc_id integer NOT NULL,
    installation_id uuid NOT NULL,
    source_profile_id text NOT NULL,
    source_revision integer NOT NULL,
    actor_identity jsonb NOT NULL,
    CONSTRAINT npc_metadata_actor_identity_check CHECK ((jsonb_typeof(actor_identity) = 'object'::text))
);


--
-- Name: npc_reference_groups; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.npc_reference_groups (
    installation_id uuid NOT NULL,
    group_key text NOT NULL,
    name text NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    canonical_ref text NOT NULL,
    aliases jsonb DEFAULT '[]'::jsonb NOT NULL,
    match_name text DEFAULT ''::text NOT NULL
);


--
-- Name: oghma_catalog_deletions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_catalog_deletions (
    installation_id uuid NOT NULL,
    topic character varying(256) NOT NULL,
    deleted_at timestamp with time zone NOT NULL,
    CONSTRAINT oghma_catalog_deletions_topic_check CHECK ((((topic)::text = lower(btrim((topic)::text))) AND (length((topic)::text) > 0)))
);


--
-- Name: TABLE oghma_catalog_deletions; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.oghma_catalog_deletions IS 'Playthrough Manager Backed Up';


--
-- Name: oghma_catalog_entries; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_catalog_entries (
    catalog_id uuid NOT NULL,
    topic character varying(256) NOT NULL,
    title character varying(256) NOT NULL,
    aliases text DEFAULT ''::text NOT NULL,
    topic_desc text NOT NULL,
    knowledge_class text NOT NULL,
    topic_desc_basic text NOT NULL,
    knowledge_class_basic text NOT NULL,
    tags text NOT NULL,
    category text NOT NULL,
    mod_source character varying(256),
    CONSTRAINT oghma_catalog_entries_category_check CHECK (((octet_length(category) >= 1) AND (octet_length(category) <= 128))),
    CONSTRAINT oghma_catalog_entries_mod_source_check CHECK (((mod_source IS NULL) OR (((mod_source)::text = btrim((mod_source)::text)) AND ((mod_source)::text ~* '^[^/\\]+\.(esm|esp|omwaddon)$'::text)))),
    CONSTRAINT oghma_catalog_entries_title_check CHECK (((octet_length((title)::text) >= 1) AND (octet_length((title)::text) <= 256))),
    CONSTRAINT oghma_catalog_entries_topic_check CHECK (((octet_length((topic)::text) >= 1) AND (octet_length((topic)::text) <= 256))),
    CONSTRAINT oghma_catalog_entries_topic_desc_basic_check CHECK (((octet_length(topic_desc_basic) >= 1) AND (octet_length(topic_desc_basic) <= 131072))),
    CONSTRAINT oghma_catalog_entries_topic_desc_check CHECK (((octet_length(topic_desc) >= 1) AND (octet_length(topic_desc) <= 131072)))
);


--
-- Name: TABLE oghma_catalog_entries; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.oghma_catalog_entries IS 'Playthrough Manager Backed Up';


--
-- Name: oghma_catalogs; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_catalogs (
    catalog_id uuid NOT NULL,
    catalog_version character varying(128) NOT NULL,
    format_version text NOT NULL,
    articles_sha256 character(64) NOT NULL,
    manifest_sha256 character(64) NOT NULL,
    ontology_sha256 character(64) NOT NULL,
    topic_seeds_sha256 character(64) NOT NULL,
    generator_sha256 character(64) NOT NULL,
    builder_sha256 character(64) NOT NULL,
    official_content_sha256 jsonb NOT NULL,
    row_count integer NOT NULL,
    state text NOT NULL,
    previous_catalog_id uuid,
    imported_at timestamp with time zone NOT NULL,
    activated_at timestamp with time zone NOT NULL,
    superseded_at timestamp with time zone,
    CONSTRAINT oghma_catalogs_catalog_version_check CHECK ((((catalog_version)::text = btrim((catalog_version)::text)) AND ((length((catalog_version)::text) >= 1) AND (length((catalog_version)::text) <= 128)))),
    CONSTRAINT oghma_catalogs_official_content_sha256_check CHECK ((jsonb_typeof(official_content_sha256) = 'object'::text)),
    CONSTRAINT oghma_catalogs_row_count_check CHECK ((row_count >= 1)),
    CONSTRAINT oghma_catalogs_state_check CHECK ((state = ANY (ARRAY['active'::text, 'superseded'::text])))
);


--
-- Name: TABLE oghma_catalogs; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.oghma_catalogs IS 'Playthrough Manager Backed Up';


--
-- Name: oghma_dynamic; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_dynamic (
    id uuid NOT NULL,
    installation_id uuid NOT NULL,
    revision integer DEFAULT 1 NOT NULL,
    id_quest character varying(256) NOT NULL,
    stage integer NOT NULL,
    topic character varying(256) NOT NULL,
    topic_desc text DEFAULT ''::text NOT NULL,
    knowledge_class text DEFAULT ''::text NOT NULL,
    topic_desc_basic text DEFAULT ''::text NOT NULL,
    knowledge_class_basic text DEFAULT ''::text NOT NULL,
    tags text DEFAULT ''::text NOT NULL,
    category character varying(128) DEFAULT ''::character varying NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT oghma_dynamic_id_quest_check CHECK ((((id_quest)::text = lower(btrim((id_quest)::text))) AND (length((id_quest)::text) > 0))),
    CONSTRAINT oghma_dynamic_knowledge_class_basic_check CHECK ((octet_length(knowledge_class_basic) <= 4096)),
    CONSTRAINT oghma_dynamic_knowledge_class_check CHECK ((octet_length(knowledge_class) <= 4096)),
    CONSTRAINT oghma_dynamic_revision_check CHECK ((revision > 0)),
    CONSTRAINT oghma_dynamic_stage_check CHECK ((stage >= 0)),
    CONSTRAINT oghma_dynamic_tags_check CHECK ((octet_length(tags) <= 4096)),
    CONSTRAINT oghma_dynamic_topic_check CHECK ((((topic)::text = lower(btrim((topic)::text))) AND (length((topic)::text) > 0))),
    CONSTRAINT oghma_dynamic_topic_desc_basic_check CHECK ((octet_length(topic_desc_basic) <= 131072)),
    CONSTRAINT oghma_dynamic_topic_desc_check CHECK ((octet_length(topic_desc) <= 131072))
);


--
-- Name: TABLE oghma_dynamic; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.oghma_dynamic IS 'Playthrough Manager Backed Up';


--
-- Name: oghma_dynamic_applications; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_dynamic_applications (
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    rule_id uuid NOT NULL,
    revision integer NOT NULL,
    source_event_id uuid NOT NULL,
    document_id uuid NOT NULL,
    patch jsonb NOT NULL,
    applied_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT oghma_dynamic_applications_patch_check CHECK ((jsonb_typeof(patch) = 'object'::text)),
    CONSTRAINT oghma_dynamic_applications_revision_check CHECK ((revision > 0))
);


--
-- Name: TABLE oghma_dynamic_applications; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.oghma_dynamic_applications IS 'Playthrough Manager Backed Up';


--
-- Name: oghma_factory_documents; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_factory_documents (
    installation_id uuid NOT NULL,
    topic character varying(256) NOT NULL,
    document_id uuid NOT NULL,
    catalog_id uuid NOT NULL
);


--
-- Name: TABLE oghma_factory_documents; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.oghma_factory_documents IS 'Playthrough Manager Backed Up';


--
-- Name: oghma_installation_settings; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_installation_settings (
    installation_id uuid NOT NULL,
    knowledge_tags text DEFAULT ''::text NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    racial_context_enabled boolean DEFAULT true NOT NULL,
    location_context_enabled boolean DEFAULT true NOT NULL,
    topic_count smallint DEFAULT 1 NOT NULL,
    extractor_enabled boolean DEFAULT false NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    result_limit smallint DEFAULT 3 NOT NULL,
    extractor_timeout_ms integer DEFAULT 1500 NOT NULL,
    CONSTRAINT oghma_extractor_timeout_range CHECK (((extractor_timeout_ms >= 250) AND (extractor_timeout_ms <= 3000))),
    CONSTRAINT oghma_installation_settings_knowledge_tags_check CHECK (((octet_length(knowledge_tags) >= 0) AND (octet_length(knowledge_tags) <= 4096))),
    CONSTRAINT oghma_result_limit_range CHECK (((result_limit >= 1) AND (result_limit <= 5))),
    CONSTRAINT oghma_topic_count_range CHECK (((topic_count >= 1) AND (topic_count <= 3)))
);


--
-- Name: oghma_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.oghma_metadata (
    topic character varying NOT NULL,
    document_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text,
    playthrough_id uuid
);


--
-- Name: operational_audit; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.operational_audit (
    audit_id uuid NOT NULL,
    category text NOT NULL,
    action text NOT NULL,
    scope jsonb DEFAULT '{}'::jsonb NOT NULL,
    detail jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT operational_audit_detail_check CHECK ((jsonb_typeof(detail) = 'object'::text)),
    CONSTRAINT operational_audit_scope_check CHECK ((jsonb_typeof(scope) = 'object'::text))
);


--
-- Name: pairing_tokens; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.pairing_tokens (
    pairing_token_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    token_hash character(64) NOT NULL,
    state text NOT NULL,
    valid_until timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    revoked_at timestamp with time zone,
    mac_key bytea NOT NULL,
    CONSTRAINT pairing_tokens_check CHECK ((((state = 'overlap'::text) AND (valid_until IS NOT NULL)) OR (state <> 'overlap'::text))),
    CONSTRAINT pairing_tokens_mac_key_length CHECK ((octet_length(mac_key) = 32)),
    CONSTRAINT pairing_tokens_state_check CHECK ((state = ANY (ARRAY['active'::text, 'overlap'::text, 'revoked'::text]))),
    CONSTRAINT pairing_tokens_token_hash_check CHECK ((token_hash ~ '^[0-9a-f]{64}$'::text))
);


--
-- Name: physical_diary_deliveries; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.physical_diary_deliveries (
    delivery_id uuid NOT NULL,
    book_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    snapshot jsonb NOT NULL,
    content_hash character(64) NOT NULL,
    state text DEFAULT 'delivered'::text NOT NULL,
    result_message_id uuid,
    result_fingerprint character(64),
    reason_code text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    checked_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    completed_at timestamp with time zone,
    retry_after timestamp with time zone,
    CONSTRAINT physical_diary_deliveries_check CHECK (((result_message_id IS NULL) = (result_fingerprint IS NULL))),
    CONSTRAINT physical_diary_deliveries_content_hash_check CHECK ((content_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT physical_diary_deliveries_generation_check CHECK ((generation >= 0)),
    CONSTRAINT physical_diary_deliveries_snapshot_check CHECK (((jsonb_typeof(snapshot) = 'object'::text) AND (octet_length((snapshot)::text) <= 16384))),
    CONSTRAINT physical_diary_deliveries_state_check CHECK ((state = ANY (ARRAY['delivered'::text, 'succeeded'::text, 'failed'::text, 'superseded'::text])))
);


--
-- Name: player2_routing; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.player2_routing (
    installation_id uuid NOT NULL,
    revision integer NOT NULL,
    enabled boolean NOT NULL,
    configuration_id uuid,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT player2_routing_check CHECK (((NOT enabled) OR (configuration_id IS NOT NULL))),
    CONSTRAINT player2_routing_revision_check CHECK ((revision > 0))
);


--
-- Name: player_speech_style_drafts; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.player_speech_style_drafts (
    job_id uuid NOT NULL,
    profile_id text NOT NULL,
    base_revision integer NOT NULL,
    speech_style text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT player_speech_style_drafts_base_revision_check CHECK ((base_revision > 0)),
    CONSTRAINT player_speech_style_drafts_speech_style_check CHECK (((octet_length(speech_style) >= 1) AND (octet_length(speech_style) <= 8192)))
);


--
-- Name: playthrough_associations; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.playthrough_associations (
    association_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    character_id uuid NOT NULL,
    from_playthrough_id uuid NOT NULL,
    to_playthrough_id uuid NOT NULL,
    state text DEFAULT 'pending'::text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    completed_at timestamp with time zone,
    CONSTRAINT playthrough_associations_check CHECK ((from_playthrough_id <> to_playthrough_id)),
    CONSTRAINT playthrough_associations_state_check CHECK ((state = ANY (ARRAY['pending'::text, 'applied'::text, 'cancelled'::text])))
);


--
-- Name: playthrough_local_state; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.playthrough_local_state (
    playthrough_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    state jsonb NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT playthrough_local_state_state_check CHECK ((jsonb_typeof(state) = 'object'::text))
);


--
-- Name: playthrough_revisions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.playthrough_revisions (
    playthrough_id uuid NOT NULL,
    revision integer NOT NULL,
    content jsonb NOT NULL,
    change_reason text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT playthrough_revisions_content_check CHECK ((jsonb_typeof(content) = 'object'::text)),
    CONSTRAINT playthrough_revisions_revision_check CHECK ((revision > 0))
);


--
-- Name: TABLE playthrough_revisions; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.playthrough_revisions IS 'Playthrough Manager Backed Up';


--
-- Name: playthrough_saves; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.playthrough_saves (
    save_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    name text NOT NULL,
    notes text DEFAULT ''::text NOT NULL,
    kind text NOT NULL,
    capture_key text,
    document text NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT playthrough_saves_kind_check CHECK ((kind = ANY (ARRAY['manual'::text, 'default'::text, 'dragon_break'::text, 'before_copy'::text]))),
    CONSTRAINT playthrough_saves_name_check CHECK (((length(name) >= 1) AND (length(name) <= 128)))
);


--
-- Name: playthroughs; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.playthroughs (
    playthrough_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    name text NOT NULL,
    content_fingerprint character varying(71),
    current_revision integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    CONSTRAINT playthroughs_content_fingerprint_check CHECK (((content_fingerprint IS NULL) OR ((content_fingerprint)::text ~ '^sha256:[0-9a-f]{64}$'::text))),
    CONSTRAINT playthroughs_current_revision_check CHECK ((current_revision > 0)),
    CONSTRAINT playthroughs_name_check CHECK (((octet_length(name) >= 1) AND (octet_length(name) <= 256)))
);


--
-- Name: TABLE playthroughs; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.playthroughs IS 'Playthrough Manager Backed Up';


--
-- Name: profile_assignment_rules; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.profile_assignment_rules (
    rule_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    core_profile_id uuid,
    description text NOT NULL,
    priority integer DEFAULT 0 NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    matchers jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT profile_assignment_rules_description_check CHECK (((octet_length(description) >= 1) AND (octet_length(description) <= 200))),
    CONSTRAINT profile_assignment_rules_matchers_check CHECK (((jsonb_typeof(matchers) = 'object'::text) AND ((octet_length((matchers)::text) >= 2) AND (octet_length((matchers)::text) <= 32768)))),
    CONSTRAINT profile_assignment_rules_priority_check CHECK (((priority >= '-100000'::integer) AND (priority <= 100000)))
);


--
-- Name: profile_evolution_clocks; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.profile_evolution_clocks (
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    epoch uuid NOT NULL,
    game_minute bigint NOT NULL,
    started_minute bigint NOT NULL,
    started_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);


--
-- Name: TABLE profile_evolution_clocks; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.profile_evolution_clocks IS 'Playthrough Manager Backed Up';


--
-- Name: profile_evolution_events; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.profile_evolution_events (
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    epoch uuid NOT NULL,
    rowid bigint NOT NULL
);


--
-- Name: TABLE profile_evolution_events; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.profile_evolution_events IS 'Playthrough Manager Backed Up';


--
-- Name: profile_evolution_progress; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.profile_evolution_progress (
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    epoch uuid NOT NULL,
    last_game_minute bigint NOT NULL,
    consumed_events bigint DEFAULT 0 NOT NULL,
    attempted_at timestamp with time zone,
    checked_at timestamp with time zone DEFAULT '-infinity'::timestamp with time zone NOT NULL,
    manual_requested boolean DEFAULT false NOT NULL,
    manual_request_id uuid,
    manual_requested_at timestamp with time zone,
    manual_attempts integer DEFAULT 0 NOT NULL
);


--
-- Name: TABLE profile_evolution_progress; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.profile_evolution_progress IS 'Playthrough Manager Backed Up';


--
-- Name: profile_revisions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.profile_revisions (
    profile_id text NOT NULL,
    revision integer NOT NULL,
    content jsonb NOT NULL,
    change_reason text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    provenance jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT profile_revisions_content_check CHECK ((jsonb_typeof(content) = 'object'::text)),
    CONSTRAINT profile_revisions_provenance_check CHECK (((jsonb_typeof(provenance) = 'object'::text) AND (octet_length((provenance)::text) <= 32768))),
    CONSTRAINT profile_revisions_revision_check CHECK ((revision > 0))
);


--
-- Name: TABLE profile_revisions; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.profile_revisions IS 'Playthrough Manager Backed Up';


--
-- Name: profiles; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.profiles (
    profile_id text NOT NULL,
    installation_id uuid NOT NULL,
    name text NOT NULL,
    actor_identity jsonb DEFAULT '{}'::jsonb NOT NULL,
    current_revision integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    core_profile_id uuid,
    playthrough_id uuid,
    CONSTRAINT profiles_actor_identity_check CHECK ((jsonb_typeof(actor_identity) = 'object'::text)),
    CONSTRAINT profiles_current_revision_check CHECK ((current_revision > 0)),
    CONSTRAINT profiles_name_check CHECK (((octet_length(name) >= 1) AND (octet_length(name) <= 256)))
);


--
-- Name: TABLE profiles; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.profiles IS 'Playthrough Manager Backed Up';


--
-- Name: prompt_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.prompt_metadata (
    prompt_key character varying(128) NOT NULL,
    installation_id uuid NOT NULL,
    source_configuration_id uuid,
    source_revision integer
);


--
-- Name: prompt_trace_sections; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.prompt_trace_sections (
    prompt_trace_id uuid NOT NULL,
    section_order smallint NOT NULL,
    section_key text NOT NULL,
    source_refs jsonb NOT NULL,
    inclusion_reason text NOT NULL,
    source_occurred_at timestamp with time zone,
    playthrough_id uuid NOT NULL,
    source_characters integer NOT NULL,
    estimated_tokens integer NOT NULL,
    redacted_preview text NOT NULL,
    source_sha256 character(64) NOT NULL,
    CONSTRAINT prompt_trace_sections_estimated_tokens_check CHECK (((estimated_tokens >= 0) AND (estimated_tokens <= 131072))),
    CONSTRAINT prompt_trace_sections_inclusion_reason_check CHECK ((inclusion_reason = ANY (ARRAY['included'::text, 'empty'::text, 'byte_limit'::text, 'minimal_fallback'::text, 'covered_by_history'::text]))),
    CONSTRAINT prompt_trace_sections_redacted_preview_check CHECK ((octet_length(redacted_preview) <= 256)),
    CONSTRAINT prompt_trace_sections_section_key_check CHECK ((section_key = ANY (ARRAY['output_contract'::text, 'npc_context'::text, 'player_narrator_context'::text, 'morrowind_context'::text, 'oghma_context'::text, 'relationships_factions'::text, 'memory_context'::text, 'conversation_context'::text, 'audience_speaker_rules'::text, 'negotiated_actions'::text, 'current_turn'::text]))),
    CONSTRAINT prompt_trace_sections_section_order_check CHECK (((section_order >= 1) AND (section_order <= 11))),
    CONSTRAINT prompt_trace_sections_source_characters_check CHECK (((source_characters >= 0) AND (source_characters <= 131072))),
    CONSTRAINT prompt_trace_sections_source_refs_check CHECK ((jsonb_typeof(source_refs) = 'array'::text)),
    CONSTRAINT prompt_trace_sections_source_sha256_check CHECK ((source_sha256 ~ '^[0-9a-f]{64}$'::text))
);


--
-- Name: prompt_trace_sources; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.prompt_trace_sources (
    prompt_trace_id uuid NOT NULL,
    ordinal integer NOT NULL,
    source_kind text NOT NULL,
    source_id text NOT NULL,
    included boolean NOT NULL,
    reason text NOT NULL,
    source_sha256 character(64) NOT NULL,
    included_bytes integer NOT NULL,
    redacted_preview text NOT NULL,
    section_key text NOT NULL,
    section_order smallint NOT NULL,
    source_table text NOT NULL,
    source_revision integer,
    source_occurred_at timestamp with time zone,
    playthrough_id uuid NOT NULL,
    source_characters integer NOT NULL,
    estimated_tokens integer NOT NULL,
    CONSTRAINT prompt_trace_sources_estimated_tokens_check CHECK (((estimated_tokens >= 0) AND (estimated_tokens <= 131072))),
    CONSTRAINT prompt_trace_sources_included_bytes_check CHECK (((included_bytes >= 0) AND (included_bytes <= 131072))),
    CONSTRAINT prompt_trace_sources_ordinal_check CHECK (((ordinal >= 0) AND (ordinal <= 1023))),
    CONSTRAINT prompt_trace_sources_reason_check CHECK ((reason = ANY (ARRAY['included'::text, 'section_limit'::text, 'byte_limit'::text, 'expired'::text, 'deleted'::text, 'policy_disabled'::text, 'covered_by_history'::text, 'covered_by_memory'::text]))),
    CONSTRAINT prompt_trace_sources_redacted_preview_check CHECK ((octet_length(redacted_preview) <= 256)),
    CONSTRAINT prompt_trace_sources_section_order_check CHECK (((section_order >= 1) AND (section_order <= 11))),
    CONSTRAINT prompt_trace_sources_source_characters_check CHECK (((source_characters >= 0) AND (source_characters <= 131072))),
    CONSTRAINT prompt_trace_sources_source_id_check CHECK (((octet_length(source_id) >= 1) AND (octet_length(source_id) <= 256))),
    CONSTRAINT prompt_trace_sources_source_kind_check CHECK ((source_kind = ANY (ARRAY['profile'::text, 'core_profile'::text, 'prompt'::text, 'history'::text, 'turn'::text, 'memory'::text, 'memory_digest'::text, 'relationship'::text, 'knowledge'::text, 'narrative'::text, 'action_result'::text, 'action_catalog'::text]))),
    CONSTRAINT prompt_trace_sources_source_revision_check CHECK (((source_revision IS NULL) OR (source_revision > 0))),
    CONSTRAINT prompt_trace_sources_source_sha256_check CHECK ((source_sha256 ~ '^[0-9a-f]{64}$'::text))
);


--
-- Name: prompt_traces; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.prompt_traces (
    prompt_trace_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    session_id uuid,
    turn_id uuid,
    request_id uuid,
    prompt_configuration_id uuid,
    prompt_revision integer,
    algorithm text NOT NULL,
    input_sha256 character(64) NOT NULL,
    input_bytes integer NOT NULL,
    truncated boolean NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    selected_profile_id text,
    selected_profile_revision integer,
    core_profile_id uuid,
    core_profile_revision integer,
    effective_settings_sha256 character(64),
    settings_sources jsonb,
    CONSTRAINT prompt_traces_algorithm_check CHECK (((char_length(btrim(algorithm)) >= 1) AND (char_length(btrim(algorithm)) <= 128))),
    CONSTRAINT prompt_traces_core_profile_revision_check CHECK (((core_profile_revision IS NULL) OR (core_profile_revision > 0))),
    CONSTRAINT prompt_traces_effective_settings_sha256_check CHECK (((effective_settings_sha256 IS NULL) OR (effective_settings_sha256 ~ '^[0-9a-f]{64}$'::text))),
    CONSTRAINT prompt_traces_input_bytes_check CHECK (((input_bytes >= 1) AND (input_bytes <= 131072))),
    CONSTRAINT prompt_traces_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT prompt_traces_prompt_revision_check CHECK (((prompt_revision IS NULL) OR (prompt_revision > 0))),
    CONSTRAINT prompt_traces_selected_profile_revision_check CHECK (((selected_profile_revision IS NULL) OR (selected_profile_revision > 0))),
    CONSTRAINT prompt_traces_settings_sources_check CHECK (((settings_sources IS NULL) OR (jsonb_typeof(settings_sources) = 'object'::text)))
);


--
-- Name: prompts; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.prompts (
    installation_id uuid NOT NULL,
    prompt_key character varying(128) NOT NULL,
    default_prompt text NOT NULL,
    custom_prompt text,
    description text DEFAULT ''::text NOT NULL,
    source_configuration_id uuid,
    source_revision integer,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT prompts_source_revision_check CHECK (((source_revision IS NULL) OR (source_revision > 0)))
);


--
-- Name: provider_attempts; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.provider_attempts (
    provider_attempt_id uuid NOT NULL,
    provider_kind text NOT NULL,
    provider_name text NOT NULL,
    operation text NOT NULL,
    request_id uuid,
    turn_id uuid,
    job_id uuid,
    attempt_number integer NOT NULL,
    state text NOT NULL,
    model text,
    config_revision text,
    input_bytes integer,
    output_bytes integer,
    started_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    finished_at timestamp with time zone,
    duration_ms integer,
    error_code text,
    error_detail text,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT provider_attempts_attempt_number_check CHECK ((attempt_number > 0)),
    CONSTRAINT provider_attempts_check CHECK ((((state = 'started'::text) AND (finished_at IS NULL)) OR ((state <> 'started'::text) AND (finished_at IS NOT NULL)))),
    CONSTRAINT provider_attempts_duration_ms_check CHECK (((duration_ms IS NULL) OR (duration_ms >= 0))),
    CONSTRAINT provider_attempts_input_bytes_check CHECK (((input_bytes IS NULL) OR (input_bytes >= 0))),
    CONSTRAINT provider_attempts_metadata_check CHECK ((jsonb_typeof(metadata) = 'object'::text)),
    CONSTRAINT provider_attempts_output_bytes_check CHECK (((output_bytes IS NULL) OR (output_bytes >= 0))),
    CONSTRAINT provider_attempts_provider_kind_check CHECK ((provider_kind = ANY (ARRAY['llm'::text, 'stt'::text, 'tts'::text, 'embedding'::text, 'translation'::text]))),
    CONSTRAINT provider_attempts_state_check CHECK ((state = ANY (ARRAY['started'::text, 'succeeded'::text, 'failed'::text, 'cancelled'::text])))
);


--
-- Name: quest_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.quest_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    session_id uuid,
    journal_id text NOT NULL,
    source_turn_id uuid
);


--
-- Name: TABLE quest_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.quest_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: questlog_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.questlog_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    session_id uuid,
    journal_id text NOT NULL,
    source_turn_id uuid,
    entry_hash character(64) NOT NULL
);


--
-- Name: TABLE questlog_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.questlog_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: quickstart_local_llm; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.quickstart_local_llm (
    installation_id uuid NOT NULL,
    configuration_id uuid NOT NULL,
    server_type text NOT NULL,
    scope text NOT NULL,
    CONSTRAINT quickstart_local_llm_scope_check CHECK ((scope = ANY (ARRAY['conversations'::text, 'all'::text]))),
    CONSTRAINT quickstart_local_llm_server_type_check CHECK ((server_type = ANY (ARRAY['lm_studio'::text, 'ollama'::text, 'llama_cpp'::text, 'koboldcpp'::text, 'other'::text])))
);


--
-- Name: rate_limit_buckets; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.rate_limit_buckets (
    bucket_key character(64) NOT NULL,
    window_started_at timestamp with time zone NOT NULL,
    request_count integer NOT NULL,
    CONSTRAINT rate_limit_buckets_request_count_check CHECK ((request_count >= 0))
);


--
-- Name: rechat_chains; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.rechat_chains (
    chain_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    mode text NOT NULL,
    state text DEFAULT 'open'::text NOT NULL,
    max_depth smallint NOT NULL,
    current_depth smallint DEFAULT 0 NOT NULL,
    participants jsonb NOT NULL,
    previous_speaker jsonb,
    next_target jsonb,
    origin_turn_id uuid,
    latest_turn_id uuid,
    cancellation_reason text,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    configured_mode text DEFAULT 'random'::text NOT NULL,
    probability_percent smallint DEFAULT 50 NOT NULL,
    strict_targeting boolean DEFAULT false NOT NULL,
    round_budget smallint NOT NULL,
    previous_listener jsonb,
    origin_line text,
    CONSTRAINT rechat_chains_check CHECK ((((current_depth >= 0) AND (current_depth <= 32)) AND (current_depth <= max_depth))),
    CONSTRAINT rechat_chains_configured_mode_check CHECK ((configured_mode = ANY (ARRAY['tight'::text, 'conversational'::text, 'group'::text, 'random'::text]))),
    CONSTRAINT rechat_chains_generation_check CHECK ((generation >= 0)),
    CONSTRAINT rechat_chains_max_depth_check CHECK (((max_depth >= 1) AND (max_depth <= 32))),
    CONSTRAINT rechat_chains_mode_check CHECK ((mode = ANY (ARRAY['tight'::text, 'conversational'::text, 'group'::text, 'random'::text]))),
    CONSTRAINT rechat_chains_next_target_check CHECK (((next_target IS NULL) OR (jsonb_typeof(next_target) = 'object'::text))),
    CONSTRAINT rechat_chains_participants_check CHECK ((jsonb_typeof(participants) = 'array'::text)),
    CONSTRAINT rechat_chains_previous_listener_check CHECK (((previous_listener IS NULL) OR (jsonb_typeof(previous_listener) = 'object'::text))),
    CONSTRAINT rechat_chains_previous_speaker_check CHECK (((previous_speaker IS NULL) OR (jsonb_typeof(previous_speaker) = 'object'::text))),
    CONSTRAINT rechat_chains_probability_percent_check CHECK (((probability_percent >= 0) AND (probability_percent <= 100))),
    CONSTRAINT rechat_chains_round_budget_check CHECK (((round_budget >= 1) AND (round_budget <= 32))),
    CONSTRAINT rechat_chains_state_check CHECK ((state = ANY (ARRAY['open'::text, 'awaiting_playback'::text, 'request_in_flight'::text, 'closed'::text, 'cancelled'::text])))
);


--
-- Name: relationship_audit; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.relationship_audit (
    audit_id uuid NOT NULL,
    relationship_id uuid NOT NULL,
    mode text NOT NULL,
    before_value jsonb,
    after_value jsonb NOT NULL,
    reason text NOT NULL,
    source_event_id uuid,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    audit_sequence bigint NOT NULL,
    CONSTRAINT relationship_audit_mode_check CHECK ((mode = ANY (ARRAY['derived'::text, 'manual'::text])))
);


--
-- Name: TABLE relationship_audit; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.relationship_audit IS 'Playthrough Manager Backed Up';


--
-- Name: relationship_audit_audit_sequence_seq; Type: SEQUENCE; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE lorkhan_internal.relationship_audit ALTER COLUMN audit_sequence ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME lorkhan_internal.relationship_audit_audit_sequence_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: relationship_build_results; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.relationship_build_results (
    job_id uuid NOT NULL,
    source_count integer NOT NULL,
    target_count integer NOT NULL,
    changed_count integer NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    draft jsonb,
    CONSTRAINT relationship_build_results_changed_count_check CHECK (((changed_count >= 0) AND (changed_count <= 20))),
    CONSTRAINT relationship_build_results_draft_check CHECK (((draft IS NULL) OR ((jsonb_typeof(draft) = 'array'::text) AND (jsonb_array_length(draft) <= 20)))),
    CONSTRAINT relationship_build_results_source_count_check CHECK (((source_count >= 1) AND (source_count <= 100))),
    CONSTRAINT relationship_build_results_target_count_check CHECK (((target_count >= 1) AND (target_count <= 20)))
);


--
-- Name: relationship_conversion_results; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.relationship_conversion_results (
    job_id uuid NOT NULL,
    batch_request_id uuid NOT NULL,
    owner_profile_id text NOT NULL,
    source_bytes integer NOT NULL,
    target_count integer NOT NULL,
    changed_count integer NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT relationship_conversion_results_changed_count_check CHECK (((changed_count >= 0) AND (changed_count <= 20))),
    CONSTRAINT relationship_conversion_results_source_bytes_check CHECK (((source_bytes >= 1) AND (source_bytes <= 32768))),
    CONSTRAINT relationship_conversion_results_target_count_check CHECK (((target_count >= 1) AND (target_count <= 20)))
);


--
-- Name: relationship_evaluation_results; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.relationship_evaluation_results (
    job_id uuid NOT NULL,
    source_event_id uuid NOT NULL,
    relationship_id uuid,
    disposition_delta integer NOT NULL,
    affinity_delta integer NOT NULL,
    reason text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT relationship_evaluation_results_affinity_delta_check CHECK (((affinity_delta >= '-10'::integer) AND (affinity_delta <= 10))),
    CONSTRAINT relationship_evaluation_results_disposition_delta_check CHECK (((disposition_delta >= '-10'::integer) AND (disposition_delta <= 10))),
    CONSTRAINT relationship_evaluation_results_reason_check CHECK (((octet_length(reason) >= 1) AND (octet_length(reason) <= 1024)))
);


--
-- Name: relationship_records; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.relationship_records (
    relationship_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    actor_identity jsonb NOT NULL,
    disposition integer NOT NULL,
    affinity integer NOT NULL,
    source_mode text NOT NULL,
    source_event_id uuid,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    deleted_at timestamp with time zone,
    revision integer DEFAULT 1 NOT NULL,
    custom_info text DEFAULT ''::text NOT NULL,
    relationship_type text DEFAULT 'neutral'::text NOT NULL,
    details jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT relationship_records_actor_identity_check CHECK ((jsonb_typeof(actor_identity) = 'object'::text)),
    CONSTRAINT relationship_records_affinity_check CHECK (((affinity >= '-100'::integer) AND (affinity <= 100))),
    CONSTRAINT relationship_records_custom_info_check CHECK ((char_length(custom_info) <= 2000)),
    CONSTRAINT relationship_records_details_check CHECK (((jsonb_typeof(details) = 'object'::text) AND ((details - ARRAY['relation'::text, 'note'::text, 'best'::text, 'worst'::text]) = '{}'::jsonb) AND ((NOT (details ? 'relation'::text)) OR ((jsonb_typeof((details -> 'relation'::text)) = 'string'::text) AND (char_length((details ->> 'relation'::text)) <= 1024))) AND ((NOT (details ? 'note'::text)) OR ((jsonb_typeof((details -> 'note'::text)) = 'string'::text) AND (char_length((details ->> 'note'::text)) <= 1024))) AND ((NOT (details ? 'best'::text)) OR ((jsonb_typeof((details -> 'best'::text)) = 'string'::text) AND (char_length((details ->> 'best'::text)) <= 1024))) AND ((NOT (details ? 'worst'::text)) OR ((jsonb_typeof((details -> 'worst'::text)) = 'string'::text) AND (char_length((details ->> 'worst'::text)) <= 1024))))),
    CONSTRAINT relationship_records_disposition_check CHECK (((disposition >= '-100'::integer) AND (disposition <= 100))),
    CONSTRAINT relationship_records_relationship_type_check CHECK ((relationship_type ~ '^[a-z][a-z0-9_-]{0,49}$'::text)),
    CONSTRAINT relationship_records_revision_check CHECK ((revision > 0)),
    CONSTRAINT relationship_records_source_mode_check CHECK ((source_mode = ANY (ARRAY['derived'::text, 'manual'::text])))
);


--
-- Name: TABLE relationship_records; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.relationship_records IS 'Playthrough Manager Backed Up';


--
-- Name: relationship_revisions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.relationship_revisions (
    relationship_id uuid NOT NULL,
    revision integer NOT NULL,
    content jsonb NOT NULL,
    provenance jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT relationship_revisions_content_check CHECK ((jsonb_typeof(content) = 'object'::text)),
    CONSTRAINT relationship_revisions_provenance_check CHECK (((jsonb_typeof(provenance) = 'object'::text) AND (octet_length((provenance)::text) <= 32768))),
    CONSTRAINT relationship_revisions_revision_check CHECK ((revision > 0))
);


--
-- Name: TABLE relationship_revisions; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.relationship_revisions IS 'Playthrough Manager Backed Up';


--
-- Name: request_log_hidden; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.request_log_hidden (
    provider_attempt_id uuid NOT NULL,
    cleared_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);


--
-- Name: request_mac_nonces; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.request_mac_nonces (
    pairing_token_id uuid NOT NULL,
    nonce character(32) NOT NULL,
    request_timestamp timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT request_mac_nonces_nonce_check CHECK ((nonce ~ '^[0-9a-f]{32}$'::text))
);


--
-- Name: response_events; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.response_events (
    session_id uuid NOT NULL,
    sequence bigint NOT NULL,
    message_id uuid NOT NULL,
    request_id uuid NOT NULL,
    generation bigint NOT NULL,
    turn_id uuid NOT NULL,
    event_type text NOT NULL,
    payload jsonb NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT response_events_event_type_check CHECK ((event_type = ANY (ARRAY['turn.accepted'::text, 'dialogue.delta'::text, 'dialogue.complete'::text, 'speech.ready'::text, 'action.intent'::text, 'response.complete'::text, 'turn.complete'::text, 'turn.failed'::text, 'turn.cancelled'::text, 'stt.transcript'::text, 'stt.failed'::text, 'director.instructions'::text, 'relationship.adjust'::text]))),
    CONSTRAINT response_events_generation_check CHECK ((generation >= 0)),
    CONSTRAINT response_events_sequence_check CHECK ((sequence > 0))
);


--
-- Name: responselog; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.responselog (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    session_id uuid,
    turn_id uuid,
    response_message_id uuid,
    localts bigint NOT NULL,
    sent bigint DEFAULT 0 NOT NULL,
    actor text,
    text text,
    action text,
    tag character varying(256),
    actor_identity jsonb DEFAULT '{}'::jsonb NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    sent_at timestamp with time zone,
    CONSTRAINT responselog_actor_identity_check CHECK ((jsonb_typeof(actor_identity) = 'object'::text)),
    CONSTRAINT responselog_payload_check CHECK ((jsonb_typeof(payload) = 'object'::text)),
    CONSTRAINT responselog_sent_check CHECK ((sent = ANY (ARRAY[(0)::bigint, (1)::bigint])))
);


--
-- Name: TABLE responselog; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.responselog IS 'Playthrough Manager Backed Up';


--
-- Name: responselog_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.responselog_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    session_id uuid,
    turn_id uuid,
    response_message_id uuid,
    actor_identity jsonb DEFAULT '{}'::jsonb NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    sent_at timestamp with time zone,
    CONSTRAINT responselog_metadata_actor_identity_check CHECK ((jsonb_typeof(actor_identity) = 'object'::text)),
    CONSTRAINT responselog_metadata_payload_check CHECK ((jsonb_typeof(payload) = 'object'::text))
);


--
-- Name: TABLE responselog_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.responselog_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: responselog_rowid_seq; Type: SEQUENCE; Schema: lorkhan_internal; Owner: -
--

CREATE SEQUENCE lorkhan_internal.responselog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: responselog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: lorkhan_internal; Owner: -
--

ALTER SEQUENCE lorkhan_internal.responselog_rowid_seq OWNED BY lorkhan_internal.responselog.rowid;


--
-- Name: retrieval_traces; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.retrieval_traces (
    retrieval_trace_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text,
    playthrough_id uuid,
    domain text NOT NULL,
    query text NOT NULL,
    result_ids uuid[] NOT NULL,
    scores jsonb NOT NULL,
    algorithm text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    turn_id uuid,
    prompt_section text,
    reasons jsonb DEFAULT '{}'::jsonb NOT NULL,
    CONSTRAINT retrieval_traces_domain_check CHECK ((domain = ANY (ARRAY['memory'::text, 'knowledge'::text]))),
    CONSTRAINT retrieval_traces_prompt_section_check CHECK (((prompt_section IS NULL) OR (prompt_section = ANY (ARRAY['memory_context'::text, 'morrowind_context'::text, 'oghma_context'::text])))),
    CONSTRAINT retrieval_traces_query_check CHECK (((octet_length(query) >= 1) AND (octet_length(query) <= 4096))),
    CONSTRAINT retrieval_traces_reasons_check CHECK ((jsonb_typeof(reasons) = 'object'::text)),
    CONSTRAINT retrieval_traces_scores_check CHECK ((jsonb_typeof(scores) = 'object'::text))
);


--
-- Name: TABLE retrieval_traces; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.retrieval_traces IS 'Playthrough Manager Backed Up';


--
-- Name: scene_classifications; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.scene_classifications (
    job_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid NOT NULL,
    profile_id text NOT NULL,
    turn_id uuid NOT NULL,
    history jsonb NOT NULL,
    genre text,
    observed_at timestamp with time zone NOT NULL,
    classified_at timestamp with time zone,
    CONSTRAINT scene_classifications_genre_check CHECK ((genre = ANY (ARRAY['default'::text, 'horror'::text, 'action'::text, 'thriller'::text, 'mystery'::text, 'romance'::text, 'comedy'::text, 'drama'::text, 'nsfw'::text]))),
    CONSTRAINT scene_classifications_history_check CHECK (((jsonb_typeof(history) = 'array'::text) AND ((jsonb_array_length(history) >= 1) AND (jsonb_array_length(history) <= 10)) AND (octet_length((history)::text) <= 32768)))
);


--
-- Name: sessions; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.sessions (
    session_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    profile_id text NOT NULL,
    playthrough_id uuid NOT NULL,
    generation bigint NOT NULL,
    content_fingerprint character varying(71) NOT NULL,
    openmw_version text NOT NULL,
    openmw_commit character(40) NOT NULL,
    lua_api_revision integer NOT NULL,
    client_version text NOT NULL,
    platform text NOT NULL,
    capabilities text[] DEFAULT '{}'::text[] NOT NULL,
    enabled_actions text[] DEFAULT '{}'::text[] NOT NULL,
    event_sequence bigint DEFAULT 0 NOT NULL,
    state text DEFAULT 'active'::text NOT NULL,
    created_at timestamp with time zone NOT NULL,
    ended_at timestamp with time zone,
    character_id uuid,
    archived boolean DEFAULT false NOT NULL,
    CONSTRAINT sessions_archive_inert CHECK (((NOT archived) OR ((state = 'ended'::text) AND (cardinality(capabilities) = 0) AND (cardinality(enabled_actions) = 0) AND (character_id IS NULL)))),
    CONSTRAINT sessions_content_fingerprint_check CHECK (((content_fingerprint)::text ~ '^sha256:[0-9a-f]{64}$'::text)),
    CONSTRAINT sessions_event_sequence_check CHECK ((event_sequence >= 0)),
    CONSTRAINT sessions_generation_check CHECK ((generation >= 0)),
    CONSTRAINT sessions_state_check CHECK ((state = ANY (ARRAY['active'::text, 'ended'::text, 'replaced'::text])))
);


--
-- Name: TABLE sessions; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.sessions IS 'Playthrough Manager Backed Up';


--
-- Name: source_events; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.source_events (
    source_event_id uuid NOT NULL,
    installation_id uuid NOT NULL,
    session_id uuid,
    generation bigint,
    event_kind text NOT NULL,
    occurred_at timestamp with time zone NOT NULL,
    received_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    schema_name text NOT NULL,
    request_id uuid,
    turn_id uuid,
    action_id uuid,
    payload jsonb NOT NULL,
    CONSTRAINT source_events_check CHECK ((((session_id IS NULL) AND (generation IS NULL)) OR ((session_id IS NOT NULL) AND (generation >= 0))))
);


--
-- Name: TABLE source_events; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.source_events IS 'Playthrough Manager Backed Up';


--
-- Name: speech; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.speech (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    session_id uuid,
    turn_id uuid,
    dialogue_message_id uuid,
    sess character varying(1024),
    speaker text,
    speech text NOT NULL,
    location text,
    listener text,
    topic text,
    localts bigint NOT NULL,
    gamets bigint DEFAULT 0 NOT NULL,
    ts bigint,
    companions text,
    audios text,
    utterance_id text,
    speaker_identity jsonb DEFAULT '{}'::jsonb NOT NULL,
    listener_identity jsonb DEFAULT '{}'::jsonb NOT NULL,
    audience jsonb DEFAULT '[]'::jsonb NOT NULL,
    delivery_state text DEFAULT 'emitted'::text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT speech_audience_check CHECK ((jsonb_typeof(audience) = 'array'::text)),
    CONSTRAINT speech_delivery_state_check CHECK ((delivery_state = ANY (ARRAY['emitted'::text, 'pending'::text, 'spoken'::text, 'played'::text, 'failed'::text, 'expired'::text, 'interrupted'::text]))),
    CONSTRAINT speech_listener_identity_check CHECK ((jsonb_typeof(listener_identity) = 'object'::text)),
    CONSTRAINT speech_speaker_identity_check CHECK ((jsonb_typeof(speaker_identity) = 'object'::text))
);


--
-- Name: TABLE speech; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.speech IS 'Playthrough Manager Backed Up';


--
-- Name: speech_connector_voices; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.speech_connector_voices (
    configuration_id uuid NOT NULL,
    voice_id text NOT NULL,
    display_name text NOT NULL,
    language text NOT NULL,
    provider_status text NOT NULL,
    custom_voice boolean DEFAULT false NOT NULL,
    discovered_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT speech_connector_voices_display_name_check CHECK (((octet_length(display_name) >= 1) AND (octet_length(display_name) <= 512))),
    CONSTRAINT speech_connector_voices_language_check CHECK (((octet_length(language) >= 2) AND (octet_length(language) <= 35))),
    CONSTRAINT speech_connector_voices_provider_status_check CHECK (((octet_length(provider_status) >= 1) AND (octet_length(provider_status) <= 64))),
    CONSTRAINT speech_connector_voices_voice_id_check CHECK (((octet_length(voice_id) >= 1) AND (octet_length(voice_id) <= 512)))
);


--
-- Name: speech_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.speech_metadata (
    rowid bigint NOT NULL,
    installation_id uuid NOT NULL,
    playthrough_id uuid,
    session_id uuid,
    turn_id uuid,
    dialogue_message_id uuid,
    speaker_identity jsonb DEFAULT '{}'::jsonb NOT NULL,
    listener_identity jsonb DEFAULT '{}'::jsonb NOT NULL,
    audience jsonb DEFAULT '[]'::jsonb NOT NULL,
    delivery_state text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT speech_metadata_audience_check CHECK ((jsonb_typeof(audience) = 'array'::text)),
    CONSTRAINT speech_metadata_listener_identity_check CHECK ((jsonb_typeof(listener_identity) = 'object'::text)),
    CONSTRAINT speech_metadata_speaker_identity_check CHECK ((jsonb_typeof(speaker_identity) = 'object'::text))
);


--
-- Name: TABLE speech_metadata; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.speech_metadata IS 'Playthrough Manager Backed Up';


--
-- Name: speech_rowid_seq; Type: SEQUENCE; Schema: lorkhan_internal; Owner: -
--

CREATE SEQUENCE lorkhan_internal.speech_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: speech_rowid_seq; Type: SEQUENCE OWNED BY; Schema: lorkhan_internal; Owner: -
--

ALTER SEQUENCE lorkhan_internal.speech_rowid_seq OWNED BY lorkhan_internal.speech.rowid;


--
-- Name: stt_requests; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.stt_requests (
    message_id uuid NOT NULL,
    request_id uuid NOT NULL,
    turn_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    codec text NOT NULL,
    language text NOT NULL,
    audio_bytes integer NOT NULL,
    sha256 character(64) NOT NULL,
    state text NOT NULL,
    transcript text,
    created_at timestamp with time zone NOT NULL,
    completed_at timestamp with time zone,
    storage_media_id uuid,
    semantic_hash character(64),
    accepted_cursor bigint,
    provider_error_code text,
    processing_job_id uuid,
    processing_lease_token uuid,
    processing_job_attempt integer,
    cleanup_pending boolean DEFAULT false NOT NULL,
    CONSTRAINT stt_requests_accepted_cursor_check CHECK (((accepted_cursor IS NULL) OR (accepted_cursor >= 0))),
    CONSTRAINT stt_requests_audio_bytes_check CHECK (((audio_bytes >= 1) AND (audio_bytes <= 16777216))),
    CONSTRAINT stt_requests_codec_check CHECK ((codec = 'wav'::text)),
    CONSTRAINT stt_requests_generation_check CHECK ((generation >= 0)),
    CONSTRAINT stt_requests_language_check CHECK (((octet_length(language) >= 2) AND (octet_length(language) <= 35))),
    CONSTRAINT stt_requests_processing_job_attempt_check CHECK (((processing_job_attempt IS NULL) OR (processing_job_attempt > 0))),
    CONSTRAINT stt_requests_semantic_hash_check CHECK (((semantic_hash IS NULL) OR (semantic_hash ~ '^[0-9a-f]{64}$'::text))),
    CONSTRAINT stt_requests_sha256_check CHECK ((sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT stt_requests_state_check CHECK ((state = ANY (ARRAY['accepted'::text, 'processing'::text, 'transcribed'::text, 'failed'::text]))),
    CONSTRAINT stt_requests_transcript_check CHECK (((transcript IS NULL) OR ((octet_length(transcript) >= 1) AND (octet_length(transcript) <= 16384))))
);


--
-- Name: timeline_invalidated_sources; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.timeline_invalidated_sources (
    source_event_id uuid NOT NULL,
    loaded_save_id uuid NOT NULL,
    cutoff_minute bigint NOT NULL,
    invalidated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL
);


--
-- Name: TABLE timeline_invalidated_sources; Type: COMMENT; Schema: lorkhan_internal; Owner: -
--

COMMENT ON TABLE lorkhan_internal.timeline_invalidated_sources IS 'Playthrough Manager Backed Up';


--
-- Name: tts_connector_metadata; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.tts_connector_metadata (
    connector_id integer NOT NULL,
    installation_id uuid NOT NULL,
    configuration_id uuid NOT NULL,
    configuration_revision integer NOT NULL
);


--
-- Name: turn_provider_snapshots; Type: TABLE; Schema: lorkhan_internal; Owner: -
--

CREATE TABLE lorkhan_internal.turn_provider_snapshots (
    turn_id uuid NOT NULL,
    source_manifest jsonb NOT NULL,
    input_sha256 character(64) NOT NULL,
    created_at timestamp with time zone NOT NULL,
    CONSTRAINT turn_provider_snapshots_input_sha256_check CHECK ((input_sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT turn_provider_snapshots_source_manifest_check CHECK ((jsonb_typeof(source_manifest) = 'object'::text))
);


--
-- Name: actions_issued; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.actions_issued (
    action text,
    fullcall text,
    actorname text,
    ts numeric,
    localts numeric,
    gamets numeric,
    original text,
    rowid integer NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE actions_issued; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.actions_issued IS 'Playthrough Manager Backed Up';


--
-- Name: actions_issued_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.actions_issued_rowid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: actions_issued_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.actions_issued_rowid_seq OWNED BY public.actions_issued.rowid;


--
-- Name: animations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.animations (
    mood character varying(128) NOT NULL,
    animations character varying(65535),
    npc character varying(256)
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: animations_custom; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.animations_custom (
    mood character varying(128) NOT NULL,
    animations character varying(65535),
    npc character varying(256)
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: core_api_badge; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_api_badge (
    id integer NOT NULL,
    label text NOT NULL,
    api_key text NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: api_badge_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.api_badge_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: api_badge_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.api_badge_id_seq OWNED BY public.core_api_badge.id;


--
-- Name: audit_memory; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_memory (
    input text,
    keywords text,
    rank_any numeric(20,10),
    rank_all numeric(20,10),
    memory text,
    "time" text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    recall_candidates jsonb
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE audit_memory; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.audit_memory IS 'Playthrough Manager Backed Up';


--
-- Name: audit_request_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_request_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_request; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_request (
    request text,
    result text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    rowid bigint DEFAULT nextval('public.audit_request_rowid_seq'::regclass) NOT NULL,
    url text,
    connector text,
    usage jsonb,
    response text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE audit_request; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.audit_request IS 'Playthrough Manager Backed Up';


--
-- Name: bio_templates; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bio_templates (
    npc_name character varying(128) NOT NULL,
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


--
-- Name: bio_templates_custom; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.bio_templates_custom (
    npc_name character varying(128) NOT NULL,
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


--
-- Name: books; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.books (
    sess character varying(1024),
    title text,
    content text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE books; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.books IS 'Playthrough Manager Backed Up';


--
-- Name: books_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.books_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: books_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.books_rowid_seq OWNED BY public.books.rowid;


--
-- Name: combined_animations; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.combined_animations AS
 SELECT c.mood,
    c.animations,
    c.npc
   FROM public.animations_custom c
UNION ALL
 SELECT t.mood,
    t.animations,
    t.npc
   FROM (public.animations t
     LEFT JOIN public.animations_custom c ON (((t.mood)::text = (c.mood)::text)))
  WHERE (c.mood IS NULL);


--
-- Name: combined_bio_templates; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.combined_bio_templates AS
 SELECT c.npc_name,
    c.oghma_knowledge_tags,
    c.core,
    c.npc_static_bio,
    c.appearance,
    c.personality,
    c.relationships,
    c.occupation,
    c.skills,
    c.speechstyle,
    c.goals,
    c.voiceid,
    c.gender,
    c.race,
    c.refid
   FROM public.bio_templates_custom c
UNION ALL
 SELECT b.npc_name,
    b.oghma_knowledge_tags,
    b.core,
    b.npc_static_bio,
    b.appearance,
    b.personality,
    b.relationships,
    b.occupation,
    b.skills,
    b.speechstyle,
    b.goals,
    b.voiceid,
    b.gender,
    b.race,
    b.refid
   FROM (public.bio_templates b
     LEFT JOIN public.bio_templates_custom c ON (((b.npc_name)::text = (c.npc_name)::text)))
  WHERE (c.npc_name IS NULL);


--
-- Name: core_action; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_action (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: core_action_custom; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_action_custom (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: combined_core_action; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.combined_core_action AS
 SELECT c.id,
    c.code_name,
    c.action_name,
    c.description,
    c.return_message,
    c.available_to_npc,
    c.available_to_followers,
    c.available_to_narrator,
    c.is_activated,
    c.parameters_json,
    c.metadata,
    c.game_function,
    c.import_version,
    c.script_proxy_program,
    c.created_at,
    c.updated_at
   FROM public.core_action_custom c
UNION ALL
 SELECT b.id,
    b.code_name,
    b.action_name,
    b.description,
    b.return_message,
    b.available_to_npc,
    b.available_to_followers,
    b.available_to_narrator,
    b.is_activated,
    b.parameters_json,
    b.metadata,
    b.game_function,
    b.import_version,
    b.script_proxy_program,
    b.created_at,
    b.updated_at
   FROM (public.core_action b
     LEFT JOIN public.core_action_custom c ON ((lower((b.code_name)::text) = lower((c.code_name)::text))))
  WHERE (c.code_name IS NULL);


--
-- Name: descriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.descriptions (
    plugin text DEFAULT ''::text NOT NULL,
    baseid character varying(128) NOT NULL,
    name text,
    description text
);


--
-- Name: descriptions_custom; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.descriptions_custom (
    plugin text DEFAULT ''::text NOT NULL,
    baseid character varying(128) NOT NULL,
    name text,
    description text
);


--
-- Name: combined_descriptions; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.combined_descriptions AS
 SELECT c.plugin,
    c.baseid,
    c.name,
    c.description
   FROM public.descriptions_custom c
UNION ALL
 SELECT i.plugin,
    i.baseid,
    i.name,
    i.description
   FROM (public.descriptions i
     LEFT JOIN public.descriptions_custom c ON (((i.plugin = c.plugin) AND ((i.baseid)::text = (c.baseid)::text))))
  WHERE (c.baseid IS NULL);


--
-- Name: conf_opts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.conf_opts (
    id text NOT NULL,
    value text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE conf_opts; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.conf_opts IS 'Playthrough Manager Backed Up';


--
-- Name: core_action_custom_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.core_action_custom_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: core_action_custom_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.core_action_custom_id_seq OWNED BY public.core_action_custom.id;


--
-- Name: core_action_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.core_action_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: core_action_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.core_action_id_seq OWNED BY public.core_action.id;


--
-- Name: core_llm_connector; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_llm_connector (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: core_narrator; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_narrator (
    id text NOT NULL,
    value text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: COLUMN core_narrator.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_narrator.id IS 'Narrator setting key';


--
-- Name: COLUMN core_narrator.value; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_narrator.value IS 'Value for the setting';


--
-- Name: core_npc_master; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_npc_master (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: COLUMN core_npc_master.personality; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_npc_master.personality IS 'how they behave';


--
-- Name: COLUMN core_npc_master.core; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_npc_master.core IS 'really quick summary of character';


--
-- Name: COLUMN core_npc_master.tags; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_npc_master.tags IS 'comma separated,user tags';


--
-- Name: core_npc_master_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_npc_master_history (
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


--
-- Name: core_npc_master_history_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.core_npc_master_history_history_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: core_npc_master_history_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.core_npc_master_history_history_id_seq OWNED BY public.core_npc_master_history.history_id;


--
-- Name: core_player; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_player (
    id text NOT NULL,
    value text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE core_player; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.core_player IS 'Playthrough Manager Backed Up';


--
-- Name: COLUMN core_player.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_player.id IS 'Key name such as player_name, appearance, speech_style, or Morrowind stats';


--
-- Name: COLUMN core_player.value; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_player.value IS 'Value for the key';


--
-- Name: core_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_profiles (
    id integer NOT NULL,
    label text,
    default_npc text,
    default_narrator text,
    tts_connector_id integer,
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: COLUMN core_profiles.llm_fallback_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_profiles.llm_fallback_id IS 'Fallback LLM connector used when primary connector fails with network error';


--
-- Name: COLUMN core_profiles.prompt; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.core_profiles.prompt IS 'profile specific prompt, will be added to context';


--
-- Name: core_stt_connector; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_stt_connector (
    id integer NOT NULL,
    driver text,
    label text,
    metadata jsonb,
    api_badge_id integer,
    url text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: core_tts_connector; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_tts_connector (
    id integer NOT NULL,
    driver text,
    label text,
    metadata jsonb,
    api_badge_id integer,
    url text,
    voice_field text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: core_tts_fallback; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.core_tts_fallback (
    id integer NOT NULL,
    race text NOT NULL,
    gender text NOT NULL,
    voiceid text DEFAULT ''::text NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT core_tts_fallback_gender_check CHECK ((gender = ANY (ARRAY['male'::text, 'female'::text])))
);


--
-- Name: core_tts_fallback_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

ALTER TABLE public.core_tts_fallback ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY (
    SEQUENCE NAME public.core_tts_fallback_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: currentmission; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.currentmission (
    sess character varying(1024),
    description text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE currentmission; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.currentmission IS 'Playthrough Manager Backed Up';


--
-- Name: currentmission_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.currentmission_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: currentmission_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.currentmission_rowid_seq OWNED BY public.currentmission.rowid;


--
-- Name: database_versioning; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.database_versioning (
    tablename text NOT NULL,
    version bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: diarylog; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.diarylog (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE diarylog; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.diarylog IS 'Playthrough Manager Backed Up';


--
-- Name: diarylog_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.diarylog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: diarylog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.diarylog_rowid_seq OWNED BY public.diarylog.rowid;


--
-- Name: dynamic_bio; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.dynamic_bio (
    id integer NOT NULL,
    prompt text NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE dynamic_bio; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.dynamic_bio IS 'Playthrough Manager Backed Up';


--
-- Name: dynamic_bio_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.dynamic_bio_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: dynamic_bio_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.dynamic_bio_id_seq OWNED BY public.dynamic_bio.id;


--
-- Name: eventlog; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.eventlog (
    type character varying(128),
    data text,
    sess character varying(1024),
    gamets bigint NOT NULL,
    localts bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL,
    people text,
    location text,
    party text,
    utterance_id text,
    delivery_state text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE eventlog; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.eventlog IS 'Playthrough Manager Backed Up';


--
-- Name: eventlog_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.eventlog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: eventlog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.eventlog_rowid_seq OWNED BY public.eventlog.rowid;


--
-- Name: eventlog_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.eventlog_view AS
 SELECT e.type,
    e.data,
    e.sess,
    e.gamets,
    e.localts,
    e.ts,
    e.rowid,
    e.people,
    e.location,
    e.party,
    e.utterance_id,
    e.delivery_state,
    (to_timestamp((e.localts)::double precision) AT TIME ZONE 'UTC'::text) AS mw_local_datetime,
        CASE
            WHEN (e.ts IS NULL) THEN NULL::timestamp without time zone
            ELSE (to_timestamp((((e.ts)::numeric / 1000.0))::double precision) AT TIME ZONE 'UTC'::text)
        END AS mw_event_datetime
   FROM public.eventlog e;


--
-- Name: faction_vanilla; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.faction_vanilla (
    name text,
    formid text
);


--
-- Name: factions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.factions (
    name text,
    formid text NOT NULL,
    vendor_cont text,
    stock jsonb,
    gold numeric,
    player_rank numeric,
    localts bigint
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE factions; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.factions IS 'Playthrough Manager Backed Up';


--
-- Name: game_plugins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.game_plugins (
    plugin_name text NOT NULL,
    is_light boolean DEFAULT false NOT NULL,
    compile_index integer DEFAULT 0 NOT NULL,
    small_file_compile_index integer DEFAULT 0 NOT NULL,
    partial_index integer DEFAULT 0 NOT NULL,
    formid_prefix text DEFAULT ''::text NOT NULL,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE game_plugins; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.game_plugins IS 'Playthrough Manager Backed Up';


--
-- Name: general_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.general_settings (
    id text NOT NULL,
    value text,
    description text DEFAULT ''::text,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE general_settings; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.general_settings IS 'Playthrough Manager Backed Up';


--
-- Name: import_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.import_rules (
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


--
-- Name: import_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.import_rules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: import_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.import_rules_id_seq OWNED BY public.import_rules.id;


--
-- Name: json_personalities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.json_personalities (
    npc_name character varying(256) NOT NULL,
    personality jsonb
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: llm_connector_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.llm_connector_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: llm_connector_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.llm_connector_id_seq OWNED BY public.core_llm_connector.id;


--
-- Name: locations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.locations (
    name text,
    formid bigint,
    region text,
    hold text,
    tags text,
    factions text,
    is_interior integer,
    vanilla_location boolean,
    coords point,
    refs text,
    cleared boolean,
    updated_at timestamp without time zone,
    world text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE locations; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.locations IS 'Playthrough Manager Backed Up';


--
-- Name: locations_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.locations_v AS
 SELECT locations.name,
    locations.formid,
    locations.region,
    locations.hold,
    locations.tags,
    locations.factions,
    locations.is_interior,
    locations.vanilla_location,
    locations.coords,
    locations.refs,
    locations.cleared,
    locations.updated_at,
    locations.world
   FROM public.locations;


--
-- Name: log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.log (
    localts bigint NOT NULL,
    prompt text,
    response text,
    url text,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE log; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.log IS 'Playthrough Manager Backed Up';


--
-- Name: log_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.log_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: log_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.log_rowid_seq OWNED BY public.log.rowid;


--
-- Name: market_cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.market_cache (
    baseid character varying(128) NOT NULL,
    name text,
    description text,
    plugin text NOT NULL,
    enchantment integer,
    price numeric
);


--
-- Name: TABLE market_cache; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.market_cache IS 'Playthrough Manager Backed Up';


--
-- Name: memory; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.memory (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: memory_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.memory_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: memory_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.memory_rowid_seq OWNED BY public.memory.rowid;


--
-- Name: memory_summary; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.memory_summary (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: memory_summary_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.memory_summary_rowid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: memory_summary_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.memory_summary_rowid_seq OWNED BY public.memory_summary.rowid;


--
-- Name: memory_uid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.memory_uid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: memory_uid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.memory_uid_seq OWNED BY public.memory.uid;


--
-- Name: speech; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.speech (
    sess character varying(1024),
    speaker text,
    speech text,
    location text,
    listener text,
    topic text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL,
    companions text,
    audios text,
    mood text,
    emotion text,
    emotion_intensity text,
    utterance_id text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE speech; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.speech IS 'Playthrough Manager Backed Up';


--
-- Name: memory_v; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.memory_v AS
 SELECT subquery.message,
    subquery.uid,
    subquery.gamets,
    subquery.speaker,
    subquery.listener,
    subquery.ts
   FROM ( SELECT memory.message,
            memory.uid,
            memory.gamets,
            '-'::text AS speaker,
            '-'::text AS listener,
            memory.ts
           FROM public.memory
          WHERE ((memory.message !~~ 'Dear Diary%'::text) AND (memory.message <> ''::text) AND ((memory.event)::text <> 'backgroundlife_diary'::text))
        UNION
         SELECT ((((('(Context Location:'::text || speech.location) || ') '::text) || speech.speaker) || ': '::text) || speech.speech),
            (speech.rowid)::integer AS rowid,
            speech.gamets,
            speech.speaker,
            speech.listener,
            speech.ts
           FROM public.speech
          WHERE (speech.speech <> ''::text)
        UNION
         SELECT eventlog.data,
            (eventlog.rowid)::integer AS rowid,
            eventlog.gamets,
            '-'::text AS text,
            '-'::text AS listener,
            eventlog.ts
           FROM public.eventlog
          WHERE ((eventlog.type)::text = ANY (ARRAY[('death'::character varying)::text, ('location'::character varying)::text]))) subquery
  ORDER BY subquery.gamets, subquery.ts;


--
-- Name: speech_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.speech_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: speech_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.speech_rowid_seq OWNED BY public.speech.rowid;


--
-- Name: moods_issued; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.moods_issued (
    sess character varying(1024),
    speaker text,
    mood text,
    listener text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    ts bigint,
    rowid bigint DEFAULT nextval('public.speech_rowid_seq'::regclass) NOT NULL,
    emotion text,
    emotion_intensity text
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE moods_issued; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.moods_issued IS 'Playthrough Manager Backed Up';


--
-- Name: named_cell; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.named_cell (
    id bigint NOT NULL,
    cell_name text,
    location_id bigint,
    interior integer,
    dest_door_cell_id bigint,
    dest_door_exterior bigint,
    door_id bigint NOT NULL,
    vanilla_cell boolean,
    statics_list text,
    worldspace text,
    closed integer,
    door_name text,
    door_x numeric,
    door_y numeric,
    gamets bigint
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE named_cell; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.named_cell IS 'Playthrough Manager Backed Up';


--
-- Name: COLUMN named_cell.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.named_cell.id IS 'stable OpenMW cell compatibility id';


--
-- Name: COLUMN named_cell.location_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.named_cell.location_id IS 'associated OpenMW location compatibility id';


--
-- Name: COLUMN named_cell.dest_door_exterior; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.named_cell.dest_door_exterior IS '-1 unknown, -2 in-cell door, 1 exterior, 0 interior';


--
-- Name: npc_master_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.npc_master_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: npc_master_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.npc_master_id_seq OWNED BY public.core_npc_master.id;


--
-- Name: npc_profile_backup; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.npc_profile_backup (
    name text,
    data text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE npc_profile_backup; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.npc_profile_backup IS 'Playthrough Manager Backed Up';


--
-- Name: oghma; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oghma (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: oghma_context_rule; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oghma_context_rule (
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
    CONSTRAINT oghma_context_rule_max_articles_check CHECK (((max_articles >= 1) AND (max_articles <= 5))),
    CONSTRAINT oghma_context_rule_selector_type_check CHECK ((selector_type = ANY (ARRAY['topic'::text, 'tag'::text, 'category'::text])))
);


--
-- Name: TABLE oghma_context_rule; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.oghma_context_rule IS 'Playthrough Manager Backed Up';


--
-- Name: oghma_context_rule_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.oghma_context_rule_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: oghma_context_rule_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.oghma_context_rule_id_seq OWNED BY public.oghma_context_rule.id;


--
-- Name: oghma_dynamic; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.oghma_dynamic (
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
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: oghma_dynamic_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.oghma_dynamic_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: oghma_dynamic_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.oghma_dynamic_id_seq OWNED BY public.oghma_dynamic.id;


--
-- Name: physical_npc_diaries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.physical_npc_diaries (
    npc_name text NOT NULL,
    title text NOT NULL,
    last_diary_localts bigint DEFAULT 0 NOT NULL,
    created_at bigint DEFAULT 0 NOT NULL,
    updated_at bigint DEFAULT 0 NOT NULL
);


--
-- Name: TABLE physical_npc_diaries; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.physical_npc_diaries IS 'Playthrough Manager Backed Up';


--
-- Name: profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.profiles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.profiles_id_seq OWNED BY public.core_profiles.id;


--
-- Name: prompts; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.prompts (
    prompt_key character varying(128) NOT NULL,
    default_prompt text NOT NULL,
    custom_prompt text,
    description text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


--
-- Name: questlog; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.questlog (
    ts text,
    sess character varying(1024),
    id_quest character varying(1024),
    name text,
    editor_id text,
    giver_actor_id text,
    reward text,
    target_id text,
    is_unique boolean,
    mod text,
    stage integer,
    briefing text,
    briefing2 text,
    localts bigint,
    gamets bigint,
    data text,
    status text,
    rowid integer NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE questlog; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.questlog IS 'Playthrough Manager Backed Up';


--
-- Name: questlog_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.questlog_rowid_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: questlog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.questlog_rowid_seq OWNED BY public.questlog.rowid;


--
-- Name: quests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.quests (
    ts text NOT NULL,
    sess character varying(1024),
    id_quest character varying(1024) NOT NULL,
    name text,
    editor_id text,
    giver_actor_id text,
    reward text,
    target_id text,
    is_unique boolean,
    mod text,
    stage integer,
    briefing text,
    briefing2 text,
    localts bigint NOT NULL,
    gamets bigint NOT NULL,
    data text,
    status text,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE quests; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.quests IS 'Playthrough Manager Backed Up';


--
-- Name: quests_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.quests_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: quests_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.quests_rowid_seq OWNED BY public.quests.rowid;


--
-- Name: relationship_eval_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.relationship_eval_queue (
    id integer NOT NULL,
    npc_id integer NOT NULL,
    eval_data jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    retry_count integer DEFAULT 0
);


--
-- Name: relationship_eval_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.relationship_eval_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: relationship_eval_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.relationship_eval_queue_id_seq OWNED BY public.relationship_eval_queue.id;


--
-- Name: relationship_init_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.relationship_init_queue (
    id integer NOT NULL,
    npc_id integer NOT NULL,
    init_data jsonb NOT NULL,
    created_at timestamp without time zone DEFAULT now(),
    retry_count integer DEFAULT 0,
    last_error text
);


--
-- Name: relationship_init_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.relationship_init_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: relationship_init_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.relationship_init_queue_id_seq OWNED BY public.relationship_init_queue.id;


--
-- Name: responselog; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.responselog (
    localts bigint NOT NULL,
    sent bigint NOT NULL,
    actor text,
    text text,
    action text,
    tag character varying(256),
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: TABLE responselog; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.responselog IS 'Playthrough Manager Backed Up';


--
-- Name: responselog_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.responselog_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: responselog_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.responselog_rowid_seq OWNED BY public.responselog.rowid;


--
-- Name: rolemaster; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rolemaster (
    localts bigint NOT NULL,
    ttl bigint NOT NULL,
    type character varying(128),
    data text,
    rowid bigint NOT NULL
)
WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');


--
-- Name: rolemaster_rowid_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rolemaster_rowid_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rolemaster_rowid_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rolemaster_rowid_seq OWNED BY public.rolemaster.rowid;


--
-- Name: rumors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.rumors (
    id integer NOT NULL,
    gamets bigint,
    ts bigint,
    hold text,
    content text,
    type text,
    rumor_length_days integer
);


--
-- Name: TABLE rumors; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.rumors IS 'Playthrough Manager Backed Up';


--
-- Name: rumors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.rumors_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: rumors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.rumors_id_seq OWNED BY public.rumors.id;


--
-- Name: speech_view; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.speech_view AS
 SELECT s.sess,
    s.speaker,
    s.speech,
    s.location,
    s.listener,
    s.topic,
    s.localts,
    s.gamets,
    s.ts,
    s.rowid,
    s.companions,
    s.audios,
    s.mood,
    s.emotion,
    s.emotion_intensity,
    s.utterance_id,
    (to_timestamp((s.localts)::double precision) AT TIME ZONE 'UTC'::text) AS mw_local_datetime,
        CASE
            WHEN (s.ts IS NULL) THEN NULL::timestamp without time zone
            ELSE (to_timestamp((((s.ts)::numeric / 1000.0))::double precision) AT TIME ZONE 'UTC'::text)
        END AS mw_event_datetime
   FROM public.speech s;


--
-- Name: stt_connector_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.stt_connector_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: stt_connector_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.stt_connector_id_seq OWNED BY public.core_stt_connector.id;


--
-- Name: translations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.translations (
    source_word text NOT NULL,
    translated_word text,
    expansion text,
    sense text NOT NULL,
    id integer NOT NULL
);


--
-- Name: translations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.translations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: translations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.translations_id_seq OWNED BY public.translations.id;


--
-- Name: tts_connector_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.tts_connector_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: tts_connector_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.tts_connector_id_seq OWNED BY public.core_tts_connector.id;


--
-- Name: durable_job_attempts attempt_id; Type: DEFAULT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_attempts ALTER COLUMN attempt_id SET DEFAULT nextval('lorkhan_internal.durable_job_attempts_attempt_id_seq'::regclass);


--
-- Name: durable_job_dead_letters dead_letter_id; Type: DEFAULT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_dead_letters ALTER COLUMN dead_letter_id SET DEFAULT nextval('lorkhan_internal.durable_job_dead_letters_dead_letter_id_seq'::regclass);


--
-- Name: responselog rowid; Type: DEFAULT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog ALTER COLUMN rowid SET DEFAULT nextval('lorkhan_internal.responselog_rowid_seq'::regclass);


--
-- Name: speech rowid; Type: DEFAULT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech ALTER COLUMN rowid SET DEFAULT nextval('lorkhan_internal.speech_rowid_seq'::regclass);


--
-- Name: actions_issued rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actions_issued ALTER COLUMN rowid SET DEFAULT nextval('public.actions_issued_rowid_seq'::regclass);


--
-- Name: books rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.books ALTER COLUMN rowid SET DEFAULT nextval('public.books_rowid_seq'::regclass);


--
-- Name: core_action id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_action ALTER COLUMN id SET DEFAULT nextval('public.core_action_id_seq'::regclass);


--
-- Name: core_action_custom id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_action_custom ALTER COLUMN id SET DEFAULT nextval('public.core_action_custom_id_seq'::regclass);


--
-- Name: core_api_badge id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_api_badge ALTER COLUMN id SET DEFAULT nextval('public.api_badge_id_seq'::regclass);


--
-- Name: core_llm_connector id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_llm_connector ALTER COLUMN id SET DEFAULT nextval('public.llm_connector_id_seq'::regclass);


--
-- Name: core_npc_master id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_npc_master ALTER COLUMN id SET DEFAULT nextval('public.npc_master_id_seq'::regclass);


--
-- Name: core_npc_master_history history_id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_npc_master_history ALTER COLUMN history_id SET DEFAULT nextval('public.core_npc_master_history_history_id_seq'::regclass);


--
-- Name: core_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles ALTER COLUMN id SET DEFAULT nextval('public.profiles_id_seq'::regclass);


--
-- Name: core_stt_connector id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_stt_connector ALTER COLUMN id SET DEFAULT nextval('public.stt_connector_id_seq'::regclass);


--
-- Name: core_tts_connector id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_tts_connector ALTER COLUMN id SET DEFAULT nextval('public.tts_connector_id_seq'::regclass);


--
-- Name: currentmission rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currentmission ALTER COLUMN rowid SET DEFAULT nextval('public.currentmission_rowid_seq'::regclass);


--
-- Name: diarylog rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.diarylog ALTER COLUMN rowid SET DEFAULT nextval('public.diarylog_rowid_seq'::regclass);


--
-- Name: dynamic_bio id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dynamic_bio ALTER COLUMN id SET DEFAULT nextval('public.dynamic_bio_id_seq'::regclass);


--
-- Name: eventlog rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.eventlog ALTER COLUMN rowid SET DEFAULT nextval('public.eventlog_rowid_seq'::regclass);


--
-- Name: import_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_rules ALTER COLUMN id SET DEFAULT nextval('public.import_rules_id_seq'::regclass);


--
-- Name: log rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.log ALTER COLUMN rowid SET DEFAULT nextval('public.log_rowid_seq'::regclass);


--
-- Name: memory uid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memory ALTER COLUMN uid SET DEFAULT nextval('public.memory_uid_seq'::regclass);


--
-- Name: memory rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memory ALTER COLUMN rowid SET DEFAULT nextval('public.memory_rowid_seq'::regclass);


--
-- Name: memory_summary rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memory_summary ALTER COLUMN rowid SET DEFAULT nextval('public.memory_summary_rowid_seq'::regclass);


--
-- Name: oghma_context_rule id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oghma_context_rule ALTER COLUMN id SET DEFAULT nextval('public.oghma_context_rule_id_seq'::regclass);


--
-- Name: oghma_dynamic id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oghma_dynamic ALTER COLUMN id SET DEFAULT nextval('public.oghma_dynamic_id_seq'::regclass);


--
-- Name: questlog rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.questlog ALTER COLUMN rowid SET DEFAULT nextval('public.questlog_rowid_seq'::regclass);


--
-- Name: quests rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.quests ALTER COLUMN rowid SET DEFAULT nextval('public.quests_rowid_seq'::regclass);


--
-- Name: relationship_eval_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.relationship_eval_queue ALTER COLUMN id SET DEFAULT nextval('public.relationship_eval_queue_id_seq'::regclass);


--
-- Name: relationship_init_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.relationship_init_queue ALTER COLUMN id SET DEFAULT nextval('public.relationship_init_queue_id_seq'::regclass);


--
-- Name: responselog rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.responselog ALTER COLUMN rowid SET DEFAULT nextval('public.responselog_rowid_seq'::regclass);


--
-- Name: rolemaster rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rolemaster ALTER COLUMN rowid SET DEFAULT nextval('public.rolemaster_rowid_seq'::regclass);


--
-- Name: rumors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rumors ALTER COLUMN id SET DEFAULT nextval('public.rumors_id_seq'::regclass);


--
-- Name: speech rowid; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.speech ALTER COLUMN rowid SET DEFAULT nextval('public.speech_rowid_seq'::regclass);


--
-- Name: translations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.translations ALTER COLUMN id SET DEFAULT nextval('public.translations_id_seq'::regclass);


--
-- Data for Name: action_catalog; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--

INSERT INTO lorkhan_internal.action_catalog VALUES ('inspect.report', 0, 'Read-only client observation report.', '{"type": "object", "additionalProperties": false}', 'action.inspect.report', true, true, '2026-09-18 18:23:23.251644-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Inspect', 'Information', 10, 'none', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.follow', 1, 'Ask one actor to follow the player at the exact negotiated distance.', '{"type": "object", "required": ["distance"], "properties": {"distance": {"const": 192}}, "additionalProperties": false}', 'action.ai.follow', true, true, '2026-09-18 18:23:23.251701-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Follow', 'Movement', 140, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.stop', 1, 'Stop LORKHAN movement packages owned by this actor.', '{"type": "object", "additionalProperties": false}', 'action.ai.stop', true, true, '2026-09-18 18:23:23.351071-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Stop Moving', 'Movement', 150, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('combat.start', 2, 'Start combat with the selected target after explicit player confirmation.', '{"type": "object", "additionalProperties": false}', 'action.combat.start', true, true, '2026-09-18 18:23:23.351102-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Attack', 'Combat', 210, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('combat.stop', 1, 'Stop combat with the selected target.', '{"type": "object", "additionalProperties": false}', 'action.combat.stop', true, true, '2026-09-18 18:23:23.351104-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Stop Combat', 'Combat', 220, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('animation.play', 1, 'Play one allowlisted single-loop idle animation on the selected actor.', '{"type": "object", "required": ["group"], "properties": {"group": {"enum": ["idle2", "idle3", "idle4", "idle5", "idle6", "idle7", "idle8", "idle9"], "type": "string"}}, "additionalProperties": false}', 'action.animation.play', true, true, '2026-09-18 18:23:23.352683-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Perform Gesture', 'Animation', 410, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.travel', 1, 'Travel to a same-cell point explicitly aimed at and confirmed by the player.', '{"type": "object", "required": ["destination_x", "destination_y", "destination_z", "destination_cell"], "properties": {"destination_x": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_y": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_z": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_cell": {"type": "string", "maxLength": 300, "minLength": 1}}, "additionalProperties": false}', 'action.ai.travel', true, true, '2026-09-18 18:23:23.355765-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Travel To', 'Movement', 160, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('item.unequip', 2, 'Clear one explicit OpenMW equipment slot after player confirmation.', '{"type": "object", "required": ["slot"], "properties": {"slot": {"enum": ["helmet", "cuirass", "greaves", "left_pauldron", "right_pauldron", "left_gauntlet", "right_gauntlet", "boots", "shirt", "pants", "skirt", "robe", "left_ring", "right_ring", "amulet", "belt", "carried_right", "carried_left", "ammunition"], "type": "string"}}, "additionalProperties": false}', 'action.item.unequip', true, true, '2026-09-18 18:23:23.354289-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Unequip Item', 'Items', 330, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('conversation.end', 1, 'End the conversation, release LORKHAN-owned actor packages and temporarily stop accepting AI conversation.', '{"type": "object", "additionalProperties": false}', 'action.conversation.end', true, true, '2026-09-18 18:23:24.812495-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'End Conversation', 'Conversation', 30, 'none', false, false, 0, false, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.approach', 1, 'Travel to the current same-cell position of the addressed actor.', '{"type": "object", "additionalProperties": false}', 'action.ai.approach', true, true, '2026-09-18 18:23:24.437078-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Come Closer', 'Movement', 110, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.escort', 1, 'Escort the player to a same-cell point explicitly aimed at and confirmed by the player.', '{"type": "object", "required": ["destination_x", "destination_y", "destination_z", "destination_cell"], "properties": {"destination_x": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_y": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_z": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_cell": {"type": "string", "maxLength": 300, "minLength": 1}}, "additionalProperties": false}', 'action.ai.escort', true, true, '2026-09-18 18:23:23.355784-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Lead the Way', 'Movement', 120, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.face', 1, 'Turn to face the player or a player-confirmed nearby actor with bounded actor-local control.', '{"type": "object", "additionalProperties": false}', 'action.ai.face', true, true, '2026-09-18 18:23:23.357129-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Face Target', 'Movement', 130, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('inventory.inspect', 0, 'Read a bounded snapshot of the acting NPC inventory without moving items.', '{"type": "object", "additionalProperties": false}', 'action.inventory.inspect', true, true, '2026-09-18 18:23:24.437051-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Check Inventory', 'Information', 20, 'none', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.wait', 1, 'Wait in place for a bounded duration using an owned non-repeating Wander package.', '{"type": "object", "required": ["duration_seconds"], "properties": {"duration_seconds": {"type": "integer", "maximum": 86400, "minimum": 3600, "multipleOf": 3600}}, "additionalProperties": false}', 'action.ai.wait', true, true, '2026-09-18 18:23:24.437081-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Wait Here', 'Movement', 170, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('ai.wander', 1, 'Ask an actor to wander for a bounded distance and duration.', '{"type": "object", "required": ["distance", "duration_seconds"], "properties": {"distance": {"type": "integer", "maximum": 2048, "minimum": 0}, "duration_seconds": {"type": "integer", "maximum": 86400, "minimum": 3600, "multipleOf": 3600}}, "additionalProperties": false}', 'action.ai.wander', true, true, '2026-09-18 18:23:23.351099-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Wander', 'Movement', 180, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('weapon.sheathe', 1, 'Sheathe the acting NPC weapon or lower readied magic without removing equipment or changing AI packages. A committed attack or spell can prevent sheathing.', '{"type": "object", "additionalProperties": false}', 'action.weapon.sheathe', true, true, '2026-09-18 18:23:24.888851-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Sheathe Weapon', 'Combat', 230, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('item.use', 2, 'Use one existing inventory item after explicit player confirmation.', '{"type": "object", "required": ["record_id", "content_file"], "properties": {"record_id": {"type": "string", "maxLength": 128, "minLength": 1}, "content_file": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', 'action.item.use', true, true, '2026-09-18 18:23:23.352704-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Use Item', 'Items', 310, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('item.give', 2, 'Give an exact observed inventory item instance to the target actor. Never guess item IDs.', '{"type": "object", "required": ["item_id", "count"], "properties": {"count": {"type": "integer", "maximum": 1000, "minimum": 1}, "item_id": {"type": "string", "pattern": "^@?0x[0-9a-f]{1,16}$", "maxLength": 19}}, "additionalProperties": false}', 'action.item.give', true, true, '2026-09-18 18:23:24.890514-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Give Item', 'Items', 340, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('service.barter', 1, 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '{"type": "object", "additionalProperties": false}', 'action.service.barter', true, true, '2026-09-18 18:23:24.911803-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Barter', 'Services', 301, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('service.training', 1, 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '{"type": "object", "additionalProperties": false}', 'action.service.training', true, true, '2026-09-18 18:23:24.91183-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Training', 'Services', 302, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('service.spells', 1, 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '{"type": "object", "additionalProperties": false}', 'action.service.spells', true, true, '2026-09-18 18:23:24.911852-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Buy Spells', 'Services', 303, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('service.travel', 1, 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '{"type": "object", "additionalProperties": false}', 'action.service.travel', true, true, '2026-09-18 18:23:24.911853-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Travel', 'Services', 304, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('service.spellmaking', 1, 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '{"type": "object", "additionalProperties": false}', 'action.service.spellmaking', true, true, '2026-09-18 18:23:24.911855-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Spellmaking', 'Services', 305, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('service.enchanting', 1, 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '{"type": "object", "additionalProperties": false}', 'action.service.enchanting', true, true, '2026-09-18 18:23:24.911856-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Enchanting', 'Services', 306, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('service.repair', 1, 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '{"type": "object", "additionalProperties": false}', 'action.service.repair', true, true, '2026-09-18 18:23:24.911857-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Repair', 'Services', 307, 'optional', false, true, 0, true, false);
INSERT INTO lorkhan_internal.action_catalog VALUES ('item.pickup', 2, 'Pick up the entire exact observed ground item stack. Ownership and crime rules still apply.', '{"type": "object", "required": ["item_id"], "properties": {"item_id": {"type": "string", "pattern": "^@?0x[0-9a-f]{1,16}$", "maxLength": 19}}, "additionalProperties": false}', 'action.item.pickup', true, true, '2026-09-18 18:23:24.890541-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Pick Up Item', 'Items', 360, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('item.equip', 2, 'Equip one existing inventory item into an explicit OpenMW equipment slot after player confirmation.', '{"type": "object", "required": ["record_id", "content_file", "slot"], "properties": {"slot": {"enum": ["helmet", "cuirass", "greaves", "left_pauldron", "right_pauldron", "left_gauntlet", "right_gauntlet", "boots", "shirt", "pants", "skirt", "robe", "left_ring", "right_ring", "amulet", "belt", "carried_right", "carried_left", "ammunition"], "type": "string"}, "record_id": {"type": "string", "maxLength": 128, "minLength": 1}, "content_file": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', 'action.item.equip', true, true, '2026-09-18 18:23:23.354269-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Equip Item', 'Items', 320, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('item.take', 2, 'Take an exact observed item from the player inventory after explicit approval.', '{"type": "object", "required": ["item_id", "count"], "properties": {"count": {"type": "integer", "maximum": 1000, "minimum": 1}, "item_id": {"type": "string", "pattern": "^@?0x[0-9a-f]{1,16}$", "maxLength": 19}}, "additionalProperties": false}', 'action.item.take', true, true, '2026-09-18 18:23:24.890539-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Take Offered Item', 'Items', 350, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('gold.give', 2, 'Give existing observed inventory gold to the target actor. Does not create gold.', '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "maximum": 100000, "minimum": 1}}, "additionalProperties": false}', 'action.gold.give', true, true, '2026-09-18 18:23:24.890543-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Give Gold', 'Items', 370, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('gold.take', 2, 'Take existing observed player gold after explicit approval. Does not create gold.', '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "maximum": 100000, "minimum": 1}}, "additionalProperties": false}', 'action.gold.take', true, true, '2026-09-18 18:23:24.890545-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Take Gold', 'Items', 380, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('spell.cast', 2, 'Cast an observed known spell or power with normal animation, resource and success checks. Success records a cast, not a guaranteed hit.', '{"type": "object", "required": ["spell_id"], "properties": {"spell_id": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', 'action.spell.cast', true, true, '2026-09-18 18:23:24.913676-07', '{"type": "object", "additionalProperties": false}', 16384, true, true, 'Cast Spell', 'Combat', 240, 'optional', false, true, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('item.create', 2, 'Create Item using an observed target or loaded record. Explicit Cheat/Narrator request required.', '{"type": "object", "required": ["record_id", "count"], "properties": {"count": {"type": "integer", "maximum": 100, "minimum": 1}, "record_id": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', 'action.item.create', true, true, '2026-09-18 18:23:24.917637-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Create Item', 'World Actions', 300, 'optional', false, false, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('gold.create', 2, 'Create Gold using an observed target or loaded record. Explicit Cheat/Narrator request required.', '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "maximum": 100000, "minimum": 1}}, "additionalProperties": false}', 'action.gold.create', true, true, '2026-09-18 18:23:24.91767-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Create Gold', 'World Actions', 301, 'optional', false, false, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('actor.spawn', 2, 'Spawn Actor using an observed target or loaded record. Explicit Cheat/Narrator request required. May break quests.', '{"type": "object", "required": ["record_id", "count"], "properties": {"count": {"type": "integer", "maximum": 4, "minimum": 1}, "record_id": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', 'action.actor.spawn', true, true, '2026-09-18 18:23:24.917673-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Spawn Actor', 'World Actions', 302, 'optional', false, false, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('actor.teleport_to_player', 2, 'Teleport Actor to Player using an observed target or loaded record. Explicit Cheat/Narrator request required.', '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', 'action.actor.teleport_to_player', true, true, '2026-09-18 18:23:24.917675-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Teleport Actor to Player', 'World Actions', 303, 'optional', false, false, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('player.teleport', 2, 'Teleport Player using an observed target or loaded record. Explicit Cheat/Narrator request required.', '{"type": "object", "required": ["destination_id"], "properties": {"destination_id": {"type": "string", "maxLength": 128, "minLength": 1}}, "additionalProperties": false}', 'action.player.teleport', true, true, '2026-09-18 18:23:24.917677-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Teleport Player', 'World Actions', 304, 'optional', false, false, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('actor.restore', 2, 'Restore Actor using an observed target or loaded record. Explicit Cheat/Narrator request required.', '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', 'action.actor.restore', true, true, '2026-09-18 18:23:24.917678-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Restore Actor', 'World Actions', 305, 'optional', false, false, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('actor.resurrect', 2, 'Resurrect Actor using an observed target or loaded record. Explicit Cheat/Narrator request required. May break quests.', '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', 'action.actor.resurrect', true, true, '2026-09-18 18:23:24.917695-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Resurrect Actor', 'World Actions', 306, 'optional', false, false, 0, true, true);
INSERT INTO lorkhan_internal.action_catalog VALUES ('actor.kill', 2, 'Kill Actor using an observed target or loaded record. Explicit Cheat/Narrator request required. May break quests.', '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', 'action.actor.kill', true, true, '2026-09-18 18:23:24.917697-07', '{"type": "object", "additionalProperties": false}', 16384, true, false, 'Kill Actor', 'World Actions', 307, 'optional', false, false, 0, true, true);


--
-- Data for Name: action_catalog_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--

INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (8, 'combat.start', 2, 'action.combat.start');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (12, 'item.unequip', 2, 'action.item.unequip');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (13, 'item.use', 2, 'action.item.use');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (11, 'item.equip', 2, 'action.item.equip');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (19, 'item.give', 2, 'action.item.give');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (20, 'item.take', 2, 'action.item.take');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (21, 'item.pickup', 2, 'action.item.pickup');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (22, 'gold.give', 2, 'action.gold.give');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (23, 'gold.take', 2, 'action.gold.take');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (31, 'spell.cast', 2, 'action.spell.cast');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (32, 'item.create', 2, 'action.item.create');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (33, 'gold.create', 2, 'action.gold.create');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (34, 'actor.spawn', 2, 'action.actor.spawn');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (35, 'actor.teleport_to_player', 2, 'action.actor.teleport_to_player');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (36, 'player.teleport', 2, 'action.player.teleport');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (37, 'actor.restore', 2, 'action.actor.restore');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (38, 'actor.resurrect', 2, 'action.actor.resurrect');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (17, 'conversation.end', 1, 'action.conversation.end');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (10, 'inspect.report', 0, 'action.inspect.report');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (3, 'ai.follow', 1, 'action.ai.follow');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (4, 'ai.stop', 1, 'action.ai.stop');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (9, 'combat.stop', 1, 'action.combat.stop');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (7, 'animation.play', 1, 'action.animation.play');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (5, 'ai.travel', 1, 'action.ai.travel');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (1, 'ai.escort', 1, 'action.ai.escort');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (2, 'ai.face', 1, 'action.ai.face');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (14, 'inventory.inspect', 0, 'action.inventory.inspect');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (15, 'ai.approach', 1, 'action.ai.approach');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (16, 'ai.wait', 1, 'action.ai.wait');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (6, 'ai.wander', 1, 'action.ai.wander');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (18, 'weapon.sheathe', 1, 'action.weapon.sheathe');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (24, 'service.barter', 1, 'action.service.barter');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (25, 'service.training', 1, 'action.service.training');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (26, 'service.spells', 1, 'action.service.spells');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (27, 'service.travel', 1, 'action.service.travel');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (28, 'service.spellmaking', 1, 'action.service.spellmaking');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (29, 'service.enchanting', 1, 'action.service.enchanting');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (30, 'service.repair', 1, 'action.service.repair');
INSERT INTO lorkhan_internal.action_catalog_metadata VALUES (39, 'actor.kill', 2, 'action.actor.kill');


--
-- Data for Name: action_delivery; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: action_intents; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: action_issued_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: action_results; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: actor_profile_bindings; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: audit_request_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: backup_records; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: biography_catalog_entries; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: biography_catalogs; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: book_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: browser_sessions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: character_playthrough_bindings; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: configuration_revisions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: configuration_sets; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: content_manifest_files; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: content_manifests; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: core_profile_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: core_profile_presets; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: core_profile_revisions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: core_profiles; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: core_tts_pronunciation; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--

INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (51, 'Morrowind', 'Moreohwind', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (52, 'Vvardenfell', 'Vardenfell', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (53, 'Nerevarine', 'Nerevareen', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (54, 'Dagoth Ur', 'Dagoth Oor', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (55, 'Lorkhan', 'Lorkahn', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (56, 'Chimer', 'Kymer', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (57, 'Dwemer', 'Dwehmur', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (58, 'Bosmer', 'Bozmer', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (59, 'Khajiit', 'Kahjeet', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (60, 'Hlaalu', 'Hlaloo', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (61, 'Telvanni', 'Telvahnee', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (62, 'Almalexia', 'Almalexeeah', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (63, 'Sotha Sil', 'Sotha Seel', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (64, 'Vivec', 'Vihveck', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (65, 'Daedra', 'Daydra', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (66, 'Daedric', 'Daydrick', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (67, 'Tribunal', 'Trybyoonal', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (68, 'Resdayn', 'Rezdayn', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (69, 'Hortator', 'Hortahter', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (70, 'Balmora', 'Balmorah', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (71, 'Seyda Neen', 'Sayda Neen', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (72, 'Ald''ruhn', 'Auldroon', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (73, 'Gnisis', 'Neesis', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (74, 'Molag Mar', 'Mohlahg Mar', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (75, 'Maar Gan', 'Mar Gahn', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (76, 'Pelagiad', 'Pellajeead', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (77, 'Suran', 'Soorahn', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (78, 'Sadrith Mora', 'Sadrith Morah', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (79, 'Dagon Fel', 'Daygon Fell', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (80, 'Akulakhan', 'Akoolakahn', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (81, 'Numidium', 'Newmidium', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (82, 'Corprus', 'Korprus', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (83, 'Kwama', 'Kwamah', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (84, 'Guar', 'Gwar', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (85, 'N''wah', 'Enwah', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (86, 'S''wit', 'Swit', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (87, 'Caius Cosades', 'Kyus Cosahdees', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (88, 'Divayth Fyr', 'Divayth Fear', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (89, 'Yagrum Bagarn', 'Yahgrum Bagarn', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (90, 'Urshilaku', 'Urshilakoo', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (91, 'Ashkhan', 'Ashkahn', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (92, 'Velothi', 'Vellothee', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (93, 'Kogoruhn', 'Kogoroon', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (94, 'Muatra', 'Mooahtra', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (95, 'Kagrenac', 'Kagrenack', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');
INSERT INTO lorkhan_internal.core_tts_pronunciation VALUES (96, 'Sheogorad', 'Sheeogorad', '', '', '', true, true, '2026-09-18 18:23:24.72708-07', '2026-09-18 18:23:24.72708-07');


--
-- Data for Name: currentmission_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: database_backup_settings; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--

INSERT INTO lorkhan_internal.database_backup_settings VALUES (true, false, 5, NULL);


--
-- Data for Name: database_snapshot_source; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--

INSERT INTO lorkhan_internal.database_snapshot_source VALUES (true, NULL, NULL, NULL);


--
-- Data for Name: debug_commands; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: description_catalog_entries; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: description_catalogs; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: description_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: dialogue_delivery_results; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: dialogue_utterances; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: diarylog_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: director_instructions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: director_plans; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: discovered_items; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: disposition_adjustments; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: durable_job_attempts; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: durable_job_dead_letters; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: durable_jobs; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: eventlog_hidden_types; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: eventlog_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: faction_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: game_dispositions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: game_plugin_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: general_setting_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: global_settings_presets; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: idempotency_requests; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: installation_profile_preferences; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: installation_provider_selections; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: installations; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: interruptions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: item_descriptions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: knowledge_documents; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: llm_connector_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: location_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: log_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: media_objects; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: memory_embeddings; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: memory_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: memory_model_summaries; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: memory_record_revisions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: memory_records; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: memory_summary_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: menu_dialogue_tts_requests; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: narrative_records; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: npc_evolution_reports; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: npc_memory_digests; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: npc_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: npc_reference_groups; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_catalog_deletions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_catalog_entries; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_catalogs; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_dynamic; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_dynamic_applications; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_factory_documents; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_installation_settings; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: oghma_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: operational_audit; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: pairing_tokens; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: physical_diary_deliveries; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: player2_routing; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: player_speech_style_drafts; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: playthrough_associations; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: playthrough_local_state; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: playthrough_revisions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: playthrough_saves; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: playthroughs; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: profile_assignment_rules; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: profile_evolution_clocks; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: profile_evolution_events; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: profile_evolution_progress; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: profile_revisions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: profiles; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: prompt_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: prompt_trace_sections; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: prompt_trace_sources; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: prompt_traces; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: prompts; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: provider_attempts; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: quest_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: questlog_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: quickstart_local_llm; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: rate_limit_buckets; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: rechat_chains; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: relationship_audit; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: relationship_build_results; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: relationship_conversion_results; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: relationship_evaluation_results; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: relationship_records; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: relationship_revisions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: request_log_hidden; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: request_mac_nonces; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: response_events; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: responselog; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: responselog_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: retrieval_traces; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: scene_classifications; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: sessions; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: source_events; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: speech; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: speech_connector_voices; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: speech_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: stt_requests; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: timeline_invalidated_sources; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: timeline_invalidated_turns; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: tts_connector_metadata; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: turn_provider_snapshots; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: turns; Type: TABLE DATA; Schema: lorkhan_internal; Owner: -
--



--
-- Data for Name: actions_issued; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: animations; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: animations_custom; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: audit_memory; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: audit_request; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: bio_templates; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: bio_templates_custom; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: books; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: conf_opts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_action; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_action_custom; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.core_action_custom VALUES (10, 'inspect.report', 'inspect.report', 'Read-only client observation report.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 0, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.inspect.report", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (4, 'ai.stop', 'ai.stop', 'Stop LORKHAN movement packages owned by this actor.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.stop", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (12, 'item.unequip', 'item.unequip', 'Clear one explicit OpenMW equipment slot after player confirmation.', '', true, false, false, true, '{"type": "object", "required": ["slot"], "properties": {"slot": {"enum": ["helmet", "cuirass", "greaves", "left_pauldron", "right_pauldron", "left_gauntlet", "right_gauntlet", "boots", "shirt", "pants", "skirt", "robe", "left_ring", "right_ring", "amulet", "belt", "carried_right", "carried_left", "ammunition"], "type": "string"}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.item.unequip", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (3, 'ai.follow', 'ai.follow', 'Ask one actor to follow the player at the exact negotiated distance.', '', true, false, false, true, '{"type": "object", "required": ["distance"], "properties": {"distance": {"const": 192}}, "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.follow", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (9, 'combat.stop', 'combat.stop', 'Stop combat with the selected target.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.combat.stop", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (7, 'animation.play', 'animation.play', 'Play one allowlisted single-loop idle animation on the selected actor.', '', true, false, false, true, '{"type": "object", "required": ["group"], "properties": {"group": {"enum": ["idle2", "idle3", "idle4", "idle5", "idle6", "idle7", "idle8", "idle9"], "type": "string"}}, "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.animation.play", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (8, 'combat.start', 'combat.start', 'Start combat with the selected target after explicit player confirmation.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.combat.start", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (5, 'ai.travel', 'ai.travel', 'Travel to a same-cell point explicitly aimed at and confirmed by the player.', '', true, false, false, true, '{"type": "object", "required": ["destination_x", "destination_y", "destination_z", "destination_cell"], "properties": {"destination_x": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_y": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_z": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_cell": {"type": "string", "maxLength": 300, "minLength": 1}}, "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.travel", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (1, 'ai.escort', 'ai.escort', 'Escort the player to a same-cell point explicitly aimed at and confirmed by the player.', '', true, false, false, true, '{"type": "object", "required": ["destination_x", "destination_y", "destination_z", "destination_cell"], "properties": {"destination_x": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_y": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_z": {"type": "number", "maximum": 100000000, "minimum": -100000000}, "destination_cell": {"type": "string", "maxLength": 300, "minLength": 1}}, "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.escort", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (2, 'ai.face', 'ai.face', 'Turn to face the player or a player-confirmed nearby actor with bounded actor-local control.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.face", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (14, 'inventory.inspect', 'inventory.inspect', 'Read a bounded snapshot of the acting NPC inventory without moving items.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 0, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.inventory.inspect", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.436803', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (15, 'ai.approach', 'ai.approach', 'Travel to the current same-cell position of the addressed actor.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.approach", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.436803', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (17, 'conversation.end', 'conversation.end', 'End the conversation, release LORKHAN-owned actor packages and temporarily stop accepting AI conversation.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.conversation.end", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.812384', '2026-09-18 18:23:24.812384');
INSERT INTO public.core_action_custom VALUES (16, 'ai.wait', 'ai.wait', 'Wait in place for a bounded duration using an owned non-repeating Wander package.', '', true, false, false, true, '{"type": "object", "required": ["duration_seconds"], "properties": {"duration_seconds": {"type": "integer", "maximum": 86400, "minimum": 3600, "multipleOf": 3600}}, "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.wait", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.436803', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (6, 'ai.wander', 'ai.wander', 'Ask an actor to wander for a bounded distance and duration.', '', true, false, false, true, '{"type": "object", "required": ["distance", "duration_seconds"], "properties": {"distance": {"type": "integer", "maximum": 2048, "minimum": 0}, "duration_seconds": {"type": "integer", "maximum": 86400, "minimum": 3600, "multipleOf": 3600}}, "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.ai.wander", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (18, 'weapon.sheathe', 'weapon.sheathe', 'Sheathe the acting NPC weapon or lower readied magic without removing equipment or changing AI packages. A committed attack or spell can prevent sheathing.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.weapon.sheathe", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.888735', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (13, 'item.use', 'item.use', 'Use one existing inventory item after explicit player confirmation.', '', true, false, false, true, '{"type": "object", "required": ["record_id", "content_file"], "properties": {"record_id": {"type": "string", "maxLength": 128, "minLength": 1}, "content_file": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.item.use", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (11, 'item.equip', 'item.equip', 'Equip one existing inventory item into an explicit OpenMW equipment slot after player confirmation.', '', true, false, false, true, '{"type": "object", "required": ["record_id", "content_file", "slot"], "properties": {"slot": {"enum": ["helmet", "cuirass", "greaves", "left_pauldron", "right_pauldron", "left_gauntlet", "right_gauntlet", "boots", "shirt", "pants", "skirt", "robe", "left_ring", "right_ring", "amulet", "belt", "carried_right", "carried_left", "ammunition"], "type": "string"}, "record_id": {"type": "string", "maxLength": 128, "minLength": 1}, "content_file": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.item.equip", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.003081', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (24, 'service.barter', 'service.barter', 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.service.barter", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.911624', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (25, 'service.training', 'service.training', 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.service.training", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.911624', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (26, 'service.spells', 'service.spells', 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.service.spells", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.911624', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (27, 'service.travel', 'service.travel', 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.service.travel", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.911624', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (28, 'service.spellmaking', 'service.spellmaking', 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.service.spellmaking", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.911624', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (29, 'service.enchanting', 'service.enchanting', 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.service.enchanting", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.911624', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (30, 'service.repair', 'service.repair', 'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.', '', true, false, false, true, '{"type": "object", "additionalProperties": false}', '{"tier": 1, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.service.repair", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.911624', '2026-09-18 18:23:24.915226');
INSERT INTO public.core_action_custom VALUES (19, 'item.give', 'item.give', 'Give an exact observed inventory item instance to the target actor. Never guess item IDs.', '', true, false, false, true, '{"type": "object", "required": ["item_id", "count"], "properties": {"count": {"type": "integer", "maximum": 1000, "minimum": 1}, "item_id": {"type": "string", "pattern": "^@?0x[0-9a-f]{1,16}$", "maxLength": 19}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.item.give", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.890394', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (20, 'item.take', 'item.take', 'Take an exact observed item from the player inventory after explicit approval.', '', true, false, false, true, '{"type": "object", "required": ["item_id", "count"], "properties": {"count": {"type": "integer", "maximum": 1000, "minimum": 1}, "item_id": {"type": "string", "pattern": "^@?0x[0-9a-f]{1,16}$", "maxLength": 19}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.item.take", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.890394', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (21, 'item.pickup', 'item.pickup', 'Pick up the entire exact observed ground item stack. Ownership and crime rules still apply.', '', true, false, false, true, '{"type": "object", "required": ["item_id"], "properties": {"item_id": {"type": "string", "pattern": "^@?0x[0-9a-f]{1,16}$", "maxLength": 19}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.item.pickup", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.890394', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (22, 'gold.give', 'gold.give', 'Give existing observed inventory gold to the target actor. Does not create gold.', '', true, false, false, true, '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "maximum": 100000, "minimum": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.gold.give", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.890394', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (23, 'gold.take', 'gold.take', 'Take existing observed player gold after explicit approval. Does not create gold.', '', true, false, false, true, '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "maximum": 100000, "minimum": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.gold.take", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.890394', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (31, 'spell.cast', 'spell.cast', 'Cast an observed known spell or power with normal animation, resource and success checks. Success records a cast, not a guaranteed hit.', '', true, false, false, true, '{"type": "object", "required": ["spell_id"], "properties": {"spell_id": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.spell.cast", "max_parameter_bytes": 16384, "continuation_capable": true, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.913462', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (32, 'item.create', 'item.create', 'Create Item using an observed target or loaded record. Explicit Cheat/Narrator request required.', '', true, false, false, true, '{"type": "object", "required": ["record_id", "count"], "properties": {"count": {"type": "integer", "maximum": 100, "minimum": 1}, "record_id": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.item.create", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (33, 'gold.create', 'gold.create', 'Create Gold using an observed target or loaded record. Explicit Cheat/Narrator request required.', '', true, false, false, true, '{"type": "object", "required": ["amount"], "properties": {"amount": {"type": "integer", "maximum": 100000, "minimum": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.gold.create", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (34, 'actor.spawn', 'actor.spawn', 'Spawn Actor using an observed target or loaded record. Explicit Cheat/Narrator request required. May break quests.', '', true, false, false, true, '{"type": "object", "required": ["record_id", "count"], "properties": {"count": {"type": "integer", "maximum": 4, "minimum": 1}, "record_id": {"type": "string", "maxLength": 256, "minLength": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.actor.spawn", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (35, 'actor.teleport_to_player', 'actor.teleport_to_player', 'Teleport Actor to Player using an observed target or loaded record. Explicit Cheat/Narrator request required.', '', true, false, false, true, '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.actor.teleport_to_player", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (36, 'player.teleport', 'player.teleport', 'Teleport Player using an observed target or loaded record. Explicit Cheat/Narrator request required.', '', true, false, false, true, '{"type": "object", "required": ["destination_id"], "properties": {"destination_id": {"type": "string", "maxLength": 128, "minLength": 1}}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.player.teleport", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (37, 'actor.restore', 'actor.restore', 'Restore Actor using an observed target or loaded record. Explicit Cheat/Narrator request required.', '', true, false, false, true, '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.actor.restore", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (38, 'actor.resurrect', 'actor.resurrect', 'Resurrect Actor using an observed target or loaded record. Explicit Cheat/Narrator request required. May break quests.', '', true, false, false, true, '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.actor.resurrect", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');
INSERT INTO public.core_action_custom VALUES (39, 'actor.kill', 'actor.kill', 'Kill Actor using an observed target or loaded record. Explicit Cheat/Narrator request required. May break quests.', '', true, false, false, true, '{"type": "object", "required": [], "properties": {}, "additionalProperties": false}', '{"tier": 2, "result_schema": {"type": "object", "additionalProperties": false}, "client_capability": "action.actor.kill", "max_parameter_bytes": 16384, "continuation_capable": false, "terminal_result_required": true}', true, 1, NULL, '2026-09-18 18:23:24.917465', '2026-09-18 18:23:25.287068');


--
-- Data for Name: core_api_badge; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_llm_connector; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_narrator; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_npc_master; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_npc_master_history; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_player; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_profiles; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_stt_connector; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_tts_connector; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: core_tts_fallback; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.core_tts_fallback VALUES (1, 'argonian', 'male', 'mw_argonian_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (2, 'argonian', 'female', 'mw_argonian_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (3, 'breton', 'male', 'mw_breton_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (4, 'breton', 'female', 'mw_breton_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (5, 'dark_elf', 'male', 'mw_dark_elf_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (6, 'dark_elf', 'female', 'mw_dark_elf_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (7, 'high_elf', 'male', 'mw_high_elf_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (8, 'high_elf', 'female', 'mw_high_elf_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (9, 'imperial', 'male', 'mw_imperial_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (10, 'imperial', 'female', 'mw_imperial_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (11, 'khajiit', 'male', 'mw_khajiit_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (12, 'khajiit', 'female', 'mw_khajiit_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (13, 'nord', 'male', 'mw_nord_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (14, 'nord', 'female', 'mw_nord_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (15, 'orc', 'male', 'mw_orc_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (16, 'orc', 'female', 'mw_orc_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (17, 'redguard', 'male', 'mw_redguard_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (18, 'redguard', 'female', 'mw_redguard_female', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (19, 'wood_elf', 'male', 'mw_wood_elf_male', '2026-09-18 18:23:24.730406');
INSERT INTO public.core_tts_fallback VALUES (20, 'wood_elf', 'female', 'mw_wood_elf_female', '2026-09-18 18:23:24.730406');


--
-- Data for Name: currentmission; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: database_versioning; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.database_versioning VALUES ('LorkhanServer', 26);


--
-- Data for Name: descriptions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: descriptions_custom; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: diarylog; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: dynamic_bio; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: eventlog; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: faction_vanilla; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: factions; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: game_plugins; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: general_settings; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: import_rules; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: json_personalities; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: locations; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: log; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: market_cache; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: memory; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: memory_summary; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: moods_issued; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: named_cell; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: npc_profile_backup; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: oghma; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: oghma_context_rule; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: oghma_dynamic; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: physical_npc_diaries; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: prompts; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: questlog; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: quests; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: relationship_eval_queue; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: relationship_init_queue; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: responselog; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: rolemaster; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: rumors; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: speech; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Data for Name: translations; Type: TABLE DATA; Schema: public; Owner: -
--



--
-- Name: core_tts_pronunciation_id_seq; Type: SEQUENCE SET; Schema: lorkhan_internal; Owner: -
--

SELECT pg_catalog.setval('lorkhan_internal.core_tts_pronunciation_id_seq', 96, true);


--
-- Name: durable_job_attempts_attempt_id_seq; Type: SEQUENCE SET; Schema: lorkhan_internal; Owner: -
--

SELECT pg_catalog.setval('lorkhan_internal.durable_job_attempts_attempt_id_seq', 1, false);


--
-- Name: durable_job_dead_letters_dead_letter_id_seq; Type: SEQUENCE SET; Schema: lorkhan_internal; Owner: -
--

SELECT pg_catalog.setval('lorkhan_internal.durable_job_dead_letters_dead_letter_id_seq', 1, false);


--
-- Name: relationship_audit_audit_sequence_seq; Type: SEQUENCE SET; Schema: lorkhan_internal; Owner: -
--

SELECT pg_catalog.setval('lorkhan_internal.relationship_audit_audit_sequence_seq', 1, false);


--
-- Name: responselog_rowid_seq; Type: SEQUENCE SET; Schema: lorkhan_internal; Owner: -
--

SELECT pg_catalog.setval('lorkhan_internal.responselog_rowid_seq', 1, false);


--
-- Name: speech_rowid_seq; Type: SEQUENCE SET; Schema: lorkhan_internal; Owner: -
--

SELECT pg_catalog.setval('lorkhan_internal.speech_rowid_seq', 1, false);


--
-- Name: actions_issued_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.actions_issued_rowid_seq', 1, false);


--
-- Name: api_badge_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.api_badge_id_seq', 1, false);


--
-- Name: audit_request_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.audit_request_rowid_seq', 1, false);


--
-- Name: books_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.books_rowid_seq', 1, false);


--
-- Name: core_action_custom_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.core_action_custom_id_seq', 39, true);


--
-- Name: core_action_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.core_action_id_seq', 1, false);


--
-- Name: core_npc_master_history_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.core_npc_master_history_history_id_seq', 1, false);


--
-- Name: core_tts_fallback_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.core_tts_fallback_id_seq', 20, true);


--
-- Name: currentmission_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.currentmission_rowid_seq', 1, false);


--
-- Name: diarylog_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.diarylog_rowid_seq', 1, false);


--
-- Name: dynamic_bio_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.dynamic_bio_id_seq', 1, false);


--
-- Name: eventlog_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.eventlog_rowid_seq', 1, false);


--
-- Name: import_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.import_rules_id_seq', 1, false);


--
-- Name: llm_connector_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.llm_connector_id_seq', 1, false);


--
-- Name: log_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.log_rowid_seq', 1, false);


--
-- Name: memory_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.memory_rowid_seq', 1, false);


--
-- Name: memory_summary_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.memory_summary_rowid_seq', 1, false);


--
-- Name: memory_uid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.memory_uid_seq', 1, false);


--
-- Name: npc_master_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.npc_master_id_seq', 1, false);


--
-- Name: oghma_context_rule_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.oghma_context_rule_id_seq', 1, false);


--
-- Name: oghma_dynamic_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.oghma_dynamic_id_seq', 1, false);


--
-- Name: profiles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.profiles_id_seq', 1, false);


--
-- Name: questlog_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.questlog_rowid_seq', 1, false);


--
-- Name: quests_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.quests_rowid_seq', 1, false);


--
-- Name: relationship_eval_queue_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.relationship_eval_queue_id_seq', 1, false);


--
-- Name: relationship_init_queue_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.relationship_init_queue_id_seq', 1, false);


--
-- Name: responselog_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.responselog_rowid_seq', 1, false);


--
-- Name: rolemaster_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.rolemaster_rowid_seq', 1, false);


--
-- Name: rumors_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.rumors_id_seq', 1, false);


--
-- Name: speech_rowid_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.speech_rowid_seq', 1, false);


--
-- Name: stt_connector_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.stt_connector_id_seq', 1, false);


--
-- Name: translations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.translations_id_seq', 1, false);


--
-- Name: tts_connector_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.tts_connector_id_seq', 1, false);


--
-- Name: action_catalog_metadata action_catalog_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_catalog_metadata
    ADD CONSTRAINT action_catalog_metadata_pkey PRIMARY KEY (action_id);


--
-- Name: action_catalog_metadata action_catalog_metadata_source_action_name_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_catalog_metadata
    ADD CONSTRAINT action_catalog_metadata_source_action_name_key UNIQUE (source_action_name);


--
-- Name: action_catalog action_catalog_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_catalog
    ADD CONSTRAINT action_catalog_pkey PRIMARY KEY (action_name);


--
-- Name: action_delivery action_delivery_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_delivery
    ADD CONSTRAINT action_delivery_pkey PRIMARY KEY (action_id);


--
-- Name: action_intents action_intents_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_intents
    ADD CONSTRAINT action_intents_pkey PRIMARY KEY (action_id);


--
-- Name: action_issued_metadata action_issued_metadata_action_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_issued_metadata
    ADD CONSTRAINT action_issued_metadata_action_id_key UNIQUE (action_id);


--
-- Name: action_issued_metadata action_issued_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_issued_metadata
    ADD CONSTRAINT action_issued_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: action_results action_results_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_results
    ADD CONSTRAINT action_results_message_id_key UNIQUE (message_id);


--
-- Name: action_results action_results_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_results
    ADD CONSTRAINT action_results_pkey PRIMARY KEY (action_id);


--
-- Name: action_results action_results_source_event_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_results
    ADD CONSTRAINT action_results_source_event_id_key UNIQUE (source_event_id);


--
-- Name: actor_profile_bindings actor_profile_bindings_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.actor_profile_bindings
    ADD CONSTRAINT actor_profile_bindings_pkey PRIMARY KEY (installation_id, playthrough_id, actor_key);


--
-- Name: audit_request_metadata audit_request_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: backup_records backup_records_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.backup_records
    ADD CONSTRAINT backup_records_pkey PRIMARY KEY (backup_id);


--
-- Name: biography_catalog_entries biography_catalog_entries_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.biography_catalog_entries
    ADD CONSTRAINT biography_catalog_entries_pkey PRIMARY KEY (catalog_id, npc_name);


--
-- Name: biography_catalogs biography_catalogs_catalog_version_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.biography_catalogs
    ADD CONSTRAINT biography_catalogs_catalog_version_key UNIQUE (catalog_version);


--
-- Name: biography_catalogs biography_catalogs_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.biography_catalogs
    ADD CONSTRAINT biography_catalogs_pkey PRIMARY KEY (catalog_id);


--
-- Name: book_metadata book_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.book_metadata
    ADD CONSTRAINT book_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: browser_sessions browser_sessions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.browser_sessions
    ADD CONSTRAINT browser_sessions_pkey PRIMARY KEY (session_hash);


--
-- Name: character_playthrough_bindings character_playthrough_binding_installation_id_playthrough_i_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.character_playthrough_bindings
    ADD CONSTRAINT character_playthrough_binding_installation_id_playthrough_i_key UNIQUE (installation_id, playthrough_id);


--
-- Name: character_playthrough_bindings character_playthrough_bindings_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.character_playthrough_bindings
    ADD CONSTRAINT character_playthrough_bindings_pkey PRIMARY KEY (installation_id, character_id);


--
-- Name: configuration_revisions configuration_revisions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.configuration_revisions
    ADD CONSTRAINT configuration_revisions_pkey PRIMARY KEY (configuration_id, revision);


--
-- Name: configuration_sets configuration_sets_id_installation_unique; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.configuration_sets
    ADD CONSTRAINT configuration_sets_id_installation_unique UNIQUE (configuration_id, installation_id);


--
-- Name: configuration_sets configuration_sets_installation_id_profile_id_kind_name_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.configuration_sets
    ADD CONSTRAINT configuration_sets_installation_id_profile_id_kind_name_key UNIQUE NULLS NOT DISTINCT (installation_id, profile_id, kind, name);


--
-- Name: configuration_sets configuration_sets_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.configuration_sets
    ADD CONSTRAINT configuration_sets_pkey PRIMARY KEY (configuration_id);


--
-- Name: content_manifest_files content_manifest_files_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifest_files
    ADD CONSTRAINT content_manifest_files_pkey PRIMARY KEY (installation_id, content_file);


--
-- Name: content_manifests content_manifests_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifests
    ADD CONSTRAINT content_manifests_pkey PRIMARY KEY (installation_id);


--
-- Name: core_profile_metadata core_profile_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_metadata
    ADD CONSTRAINT core_profile_metadata_pkey PRIMARY KEY (core_profile_id);


--
-- Name: core_profile_metadata core_profile_metadata_source_core_profile_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_metadata
    ADD CONSTRAINT core_profile_metadata_source_core_profile_id_key UNIQUE (source_core_profile_id);


--
-- Name: core_profile_presets core_profile_presets_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_presets
    ADD CONSTRAINT core_profile_presets_pkey PRIMARY KEY (preset_id);


--
-- Name: core_profile_revisions core_profile_revisions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_revisions
    ADD CONSTRAINT core_profile_revisions_pkey PRIMARY KEY (core_profile_id, revision);


--
-- Name: core_profiles core_profiles_core_profile_id_installation_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profiles
    ADD CONSTRAINT core_profiles_core_profile_id_installation_id_key UNIQUE (core_profile_id, installation_id);


--
-- Name: core_profiles core_profiles_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profiles
    ADD CONSTRAINT core_profiles_pkey PRIMARY KEY (core_profile_id);


--
-- Name: core_tts_pronunciation core_tts_pronunciation_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_tts_pronunciation
    ADD CONSTRAINT core_tts_pronunciation_pkey PRIMARY KEY (id);


--
-- Name: currentmission_metadata currentmission_metadata_installation_id_playthrough_id_jour_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.currentmission_metadata
    ADD CONSTRAINT currentmission_metadata_installation_id_playthrough_id_jour_key UNIQUE (installation_id, playthrough_id, journal_id);


--
-- Name: currentmission_metadata currentmission_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.currentmission_metadata
    ADD CONSTRAINT currentmission_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: database_backup_settings database_backup_settings_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.database_backup_settings
    ADD CONSTRAINT database_backup_settings_pkey PRIMARY KEY (singleton);


--
-- Name: database_snapshot_source database_snapshot_source_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.database_snapshot_source
    ADD CONSTRAINT database_snapshot_source_pkey PRIMARY KEY (singleton);


--
-- Name: debug_commands debug_commands_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.debug_commands
    ADD CONSTRAINT debug_commands_pkey PRIMARY KEY (command_id);


--
-- Name: debug_commands debug_commands_result_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.debug_commands
    ADD CONSTRAINT debug_commands_result_message_id_key UNIQUE (result_message_id);


--
-- Name: description_catalog_entries description_catalog_entries_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_catalog_entries
    ADD CONSTRAINT description_catalog_entries_pkey PRIMARY KEY (catalog_id, plugin, baseid);


--
-- Name: description_catalogs description_catalogs_catalog_version_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_catalogs
    ADD CONSTRAINT description_catalogs_catalog_version_key UNIQUE (catalog_version);


--
-- Name: description_catalogs description_catalogs_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_catalogs
    ADD CONSTRAINT description_catalogs_pkey PRIMARY KEY (catalog_id);


--
-- Name: description_metadata description_metadata_description_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_metadata
    ADD CONSTRAINT description_metadata_description_id_key UNIQUE (description_id);


--
-- Name: description_metadata description_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_metadata
    ADD CONSTRAINT description_metadata_pkey PRIMARY KEY (plugin, baseid);


--
-- Name: dialogue_delivery_results dialogue_delivery_results_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_message_id_key UNIQUE (message_id);


--
-- Name: dialogue_delivery_results dialogue_delivery_results_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_pkey PRIMARY KEY (dialogue_message_id);


--
-- Name: dialogue_delivery_results dialogue_delivery_results_source_event_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_source_event_id_key UNIQUE (source_event_id);


--
-- Name: dialogue_utterances dialogue_utterances_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_utterances
    ADD CONSTRAINT dialogue_utterances_pkey PRIMARY KEY (dialogue_message_id);


--
-- Name: dialogue_utterances dialogue_utterances_response_line_unique; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_utterances
    ADD CONSTRAINT dialogue_utterances_response_line_unique UNIQUE (response_line_id);


--
-- Name: dialogue_utterances dialogue_utterances_turn_id_utterance_index_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_utterances
    ADD CONSTRAINT dialogue_utterances_turn_id_utterance_index_key UNIQUE (turn_id, utterance_index);


--
-- Name: dialogue_utterances dialogue_utterances_utterance_unique; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_utterances
    ADD CONSTRAINT dialogue_utterances_utterance_unique UNIQUE (utterance_id);


--
-- Name: diarylog_metadata diarylog_metadata_narrative_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.diarylog_metadata
    ADD CONSTRAINT diarylog_metadata_narrative_id_key UNIQUE (narrative_id);


--
-- Name: diarylog_metadata diarylog_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.diarylog_metadata
    ADD CONSTRAINT diarylog_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: director_instructions director_instructions_child_turn_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_instructions
    ADD CONSTRAINT director_instructions_child_turn_id_key UNIQUE (child_turn_id);


--
-- Name: director_instructions director_instructions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_instructions
    ADD CONSTRAINT director_instructions_pkey PRIMARY KEY (instruction_id);


--
-- Name: director_instructions director_instructions_plan_id_ordinal_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_instructions
    ADD CONSTRAINT director_instructions_plan_id_ordinal_key UNIQUE (plan_id, ordinal);


--
-- Name: director_plans director_plans_origin_turn_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_plans
    ADD CONSTRAINT director_plans_origin_turn_id_key UNIQUE (origin_turn_id);


--
-- Name: director_plans director_plans_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_plans
    ADD CONSTRAINT director_plans_pkey PRIMARY KEY (plan_id);


--
-- Name: discovered_items discovered_items_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.discovered_items
    ADD CONSTRAINT discovered_items_pkey PRIMARY KEY (installation_id, content_file, record_id);


--
-- Name: disposition_adjustments disposition_adjustments_job_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_job_id_key UNIQUE (job_id);


--
-- Name: disposition_adjustments disposition_adjustments_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_pkey PRIMARY KEY (adjustment_id);


--
-- Name: durable_job_attempts durable_job_attempts_job_id_attempt_number_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_attempts
    ADD CONSTRAINT durable_job_attempts_job_id_attempt_number_key UNIQUE (job_id, attempt_number);


--
-- Name: durable_job_attempts durable_job_attempts_lease_token_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_attempts
    ADD CONSTRAINT durable_job_attempts_lease_token_key UNIQUE (lease_token);


--
-- Name: durable_job_attempts durable_job_attempts_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_attempts
    ADD CONSTRAINT durable_job_attempts_pkey PRIMARY KEY (attempt_id);


--
-- Name: durable_job_dead_letters durable_job_dead_letters_job_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_dead_letters
    ADD CONSTRAINT durable_job_dead_letters_job_id_key UNIQUE (job_id);


--
-- Name: durable_job_dead_letters durable_job_dead_letters_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_dead_letters
    ADD CONSTRAINT durable_job_dead_letters_pkey PRIMARY KEY (dead_letter_id);


--
-- Name: durable_jobs durable_jobs_job_type_idempotency_key_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_jobs
    ADD CONSTRAINT durable_jobs_job_type_idempotency_key_key UNIQUE (job_type, idempotency_key);


--
-- Name: durable_jobs durable_jobs_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_jobs
    ADD CONSTRAINT durable_jobs_pkey PRIMARY KEY (job_id);


--
-- Name: eventlog_hidden_types eventlog_hidden_types_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_hidden_types
    ADD CONSTRAINT eventlog_hidden_types_pkey PRIMARY KEY (installation_id, event_type);


--
-- Name: eventlog_metadata eventlog_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: eventlog_metadata eventlog_metadata_projection_kind_projection_key_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_projection_kind_projection_key_key UNIQUE (projection_kind, projection_key);


--
-- Name: faction_metadata faction_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.faction_metadata
    ADD CONSTRAINT faction_metadata_pkey PRIMARY KEY (formid);


--
-- Name: game_dispositions game_dispositions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_dispositions
    ADD CONSTRAINT game_dispositions_pkey PRIMARY KEY (installation_id, playthrough_id, actor_key, player_key);


--
-- Name: game_plugin_metadata game_plugin_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_plugin_metadata
    ADD CONSTRAINT game_plugin_metadata_pkey PRIMARY KEY (plugin_name);


--
-- Name: general_setting_metadata general_setting_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.general_setting_metadata
    ADD CONSTRAINT general_setting_metadata_pkey PRIMARY KEY (id);


--
-- Name: general_setting_metadata general_setting_metadata_source_configuration_id_setting_ke_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.general_setting_metadata
    ADD CONSTRAINT general_setting_metadata_source_configuration_id_setting_ke_key UNIQUE (source_configuration_id, setting_key);


--
-- Name: global_settings_presets global_settings_presets_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.global_settings_presets
    ADD CONSTRAINT global_settings_presets_pkey PRIMARY KEY (preset_id);


--
-- Name: idempotency_requests idempotency_requests_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.idempotency_requests
    ADD CONSTRAINT idempotency_requests_pkey PRIMARY KEY (installation_id, idempotency_key, route);


--
-- Name: installation_profile_preferences installation_profile_preferences_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.installation_profile_preferences
    ADD CONSTRAINT installation_profile_preferences_pkey PRIMARY KEY (installation_id);


--
-- Name: installation_provider_selections installation_provider_selections_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.installation_provider_selections
    ADD CONSTRAINT installation_provider_selections_pkey PRIMARY KEY (installation_id, provider_kind);


--
-- Name: installations installations_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.installations
    ADD CONSTRAINT installations_pkey PRIMARY KEY (installation_id);


--
-- Name: interruptions interruptions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.interruptions
    ADD CONSTRAINT interruptions_pkey PRIMARY KEY (message_id);


--
-- Name: interruptions interruptions_request_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.interruptions
    ADD CONSTRAINT interruptions_request_id_key UNIQUE (request_id);


--
-- Name: item_descriptions item_descriptions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.item_descriptions
    ADD CONSTRAINT item_descriptions_pkey PRIMARY KEY (description_id);


--
-- Name: knowledge_documents knowledge_documents_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.knowledge_documents
    ADD CONSTRAINT knowledge_documents_pkey PRIMARY KEY (document_id);


--
-- Name: llm_connector_metadata llm_connector_metadata_configuration_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.llm_connector_metadata
    ADD CONSTRAINT llm_connector_metadata_configuration_id_key UNIQUE (configuration_id);


--
-- Name: llm_connector_metadata llm_connector_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.llm_connector_metadata
    ADD CONSTRAINT llm_connector_metadata_pkey PRIMARY KEY (connector_id);


--
-- Name: location_metadata location_metadata_cell_key_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.location_metadata
    ADD CONSTRAINT location_metadata_cell_key_key UNIQUE (cell_key);


--
-- Name: location_metadata location_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.location_metadata
    ADD CONSTRAINT location_metadata_pkey PRIMARY KEY (formid);


--
-- Name: log_metadata log_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.log_metadata
    ADD CONSTRAINT log_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: media_objects media_objects_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.media_objects
    ADD CONSTRAINT media_objects_pkey PRIMARY KEY (media_id);


--
-- Name: memory_embeddings memory_embeddings_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_embeddings
    ADD CONSTRAINT memory_embeddings_pkey PRIMARY KEY (memory_id, memory_revision, policy_configuration_id, policy_revision);


--
-- Name: memory_metadata memory_metadata_memory_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_memory_id_key UNIQUE (memory_id);


--
-- Name: memory_metadata memory_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: memory_model_summaries memory_model_summaries_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_model_summaries
    ADD CONSTRAINT memory_model_summaries_pkey PRIMARY KEY (memory_id, memory_revision);


--
-- Name: memory_record_revisions memory_record_revisions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_record_revisions
    ADD CONSTRAINT memory_record_revisions_pkey PRIMARY KEY (memory_id, revision);


--
-- Name: memory_records memory_records_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_records
    ADD CONSTRAINT memory_records_pkey PRIMARY KEY (memory_id);


--
-- Name: memory_summary_metadata memory_summary_metadata_memory_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_summary_metadata
    ADD CONSTRAINT memory_summary_metadata_memory_id_key UNIQUE (memory_id);


--
-- Name: memory_summary_metadata memory_summary_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_summary_metadata
    ADD CONSTRAINT memory_summary_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: menu_dialogue_tts_requests menu_dialogue_tts_requests_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.menu_dialogue_tts_requests
    ADD CONSTRAINT menu_dialogue_tts_requests_pkey PRIMARY KEY (message_id);


--
-- Name: menu_dialogue_tts_requests menu_dialogue_tts_requests_request_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.menu_dialogue_tts_requests
    ADD CONSTRAINT menu_dialogue_tts_requests_request_id_key UNIQUE (request_id);


--
-- Name: narrative_records narrative_records_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.narrative_records
    ADD CONSTRAINT narrative_records_pkey PRIMARY KEY (narrative_id);


--
-- Name: npc_evolution_reports npc_evolution_reports_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_evolution_reports
    ADD CONSTRAINT npc_evolution_reports_pkey PRIMARY KEY (job_id);


--
-- Name: npc_memory_digests npc_memory_digests_installation_id_playthrough_id_profile__key1; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_installation_id_playthrough_id_profile__key1 UNIQUE (installation_id, playthrough_id, profile_id, digest_id);


--
-- Name: npc_memory_digests npc_memory_digests_installation_id_playthrough_id_profile_i_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_installation_id_playthrough_id_profile_i_key UNIQUE (installation_id, playthrough_id, profile_id, revision);


--
-- Name: npc_memory_digests npc_memory_digests_job_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_job_id_key UNIQUE (job_id);


--
-- Name: npc_memory_digests npc_memory_digests_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_pkey PRIMARY KEY (digest_id);


--
-- Name: npc_metadata npc_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_metadata
    ADD CONSTRAINT npc_metadata_pkey PRIMARY KEY (npc_id);


--
-- Name: npc_metadata npc_metadata_source_profile_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_metadata
    ADD CONSTRAINT npc_metadata_source_profile_id_key UNIQUE (source_profile_id);


--
-- Name: npc_reference_groups npc_reference_groups_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_reference_groups
    ADD CONSTRAINT npc_reference_groups_pkey PRIMARY KEY (installation_id, group_key);


--
-- Name: oghma_catalog_deletions oghma_catalog_deletions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_catalog_deletions
    ADD CONSTRAINT oghma_catalog_deletions_pkey PRIMARY KEY (installation_id, topic);


--
-- Name: oghma_catalog_entries oghma_catalog_entries_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_catalog_entries
    ADD CONSTRAINT oghma_catalog_entries_pkey PRIMARY KEY (catalog_id, topic);


--
-- Name: oghma_catalogs oghma_catalogs_catalog_version_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_catalogs
    ADD CONSTRAINT oghma_catalogs_catalog_version_key UNIQUE (catalog_version);


--
-- Name: oghma_catalogs oghma_catalogs_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_catalogs
    ADD CONSTRAINT oghma_catalogs_pkey PRIMARY KEY (catalog_id);


--
-- Name: oghma_dynamic_applications oghma_dynamic_applications_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic_applications
    ADD CONSTRAINT oghma_dynamic_applications_pkey PRIMARY KEY (installation_id, playthrough_id, rule_id, revision);


--
-- Name: oghma_dynamic oghma_dynamic_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic
    ADD CONSTRAINT oghma_dynamic_pkey PRIMARY KEY (id);


--
-- Name: oghma_factory_documents oghma_factory_documents_document_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_factory_documents
    ADD CONSTRAINT oghma_factory_documents_document_id_key UNIQUE (document_id);


--
-- Name: oghma_factory_documents oghma_factory_documents_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_factory_documents
    ADD CONSTRAINT oghma_factory_documents_pkey PRIMARY KEY (installation_id, topic);


--
-- Name: oghma_installation_settings oghma_installation_settings_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_installation_settings
    ADD CONSTRAINT oghma_installation_settings_pkey PRIMARY KEY (installation_id);


--
-- Name: oghma_metadata oghma_metadata_document_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_metadata
    ADD CONSTRAINT oghma_metadata_document_id_key UNIQUE (document_id);


--
-- Name: oghma_metadata oghma_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_metadata
    ADD CONSTRAINT oghma_metadata_pkey PRIMARY KEY (topic);


--
-- Name: operational_audit operational_audit_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.operational_audit
    ADD CONSTRAINT operational_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: pairing_tokens pairing_tokens_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.pairing_tokens
    ADD CONSTRAINT pairing_tokens_pkey PRIMARY KEY (pairing_token_id);


--
-- Name: pairing_tokens pairing_tokens_token_hash_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.pairing_tokens
    ADD CONSTRAINT pairing_tokens_token_hash_key UNIQUE (token_hash);


--
-- Name: physical_diary_deliveries physical_diary_deliveries_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.physical_diary_deliveries
    ADD CONSTRAINT physical_diary_deliveries_pkey PRIMARY KEY (delivery_id);


--
-- Name: player2_routing player2_routing_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.player2_routing
    ADD CONSTRAINT player2_routing_pkey PRIMARY KEY (installation_id, revision);


--
-- Name: player_speech_style_drafts player_speech_style_drafts_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.player_speech_style_drafts
    ADD CONSTRAINT player_speech_style_drafts_pkey PRIMARY KEY (job_id);


--
-- Name: playthrough_associations playthrough_associations_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_associations
    ADD CONSTRAINT playthrough_associations_pkey PRIMARY KEY (association_id);


--
-- Name: playthrough_local_state playthrough_local_state_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_local_state
    ADD CONSTRAINT playthrough_local_state_pkey PRIMARY KEY (playthrough_id);


--
-- Name: playthrough_revisions playthrough_revisions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_revisions
    ADD CONSTRAINT playthrough_revisions_pkey PRIMARY KEY (playthrough_id, revision);


--
-- Name: playthrough_saves playthrough_saves_installation_id_capture_key_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_saves
    ADD CONSTRAINT playthrough_saves_installation_id_capture_key_key UNIQUE (installation_id, capture_key);


--
-- Name: playthrough_saves playthrough_saves_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_saves
    ADD CONSTRAINT playthrough_saves_pkey PRIMARY KEY (save_id);


--
-- Name: playthroughs playthroughs_id_installation_profile_unique; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthroughs
    ADD CONSTRAINT playthroughs_id_installation_profile_unique UNIQUE (playthrough_id, installation_id, profile_id);


--
-- Name: playthroughs playthroughs_id_installation_unique; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthroughs
    ADD CONSTRAINT playthroughs_id_installation_unique UNIQUE (playthrough_id, installation_id);


--
-- Name: playthroughs playthroughs_installation_id_name_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthroughs
    ADD CONSTRAINT playthroughs_installation_id_name_key UNIQUE (installation_id, name);


--
-- Name: playthroughs playthroughs_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthroughs
    ADD CONSTRAINT playthroughs_pkey PRIMARY KEY (playthrough_id);


--
-- Name: profile_assignment_rules profile_assignment_rules_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_assignment_rules
    ADD CONSTRAINT profile_assignment_rules_pkey PRIMARY KEY (rule_id);


--
-- Name: profile_evolution_clocks profile_evolution_clocks_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_clocks
    ADD CONSTRAINT profile_evolution_clocks_pkey PRIMARY KEY (installation_id, playthrough_id);


--
-- Name: profile_evolution_events profile_evolution_events_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_events
    ADD CONSTRAINT profile_evolution_events_pkey PRIMARY KEY (profile_id, playthrough_id, epoch, rowid);


--
-- Name: profile_evolution_progress profile_evolution_progress_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_progress
    ADD CONSTRAINT profile_evolution_progress_pkey PRIMARY KEY (profile_id, playthrough_id);


--
-- Name: profile_revisions profile_revisions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_revisions
    ADD CONSTRAINT profile_revisions_pkey PRIMARY KEY (profile_id, revision);


--
-- Name: profiles profiles_id_installation_unique; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profiles
    ADD CONSTRAINT profiles_id_installation_unique UNIQUE (profile_id, installation_id);


--
-- Name: profiles profiles_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profiles
    ADD CONSTRAINT profiles_pkey PRIMARY KEY (profile_id);


--
-- Name: prompt_metadata prompt_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_metadata
    ADD CONSTRAINT prompt_metadata_pkey PRIMARY KEY (prompt_key);


--
-- Name: prompt_trace_sections prompt_trace_sections_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_trace_sections
    ADD CONSTRAINT prompt_trace_sections_pkey PRIMARY KEY (prompt_trace_id, section_order);


--
-- Name: prompt_trace_sections prompt_trace_sections_prompt_trace_id_section_key_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_trace_sections
    ADD CONSTRAINT prompt_trace_sections_prompt_trace_id_section_key_key UNIQUE (prompt_trace_id, section_key);


--
-- Name: prompt_trace_sources prompt_trace_sources_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_trace_sources
    ADD CONSTRAINT prompt_trace_sources_pkey PRIMARY KEY (prompt_trace_id, ordinal);


--
-- Name: prompt_traces prompt_traces_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_pkey PRIMARY KEY (prompt_trace_id);


--
-- Name: prompt_traces prompt_traces_turn_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_turn_id_key UNIQUE (turn_id);


--
-- Name: prompts prompts_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompts
    ADD CONSTRAINT prompts_pkey PRIMARY KEY (installation_id, prompt_key);


--
-- Name: provider_attempts provider_attempts_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.provider_attempts
    ADD CONSTRAINT provider_attempts_pkey PRIMARY KEY (provider_attempt_id);


--
-- Name: provider_attempts provider_attempts_provider_kind_provider_name_operation_req_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.provider_attempts
    ADD CONSTRAINT provider_attempts_provider_kind_provider_name_operation_req_key UNIQUE (provider_kind, provider_name, operation, request_id, attempt_number);


--
-- Name: quest_metadata quest_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quest_metadata
    ADD CONSTRAINT quest_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: questlog_metadata questlog_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.questlog_metadata
    ADD CONSTRAINT questlog_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: quickstart_local_llm quickstart_local_llm_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quickstart_local_llm
    ADD CONSTRAINT quickstart_local_llm_pkey PRIMARY KEY (installation_id);


--
-- Name: rate_limit_buckets rate_limit_buckets_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rate_limit_buckets
    ADD CONSTRAINT rate_limit_buckets_pkey PRIMARY KEY (bucket_key);


--
-- Name: rechat_chains rechat_chains_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rechat_chains
    ADD CONSTRAINT rechat_chains_pkey PRIMARY KEY (chain_id);


--
-- Name: rechat_chains rechat_chains_session_id_generation_chain_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rechat_chains
    ADD CONSTRAINT rechat_chains_session_id_generation_chain_id_key UNIQUE (session_id, generation, chain_id);


--
-- Name: relationship_audit relationship_audit_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_audit
    ADD CONSTRAINT relationship_audit_pkey PRIMARY KEY (audit_id);


--
-- Name: relationship_build_results relationship_build_results_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_build_results
    ADD CONSTRAINT relationship_build_results_pkey PRIMARY KEY (job_id);


--
-- Name: relationship_conversion_results relationship_conversion_results_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_conversion_results
    ADD CONSTRAINT relationship_conversion_results_pkey PRIMARY KEY (job_id);


--
-- Name: relationship_evaluation_results relationship_evaluation_results_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_evaluation_results
    ADD CONSTRAINT relationship_evaluation_results_pkey PRIMARY KEY (job_id);


--
-- Name: relationship_records relationship_records_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_records
    ADD CONSTRAINT relationship_records_pkey PRIMARY KEY (relationship_id);


--
-- Name: relationship_revisions relationship_revisions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_revisions
    ADD CONSTRAINT relationship_revisions_pkey PRIMARY KEY (relationship_id, revision);


--
-- Name: request_log_hidden request_log_hidden_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.request_log_hidden
    ADD CONSTRAINT request_log_hidden_pkey PRIMARY KEY (provider_attempt_id);


--
-- Name: request_mac_nonces request_mac_nonces_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.request_mac_nonces
    ADD CONSTRAINT request_mac_nonces_pkey PRIMARY KEY (pairing_token_id, nonce);


--
-- Name: response_events response_events_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.response_events
    ADD CONSTRAINT response_events_message_id_key UNIQUE (message_id);


--
-- Name: response_events response_events_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.response_events
    ADD CONSTRAINT response_events_pkey PRIMARY KEY (session_id, sequence);


--
-- Name: responselog_metadata responselog_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog_metadata
    ADD CONSTRAINT responselog_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: responselog_metadata responselog_metadata_response_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog_metadata
    ADD CONSTRAINT responselog_metadata_response_message_id_key UNIQUE (response_message_id);


--
-- Name: responselog responselog_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog
    ADD CONSTRAINT responselog_pkey PRIMARY KEY (rowid);


--
-- Name: responselog responselog_response_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog
    ADD CONSTRAINT responselog_response_message_id_key UNIQUE (response_message_id);


--
-- Name: retrieval_traces retrieval_traces_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.retrieval_traces
    ADD CONSTRAINT retrieval_traces_pkey PRIMARY KEY (retrieval_trace_id);


--
-- Name: scene_classifications scene_classifications_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.scene_classifications
    ADD CONSTRAINT scene_classifications_pkey PRIMARY KEY (job_id);


--
-- Name: scene_classifications scene_classifications_turn_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.scene_classifications
    ADD CONSTRAINT scene_classifications_turn_id_key UNIQUE (turn_id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (session_id);


--
-- Name: source_events source_events_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.source_events
    ADD CONSTRAINT source_events_pkey PRIMARY KEY (source_event_id);


--
-- Name: speech_connector_voices speech_connector_voices_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_connector_voices
    ADD CONSTRAINT speech_connector_voices_pkey PRIMARY KEY (configuration_id, voice_id);


--
-- Name: speech speech_dialogue_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech
    ADD CONSTRAINT speech_dialogue_message_id_key UNIQUE (dialogue_message_id);


--
-- Name: speech_metadata speech_metadata_dialogue_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_metadata
    ADD CONSTRAINT speech_metadata_dialogue_message_id_key UNIQUE (dialogue_message_id);


--
-- Name: speech_metadata speech_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_metadata
    ADD CONSTRAINT speech_metadata_pkey PRIMARY KEY (rowid);


--
-- Name: speech speech_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech
    ADD CONSTRAINT speech_pkey PRIMARY KEY (rowid);


--
-- Name: stt_requests stt_requests_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.stt_requests
    ADD CONSTRAINT stt_requests_pkey PRIMARY KEY (message_id);


--
-- Name: stt_requests stt_requests_request_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.stt_requests
    ADD CONSTRAINT stt_requests_request_id_key UNIQUE (request_id);


--
-- Name: timeline_invalidated_sources timeline_invalidated_sources_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.timeline_invalidated_sources
    ADD CONSTRAINT timeline_invalidated_sources_pkey PRIMARY KEY (source_event_id);


--
-- Name: timeline_invalidated_turns timeline_invalidated_turns_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.timeline_invalidated_turns
    ADD CONSTRAINT timeline_invalidated_turns_pkey PRIMARY KEY (turn_id);


--
-- Name: tts_connector_metadata tts_connector_metadata_configuration_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.tts_connector_metadata
    ADD CONSTRAINT tts_connector_metadata_configuration_id_key UNIQUE (configuration_id);


--
-- Name: tts_connector_metadata tts_connector_metadata_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.tts_connector_metadata
    ADD CONSTRAINT tts_connector_metadata_pkey PRIMARY KEY (connector_id);


--
-- Name: turn_provider_snapshots turn_provider_snapshots_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.turn_provider_snapshots
    ADD CONSTRAINT turn_provider_snapshots_pkey PRIMARY KEY (turn_id);


--
-- Name: turns turns_message_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.turns
    ADD CONSTRAINT turns_message_id_key UNIQUE (message_id);


--
-- Name: turns turns_pkey; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.turns
    ADD CONSTRAINT turns_pkey PRIMARY KEY (turn_id);


--
-- Name: turns turns_request_id_key; Type: CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.turns
    ADD CONSTRAINT turns_request_id_key UNIQUE (request_id);


--
-- Name: actions_issued actions_issued_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.actions_issued
    ADD CONSTRAINT actions_issued_pkey PRIMARY KEY (rowid);


--
-- Name: audit_request audit_request_primary; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_request
    ADD CONSTRAINT audit_request_primary PRIMARY KEY (rowid);


--
-- Name: bio_templates_custom bio_templates_custom_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bio_templates_custom
    ADD CONSTRAINT bio_templates_custom_pkey PRIMARY KEY (npc_name);


--
-- Name: bio_templates bio_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.bio_templates
    ADD CONSTRAINT bio_templates_pkey PRIMARY KEY (npc_name);


--
-- Name: books books_pidx; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.books
    ADD CONSTRAINT books_pidx PRIMARY KEY (rowid);


--
-- Name: named_cell cell_id_door_id; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.named_cell
    ADD CONSTRAINT cell_id_door_id PRIMARY KEY (id, door_id);


--
-- Name: core_action core_action_code_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_action
    ADD CONSTRAINT core_action_code_name_key UNIQUE (code_name);


--
-- Name: core_action_custom core_action_custom_code_name_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_action_custom
    ADD CONSTRAINT core_action_custom_code_name_key UNIQUE (code_name);


--
-- Name: core_action_custom core_action_custom_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_action_custom
    ADD CONSTRAINT core_action_custom_pkey PRIMARY KEY (id);


--
-- Name: core_action core_action_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_action
    ADD CONSTRAINT core_action_pkey PRIMARY KEY (id);


--
-- Name: core_api_badge core_api_badge_label_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_api_badge
    ADD CONSTRAINT core_api_badge_label_unique UNIQUE (label);


--
-- Name: core_narrator core_narrator_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_narrator
    ADD CONSTRAINT core_narrator_pkey PRIMARY KEY (id);


--
-- Name: core_npc_master_history core_npc_master_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_npc_master_history
    ADD CONSTRAINT core_npc_master_history_pkey PRIMARY KEY (history_id);


--
-- Name: core_player core_player_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_player
    ADD CONSTRAINT core_player_pkey PRIMARY KEY (id);


--
-- Name: core_tts_fallback core_tts_fallback_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_tts_fallback
    ADD CONSTRAINT core_tts_fallback_pkey PRIMARY KEY (id);


--
-- Name: core_tts_fallback core_tts_fallback_race_gender_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_tts_fallback
    ADD CONSTRAINT core_tts_fallback_race_gender_key UNIQUE (race, gender);


--
-- Name: currentmission currentmission_pidx; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.currentmission
    ADD CONSTRAINT currentmission_pidx PRIMARY KEY (rowid);


--
-- Name: database_versioning database_versioning_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.database_versioning
    ADD CONSTRAINT database_versioning_pkey PRIMARY KEY (tablename);


--
-- Name: descriptions_custom descriptions_custom_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.descriptions_custom
    ADD CONSTRAINT descriptions_custom_pkey PRIMARY KEY (plugin, baseid);


--
-- Name: descriptions descriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.descriptions
    ADD CONSTRAINT descriptions_pkey PRIMARY KEY (plugin, baseid);


--
-- Name: diarylog diarylog_pidx; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.diarylog
    ADD CONSTRAINT diarylog_pidx PRIMARY KEY (rowid);


--
-- Name: dynamic_bio dynamic_bio_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.dynamic_bio
    ADD CONSTRAINT dynamic_bio_pkey PRIMARY KEY (id);


--
-- Name: eventlog eventlog_primary; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.eventlog
    ADD CONSTRAINT eventlog_primary PRIMARY KEY (rowid);


--
-- Name: factions factions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.factions
    ADD CONSTRAINT factions_pkey PRIMARY KEY (formid);


--
-- Name: game_plugins game_plugins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.game_plugins
    ADD CONSTRAINT game_plugins_pkey PRIMARY KEY (plugin_name);


--
-- Name: general_settings general_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.general_settings
    ADD CONSTRAINT general_settings_pkey PRIMARY KEY (id);


--
-- Name: import_rules import_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_rules
    ADD CONSTRAINT import_rules_pkey PRIMARY KEY (id);


--
-- Name: json_personalities json_personalities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.json_personalities
    ADD CONSTRAINT json_personalities_pkey PRIMARY KEY (npc_name);


--
-- Name: core_llm_connector llm_connector_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_llm_connector
    ADD CONSTRAINT llm_connector_pkey PRIMARY KEY (id);


--
-- Name: market_cache market_cache_pk; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.market_cache
    ADD CONSTRAINT market_cache_pk PRIMARY KEY (baseid, plugin);


--
-- Name: memory memory_pidx; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memory
    ADD CONSTRAINT memory_pidx PRIMARY KEY (rowid);


--
-- Name: memory_summary memory_summary_pidx; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.memory_summary
    ADD CONSTRAINT memory_summary_pidx PRIMARY KEY (rowid);


--
-- Name: moods_issued moods_issued_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.moods_issued
    ADD CONSTRAINT moods_issued_pkey PRIMARY KEY (rowid);


--
-- Name: core_api_badge my_table_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_api_badge
    ADD CONSTRAINT my_table_pkey PRIMARY KEY (id);


--
-- Name: core_npc_master npc_master_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_npc_master
    ADD CONSTRAINT npc_master_pkey PRIMARY KEY (id);


--
-- Name: oghma_context_rule oghma_context_rule_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oghma_context_rule
    ADD CONSTRAINT oghma_context_rule_pkey PRIMARY KEY (id);


--
-- Name: oghma_dynamic oghma_dynamic_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oghma_dynamic
    ADD CONSTRAINT oghma_dynamic_pkey PRIMARY KEY (id);


--
-- Name: oghma oghma_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.oghma
    ADD CONSTRAINT oghma_pkey PRIMARY KEY (topic);


--
-- Name: physical_npc_diaries physical_npc_diaries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.physical_npc_diaries
    ADD CONSTRAINT physical_npc_diaries_pkey PRIMARY KEY (npc_name);


--
-- Name: conf_opts pid; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.conf_opts
    ADD CONSTRAINT pid PRIMARY KEY (id);


--
-- Name: core_profiles profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_pkey PRIMARY KEY (id);


--
-- Name: prompts prompts_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.prompts
    ADD CONSTRAINT prompts_pkey PRIMARY KEY (prompt_key);


--
-- Name: questlog questlog_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.questlog
    ADD CONSTRAINT questlog_pkey PRIMARY KEY (rowid);


--
-- Name: relationship_eval_queue relationship_eval_queue_npc_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.relationship_eval_queue
    ADD CONSTRAINT relationship_eval_queue_npc_id_key UNIQUE (npc_id);


--
-- Name: relationship_eval_queue relationship_eval_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.relationship_eval_queue
    ADD CONSTRAINT relationship_eval_queue_pkey PRIMARY KEY (id);


--
-- Name: relationship_init_queue relationship_init_queue_npc_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.relationship_init_queue
    ADD CONSTRAINT relationship_init_queue_npc_id_key UNIQUE (npc_id);


--
-- Name: relationship_init_queue relationship_init_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.relationship_init_queue
    ADD CONSTRAINT relationship_init_queue_pkey PRIMARY KEY (id);


--
-- Name: rolemaster rolemaster_pk; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.rolemaster
    ADD CONSTRAINT rolemaster_pk PRIMARY KEY (rowid);


--
-- Name: core_stt_connector stt_connector_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_stt_connector
    ADD CONSTRAINT stt_connector_pkey PRIMARY KEY (id);


--
-- Name: translations translation_pk; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.translations
    ADD CONSTRAINT translation_pk PRIMARY KEY (id);


--
-- Name: core_tts_connector tts_connector_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_tts_connector
    ADD CONSTRAINT tts_connector_pkey PRIMARY KEY (id);


--
-- Name: action_intents_conversation_end_session; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX action_intents_conversation_end_session ON lorkhan_internal.action_intents USING btree (session_id, emitted_at DESC) WHERE (action_name = 'conversation.end'::text);


--
-- Name: action_intents_session_turn; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX action_intents_session_turn ON lorkhan_internal.action_intents USING btree (session_id, turn_id);


--
-- Name: actor_profile_bindings_profile; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX actor_profile_bindings_profile ON lorkhan_internal.actor_profile_bindings USING btree (installation_id, profile_id);


--
-- Name: biography_catalog_entries_identity_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX biography_catalog_entries_identity_uq ON lorkhan_internal.biography_catalog_entries USING btree (catalog_id, lower((COALESCE(content_file, ''::character varying))::text), lower((record_id)::text));


--
-- Name: biography_catalog_entries_lookup_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX biography_catalog_entries_lookup_idx ON lorkhan_internal.biography_catalog_entries USING btree (lower((content_file)::text), lower((record_id)::text));


--
-- Name: biography_catalogs_one_active_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX biography_catalogs_one_active_uq ON lorkhan_internal.biography_catalogs USING btree (state) WHERE (state = 'active'::text);


--
-- Name: biography_catalogs_recent_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX biography_catalogs_recent_idx ON lorkhan_internal.biography_catalogs USING btree (activated_at DESC, catalog_id);


--
-- Name: book_metadata_scope_record_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX book_metadata_scope_record_unique ON lorkhan_internal.book_metadata USING btree (installation_id, playthrough_id, record_id);


--
-- Name: configuration_sets_one_global_settings_per_installation; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX configuration_sets_one_global_settings_per_installation ON lorkhan_internal.configuration_sets USING btree (installation_id) WHERE ((kind = 'global_settings'::text) AND (deleted_at IS NULL));


--
-- Name: configuration_sets_one_installation_action_policy; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX configuration_sets_one_installation_action_policy ON lorkhan_internal.configuration_sets USING btree (installation_id) WHERE ((kind = 'action_policy'::text) AND (profile_id IS NULL) AND (deleted_at IS NULL));


--
-- Name: configuration_sets_one_profile_action_policy; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX configuration_sets_one_profile_action_policy ON lorkhan_internal.configuration_sets USING btree (installation_id, profile_id) WHERE ((kind = 'action_policy'::text) AND (profile_id IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: content_manifest_files_active_name_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX content_manifest_files_active_name_idx ON lorkhan_internal.content_manifest_files USING btree (installation_id, lower((content_file)::text)) WHERE active;


--
-- Name: content_manifest_files_active_order_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX content_manifest_files_active_order_uq ON lorkhan_internal.content_manifest_files USING btree (installation_id, load_order) WHERE active;


--
-- Name: core_profile_presets_name; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX core_profile_presets_name ON lorkhan_internal.core_profile_presets USING btree (installation_id, lower(name));


--
-- Name: core_profiles_live_label_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX core_profiles_live_label_unique ON lorkhan_internal.core_profiles USING btree (installation_id, lower(label)) WHERE (deleted_at IS NULL);


--
-- Name: core_profiles_live_slot_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX core_profiles_live_slot_unique ON lorkhan_internal.core_profiles USING btree (installation_id, slot) WHERE ((deleted_at IS NULL) AND (slot IS NOT NULL));


--
-- Name: core_profiles_one_default_npc; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX core_profiles_one_default_npc ON lorkhan_internal.core_profiles USING btree (installation_id) WHERE ((deleted_at IS NULL) AND default_npc);


--
-- Name: core_tts_pronunciation_unique_entry; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX core_tts_pronunciation_unique_entry ON lorkhan_internal.core_tts_pronunciation USING btree (lower((source_text)::text), md5(((((lower((npc_names)::text) || ''::text) || lower((races)::text)) || ''::text) || lower((oghma_tags)::text))), is_builtin);


--
-- Name: debug_commands_session_queue; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX debug_commands_session_queue ON lorkhan_internal.debug_commands USING btree (session_id, generation, state, created_at);


--
-- Name: description_catalog_entries_canonical_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX description_catalog_entries_canonical_uq ON lorkhan_internal.description_catalog_entries USING btree (catalog_id, lower(plugin), lower((baseid)::text));


--
-- Name: description_catalogs_one_active_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX description_catalogs_one_active_uq ON lorkhan_internal.description_catalogs USING btree (state) WHERE (state = 'active'::text);


--
-- Name: description_catalogs_recent_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX description_catalogs_recent_idx ON lorkhan_internal.description_catalogs USING btree (activated_at DESC, catalog_id);


--
-- Name: dialogue_utterances_pending; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX dialogue_utterances_pending ON lorkhan_internal.dialogue_utterances USING btree (delivery_deadline_at, dialogue_message_id) WHERE (delivery_state = 'pending'::text);


--
-- Name: discovered_items_recent_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX discovered_items_recent_idx ON lorkhan_internal.discovered_items USING btree (installation_id, last_seen_at DESC);


--
-- Name: durable_job_attempts_job_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX durable_job_attempts_job_order ON lorkhan_internal.durable_job_attempts USING btree (job_id, attempt_number DESC);


--
-- Name: durable_jobs_claim_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX durable_jobs_claim_order ON lorkhan_internal.durable_jobs USING btree (priority DESC, next_run_at, created_at, job_id) WHERE (state = ANY (ARRAY['queued'::text, 'leased'::text]));


--
-- Name: eventlog_metadata_dialogue; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX eventlog_metadata_dialogue ON lorkhan_internal.eventlog_metadata USING btree (dialogue_message_id) WHERE (dialogue_message_id IS NOT NULL);


--
-- Name: eventlog_metadata_scope_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX eventlog_metadata_scope_order ON lorkhan_internal.eventlog_metadata USING btree (installation_id, playthrough_id, rowid DESC) WHERE (suppressed_at IS NULL);


--
-- Name: eventlog_metadata_source; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX eventlog_metadata_source ON lorkhan_internal.eventlog_metadata USING btree (source_event_id) WHERE (source_event_id IS NOT NULL);


--
-- Name: eventlog_metadata_turn_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX eventlog_metadata_turn_order ON lorkhan_internal.eventlog_metadata USING btree (turn_id, rowid) WHERE (turn_id IS NOT NULL);


--
-- Name: global_settings_presets_name; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX global_settings_presets_name ON lorkhan_internal.global_settings_presets USING btree (installation_id, lower(name));


--
-- Name: installation_provider_selections_configuration; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX installation_provider_selections_configuration ON lorkhan_internal.installation_provider_selections USING btree (configuration_id);


--
-- Name: item_descriptions_identity_active_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX item_descriptions_identity_active_uq ON lorkhan_internal.item_descriptions USING btree (installation_id, lower((content_file)::text), lower((record_id)::text)) WHERE (deleted_at IS NULL);


--
-- Name: item_descriptions_lookup_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX item_descriptions_lookup_idx ON lorkhan_internal.item_descriptions USING btree (installation_id, lower((record_id)::text)) WHERE (deleted_at IS NULL);


--
-- Name: knowledge_custom_topic_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX knowledge_custom_topic_uq ON lorkhan_internal.knowledge_documents USING btree (installation_id, COALESCE(profile_id, '00000000-0000-0000-0000-000000000000'::text), COALESCE(playthrough_id, '00000000-0000-0000-0000-000000000000'::uuid), lower((topic)::text)) WHERE ((deleted_at IS NULL) AND ((provenance ->> 'source'::text) IS DISTINCT FROM 'factory-oghma'::text));


--
-- Name: knowledge_factory_mod_source_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX knowledge_factory_mod_source_idx ON lorkhan_internal.knowledge_documents USING btree (installation_id, lower(COALESCE((provenance ->> 'mod_source'::text), ''::text))) WHERE ((deleted_at IS NULL) AND ((provenance ->> 'source'::text) = 'factory-oghma'::text));


--
-- Name: knowledge_factory_topic_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX knowledge_factory_topic_uq ON lorkhan_internal.knowledge_documents USING btree (installation_id, lower((topic)::text)) WHERE ((deleted_at IS NULL) AND ((provenance ->> 'source'::text) = 'factory-oghma'::text));


--
-- Name: knowledge_scope; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX knowledge_scope ON lorkhan_internal.knowledge_documents USING btree (installation_id, profile_id, playthrough_id) WHERE (deleted_at IS NULL);


--
-- Name: media_objects_dialogue_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX media_objects_dialogue_unique ON lorkhan_internal.media_objects USING btree (dialogue_message_id) WHERE (dialogue_message_id IS NOT NULL);


--
-- Name: media_objects_menu_dialogue_message_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX media_objects_menu_dialogue_message_unique ON lorkhan_internal.media_objects USING btree (menu_dialogue_message_id) WHERE (menu_dialogue_message_id IS NOT NULL);


--
-- Name: media_objects_owner_expiry; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX media_objects_owner_expiry ON lorkhan_internal.media_objects USING btree (installation_id, session_id, generation, expires_at) WHERE (deleted_at IS NULL);


--
-- Name: memory_embeddings_policy_revision; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX memory_embeddings_policy_revision ON lorkhan_internal.memory_embeddings USING btree (policy_configuration_id, policy_revision, memory_id);


--
-- Name: memory_records_derivation_key_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX memory_records_derivation_key_unique ON lorkhan_internal.memory_records USING btree (installation_id, profile_id, playthrough_id, derivation_key) WHERE ((derivation_key IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: memory_scope_tier; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX memory_scope_tier ON lorkhan_internal.memory_records USING btree (installation_id, profile_id, playthrough_id, tier, occurred_at DESC) WHERE (deleted_at IS NULL);


--
-- Name: narrative_records_derivation_key_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX narrative_records_derivation_key_unique ON lorkhan_internal.narrative_records USING btree (installation_id, profile_id, playthrough_id, kind, derivation_key) WHERE ((derivation_key IS NOT NULL) AND (deleted_at IS NULL));


--
-- Name: narrative_scope; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX narrative_scope ON lorkhan_internal.narrative_records USING btree (installation_id, profile_id, playthrough_id, kind) WHERE (deleted_at IS NULL);


--
-- Name: npc_evolution_reports_profile; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX npc_evolution_reports_profile ON lorkhan_internal.npc_evolution_reports USING btree (profile_id, created_at);


--
-- Name: npc_memory_digests_scope; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX npc_memory_digests_scope ON lorkhan_internal.npc_memory_digests USING btree (installation_id, playthrough_id, profile_id, revision DESC);


--
-- Name: npc_reference_groups_match_name; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX npc_reference_groups_match_name ON lorkhan_internal.npc_reference_groups USING btree (installation_id, lower(btrim(match_name))) WHERE (btrim(match_name) <> ''::text);


--
-- Name: oghma_catalog_entries_topic_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX oghma_catalog_entries_topic_uq ON lorkhan_internal.oghma_catalog_entries USING btree (catalog_id, lower((topic)::text));


--
-- Name: oghma_catalogs_one_active_uq; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX oghma_catalogs_one_active_uq ON lorkhan_internal.oghma_catalogs USING btree (state) WHERE (state = 'active'::text);


--
-- Name: oghma_catalogs_recent_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX oghma_catalogs_recent_idx ON lorkhan_internal.oghma_catalogs USING btree (activated_at DESC, catalog_id);


--
-- Name: oghma_dynamic_active_key; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX oghma_dynamic_active_key ON lorkhan_internal.oghma_dynamic USING btree (installation_id, id_quest, stage, topic) WHERE (deleted_at IS NULL);


--
-- Name: oghma_factory_documents_catalog_idx; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX oghma_factory_documents_catalog_idx ON lorkhan_internal.oghma_factory_documents USING btree (catalog_id, installation_id);


--
-- Name: one_memory_embedding_policy_per_installation; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX one_memory_embedding_policy_per_installation ON lorkhan_internal.configuration_sets USING btree (installation_id) WHERE ((kind = 'memory_embedding_policy'::text) AND (deleted_at IS NULL));


--
-- Name: one_memory_policy_per_installation; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX one_memory_policy_per_installation ON lorkhan_internal.configuration_sets USING btree (installation_id) WHERE ((kind = 'memory_policy'::text) AND (deleted_at IS NULL));


--
-- Name: one_translation_policy_per_installation; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX one_translation_policy_per_installation ON lorkhan_internal.configuration_sets USING btree (installation_id) WHERE ((kind = 'translation_policy'::text) AND (deleted_at IS NULL));


--
-- Name: pairing_tokens_one_active; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX pairing_tokens_one_active ON lorkhan_internal.pairing_tokens USING btree (installation_id) WHERE (state = 'active'::text);


--
-- Name: physical_diary_one_pending; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX physical_diary_one_pending ON lorkhan_internal.physical_diary_deliveries USING btree (session_id, book_id) WHERE (state = 'delivered'::text);


--
-- Name: physical_diary_session_profile; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX physical_diary_session_profile ON lorkhan_internal.physical_diary_deliveries USING btree (session_id, profile_id, created_at DESC);


--
-- Name: player_speech_style_drafts_profile; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX player_speech_style_drafts_profile ON lorkhan_internal.player_speech_style_drafts USING btree (profile_id);


--
-- Name: playthrough_associations_pending_character; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX playthrough_associations_pending_character ON lorkhan_internal.playthrough_associations USING btree (installation_id, character_id) WHERE (state = 'pending'::text);


--
-- Name: playthrough_associations_pending_target; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX playthrough_associations_pending_target ON lorkhan_internal.playthrough_associations USING btree (installation_id, to_playthrough_id) WHERE (state = 'pending'::text);


--
-- Name: playthrough_saves_scope; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX playthrough_saves_scope ON lorkhan_internal.playthrough_saves USING btree (installation_id, created_at DESC);


--
-- Name: profile_assignment_rules_runtime; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX profile_assignment_rules_runtime ON lorkhan_internal.profile_assignment_rules USING btree (installation_id, priority DESC, created_at, rule_id) WHERE enabled;


--
-- Name: profiles_core_profile; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX profiles_core_profile ON lorkhan_internal.profiles USING btree (installation_id, core_profile_id) WHERE (deleted_at IS NULL);


--
-- Name: prompt_trace_sections_playthrough_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX prompt_trace_sections_playthrough_order ON lorkhan_internal.prompt_trace_sections USING btree (playthrough_id, prompt_trace_id, section_order);


--
-- Name: provider_attempts_job_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX provider_attempts_job_order ON lorkhan_internal.provider_attempts USING btree (job_id, started_at, provider_attempt_id) WHERE (job_id IS NOT NULL);


--
-- Name: provider_attempts_request_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX provider_attempts_request_order ON lorkhan_internal.provider_attempts USING btree (request_id, started_at, provider_attempt_id);


--
-- Name: quest_metadata_scope_journal_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX quest_metadata_scope_journal_unique ON lorkhan_internal.quest_metadata USING btree (installation_id, playthrough_id, journal_id);


--
-- Name: questlog_metadata_scope_entry_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX questlog_metadata_scope_entry_unique ON lorkhan_internal.questlog_metadata USING btree (installation_id, playthrough_id, journal_id, entry_hash);


--
-- Name: rechat_one_open_chain_per_session; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX rechat_one_open_chain_per_session ON lorkhan_internal.rechat_chains USING btree (session_id, generation) WHERE (state = ANY (ARRAY['open'::text, 'awaiting_playback'::text, 'request_in_flight'::text]));


--
-- Name: relationship_audit_record_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX relationship_audit_record_order ON lorkhan_internal.relationship_audit USING btree (relationship_id, audit_sequence DESC);


--
-- Name: relationship_build_scope_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX relationship_build_scope_order ON lorkhan_internal.durable_jobs USING btree (((payload ->> 'installation_id'::text)), ((payload ->> 'profile_id'::text)), ((payload ->> 'playthrough_id'::text)), created_at DESC, job_id DESC) WHERE (job_type = 'relationship.build'::text);


--
-- Name: relationship_conversion_batch_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX relationship_conversion_batch_order ON lorkhan_internal.durable_jobs USING btree (((payload ->> 'installation_id'::text)), ((payload ->> 'playthrough_id'::text)), ((payload ->> 'request_id'::text)), created_at, job_id) WHERE (job_type = 'relationship.convert'::text);


--
-- Name: relationship_identity_scope; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX relationship_identity_scope ON lorkhan_internal.relationship_records USING btree (installation_id, profile_id, playthrough_id, md5((lorkhan_internal.relationship_identity_key(actor_identity))::text));


--
-- Name: relationship_scope; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX relationship_scope ON lorkhan_internal.relationship_records USING btree (installation_id, profile_id, playthrough_id) WHERE (deleted_at IS NULL);


--
-- Name: request_mac_nonces_created; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX request_mac_nonces_created ON lorkhan_internal.request_mac_nonces USING btree (created_at);


--
-- Name: responselog_pending; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX responselog_pending ON lorkhan_internal.responselog USING btree (installation_id, session_id, rowid) WHERE (sent = 0);


--
-- Name: retrieval_traces_turn_section; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX retrieval_traces_turn_section ON lorkhan_internal.retrieval_traces USING btree (turn_id, prompt_section, created_at);


--
-- Name: scene_classifications_scope; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX scene_classifications_scope ON lorkhan_internal.scene_classifications USING btree (installation_id, playthrough_id, profile_id, observed_at DESC);


--
-- Name: sessions_one_active_installation; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX sessions_one_active_installation ON lorkhan_internal.sessions USING btree (installation_id) WHERE (state = 'active'::text);


--
-- Name: sessions_runtime_generation_key; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX sessions_runtime_generation_key ON lorkhan_internal.sessions USING btree (installation_id, generation) WHERE (NOT archived);


--
-- Name: source_events_session_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX source_events_session_order ON lorkhan_internal.source_events USING btree (session_id, received_at, source_event_id);


--
-- Name: speech_connector_voices_discovered; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX speech_connector_voices_discovered ON lorkhan_internal.speech_connector_voices USING btree (configuration_id, discovered_at DESC);


--
-- Name: speech_playthrough_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX speech_playthrough_order ON lorkhan_internal.speech USING btree (installation_id, playthrough_id, gamets, ts, rowid);


--
-- Name: speech_turn_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX speech_turn_order ON lorkhan_internal.speech USING btree (turn_id, rowid) WHERE (turn_id IS NOT NULL);


--
-- Name: stt_requests_storage_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX stt_requests_storage_unique ON lorkhan_internal.stt_requests USING btree (storage_media_id) WHERE (storage_media_id IS NOT NULL);


--
-- Name: turns_response_id_unique; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE UNIQUE INDEX turns_response_id_unique ON lorkhan_internal.turns USING btree (response_id) WHERE (response_id IS NOT NULL);


--
-- Name: turns_session_order; Type: INDEX; Schema: lorkhan_internal; Owner: -
--

CREATE INDEX turns_session_order ON lorkhan_internal.turns USING btree (session_id, accepted_at, turn_id);


--
-- Name: chim_harness_audit_memory_created_at_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX chim_harness_audit_memory_created_at_idx ON public.audit_memory USING btree (created_at);


--
-- Name: core_profiles_slot_unique_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX core_profiles_slot_unique_idx ON public.core_profiles USING btree (slot) WHERE (slot IS NOT NULL);


--
-- Name: event_log_type; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX event_log_type ON public.eventlog USING btree (type);


--
-- Name: idx_core_action_action_name_lower; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_action_name_lower ON public.core_action USING btree (lower((action_name)::text));


--
-- Name: idx_core_action_available_to_followers; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_available_to_followers ON public.core_action USING btree (available_to_followers);


--
-- Name: idx_core_action_available_to_narrator; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_available_to_narrator ON public.core_action USING btree (available_to_narrator);


--
-- Name: idx_core_action_available_to_npc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_available_to_npc ON public.core_action USING btree (available_to_npc);


--
-- Name: idx_core_action_code_name_lower; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_code_name_lower ON public.core_action USING btree (lower((code_name)::text));


--
-- Name: idx_core_action_custom_action_name_lower; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_custom_action_name_lower ON public.core_action_custom USING btree (lower((action_name)::text));


--
-- Name: idx_core_action_custom_available_to_followers; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_custom_available_to_followers ON public.core_action_custom USING btree (available_to_followers);


--
-- Name: idx_core_action_custom_available_to_narrator; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_custom_available_to_narrator ON public.core_action_custom USING btree (available_to_narrator);


--
-- Name: idx_core_action_custom_available_to_npc; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_custom_available_to_npc ON public.core_action_custom USING btree (available_to_npc);


--
-- Name: idx_core_action_custom_code_name_lower; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_custom_code_name_lower ON public.core_action_custom USING btree (lower((code_name)::text));


--
-- Name: idx_core_action_custom_game_function; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_custom_game_function ON public.core_action_custom USING btree (game_function);


--
-- Name: idx_core_action_custom_is_activated; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_custom_is_activated ON public.core_action_custom USING btree (is_activated);


--
-- Name: idx_core_action_game_function; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_game_function ON public.core_action USING btree (game_function);


--
-- Name: idx_core_action_is_activated; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_action_is_activated ON public.core_action USING btree (is_activated);


--
-- Name: idx_core_api_badge_label_lower; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_core_api_badge_label_lower ON public.core_api_badge USING btree (lower(label));


--
-- Name: idx_diarylog_people_gamets; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_diarylog_people_gamets ON public.diarylog USING btree (lower(TRIM(BOTH FROM people)), gamets DESC, localts DESC, rowid DESC);


--
-- Name: idx_eventlog_delivery_state; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eventlog_delivery_state ON public.eventlog USING btree (delivery_state);


--
-- Name: idx_eventlog_gamets_pos; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eventlog_gamets_pos ON public.eventlog USING btree (gamets) WHERE (gamets > 0);


--
-- Name: idx_eventlog_gamets_ts_pos; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eventlog_gamets_ts_pos ON public.eventlog USING btree (gamets DESC, ts DESC);


--
-- Name: idx_eventlog_people_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eventlog_people_trgm ON public.eventlog USING gin (people public.gin_trgm_ops);


--
-- Name: idx_eventlog_people_trgm2; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eventlog_people_trgm2 ON public.eventlog USING gin (data public.gin_trgm_ops);


--
-- Name: idx_eventlog_utterance_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_eventlog_utterance_id ON public.eventlog USING btree (utterance_id);


--
-- Name: idx_oghma_context_rule_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_oghma_context_rule_active ON public.oghma_context_rule USING btree (enabled, priority, id);


--
-- Name: idx_prompts_prompt_key_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX idx_prompts_prompt_key_unique ON public.prompts USING btree (prompt_key);


--
-- Name: idx_speech_gamets_pos; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_speech_gamets_pos ON public.speech USING btree (gamets) WHERE (gamets > 0);


--
-- Name: idx_speech_listener_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_speech_listener_trgm ON public.speech USING gin (listener public.gin_trgm_ops);


--
-- Name: idx_speech_speaker_trgm; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_speech_speaker_trgm ON public.speech USING gin (speaker public.gin_trgm_ops);


--
-- Name: idx_speech_utterance_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_speech_utterance_id ON public.speech USING btree (utterance_id);


--
-- Name: oghma_native_vector_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX oghma_native_vector_idx ON public.oghma USING gin (native_vector);


--
-- Name: physical_npc_diaries_updated_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX physical_npc_diaries_updated_idx ON public.physical_npc_diaries USING btree (updated_at DESC);


--
-- Name: search_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX search_idx ON public.translations USING btree (source_word);


--
-- Name: book_metadata guard_timeline_book_projection; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_book_projection AFTER INSERT OR UPDATE ON lorkhan_internal.book_metadata FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();


--
-- Name: currentmission_metadata guard_timeline_currentmission; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_currentmission AFTER INSERT OR UPDATE ON lorkhan_internal.currentmission_metadata FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();


--
-- Name: eventlog_metadata guard_timeline_event; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_event BEFORE INSERT OR UPDATE ON lorkhan_internal.eventlog_metadata FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_event();


--
-- Name: memory_records guard_timeline_memory; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_memory BEFORE INSERT OR UPDATE ON lorkhan_internal.memory_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_memory();


--
-- Name: narrative_records guard_timeline_narrative; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_narrative BEFORE INSERT OR UPDATE ON lorkhan_internal.narrative_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_narrative();


--
-- Name: quest_metadata guard_timeline_quest; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_quest AFTER INSERT OR UPDATE ON lorkhan_internal.quest_metadata FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();


--
-- Name: questlog_metadata guard_timeline_questlog; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_questlog AFTER INSERT OR UPDATE ON lorkhan_internal.questlog_metadata FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();


--
-- Name: speech_metadata guard_timeline_speech_projection; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER guard_timeline_speech_projection AFTER INSERT OR UPDATE ON lorkhan_internal.speech_metadata FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_speech_projection();


--
-- Name: action_intents herika_project_action; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_action AFTER INSERT OR UPDATE ON lorkhan_internal.action_intents FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_action_projection();


--
-- Name: action_catalog herika_project_action_catalog; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_action_catalog AFTER INSERT OR DELETE OR UPDATE ON lorkhan_internal.action_catalog FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_action_catalog_projection();


--
-- Name: configuration_sets herika_project_configuration; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_configuration AFTER INSERT OR UPDATE ON lorkhan_internal.configuration_sets FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_configuration_projection();


--
-- Name: configuration_revisions herika_project_configuration_revision; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_configuration_revision AFTER INSERT OR UPDATE ON lorkhan_internal.configuration_revisions FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_configuration_projection();


--
-- Name: core_profiles herika_project_core_profile; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_core_profile AFTER INSERT OR UPDATE ON lorkhan_internal.core_profiles FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_core_profile_projection();


--
-- Name: core_profile_revisions herika_project_core_profile_revision; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_core_profile_revision AFTER INSERT OR UPDATE ON lorkhan_internal.core_profile_revisions FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_core_profile_projection();


--
-- Name: dialogue_utterances herika_project_dialogue_audit; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_dialogue_audit AFTER INSERT OR DELETE OR UPDATE ON lorkhan_internal.dialogue_utterances FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_turn_audit();


--
-- Name: item_descriptions herika_project_item_description; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_item_description AFTER INSERT OR DELETE OR UPDATE ON lorkhan_internal.item_descriptions FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_description_projection();


--
-- Name: knowledge_documents herika_project_knowledge; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_knowledge AFTER INSERT OR UPDATE ON lorkhan_internal.knowledge_documents FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_knowledge_projection();


--
-- Name: memory_records herika_project_memory; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_memory AFTER INSERT OR UPDATE ON lorkhan_internal.memory_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_memory_projection();


--
-- Name: narrative_records herika_project_narrative; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_narrative AFTER INSERT OR UPDATE ON lorkhan_internal.narrative_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_narrative_projection();


--
-- Name: profiles herika_project_profile; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_profile AFTER INSERT OR UPDATE ON lorkhan_internal.profiles FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_profile_projection();


--
-- Name: profile_revisions herika_project_profile_revision; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_profile_revision AFTER INSERT OR UPDATE ON lorkhan_internal.profile_revisions FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_profile_projection();


--
-- Name: prompts herika_project_prompt; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_prompt AFTER INSERT OR DELETE OR UPDATE ON lorkhan_internal.prompts FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_prompt_projection();


--
-- Name: provider_attempts herika_project_provider_attempt; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_provider_attempt AFTER INSERT OR UPDATE ON lorkhan_internal.provider_attempts FOR EACH ROW WHEN ((new.turn_id IS NOT NULL)) EXECUTE FUNCTION lorkhan_internal.trigger_turn_audit();


--
-- Name: relationship_records herika_project_relationship; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_relationship AFTER INSERT OR DELETE OR UPDATE ON lorkhan_internal.relationship_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_relationship_projection();


--
-- Name: responselog herika_project_responselog; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_responselog AFTER INSERT OR DELETE OR UPDATE ON lorkhan_internal.responselog FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_responselog_row();


--
-- Name: speech herika_project_speech; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_speech AFTER INSERT OR DELETE OR UPDATE ON lorkhan_internal.speech FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_speech_row();


--
-- Name: turn_provider_snapshots herika_project_turn_snapshot; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_turn_snapshot AFTER INSERT OR UPDATE ON lorkhan_internal.turn_provider_snapshots FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_turn_audit();


--
-- Name: turns herika_project_turn_world; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_project_turn_world AFTER INSERT ON lorkhan_internal.turns FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_turn_world_projection();


--
-- Name: action_intents herika_remove_action; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_remove_action BEFORE DELETE ON lorkhan_internal.action_intents FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.remove_action_projection();


--
-- Name: configuration_sets herika_remove_configuration; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_remove_configuration BEFORE DELETE ON lorkhan_internal.configuration_sets FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_configuration_removal();


--
-- Name: core_profiles herika_remove_core_profile; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_remove_core_profile BEFORE DELETE ON lorkhan_internal.core_profiles FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_core_profile_removal();


--
-- Name: knowledge_documents herika_remove_knowledge; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_remove_knowledge BEFORE DELETE ON lorkhan_internal.knowledge_documents FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.remove_knowledge_projection();


--
-- Name: memory_records herika_remove_memory; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_remove_memory BEFORE DELETE ON lorkhan_internal.memory_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.remove_memory_projection();


--
-- Name: narrative_records herika_remove_narrative; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_remove_narrative BEFORE DELETE ON lorkhan_internal.narrative_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.remove_narrative_projection();


--
-- Name: profiles herika_remove_profile; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER herika_remove_profile BEFORE DELETE ON lorkhan_internal.profiles FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.trigger_profile_removal();


--
-- Name: installations installations_seed_reference_groups; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER installations_seed_reference_groups AFTER INSERT ON lorkhan_internal.installations FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.seed_npc_reference_groups();


--
-- Name: memory_records memory_record_initial_revision_capture; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER memory_record_initial_revision_capture AFTER INSERT ON lorkhan_internal.memory_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.capture_memory_record_initial_revision();


--
-- Name: memory_records memory_record_revision_capture; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER memory_record_revision_capture BEFORE UPDATE OF tier, content, source_event_id, provenance, occurred_at, deleted_at ON lorkhan_internal.memory_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.capture_memory_record_revision();


--
-- Name: relationship_records relationship_revision; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER relationship_revision BEFORE UPDATE ON lorkhan_internal.relationship_records FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.advance_relationship_revision();


--
-- Name: turns turns_openmw_record_identity; Type: TRIGGER; Schema: lorkhan_internal; Owner: -
--

CREATE TRIGGER turns_openmw_record_identity AFTER INSERT ON lorkhan_internal.turns FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.sync_openmw_record_identity();


--
-- Name: action_catalog_metadata action_catalog_metadata_action_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_catalog_metadata
    ADD CONSTRAINT action_catalog_metadata_action_id_fkey FOREIGN KEY (action_id) REFERENCES public.core_action_custom(id) ON DELETE CASCADE;


--
-- Name: action_delivery action_delivery_action_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_delivery
    ADD CONSTRAINT action_delivery_action_id_fkey FOREIGN KEY (action_id) REFERENCES lorkhan_internal.action_intents(action_id) ON DELETE CASCADE;


--
-- Name: action_delivery action_delivery_continuation_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_delivery
    ADD CONSTRAINT action_delivery_continuation_turn_id_fkey FOREIGN KEY (continuation_turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: action_intents action_intents_policy_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_intents
    ADD CONSTRAINT action_intents_policy_configuration_id_fkey FOREIGN KEY (policy_configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id);


--
-- Name: action_intents action_intents_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_intents
    ADD CONSTRAINT action_intents_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: action_intents action_intents_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_intents
    ADD CONSTRAINT action_intents_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: action_issued_metadata action_issued_metadata_action_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_issued_metadata
    ADD CONSTRAINT action_issued_metadata_action_id_fkey FOREIGN KEY (action_id) REFERENCES lorkhan_internal.action_intents(action_id) ON DELETE CASCADE;


--
-- Name: action_issued_metadata action_issued_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_issued_metadata
    ADD CONSTRAINT action_issued_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.actions_issued(rowid) ON DELETE CASCADE;


--
-- Name: action_issued_metadata action_issued_metadata_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_issued_metadata
    ADD CONSTRAINT action_issued_metadata_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: action_issued_metadata action_issued_metadata_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_issued_metadata
    ADD CONSTRAINT action_issued_metadata_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: action_results action_results_action_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_results
    ADD CONSTRAINT action_results_action_id_fkey FOREIGN KEY (action_id) REFERENCES lorkhan_internal.action_intents(action_id);


--
-- Name: action_results action_results_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.action_results
    ADD CONSTRAINT action_results_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: actor_profile_bindings actor_profile_bindings_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.actor_profile_bindings
    ADD CONSTRAINT actor_profile_bindings_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: actor_profile_bindings actor_profile_bindings_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.actor_profile_bindings
    ADD CONSTRAINT actor_profile_bindings_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: actor_profile_bindings actor_profile_bindings_profile_id_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.actor_profile_bindings
    ADD CONSTRAINT actor_profile_bindings_profile_id_installation_id_fkey FOREIGN KEY (profile_id, installation_id) REFERENCES lorkhan_internal.profiles(profile_id, installation_id);


--
-- Name: audit_request_metadata audit_request_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: audit_request_metadata audit_request_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: audit_request_metadata audit_request_metadata_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE SET NULL;


--
-- Name: audit_request_metadata audit_request_metadata_prompt_trace_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_prompt_trace_id_fkey FOREIGN KEY (prompt_trace_id) REFERENCES lorkhan_internal.prompt_traces(prompt_trace_id) ON DELETE SET NULL;


--
-- Name: audit_request_metadata audit_request_metadata_provider_attempt_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_provider_attempt_id_fkey FOREIGN KEY (provider_attempt_id) REFERENCES lorkhan_internal.provider_attempts(provider_attempt_id) ON DELETE SET NULL;


--
-- Name: audit_request_metadata audit_request_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.audit_request(rowid) ON DELETE CASCADE;


--
-- Name: audit_request_metadata audit_request_metadata_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: audit_request_metadata audit_request_metadata_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.audit_request_metadata
    ADD CONSTRAINT audit_request_metadata_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: biography_catalog_entries biography_catalog_entries_catalog_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.biography_catalog_entries
    ADD CONSTRAINT biography_catalog_entries_catalog_id_fkey FOREIGN KEY (catalog_id) REFERENCES lorkhan_internal.biography_catalogs(catalog_id) ON DELETE CASCADE;


--
-- Name: biography_catalogs biography_catalogs_previous_catalog_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.biography_catalogs
    ADD CONSTRAINT biography_catalogs_previous_catalog_id_fkey FOREIGN KEY (previous_catalog_id) REFERENCES lorkhan_internal.biography_catalogs(catalog_id);


--
-- Name: book_metadata book_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.book_metadata
    ADD CONSTRAINT book_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: book_metadata book_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.book_metadata
    ADD CONSTRAINT book_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: book_metadata book_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.book_metadata
    ADD CONSTRAINT book_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.books(rowid) ON DELETE CASCADE;


--
-- Name: book_metadata book_metadata_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.book_metadata
    ADD CONSTRAINT book_metadata_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: book_metadata book_metadata_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.book_metadata
    ADD CONSTRAINT book_metadata_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: character_playthrough_bindings character_playthrough_bindings_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.character_playthrough_bindings
    ADD CONSTRAINT character_playthrough_bindings_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: character_playthrough_bindings character_playthrough_bindings_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.character_playthrough_bindings
    ADD CONSTRAINT character_playthrough_bindings_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE RESTRICT;


--
-- Name: configuration_revisions configuration_revisions_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.configuration_revisions
    ADD CONSTRAINT configuration_revisions_configuration_id_fkey FOREIGN KEY (configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id) ON DELETE CASCADE;


--
-- Name: configuration_sets configuration_sets_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.configuration_sets
    ADD CONSTRAINT configuration_sets_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: configuration_sets configuration_sets_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.configuration_sets
    ADD CONSTRAINT configuration_sets_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: content_manifest_files content_manifest_files_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifest_files
    ADD CONSTRAINT content_manifest_files_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: content_manifest_files content_manifest_files_source_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifest_files
    ADD CONSTRAINT content_manifest_files_source_session_id_fkey FOREIGN KEY (source_session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: content_manifest_files content_manifest_files_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifest_files
    ADD CONSTRAINT content_manifest_files_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: content_manifests content_manifests_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifests
    ADD CONSTRAINT content_manifests_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: content_manifests content_manifests_source_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifests
    ADD CONSTRAINT content_manifests_source_session_id_fkey FOREIGN KEY (source_session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: content_manifests content_manifests_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.content_manifests
    ADD CONSTRAINT content_manifests_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: core_profile_metadata core_profile_metadata_core_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_metadata
    ADD CONSTRAINT core_profile_metadata_core_profile_id_fkey FOREIGN KEY (core_profile_id) REFERENCES public.core_profiles(id) ON DELETE CASCADE;


--
-- Name: core_profile_metadata core_profile_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_metadata
    ADD CONSTRAINT core_profile_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: core_profile_metadata core_profile_metadata_source_core_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_metadata
    ADD CONSTRAINT core_profile_metadata_source_core_profile_id_fkey FOREIGN KEY (source_core_profile_id) REFERENCES lorkhan_internal.core_profiles(core_profile_id) ON DELETE CASCADE;


--
-- Name: core_profile_presets core_profile_presets_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_presets
    ADD CONSTRAINT core_profile_presets_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: core_profile_revisions core_profile_revisions_core_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profile_revisions
    ADD CONSTRAINT core_profile_revisions_core_profile_id_fkey FOREIGN KEY (core_profile_id) REFERENCES lorkhan_internal.core_profiles(core_profile_id) ON DELETE CASCADE;


--
-- Name: core_profiles core_profiles_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.core_profiles
    ADD CONSTRAINT core_profiles_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: currentmission_metadata currentmission_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.currentmission_metadata
    ADD CONSTRAINT currentmission_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: currentmission_metadata currentmission_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.currentmission_metadata
    ADD CONSTRAINT currentmission_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: currentmission_metadata currentmission_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.currentmission_metadata
    ADD CONSTRAINT currentmission_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.currentmission(rowid) ON DELETE CASCADE;


--
-- Name: currentmission_metadata currentmission_metadata_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.currentmission_metadata
    ADD CONSTRAINT currentmission_metadata_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: debug_commands debug_commands_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.debug_commands
    ADD CONSTRAINT debug_commands_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: debug_commands debug_commands_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.debug_commands
    ADD CONSTRAINT debug_commands_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: description_catalog_entries description_catalog_entries_catalog_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_catalog_entries
    ADD CONSTRAINT description_catalog_entries_catalog_id_fkey FOREIGN KEY (catalog_id) REFERENCES lorkhan_internal.description_catalogs(catalog_id) ON DELETE CASCADE;


--
-- Name: description_catalogs description_catalogs_previous_catalog_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_catalogs
    ADD CONSTRAINT description_catalogs_previous_catalog_id_fkey FOREIGN KEY (previous_catalog_id) REFERENCES lorkhan_internal.description_catalogs(catalog_id);


--
-- Name: description_metadata description_metadata_description_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_metadata
    ADD CONSTRAINT description_metadata_description_id_fkey FOREIGN KEY (description_id) REFERENCES lorkhan_internal.item_descriptions(description_id) ON DELETE CASCADE;


--
-- Name: description_metadata description_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.description_metadata
    ADD CONSTRAINT description_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: dialogue_delivery_results dialogue_delivery_results_dialogue_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_dialogue_fk FOREIGN KEY (dialogue_message_id) REFERENCES lorkhan_internal.dialogue_utterances(dialogue_message_id) NOT VALID;


--
-- Name: dialogue_delivery_results dialogue_delivery_results_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: dialogue_delivery_results dialogue_delivery_results_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: dialogue_delivery_results dialogue_delivery_results_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: dialogue_utterances dialogue_utterances_expiry_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_utterances
    ADD CONSTRAINT dialogue_utterances_expiry_job_id_fkey FOREIGN KEY (expiry_job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: dialogue_utterances dialogue_utterances_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_utterances
    ADD CONSTRAINT dialogue_utterances_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: dialogue_utterances dialogue_utterances_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.dialogue_utterances
    ADD CONSTRAINT dialogue_utterances_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: diarylog_metadata diarylog_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.diarylog_metadata
    ADD CONSTRAINT diarylog_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: diarylog_metadata diarylog_metadata_narrative_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.diarylog_metadata
    ADD CONSTRAINT diarylog_metadata_narrative_id_fkey FOREIGN KEY (narrative_id) REFERENCES lorkhan_internal.narrative_records(narrative_id) ON DELETE CASCADE;


--
-- Name: diarylog_metadata diarylog_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.diarylog_metadata
    ADD CONSTRAINT diarylog_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: diarylog_metadata diarylog_metadata_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.diarylog_metadata
    ADD CONSTRAINT diarylog_metadata_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE SET NULL;


--
-- Name: diarylog_metadata diarylog_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.diarylog_metadata
    ADD CONSTRAINT diarylog_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.diarylog(rowid) ON DELETE CASCADE;


--
-- Name: director_instructions director_instructions_plan_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_instructions
    ADD CONSTRAINT director_instructions_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES lorkhan_internal.director_plans(plan_id) ON DELETE CASCADE;


--
-- Name: director_plans director_plans_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_plans
    ADD CONSTRAINT director_plans_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: director_plans director_plans_origin_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_plans
    ADD CONSTRAINT director_plans_origin_turn_id_fkey FOREIGN KEY (origin_turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: director_plans director_plans_plan_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_plans
    ADD CONSTRAINT director_plans_plan_id_fkey FOREIGN KEY (plan_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: director_plans director_plans_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.director_plans
    ADD CONSTRAINT director_plans_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: discovered_items discovered_items_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.discovered_items
    ADD CONSTRAINT discovered_items_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: discovered_items discovered_items_source_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.discovered_items
    ADD CONSTRAINT discovered_items_source_session_id_fkey FOREIGN KEY (source_session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: discovered_items discovered_items_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.discovered_items
    ADD CONSTRAINT discovered_items_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: disposition_adjustments disposition_adjustments_confirmed_source_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_confirmed_source_id_fkey FOREIGN KEY (confirmed_source_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: disposition_adjustments disposition_adjustments_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: disposition_adjustments disposition_adjustments_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: disposition_adjustments disposition_adjustments_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id);


--
-- Name: disposition_adjustments disposition_adjustments_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: disposition_adjustments disposition_adjustments_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.disposition_adjustments
    ADD CONSTRAINT disposition_adjustments_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: durable_job_attempts durable_job_attempts_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_attempts
    ADD CONSTRAINT durable_job_attempts_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE;


--
-- Name: durable_job_dead_letters durable_job_dead_letters_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_dead_letters
    ADD CONSTRAINT durable_job_dead_letters_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE;


--
-- Name: durable_job_dead_letters durable_job_dead_letters_replay_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.durable_job_dead_letters
    ADD CONSTRAINT durable_job_dead_letters_replay_job_id_fkey FOREIGN KEY (replay_job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: eventlog_hidden_types eventlog_hidden_types_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_hidden_types
    ADD CONSTRAINT eventlog_hidden_types_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: eventlog_metadata eventlog_metadata_dialogue_message_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_dialogue_message_id_fkey FOREIGN KEY (dialogue_message_id) REFERENCES lorkhan_internal.dialogue_utterances(dialogue_message_id) ON DELETE SET NULL;


--
-- Name: eventlog_metadata eventlog_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: eventlog_metadata eventlog_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: eventlog_metadata eventlog_metadata_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE SET NULL;


--
-- Name: eventlog_metadata eventlog_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.eventlog(rowid) ON DELETE CASCADE;


--
-- Name: eventlog_metadata eventlog_metadata_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: eventlog_metadata eventlog_metadata_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.eventlog_metadata
    ADD CONSTRAINT eventlog_metadata_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id) ON DELETE SET NULL;


--
-- Name: faction_metadata faction_metadata_formid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.faction_metadata
    ADD CONSTRAINT faction_metadata_formid_fkey FOREIGN KEY (formid) REFERENCES public.factions(formid) ON DELETE CASCADE;


--
-- Name: faction_metadata faction_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.faction_metadata
    ADD CONSTRAINT faction_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: faction_metadata faction_metadata_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.faction_metadata
    ADD CONSTRAINT faction_metadata_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: game_dispositions game_dispositions_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_dispositions
    ADD CONSTRAINT game_dispositions_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: game_dispositions game_dispositions_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_dispositions
    ADD CONSTRAINT game_dispositions_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id);


--
-- Name: game_dispositions game_dispositions_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_dispositions
    ADD CONSTRAINT game_dispositions_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: game_dispositions game_dispositions_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_dispositions
    ADD CONSTRAINT game_dispositions_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: game_plugin_metadata game_plugin_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_plugin_metadata
    ADD CONSTRAINT game_plugin_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: game_plugin_metadata game_plugin_metadata_plugin_name_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_plugin_metadata
    ADD CONSTRAINT game_plugin_metadata_plugin_name_fkey FOREIGN KEY (plugin_name) REFERENCES public.game_plugins(plugin_name) ON DELETE CASCADE;


--
-- Name: game_plugin_metadata game_plugin_metadata_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.game_plugin_metadata
    ADD CONSTRAINT game_plugin_metadata_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: general_setting_metadata general_setting_metadata_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.general_setting_metadata
    ADD CONSTRAINT general_setting_metadata_id_fkey FOREIGN KEY (id) REFERENCES public.general_settings(id) ON DELETE CASCADE;


--
-- Name: general_setting_metadata general_setting_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.general_setting_metadata
    ADD CONSTRAINT general_setting_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: general_setting_metadata general_setting_metadata_source_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.general_setting_metadata
    ADD CONSTRAINT general_setting_metadata_source_configuration_id_fkey FOREIGN KEY (source_configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id) ON DELETE CASCADE;


--
-- Name: global_settings_presets global_settings_presets_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.global_settings_presets
    ADD CONSTRAINT global_settings_presets_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: idempotency_requests idempotency_requests_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.idempotency_requests
    ADD CONSTRAINT idempotency_requests_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: installation_profile_preferences installation_profile_preferences_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.installation_profile_preferences
    ADD CONSTRAINT installation_profile_preferences_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: installation_provider_selections installation_provider_selecti_configuration_id_installatio_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.installation_provider_selections
    ADD CONSTRAINT installation_provider_selecti_configuration_id_installatio_fkey FOREIGN KEY (configuration_id, installation_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id, installation_id);


--
-- Name: installation_provider_selections installation_provider_selections_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.installation_provider_selections
    ADD CONSTRAINT installation_provider_selections_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: interruptions interruptions_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.interruptions
    ADD CONSTRAINT interruptions_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: interruptions interruptions_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.interruptions
    ADD CONSTRAINT interruptions_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: item_descriptions item_descriptions_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.item_descriptions
    ADD CONSTRAINT item_descriptions_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: knowledge_documents knowledge_documents_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.knowledge_documents
    ADD CONSTRAINT knowledge_documents_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: knowledge_documents knowledge_documents_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.knowledge_documents
    ADD CONSTRAINT knowledge_documents_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: knowledge_documents knowledge_documents_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.knowledge_documents
    ADD CONSTRAINT knowledge_documents_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: llm_connector_metadata llm_connector_metadata_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.llm_connector_metadata
    ADD CONSTRAINT llm_connector_metadata_configuration_id_fkey FOREIGN KEY (configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id) ON DELETE CASCADE;


--
-- Name: llm_connector_metadata llm_connector_metadata_connector_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.llm_connector_metadata
    ADD CONSTRAINT llm_connector_metadata_connector_id_fkey FOREIGN KEY (connector_id) REFERENCES public.core_llm_connector(id) ON DELETE CASCADE;


--
-- Name: llm_connector_metadata llm_connector_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.llm_connector_metadata
    ADD CONSTRAINT llm_connector_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: location_metadata location_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.location_metadata
    ADD CONSTRAINT location_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: location_metadata location_metadata_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.location_metadata
    ADD CONSTRAINT location_metadata_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: log_metadata log_metadata_prompt_trace_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.log_metadata
    ADD CONSTRAINT log_metadata_prompt_trace_id_fkey FOREIGN KEY (prompt_trace_id) REFERENCES lorkhan_internal.prompt_traces(prompt_trace_id) ON DELETE SET NULL;


--
-- Name: log_metadata log_metadata_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.log_metadata
    ADD CONSTRAINT log_metadata_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: media_objects media_objects_dialogue_message_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.media_objects
    ADD CONSTRAINT media_objects_dialogue_message_id_fkey FOREIGN KEY (dialogue_message_id) REFERENCES lorkhan_internal.dialogue_utterances(dialogue_message_id);


--
-- Name: media_objects media_objects_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.media_objects
    ADD CONSTRAINT media_objects_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: media_objects media_objects_menu_dialogue_message_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.media_objects
    ADD CONSTRAINT media_objects_menu_dialogue_message_id_fkey FOREIGN KEY (menu_dialogue_message_id) REFERENCES lorkhan_internal.menu_dialogue_tts_requests(message_id);


--
-- Name: media_objects media_objects_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.media_objects
    ADD CONSTRAINT media_objects_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: media_objects media_objects_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.media_objects
    ADD CONSTRAINT media_objects_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id);


--
-- Name: memory_embeddings memory_embeddings_memory_id_memory_revision_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_embeddings
    ADD CONSTRAINT memory_embeddings_memory_id_memory_revision_fkey FOREIGN KEY (memory_id, memory_revision) REFERENCES lorkhan_internal.memory_record_revisions(memory_id, revision) ON DELETE CASCADE;


--
-- Name: memory_embeddings memory_embeddings_policy_configuration_id_policy_revision_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_embeddings
    ADD CONSTRAINT memory_embeddings_policy_configuration_id_policy_revision_fkey FOREIGN KEY (policy_configuration_id, policy_revision) REFERENCES lorkhan_internal.configuration_revisions(configuration_id, revision);


--
-- Name: memory_metadata memory_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: memory_metadata memory_metadata_memory_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_memory_id_fkey FOREIGN KEY (memory_id) REFERENCES lorkhan_internal.memory_records(memory_id) ON DELETE CASCADE;


--
-- Name: memory_metadata memory_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: memory_metadata memory_metadata_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE SET NULL;


--
-- Name: memory_metadata memory_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.memory(rowid) ON DELETE CASCADE;


--
-- Name: memory_metadata memory_metadata_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_metadata
    ADD CONSTRAINT memory_metadata_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id) ON DELETE SET NULL;


--
-- Name: memory_model_summaries memory_model_summaries_memory_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_model_summaries
    ADD CONSTRAINT memory_model_summaries_memory_id_fkey FOREIGN KEY (memory_id) REFERENCES lorkhan_internal.memory_records(memory_id) ON DELETE CASCADE;


--
-- Name: memory_model_summaries memory_model_summaries_memory_id_memory_revision_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_model_summaries
    ADD CONSTRAINT memory_model_summaries_memory_id_memory_revision_fkey FOREIGN KEY (memory_id, memory_revision) REFERENCES lorkhan_internal.memory_record_revisions(memory_id, revision) ON DELETE CASCADE;


--
-- Name: memory_model_summaries memory_model_summaries_policy_configuration_id_policy_revi_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_model_summaries
    ADD CONSTRAINT memory_model_summaries_policy_configuration_id_policy_revi_fkey FOREIGN KEY (policy_configuration_id, policy_revision) REFERENCES lorkhan_internal.configuration_revisions(configuration_id, revision);


--
-- Name: memory_model_summaries memory_model_summaries_provider_configuration_id_provider__fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_model_summaries
    ADD CONSTRAINT memory_model_summaries_provider_configuration_id_provider__fkey FOREIGN KEY (provider_configuration_id, provider_revision) REFERENCES lorkhan_internal.configuration_revisions(configuration_id, revision);


--
-- Name: memory_record_revisions memory_record_revisions_memory_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_record_revisions
    ADD CONSTRAINT memory_record_revisions_memory_id_fkey FOREIGN KEY (memory_id) REFERENCES lorkhan_internal.memory_records(memory_id) ON DELETE CASCADE;


--
-- Name: memory_record_revisions memory_record_revisions_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_record_revisions
    ADD CONSTRAINT memory_record_revisions_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: memory_records memory_records_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_records
    ADD CONSTRAINT memory_records_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: memory_records memory_records_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_records
    ADD CONSTRAINT memory_records_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: memory_records memory_records_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_records
    ADD CONSTRAINT memory_records_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: memory_records memory_records_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_records
    ADD CONSTRAINT memory_records_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: memory_summary_metadata memory_summary_metadata_memory_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_summary_metadata
    ADD CONSTRAINT memory_summary_metadata_memory_id_fkey FOREIGN KEY (memory_id) REFERENCES lorkhan_internal.memory_records(memory_id) ON DELETE CASCADE;


--
-- Name: memory_summary_metadata memory_summary_metadata_rowid_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.memory_summary_metadata
    ADD CONSTRAINT memory_summary_metadata_rowid_fkey FOREIGN KEY (rowid) REFERENCES public.memory_summary(rowid) ON DELETE CASCADE;


--
-- Name: menu_dialogue_tts_requests menu_dialogue_tts_requests_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.menu_dialogue_tts_requests
    ADD CONSTRAINT menu_dialogue_tts_requests_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: menu_dialogue_tts_requests menu_dialogue_tts_requests_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.menu_dialogue_tts_requests
    ADD CONSTRAINT menu_dialogue_tts_requests_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id);


--
-- Name: menu_dialogue_tts_requests menu_dialogue_tts_requests_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.menu_dialogue_tts_requests
    ADD CONSTRAINT menu_dialogue_tts_requests_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: narrative_records narrative_records_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.narrative_records
    ADD CONSTRAINT narrative_records_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: narrative_records narrative_records_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.narrative_records
    ADD CONSTRAINT narrative_records_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: narrative_records narrative_records_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.narrative_records
    ADD CONSTRAINT narrative_records_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: npc_evolution_reports npc_evolution_reports_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_evolution_reports
    ADD CONSTRAINT npc_evolution_reports_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: npc_evolution_reports npc_evolution_reports_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_evolution_reports
    ADD CONSTRAINT npc_evolution_reports_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE;


--
-- Name: npc_evolution_reports npc_evolution_reports_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_evolution_reports
    ADD CONSTRAINT npc_evolution_reports_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: npc_memory_digests npc_memory_digests_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: npc_memory_digests npc_memory_digests_installation_id_playthrough_id_profile__fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_installation_id_playthrough_id_profile__fkey FOREIGN KEY (installation_id, playthrough_id, profile_id, previous_digest_id) REFERENCES lorkhan_internal.npc_memory_digests(installation_id, playthrough_id, profile_id, digest_id);


--
-- Name: npc_memory_digests npc_memory_digests_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE SET NULL;


--
-- Name: npc_memory_digests npc_memory_digests_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: npc_memory_digests npc_memory_digests_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_memory_digests
    ADD CONSTRAINT npc_memory_digests_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: npc_metadata npc_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_metadata
    ADD CONSTRAINT npc_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: npc_metadata npc_metadata_npc_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_metadata
    ADD CONSTRAINT npc_metadata_npc_id_fkey FOREIGN KEY (npc_id) REFERENCES public.core_npc_master(id) ON DELETE CASCADE;


--
-- Name: npc_metadata npc_metadata_source_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_metadata
    ADD CONSTRAINT npc_metadata_source_profile_id_fkey FOREIGN KEY (source_profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: npc_reference_groups npc_reference_groups_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.npc_reference_groups
    ADD CONSTRAINT npc_reference_groups_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: oghma_catalog_deletions oghma_catalog_deletions_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_catalog_deletions
    ADD CONSTRAINT oghma_catalog_deletions_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: oghma_catalog_entries oghma_catalog_entries_catalog_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_catalog_entries
    ADD CONSTRAINT oghma_catalog_entries_catalog_id_fkey FOREIGN KEY (catalog_id) REFERENCES lorkhan_internal.oghma_catalogs(catalog_id) ON DELETE CASCADE;


--
-- Name: oghma_catalogs oghma_catalogs_previous_catalog_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_catalogs
    ADD CONSTRAINT oghma_catalogs_previous_catalog_id_fkey FOREIGN KEY (previous_catalog_id) REFERENCES lorkhan_internal.oghma_catalogs(catalog_id);


--
-- Name: oghma_dynamic_applications oghma_dynamic_applications_document_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic_applications
    ADD CONSTRAINT oghma_dynamic_applications_document_id_fkey FOREIGN KEY (document_id) REFERENCES lorkhan_internal.knowledge_documents(document_id);


--
-- Name: oghma_dynamic_applications oghma_dynamic_applications_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic_applications
    ADD CONSTRAINT oghma_dynamic_applications_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: oghma_dynamic_applications oghma_dynamic_applications_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic_applications
    ADD CONSTRAINT oghma_dynamic_applications_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id);


--
-- Name: oghma_dynamic_applications oghma_dynamic_applications_rule_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic_applications
    ADD CONSTRAINT oghma_dynamic_applications_rule_id_fkey FOREIGN KEY (rule_id) REFERENCES lorkhan_internal.oghma_dynamic(id);


--
-- Name: oghma_dynamic_applications oghma_dynamic_applications_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic_applications
    ADD CONSTRAINT oghma_dynamic_applications_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: oghma_dynamic oghma_dynamic_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_dynamic
    ADD CONSTRAINT oghma_dynamic_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: oghma_factory_documents oghma_factory_documents_catalog_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_factory_documents
    ADD CONSTRAINT oghma_factory_documents_catalog_id_fkey FOREIGN KEY (catalog_id) REFERENCES lorkhan_internal.oghma_catalogs(catalog_id);


--
-- Name: oghma_factory_documents oghma_factory_documents_document_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_factory_documents
    ADD CONSTRAINT oghma_factory_documents_document_id_fkey FOREIGN KEY (document_id) REFERENCES lorkhan_internal.knowledge_documents(document_id) ON DELETE CASCADE;


--
-- Name: oghma_factory_documents oghma_factory_documents_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_factory_documents
    ADD CONSTRAINT oghma_factory_documents_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: oghma_installation_settings oghma_installation_settings_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_installation_settings
    ADD CONSTRAINT oghma_installation_settings_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: oghma_metadata oghma_metadata_document_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_metadata
    ADD CONSTRAINT oghma_metadata_document_id_fkey FOREIGN KEY (document_id) REFERENCES lorkhan_internal.knowledge_documents(document_id) ON DELETE CASCADE;


--
-- Name: oghma_metadata oghma_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_metadata
    ADD CONSTRAINT oghma_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: oghma_metadata oghma_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_metadata
    ADD CONSTRAINT oghma_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: oghma_metadata oghma_metadata_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_metadata
    ADD CONSTRAINT oghma_metadata_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE SET NULL;


--
-- Name: oghma_metadata oghma_metadata_topic_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.oghma_metadata
    ADD CONSTRAINT oghma_metadata_topic_fkey FOREIGN KEY (topic) REFERENCES public.oghma(topic) ON DELETE CASCADE;


--
-- Name: pairing_tokens pairing_tokens_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.pairing_tokens
    ADD CONSTRAINT pairing_tokens_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: physical_diary_deliveries physical_diary_deliveries_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.physical_diary_deliveries
    ADD CONSTRAINT physical_diary_deliveries_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: physical_diary_deliveries physical_diary_deliveries_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.physical_diary_deliveries
    ADD CONSTRAINT physical_diary_deliveries_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id);


--
-- Name: physical_diary_deliveries physical_diary_deliveries_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.physical_diary_deliveries
    ADD CONSTRAINT physical_diary_deliveries_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id);


--
-- Name: physical_diary_deliveries physical_diary_deliveries_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.physical_diary_deliveries
    ADD CONSTRAINT physical_diary_deliveries_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: player2_routing player2_routing_configuration_id_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.player2_routing
    ADD CONSTRAINT player2_routing_configuration_id_installation_id_fkey FOREIGN KEY (configuration_id, installation_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id, installation_id);


--
-- Name: player2_routing player2_routing_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.player2_routing
    ADD CONSTRAINT player2_routing_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: player_speech_style_drafts player_speech_style_drafts_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.player_speech_style_drafts
    ADD CONSTRAINT player_speech_style_drafts_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE;


--
-- Name: player_speech_style_drafts player_speech_style_drafts_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.player_speech_style_drafts
    ADD CONSTRAINT player_speech_style_drafts_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: playthrough_associations playthrough_associations_from_playthrough_id_installation__fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_associations
    ADD CONSTRAINT playthrough_associations_from_playthrough_id_installation__fkey FOREIGN KEY (from_playthrough_id, installation_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id, installation_id);


--
-- Name: playthrough_associations playthrough_associations_installation_id_character_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_associations
    ADD CONSTRAINT playthrough_associations_installation_id_character_id_fkey FOREIGN KEY (installation_id, character_id) REFERENCES lorkhan_internal.character_playthrough_bindings(installation_id, character_id);


--
-- Name: playthrough_associations playthrough_associations_to_playthrough_id_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_associations
    ADD CONSTRAINT playthrough_associations_to_playthrough_id_installation_id_fkey FOREIGN KEY (to_playthrough_id, installation_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id, installation_id);


--
-- Name: playthrough_local_state playthrough_local_state_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_local_state
    ADD CONSTRAINT playthrough_local_state_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: playthrough_local_state playthrough_local_state_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_local_state
    ADD CONSTRAINT playthrough_local_state_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: playthrough_revisions playthrough_revisions_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthrough_revisions
    ADD CONSTRAINT playthrough_revisions_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: playthroughs playthroughs_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthroughs
    ADD CONSTRAINT playthroughs_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: playthroughs playthroughs_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthroughs
    ADD CONSTRAINT playthroughs_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id);


--
-- Name: playthroughs playthroughs_profile_installation_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.playthroughs
    ADD CONSTRAINT playthroughs_profile_installation_fk FOREIGN KEY (profile_id, installation_id) REFERENCES lorkhan_internal.profiles(profile_id, installation_id);


--
-- Name: profile_assignment_rules profile_assignment_rules_core_profile_id_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_assignment_rules
    ADD CONSTRAINT profile_assignment_rules_core_profile_id_installation_id_fkey FOREIGN KEY (core_profile_id, installation_id) REFERENCES lorkhan_internal.core_profiles(core_profile_id, installation_id);


--
-- Name: profile_assignment_rules profile_assignment_rules_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_assignment_rules
    ADD CONSTRAINT profile_assignment_rules_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: profile_evolution_clocks profile_evolution_clocks_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_clocks
    ADD CONSTRAINT profile_evolution_clocks_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: profile_evolution_clocks profile_evolution_clocks_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_clocks
    ADD CONSTRAINT profile_evolution_clocks_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: profile_evolution_events profile_evolution_events_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_events
    ADD CONSTRAINT profile_evolution_events_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: profile_evolution_events profile_evolution_events_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_events
    ADD CONSTRAINT profile_evolution_events_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: profile_evolution_progress profile_evolution_progress_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_progress
    ADD CONSTRAINT profile_evolution_progress_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: profile_evolution_progress profile_evolution_progress_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_evolution_progress
    ADD CONSTRAINT profile_evolution_progress_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: profile_revisions profile_revisions_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profile_revisions
    ADD CONSTRAINT profile_revisions_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: profiles profiles_core_profile_installation_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profiles
    ADD CONSTRAINT profiles_core_profile_installation_fk FOREIGN KEY (core_profile_id, installation_id) REFERENCES lorkhan_internal.core_profiles(core_profile_id, installation_id);


--
-- Name: profiles profiles_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profiles
    ADD CONSTRAINT profiles_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: profiles profiles_playthrough_owner_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.profiles
    ADD CONSTRAINT profiles_playthrough_owner_fk FOREIGN KEY (playthrough_id, installation_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id, installation_id) DEFERRABLE INITIALLY DEFERRED;


--
-- Name: prompt_metadata prompt_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_metadata
    ADD CONSTRAINT prompt_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: prompt_metadata prompt_metadata_prompt_key_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_metadata
    ADD CONSTRAINT prompt_metadata_prompt_key_fkey FOREIGN KEY (prompt_key) REFERENCES public.prompts(prompt_key) ON DELETE CASCADE;


--
-- Name: prompt_metadata prompt_metadata_source_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_metadata
    ADD CONSTRAINT prompt_metadata_source_configuration_id_fkey FOREIGN KEY (source_configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id) ON DELETE SET NULL;


--
-- Name: prompt_trace_sections prompt_trace_sections_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_trace_sections
    ADD CONSTRAINT prompt_trace_sections_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: prompt_trace_sections prompt_trace_sections_prompt_trace_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_trace_sections
    ADD CONSTRAINT prompt_trace_sections_prompt_trace_id_fkey FOREIGN KEY (prompt_trace_id) REFERENCES lorkhan_internal.prompt_traces(prompt_trace_id) ON DELETE CASCADE;


--
-- Name: prompt_trace_sources prompt_trace_sources_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_trace_sources
    ADD CONSTRAINT prompt_trace_sources_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: prompt_trace_sources prompt_trace_sources_prompt_trace_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_trace_sources
    ADD CONSTRAINT prompt_trace_sources_prompt_trace_id_fkey FOREIGN KEY (prompt_trace_id) REFERENCES lorkhan_internal.prompt_traces(prompt_trace_id) ON DELETE CASCADE;


--
-- Name: prompt_traces prompt_traces_core_profile_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_core_profile_fk FOREIGN KEY (core_profile_id, installation_id) REFERENCES lorkhan_internal.core_profiles(core_profile_id, installation_id);


--
-- Name: prompt_traces prompt_traces_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: prompt_traces prompt_traces_playthrough_id_installation_id_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_playthrough_id_installation_id_profile_id_fkey FOREIGN KEY (playthrough_id, installation_id, profile_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id, installation_id, profile_id);


--
-- Name: prompt_traces prompt_traces_profile_id_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_profile_id_installation_id_fkey FOREIGN KEY (profile_id, installation_id) REFERENCES lorkhan_internal.profiles(profile_id, installation_id);


--
-- Name: prompt_traces prompt_traces_prompt_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_prompt_configuration_id_fkey FOREIGN KEY (prompt_configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id);


--
-- Name: prompt_traces prompt_traces_selected_profile_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_selected_profile_fk FOREIGN KEY (selected_profile_id, installation_id) REFERENCES lorkhan_internal.profiles(profile_id, installation_id);


--
-- Name: prompt_traces prompt_traces_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: prompt_traces prompt_traces_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: prompts prompts_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompts
    ADD CONSTRAINT prompts_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: prompts prompts_source_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.prompts
    ADD CONSTRAINT prompts_source_configuration_id_fkey FOREIGN KEY (source_configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id) ON DELETE SET NULL;


--
-- Name: provider_attempts provider_attempts_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.provider_attempts
    ADD CONSTRAINT provider_attempts_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: quest_metadata quest_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quest_metadata
    ADD CONSTRAINT quest_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: quest_metadata quest_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quest_metadata
    ADD CONSTRAINT quest_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: quest_metadata quest_metadata_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quest_metadata
    ADD CONSTRAINT quest_metadata_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: quest_metadata quest_metadata_source_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quest_metadata
    ADD CONSTRAINT quest_metadata_source_turn_id_fkey FOREIGN KEY (source_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: quickstart_local_llm quickstart_local_llm_configuration_id_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quickstart_local_llm
    ADD CONSTRAINT quickstart_local_llm_configuration_id_installation_id_fkey FOREIGN KEY (configuration_id, installation_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id, installation_id);


--
-- Name: quickstart_local_llm quickstart_local_llm_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.quickstart_local_llm
    ADD CONSTRAINT quickstart_local_llm_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: rechat_chains rechat_chains_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rechat_chains
    ADD CONSTRAINT rechat_chains_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: rechat_chains rechat_chains_latest_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rechat_chains
    ADD CONSTRAINT rechat_chains_latest_turn_id_fkey FOREIGN KEY (latest_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: rechat_chains rechat_chains_origin_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rechat_chains
    ADD CONSTRAINT rechat_chains_origin_turn_id_fkey FOREIGN KEY (origin_turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: rechat_chains rechat_chains_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rechat_chains
    ADD CONSTRAINT rechat_chains_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: rechat_chains rechat_chains_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.rechat_chains
    ADD CONSTRAINT rechat_chains_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE CASCADE;


--
-- Name: relationship_audit relationship_audit_relationship_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_audit
    ADD CONSTRAINT relationship_audit_relationship_id_fkey FOREIGN KEY (relationship_id) REFERENCES lorkhan_internal.relationship_records(relationship_id) ON DELETE CASCADE;


--
-- Name: relationship_audit relationship_audit_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_audit
    ADD CONSTRAINT relationship_audit_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: relationship_build_results relationship_build_results_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_build_results
    ADD CONSTRAINT relationship_build_results_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: relationship_conversion_results relationship_conversion_results_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_conversion_results
    ADD CONSTRAINT relationship_conversion_results_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: relationship_conversion_results relationship_conversion_results_owner_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_conversion_results
    ADD CONSTRAINT relationship_conversion_results_owner_profile_id_fkey FOREIGN KEY (owner_profile_id) REFERENCES lorkhan_internal.profiles(profile_id);


--
-- Name: relationship_evaluation_results relationship_evaluation_results_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_evaluation_results
    ADD CONSTRAINT relationship_evaluation_results_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: relationship_evaluation_results relationship_evaluation_results_relationship_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_evaluation_results
    ADD CONSTRAINT relationship_evaluation_results_relationship_id_fkey FOREIGN KEY (relationship_id) REFERENCES lorkhan_internal.relationship_records(relationship_id);


--
-- Name: relationship_evaluation_results relationship_evaluation_results_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_evaluation_results
    ADD CONSTRAINT relationship_evaluation_results_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: relationship_records relationship_records_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_records
    ADD CONSTRAINT relationship_records_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: relationship_records relationship_records_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_records
    ADD CONSTRAINT relationship_records_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: relationship_records relationship_records_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_records
    ADD CONSTRAINT relationship_records_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: relationship_records relationship_records_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_records
    ADD CONSTRAINT relationship_records_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: relationship_revisions relationship_revisions_relationship_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.relationship_revisions
    ADD CONSTRAINT relationship_revisions_relationship_id_fkey FOREIGN KEY (relationship_id) REFERENCES lorkhan_internal.relationship_records(relationship_id) ON DELETE CASCADE;


--
-- Name: request_log_hidden request_log_hidden_provider_attempt_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.request_log_hidden
    ADD CONSTRAINT request_log_hidden_provider_attempt_id_fkey FOREIGN KEY (provider_attempt_id) REFERENCES lorkhan_internal.provider_attempts(provider_attempt_id) ON DELETE CASCADE;


--
-- Name: request_mac_nonces request_mac_nonces_pairing_token_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.request_mac_nonces
    ADD CONSTRAINT request_mac_nonces_pairing_token_id_fkey FOREIGN KEY (pairing_token_id) REFERENCES lorkhan_internal.pairing_tokens(pairing_token_id) ON DELETE CASCADE;


--
-- Name: response_events response_events_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.response_events
    ADD CONSTRAINT response_events_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: responselog responselog_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog
    ADD CONSTRAINT responselog_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: responselog_metadata responselog_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog_metadata
    ADD CONSTRAINT responselog_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: responselog_metadata responselog_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog_metadata
    ADD CONSTRAINT responselog_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: responselog_metadata responselog_metadata_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog_metadata
    ADD CONSTRAINT responselog_metadata_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: responselog responselog_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog
    ADD CONSTRAINT responselog_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: responselog responselog_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.responselog
    ADD CONSTRAINT responselog_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: retrieval_traces retrieval_traces_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.retrieval_traces
    ADD CONSTRAINT retrieval_traces_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: retrieval_traces retrieval_traces_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.retrieval_traces
    ADD CONSTRAINT retrieval_traces_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: retrieval_traces retrieval_traces_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.retrieval_traces
    ADD CONSTRAINT retrieval_traces_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: retrieval_traces retrieval_traces_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.retrieval_traces
    ADD CONSTRAINT retrieval_traces_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: scene_classifications scene_classifications_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.scene_classifications
    ADD CONSTRAINT scene_classifications_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: scene_classifications scene_classifications_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.scene_classifications
    ADD CONSTRAINT scene_classifications_job_id_fkey FOREIGN KEY (job_id) REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE;


--
-- Name: scene_classifications scene_classifications_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.scene_classifications
    ADD CONSTRAINT scene_classifications_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: scene_classifications scene_classifications_profile_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.scene_classifications
    ADD CONSTRAINT scene_classifications_profile_id_fkey FOREIGN KEY (profile_id) REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE;


--
-- Name: scene_classifications scene_classifications_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.scene_classifications
    ADD CONSTRAINT scene_classifications_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE CASCADE;


--
-- Name: sessions sessions_character_binding_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.sessions
    ADD CONSTRAINT sessions_character_binding_fk FOREIGN KEY (installation_id, character_id) REFERENCES lorkhan_internal.character_playthrough_bindings(installation_id, character_id);


--
-- Name: sessions sessions_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.sessions
    ADD CONSTRAINT sessions_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: sessions sessions_playthrough_installation_profile_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.sessions
    ADD CONSTRAINT sessions_playthrough_installation_profile_fk FOREIGN KEY (playthrough_id, installation_id, profile_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id, installation_id, profile_id);


--
-- Name: sessions sessions_profile_installation_fk; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.sessions
    ADD CONSTRAINT sessions_profile_installation_fk FOREIGN KEY (profile_id, installation_id) REFERENCES lorkhan_internal.profiles(profile_id, installation_id);


--
-- Name: source_events source_events_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.source_events
    ADD CONSTRAINT source_events_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id);


--
-- Name: source_events source_events_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.source_events
    ADD CONSTRAINT source_events_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: speech_connector_voices speech_connector_voices_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_connector_voices
    ADD CONSTRAINT speech_connector_voices_configuration_id_fkey FOREIGN KEY (configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id) ON DELETE CASCADE;


--
-- Name: speech speech_dialogue_message_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech
    ADD CONSTRAINT speech_dialogue_message_id_fkey FOREIGN KEY (dialogue_message_id) REFERENCES lorkhan_internal.dialogue_utterances(dialogue_message_id) ON DELETE SET NULL;


--
-- Name: speech speech_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech
    ADD CONSTRAINT speech_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: speech_metadata speech_metadata_dialogue_message_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_metadata
    ADD CONSTRAINT speech_metadata_dialogue_message_id_fkey FOREIGN KEY (dialogue_message_id) REFERENCES lorkhan_internal.dialogue_utterances(dialogue_message_id) ON DELETE SET NULL;


--
-- Name: speech_metadata speech_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_metadata
    ADD CONSTRAINT speech_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: speech_metadata speech_metadata_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_metadata
    ADD CONSTRAINT speech_metadata_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: speech_metadata speech_metadata_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_metadata
    ADD CONSTRAINT speech_metadata_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: speech_metadata speech_metadata_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech_metadata
    ADD CONSTRAINT speech_metadata_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL;


--
-- Name: speech speech_playthrough_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech
    ADD CONSTRAINT speech_playthrough_id_fkey FOREIGN KEY (playthrough_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE;


--
-- Name: speech speech_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.speech
    ADD CONSTRAINT speech_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id) ON DELETE SET NULL;


--
-- Name: stt_requests stt_requests_processing_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.stt_requests
    ADD CONSTRAINT stt_requests_processing_job_id_fkey FOREIGN KEY (processing_job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: stt_requests stt_requests_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.stt_requests
    ADD CONSTRAINT stt_requests_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: timeline_invalidated_sources timeline_invalidated_sources_loaded_save_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.timeline_invalidated_sources
    ADD CONSTRAINT timeline_invalidated_sources_loaded_save_id_fkey FOREIGN KEY (loaded_save_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: timeline_invalidated_sources timeline_invalidated_sources_source_event_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.timeline_invalidated_sources
    ADD CONSTRAINT timeline_invalidated_sources_source_event_id_fkey FOREIGN KEY (source_event_id) REFERENCES lorkhan_internal.source_events(source_event_id) ON DELETE CASCADE;


--
-- Name: timeline_invalidated_turns timeline_invalidated_turns_loaded_save_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.timeline_invalidated_turns
    ADD CONSTRAINT timeline_invalidated_turns_loaded_save_id_fkey FOREIGN KEY (loaded_save_id) REFERENCES lorkhan_internal.source_events(source_event_id);


--
-- Name: timeline_invalidated_turns timeline_invalidated_turns_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.timeline_invalidated_turns
    ADD CONSTRAINT timeline_invalidated_turns_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE CASCADE;


--
-- Name: tts_connector_metadata tts_connector_metadata_configuration_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.tts_connector_metadata
    ADD CONSTRAINT tts_connector_metadata_configuration_id_fkey FOREIGN KEY (configuration_id) REFERENCES lorkhan_internal.configuration_sets(configuration_id) ON DELETE CASCADE;


--
-- Name: tts_connector_metadata tts_connector_metadata_connector_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.tts_connector_metadata
    ADD CONSTRAINT tts_connector_metadata_connector_id_fkey FOREIGN KEY (connector_id) REFERENCES public.core_tts_connector(id) ON DELETE CASCADE;


--
-- Name: tts_connector_metadata tts_connector_metadata_installation_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.tts_connector_metadata
    ADD CONSTRAINT tts_connector_metadata_installation_id_fkey FOREIGN KEY (installation_id) REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE;


--
-- Name: turn_provider_snapshots turn_provider_snapshots_turn_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.turn_provider_snapshots
    ADD CONSTRAINT turn_provider_snapshots_turn_id_fkey FOREIGN KEY (turn_id) REFERENCES lorkhan_internal.turns(turn_id) ON DELETE CASCADE;


--
-- Name: turns turns_processing_job_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.turns
    ADD CONSTRAINT turns_processing_job_id_fkey FOREIGN KEY (processing_job_id) REFERENCES lorkhan_internal.durable_jobs(job_id);


--
-- Name: turns turns_session_id_fkey; Type: FK CONSTRAINT; Schema: lorkhan_internal; Owner: -
--

ALTER TABLE ONLY lorkhan_internal.turns
    ADD CONSTRAINT turns_session_id_fkey FOREIGN KEY (session_id) REFERENCES lorkhan_internal.sessions(session_id);


--
-- Name: core_profiles fk_diary_connector; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT fk_diary_connector FOREIGN KEY (diary_connector_id) REFERENCES public.core_llm_connector(id);


--
-- Name: core_npc_master fk_profile_id; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_npc_master
    ADD CONSTRAINT fk_profile_id FOREIGN KEY (profile_id) REFERENCES public.core_profiles(id) ON DELETE SET NULL;


--
-- Name: import_rules import_rules_profile_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.import_rules
    ADD CONSTRAINT import_rules_profile_fkey FOREIGN KEY (profile) REFERENCES public.core_profiles(id);


--
-- Name: core_llm_connector llm_connector_api_badge_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_llm_connector
    ADD CONSTRAINT llm_connector_api_badge_id_fkey FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id);


--
-- Name: core_profiles profiles_llm_fallback_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_fallback_id_fkey FOREIGN KEY (llm_fallback_id) REFERENCES public.core_llm_connector(id);


--
-- Name: core_profiles profiles_llm_formatter_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_formatter_id_fkey FOREIGN KEY (llm_formatter_id) REFERENCES public.core_llm_connector(id);


--
-- Name: core_profiles profiles_llm_primary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_primary_id_fkey FOREIGN KEY (llm_primary_id) REFERENCES public.core_llm_connector(id);


--
-- Name: core_profiles profiles_llm_quaternary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_quaternary_id_fkey FOREIGN KEY (llm_quaternary_id) REFERENCES public.core_llm_connector(id);


--
-- Name: core_profiles profiles_llm_secondary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_secondary_id_fkey FOREIGN KEY (llm_secondary_id) REFERENCES public.core_llm_connector(id);


--
-- Name: core_profiles profiles_llm_tertiary_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_llm_tertiary_id_fkey FOREIGN KEY (llm_tertiary_id) REFERENCES public.core_llm_connector(id);


--
-- Name: core_profiles profiles_tts_connector_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_profiles
    ADD CONSTRAINT profiles_tts_connector_id_fkey FOREIGN KEY (tts_connector_id) REFERENCES public.core_tts_connector(id);


--
-- Name: core_stt_connector stt_connector_api_badge_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_stt_connector
    ADD CONSTRAINT stt_connector_api_badge_id_fkey FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id);


--
-- Name: core_tts_connector tts_connector_api_badge_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.core_tts_connector
    ADD CONSTRAINT tts_connector_api_badge_id_fkey FOREIGN KEY (api_badge_id) REFERENCES public.core_api_badge(id);
