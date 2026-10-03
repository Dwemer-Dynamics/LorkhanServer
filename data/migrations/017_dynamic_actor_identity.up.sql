-- actor.identity.dynamic.v1: runtime-generated actors share the zero RefNum and "lorkhan:dynamic" sentinel, so the
-- 015 durable key would merge every copy of a base record. The v2 key is the 015 key plus dynamic.uuid; only the
-- dynamic.runtime_ref snapshot is dropped, matching ProfileId::durableIdentity. Identities without a dynamic member
-- produce exactly the 015 key. The 015 function and its index are left unchanged; lookups move to a new index.
-- Additive only: no stored identity, profile, binding or history row is rewritten or backfilled. Bodies repeat the
-- 001/015 expressions instead of calling them so index builds (and pg_restore) inline only built-ins.
CREATE FUNCTION lorkhan_internal.durable_actor_identity_key_v2(identity jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT jsonb_build_object('kind',identity->'kind','record_id',identity->'record_id',
    'content_file',identity->'content_file','refnum',CASE WHEN jsonb_typeof(identity#>'{refnum,index}')='number'
    AND (identity#>>'{refnum,index}') COLLATE "C" ~ '^(0|[1-9][0-9]{0,9})$'
    AND (length(identity#>>'{refnum,index}')<10 OR (identity#>>'{refnum,index}') COLLATE "C"<='4294967295')
    THEN jsonb_build_object('index',identity#>'{refnum,index}') ELSE identity->'refnum' END)||CASE WHEN identity ? 'dynamic'
    THEN jsonb_build_object('dynamic',CASE WHEN jsonb_typeof(identity->'dynamic')='object'
        THEN jsonb_build_object('uuid',identity#>'{dynamic,uuid}') ELSE identity->'dynamic' END)
    ELSE '{}'::jsonb END $$;

-- Same-turn relationship witnesses need the exact current snapshot: the 001 relationship_identity_key plus the whole
-- dynamic member (uuid and runtime_ref), so two copies sharing a base record and sentinel never witness each other.
-- Placed identities produce exactly the 001 key; the 001 function is unchanged.
CREATE FUNCTION lorkhan_internal.relationship_identity_key_v2(identity jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT jsonb_build_object('kind',identity->'kind','record_id',identity->'record_id',
    'content_file',identity->'content_file','refnum',identity->'refnum')||CASE WHEN identity ? 'dynamic'
    THEN jsonb_build_object('dynamic',identity->'dynamic') ELSE '{}'::jsonb END $$;

CREATE INDEX relationship_durable_identity_v2_scope ON lorkhan_internal.relationship_records USING btree
    (installation_id, profile_id, playthrough_id, md5((lorkhan_internal.durable_actor_identity_key_v2(actor_identity))::text));
