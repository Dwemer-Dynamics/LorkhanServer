-- Keep Oghma independently traceable and available after lower-priority world context is trimmed.
ALTER TABLE lorkhan_internal.prompt_traces
    DROP CONSTRAINT IF EXISTS prompt_traces_algorithm_check,
    ADD CONSTRAINT prompt_traces_algorithm_check CHECK (algorithm IN (
        'deterministic-prompt-v1','chim-roleplay-prompt-v1','chim-compact-roleplay-prompt-v2'
    ));

ALTER TABLE lorkhan_internal.prompt_trace_sources
    DROP CONSTRAINT IF EXISTS prompt_trace_sources_section_order_check,
    ADD CONSTRAINT prompt_trace_sources_section_order_check CHECK (section_order BETWEEN 1 AND 11);

ALTER TABLE lorkhan_internal.prompt_trace_sections
    DROP CONSTRAINT IF EXISTS prompt_trace_sections_section_order_check,
    DROP CONSTRAINT IF EXISTS prompt_trace_sections_section_key_check,
    ADD CONSTRAINT prompt_trace_sections_section_order_check CHECK (section_order BETWEEN 1 AND 11),
    ADD CONSTRAINT prompt_trace_sections_section_key_check CHECK (section_key IN (
        'output_contract','npc_context','player_narrator_context','morrowind_context','oghma_context',
        'relationships_factions','memory_context','conversation_context','audience_speaker_rules',
        'negotiated_actions','current_turn'
    ));

ALTER TABLE lorkhan_internal.retrieval_traces
    DROP CONSTRAINT IF EXISTS retrieval_traces_prompt_section_check,
    ADD CONSTRAINT retrieval_traces_prompt_section_check CHECK (
        prompt_section IS NULL OR prompt_section IN ('memory_context','morrowind_context','oghma_context')
    );
