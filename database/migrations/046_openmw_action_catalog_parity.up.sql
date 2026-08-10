INSERT INTO almsivi_internal.action_catalog (action_name, tier, description, parameter_schema, client_capability) VALUES
('inventory.inspect', 0, 'Read a bounded snapshot of the acting NPC inventory without moving items.',
 '{"type":"object","additionalProperties":false}'::jsonb, 'action.inventory.inspect'),
('ai.approach', 1, 'Travel to the current same-cell position of the addressed actor.',
 '{"type":"object","additionalProperties":false}'::jsonb, 'action.ai.approach'),
('ai.wait', 1, 'Wait in place for a bounded duration using an owned non-repeating Wander package.',
 '{"type":"object","properties":{"duration_seconds":{"type":"integer","minimum":3600,"maximum":86400,"multipleOf":3600}},"required":["duration_seconds"],"additionalProperties":false}'::jsonb, 'action.ai.wait')
ON CONFLICT (action_name) DO UPDATE SET tier=EXCLUDED.tier, description=EXCLUDED.description,
    parameter_schema=EXCLUDED.parameter_schema, client_capability=EXCLUDED.client_capability;

UPDATE almsivi_internal.action_catalog
SET parameter_schema='{"type":"object","properties":{"distance":{"type":"integer","minimum":0,"maximum":2048},"duration_seconds":{"type":"integer","minimum":3600,"maximum":86400,"multipleOf":3600}},"required":["distance","duration_seconds"],"additionalProperties":false}'::jsonb
WHERE action_name='ai.wander';
