DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM lorkhan_internal.disposition_adjustments) THEN
  RAISE EXCEPTION 'Archive disposition adjustment receipts before reverting migration 126';
 END IF;
END $$;
ALTER TABLE lorkhan_internal.response_events DROP CONSTRAINT response_events_event_type_check;
ALTER TABLE lorkhan_internal.response_events ADD CONSTRAINT response_events_event_type_check CHECK(event_type IN (
    'turn.accepted','dialogue.delta','dialogue.complete','speech.ready','action.intent','response.complete',
    'turn.complete','turn.failed','turn.cancelled','stt.transcript','stt.failed','director.instructions'
));
DROP TABLE lorkhan_internal.disposition_adjustments;
DROP TABLE lorkhan_internal.game_dispositions;
