DROP VIEW herika_compat.memory_v;
DROP VIEW public.eventlog_view;
ALTER TABLE public.eventlog_metadata DROP CONSTRAINT eventlog_metadata_rowid_fkey;
ALTER SEQUENCE public.eventlog_rowid_seq OWNED BY NONE;
ALTER SEQUENCE public.eventlog_rowid_seq RENAME TO eventlog_herika_stage_rowid_seq;
ALTER TABLE public.eventlog RENAME TO eventlog_herika_stage;

CREATE TABLE public.eventlog (
    rowid bigserial PRIMARY KEY,
    type character varying(128) NOT NULL,
    data text NOT NULL DEFAULT '',
    sess character varying(1024),
    gamets bigint NOT NULL DEFAULT 0,
    localts bigint NOT NULL,
    ts bigint,
    people text,
    location text,
    party text,
    utterance_id text,
    delivery_state text CHECK (delivery_state IS NULL OR delivery_state IN ('emitted','pending','spoken','played','failed','expired','interrupted'))
);
CREATE INDEX eventlog_chim_order ON public.eventlog (gamets DESC,ts DESC,localts DESC,rowid DESC);
CREATE INDEX eventlog_chim_type_order ON public.eventlog (type,rowid DESC);
CREATE INDEX eventlog_chim_utterance ON public.eventlog (utterance_id) WHERE utterance_id IS NOT NULL;

INSERT INTO public.eventlog (
    rowid,type,data,sess,gamets,localts,ts,people,location,party,utterance_id,delivery_state
)
SELECT rowid,COALESCE(type,''),COALESCE(data,''),sess,gamets,localts,ts,people,location,party,utterance_id,delivery_state
FROM public.eventlog_herika_stage ORDER BY rowid;
SELECT setval('public.eventlog_rowid_seq',COALESCE((SELECT max(rowid) FROM public.eventlog),1),
              EXISTS(SELECT 1 FROM public.eventlog));

ALTER TABLE public.eventlog_metadata ADD CONSTRAINT eventlog_metadata_rowid_fkey
    FOREIGN KEY (rowid) REFERENCES public.eventlog(rowid) ON DELETE CASCADE;
CREATE VIEW public.eventlog_view AS
SELECT e.*,
       to_timestamp(e.localts) AT TIME ZONE 'UTC' AS local_datetime,
       CASE WHEN e.ts IS NULL THEN NULL ELSE to_timestamp(e.ts/1000.0) AT TIME ZONE 'UTC' END AS event_datetime
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

DROP TABLE public.eventlog_herika_stage;
DROP SEQUENCE public.eventlog_herika_stage_rowid_seq;
