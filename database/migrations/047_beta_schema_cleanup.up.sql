-- Retire excluded Herika/Skyrim compatibility surfaces before the first beta.
-- The local installation has no player data; the verified pre-cleanup dump is
-- the rollback path for this intentionally destructive schema reduction.
ALTER TABLE public.core_profiles DROP COLUMN IF EXISTS itt_connector_id;

DROP TABLE IF EXISTS public.quest_asset_group_members;
DROP TABLE IF EXISTS public.quest_asset_groups;
DROP TABLE IF EXISTS public.quest_assets;
DROP TABLE IF EXISTS public.quest_asset_packs;
DROP TABLE IF EXISTS public.quest_asset_imports;
DROP TABLE IF EXISTS public.quest_item_types;
DROP TABLE IF EXISTS public.quest_npc_own_templates;
DROP TABLE IF EXISTS public.quest_npc_templates;
DROP TABLE IF EXISTS public.quest_outfits;
DROP TABLE IF EXISTS public.quest_weapons;

DROP TABLE IF EXISTS public.skyrim_quest_action_outbox;
DROP TABLE IF EXISTS public.skyrim_quest_beat_state;
DROP TABLE IF EXISTS public.skyrim_quest_events;
DROP TABLE IF EXISTS public.skyrim_quest_instances;
DROP TABLE IF EXISTS public.skyrim_quest_definitions;
DROP TABLE IF EXISTS public.sneq_quests_saved;
DROP TABLE IF EXISTS public.sneq_quests;

DROP TABLE IF EXISTS public.visual_context;
DROP TABLE IF EXISTS public.core_itt_connector;
DROP TABLE IF EXISTS public.bgl_history;
DROP TABLE IF EXISTS public.core_faction_politics_development;
DROP TABLE IF EXISTS public.core_faction_politics_relation;
DROP TABLE IF EXISTS public.core_faction_politics_state;
DROP TABLE IF EXISTS public.master_packages;
DROP TABLE IF EXISTS public.npc_commitments;
DROP TABLE IF EXISTS almsivi_internal.autonomy_schedules;

DROP FUNCTION IF EXISTS almsivi_internal.chim_touch_updated_at();
DROP FUNCTION IF EXISTS almsivi_internal.reject_excluded_feature_write();
