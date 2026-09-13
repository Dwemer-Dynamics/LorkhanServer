-- Keep immutable transport and turn records while retiring their abandoned timeline.
CREATE TABLE lorkhan_internal.timeline_invalidated_turns (
    turn_id uuid PRIMARY KEY REFERENCES lorkhan_internal.turns(turn_id) ON DELETE CASCADE,
    loaded_save_id uuid NOT NULL REFERENCES lorkhan_internal.source_events(source_event_id),
    cutoff_minute bigint NOT NULL,
    invalidated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE lorkhan_internal.timeline_invalidated_sources (
    source_event_id uuid PRIMARY KEY REFERENCES lorkhan_internal.source_events(source_event_id) ON DELETE CASCADE,
    loaded_save_id uuid NOT NULL REFERENCES lorkhan_internal.source_events(source_event_id),
    cutoff_minute bigint NOT NULL,
    invalidated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

-- Serialize derived publication with the accepted-session installation fence.
CREATE FUNCTION lorkhan_internal.guard_timeline_memory() RETURNS trigger
LANGUAGE plpgsql SET search_path=lorkhan_internal,public,pg_temp AS $$
BEGIN
    IF NEW.deleted_at IS NOT NULL THEN RETURN NEW; END IF;
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=NEW.source_event_id
        OR COALESCE(NEW.provenance->'source_event_ids','[]'::jsonb) @> jsonb_build_array(i.source_event_id::text)) THEN
        NEW.deleted_at=clock_timestamp();
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guard_timeline_memory BEFORE INSERT OR UPDATE ON lorkhan_internal.memory_records
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_memory();

CREATE FUNCTION lorkhan_internal.guard_timeline_narrative() RETURNS trigger
LANGUAGE plpgsql SET search_path=lorkhan_internal,public,pg_temp AS $$
BEGIN
    IF NEW.deleted_at IS NOT NULL THEN RETURN NEW; END IF;
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i
        WHERE COALESCE(NEW.provenance->'source_turn_ids','[]'::jsonb) @> jsonb_build_array(i.turn_id::text)) THEN
        NEW.deleted_at=clock_timestamp();
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guard_timeline_narrative BEFORE INSERT OR UPDATE ON lorkhan_internal.narrative_records
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_narrative();

CREATE FUNCTION lorkhan_internal.guard_timeline_event() RETURNS trigger
LANGUAGE plpgsql SET search_path=lorkhan_internal,public,pg_temp AS $$
BEGIN
    IF NEW.suppressed_at IS NOT NULL THEN RETURN NEW; END IF;
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=NEW.turn_id)
       OR EXISTS(SELECT 1 FROM timeline_invalidated_sources i WHERE i.source_event_id=NEW.source_event_id) THEN
        NEW.suppressed_at=clock_timestamp(); NEW.suppression_reason='loaded_save_rollback';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER guard_timeline_event BEFORE INSERT OR UPDATE ON lorkhan_internal.eventlog_metadata
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_event();

CREATE VIEW lorkhan_internal.active_turns AS
SELECT t.* FROM lorkhan_internal.turns t
WHERE NOT EXISTS(SELECT 1 FROM lorkhan_internal.timeline_invalidated_turns i WHERE i.turn_id=t.turn_id);

-- These are reconstructible presentation rows; the typed source/utterance records stay intact.
CREATE FUNCTION lorkhan_internal.guard_timeline_speech_projection() RETURNS trigger
LANGUAGE plpgsql SET search_path=lorkhan_internal,public,pg_temp AS $$
BEGIN
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=NEW.turn_id) THEN
        DELETE FROM public.speech WHERE rowid=NEW.rowid;
        DELETE FROM speech_metadata WHERE rowid=NEW.rowid;
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER guard_timeline_speech_projection AFTER INSERT OR UPDATE ON lorkhan_internal.speech_metadata
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_speech_projection();
CREATE FUNCTION lorkhan_internal.guard_timeline_world_projection() RETURNS trigger
LANGUAGE plpgsql SET search_path=lorkhan_internal,public,pg_temp AS $$
BEGIN
    PERFORM 1 FROM installations WHERE installation_id=NEW.installation_id FOR SHARE;
    IF EXISTS(SELECT 1 FROM timeline_invalidated_turns i WHERE i.turn_id=NEW.source_turn_id) THEN
        EXECUTE format('DELETE FROM public.%I WHERE rowid=$1', CASE TG_TABLE_NAME
            WHEN 'book_metadata' THEN 'books' WHEN 'currentmission_metadata' THEN 'currentmission'
            WHEN 'questlog_metadata' THEN 'questlog' WHEN 'quest_metadata' THEN 'quests' END) USING NEW.rowid;
        EXECUTE format('DELETE FROM lorkhan_internal.%I WHERE rowid=$1',TG_TABLE_NAME) USING NEW.rowid;
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER guard_timeline_book_projection AFTER INSERT OR UPDATE ON lorkhan_internal.book_metadata
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();

CREATE TRIGGER guard_timeline_currentmission AFTER INSERT OR UPDATE ON lorkhan_internal.currentmission_metadata
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();
CREATE TRIGGER guard_timeline_questlog AFTER INSERT OR UPDATE ON lorkhan_internal.questlog_metadata
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();
CREATE TRIGGER guard_timeline_quest AFTER INSERT OR UPDATE ON lorkhan_internal.quest_metadata
FOR EACH ROW EXECUTE FUNCTION lorkhan_internal.guard_timeline_world_projection();
