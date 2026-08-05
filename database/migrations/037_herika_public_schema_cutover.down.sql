CREATE SCHEMA herika_compat;

ALTER VIEW public.combined_animations SET SCHEMA herika_compat;
ALTER VIEW public.combined_bio_templates SET SCHEMA herika_compat;
ALTER VIEW public.combined_core_action SET SCHEMA herika_compat;
ALTER VIEW public.combined_descriptions SET SCHEMA herika_compat;
ALTER VIEW public.locations_v SET SCHEMA herika_compat;
ALTER VIEW public.memory_v SET SCHEMA herika_compat;
ALTER VIEW public.speech_view SET SCHEMA herika_compat;

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
        EXECUTE format('ALTER TABLE public.%I SET SCHEMA herika_compat',object_name);
    END LOOP;
END
$herika_tables$;

DO $herika_sequences$
DECLARE object_name text;
DECLARE exact_sequences text[] := ARRAY[
    'actions_issued_rowid_seq','api_badge_id_seq','audit_request_rowid_seq','books_rowid_seq',
    'core_npc_master_history_history_id_seq','core_tts_fallback_id_seq','currentmission_rowid_seq',
    'diarylog_rowid_seq','itt_connector_id_seq','llm_connector_id_seq','log_rowid_seq','memory_rowid_seq',
    'memory_summary_rowid_seq','memory_uid_seq','npc_master_id_seq','oghma_dynamic_id_seq',
    'profiles_id_seq','questlog_rowid_seq','quests_rowid_seq','relationship_eval_queue_id_seq',
    'relationship_init_queue_id_seq','responselog_rowid_seq','rolemaster_rowid_seq','rumors_id_seq',
    'speech_rowid_seq','stt_connector_id_seq','tts_connector_id_seq','core_action_id_seq',
    'core_action_custom_id_seq','dynamic_bio_id_seq','import_rules_id_seq','translations_id_seq',
    'bgl_history_rowid_seq','core_faction_politics_development_id_seq','npc_commitments_id_seq',
    'oghma_context_rule_id_seq','visual_context_id_seq','quest_asset_imports_id_seq',
    'skyrim_quest_action_outbox_id_seq','skyrim_quest_events_id_seq','sneq_quests_saved_history_id_seq'
];
BEGIN
    FOREACH object_name IN ARRAY exact_sequences LOOP
        IF to_regclass(format('public.%I',object_name)) IS NOT NULL THEN
            EXECUTE format('ALTER SEQUENCE public.%I SET SCHEMA herika_compat',object_name);
        END IF;
    END LOOP;
END
$herika_sequences$;

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
        EXECUTE format('ALTER TABLE almsivi_internal.%I SET SCHEMA herika_compat',object_name);
    END LOOP;
    ALTER VIEW almsivi_internal.almsivi_core_profiles_source SET SCHEMA herika_compat;
END
$companions$;

DO $sources$
DECLARE
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
    FOREACH object_name IN ARRAY source_tables LOOP
        EXECUTE format('ALTER TABLE almsivi_internal.%I SET SCHEMA public',object_name);
    END LOOP;
END
$sources$;

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
        WHERE n.nspname='almsivi_internal' AND p.proname LIKE 'sync\_%' ESCAPE '\'
           OR n.nspname='almsivi_internal' AND p.proname LIKE 'remove\_%' ESCAPE '\'
           OR n.nspname='almsivi_internal' AND p.proname LIKE 'trigger\_%' ESCAPE '\'
           OR n.nspname='almsivi_internal' AND p.proname IN ('refresh_npc_relationships','refresh_turn_audit','rebuild_special_profiles','project_turn_world','chim_touch_updated_at')
        ORDER BY p.proname,p.oid
    LOOP
        definition := pg_get_functiondef(function_row.oid);
        FOREACH object_name IN ARRAY source_tables LOOP
            definition := replace(definition,'almsivi_internal.'||object_name,'public.'||object_name);
        END LOOP;
        EXECUTE definition;
        EXECUTE format(
            'ALTER FUNCTION almsivi_internal.%I(%s) SET search_path TO herika_compat, public, pg_temp',
            function_row.proname,function_row.arguments
        );
        EXECUTE format(
            'ALTER FUNCTION almsivi_internal.%I(%s) SET SCHEMA herika_compat',
            function_row.proname,function_row.arguments
        );
    END LOOP;
END
$functions$;
