DELETE FROM lorkhan_internal.action_catalog WHERE action_name IN ('inventory.inspect', 'ai.approach', 'ai.wait');
UPDATE lorkhan_internal.action_catalog
SET parameter_schema='{"type":"object","properties":{"distance":{"type":"integer","minimum":0,"maximum":2048},"duration_seconds":{"type":"integer","minimum":1,"maximum":3600}},"required":["distance","duration_seconds"],"additionalProperties":false}'::jsonb
WHERE action_name='ai.wander';
