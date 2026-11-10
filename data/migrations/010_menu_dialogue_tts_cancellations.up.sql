-- An explicit client cancel stops one in-flight or not-yet-started menu/book speech request.
-- Rows matter only while that request can still run; operational retention removes them after a day.
CREATE TABLE lorkhan_internal.menu_dialogue_tts_cancellations (
    installation_id uuid NOT NULL,
    message_id uuid NOT NULL,
    session_id uuid NOT NULL,
    generation bigint NOT NULL,
    cancel_message_id uuid NOT NULL,
    cancelled_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT menu_dialogue_tts_cancellations_pkey PRIMARY KEY (installation_id, message_id),
    CONSTRAINT menu_dialogue_tts_cancellations_generation_check CHECK ((generation >= 0))
);
CREATE INDEX menu_dialogue_tts_cancellations_cancelled_at ON lorkhan_internal.menu_dialogue_tts_cancellations USING btree (cancelled_at);
