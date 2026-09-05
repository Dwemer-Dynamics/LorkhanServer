ALTER TABLE response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE response_events ADD CONSTRAINT response_events_event_type_check CHECK (event_type IN (
    'turn.accepted','dialogue.complete','speech.ready','action.intent','turn.complete','turn.failed','turn.cancelled'
));
DROP TABLE IF EXISTS dialogue_delivery_results;
DROP TABLE IF EXISTS stt_requests;
