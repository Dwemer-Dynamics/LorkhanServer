-- Synchronize LORKHAN's revisioned Global -> Core Profile -> NPC model into the
-- exact Herika connector/profile tables while preserving UUID ownership separately.

CREATE TABLE herika_compat.general_setting_metadata (
    id text PRIMARY KEY REFERENCES herika_compat.general_settings(id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    source_configuration_id uuid NOT NULL REFERENCES public.configuration_sets(configuration_id) ON DELETE CASCADE,
    source_revision integer NOT NULL,
    setting_key text NOT NULL,
    UNIQUE (source_configuration_id,setting_key)
);

-- Stable source alias follows the typed UUID table when the final cutover moves it
-- out of public to make room for Herika's integer core_profiles contract.
CREATE VIEW herika_compat.lorkhan_core_profiles_source AS SELECT * FROM public.core_profiles;

INSERT INTO herika_compat.general_setting_metadata (
    id,installation_id,source_configuration_id,source_revision,setting_key
)
SELECT settings.id,configuration.installation_id,configuration.configuration_id,
       configuration.current_revision,entry.key
FROM public.configuration_sets configuration
JOIN public.configuration_revisions revision
  ON revision.configuration_id=configuration.configuration_id
 AND revision.revision=configuration.current_revision
CROSS JOIN LATERAL jsonb_each(revision.content) entry
JOIN herika_compat.general_settings settings ON settings.id='lorkhan.'||entry.key
WHERE configuration.kind='global_settings' AND configuration.deleted_at IS NULL
ON CONFLICT (id) DO UPDATE SET
    installation_id=EXCLUDED.installation_id,
    source_configuration_id=EXCLUDED.source_configuration_id,
    source_revision=EXCLUDED.source_revision,
    setting_key=EXCLUDED.setting_key;

CREATE OR REPLACE FUNCTION herika_compat.remove_configuration_projection(source_configuration uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_configuration_projection(source_configuration uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE source_row record;
DECLARE projected_connector_id integer;
BEGIN
    SELECT configuration.*,revision.content
    INTO source_row
    FROM public.configuration_sets configuration
    JOIN public.configuration_revisions revision
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
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_configuration_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    PERFORM sync_configuration_projection(NEW.configuration_id);
    RETURN NEW;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_configuration_removal()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    PERFORM remove_configuration_projection(OLD.configuration_id);
    RETURN OLD;
END
$function$;

CREATE TRIGGER herika_remove_configuration
BEFORE DELETE ON public.configuration_sets
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_configuration_removal();
CREATE TRIGGER herika_project_configuration
AFTER INSERT OR UPDATE ON public.configuration_sets
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_configuration_projection();
CREATE TRIGGER herika_project_configuration_revision
AFTER INSERT OR UPDATE ON public.configuration_revisions
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_configuration_projection();

CREATE OR REPLACE FUNCTION herika_compat.remove_core_profile_projection(source_profile uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_core_profile_projection(source_profile uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
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
    JOIN public.core_profile_revisions revision
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
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_core_profile_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    PERFORM sync_core_profile_projection(NEW.core_profile_id);
    RETURN NEW;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_core_profile_removal()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    PERFORM remove_core_profile_projection(OLD.core_profile_id);
    RETURN OLD;
END
$function$;

CREATE TRIGGER herika_remove_core_profile
BEFORE DELETE ON public.core_profiles
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_core_profile_removal();
CREATE TRIGGER herika_project_core_profile
AFTER INSERT OR UPDATE ON public.core_profiles
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_core_profile_projection();
CREATE TRIGGER herika_project_core_profile_revision
AFTER INSERT OR UPDATE ON public.core_profile_revisions
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_core_profile_projection();

CREATE OR REPLACE FUNCTION herika_compat.rebuild_special_profiles()
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    DELETE FROM core_player;
    INSERT INTO core_player (id,value)
    SELECT DISTINCT ON (entry.key) entry.key,
           CASE WHEN jsonb_typeof(entry.value)='string' THEN entry.value#>>'{}' ELSE entry.value::text END
    FROM public.profiles profile
    JOIN public.profile_revisions revision
      ON revision.profile_id=profile.profile_id AND revision.revision=profile.current_revision
    CROSS JOIN LATERAL jsonb_each(revision.content) entry
    WHERE profile.deleted_at IS NULL AND profile.actor_identity->>'kind'='player'
    ORDER BY entry.key,profile.created_at DESC,profile.profile_id;

    DELETE FROM core_narrator;
    INSERT INTO core_narrator (id,value)
    SELECT DISTINCT ON (entry.key) entry.key,
           CASE WHEN jsonb_typeof(entry.value)='string' THEN entry.value#>>'{}' ELSE entry.value::text END
    FROM public.profiles profile
    JOIN public.profile_revisions revision
      ON revision.profile_id=profile.profile_id AND revision.revision=profile.current_revision
    CROSS JOIN LATERAL jsonb_each(revision.content) entry
    WHERE profile.deleted_at IS NULL AND profile.actor_identity->>'kind'='narrator'
    ORDER BY entry.key,profile.created_at DESC,profile.profile_id;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.remove_npc_projection(source_profile uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE projected_id integer;
DECLARE projected_name text;
BEGIN
    SELECT metadata.npc_id,npc.npc_name INTO projected_id,projected_name
    FROM npc_metadata metadata
    JOIN core_npc_master npc ON npc.id=metadata.npc_id
    WHERE metadata.source_profile_id=source_profile;
    IF projected_id IS NOT NULL THEN
        DELETE FROM bio_templates_custom WHERE npc_name=projected_name;
        DELETE FROM npc_metadata WHERE npc_id=projected_id;
        DELETE FROM core_npc_master WHERE id=projected_id;
    END IF;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.sync_profile_projection(source_profile uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
DECLARE source_row record;
DECLARE projected_id integer;
DECLARE projected_name text;
DECLARE inherited_profile integer;
BEGIN
    SELECT profile.*,revision.content,revision.created_at AS revision_created_at
    INTO source_row
    FROM public.profiles profile
    JOIN public.profile_revisions revision
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
    IF COALESCE(source_row.actor_identity->>'kind','actor') NOT IN ('npc','actor') THEN
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
            source_row.content#>>'{voice,id}',source_row.actor_identity,source_row.content->>'gender',
            source_row.content->>'race',left(source_row.actor_identity->>'record_id',16),inherited_profile,0,
            jsonb_build_object('actor_identity',source_row.actor_identity,'lorkhan_profile',source_row.content),
            md5(source_row.content::text),source_row.content->>'core',
            source_row.actor_identity->>'content_file',source_row.content->>'tags'
        ) RETURNING id INTO projected_id;
        INSERT INTO npc_metadata (
            npc_id,installation_id,source_profile_id,source_revision,actor_identity
        ) VALUES (
            projected_id,source_row.installation_id,source_profile,
            source_row.current_revision,source_row.actor_identity
        );
    ELSE
        IF projected_name IS DISTINCT FROM source_row.name THEN
            DELETE FROM bio_templates_custom WHERE npc_name=projected_name;
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
            voiceid=source_row.content#>>'{voice,id}',metadata=source_row.actor_identity,
            gender=source_row.content->>'gender',race=source_row.content->>'race',
            refid=left(source_row.actor_identity->>'record_id',16),profile_id=inherited_profile,
            dynamic_profile=0,
            extended_data=jsonb_build_object('actor_identity',source_row.actor_identity,'lorkhan_profile',source_row.content),
            md5=md5(source_row.content::text),core=source_row.content->>'core',
            base=source_row.actor_identity->>'content_file',tags=source_row.content->>'tags'
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
           source_row.content#>>'{voice,id}',source_row.actor_identity,source_row.content->>'gender',
           source_row.content->>'race',left(source_row.actor_identity->>'record_id',16),inherited_profile,0,
           jsonb_build_object('actor_identity',source_row.actor_identity,'lorkhan_profile',source_row.content,
                              'lorkhan_revision',source_row.current_revision),
           md5(source_row.content::text),source_row.revision_created_at AT TIME ZONE 'UTC',
           source_row.content->>'core',source_row.actor_identity->>'content_file',source_row.content->>'tags'
    WHERE NOT EXISTS (
        SELECT 1 FROM core_npc_master_history history
        WHERE history.npc_id=projected_id
          AND history.extended_data->>'lorkhan_revision'=source_row.current_revision::text
    );

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
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_profile_projection()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    PERFORM sync_profile_projection(NEW.profile_id);
    RETURN NEW;
END
$function$;

CREATE OR REPLACE FUNCTION herika_compat.trigger_profile_removal()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = herika_compat, public, pg_temp
AS $function$
BEGIN
    PERFORM remove_npc_projection(OLD.profile_id);
    RETURN OLD;
END
$function$;

CREATE TRIGGER herika_remove_profile
BEFORE DELETE ON public.profiles
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_profile_removal();
CREATE TRIGGER herika_project_profile
AFTER INSERT OR UPDATE ON public.profiles
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_profile_projection();
CREATE TRIGGER herika_project_profile_revision
AFTER INSERT OR UPDATE ON public.profile_revisions
FOR EACH ROW EXECUTE FUNCTION herika_compat.trigger_profile_projection();

-- Re-run each current record once so upgrades from 026-030 gain the same normalized state.
SELECT herika_compat.sync_configuration_projection(configuration_id)
FROM public.configuration_sets ORDER BY created_at,configuration_id;
SELECT herika_compat.sync_core_profile_projection(core_profile_id)
FROM public.core_profiles ORDER BY created_at,core_profile_id;
SELECT herika_compat.sync_profile_projection(profile_id)
FROM public.profiles ORDER BY created_at,profile_id;
