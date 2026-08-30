ALTER TABLE lorkhan_internal.prompt_traces
    DROP CONSTRAINT prompt_traces_algorithm_check,
    ADD CONSTRAINT prompt_traces_algorithm_check CHECK (algorithm IN (
        'deterministic-prompt-v1','chim-roleplay-prompt-v1','chim-compact-roleplay-prompt-v2',
        'chim-compact-roleplay-prompt-v3-markdown'
    ));
