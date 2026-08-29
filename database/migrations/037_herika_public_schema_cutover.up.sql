-- Promote the validated Herika contract to public and keep OpenMW transport state internal.
CREATE SCHEMA IF NOT EXISTS lorkhan_internal;

DO $cutover$
DECLARE
    object_name text;
    unexpected text;
    source_tables text[] := ARRAY[
        'action_catalog','action_delivery','action_intents','action_results','actor_profile_bindings',
        'autonomy_schedules','backup_records','browser_sessions','configuration_revisions','configuration_sets',
        'core_profile_revisions','core_profiles','dialogue_delivery_results','dialogue_utterances',
        'durable_job_attempts','durable_job_dead_letters','durable_jobs','eventlog_hidden_types',
        'eventlog_metadata','idempotency_requests','installation_profile_preferences',
        'installation_provider_selections','installations','interruptions','item_descriptions',
        'knowledge_documents','media_objects','memory_records','narrative_records','operational_audit',
        'pairing_tokens','playthrough_revisions','playthroughs','profile_revisions','profiles',
        'prompt_trace_sources','prompt_traces','prompts','provider_attempts','rate_limit_buckets',
        'rechat_chains','relationship_audit','relationship_records','request_mac_nonces','response_events',
        'responselog','retrieval_traces','sessions','source_events','speech','speech_connector_voices',
        'stt_requests','turn_provider_snapshots','turns'
    ];
BEGIN
    FOREACH object_name IN ARRAY source_tables LOOP
        IF to_regclass(format('public.%I',object_name)) IS NULL THEN
            RAISE EXCEPTION 'Missing LORKHAN source table public.%',object_name;
        END IF;
        EXECUTE format('ALTER TABLE public.%I SET SCHEMA lorkhan_internal',object_name);
    END LOOP;

    SELECT string_agg(c.relname,',' ORDER BY c.relname) INTO unexpected
    FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
    WHERE n.nspname='public' AND c.relkind IN ('r','p') AND c.relname<>'eventlog';
    IF unexpected IS NOT NULL THEN
        RAISE EXCEPTION 'Unclassified public tables remain before Herika promotion: %',unexpected;
    END IF;
END
$cutover$;

DO $companions$
DECLARE
    object_name text;
    companion_tables text[] := ARRAY[
        'action_catalog_metadata','action_issued_metadata','audit_request_metadata','book_metadata',
        'core_profile_metadata','currentmission_metadata','description_metadata','diarylog_metadata',
        'faction_metadata','game_plugin_metadata','general_setting_metadata','llm_connector_metadata',
        'location_metadata','log_metadata','memory_metadata','memory_summary_metadata','npc_metadata',
        'oghma_metadata','prompt_metadata','quest_metadata','questlog_metadata','responselog_metadata',
        'speech_metadata','tts_connector_metadata'
    ];
BEGIN
    FOREACH object_name IN ARRAY companion_tables LOOP
        IF to_regclass(format('herika_compat.%I',object_name)) IS NULL THEN
            RAISE EXCEPTION 'Missing LORKHAN companion table herika_compat.%',object_name;
        END IF;
        EXECUTE format('ALTER TABLE herika_compat.%I SET SCHEMA lorkhan_internal',object_name);
    END LOOP;
    ALTER VIEW herika_compat.lorkhan_core_profiles_source SET SCHEMA lorkhan_internal;
END
$companions$;

DO $herika_tables$
DECLARE
    object_name text;
    exact_tables text[] := ARRAY[
        'bio_templates','bio_templates_custom','core_api_badge','core_itt_connector','core_llm_connector',
        'core_narrator','core_npc_master','core_npc_master_history','core_player','core_profiles',
        'core_stt_connector','core_tts_connector','core_tts_fallback','general_settings','prompts',
        'responselog','speech','actions_issued','audit_memory','audit_request','conf_opts',
        'database_versioning','log','moods_issued','rolemaster','diarylog','memory','memory_summary',
        'oghma','oghma_dynamic','physical_npc_diaries','relationship_eval_queue',
        'relationship_init_queue','rumors','books','currentmission','descriptions','descriptions_custom',
        'factions','game_plugins','locations','named_cell','questlog','quests','animations',
        'animations_custom','core_action','core_action_custom','dynamic_bio','import_rules',
        'json_personalities','translations','bgl_history','core_faction_politics_development',
        'core_faction_politics_relation','core_faction_politics_state','faction_vanilla','market_cache',
        'master_packages','npc_commitments','npc_profile_backup','oghma_context_rule','visual_context',
        'quest_asset_group_members','quest_asset_groups','quest_asset_imports','quest_asset_packs',
        'quest_assets','quest_item_types','quest_npc_own_templates','quest_npc_templates','quest_outfits',
        'quest_weapons','skyrim_quest_action_outbox','skyrim_quest_beat_state',
        'skyrim_quest_definitions','skyrim_quest_events','skyrim_quest_instances','sneq_quests',
        'sneq_quests_saved'
    ];
BEGIN
    FOREACH object_name IN ARRAY exact_tables LOOP
        IF to_regclass(format('herika_compat.%I',object_name)) IS NULL THEN
            RAISE EXCEPTION 'Missing staged Herika table herika_compat.%',object_name;
        END IF;
        EXECUTE format('ALTER TABLE herika_compat.%I SET SCHEMA public',object_name);
    END LOOP;
END
$herika_tables$;

DO $herika_sequences$
DECLARE object_name text;
BEGIN
    FOR object_name IN
        SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='herika_compat' AND c.relkind='S' ORDER BY c.relname
    LOOP
        EXECUTE format('ALTER SEQUENCE herika_compat.%I SET SCHEMA public',object_name);
    END LOOP;
END
$herika_sequences$;

ALTER VIEW herika_compat.combined_animations SET SCHEMA public;
ALTER VIEW herika_compat.combined_bio_templates SET SCHEMA public;
ALTER VIEW herika_compat.combined_core_action SET SCHEMA public;
ALTER VIEW herika_compat.combined_descriptions SET SCHEMA public;
ALTER VIEW herika_compat.locations_v SET SCHEMA public;
ALTER VIEW herika_compat.memory_v SET SCHEMA public;
ALTER VIEW herika_compat.speech_view SET SCHEMA public;

-- PL/pgSQL source queries are parsed at execution time. Retarget only explicit
-- typed-source references, while unqualified names now resolve to canonical public data.
DO $functions$
DECLARE
    function_row record;
    definition text;
    object_name text;
    source_tables text[] := ARRAY[
        'action_catalog','action_delivery','action_intents','action_results','actor_profile_bindings',
        'autonomy_schedules','backup_records','browser_sessions','configuration_revisions','configuration_sets',
        'core_profile_revisions','core_profiles','dialogue_delivery_results','dialogue_utterances',
        'durable_job_attempts','durable_job_dead_letters','durable_jobs','eventlog_hidden_types',
        'eventlog_metadata','idempotency_requests','installation_profile_preferences',
        'installation_provider_selections','installations','interruptions','item_descriptions',
        'knowledge_documents','media_objects','memory_records','narrative_records','operational_audit',
        'pairing_tokens','playthrough_revisions','playthroughs','profile_revisions','profiles',
        'prompt_trace_sources','prompt_traces','prompts','provider_attempts','rate_limit_buckets',
        'rechat_chains','relationship_audit','relationship_records','request_mac_nonces','response_events',
        'responselog','retrieval_traces','sessions','source_events','speech','speech_connector_voices',
        'stt_requests','turn_provider_snapshots','turns'
    ];
BEGIN
    FOR function_row IN
        SELECT p.oid,p.proname,pg_get_function_identity_arguments(p.oid) AS arguments
        FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname='herika_compat' ORDER BY p.proname,p.oid
    LOOP
        definition := pg_get_functiondef(function_row.oid);
        FOREACH object_name IN ARRAY source_tables LOOP
            definition := replace(definition,'public.'||object_name,'lorkhan_internal.'||object_name);
        END LOOP;
        EXECUTE definition;
        EXECUTE format(
            'ALTER FUNCTION herika_compat.%I(%s) SET search_path TO public, lorkhan_internal, pg_temp',
            function_row.proname,function_row.arguments
        );
        EXECUTE format(
            'ALTER FUNCTION herika_compat.%I(%s) SET SCHEMA lorkhan_internal',
            function_row.proname,function_row.arguments
        );
    END LOOP;
END
$functions$;

DO $finish$
DECLARE unexpected text;
BEGIN
    SELECT string_agg(c.relname,',' ORDER BY c.relname) INTO unexpected
    FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
    WHERE n.nspname='herika_compat';
    IF unexpected IS NOT NULL THEN
        RAISE EXCEPTION 'Unexpected staged relations remain after cutover: %',unexpected;
    END IF;
END
$finish$;

DROP SCHEMA herika_compat;
