INSERT INTO lorkhan_internal.action_catalog
    (action_name,tier,description,parameter_schema,client_capability,display_name,category,sort_order,
     confirmation_mode,continuation_capable,followup_default,followup_actions_supported,cooldown_seconds)
VALUES
    ('weapon.sheathe',1,'Sheathe the acting NPC weapon or lower readied magic without removing equipment or changing AI packages. A committed attack or spell can prevent sheathing.',
     '{"type":"object","additionalProperties":false}'::jsonb,'action.weapon.sheathe','Sheathe Weapon','Combat',230,
     'optional',true,false,true,0);
