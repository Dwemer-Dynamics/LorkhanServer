DO $streamed_sentence_chunks$
BEGIN
    IF EXISTS (
        SELECT 1 FROM lorkhan_internal.dialogue_utterances
        WHERE utterance_index > 4 OR utterance_count > 4
    ) THEN
        RAISE EXCEPTION 'Cannot restore the four-utterance limit while longer streamed dialogue exists';
    END IF;
END
$streamed_sentence_chunks$;

ALTER TABLE lorkhan_internal.dialogue_utterances
    DROP CONSTRAINT dialogue_utterances_utterance_index_check,
    DROP CONSTRAINT dialogue_utterances_utterance_count_check,
    ADD CONSTRAINT dialogue_utterances_utterance_index_check
        CHECK (utterance_index BETWEEN 1 AND 4),
    ADD CONSTRAINT dialogue_utterances_utterance_count_check
        CHECK (utterance_count BETWEEN 1 AND 4);
