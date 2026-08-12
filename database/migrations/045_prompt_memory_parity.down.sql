DROP TRIGGER IF EXISTS memory_record_initial_revision_capture ON almsivi_internal.memory_records;
DROP FUNCTION IF EXISTS almsivi_internal.capture_memory_record_initial_revision();
DROP TRIGGER IF EXISTS memory_record_revision_capture ON almsivi_internal.memory_records;
DROP FUNCTION IF EXISTS almsivi_internal.capture_memory_record_revision();
DROP TABLE IF EXISTS almsivi_internal.memory_record_revisions;
ALTER TABLE almsivi_internal.memory_records DROP COLUMN IF EXISTS current_revision;

DROP INDEX IF EXISTS retrieval_traces_turn_section;
ALTER TABLE almsivi_internal.retrieval_traces
    DROP CONSTRAINT IF EXISTS retrieval_traces_prompt_section_check,
    DROP COLUMN IF EXISTS reasons,
    DROP COLUMN IF EXISTS prompt_section,
    DROP COLUMN IF EXISTS turn_id;

DROP TABLE IF EXISTS almsivi_internal.prompt_trace_sections;
ALTER TABLE almsivi_internal.prompt_trace_sources
    DROP CONSTRAINT IF EXISTS prompt_trace_sources_estimated_tokens_check,
    DROP CONSTRAINT IF EXISTS prompt_trace_sources_source_characters_check,
    DROP CONSTRAINT IF EXISTS prompt_trace_sources_source_revision_check,
    DROP CONSTRAINT IF EXISTS prompt_trace_sources_section_order_check,
    DROP COLUMN IF EXISTS estimated_tokens,
    DROP COLUMN IF EXISTS source_characters,
    DROP COLUMN IF EXISTS playthrough_id,
    DROP COLUMN IF EXISTS source_occurred_at,
    DROP COLUMN IF EXISTS source_revision,
    DROP COLUMN IF EXISTS source_table,
    DROP COLUMN IF EXISTS section_order,
    DROP COLUMN IF EXISTS section_key;
