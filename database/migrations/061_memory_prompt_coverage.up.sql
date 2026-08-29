ALTER TABLE almsivi_internal.prompt_trace_sources
    DROP CONSTRAINT prompt_trace_sources_reason_check,
    ADD CONSTRAINT prompt_trace_sources_reason_check CHECK (reason IN (
        'included','section_limit','byte_limit','expired','deleted','policy_disabled',
        'covered_by_history','covered_by_memory'
    ));

ALTER TABLE almsivi_internal.prompt_trace_sections
    DROP CONSTRAINT prompt_trace_sections_inclusion_reason_check,
    ADD CONSTRAINT prompt_trace_sections_inclusion_reason_check CHECK (inclusion_reason IN (
        'included','empty','byte_limit','minimal_fallback','covered_by_history'
    ));
