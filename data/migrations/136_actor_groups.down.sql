DO $migration$
DECLARE definition text;
BEGIN
 SELECT pg_get_functiondef('lorkhan_internal.sync_profile_projection(text)'::regprocedure) INTO definition;
 EXECUTE replace(definition,'NOT IN (''npc'',''actor'',''creature'')','NOT IN (''npc'',''actor'')');
END
$migration$;
SELECT lorkhan_internal.sync_profile_projection(profile_id)
FROM lorkhan_internal.profiles WHERE actor_identity->>'kind'='creature';
DELETE FROM lorkhan_internal.actor_profile_bindings b USING lorkhan_internal.npc_reference_groups g
WHERE b.installation_id=g.installation_id AND g.match_name<>'' AND lower(btrim(b.actor_identity->>'display_name'))=lower(btrim(g.match_name));
DROP INDEX lorkhan_internal.npc_reference_groups_match_name;
ALTER TABLE lorkhan_internal.npc_reference_groups DROP COLUMN match_name;
