-- Rebuild the already-public eventlog into Herika's exact table contract. Typed
-- installation/session/turn ownership remains in eventlog_metadata.

DROP VIEW herika_compat.memory_v;
DROP VIEW public.eventlog_view;
ALTER TABLE public.eventlog_metadata DROP CONSTRAINT eventlog_metadata_rowid_fkey;
ALTER SEQUENCE public.eventlog_rowid_seq OWNED BY NONE;
ALTER SEQUENCE public.eventlog_rowid_seq RENAME TO eventlog_almsivi_stage_rowid_seq;
ALTER TABLE public.eventlog RENAME TO eventlog_almsivi_stage;

CREATE TABLE public.eventlog (
    type character varying(128),
    data text,
    sess character varying(1024),
    gamets bigint NOT NULL,
    localts bigint NOT NULL,
    ts bigint,
    rowid bigint NOT NULL,
    people text,
    location text,
    party text,
    utterance_id text,
    delivery_state text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE public.eventlog_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE public.eventlog_rowid_seq OWNED BY public.eventlog.rowid;
ALTER TABLE ONLY public.eventlog ALTER COLUMN rowid SET DEFAULT nextval('public.eventlog_rowid_seq'::regclass);
ALTER TABLE ONLY public.eventlog ADD CONSTRAINT eventlog_primary PRIMARY KEY (rowid);
CREATE INDEX event_log_type ON public.eventlog USING btree (type);
CREATE INDEX idx_eventlog_delivery_state ON public.eventlog USING btree (delivery_state);
CREATE INDEX idx_eventlog_gamets_pos ON public.eventlog USING btree (gamets) WHERE gamets>0;
CREATE INDEX idx_eventlog_gamets_ts_pos ON public.eventlog USING btree (gamets DESC,ts DESC);
CREATE INDEX idx_eventlog_people_trgm ON public.eventlog USING gin (people public.gin_trgm_ops);
CREATE INDEX idx_eventlog_people_trgm2 ON public.eventlog USING gin (data public.gin_trgm_ops);
CREATE INDEX idx_eventlog_utterance_id ON public.eventlog USING btree (utterance_id);

INSERT INTO public.eventlog (
    type,data,sess,gamets,localts,ts,rowid,people,location,party,utterance_id,delivery_state
)
SELECT type,data,sess,gamets,localts,ts,rowid,people,location,party,utterance_id,delivery_state
FROM public.eventlog_almsivi_stage ORDER BY rowid;
SELECT setval('public.eventlog_rowid_seq',COALESCE((SELECT max(rowid) FROM public.eventlog),1),
              EXISTS(SELECT 1 FROM public.eventlog));

ALTER TABLE public.eventlog_metadata ADD CONSTRAINT eventlog_metadata_rowid_fkey
    FOREIGN KEY (rowid) REFERENCES public.eventlog(rowid) ON DELETE CASCADE;

CREATE VIEW public.eventlog_view AS
SELECT e.*,
       to_timestamp(e.localts) AT TIME ZONE 'UTC' AS mw_local_datetime,
       CASE WHEN e.ts IS NULL THEN NULL ELSE to_timestamp(e.ts/1000.0) AT TIME ZONE 'UTC' END AS mw_event_datetime
FROM public.eventlog e;

CREATE VIEW herika_compat.memory_v AS
SELECT subquery.message,subquery.uid,subquery.gamets,subquery.speaker,subquery.listener,subquery.ts
FROM (
    SELECT memory.message,memory.uid,memory.gamets,'-'::text AS speaker,'-'::text AS listener,memory.ts
    FROM herika_compat.memory
    WHERE memory.message NOT LIKE 'Dear Diary%'::text AND memory.message<>''::text
      AND memory.event::text<>'backgroundlife_diary'::text
    UNION
    SELECT '(Context Location:'::text||speech.location||') '::text||speech.speaker||': '::text||speech.speech,
           speech.rowid::integer AS rowid,speech.gamets,speech.speaker,speech.listener,speech.ts
    FROM herika_compat.speech WHERE speech.speech<>''::text
    UNION
    SELECT eventlog.data,eventlog.rowid::integer AS rowid,eventlog.gamets,
           '-'::text AS text,'-'::text AS listener,eventlog.ts
    FROM public.eventlog
    WHERE eventlog.type::text=ANY(ARRAY['death'::character varying::text,'location'::character varying::text])
) subquery
ORDER BY subquery.gamets,subquery.ts;

DROP TABLE public.eventlog_almsivi_stage;
DROP SEQUENCE public.eventlog_almsivi_stage_rowid_seq;
