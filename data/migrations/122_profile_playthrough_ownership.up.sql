ALTER TABLE lorkhan_internal.playthroughs ADD CONSTRAINT playthroughs_id_installation_unique UNIQUE(playthrough_id,installation_id);
ALTER TABLE lorkhan_internal.profiles ADD COLUMN playthrough_id uuid;
-- Public NPC projections are identified by npc_metadata/source_profile_id, not display names.
ALTER TABLE public.core_npc_master DROP CONSTRAINT npc_master_npc_name_key;
ALTER TABLE lorkhan_internal.profiles ADD CONSTRAINT profiles_playthrough_owner_fk
    FOREIGN KEY(playthrough_id,installation_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id,installation_id)
    DEFERRABLE INITIALLY DEFERRED;
DROP INDEX lorkhan_internal.profiles_installation_id_name_active_key;
CREATE UNIQUE INDEX profiles_installation_id_name_active_key ON lorkhan_internal.profiles
    (installation_id,playthrough_id,name) NULLS NOT DISTINCT WHERE deleted_at IS NULL;

-- Scoped personas are not reusable shared biography templates. Preserve explicit template writes
-- and legacy rows whose original provenance cannot safely be inferred.
DO $migration$
DECLARE definition text;
DECLARE function_name text;
BEGIN
    FOREACH function_name IN ARRAY ARRAY['sync_profile_projection','remove_npc_projection'] LOOP
        definition:=pg_get_functiondef(('lorkhan_internal.'||function_name||'(uuid)')::regprocedure);
        IF position('DELETE FROM bio_templates_custom WHERE npc_name=projected_name;' IN definition)=0 THEN
            RAISE EXCEPTION 'profile_projection_shape_changed';
        END IF;
        definition:=replace(definition,'DELETE FROM bio_templates_custom WHERE npc_name=projected_name;',
            'DELETE FROM bio_templates_custom WHERE npc_name=projected_name AND EXISTS (SELECT 1 FROM lorkhan_internal.profiles owner WHERE owner.profile_id=source_profile AND owner.playthrough_id IS NULL);');
        IF function_name='sync_profile_projection' THEN
            IF position('    INSERT INTO bio_templates_custom (' IN definition)=0 THEN RAISE EXCEPTION 'profile_projection_shape_changed'; END IF;
            definition:=replace(definition,'    INSERT INTO bio_templates_custom (',
                E'    IF source_row.playthrough_id IS NOT NULL THEN\n        PERFORM refresh_npc_relationships(source_profile);\n        RETURN;\n    END IF;\n\n    INSERT INTO bio_templates_custom (');
        END IF;
        EXECUTE definition;
    END LOOP;
END
$migration$;
