-- A normal downgrade must not erase branch ownership. Backed-up replay captures exact markers first.
DO $$ BEGIN
    IF EXISTS(SELECT 1 FROM lorkhan_internal.timeline_invalidated_turns) THEN
        IF to_regclass('pg_temp.replay_timeline_invalidated_turns') IS NULL THEN
            RAISE EXCEPTION 'Cannot remove loaded-save timeline tracking while invalidations exist';
        END IF;
        IF EXISTS(SELECT * FROM lorkhan_internal.timeline_invalidated_turns EXCEPT SELECT * FROM pg_temp.replay_timeline_invalidated_turns) THEN
            RAISE EXCEPTION 'Timeline replay capture does not match active invalidations';
        END IF;
    END IF;
    IF EXISTS(SELECT 1 FROM lorkhan_internal.timeline_invalidated_sources) THEN
        IF to_regclass('pg_temp.replay_timeline_invalidated_sources') IS NULL THEN
            RAISE EXCEPTION 'Cannot remove loaded-save timeline tracking while invalidations exist';
        END IF;
        IF EXISTS(SELECT * FROM lorkhan_internal.timeline_invalidated_sources EXCEPT SELECT * FROM pg_temp.replay_timeline_invalidated_sources) THEN
            RAISE EXCEPTION 'Timeline replay capture does not match active invalidations';
        END IF;
    END IF;
END $$;
DROP TRIGGER guard_timeline_book_projection ON lorkhan_internal.book_metadata;
DROP TRIGGER guard_timeline_currentmission ON lorkhan_internal.currentmission_metadata;
DROP TRIGGER guard_timeline_questlog ON lorkhan_internal.questlog_metadata;
DROP TRIGGER guard_timeline_quest ON lorkhan_internal.quest_metadata;
DROP FUNCTION lorkhan_internal.guard_timeline_world_projection();
DROP TRIGGER guard_timeline_speech_projection ON lorkhan_internal.speech_metadata;
DROP FUNCTION lorkhan_internal.guard_timeline_speech_projection();
DROP VIEW lorkhan_internal.active_turns;
DROP TRIGGER guard_timeline_event ON lorkhan_internal.eventlog_metadata;
DROP FUNCTION lorkhan_internal.guard_timeline_event();
DROP TRIGGER guard_timeline_narrative ON lorkhan_internal.narrative_records;
DROP FUNCTION lorkhan_internal.guard_timeline_narrative();
DROP TRIGGER guard_timeline_memory ON lorkhan_internal.memory_records;
DROP FUNCTION lorkhan_internal.guard_timeline_memory();
DROP TABLE lorkhan_internal.timeline_invalidated_sources;
DROP TABLE lorkhan_internal.timeline_invalidated_turns;
