INSERT INTO lorkhan_internal.action_catalog
    (action_name,tier,description,parameter_schema,client_capability,display_name,category,sort_order,
     confirmation_mode,followup_default,followup_actions_supported,cooldown_seconds)
VALUES
    ('conversation.end',1,'End the conversation, release LORKHAN-owned actor packages and temporarily stop accepting AI conversation.',
     '{"type":"object","additionalProperties":false}'::jsonb,'action.conversation.end','End Conversation','Conversation',30,
     'none',false,false,0);
CREATE INDEX action_intents_conversation_end_session
    ON lorkhan_internal.action_intents(session_id,emitted_at DESC)
    WHERE action_name='conversation.end';
