INSERT INTO action_catalog (action_name, tier, description, parameter_schema, client_capability) VALUES
('ai.travel', 1, 'Travel to a same-cell point explicitly aimed at and confirmed by the player.',
 '{"type":"object","properties":{"destination_x":{"type":"number","minimum":-100000000,"maximum":100000000},"destination_y":{"type":"number","minimum":-100000000,"maximum":100000000},"destination_z":{"type":"number","minimum":-100000000,"maximum":100000000},"destination_cell":{"type":"string","minLength":1,"maxLength":300}},"required":["destination_x","destination_y","destination_z","destination_cell"],"additionalProperties":false}'::jsonb,
 'action.ai.travel'),
('ai.escort', 1, 'Escort the player to a same-cell point explicitly aimed at and confirmed by the player.',
 '{"type":"object","properties":{"destination_x":{"type":"number","minimum":-100000000,"maximum":100000000},"destination_y":{"type":"number","minimum":-100000000,"maximum":100000000},"destination_z":{"type":"number","minimum":-100000000,"maximum":100000000},"destination_cell":{"type":"string","minLength":1,"maxLength":300}},"required":["destination_x","destination_y","destination_z","destination_cell"],"additionalProperties":false}'::jsonb,
 'action.ai.escort')
ON CONFLICT (action_name) DO UPDATE SET tier=EXCLUDED.tier, description=EXCLUDED.description,
    parameter_schema=EXCLUDED.parameter_schema, client_capability=EXCLUDED.client_capability;
