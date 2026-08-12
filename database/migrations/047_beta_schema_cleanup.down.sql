-- The retired Herika compatibility objects are intentionally irreversible
-- before beta. Restore the verified pre-cleanup PostgreSQL dump if required.
-- Recreate the version-004 typed table so a complete historical migration
-- teardown/reapply can still cross the version-037 schema cutover safely.
CREATE TABLE IF NOT EXISTS almsivi_internal.autonomy_schedules (
    schedule_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES almsivi_internal.installations(installation_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES almsivi_internal.profiles(profile_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES almsivi_internal.playthroughs(playthrough_id) ON DELETE CASCADE,
    kind text NOT NULL CHECK (kind IN ('rechat', 'boredom', 'greeting')),
    enabled boolean NOT NULL DEFAULT false,
    interval_seconds integer NOT NULL CHECK (interval_seconds BETWEEN 30 AND 86400),
    cooldown_seconds integer NOT NULL CHECK (cooldown_seconds BETWEEN 30 AND 86400),
    last_triggered_at timestamptz,
    current_session_id uuid REFERENCES almsivi_internal.sessions(session_id),
    confirmed_at timestamptz,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (installation_id, profile_id, playthrough_id, kind)
);

-- Empty compatibility shells let a complete migration teardown cross the
-- historical public-schema cutover. Reapplying migrations 026, 033, and 034
-- recreates their exact former definitions before migration 047 retires them.
CREATE TABLE IF NOT EXISTS public.bgl_history ();
CREATE TABLE IF NOT EXISTS public.core_faction_politics_development ();
CREATE TABLE IF NOT EXISTS public.core_faction_politics_relation ();
CREATE TABLE IF NOT EXISTS public.core_faction_politics_state ();
CREATE TABLE IF NOT EXISTS public.core_itt_connector ();
CREATE TABLE IF NOT EXISTS public.master_packages ();
CREATE TABLE IF NOT EXISTS public.npc_commitments ();
CREATE TABLE IF NOT EXISTS public.quest_asset_group_members ();
CREATE TABLE IF NOT EXISTS public.quest_asset_groups ();
CREATE TABLE IF NOT EXISTS public.quest_asset_imports ();
CREATE TABLE IF NOT EXISTS public.quest_asset_packs ();
CREATE TABLE IF NOT EXISTS public.quest_assets ();
CREATE TABLE IF NOT EXISTS public.quest_item_types ();
CREATE TABLE IF NOT EXISTS public.quest_npc_own_templates ();
CREATE TABLE IF NOT EXISTS public.quest_npc_templates ();
CREATE TABLE IF NOT EXISTS public.quest_outfits ();
CREATE TABLE IF NOT EXISTS public.quest_weapons ();
CREATE TABLE IF NOT EXISTS public.skyrim_quest_action_outbox ();
CREATE TABLE IF NOT EXISTS public.skyrim_quest_beat_state ();
CREATE TABLE IF NOT EXISTS public.skyrim_quest_definitions ();
CREATE TABLE IF NOT EXISTS public.skyrim_quest_events ();
CREATE TABLE IF NOT EXISTS public.skyrim_quest_instances ();
CREATE TABLE IF NOT EXISTS public.sneq_quests ();
CREATE TABLE IF NOT EXISTS public.sneq_quests_saved ();
CREATE TABLE IF NOT EXISTS public.visual_context ();
