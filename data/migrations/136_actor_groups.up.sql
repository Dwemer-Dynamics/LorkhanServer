ALTER TABLE lorkhan_internal.npc_reference_groups ADD COLUMN match_name text NOT NULL DEFAULT '';
CREATE UNIQUE INDEX npc_reference_groups_match_name ON lorkhan_internal.npc_reference_groups(installation_id,lower(btrim(match_name))) WHERE btrim(match_name)<>'';

-- Creature actors (including Vivec) use the same editable NPC projection.
DO $migration$
DECLARE definition text;
BEGIN
 SELECT pg_get_functiondef('lorkhan_internal.sync_profile_projection(text)'::regprocedure) INTO definition;
 IF position('NOT IN (''npc'',''actor'')' IN definition)=0 THEN RAISE EXCEPTION 'unexpected_actor_projection'; END IF;
 EXECUTE replace(definition,'NOT IN (''npc'',''actor'')','NOT IN (''npc'',''actor'',''creature'')');
END
$migration$;
SELECT lorkhan_internal.sync_profile_projection(profile_id)
FROM lorkhan_internal.profiles WHERE deleted_at IS NULL AND actor_identity->>'kind'='creature';
