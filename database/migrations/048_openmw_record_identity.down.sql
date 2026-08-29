DROP TRIGGER IF EXISTS turns_openmw_record_identity ON lorkhan_internal.turns;
UPDATE lorkhan_internal.action_catalog SET parameter_schema=
    '{"type":"object","properties":{"record_id":{"type":"string","minLength":1,"maxLength":128}},"required":["record_id"],"additionalProperties":false}'::jsonb
WHERE action_name='item.use';
UPDATE lorkhan_internal.action_catalog SET parameter_schema=
    '{"type":"object","properties":{"record_id":{"type":"string","minLength":1,"maxLength":128},"slot":{"type":"string","enum":["helmet","cuirass","greaves","left_pauldron","right_pauldron","left_gauntlet","right_gauntlet","boots","shirt","pants","skirt","robe","left_ring","right_ring","amulet","belt","carried_right","carried_left","ammunition"]}},"required":["record_id","slot"],"additionalProperties":false}'::jsonb
WHERE action_name='item.equip';
DROP FUNCTION IF EXISTS lorkhan_internal.sync_openmw_record_identity();
DROP FUNCTION IF EXISTS lorkhan_internal.observe_openmw_item(uuid,uuid,uuid,timestamptz,text,jsonb);
DROP TABLE IF EXISTS lorkhan_internal.discovered_items;
DROP TABLE IF EXISTS lorkhan_internal.content_manifest_files;
DROP TABLE IF EXISTS lorkhan_internal.content_manifests;
