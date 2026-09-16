DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM lorkhan_internal.profiles WHERE playthrough_id IS NOT NULL) THEN
        RAISE EXCEPTION 'profile_ownership_rollback_requires_export';
    END IF;
END $$;
DO $migration$
DECLARE definition text;
DECLARE function_name text;
BEGIN
    FOREACH function_name IN ARRAY ARRAY['sync_profile_projection','remove_npc_projection'] LOOP
        definition:=pg_get_functiondef(('lorkhan_internal.'||function_name||'(uuid)')::regprocedure);
        definition:=replace(definition,
            'DELETE FROM bio_templates_custom WHERE npc_name=projected_name AND EXISTS (SELECT 1 FROM lorkhan_internal.profiles owner WHERE owner.profile_id=source_profile AND owner.playthrough_id IS NULL);',
            'DELETE FROM bio_templates_custom WHERE npc_name=projected_name;');
        definition:=replace(definition,
            E'    IF source_row.playthrough_id IS NOT NULL THEN\n        PERFORM refresh_npc_relationships(source_profile);\n        RETURN;\n    END IF;\n\n    INSERT INTO bio_templates_custom (',
            '    INSERT INTO bio_templates_custom (');
        EXECUTE definition;
    END LOOP;
END
$migration$;
ALTER TABLE public.core_npc_master ADD CONSTRAINT npc_master_npc_name_key UNIQUE(npc_name);
DROP INDEX lorkhan_internal.profiles_installation_id_name_active_key;
CREATE UNIQUE INDEX profiles_installation_id_name_active_key ON lorkhan_internal.profiles(installation_id,name) WHERE deleted_at IS NULL;
ALTER TABLE lorkhan_internal.profiles DROP CONSTRAINT profiles_playthrough_owner_fk;
ALTER TABLE lorkhan_internal.profiles DROP COLUMN playthrough_id;
ALTER TABLE lorkhan_internal.playthroughs DROP CONSTRAINT playthroughs_id_installation_unique;
