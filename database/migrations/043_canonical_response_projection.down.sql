ALTER TABLE lorkhan_internal.dialogue_utterances
    DROP CONSTRAINT IF EXISTS dialogue_utterances_utterance_unique,
    DROP CONSTRAINT IF EXISTS dialogue_utterances_response_line_unique,
    DROP COLUMN IF EXISTS runtime_generation,
    DROP COLUMN IF EXISTS utterance_id,
    DROP COLUMN IF EXISTS response_line_id;

DROP INDEX IF EXISTS lorkhan_internal.turns_response_id_unique;

ALTER TABLE lorkhan_internal.turns
    DROP CONSTRAINT IF EXISTS turns_response_projection_shape,
    DROP COLUMN IF EXISTS response_created_at,
    DROP COLUMN IF EXISTS response_payload,
    DROP COLUMN IF EXISTS response_id,
    DROP COLUMN IF EXISTS runtime_generation;
