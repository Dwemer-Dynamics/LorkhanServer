INSERT INTO lorkhan_internal.action_catalog
    (action_name,tier,description,parameter_schema,client_capability,display_name,category,sort_order,
     confirmation_mode,continuation_capable,followup_default,followup_actions_supported,cooldown_seconds)
SELECT 'service.'||service,1,
       'Open the acting NPC service menu through normal dialogue availability and refusal checks. Success means the menu opened, not a completed purchase or service.',
       '{"type":"object","additionalProperties":false}'::jsonb,'action.service.'||service,label,'Services',300+ordinal,
       'optional',true,false,true,0
FROM (VALUES ('barter','Barter',1),('training','Training',2),('spells','Buy Spells',3),('travel','Travel',4),
             ('spellmaking','Spellmaking',5),('enchanting','Enchanting',6),('repair','Repair',7)) AS services(service,label,ordinal);
