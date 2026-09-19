CREATE OR REPLACE FUNCTION lorkhan_internal.trigger_profile_projection()
RETURNS trigger LANGUAGE plpgsql
SET search_path = public, lorkhan_internal, pg_temp AS $$
BEGIN
    PERFORM lorkhan_internal.sync_profile_projection(NEW.profile_id);
    RETURN NEW;
END;
$$;
DROP TRIGGER IF EXISTS npc_plugin_history_capture ON public.core_npc_master_history;
DROP TRIGGER IF EXISTS npc_plugin_data_projection ON lorkhan_internal.npc_metadata;
DROP TRIGGER IF EXISTS profile_plugin_data_capture ON lorkhan_internal.profile_revisions;
DROP FUNCTION IF EXISTS lorkhan_internal.capture_npc_plugin_history();
DROP FUNCTION IF EXISTS lorkhan_internal.project_npc_plugin_data();
DROP FUNCTION IF EXISTS lorkhan_internal.capture_profile_plugin_data();
ALTER TABLE public.core_npc_master_history DROP COLUMN IF EXISTS plugin_extended_data;
ALTER TABLE public.core_npc_master DROP COLUMN IF EXISTS plugin_extended_data;
ALTER TABLE lorkhan_internal.profile_revisions DROP COLUMN IF EXISTS plugin_extended_data;
ALTER TABLE lorkhan_internal.profiles DROP COLUMN IF EXISTS plugin_extended_data;
