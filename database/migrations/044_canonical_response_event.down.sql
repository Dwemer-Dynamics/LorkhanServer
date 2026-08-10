ALTER TABLE almsivi_internal.response_events DROP CONSTRAINT IF EXISTS response_events_event_type_check;
ALTER TABLE almsivi_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK (event_type IN (
    'turn.accepted','dialogue.delta','dialogue.complete','speech.ready','action.intent',
    'turn.complete','turn.failed','turn.cancelled','stt.transcript','stt.failed'
));
