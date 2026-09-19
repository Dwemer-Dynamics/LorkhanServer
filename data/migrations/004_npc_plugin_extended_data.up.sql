-- Typed profiles own portable state; public NPC tables remain derived projections.
ALTER TABLE lorkhan_internal.profiles ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object');
ALTER TABLE lorkhan_internal.profile_revisions ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object');
ALTER TABLE public.core_npc_master ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object');
ALTER TABLE public.core_npc_master_history ADD COLUMN IF NOT EXISTS plugin_extended_data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(plugin_extended_data) = 'object');

-- Capture plugin state only when an ordinary profile revision is created.
CREATE OR REPLACE FUNCTION lorkhan_internal.capture_profile_plugin_data()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    SELECT plugin_extended_data INTO NEW.plugin_extended_data
    FROM lorkhan_internal.profiles WHERE profile_id = NEW.profile_id FOR UPDATE;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE TRIGGER profile_plugin_data_capture
BEFORE INSERT ON lorkhan_internal.profile_revisions
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.capture_profile_plugin_data();

-- Rebuild public state when an NPC projection is first created or rebuilt on import.
CREATE OR REPLACE FUNCTION lorkhan_internal.project_npc_plugin_data()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    UPDATE public.core_npc_master npc SET plugin_extended_data = profile.plugin_extended_data
    FROM lorkhan_internal.profiles profile
    WHERE npc.id = NEW.npc_id AND profile.profile_id = NEW.source_profile_id;
    RETURN NEW;
END;
$$;
CREATE OR REPLACE TRIGGER npc_plugin_data_projection
AFTER INSERT ON lorkhan_internal.npc_metadata
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.project_npc_plugin_data();

CREATE OR REPLACE FUNCTION lorkhan_internal.capture_npc_plugin_history()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    SELECT revision.plugin_extended_data INTO NEW.plugin_extended_data
    FROM lorkhan_internal.npc_metadata metadata
    JOIN lorkhan_internal.profile_revisions revision ON revision.profile_id = metadata.source_profile_id
    WHERE metadata.npc_id = NEW.npc_id
      AND revision.revision::text = NEW.extended_data->>'lorkhan_revision';
    NEW.plugin_extended_data := COALESCE(NEW.plugin_extended_data, '{}'::jsonb);
    RETURN NEW;
END;
$$;
CREATE OR REPLACE TRIGGER npc_plugin_history_capture
BEFORE INSERT ON public.core_npc_master_history
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.capture_npc_plugin_history();

-- A plugin-only write updates its projection without generating a profile revision/history.
CREATE OR REPLACE FUNCTION lorkhan_internal.trigger_profile_projection()
RETURNS trigger LANGUAGE plpgsql
SET search_path = public, lorkhan_internal, pg_temp AS $$
BEGIN
    IF TG_TABLE_NAME = 'profiles' AND TG_OP = 'UPDATE' THEN
        UPDATE public.core_npc_master npc SET plugin_extended_data = NEW.plugin_extended_data
        FROM lorkhan_internal.npc_metadata metadata
        WHERE metadata.npc_id = npc.id AND metadata.source_profile_id = NEW.profile_id
          AND npc.plugin_extended_data IS DISTINCT FROM NEW.plugin_extended_data;
        IF (to_jsonb(OLD) - 'plugin_extended_data') = (to_jsonb(NEW) - 'plugin_extended_data') THEN
            RETURN NEW;
        END IF;
    END IF;
    PERFORM lorkhan_internal.sync_profile_projection(NEW.profile_id);
    RETURN NEW;
END;
$$;
