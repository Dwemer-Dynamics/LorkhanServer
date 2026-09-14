CREATE TABLE lorkhan_internal.director_plans (
    plan_id uuid PRIMARY KEY REFERENCES lorkhan_internal.durable_jobs(job_id),
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
    session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id),
    playthrough_id uuid NOT NULL,
    generation bigint NOT NULL,
    origin_turn_id uuid NOT NULL UNIQUE REFERENCES lorkhan_internal.turns(turn_id),
    request_id uuid NOT NULL,
    input jsonb NOT NULL,
    state text NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','delivered','cancelled')),
    expires_at timestamptz NOT NULL DEFAULT clock_timestamp()+interval '300 seconds'
);
CREATE TABLE lorkhan_internal.director_instructions (
    instruction_id uuid PRIMARY KEY,
    plan_id uuid NOT NULL REFERENCES lorkhan_internal.director_plans(plan_id) ON DELETE CASCADE,
    ordinal smallint NOT NULL CHECK(ordinal BETWEEN 1 AND 3),
    actor jsonb NOT NULL,
    recipient jsonb NOT NULL,
    instruction text NOT NULL CHECK(octet_length(instruction) BETWEEN 1 AND 2000),
    scene_note text NOT NULL CHECK(octet_length(scene_note)<=1000),
    child_turn_id uuid UNIQUE,
    UNIQUE(plan_id,ordinal)
);
ALTER TABLE lorkhan_internal.response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE lorkhan_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK(event_type IN (
    'turn.accepted','dialogue.delta','dialogue.complete','speech.ready','action.intent','response.complete',
    'turn.complete','turn.failed','turn.cancelled','stt.transcript','stt.failed','director.instructions'
));
