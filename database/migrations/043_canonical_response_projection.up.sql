-- Persist the single canonical response projection consumed by every downstream delivery surface.
ALTER TABLE almsivi_internal.turns
    ADD COLUMN runtime_generation bigint NOT NULL DEFAULT 1 CHECK (runtime_generation > 0),
    ADD COLUMN response_id uuid,
    ADD COLUMN response_payload jsonb,
    ADD COLUMN response_created_at timestamptz,
    ADD CONSTRAINT turns_response_projection_shape CHECK (
        (response_id IS NULL AND response_payload IS NULL AND response_created_at IS NULL)
        OR (response_id IS NOT NULL AND jsonb_typeof(response_payload) = 'object' AND response_created_at IS NOT NULL)
    );

CREATE UNIQUE INDEX turns_response_id_unique ON almsivi_internal.turns(response_id) WHERE response_id IS NOT NULL;

ALTER TABLE almsivi_internal.dialogue_utterances
    ADD COLUMN response_line_id uuid,
    ADD COLUMN utterance_id uuid,
    ADD COLUMN runtime_generation bigint NOT NULL DEFAULT 1 CHECK (runtime_generation > 0);

UPDATE almsivi_internal.dialogue_utterances
SET response_line_id = dialogue_message_id,
    utterance_id = dialogue_message_id
WHERE response_line_id IS NULL OR utterance_id IS NULL;

ALTER TABLE almsivi_internal.dialogue_utterances
    ALTER COLUMN response_line_id SET NOT NULL,
    ALTER COLUMN utterance_id SET NOT NULL,
    ADD CONSTRAINT dialogue_utterances_response_line_unique UNIQUE(response_line_id),
    ADD CONSTRAINT dialogue_utterances_utterance_unique UNIQUE(utterance_id);
