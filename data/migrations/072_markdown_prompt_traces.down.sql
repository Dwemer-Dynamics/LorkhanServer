DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM lorkhan_internal.prompt_traces
        WHERE algorithm='chim-compact-roleplay-prompt-v3-markdown'
    ) THEN
        RAISE EXCEPTION 'Cannot remove Markdown prompt trace support while Markdown traces exist.';
    END IF;
END $$;

ALTER TABLE lorkhan_internal.prompt_traces
    DROP CONSTRAINT prompt_traces_algorithm_check,
    ADD CONSTRAINT prompt_traces_algorithm_check CHECK (algorithm IN (
        'deterministic-prompt-v1','chim-roleplay-prompt-v1','chim-compact-roleplay-prompt-v2'
    ));
