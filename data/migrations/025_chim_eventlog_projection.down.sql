DROP VIEW IF EXISTS eventlog_view;
DROP INDEX IF EXISTS eventlog_chim_utterance;
DROP INDEX IF EXISTS eventlog_chim_type_order;
DROP INDEX IF EXISTS eventlog_chim_order;

ALTER TABLE eventlog
    ADD COLUMN installation_id uuid,
    ADD COLUMN playthrough_id uuid,
    ADD COLUMN profile_id uuid,
    ADD COLUMN session_id uuid,
    ADD COLUMN source_event_id uuid,
    ADD COLUMN request_id uuid,
    ADD COLUMN turn_id uuid,
    ADD COLUMN speaker jsonb NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN target jsonb NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN audience jsonb NOT NULL DEFAULT '[]'::jsonb,
    ADD COLUMN payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    ADD COLUMN created_at timestamptz NOT NULL DEFAULT clock_timestamp();

UPDATE eventlog e SET
    installation_id=m.installation_id,playthrough_id=m.playthrough_id,profile_id=m.profile_id,
    session_id=m.session_id,source_event_id=m.source_event_id,request_id=m.request_id,turn_id=m.turn_id,
    speaker=m.speaker,target=m.target,audience=m.audience,payload=m.payload,created_at=m.created_at
FROM eventlog_metadata m WHERE m.rowid=e.rowid;

ALTER TABLE eventlog ALTER COLUMN installation_id SET NOT NULL;
ALTER TABLE eventlog ADD CONSTRAINT eventlog_installation_id_fkey FOREIGN KEY (installation_id)
    REFERENCES installations(installation_id) ON DELETE CASCADE;
ALTER TABLE eventlog ADD CONSTRAINT eventlog_playthrough_id_fkey FOREIGN KEY (playthrough_id)
    REFERENCES playthroughs(playthrough_id) ON DELETE CASCADE;
ALTER TABLE eventlog ADD CONSTRAINT eventlog_profile_id_fkey FOREIGN KEY (profile_id)
    REFERENCES profiles(profile_id) ON DELETE SET NULL;
ALTER TABLE eventlog ADD CONSTRAINT eventlog_session_id_fkey FOREIGN KEY (session_id)
    REFERENCES sessions(session_id) ON DELETE SET NULL;
ALTER TABLE eventlog ADD CONSTRAINT eventlog_source_event_id_fkey FOREIGN KEY (source_event_id)
    REFERENCES source_events(source_event_id) ON DELETE SET NULL;

DROP TABLE eventlog_hidden_types;
DROP TABLE eventlog_metadata;

CREATE UNIQUE INDEX eventlog_source_event_id_unique ON eventlog (source_event_id) WHERE source_event_id IS NOT NULL;
CREATE INDEX eventlog_playthrough_order ON eventlog (installation_id,playthrough_id,gamets,ts,rowid);
CREATE INDEX eventlog_turn_order ON eventlog (turn_id,rowid) WHERE turn_id IS NOT NULL;
CREATE INDEX eventlog_type_order ON eventlog (installation_id,type,rowid DESC);

CREATE VIEW eventlog_view AS
SELECT e.*,
       to_timestamp(e.localts) AT TIME ZONE 'UTC' AS local_datetime,
       CASE WHEN e.ts IS NULL THEN NULL ELSE to_timestamp(e.ts / 1000.0) AT TIME ZONE 'UTC' END AS event_datetime
FROM eventlog e;
