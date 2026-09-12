DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.npc_evolution_reports) THEN
        RAISE EXCEPTION 'Review and remove NPC evolution reports before reverting migration 103';
    END IF;
END $$;
DROP TABLE lorkhan_internal.npc_evolution_reports;
