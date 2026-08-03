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
            'events', 'request_logs' => "SELECT event_kind AS type,schema_name AS schema,request_id,turn_id,occurred_at FROM source_events ORDER BY received_at DESC LIMIT 100",
            'responses' => "SELECT COALESCE(speaker->>'display_name',speaker->>'name',speaker->>'record_id','Unknown') AS speaker,text,delivery_state,emitted_at FROM dialogue_utterances ORDER BY emitted_at DESC LIMIT 100",
            'memories' => "SELECT memory_id,installation_id,profile_id,playthrough_id,tier,content,provenance,occurred_at,updated_at FROM memory_records WHERE deleted_at IS NULL ORDER BY occurred_at DESC LIMIT 100",
            'relationships', 'relationship_logs' => "SELECT relationship_id,installation_id,profile_id,playthrough_id,actor_identity,COALESCE(actor_identity->>'display_name',actor_identity->>'name',actor_identity->>'record_id',actor_identity::text) AS actor,disposition,affinity,source_mode,updated_at FROM relationship_records WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 100",
            'narratives' => "SELECT narrative_id,installation_id,profile_id,playthrough_id,kind,title,content,provenance,created_at FROM narrative_records WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 100",
            'knowledge', 'worldknowledge' => "SELECT document_id,installation_id,profile_id,playthrough_id,title,left(content,4000) AS content,provenance,created_at FROM knowledge_documents WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 100",
            'journal' => "WITH latest AS (SELECT DISTINCT ON (s.installation_id,s.profile_id,s.playthrough_id) "
                . "s.installation_id,s.profile_id,s.playthrough_id,t.accepted_at,t.context->'journal' AS journal "
                . "FROM turns t JOIN sessions s ON s.session_id=t.session_id "
                . "WHERE jsonb_typeof(t.context->'journal'->'items')='array' "
                . "ORDER BY s.installation_id,s.profile_id,s.playthrough_id,t.accepted_at DESC) "
                . "SELECT l.installation_id,l.profile_id,l.playthrough_id,e.item->>'quest_id' AS quest_id,"
                . "e.item->>'text' AS journal_entry,e.item->>'day' AS game_day,e.item->>'month' AS game_month,"
                . "e.item->>'day_of_month' AS day_of_month,l.accepted_at AS last_synced_at "
                . "FROM latest l CROSS JOIN LATERAL jsonb_array_elements(l.journal->'items') WITH ORDINALITY e(item,ordinality) "
                . "ORDER BY l.accepted_at DESC,e.ordinality DESC LIMIT 100",
            'books' => "WITH observed AS (SELECT s.installation_id,s.profile_id,s.playthrough_id,t.accepted_at,e.item "
                . "FROM turns t JOIN sessions s ON s.session_id=t.session_id "
                . "CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(t.context->'books'->'items')='array' "
                . "THEN t.context->'books'->'items' ELSE '[]'::jsonb END) e(item)), "
                . "latest AS (SELECT DISTINCT ON (installation_id,profile_id,playthrough_id,lower(item->>'record_id')) "
                . "installation_id,profile_id,playthrough_id,item,accepted_at FROM observed "
                . "WHERE COALESCE(item->>'record_id','')<>'' ORDER BY installation_id,profile_id,playthrough_id,"
                . "lower(item->>'record_id'),accepted_at DESC) "
                . "SELECT installation_id,profile_id,playthrough_id,item->>'title' AS title,item->>'record_id' AS record_id,"
                . "item->>'text' AS book_text,item->>'is_scroll' AS is_scroll,item->>'skill' AS taught_skill,accepted_at AS last_read_at "
                . "FROM latest ORDER BY accepted_at DESC,title,record_id LIMIT 100",
            'descriptions' => "SELECT description_id,installation_id,content_file,record_id,display_name,description,updated_at FROM item_descriptions WHERE deleted_at IS NULL ORDER BY lower(display_name),lower(content_file),lower(record_id) LIMIT 500",
            'characters' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,"
                . "p.core_profile_id,cp.label AS core_profile_label,"
                . "(SELECT count(*)::int FROM actor_profile_bindings b WHERE b.installation_id=p.installation_id AND b.profile_id=p.profile_id) AS binding_count,r.created_at "
                . ",(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions "
                . "FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision LEFT JOIN core_profiles cp ON cp.core_profile_id=p.core_profile_id AND cp.deleted_at IS NULL WHERE p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY p.name LIMIT 500",
            'core_profiles' => "SELECT c.core_profile_id,c.installation_id,c.label,c.default_npc,c.slot,c.current_revision,r.content,r.change_reason,r.created_at,"
                . "(SELECT count(*)::int FROM profiles p WHERE p.installation_id=c.installation_id AND p.core_profile_id=c.core_profile_id AND p.deleted_at IS NULL) AS profile_usage,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM core_profile_revisions history WHERE history.core_profile_id=c.core_profile_id) AS revisions "
                . "FROM core_profiles c JOIN core_profile_revisions r ON r.core_profile_id=c.core_profile_id AND r.revision=c.current_revision WHERE c.deleted_at IS NULL ORDER BY c.installation_id,c.default_npc DESC,c.slot NULLS LAST,lower(c.label) LIMIT 100",
            'profiles' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,(SELECT count(*)::int FROM actor_profile_bindings b WHERE b.installation_id=p.installation_id AND b.profile_id=p.profile_id) AS binding_count,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator') ORDER BY CASE WHEN p.actor_identity->>'kind'='template' THEN 0 ELSE 1 END,p.name LIMIT 500",
            'npc_biographies' => "SELECT p.profile_id,p.installation_id,p.name,p.current_revision,p.actor_identity,r.content,0::int AS binding_count,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM profile_revisions history WHERE history.profile_id=p.profile_id) AS revisions FROM profiles p JOIN profile_revisions r ON r.profile_id=p.profile_id AND r.revision=p.current_revision WHERE p.deleted_at IS NULL AND COALESCE(p.actor_identity->>'kind','actor') NOT IN ('player','narrator','template') ORDER BY p.name LIMIT 500",
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
            'llm' => "SELECT c.configuration_id,c.installation_id,c.name,c.current_revision,r.content,"
                . "(SELECT count(*)::int FROM sessions s WHERE s.provider_configuration_id=c.configuration_id AND s.state='active') AS active_session_usage,"
                . "((SELECT count(*) FROM profiles p JOIN profile_revisions pr ON pr.profile_id=p.profile_id AND pr.revision=p.current_revision WHERE p.deleted_at IS NULL AND (pr.content->'routing'->>'llm_configuration_id'=c.configuration_id::text OR pr.content->'routing'->>'llm_fast_configuration_id'=c.configuration_id::text OR pr.content->'routing'->>'llm_powerful_configuration_id'=c.configuration_id::text OR pr.content->'routing'->>'llm_experimental_configuration_id'=c.configuration_id::text OR pr.content->'routing'->>'llm_fallback_configuration_id'=c.configuration_id::text))+(SELECT count(*) FROM core_profiles cp JOIN core_profile_revisions cr ON cr.core_profile_id=cp.core_profile_id AND cr.revision=cp.current_revision WHERE cp.deleted_at IS NULL AND (cr.content->'routing'->>'llm_configuration_id'=c.configuration_id::text OR cr.content->'routing'->>'llm_fast_configuration_id'=c.configuration_id::text OR cr.content->'routing'->>'llm_powerful_configuration_id'=c.configuration_id::text OR cr.content->'routing'->>'llm_experimental_configuration_id'=c.configuration_id::text OR cr.content->'routing'->>'llm_fallback_configuration_id'=c.configuration_id::text)))::int AS profile_usage,r.created_at,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions "
                . "FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='provider' AND c.deleted_at IS NULL ORDER BY c.name LIMIT 100",
            'tts' => "SELECT c.configuration_id,c.installation_id,c.name,c.current_revision,r.content,(s.configuration_id IS NOT NULL) AS active,((SELECT count(*) FROM profiles p JOIN profile_revisions pr ON pr.profile_id=p.profile_id AND pr.revision=p.current_revision WHERE p.deleted_at IS NULL AND pr.content->'routing'->>'tts_configuration_id'=c.configuration_id::text)+(SELECT count(*) FROM core_profiles cp JOIN core_profile_revisions cr ON cr.core_profile_id=cp.core_profile_id AND cr.revision=cp.current_revision WHERE cp.deleted_at IS NULL AND cr.content->'routing'->>'tts_configuration_id'=c.configuration_id::text))::int AS profile_usage,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision LEFT JOIN installation_provider_selections s ON s.configuration_id=c.configuration_id AND s.installation_id=c.installation_id AND s.provider_kind='tts_provider' WHERE c.kind='tts_provider' AND c.deleted_at IS NULL ORDER BY active DESC,c.name LIMIT 100",
            'voice_catalog' => "SELECT v.configuration_id,v.voice_id,v.display_name,v.language,v.provider_status,v.custom_voice,v.discovered_at,c.installation_id,c.name AS connector_name FROM speech_connector_voices v JOIN configuration_sets c ON c.configuration_id=v.configuration_id WHERE c.kind='tts_provider' AND c.deleted_at IS NULL ORDER BY c.name,v.display_name,v.voice_id LIMIT 1024",
            'stt' => "SELECT c.configuration_id,c.installation_id,c.name,c.current_revision,r.content,(s.configuration_id IS NOT NULL) AS active,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision LEFT JOIN installation_provider_selections s ON s.configuration_id=c.configuration_id AND s.installation_id=c.installation_id AND s.provider_kind='stt_provider' WHERE c.kind='stt_provider' AND c.deleted_at IS NULL ORDER BY active DESC,c.name LIMIT 100",
            'prompts' => "SELECT c.configuration_id,c.installation_id,c.name,c.current_revision,r.content,((SELECT count(*) FROM profiles p JOIN profile_revisions pr ON pr.profile_id=p.profile_id AND pr.revision=p.current_revision WHERE p.deleted_at IS NULL AND pr.content->'routing'->>'prompt_configuration_id'=c.configuration_id::text)+(SELECT count(*) FROM core_profiles cp JOIN core_profile_revisions cr ON cr.core_profile_id=cp.core_profile_id AND cr.revision=cp.current_revision WHERE cp.deleted_at IS NULL AND cr.content->'routing'->>'prompt_configuration_id'=c.configuration_id::text))::int AS profile_usage,r.created_at,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions "
                . "FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='prompt' AND c.deleted_at IS NULL ORDER BY c.name LIMIT 100",
            'action_policies' => "SELECT c.configuration_id,c.installation_id,c.profile_id,p.name AS profile_name,c.name,c.current_revision,r.content,r.created_at,"
                . "(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions "
                . "FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision LEFT JOIN profiles p ON p.profile_id=c.profile_id AND p.deleted_at IS NULL WHERE c.kind='action_policy' AND c.deleted_at IS NULL ORDER BY c.name LIMIT 100",
            'actions' => "SELECT action_name,tier,client_capability,enabled,description FROM action_catalog ORDER BY tier,action_name LIMIT 100",
            'installations' => "SELECT installation_id,display_name,last_seen_at,created_at FROM installations WHERE revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 100",
            'global_settings' => "SELECT c.configuration_id,c.installation_id,i.display_name,c.name,c.current_revision,r.content,r.created_at,(SELECT jsonb_agg(jsonb_build_object('revision',history.revision,'reason',history.change_reason,'created_at',history.created_at) ORDER BY history.revision DESC) FROM configuration_revisions history WHERE history.configuration_id=c.configuration_id) AS revisions FROM configuration_sets c JOIN installations i ON i.installation_id=c.installation_id JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='global_settings' AND c.deleted_at IS NULL ORDER BY i.display_name LIMIT 100",
            'profile_preferences' => "SELECT i.installation_id,i.display_name,COALESCE(p.auto_lock_on_edit,true) AS auto_lock_on_edit,p.updated_at FROM installations i LEFT JOIN installation_profile_preferences p ON p.installation_id=i.installation_id WHERE i.revoked_at IS NULL ORDER BY i.last_seen_at DESC LIMIT 100",
            'autonomy' => "SELECT schedule_id,installation_id,profile_id,playthrough_id,kind,enabled,interval_seconds,cooldown_seconds,current_session_id,confirmed_at,last_triggered_at,updated_at FROM autonomy_schedules ORDER BY updated_at DESC LIMIT 100",
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
            'response_queue' => "SELECT COALESCE(speaker->>'display_name',speaker->>'name',speaker->>'record_id','Unknown') AS speaker,"
                . "left(text,1000) AS text,delivery_state,CASE WHEN delivery_state='pending' AND delivery_deadline_at<=clock_timestamp() THEN 'overdue' ELSE 'current' END AS queue_health,"
                . "emitted_at,delivery_deadline_at,delivered_at FROM dialogue_utterances ORDER BY emitted_at DESC LIMIT 100",
            'oghma_audit' => "SELECT domain,left(query,1000) AS query,cardinality(result_ids) AS result_count,algorithm,created_at "
                . "FROM retrieval_traces WHERE domain='knowledge' ORDER BY created_at DESC LIMIT 100",
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
            'database_manager' => "SELECT version,name,checksum,applied_at FROM schema_migrations ORDER BY version DESC LIMIT 100",
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
