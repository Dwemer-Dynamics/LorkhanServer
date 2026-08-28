-- Preserve audit rows; older readers use the original coarse omission reasons.
UPDATE almsivi_internal.prompt_trace_sources SET reason='section_limit'
    WHERE reason IN ('covered_by_history','covered_by_memory');
ALTER TABLE almsivi_internal.prompt_trace_sources
    DROP CONSTRAINT prompt_trace_sources_reason_check,
    ADD CONSTRAINT prompt_trace_sources_reason_check CHECK (reason IN (
        'included','section_limit','byte_limit','expired','deleted','policy_disabled'
    ));

UPDATE almsivi_internal.prompt_trace_sections SET inclusion_reason='empty'
    WHERE inclusion_reason='covered_by_history';
ALTER TABLE almsivi_internal.prompt_trace_sections
    DROP CONSTRAINT prompt_trace_sections_inclusion_reason_check,
    ADD CONSTRAINT prompt_trace_sections_inclusion_reason_check CHECK (inclusion_reason IN (
        'included','empty','byte_limit','minimal_fallback'
    ));
