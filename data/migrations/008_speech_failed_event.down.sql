-- Earlier code cannot replay speech.failed; drop those terminal notices and restore the prior event set.
DELETE FROM lorkhan_internal.response_events WHERE event_type = 'speech.failed';
ALTER TABLE lorkhan_internal.response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE lorkhan_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK ((event_type = ANY (ARRAY[
    'turn.accepted'::text, 'dialogue.delta'::text, 'dialogue.complete'::text, 'speech.ready'::text, 'action.intent'::text,
    'response.complete'::text, 'turn.complete'::text, 'turn.failed'::text, 'turn.cancelled'::text, 'stt.transcript'::text,
    'stt.failed'::text, 'director.instructions'::text, 'relationship.adjust'::text])));
