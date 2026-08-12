<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use PDO;

final class ManagementUiRepository
{
    public function __construct(private readonly PDO $db) {}

    /** Load the bounded datasets used by the server-style home dashboard. */
    public function dashboard(): array
    {
        $current = $this->one(
            "SELECT s.state,s.created_at,s.openmw_version,s.lua_api_revision,s.client_version,s.platform,"
            . "p.name AS profile_name,pt.name AS playthrough_name "
            . "FROM sessions s LEFT JOIN profiles p ON p.profile_id=s.profile_id "
            . "LEFT JOIN playthroughs pt ON pt.playthrough_id=s.playthrough_id "
            . "ORDER BY (s.state='active') DESC,s.created_at DESC LIMIT 1"
        );

        return [
            'database_version' => (string) $this->db->query('SHOW server_version')->fetchColumn(),
            'current' => $current,
            'dialogue' => $this->all(
                "SELECT COALESCE(speaker->>'display_name',speaker->>'name',speaker->>'record_id','Unknown') AS speaker,"
                . "text,delivery_state,emitted_at FROM dialogue_utterances ORDER BY emitted_at DESC LIMIT 12"
            ),
            'stats' => [
                'Installations' => $this->count('installations', 'revoked_at IS NULL'),
                'Sessions' => $this->count('sessions'),
                'Turns' => $this->count('turns'),
                'Memories' => $this->count('memory_records', 'deleted_at IS NULL'),
                'Relationships' => $this->count('relationship_records', 'deleted_at IS NULL'),
                'Queued Jobs' => $this->count('durable_jobs', "state='queued'"),
            ],
            'latest_narrative' => $this->one(
                "SELECT kind,title,content,created_at FROM narrative_records WHERE deleted_at IS NULL "
                . "ORDER BY created_at DESC LIMIT 1"
            ),
            'words' => $this->all(
                "SELECT word,count(*)::int AS uses FROM "
                . "(SELECT text FROM dialogue_utterances ORDER BY emitted_at DESC LIMIT 100) d "
                . "CROSS JOIN LATERAL regexp_split_to_table(lower(d.text),'[^[:alnum:]_]+') AS word "
                . "WHERE length(word)>3 GROUP BY word ORDER BY uses DESC,word LIMIT 12"
            ),
            'runtime' => [
                'Active Sessions' => $this->count('sessions', "state='active'"),
                'Pending Dialogue' => $this->count('dialogue_utterances', "delivery_state='pending'"),
                'Terminal Actions' => $this->count('action_results'),
                'Dead Jobs' => $this->count('durable_jobs', "state='dead'"),
                'Knowledge Records' => $this->count('knowledge_documents', 'deleted_at IS NULL'),
                'Provider Attempts' => $this->count('provider_attempts'),
            ],
        ];
    }

    /** Load the inspection tabs rendered directly inside the Roleplay PHP page. */
    public function roleplay(): array
    {
        return [
            'events' => $this->rows('events'),
            'responses' => $this->rows('responses'),
            'memories' => $this->rows('memories'),
            'relationships' => $this->rows('relationships'),
            'narratives' => $this->rows('narratives'),
            'knowledge' => $this->rows('knowledge'),
            'journal' => $this->rows('journal'),
            'books' => $this->rows('books'),
        ];
    }

    /** Return one of the allowlisted bounded datasets used by embedded management pages. */
    public function rows(string $view): array
    {
        $sql = match ($view) {
            'events' => "SELECT e.type,'chim-roleplay-event.v1' AS schema,m.request_id,m.turn_id,m.created_at AS occurred_at FROM public.eventlog e JOIN almsivi_internal.eventlog_metadata m ON m.rowid=e.rowid WHERE m.suppressed_at IS NULL ORDER BY e.rowid DESC LIMIT 100",
            'request_logs' => "SELECT trace.prompt_trace_id,trace.request_id,trace.turn_id,trace.algorithm,trace.input_bytes,trace.truncated,"
                . "count(section.section_order)::int AS section_count,jsonb_object_agg(section.section_key,section.inclusion_reason ORDER BY section.section_order) AS sections,"
                . "trace.input_sha256,trace.created_at FROM prompt_traces trace JOIN prompt_trace_sections section ON section.prompt_trace_id=trace.prompt_trace_id "
                . "GROUP BY trace.prompt_trace_id ORDER BY trace.created_at DESC LIMIT 100",
            'responses' => "SELECT COALESCE(s.speaker,'Unknown') AS speaker,s.speech AS text,m.delivery_state,m.created_at AS emitted_at FROM public.speech s LEFT JOIN almsivi_internal.speech_metadata m ON m.rowid=s.rowid ORDER BY s.rowid DESC LIMIT 100",
            'memories' => "SELECT metadata.memory_id,metadata.installation_id,metadata.profile_id,metadata.playthrough_id,metadata.tier,m.message AS content,"
                . "jsonb_build_object('speaker',m.speaker,'listener',m.listener,'event',m.event,'momentum',m.momentum) AS provenance,"
                . "source.current_revision,source.source_event_id,CASE WHEN source.source_event_id IS NULL THEN 'authored' "
                . "WHEN delivery.status='played' THEN 'played dialogue' ELSE COALESCE(event.event_kind,'derived') END AS eligibility,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',revision.revision,'reason',revision.change_reason,'created_at',revision.created_at) ORDER BY revision.revision DESC) "
                . "FROM memory_record_revisions revision WHERE revision.memory_id=source.memory_id) AS revisions,"
                . "to_timestamp(m.localts) AS occurred_at,source.updated_at FROM public.memory m "
                . "JOIN almsivi_internal.memory_metadata metadata ON metadata.rowid=m.rowid "
                . "LEFT JOIN almsivi_internal.memory_records source ON source.memory_id=metadata.memory_id "
                . "LEFT JOIN almsivi_internal.source_events event ON event.source_event_id=source.source_event_id "
                . "LEFT JOIN almsivi_internal.dialogue_delivery_results delivery ON delivery.source_event_id=source.source_event_id "
                . "ORDER BY m.localts DESC,m.rowid DESC LIMIT 100",
            'relationships', 'relationship_logs' => "SELECT COALESCE(source.relationship_id::text,metadata.source_profile_id::text||':'||rel.key) AS relationship_id,metadata.installation_id,metadata.source_profile_id AS profile_id,source.playthrough_id,COALESCE(source.actor_identity,jsonb_build_object('record_id',rel.key,'display_name',rel.value->>'name')) AS actor_identity,COALESCE(rel.value->>'name',rel.key) AS actor,rel.value->>'disposition' AS disposition,rel.value->>'affinity' AS affinity,COALESCE(rel.value->>'source',source.source_mode) AS source_mode,source.updated_at FROM public.core_npc_master npc JOIN almsivi_internal.npc_metadata metadata ON metadata.npc_id=npc.id CROSS JOIN LATERAL jsonb_each(COALESCE(npc.extended_data->'relationships','{}'::jsonb)) rel LEFT JOIN almsivi_internal.relationship_records source ON source.profile_id=metadata.source_profile_id AND source.deleted_at IS NULL AND lower(COALESCE(source.actor_identity->>'record_id',source.actor_identity->>'display_name',''))=lower(rel.key) ORDER BY source.updated_at DESC NULLS LAST,npc.id,rel.key LIMIT 100",
            'narratives' => "SELECT metadata.narrative_id,metadata.installation_id,metadata.profile_id,metadata.playthrough_id,d.tags AS kind,d.topic AS title,d.content,jsonb_build_object('location',d.location,'people',d.people,'tags',d.tags) AS provenance,to_timestamp(d.localts) AS created_at FROM public.diarylog d JOIN almsivi_internal.diarylog_metadata metadata ON metadata.rowid=d.rowid ORDER BY d.localts DESC,d.rowid DESC LIMIT 100",
            'knowledge', 'worldknowledge' => "SELECT metadata.document_id,metadata.installation_id,metadata.profile_id,metadata.playthrough_id,o.topic AS title,left(o.topic_desc,4000) AS content,jsonb_build_object('category',o.category,'class',o.knowledge_class,'tags',o.tags,'aliases',o.aliases) AS provenance,source.created_at FROM public.oghma o JOIN almsivi_internal.oghma_metadata metadata ON metadata.topic=o.topic LEFT JOIN almsivi_internal.knowledge_documents source ON source.document_id=metadata.document_id ORDER BY source.created_at DESC NULLS LAST,o.topic LIMIT 100",
            'journal' => "SELECT metadata.installation_id,session.profile_id,metadata.playthrough_id,q.id_quest AS quest_id,COALESCE(NULLIF(q.briefing2,''),NULLIF(q.briefing,''),q.data) AS journal_entry,NULL::text AS game_day,NULL::text AS game_month,NULL::text AS day_of_month,to_timestamp(q.localts) AS last_synced_at FROM public.questlog q JOIN almsivi_internal.questlog_metadata metadata ON metadata.rowid=q.rowid LEFT JOIN almsivi_internal.sessions session ON session.session_id=metadata.session_id ORDER BY q.localts DESC,q.rowid DESC LIMIT 100",
            'books' => "SELECT metadata.installation_id,session.profile_id,metadata.playthrough_id,b.title,metadata.record_id,b.content AS book_text,NULL::text AS is_scroll,NULL::text AS taught_skill,to_timestamp(b.localts) AS last_read_at FROM public.books b JOIN almsivi_internal.book_metadata metadata ON metadata.rowid=b.rowid LEFT JOIN almsivi_internal.sessions session ON session.session_id=metadata.session_id ORDER BY b.localts DESC,b.rowid DESC LIMIT 100",
            'descriptions' => "SELECT metadata.description_id,metadata.installation_id,d.plugin AS content_file,d.baseid AS record_id,d.name AS display_name,d.description,source.updated_at FROM public.combined_descriptions d JOIN almsivi_internal.description_metadata metadata ON metadata.plugin=d.plugin AND metadata.baseid=d.baseid LEFT JOIN almsivi_internal.item_descriptions source ON source.description_id=metadata.description_id ORDER BY lower(d.name),lower(d.plugin),lower(d.baseid) LIMIT 500",
            'discovered_items' => "SELECT item.installation_id,item.content_file,item.record_id,item.record_kind,item.display_name,item.reference_content_file,item.observed_sources,item.last_seen_at,item.observation_count,manifest.active,manifest.load_order,description.description_id FROM almsivi_internal.discovered_items item LEFT JOIN almsivi_internal.content_manifest_files manifest ON manifest.installation_id=item.installation_id AND manifest.content_file=item.content_file LEFT JOIN almsivi_internal.item_descriptions description ON description.installation_id=item.installation_id AND lower(description.content_file)=item.content_file AND lower(description.record_id)=item.record_id AND description.deleted_at IS NULL ORDER BY item.last_seen_at DESC,item.content_file,item.record_id LIMIT 500",
            'characters' => "SELECT metadata.source_profile_id AS profile_id,metadata.installation_id,npc.npc_name AS name,metadata.source_revision AS current_revision,metadata.actor_identity,npc.extended_data->'almsivi_profile' AS content,core_metadata.source_core_profile_id AS core_profile_id,core.label AS core_profile_label,(SELECT count(*)::int FROM almsivi_internal.actor_profile_bindings binding WHERE binding.installation_id=metadata.installation_id AND binding.profile_id=metadata.source_profile_id) AS binding_count,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM almsivi_internal.profile_revisions history WHERE history.profile_id=metadata.source_profile_id) AS revisions FROM public.core_npc_master npc JOIN almsivi_internal.npc_metadata metadata ON metadata.npc_id=npc.id LEFT JOIN public.core_profiles core ON core.id=npc.profile_id LEFT JOIN almsivi_internal.core_profile_metadata core_metadata ON core_metadata.core_profile_id=core.id LEFT JOIN almsivi_internal.profile_revisions current_revision ON current_revision.profile_id=metadata.source_profile_id AND current_revision.revision=metadata.source_revision ORDER BY npc.npc_name LIMIT 500",
            'core_profiles' => "SELECT metadata.source_core_profile_id AS core_profile_id,metadata.installation_id,profile.label,(profile.default_npc='1') AS default_npc,profile.slot,metadata.source_revision AS current_revision,profile.metadata AS content,current_revision.change_reason,current_revision.created_at,(SELECT count(*)::int FROM public.core_npc_master npc WHERE npc.profile_id=profile.id) AS profile_usage,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM almsivi_internal.core_profile_revisions history WHERE history.core_profile_id=metadata.source_core_profile_id) AS revisions FROM public.core_profiles profile JOIN almsivi_internal.core_profile_metadata metadata ON metadata.core_profile_id=profile.id LEFT JOIN almsivi_internal.core_profile_revisions current_revision ON current_revision.core_profile_id=metadata.source_core_profile_id AND current_revision.revision=metadata.source_revision ORDER BY metadata.installation_id,(profile.default_npc='1') DESC,profile.slot NULLS LAST,lower(profile.label) LIMIT 100",
            'profiles' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,(SELECT count(*)::int FROM actor_profile_bindings b WHERE b.installation_id=p.installation_id AND b.profile_id=p.profile_id) AS binding_count,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator') ORDER BY CASE WHEN p.actor_identity->>'kind'='template' THEN 0 ELSE 1 END,p.name LIMIT 500",
            'npc_biographies' => "SELECT metadata.source_profile_id AS profile_id,metadata.installation_id,npc.npc_name AS name,metadata.source_revision AS current_revision,metadata.actor_identity,npc.extended_data->'almsivi_profile' AS content,0::int AS binding_count,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM almsivi_internal.profile_revisions history WHERE history.profile_id=metadata.source_profile_id) AS revisions FROM public.core_npc_master npc JOIN almsivi_internal.npc_metadata metadata ON metadata.npc_id=npc.id LEFT JOIN almsivi_internal.profile_revisions current_revision ON current_revision.profile_id=metadata.source_profile_id AND current_revision.revision=metadata.source_revision ORDER BY npc.npc_name LIMIT 500",
            'player' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,"
                . "(SELECT count(*)::int FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.installation_id=p.installation_id AND t.speaker->>'kind'='player' AND btrim(t.input_text)<>'') AS input_count,r.created_at "
                . ",(SELECT jsonb_build_object('accepted_at',latest.accepted_at,'player',latest.context->'player','playerState',latest.context->'playerState','inventory',latest.context->'inventory','equipment',latest.context->'equipment','skills',latest.context->'skills','factions',latest.context->'factions','journal',latest.context->'journal') FROM turns latest JOIN sessions latest_session ON latest_session.session_id=latest.session_id WHERE latest_session.installation_id=p.installation_id ORDER BY latest.accepted_at DESC LIMIT 1) AS latest_context "
                . ",(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions "
                . "FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='player' ORDER BY p.created_at,p.profile_id LIMIT 100",
            'narrator' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,r.created_at "
                . ",(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions "
                . "FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND p.actor_identity->>'kind'='narrator' ORDER BY p.created_at,p.profile_id LIMIT 100",
            'observed_npcs' => "WITH recent_turns AS (SELECT s.installation_id,t.target,t.audience,t.context,t.accepted_at FROM turns t JOIN sessions s ON s.session_id=t.session_id ORDER BY t.accepted_at DESC LIMIT 100),"
                . "actors AS (SELECT installation_id,target AS actor,accepted_at FROM recent_turns UNION ALL SELECT r.installation_id,a.actor,r.accepted_at FROM recent_turns r CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(r.audience)='array' THEN r.audience ELSE '[]'::jsonb END) a(actor) UNION ALL SELECT r.installation_id,a.actor,r.accepted_at FROM recent_turns r CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(r.context->'nearbyActors'->'items')='array' THEN r.context->'nearbyActors'->'items' ELSE '[]'::jsonb END) a(actor)) "
                . "SELECT DISTINCT ON (a.installation_id,lower(a.actor->>'content_file'),lower(a.actor->>'record_id')) a.installation_id,a.actor->>'display_name' AS display_name,a.actor->>'record_id' AS record_id,a.actor->>'content_file' AS content_file,a.actor->'refnum' AS refnum,a.accepted_at AS last_seen_at,"
                . "(SELECT p.profile_id FROM profiles p WHERE p.installation_id=a.installation_id AND p.deleted_at IS NULL AND lower(COALESCE(p.actor_identity->>'record_id',''))=lower(a.actor->>'record_id') AND lower(COALESCE(p.actor_identity->>'content_file',''))=lower(a.actor->>'content_file') ORDER BY p.created_at LIMIT 1) AS profile_id "
                . "FROM actors a WHERE a.actor->>'kind'='npc' AND COALESCE(a.actor->>'record_id','')<>'' ORDER BY a.installation_id,lower(a.actor->>'content_file'),lower(a.actor->>'record_id'),a.accepted_at DESC LIMIT 100",
            'llm' => "SELECT metadata.configuration_id,metadata.installation_id,connector.label AS name,metadata.configuration_revision AS current_revision,connector.metadata AS content,(SELECT count(*)::int FROM almsivi_internal.sessions session WHERE session.provider_configuration_id=metadata.configuration_id AND session.state='active') AS active_session_usage,((SELECT count(*) FROM public.core_profiles profile WHERE connector.id IN (profile.llm_primary_id,profile.llm_secondary_id,profile.llm_tertiary_id,profile.llm_quaternary_id,profile.llm_formatter_id,profile.llm_fallback_id))+(SELECT count(*) FROM public.core_npc_master npc WHERE metadata.configuration_id::text IN (npc.extended_data#>>'{almsivi_profile,routing,llm_configuration_id}',npc.extended_data#>>'{almsivi_profile,routing,llm_fast_configuration_id}',npc.extended_data#>>'{almsivi_profile,routing,llm_powerful_configuration_id}',npc.extended_data#>>'{almsivi_profile,routing,llm_experimental_configuration_id}',npc.extended_data#>>'{almsivi_profile,routing,llm_fallback_configuration_id}')))::int AS profile_usage,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM almsivi_internal.configuration_revisions history WHERE history.configuration_id=metadata.configuration_id) AS revisions FROM public.core_llm_connector connector JOIN almsivi_internal.llm_connector_metadata metadata ON metadata.connector_id=connector.id LEFT JOIN almsivi_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.configuration_id AND current_revision.revision=metadata.configuration_revision ORDER BY connector.label LIMIT 100",
            'tts' => "SELECT metadata.configuration_id,metadata.installation_id,connector.label AS name,metadata.configuration_revision AS current_revision,connector.metadata AS content,(selection.configuration_id IS NOT NULL) AS active,((SELECT count(*) FROM public.core_profiles profile WHERE profile.tts_connector_id=connector.id)+(SELECT count(*) FROM public.core_npc_master npc WHERE npc.extended_data#>>'{almsivi_profile,routing,tts_configuration_id}'=metadata.configuration_id::text))::int AS profile_usage,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM almsivi_internal.configuration_revisions history WHERE history.configuration_id=metadata.configuration_id) AS revisions FROM public.core_tts_connector connector JOIN almsivi_internal.tts_connector_metadata metadata ON metadata.connector_id=connector.id LEFT JOIN almsivi_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.configuration_id AND current_revision.revision=metadata.configuration_revision LEFT JOIN almsivi_internal.installation_provider_selections selection ON selection.configuration_id=metadata.configuration_id AND selection.installation_id=metadata.installation_id AND selection.provider_kind='tts_provider' ORDER BY active DESC,connector.label LIMIT 100",
            'voice_catalog' => "SELECT v.configuration_id,v.voice_id,v.display_name,v.language,v.provider_status,v.custom_voice,v.discovered_at,c.installation_id,c.name AS connector_name FROM speech_connector_voices v JOIN configuration_sets c ON c.configuration_id=v.configuration_id WHERE c.kind='tts_provider' AND c.deleted_at IS NULL ORDER BY c.name,v.display_name,v.voice_id LIMIT 1024",
            'stt' => "SELECT c.configuration_id,c.installation_id,c.name,c.current_revision,r.content,(s.configuration_id IS NOT NULL) AS active,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision LEFT JOIN installation_provider_selections s ON s.configuration_id=c.configuration_id AND s.installation_id=c.installation_id AND s.provider_kind='stt_provider' WHERE c.kind='stt_provider' AND c.deleted_at IS NULL ORDER BY active DESC,c.name LIMIT 100",
            'prompts' => "SELECT metadata.source_configuration_id AS configuration_id,metadata.installation_id,COALESCE(configuration.name,prompt.prompt_key) AS name,prompt.prompt_key,metadata.source_revision AS current_revision,COALESCE(current_revision.content,'{}'::jsonb)||jsonb_build_object('instruction',COALESCE(NULLIF(prompt.custom_prompt,''),prompt.default_prompt),'default_prompt',prompt.default_prompt,'custom_prompt',prompt.custom_prompt,'description',prompt.description) AS content,((SELECT count(*) FROM public.core_profiles profile WHERE profile.metadata#>>'{routing,prompt_configuration_id}'=metadata.source_configuration_id::text)+(SELECT count(*) FROM public.core_npc_master npc WHERE npc.extended_data#>>'{almsivi_profile,routing,prompt_configuration_id}'=metadata.source_configuration_id::text))::int AS profile_usage,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM almsivi_internal.configuration_revisions history WHERE history.configuration_id=metadata.source_configuration_id) AS revisions FROM public.prompts prompt JOIN almsivi_internal.prompt_metadata metadata ON metadata.prompt_key=prompt.prompt_key LEFT JOIN almsivi_internal.configuration_sets configuration ON configuration.configuration_id=metadata.source_configuration_id LEFT JOIN almsivi_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.source_configuration_id AND current_revision.revision=metadata.source_revision ORDER BY prompt.prompt_key LIMIT 100",
            'action_policies' => "SELECT c.configuration_id,c.installation_id,c.profile_id,p.name AS profile_name,c.name,c.current_revision,r.content,r.created_at,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions "
                . "FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision LEFT JOIN profiles p ON p.profile_id=c.profile_id AND p.deleted_at IS NULL WHERE c.kind='action_policy' AND c.deleted_at IS NULL ORDER BY c.name LIMIT 100",
            'actions' => "SELECT a.code_name AS action_name,COALESCE((a.metadata->>'tier')::integer,0) AS tier,a.metadata->>'client_capability' AS client_capability,a.is_activated AS enabled,a.description FROM public.combined_core_action a ORDER BY tier,a.action_name LIMIT 100",
            'installations' => "SELECT installation_id,display_name,last_seen_at,created_at FROM installations WHERE revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 100",
            'global_settings' => "SELECT metadata.source_configuration_id AS configuration_id,metadata.installation_id,installation.display_name,configuration.name,max(metadata.source_revision) AS current_revision,jsonb_object_agg(metadata.setting_key,settings.value::jsonb ORDER BY metadata.setting_key) AS content,current_revision.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM almsivi_internal.configuration_revisions history WHERE history.configuration_id=metadata.source_configuration_id) AS revisions FROM public.general_settings settings JOIN almsivi_internal.general_setting_metadata metadata ON metadata.id=settings.id JOIN almsivi_internal.installations installation ON installation.installation_id=metadata.installation_id JOIN almsivi_internal.configuration_sets configuration ON configuration.configuration_id=metadata.source_configuration_id LEFT JOIN almsivi_internal.configuration_revisions current_revision ON current_revision.configuration_id=metadata.source_configuration_id AND current_revision.revision=metadata.source_revision GROUP BY metadata.source_configuration_id,metadata.installation_id,installation.display_name,configuration.name,current_revision.created_at ORDER BY installation.display_name LIMIT 100",
            'profile_preferences' => "SELECT i.installation_id,i.display_name,COALESCE(p.auto_lock_on_edit,true) AS auto_lock_on_edit,p.updated_at FROM installations i LEFT JOIN installation_profile_preferences p ON p.installation_id=i.installation_id WHERE i.revoked_at IS NULL ORDER BY i.last_seen_at DESC LIMIT 100",
            'playthroughs' => "SELECT p.playthrough_id,p.installation_id,p.profile_id,p.name AS playthrough,pr.name AS profile,p.current_revision,p.content_fingerprint,p.created_at,"
                ."(SELECT count(*)::int FROM sessions s WHERE s.playthrough_id=p.playthrough_id) AS sessions,"
                ."(SELECT count(*)::int FROM turns t JOIN sessions s ON s.session_id=t.session_id WHERE s.playthrough_id=p.playthrough_id) AS turns,"
                ."(SELECT count(*)::int FROM dialogue_utterances d JOIN sessions s ON s.session_id=d.session_id WHERE s.playthrough_id=p.playthrough_id) AS responses,"
                ."(SELECT count(*)::int FROM memory_records m WHERE m.playthrough_id=p.playthrough_id AND m.deleted_at IS NULL) AS memories,"
                ."(SELECT count(*)::int FROM relationship_records rel WHERE rel.playthrough_id=p.playthrough_id AND rel.deleted_at IS NULL) AS relationships,"
                ."(SELECT count(*)::int FROM narrative_records n WHERE n.playthrough_id=p.playthrough_id AND n.deleted_at IS NULL) AS narratives,"
                ."(SELECT count(*)::int FROM knowledge_documents k WHERE k.playthrough_id=p.playthrough_id AND k.deleted_at IS NULL) AS knowledge_records,"
                ."(SELECT max(s.created_at) FROM sessions s WHERE s.playthrough_id=p.playthrough_id) AS last_session_at "
                ."FROM playthroughs p JOIN profiles pr ON pr.profile_id=p.profile_id WHERE p.deleted_at IS NULL ORDER BY p.name LIMIT 100",
            'active_sessions' => "SELECT s.session_id,s.installation_id,s.profile_id,s.playthrough_id,COALESCE(t.name,s.session_id::text) AS label FROM sessions s LEFT JOIN playthroughs t ON t.playthrough_id=s.playthrough_id WHERE s.state='active' ORDER BY s.created_at DESC LIMIT 50",
            'jobs' => "SELECT job_type,state,attempt_count,max_attempts,next_run_at,last_error_code,updated_at FROM durable_jobs ORDER BY updated_at DESC LIMIT 100",
            'response_queue' => "SELECT COALESCE(s.speaker,'Unknown') AS speaker,left(s.speech,1000) AS text,metadata.delivery_state,"
                . "CASE WHEN metadata.delivery_state IN ('emitted','pending') AND d.delivery_deadline_at<=clock_timestamp() THEN 'overdue' ELSE 'current' END AS queue_health,"
                . "metadata.created_at AS emitted_at,d.delivery_deadline_at,d.delivered_at FROM public.speech s JOIN almsivi_internal.speech_metadata metadata ON metadata.rowid=s.rowid LEFT JOIN almsivi_internal.dialogue_utterances d ON d.dialogue_message_id=metadata.dialogue_message_id ORDER BY s.rowid DESC LIMIT 100",
            'oghma_audit' => "SELECT domain,prompt_section,left(query,1000) AS query,cardinality(result_ids) AS result_count,reasons,algorithm,turn_id,created_at "
                . "FROM retrieval_traces ORDER BY created_at DESC LIMIT 100",
            'provider_usage' => "SELECT provider_kind,provider_name,COALESCE(model,'default') AS model,count(*)::int AS attempts,"
                . "count(*) FILTER(WHERE state='succeeded')::int AS succeeded,count(*) FILTER(WHERE state IN('failed','cancelled'))::int AS failed_or_cancelled,"
                . "COALESCE(sum(input_bytes),0)::bigint AS input_bytes,COALESCE(sum(output_bytes),0)::bigint AS output_bytes,"
                . "COALESCE(round(avg(duration_ms))::bigint,0) AS average_duration_ms FROM provider_attempts "
                . "GROUP BY provider_kind,provider_name,COALESCE(model,'default') ORDER BY provider_kind,provider_name,model LIMIT 100",
            'provider_attempts' => "SELECT provider_kind,provider_name,operation,state,duration_ms,error_code,started_at FROM provider_attempts ORDER BY started_at DESC LIMIT 100",
            'media_cache' => "SELECT m.media_id,COALESCE(d.speaker->>'display_name',d.speaker->>'name',d.speaker->>'record_id','Unknown') AS speaker,"
                . "m.codec,m.byte_count,m.duration_ms,COALESCE(d.delivery_state,'unlinked') AS delivery_state,"
                . "CASE WHEN m.deleted_at IS NOT NULL THEN 'deleted' WHEN m.expires_at<=clock_timestamp() THEN 'expired' ELSE 'available' END AS cache_state,"
                . "m.expires_at,m.created_at FROM media_objects m LEFT JOIN LATERAL (SELECT u.speaker,u.delivery_state FROM dialogue_utterances u "
                . "WHERE u.turn_id=m.turn_id ORDER BY u.utterance_index LIMIT 1) d ON true ORDER BY m.created_at DESC LIMIT 100",
            'backup_health' => "SELECT backup_id,state,format_version,byte_count,scope,created_at,restored_at FROM backup_records ORDER BY created_at DESC LIMIT 100",
            'database_manager' => "SELECT version,name,checksum,applied_at FROM almsivi_internal.schema_migrations ORDER BY version DESC LIMIT 100",
            'diagnostics' => "SELECT category,action,scope,created_at FROM operational_audit ORDER BY created_at DESC LIMIT 100",
            default => [],
        };

        if ($sql === []) return [];
        return array_map(fn(array $row): array => $this->redactRow($row), $this->all($sql));
    }

    private function count(string $table, ?string $where = null): int
    {
        $allowlisted = [
            'installations', 'sessions', 'turns', 'memory_records', 'relationship_records',
            'durable_jobs', 'dialogue_utterances', 'action_results', 'knowledge_documents',
            'provider_attempts',
        ];
        if (!in_array($table, $allowlisted, true)) return 0;
        $sql = 'SELECT count(*) FROM ' . $table . ($where === null ? '' : ' WHERE ' . $where);
        return (int) $this->db->query($sql)->fetchColumn();
    }

    private function one(string $sql): ?array
    {
        $row = $this->db->query($sql)->fetch();
        return $row === false ? null : $this->redactRow($row);
    }

    private function all(string $sql): array
    {
        return $this->db->query($sql)->fetchAll();
    }

    private function redactRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (!is_string($value) || ($value === '' || ($value[0] !== '{' && $value[0] !== '['))) continue;
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                $row[$key] = $this->redactValue($decoded);
            } catch (\JsonException) {
                // Non-JSON text is displayed as text and escaped by the template.
            }
        }
        return $row;
    }

    private function redactValue(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/(?:api[_-]?key|secret|password|authorization|token)/i', $key) === 1) return '[redacted]';
        if (!is_array($value)) return $value;
        foreach ($value as $childKey => &$childValue) {
            $childValue = $this->redactValue($childValue, (string) $childKey);
        }
        return $value;
    }
}
