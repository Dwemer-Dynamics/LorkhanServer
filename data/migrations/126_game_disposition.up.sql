CREATE TABLE lorkhan_internal.game_dispositions (
 installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
 playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id),
 actor_key text NOT NULL, player_key text NOT NULL,
 actor jsonb NOT NULL, player jsonb NOT NULL,
 base_disposition integer NOT NULL, disposition integer NOT NULL CHECK(disposition BETWEEN 0 AND 100),
 session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id), generation bigint NOT NULL,
 observed_at timestamptz NOT NULL, source_event_id uuid NOT NULL REFERENCES lorkhan_internal.source_events(source_event_id),
 PRIMARY KEY(installation_id,playthrough_id,actor_key,player_key)
);
CREATE TABLE lorkhan_internal.disposition_adjustments (
 adjustment_id uuid PRIMARY KEY, job_id uuid NOT NULL UNIQUE REFERENCES lorkhan_internal.durable_jobs(job_id),
 installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
 playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id),
 session_id uuid NOT NULL REFERENCES lorkhan_internal.sessions(session_id), generation bigint NOT NULL,
 turn_id uuid NOT NULL REFERENCES lorkhan_internal.turns(turn_id), actor_key text NOT NULL,player_key text NOT NULL,
 actor jsonb NOT NULL,player jsonb NOT NULL, delta integer NOT NULL CHECK(delta BETWEEN -3 AND 3 AND delta<>0),
 status text NOT NULL DEFAULT 'pending' CHECK(status IN('pending','applied','rejected')),
 expires_at timestamptz NOT NULL, confirmed_source_id uuid REFERENCES lorkhan_internal.source_events(source_event_id),
 created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE lorkhan_internal.response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE lorkhan_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK(event_type IN (
    'turn.accepted','dialogue.delta','dialogue.complete','speech.ready','action.intent','response.complete',
    'turn.complete','turn.failed','turn.cancelled','stt.transcript','stt.failed','director.instructions','relationship.adjust'
));
