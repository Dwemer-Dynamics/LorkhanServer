-- Delivered instruction events must be removed by the backed-up replay before this downgrade.
ALTER TABLE lorkhan_internal.response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE lorkhan_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK(event_type IN (
    'turn.accepted','dialogue.delta','dialogue.complete','speech.ready','action.intent','response.complete',
    'turn.complete','turn.failed','turn.cancelled','stt.transcript','stt.failed'
));
DROP TABLE lorkhan_internal.director_instructions;
DROP TABLE lorkhan_internal.director_plans;
