DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM almsivi_internal.relationship_records WHERE relationship_type <> 'neutral') THEN
        RAISE EXCEPTION 'Cannot remove saved non-neutral relationship types.';
    END IF;
END $$;

CREATE OR REPLACE FUNCTION almsivi_internal.refresh_npc_relationships(source_profile uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = public, almsivi_internal, pg_temp
AS $function$
DECLARE relationships_json jsonb;
BEGIN
    SELECT jsonb_object_agg(
        COALESCE(r.actor_identity->>'record_id',r.actor_identity->>'display_name',r.relationship_id::text),
        jsonb_build_object(
            'name',COALESCE(r.actor_identity->>'display_name',r.actor_identity->>'record_id'),
            'affinity',r.affinity,'disposition',r.disposition,'source',r.source_mode
        )
    ) INTO relationships_json
    FROM almsivi_internal.relationship_records r
    WHERE r.profile_id=source_profile AND r.deleted_at IS NULL;

    UPDATE core_npc_master npc SET
        relationships=CASE WHEN relationships_json IS NULL THEN NULL ELSE relationships_json::text END,
        extended_data=(npc.extended_data-'relationships')||
            CASE WHEN relationships_json IS NULL THEN '{}'::jsonb
                 ELSE jsonb_build_object('relationships',relationships_json) END
    FROM npc_metadata metadata
    WHERE metadata.source_profile_id=source_profile AND npc.id=metadata.npc_id;
END
$function$;

DROP INDEX almsivi_internal.relationship_audit_record_order;
ALTER TABLE almsivi_internal.relationship_records DROP COLUMN relationship_type;
