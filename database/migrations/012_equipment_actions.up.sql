INSERT INTO action_catalog (action_name, tier, description, parameter_schema, client_capability) VALUES
('item.equip', 2, 'Equip one existing inventory item into an explicit OpenMW equipment slot after player confirmation.',
 '{"type":"object","properties":{"record_id":{"type":"string","minLength":1,"maxLength":128},"slot":{"type":"string","enum":["helmet","cuirass","greaves","left_pauldron","right_pauldron","left_gauntlet","right_gauntlet","boots","shirt","pants","skirt","robe","left_ring","right_ring","amulet","belt","carried_right","carried_left","ammunition"]}},"required":["record_id","slot"],"additionalProperties":false}'::jsonb,
 'action.item.equip'),
('item.unequip', 2, 'Clear one explicit OpenMW equipment slot after player confirmation.',
 '{"type":"object","properties":{"slot":{"type":"string","enum":["helmet","cuirass","greaves","left_pauldron","right_pauldron","left_gauntlet","right_gauntlet","boots","shirt","pants","skirt","robe","left_ring","right_ring","amulet","belt","carried_right","carried_left","ammunition"]}},"required":["slot"],"additionalProperties":false}'::jsonb,
 'action.item.unequip')
ON CONFLICT (action_name) DO UPDATE SET tier=EXCLUDED.tier, description=EXCLUDED.description,
    parameter_schema=EXCLUDED.parameter_schema, client_capability=EXCLUDED.client_capability;
