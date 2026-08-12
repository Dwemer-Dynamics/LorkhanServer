DO $excluded_tables$
DECLARE
    relation_name text;
    excluded_relations text[] := ARRAY[
        'almsivi_internal.autonomy_schedules',
        'public.bgl_history',
        'public.core_faction_politics_development',
        'public.core_faction_politics_relation',
        'public.core_faction_politics_state',
        'public.core_itt_connector',
        'public.master_packages',
        'public.npc_commitments',
        'public.quest_asset_group_members',
        'public.quest_asset_groups',
        'public.quest_asset_imports',
        'public.quest_asset_packs',
        'public.quest_assets',
        'public.quest_item_types',
        'public.quest_npc_own_templates',
        'public.quest_npc_templates',
        'public.quest_outfits',
        'public.quest_weapons',
        'public.skyrim_quest_action_outbox',
        'public.skyrim_quest_beat_state',
        'public.skyrim_quest_definitions',
        'public.skyrim_quest_events',
        'public.skyrim_quest_instances',
        'public.sneq_quests',
        'public.sneq_quests_saved',
        'public.visual_context'
    ];
BEGIN
    FOREACH relation_name IN ARRAY excluded_relations LOOP
        IF to_regclass(relation_name) IS NOT NULL THEN
            EXECUTE format('DROP TRIGGER IF EXISTS almsivi_reject_excluded_write ON %s',relation_name);
        END IF;
    END LOOP;
END
$excluded_tables$;

DROP FUNCTION IF EXISTS almsivi_internal.reject_excluded_feature_write();
