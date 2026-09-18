-- Store physical reference numbers in refid; base remains the originating content file.
DO $migration$
DECLARE definition text;
BEGIN
 SELECT pg_get_functiondef('lorkhan_internal.sync_profile_projection(text)'::regprocedure) INTO definition;
 IF position('left(source_row.actor_identity->>''record_id'',16)' IN definition)=0 THEN
   RAISE EXCEPTION 'unexpected_npc_reference_projection';
 END IF;
 definition:=replace(definition,'left(source_row.actor_identity->>''record_id'',16)',
   '(source_row.actor_identity#>>''{refnum,index}'')');
 EXECUTE definition;
END
$migration$;
UPDATE public.core_npc_master npc SET refid=metadata.actor_identity#>>'{refnum,index}',
 base=metadata.actor_identity->>'content_file'
FROM lorkhan_internal.npc_metadata metadata WHERE metadata.npc_id=npc.id;
UPDATE public.core_npc_master_history SET refid=metadata#>>'{refnum,index}',base=metadata->>'content_file'
WHERE metadata ? 'refnum';
