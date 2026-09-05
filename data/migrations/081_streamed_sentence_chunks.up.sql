ALTER TABLE lorkhan_internal.dialogue_utterances
    DROP CONSTRAINT dialogue_utterances_utterance_index_check,
    DROP CONSTRAINT dialogue_utterances_utterance_count_check,
    ADD CONSTRAINT dialogue_utterances_utterance_index_check
        CHECK (utterance_index BETWEEN 1 AND 32),
    ADD CONSTRAINT dialogue_utterances_utterance_count_check
        CHECK (utterance_count BETWEEN 1 AND 32);
