ALTER TABLE turns ADD COLUMN processing_started_at timestamptz;
ALTER TABLE turns ADD COLUMN processing_attempts integer NOT NULL DEFAULT 0 CHECK (processing_attempts >= 0);

CREATE TABLE dialogue_utterances (
    dialogue_message_id uuid PRIMARY KEY,
    session_id uuid NOT NULL REFERENCES sessions(session_id),
    turn_id uuid NOT NULL REFERENCES turns(turn_id),
    request_id uuid NOT NULL,
    generation bigint NOT NULL CHECK (generation >= 0),
    utterance_index smallint NOT NULL CHECK (utterance_index BETWEEN 1 AND 4),
    utterance_count smallint NOT NULL CHECK (utterance_count BETWEEN 1 AND 4),
    speaker jsonb NOT NULL CHECK (jsonb_typeof(speaker) = 'object'),
    addressee jsonb NOT NULL CHECK (jsonb_typeof(addressee) = 'object'),
    audience jsonb NOT NULL CHECK (jsonb_typeof(audience) = 'array'),
    text text NOT NULL CHECK (octet_length(text) BETWEEN 1 AND 16384),
    emitted_at timestamptz NOT NULL,
    delivery_deadline_at timestamptz NOT NULL,
    delivery_state text NOT NULL DEFAULT 'pending' CHECK (delivery_state IN ('pending','played','failed','expired','interrupted')),
    delivered_at timestamptz,
    UNIQUE (turn_id, utterance_index)
);
CREATE INDEX dialogue_utterances_pending ON dialogue_utterances (delivery_deadline_at, dialogue_message_id)
    WHERE delivery_state = 'pending';

-- Migration 006 allowed multiple utterance results to share the originating turn request_id,
-- and existing rows predate dialogue_utterances. Preserve those rows while enforcing the
-- dialogue relationship for all new writes.
ALTER TABLE dialogue_delivery_results
    ADD CONSTRAINT dialogue_delivery_results_dialogue_fk FOREIGN KEY (dialogue_message_id)
    REFERENCES dialogue_utterances(dialogue_message_id) NOT VALID;

ALTER TABLE action_delivery RENAME COLUMN delivered_at TO emitted_at;

-- Existing nonterminal turns predate the asynchronous queue. Backfill one deterministic job each.
INSERT INTO durable_jobs(job_id,job_type,schema_version,idempotency_key,payload,max_attempts,priority)
SELECT md5('turn.process:v1:'||t.turn_id::text)::uuid,'turn.process',1,'turn:'||t.turn_id::text,
    jsonb_build_object('turn_id',t.turn_id,'session_id',t.session_id,'generation',t.generation),3,100
FROM turns t WHERE t.state IN('accepted','processing')
ON CONFLICT(job_type,idempotency_key) DO NOTHING;
