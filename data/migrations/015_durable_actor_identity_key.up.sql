-- Durable placed-reference key for history, memory and relationship lookups: kind, base record, content file and local
-- RefNum index. The RefNum content_file is the current load-order slot, so it is omitted only when the index is an
-- integer 0..4294967295, matching ProfileId::durableIdentity. Untyped, fractional, negative or out-of-range legacy
-- references remain exact. The digit check compares text without casts, so hostile stored values never raise.
-- relationship_identity_key keeps its full-identity meaning for same-turn checks.
CREATE FUNCTION lorkhan_internal.durable_actor_identity_key(identity jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT jsonb_build_object('kind',identity->'kind','record_id',identity->'record_id',
    'content_file',identity->'content_file','refnum',CASE WHEN jsonb_typeof(identity#>'{refnum,index}')='number'
    AND (identity#>>'{refnum,index}') COLLATE "C" ~ '^(0|[1-9][0-9]{0,9})$'
    AND (length(identity#>>'{refnum,index}')<10 OR (identity#>>'{refnum,index}') COLLATE "C"<='4294967295')
    THEN jsonb_build_object('index',identity#>'{refnum,index}') ELSE identity->'refnum' END) $$;

CREATE INDEX relationship_durable_identity_scope ON lorkhan_internal.relationship_records USING btree
    (installation_id, profile_id, playthrough_id, md5((lorkhan_internal.durable_actor_identity_key(actor_identity))::text));
-- Lookups no longer use the slot-bearing key; its scope prefix is covered by the new index.
DROP INDEX lorkhan_internal.relationship_identity_scope;
