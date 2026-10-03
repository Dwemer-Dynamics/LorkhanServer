-- Earlier code reads only the 015 key and index, which this migration never changed.
DROP INDEX IF EXISTS lorkhan_internal.relationship_durable_identity_v2_scope;
DROP FUNCTION IF EXISTS lorkhan_internal.relationship_identity_key_v2(jsonb);
DROP FUNCTION IF EXISTS lorkhan_internal.durable_actor_identity_key_v2(jsonb);
