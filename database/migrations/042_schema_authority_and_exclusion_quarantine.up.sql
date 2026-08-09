-- Keep migration authority internal and make excluded compatibility tables read-only.
CREATE SCHEMA IF NOT EXISTS almsivi_internal;

DO $migration_ledger$
BEGIN
    IF to_regclass('public.schema_migrations') IS NOT NULL THEN
        IF EXISTS (
            SELECT 1
            FROM public.schema_migrations legacy
            JOIN almsivi_internal.schema_migrations current USING (version)
            WHERE legacy.name IS DISTINCT FROM current.name
               OR legacy.checksum IS DISTINCT FROM current.checksum
        ) THEN
            RAISE EXCEPTION 'Conflicting public and internal migration ledger rows';
        END IF;

        INSERT INTO almsivi_internal.schema_migrations(version,name,checksum,applied_at)
        SELECT version,name,checksum,applied_at
        FROM public.schema_migrations
        ON CONFLICT (version) DO NOTHING;

        DROP TABLE public.schema_migrations;
    END IF;
END
$migration_ledger$;

CREATE OR REPLACE FUNCTION almsivi_internal.reject_excluded_feature_write()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    RAISE EXCEPTION 'feature_excluded' USING ERRCODE = '0A000';
END
$function$;

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
        IF to_regclass(relation_name) IS NULL THEN
            RAISE EXCEPTION 'Missing excluded compatibility table %',relation_name;
        END IF;
        EXECUTE format('DROP TRIGGER IF EXISTS almsivi_reject_excluded_write ON %s',relation_name);
        EXECUTE format(
            'CREATE TRIGGER almsivi_reject_excluded_write BEFORE INSERT OR UPDATE OR DELETE ON %s '
            'FOR EACH STATEMENT EXECUTE FUNCTION almsivi_internal.reject_excluded_feature_write()',
            relation_name
        );
    END LOOP;
END
$excluded_tables$;
