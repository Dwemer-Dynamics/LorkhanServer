DROP INDEX lorkhan_internal.action_intents_conversation_end_session;
DELETE FROM lorkhan_internal.action_catalog WHERE action_name='conversation.end';
