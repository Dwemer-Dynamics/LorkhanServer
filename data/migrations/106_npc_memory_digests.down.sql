-- Refuse to discard recorded digest trace evidence on downgrade.
ALTER TABLE lorkhan_internal.prompt_trace_sources DROP CONSTRAINT prompt_trace_sources_source_kind_check;
ALTER TABLE lorkhan_internal.prompt_trace_sources ADD CONSTRAINT prompt_trace_sources_source_kind_check CHECK (source_kind IN (
    'profile','core_profile','prompt','history','turn','memory','relationship','knowledge','narrative','action_result','action_catalog'
));
DROP TABLE lorkhan_internal.npc_memory_digests;
