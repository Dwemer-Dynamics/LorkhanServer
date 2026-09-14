INSERT INTO lorkhan_internal.action_catalog
    (action_name,tier,description,parameter_schema,client_capability,display_name,category,sort_order,
     confirmation_mode,continuation_capable,followup_default,followup_actions_supported,cooldown_seconds)
VALUES ('spell.cast',2,'Cast an observed known spell or power with normal animation, resource and success checks. Success records a cast, not a guaranteed hit.',
    '{"type":"object","additionalProperties":false,"required":["spell_id"],"properties":{"spell_id":{"type":"string","minLength":1,"maxLength":256}}}'::jsonb,
    'action.spell.cast','Cast Spell','Combat',240,'required',true,false,true,0);
