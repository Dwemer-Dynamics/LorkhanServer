UPDATE almsivi_internal.retrieval_traces
SET prompt_section='morrowind_context'
WHERE prompt_section='oghma_context';

DELETE FROM almsivi_internal.prompt_trace_sections
WHERE section_key='oghma_context'
  AND prompt_trace_id IN (
      SELECT prompt_trace_id FROM almsivi_internal.prompt_traces
      WHERE algorithm='chim-compact-roleplay-prompt-v2'
  );

ALTER TABLE almsivi_internal.prompt_trace_sections
    DROP CONSTRAINT IF EXISTS prompt_trace_sections_section_order_check;

UPDATE almsivi_internal.prompt_trace_sections
SET section_order=-section_order
WHERE section_order>5
  AND prompt_trace_id IN (
      SELECT prompt_trace_id FROM almsivi_internal.prompt_traces
      WHERE algorithm='chim-compact-roleplay-prompt-v2'
  );

UPDATE almsivi_internal.prompt_trace_sections
SET section_order=(-section_order)-1
WHERE section_order<0
  AND prompt_trace_id IN (
      SELECT prompt_trace_id FROM almsivi_internal.prompt_traces
      WHERE algorithm='chim-compact-roleplay-prompt-v2'
  );

UPDATE almsivi_internal.prompt_trace_sources
SET section_key=CASE WHEN source_kind='knowledge' THEN 'morrowind_context' ELSE section_key END,
    section_order=CASE WHEN source_kind='knowledge' THEN 4
        WHEN section_order>5 THEN section_order-1 ELSE section_order END
WHERE prompt_trace_id IN (
    SELECT prompt_trace_id FROM almsivi_internal.prompt_traces
    WHERE algorithm='chim-compact-roleplay-prompt-v2'
);

UPDATE almsivi_internal.prompt_traces
SET algorithm='chim-roleplay-prompt-v1'
WHERE algorithm='chim-compact-roleplay-prompt-v2';

ALTER TABLE almsivi_internal.prompt_traces
    DROP CONSTRAINT IF EXISTS prompt_traces_algorithm_check,
    ADD CONSTRAINT prompt_traces_algorithm_check CHECK (algorithm IN (
        'deterministic-prompt-v1','chim-roleplay-prompt-v1'
    ));

ALTER TABLE almsivi_internal.prompt_trace_sources
    DROP CONSTRAINT IF EXISTS prompt_trace_sources_section_order_check,
    ADD CONSTRAINT prompt_trace_sources_section_order_check CHECK (section_order BETWEEN 1 AND 10);

ALTER TABLE almsivi_internal.prompt_trace_sections
    DROP CONSTRAINT IF EXISTS prompt_trace_sections_section_key_check,
    ADD CONSTRAINT prompt_trace_sections_section_order_check CHECK (section_order BETWEEN 1 AND 10),
    ADD CONSTRAINT prompt_trace_sections_section_key_check CHECK (section_key IN (
        'output_contract','npc_context','player_narrator_context','morrowind_context','relationships_factions',
        'memory_context','conversation_context','audience_speaker_rules','negotiated_actions','current_turn'
    ));

ALTER TABLE almsivi_internal.retrieval_traces
    DROP CONSTRAINT IF EXISTS retrieval_traces_prompt_section_check,
    ADD CONSTRAINT retrieval_traces_prompt_section_check CHECK (
        prompt_section IS NULL OR prompt_section IN ('memory_context','morrowind_context')
    );
