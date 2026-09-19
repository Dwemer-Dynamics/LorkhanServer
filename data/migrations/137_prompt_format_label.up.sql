-- Prompt format is diagnostic metadata, not a protocol version or dialogue admission rule.
-- Keep historical labels intact and allow one stable label for all future Markdown prompts.
ALTER TABLE lorkhan_internal.prompt_traces
    DROP CONSTRAINT IF EXISTS prompt_traces_algorithm_check;
ALTER TABLE lorkhan_internal.prompt_traces
    ADD CONSTRAINT prompt_traces_algorithm_check
    CHECK (char_length(btrim(algorithm)) BETWEEN 1 AND 128);
