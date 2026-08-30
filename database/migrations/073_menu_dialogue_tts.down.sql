DELETE FROM lorkhan_internal.media_objects WHERE menu_dialogue_message_id IS NOT NULL;
DROP INDEX lorkhan_internal.media_objects_menu_dialogue_message_unique;
ALTER TABLE lorkhan_internal.media_objects DROP CONSTRAINT media_objects_single_source_check;
ALTER TABLE lorkhan_internal.media_objects DROP COLUMN menu_dialogue_message_id;
ALTER TABLE lorkhan_internal.media_objects ALTER COLUMN turn_id SET NOT NULL;
DROP TABLE lorkhan_internal.menu_dialogue_tts_requests;
