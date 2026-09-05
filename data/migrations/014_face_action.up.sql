INSERT INTO action_catalog (action_name, tier, description, parameter_schema, client_capability) VALUES
('ai.face', 1, 'Turn to face the player or a player-confirmed nearby actor with bounded actor-local control.',
 '{"type":"object","additionalProperties":false}'::jsonb, 'action.ai.face')
ON CONFLICT (action_name) DO UPDATE SET tier=EXCLUDED.tier, description=EXCLUDED.description,
    parameter_schema=EXCLUDED.parameter_schema, client_capability=EXCLUDED.client_capability;
