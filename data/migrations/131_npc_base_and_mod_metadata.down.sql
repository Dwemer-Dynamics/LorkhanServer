DO $migration$
DECLARE definition text;
BEGIN
 SELECT pg_get_functiondef('lorkhan_internal.sync_profile_projection(text)'::regprocedure) INTO definition;
 IF position('source_row.actor_identity->>''record_id'',source_row.content->>''tags''' IN definition)=0 THEN RAISE EXCEPTION 'unexpected_npc_base_projection'; END IF;
 definition:=replace(definition,'source_row.actor_identity->>''record_id'',source_row.content->>''tags''','source_row.actor_identity->>''content_file'',source_row.content->>''tags''');
 IF position('base=source_row.actor_identity->>''record_id''' IN definition)=0 THEN RAISE EXCEPTION 'unexpected_npc_base_projection'; END IF;
 definition:=replace(definition,'base=source_row.actor_identity->>''record_id''','base=source_row.actor_identity->>''content_file''');
 IF position('source_row.content#>>''{voice,id}'',(source_row.actor_identity || jsonb_build_object(''mods'',CASE WHEN COALESCE(source_row.actor_identity->>''content_file'','''')='''' THEN ''[]''::jsonb ELSE jsonb_build_array(source_row.actor_identity->>''content_file'') END)),source_row.content->>''gender''' IN definition)=0 THEN RAISE EXCEPTION 'unexpected_npc_base_projection'; END IF;
 definition:=replace(definition,'source_row.content#>>''{voice,id}'',(source_row.actor_identity || jsonb_build_object(''mods'',CASE WHEN COALESCE(source_row.actor_identity->>''content_file'','''')='''' THEN ''[]''::jsonb ELSE jsonb_build_array(source_row.actor_identity->>''content_file'') END)),source_row.content->>''gender''','source_row.content#>>''{voice,id}'',source_row.actor_identity,source_row.content->>''gender''');
 IF position('metadata=(source_row.actor_identity || jsonb_build_object(''mods'',CASE WHEN COALESCE(source_row.actor_identity->>''content_file'','''')='''' THEN ''[]''::jsonb ELSE jsonb_build_array(source_row.actor_identity->>''content_file'') END)),' IN definition)=0 THEN RAISE EXCEPTION 'unexpected_npc_base_projection'; END IF;
 definition:=replace(definition,'metadata=(source_row.actor_identity || jsonb_build_object(''mods'',CASE WHEN COALESCE(source_row.actor_identity->>''content_file'','''')='''' THEN ''[]''::jsonb ELSE jsonb_build_array(source_row.actor_identity->>''content_file'') END)),','metadata=source_row.actor_identity,');
 EXECUTE definition;
END
$migration$;
UPDATE public.core_npc_master npc SET base=metadata.actor_identity->>'content_file',metadata=npc.metadata-'mods'
FROM lorkhan_internal.npc_metadata metadata WHERE metadata.npc_id=npc.id;
UPDATE public.core_npc_master_history SET base=metadata->>'content_file',metadata=metadata-'mods' WHERE metadata ? 'record_id';
