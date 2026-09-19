-- Never relabel or delete historical traces to make a downgrade appear successful.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM lorkhan_internal.prompt_traces
        WHERE algorithm NOT IN (
            'deterministic-prompt-v1','chim-roleplay-prompt-v1','chim-compact-roleplay-prompt-v2',
            'chim-compact-roleplay-prompt-v3-markdown'
        )
    ) THEN
        RAISE EXCEPTION 'Cannot restore the legacy prompt label constraint while newer traces exist.';
    END IF;
END $$;
ALTER TABLE lorkhan_internal.prompt_traces
    DROP CONSTRAINT IF EXISTS prompt_traces_algorithm_check;
ALTER TABLE lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_algorithm_check CHECK (algorithm IN (
        'deterministic-prompt-v1','chim-roleplay-prompt-v1','chim-compact-roleplay-prompt-v2',
        'chim-compact-roleplay-prompt-v3-markdown'
    ));
