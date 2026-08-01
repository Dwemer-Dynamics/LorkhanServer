INSERT INTO action_catalog (action_name, tier, description, parameter_schema, client_capability) VALUES
('ai.stop', 1, 'Stop ALMSIVI movement packages owned by this actor.', '{"type":"object","additionalProperties":false}'::jsonb, 'action.ai.stop'),
('ai.wander', 1, 'Ask an actor to wander for a bounded distance and duration.', '{"type":"object","properties":{"distance":{"type":"integer","minimum":0,"maximum":2048},"duration_seconds":{"type":"integer","minimum":1,"maximum":3600}},"required":["distance","duration_seconds"],"additionalProperties":false}'::jsonb, 'action.ai.wander'),
('combat.start', 2, 'Start combat with the selected target after explicit player confirmation.', '{"type":"object","additionalProperties":false}'::jsonb, 'action.combat.start'),
('combat.stop', 1, 'Stop combat with the selected target.', '{"type":"object","additionalProperties":false}'::jsonb, 'action.combat.stop')
ON CONFLICT (action_name) DO UPDATE SET tier=EXCLUDED.tier, description=EXCLUDED.description,
    parameter_schema=EXCLUDED.parameter_schema, client_capability=EXCLUDED.client_capability;
