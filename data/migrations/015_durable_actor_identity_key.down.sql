CREATE INDEX IF NOT EXISTS relationship_identity_scope ON lorkhan_internal.relationship_records USING btree
    (installation_id, profile_id, playthrough_id, md5((lorkhan_internal.relationship_identity_key(actor_identity))::text));
DROP INDEX IF EXISTS lorkhan_internal.relationship_durable_identity_scope;
DROP FUNCTION IF EXISTS lorkhan_internal.durable_actor_identity_key(jsonb);
