INSERT INTO action_catalog (action_name, tier, description, parameter_schema, client_capability) VALUES
('animation.play', 1, 'Play one allowlisted single-loop idle animation on the selected actor.', '{"type":"object","properties":{"group":{"type":"string","enum":["idle2","idle3","idle4","idle5","idle6","idle7","idle8","idle9"]}},"required":["group"],"additionalProperties":false}'::jsonb, 'action.animation.play'),
('item.use', 2, 'Use one existing inventory item after explicit player confirmation.', '{"type":"object","properties":{"record_id":{"type":"string","minLength":1,"maxLength":128}},"required":["record_id"],"additionalProperties":false}'::jsonb, 'action.item.use')
ON CONFLICT (action_name) DO UPDATE SET tier=EXCLUDED.tier, description=EXCLUDED.description,
    parameter_schema=EXCLUDED.parameter_schema, client_capability=EXCLUDED.client_capability;
