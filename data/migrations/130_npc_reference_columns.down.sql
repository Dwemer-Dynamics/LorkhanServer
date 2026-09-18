DO $migration$
DECLARE definition text;
BEGIN
 SELECT pg_get_functiondef('lorkhan_internal.sync_profile_projection(text)'::regprocedure) INTO definition;
 EXECUTE replace(definition,'(source_row.actor_identity#>>''{refnum,index}'')','left(source_row.actor_identity->>''record_id'',16)');
END
$migration$;
UPDATE public.core_npc_master npc SET refid=left(metadata.actor_identity->>'record_id',16)
FROM lorkhan_internal.npc_metadata metadata WHERE metadata.npc_id=npc.id;
UPDATE public.core_npc_master_history SET refid=left(metadata->>'record_id',16) WHERE metadata ? 'refnum';
