DROP INDEX IF EXISTS rechat_one_open_chain_per_session;
DROP TABLE IF EXISTS rechat_chains;
DROP TABLE IF EXISTS prompts;
DROP TABLE IF EXISTS responselog;
DROP TABLE IF EXISTS speech;
DROP VIEW IF EXISTS eventlog_view;
DROP TABLE IF EXISTS eventlog;
DELETE FROM prompt_trace_sources WHERE source_kind = 'history';
UPDATE prompt_traces SET algorithm = 'deterministic-prompt-v1' WHERE algorithm = 'chim-roleplay-prompt-v1';
ALTER TABLE prompt_traces DROP CONSTRAINT prompt_traces_algorithm_check;
ALTER TABLE prompt_traces ADD CONSTRAINT prompt_traces_algorithm_check CHECK (algorithm = 'deterministic-prompt-v1');
ALTER TABLE prompt_trace_sources DROP CONSTRAINT prompt_trace_sources_source_kind_check;
ALTER TABLE prompt_trace_sources ADD CONSTRAINT prompt_trace_sources_source_kind_check
    CHECK (source_kind IN (
        'profile','core_profile','prompt','turn','memory','relationship','knowledge','narrative','action_result','action_catalog'
    ));
