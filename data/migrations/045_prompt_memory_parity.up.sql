-- Ordered CHIM prompt traces and delivery-gated, revisioned memory history.
ALTER TABLE lorkhan_internal.prompt_trace_sources
    ADD COLUMN section_key text,
    ADD COLUMN section_order smallint,
    ADD COLUMN source_table text,
    ADD COLUMN source_revision integer,
    ADD COLUMN source_occurred_at timestamptz,
    ADD COLUMN playthrough_id uuid REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE,
    ADD COLUMN source_characters integer,
    ADD COLUMN estimated_tokens integer;

UPDATE lorkhan_internal.prompt_trace_sources source
SET section_key = CASE source_kind
        WHEN 'profile' THEN 'npc_context'
        WHEN 'core_profile' THEN 'npc_context'
        WHEN 'prompt' THEN 'npc_context'
        WHEN 'history' THEN 'conversation_context'
        WHEN 'memory' THEN 'memory_context'
        WHEN 'relationship' THEN 'relationships_factions'
        WHEN 'knowledge' THEN 'morrowind_context'
        WHEN 'narrative' THEN 'morrowind_context'
        WHEN 'action_result' THEN 'negotiated_actions'
        WHEN 'action_catalog' THEN 'negotiated_actions'
        ELSE 'current_turn'
    END,
    section_order = CASE source_kind
        WHEN 'profile' THEN 2 WHEN 'core_profile' THEN 2 WHEN 'prompt' THEN 2
        WHEN 'knowledge' THEN 4 WHEN 'narrative' THEN 4 WHEN 'relationship' THEN 5
        WHEN 'memory' THEN 6 WHEN 'history' THEN 7
        WHEN 'action_result' THEN 9 WHEN 'action_catalog' THEN 9 ELSE 10
    END,
    source_table = CASE source_kind
        WHEN 'profile' THEN 'profile_revisions'
        WHEN 'core_profile' THEN 'core_profile_revisions'
        WHEN 'prompt' THEN 'configuration_revisions'
        WHEN 'history' THEN 'eventlog'
        WHEN 'memory' THEN 'memory_records'
        WHEN 'relationship' THEN 'relationship_records'
        WHEN 'knowledge' THEN 'knowledge_documents'
        WHEN 'narrative' THEN 'narrative_records'
        WHEN 'action_result' THEN 'action_results'
        WHEN 'action_catalog' THEN 'action_catalog'
        ELSE 'turns'
    END,
    playthrough_id = trace.playthrough_id,
    source_characters = included_bytes,
    estimated_tokens = CASE WHEN included_bytes = 0 THEN 0 ELSE CEIL(included_bytes / 4.0)::integer END
FROM lorkhan_internal.prompt_traces trace
WHERE trace.prompt_trace_id = source.prompt_trace_id;

ALTER TABLE lorkhan_internal.prompt_trace_sources
    ALTER COLUMN section_key SET NOT NULL,
    ALTER COLUMN section_order SET NOT NULL,
    ALTER COLUMN source_table SET NOT NULL,
    ALTER COLUMN playthrough_id SET NOT NULL,
    ALTER COLUMN source_characters SET NOT NULL,
    ALTER COLUMN estimated_tokens SET NOT NULL,
    ADD CONSTRAINT prompt_trace_sources_section_order_check CHECK (section_order BETWEEN 1 AND 10),
    ADD CONSTRAINT prompt_trace_sources_source_revision_check CHECK (source_revision IS NULL OR source_revision > 0),
    ADD CONSTRAINT prompt_trace_sources_source_characters_check CHECK (source_characters BETWEEN 0 AND 131072),
    ADD CONSTRAINT prompt_trace_sources_estimated_tokens_check CHECK (estimated_tokens BETWEEN 0 AND 131072);

CREATE TABLE lorkhan_internal.prompt_trace_sections (
    prompt_trace_id uuid NOT NULL REFERENCES lorkhan_internal.prompt_traces(prompt_trace_id) ON DELETE CASCADE,
    section_order smallint NOT NULL CHECK (section_order BETWEEN 1 AND 10),
    section_key text NOT NULL CHECK (section_key IN (
        'output_contract','npc_context','player_narrator_context','morrowind_context','relationships_factions',
        'memory_context','conversation_context','audience_speaker_rules','negotiated_actions','current_turn'
    )),
    source_refs jsonb NOT NULL CHECK (jsonb_typeof(source_refs) = 'array'),
    inclusion_reason text NOT NULL CHECK (inclusion_reason IN ('included','empty','byte_limit','minimal_fallback')),
    source_occurred_at timestamptz,
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE,
    source_characters integer NOT NULL CHECK (source_characters BETWEEN 0 AND 131072),
    estimated_tokens integer NOT NULL CHECK (estimated_tokens BETWEEN 0 AND 131072),
    redacted_preview text NOT NULL CHECK (octet_length(redacted_preview) <= 256),
    source_sha256 char(64) NOT NULL CHECK (source_sha256 ~ '^[0-9a-f]{64}$'),
    PRIMARY KEY (prompt_trace_id, section_order),
    UNIQUE (prompt_trace_id, section_key)
);
CREATE INDEX prompt_trace_sections_playthrough_order
    ON lorkhan_internal.prompt_trace_sections (playthrough_id, prompt_trace_id, section_order);

ALTER TABLE lorkhan_internal.retrieval_traces
    ADD COLUMN turn_id uuid REFERENCES lorkhan_internal.turns(turn_id) ON DELETE SET NULL,
    ADD COLUMN prompt_section text,
    ADD COLUMN reasons jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(reasons) = 'object');
ALTER TABLE lorkhan_internal.retrieval_traces ADD CONSTRAINT retrieval_traces_prompt_section_check
    CHECK (prompt_section IS NULL OR prompt_section IN ('memory_context','morrowind_context'));
CREATE INDEX retrieval_traces_turn_section ON lorkhan_internal.retrieval_traces (turn_id, prompt_section, created_at);

ALTER TABLE lorkhan_internal.memory_records ADD COLUMN current_revision integer NOT NULL DEFAULT 1 CHECK (current_revision > 0);
CREATE TABLE lorkhan_internal.memory_record_revisions (
    memory_id uuid NOT NULL REFERENCES lorkhan_internal.memory_records(memory_id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision > 0),
    tier text NOT NULL CHECK (tier IN ('recent','mid','long')),
    content text NOT NULL CHECK (octet_length(content) BETWEEN 1 AND 16384),
    source_event_id uuid REFERENCES lorkhan_internal.source_events(source_event_id),
    provenance jsonb NOT NULL CHECK (jsonb_typeof(provenance) = 'object'),
    occurred_at timestamptz NOT NULL,
    deleted_at timestamptz,
    change_reason text NOT NULL CHECK (octet_length(change_reason) BETWEEN 1 AND 255),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (memory_id, revision)
);

INSERT INTO lorkhan_internal.memory_record_revisions
    (memory_id,revision,tier,content,source_event_id,provenance,occurred_at,deleted_at,change_reason,created_at)
SELECT memory_id,1,tier,content,source_event_id,provenance,occurred_at,deleted_at,'migration baseline',updated_at
FROM lorkhan_internal.memory_records;

CREATE FUNCTION lorkhan_internal.capture_memory_record_initial_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO lorkhan_internal.memory_record_revisions
        (memory_id,revision,tier,content,source_event_id,provenance,occurred_at,deleted_at,change_reason,created_at)
    VALUES (NEW.memory_id,NEW.current_revision,NEW.tier,NEW.content,NEW.source_event_id,NEW.provenance,
        NEW.occurred_at,NEW.deleted_at,'created',NEW.created_at);
    RETURN NEW;
END;
$$;
CREATE TRIGGER memory_record_initial_revision_capture
AFTER INSERT ON lorkhan_internal.memory_records
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.capture_memory_record_initial_revision();

CREATE FUNCTION lorkhan_internal.capture_memory_record_revision() RETURNS trigger LANGUAGE plpgsql AS $$
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
CREATE TRIGGER memory_record_revision_capture
BEFORE UPDATE OF tier,content,source_event_id,provenance,occurred_at,deleted_at ON lorkhan_internal.memory_records
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.capture_memory_record_revision();
