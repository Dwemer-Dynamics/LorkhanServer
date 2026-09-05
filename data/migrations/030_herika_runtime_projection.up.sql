-- Keep the staged Herika data model current while the typed LORKHAN tables remain the
-- protocol-facing write model. Every projection runs in the source write transaction.

-- A response may legitimately correlate to a pre-turn STT request before the turn row
-- exists, matching the typed responselog contract while preserving the UUID as metadata.
ALTER TABLE herika_compat.responselog_metadata
    DROP CONSTRAINT IF EXISTS responselog_metadata_turn_id_fkey;

CREATE OR REPLACE FUNCTION herika_compat.sync_speech_row()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
$function$;

CREATE TRIGGER herika_project_speech
AFTER INSERT OR UPDATE OR DELETE ON public.speech
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_speech_row();

CREATE OR REPLACE FUNCTION herika_compat.sync_responselog_row()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
$function$;

CREATE TRIGGER herika_project_responselog
AFTER INSERT OR UPDATE OR DELETE ON public.responselog
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_responselog_row();

CREATE OR REPLACE FUNCTION herika_compat.remove_memory_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_memory_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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

    SELECT name INTO profile_name FROM public.profiles WHERE profile_id = NEW.profile_id;
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
$function$;

CREATE TRIGGER herika_remove_memory
BEFORE DELETE ON public.memory_records
FOR EACH ROW EXECUTE FUNCTION herika_compat.remove_memory_projection();
CREATE TRIGGER herika_project_memory
AFTER INSERT OR UPDATE ON public.memory_records
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_memory_projection();

CREATE OR REPLACE FUNCTION herika_compat.remove_knowledge_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE projected_topic text;
BEGIN
    SELECT topic INTO projected_topic FROM oghma_metadata WHERE document_id=OLD.document_id;
    IF projected_topic IS NOT NULL THEN
        DELETE FROM oghma_metadata WHERE topic=projected_topic;
        DELETE FROM oghma WHERE topic=projected_topic;
    END IF;
    RETURN OLD;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_knowledge_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
        projected_topic := NEW.title;
        IF EXISTS (SELECT 1 FROM oghma WHERE topic=projected_topic) THEN
            projected_topic := NEW.title||' ['||left(NEW.document_id::text,8)||']';
        END IF;
        INSERT INTO oghma (
            topic,topic_desc,native_vector,knowledge_class,topic_desc_basic,
            knowledge_class_basic,tags,category,aliases
        ) VALUES (
            projected_topic,NEW.content,to_tsvector('simple',NEW.content),'Morrowind',
            left(NEW.content,1000),'Morrowind',array_to_string(NEW.lexical_terms,','),
            COALESCE(NEW.provenance->>'category','LORKHAN'),''
        );
        INSERT INTO oghma_metadata (
            topic,document_id,installation_id,profile_id,playthrough_id
        ) VALUES (
            projected_topic,NEW.document_id,NEW.installation_id,NEW.profile_id,NEW.playthrough_id
        );
    ELSE
        UPDATE oghma SET
            topic_desc=NEW.content,native_vector=to_tsvector('simple',NEW.content),
            knowledge_class='Morrowind',topic_desc_basic=left(NEW.content,1000),
            knowledge_class_basic='Morrowind',tags=array_to_string(NEW.lexical_terms,','),
            category=COALESCE(NEW.provenance->>'category','LORKHAN')
        WHERE topic=projected_topic;
        UPDATE oghma_metadata SET
            installation_id=NEW.installation_id,profile_id=NEW.profile_id,
            playthrough_id=NEW.playthrough_id
        WHERE topic=projected_topic;
    END IF;
    RETURN NEW;
END
$function$;

CREATE TRIGGER herika_remove_knowledge
BEFORE DELETE ON public.knowledge_documents
FOR EACH ROW EXECUTE FUNCTION herika_compat.remove_knowledge_projection();
CREATE TRIGGER herika_project_knowledge
AFTER INSERT OR UPDATE ON public.knowledge_documents
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_knowledge_projection();

CREATE OR REPLACE FUNCTION herika_compat.remove_narrative_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE projected_rowid bigint;
BEGIN
    SELECT rowid INTO projected_rowid FROM diarylog_metadata WHERE narrative_id=OLD.narrative_id;
    IF projected_rowid IS NOT NULL THEN
        DELETE FROM diarylog_metadata WHERE rowid=projected_rowid;
        DELETE FROM diarylog WHERE rowid=projected_rowid;
    END IF;
    RETURN OLD;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_narrative_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
    SELECT name INTO profile_name FROM public.profiles WHERE profile_id=NEW.profile_id;
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
$function$;

CREATE TRIGGER herika_remove_narrative
BEFORE DELETE ON public.narrative_records
FOR EACH ROW EXECUTE FUNCTION herika_compat.remove_narrative_projection();
CREATE TRIGGER herika_project_narrative
AFTER INSERT OR UPDATE ON public.narrative_records
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_narrative_projection();

CREATE OR REPLACE FUNCTION herika_compat.refresh_npc_relationships(source_profile uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE relationships_json jsonb;
BEGIN
    SELECT jsonb_object_agg(
        COALESCE(r.actor_identity->>'record_id',r.actor_identity->>'display_name',r.relationship_id::text),
        jsonb_build_object(
            'name',COALESCE(r.actor_identity->>'display_name',r.actor_identity->>'record_id'),
            'affinity',r.affinity,'disposition',r.disposition,'source',r.source_mode
        )
    ) INTO relationships_json
    FROM public.relationship_records r
    WHERE r.profile_id=source_profile AND r.deleted_at IS NULL;

    UPDATE core_npc_master npc SET
        relationships=CASE WHEN relationships_json IS NULL THEN NULL ELSE relationships_json::text END,
        extended_data=(npc.extended_data-'relationships')||
            CASE WHEN relationships_json IS NULL THEN '{}'::jsonb
                 ELSE jsonb_build_object('relationships',relationships_json) END
    FROM npc_metadata metadata
    WHERE metadata.source_profile_id=source_profile AND npc.id=metadata.npc_id;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_relationship_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
$function$;

CREATE TRIGGER herika_project_relationship
AFTER INSERT OR UPDATE OR DELETE ON public.relationship_records
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_relationship_projection();

CREATE OR REPLACE FUNCTION herika_compat.remove_action_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE projected_rowid integer;
BEGIN
    SELECT rowid INTO projected_rowid FROM action_issued_metadata WHERE action_id=OLD.action_id;
    IF projected_rowid IS NOT NULL THEN
        DELETE FROM action_issued_metadata WHERE rowid=projected_rowid;
        DELETE FROM actions_issued WHERE rowid=projected_rowid;
    END IF;
    RETURN OLD;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_action_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE projected_rowid integer;
DECLARE game_time numeric;
BEGIN
    SELECT rowid INTO projected_rowid FROM action_issued_metadata WHERE action_id=NEW.action_id;
    SELECT COALESCE((se.payload#>>'{context,world,game_time}')::numeric,0)
    INTO game_time
    FROM public.source_events se
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
$function$;

CREATE TRIGGER herika_remove_action
BEFORE DELETE ON public.action_intents
FOR EACH ROW EXECUTE FUNCTION herika_compat.remove_action_projection();
CREATE TRIGGER herika_project_action
AFTER INSERT OR UPDATE ON public.action_intents
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_action_projection();

CREATE OR REPLACE FUNCTION herika_compat.refresh_turn_audit(source_turn uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
              FROM public.dialogue_utterances utterance WHERE utterance.turn_id=snapshot.turn_id) AS response
    INTO source_row
    FROM public.turn_provider_snapshots snapshot
    JOIN public.turns turn_row ON turn_row.turn_id=snapshot.turn_id
    JOIN public.sessions session_row ON session_row.session_id=turn_row.session_id
    LEFT JOIN public.prompt_traces trace ON trace.turn_id=snapshot.turn_id
    LEFT JOIN LATERAL (
        SELECT provider.* FROM public.provider_attempts provider
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
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_turn_audit()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    PERFORM refresh_turn_audit(CASE WHEN TG_OP='DELETE' THEN OLD.turn_id ELSE NEW.turn_id END);
    RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END
$function$;

CREATE TRIGGER herika_project_turn_snapshot
AFTER INSERT OR UPDATE ON public.turn_provider_snapshots
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_turn_audit();
CREATE TRIGGER herika_project_provider_attempt
AFTER INSERT OR UPDATE ON public.provider_attempts
FOR EACH ROW WHEN (NEW.turn_id IS NOT NULL)
EXECUTE FUNCTION herika_compat.trigger_turn_audit();
CREATE TRIGGER herika_project_dialogue_audit
AFTER INSERT OR UPDATE OR DELETE ON public.dialogue_utterances
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_turn_audit();

-- Public prompts are already the typed runtime's normalized prompt projection. Mirror
-- them exactly into Herika's prompt table and keep installation ownership in metadata.
CREATE OR REPLACE FUNCTION herika_compat.sync_prompt_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
$function$;

CREATE TRIGGER herika_project_prompt
AFTER INSERT OR UPDATE OR DELETE ON public.prompts
FOR EACH ROW EXECUTE FUNCTION herika_compat.sync_prompt_projection();
