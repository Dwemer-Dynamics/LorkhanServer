-- Herika world/journal tables with OpenMW producers and companion TES3 identities.
CREATE TABLE herika_compat.books (
    sess character varying(1024),title text,content text,localts bigint NOT NULL,
    gamets bigint NOT NULL,ts bigint,rowid bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.books_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.books_rowid_seq OWNED BY herika_compat.books.rowid;
ALTER TABLE ONLY herika_compat.books ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.books_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.books ADD CONSTRAINT books_pidx PRIMARY KEY (rowid);

CREATE TABLE herika_compat.currentmission (
    sess character varying(1024),description text,localts bigint NOT NULL,
    gamets bigint NOT NULL,ts bigint,rowid bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.currentmission_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.currentmission_rowid_seq OWNED BY herika_compat.currentmission.rowid;
ALTER TABLE ONLY herika_compat.currentmission ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.currentmission_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.currentmission ADD CONSTRAINT currentmission_pidx PRIMARY KEY (rowid);

CREATE TABLE herika_compat.questlog (
    ts text,sess character varying(1024),id_quest character varying(1024),name text,editor_id text,
    giver_actor_id text,reward text,target_id text,is_unique boolean,mod text,stage integer,
    briefing text,briefing2 text,localts bigint,gamets bigint,data text,status text,rowid integer NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.questlog_rowid_seq AS integer START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.questlog_rowid_seq OWNED BY herika_compat.questlog.rowid;
ALTER TABLE ONLY herika_compat.questlog ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.questlog_rowid_seq'::regclass);
ALTER TABLE ONLY herika_compat.questlog ADD CONSTRAINT questlog_pkey PRIMARY KEY (rowid);

CREATE TABLE herika_compat.quests (
    ts text NOT NULL,sess character varying(1024),id_quest character varying(1024) NOT NULL,name text,
    editor_id text,giver_actor_id text,reward text,target_id text,is_unique boolean,mod text,stage integer,
    briefing text,briefing2 text,localts bigint NOT NULL,gamets bigint NOT NULL,data text,status text,rowid bigint NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
CREATE SEQUENCE herika_compat.quests_rowid_seq START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;
ALTER SEQUENCE herika_compat.quests_rowid_seq OWNED BY herika_compat.quests.rowid;
ALTER TABLE ONLY herika_compat.quests ALTER COLUMN rowid SET DEFAULT nextval('herika_compat.quests_rowid_seq'::regclass);

CREATE TABLE herika_compat.locations (
    name text,formid bigint,region text,hold text,tags text,factions text,is_interior integer,
    vanilla_location boolean,coords point,refs text,cleared boolean,updated_at timestamp without time zone,world text
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
COMMENT ON TABLE herika_compat.locations IS 'locations sent from OpenMW';
CREATE VIEW herika_compat.locations_v AS SELECT locations.* FROM herika_compat.locations;

CREATE TABLE herika_compat.named_cell (
    id bigint NOT NULL,cell_name text,location_id bigint,interior integer,dest_door_cell_id bigint,
    dest_door_exterior bigint,door_id bigint NOT NULL,vanilla_cell boolean,statics_list text,
    worldspace text,closed integer,door_name text,door_x numeric,door_y numeric,gamets bigint
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.named_cell ADD CONSTRAINT cell_id_door_id PRIMARY KEY (id,door_id);
COMMENT ON COLUMN herika_compat.named_cell.id IS 'stable OpenMW cell compatibility id';
COMMENT ON COLUMN herika_compat.named_cell.location_id IS 'associated OpenMW location compatibility id';
COMMENT ON COLUMN herika_compat.named_cell.dest_door_exterior IS '-1 unknown, -2 in-cell door, 1 exterior, 0 interior';

CREATE TABLE herika_compat.factions (
    name text,formid text NOT NULL,vendor_cont text,stock jsonb,gold numeric,player_rank numeric,localts bigint
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.factions ADD CONSTRAINT factions_pkey PRIMARY KEY (formid);
COMMENT ON TABLE herika_compat.factions IS 'factions sent from OpenMW';

CREATE TABLE herika_compat.game_plugins (
    plugin_name text NOT NULL,is_light boolean DEFAULT false NOT NULL,compile_index integer DEFAULT 0 NOT NULL,
    small_file_compile_index integer DEFAULT 0 NOT NULL,partial_index integer DEFAULT 0 NOT NULL,
    formid_prefix text DEFAULT ''::text NOT NULL,updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
) WITH (autovacuum_enabled='on', toast.autovacuum_enabled='on');
ALTER TABLE ONLY herika_compat.game_plugins ADD CONSTRAINT game_plugins_pkey PRIMARY KEY (plugin_name);
COMMENT ON TABLE herika_compat.game_plugins IS 'Loaded OpenMW content files sent from the game runtime';

CREATE TABLE herika_compat.descriptions (
    plugin text DEFAULT ''::text NOT NULL,baseid character varying(128) NOT NULL,name text,description text
);
ALTER TABLE ONLY herika_compat.descriptions ADD CONSTRAINT descriptions_pkey PRIMARY KEY (plugin,baseid);
CREATE TABLE herika_compat.descriptions_custom (
    plugin text DEFAULT ''::text NOT NULL,baseid character varying(128) NOT NULL,name text,description text
);
ALTER TABLE ONLY herika_compat.descriptions_custom ADD CONSTRAINT descriptions_custom_pkey PRIMARY KEY (plugin,baseid);
CREATE VIEW herika_compat.combined_descriptions AS
SELECT c.plugin,c.baseid,c.name,c.description FROM herika_compat.descriptions_custom c
UNION ALL
SELECT i.plugin,i.baseid,i.name,i.description
FROM herika_compat.descriptions i LEFT JOIN herika_compat.descriptions_custom c
  ON i.plugin=c.plugin AND i.baseid=c.baseid
WHERE c.baseid IS NULL;

CREATE TABLE herika_compat.book_metadata (
    rowid bigint PRIMARY KEY REFERENCES herika_compat.books(rowid) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    session_id uuid REFERENCES public.sessions(session_id) ON DELETE SET NULL,
    record_id text,content_file text,source_turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL
);
CREATE TABLE herika_compat.quest_metadata (
    rowid bigint PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid REFERENCES public.playthroughs(playthrough_id) ON DELETE CASCADE,
    session_id uuid REFERENCES public.sessions(session_id) ON DELETE SET NULL,
    journal_id text NOT NULL,source_turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL
);
CREATE TABLE herika_compat.questlog_metadata (LIKE herika_compat.quest_metadata INCLUDING ALL);
CREATE TABLE herika_compat.location_metadata (
    formid bigint PRIMARY KEY,cell_key text NOT NULL UNIQUE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    source_turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL
);
CREATE TABLE herika_compat.faction_metadata (
    formid text PRIMARY KEY REFERENCES herika_compat.factions(formid) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    source_turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL,
    source_actor jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(source_actor)='object')
);
CREATE TABLE herika_compat.game_plugin_metadata (
    plugin_name text PRIMARY KEY REFERENCES herika_compat.game_plugins(plugin_name) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    content_fingerprint text,source_turn_id uuid REFERENCES public.turns(turn_id) ON DELETE SET NULL
);
CREATE TABLE herika_compat.description_metadata (
    plugin text NOT NULL,baseid character varying(128) NOT NULL,
    description_id uuid NOT NULL REFERENCES public.item_descriptions(description_id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES public.installations(installation_id) ON DELETE CASCADE,
    PRIMARY KEY (plugin,baseid),UNIQUE (description_id)
);

WITH book_items AS (
    SELECT DISTINCT ON (COALESCE(item->>'record_id',item->>'id',item->>'title',item->>'name'))
           t.turn_id,t.session_id,s.installation_id,s.playthrough_id,t.accepted_at,item,
           COALESCE(item->>'record_id',item->>'id',item->>'title',item->>'name') AS book_key
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(t.context#>'{books,items}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object' AND COALESCE(item->>'record_id',item->>'id',item->>'title',item->>'name') IS NOT NULL
    ORDER BY book_key,t.accepted_at DESC,t.turn_id
), numbered AS (
    SELECT book_items.*,row_number() OVER (ORDER BY accepted_at,book_key)::bigint AS compat_rowid FROM book_items
)
INSERT INTO herika_compat.books (rowid,sess,title,content,localts,gamets,ts)
SELECT compat_rowid,session_id::text,COALESCE(item->>'title',item->>'name',book_key),item->>'content',
       extract(epoch FROM accepted_at)::bigint,0,(extract(epoch FROM accepted_at)*1000)::bigint
FROM numbered ORDER BY compat_rowid;
WITH book_items AS (
    SELECT DISTINCT ON (COALESCE(item->>'record_id',item->>'id',item->>'title',item->>'name'))
           t.turn_id,t.session_id,s.installation_id,s.playthrough_id,t.accepted_at,item,
           COALESCE(item->>'record_id',item->>'id',item->>'title',item->>'name') AS book_key
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(t.context#>'{books,items}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object' AND COALESCE(item->>'record_id',item->>'id',item->>'title',item->>'name') IS NOT NULL
    ORDER BY book_key,t.accepted_at DESC,t.turn_id
), numbered AS (
    SELECT book_items.*,row_number() OVER (ORDER BY accepted_at,book_key)::bigint AS compat_rowid FROM book_items
)
INSERT INTO herika_compat.book_metadata (rowid,installation_id,playthrough_id,session_id,record_id,content_file,source_turn_id)
SELECT compat_rowid,installation_id,playthrough_id,session_id,book_key,item->>'content_file',turn_id FROM numbered;
SELECT setval('herika_compat.books_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.books),1),EXISTS(SELECT 1 FROM herika_compat.books));

WITH journal_items AS (
    SELECT DISTINCT ON (COALESCE(item->>'quest_id',item->>'id'))
           t.turn_id,t.session_id,s.installation_id,s.playthrough_id,t.accepted_at,t.context,item,
           COALESCE(item->>'quest_id',item->>'id') AS journal_id
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(t.context#>'{journal,items}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object' AND COALESCE(item->>'quest_id',item->>'id') IS NOT NULL
    ORDER BY journal_id,t.accepted_at DESC,t.turn_id
), numbered AS (
    SELECT journal_items.*,row_number() OVER (ORDER BY accepted_at,journal_id)::bigint AS compat_rowid FROM journal_items
)
INSERT INTO herika_compat.quests (
    rowid,ts,sess,id_quest,name,editor_id,mod,stage,briefing,briefing2,localts,gamets,data,status
)
SELECT compat_rowid,accepted_at::text,session_id::text,journal_id,journal_id,journal_id,
       item->>'content_file',CASE WHEN item->>'id'~'^-?[0-9]+$'
           AND (item->>'id')::numeric BETWEEN -2147483648 AND 2147483647 THEN (item->>'id')::integer END,
       item->>'text',concat_ws(' ',item->>'day',item->>'month',item->>'day_of_month'),
       extract(epoch FROM accepted_at)::bigint,
       CASE WHEN jsonb_typeof(context#>'{world,game_time}')='number' THEN floor((context#>>'{world,game_time}')::numeric)::bigint ELSE 0 END,
       item::text,'active'
FROM numbered ORDER BY compat_rowid;
WITH journal_items AS (
    SELECT DISTINCT ON (COALESCE(item->>'quest_id',item->>'id'))
           t.turn_id,t.session_id,s.installation_id,s.playthrough_id,t.accepted_at,item,
           COALESCE(item->>'quest_id',item->>'id') AS journal_id
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(t.context#>'{journal,items}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object' AND COALESCE(item->>'quest_id',item->>'id') IS NOT NULL
    ORDER BY journal_id,t.accepted_at DESC,t.turn_id
), numbered AS (
    SELECT journal_items.*,row_number() OVER (ORDER BY accepted_at,journal_id)::bigint AS compat_rowid FROM journal_items
)
INSERT INTO herika_compat.quest_metadata (rowid,installation_id,playthrough_id,session_id,journal_id,source_turn_id)
SELECT compat_rowid,installation_id,playthrough_id,session_id,journal_id,turn_id FROM numbered;
SELECT setval('herika_compat.quests_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.quests),1),EXISTS(SELECT 1 FROM herika_compat.quests));

WITH journal_changes AS (
    SELECT DISTINCT ON (COALESCE(item->>'quest_id',item->>'id'),item::text)
           t.turn_id,t.session_id,s.installation_id,s.playthrough_id,t.accepted_at,t.context,item,
           COALESCE(item->>'quest_id',item->>'id') AS journal_id
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(t.context#>'{journal,items}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object' AND COALESCE(item->>'quest_id',item->>'id') IS NOT NULL
    ORDER BY journal_id,item::text,t.accepted_at,t.turn_id
), numbered AS (
    SELECT journal_changes.*,row_number() OVER (ORDER BY accepted_at,journal_id)::integer AS compat_rowid FROM journal_changes
)
INSERT INTO herika_compat.questlog (
    rowid,ts,sess,id_quest,name,editor_id,mod,stage,briefing,briefing2,localts,gamets,data,status
)
SELECT compat_rowid,accepted_at::text,session_id::text,journal_id,journal_id,journal_id,
       item->>'content_file',CASE WHEN item->>'id'~'^-?[0-9]+$'
           AND (item->>'id')::numeric BETWEEN -2147483648 AND 2147483647 THEN (item->>'id')::integer END,
       item->>'text',concat_ws(' ',item->>'day',item->>'month',item->>'day_of_month'),
       extract(epoch FROM accepted_at)::bigint,
       CASE WHEN jsonb_typeof(context#>'{world,game_time}')='number' THEN floor((context#>>'{world,game_time}')::numeric)::bigint ELSE 0 END,
       item::text,'recorded'
FROM numbered ORDER BY compat_rowid;
WITH journal_changes AS (
    SELECT DISTINCT ON (COALESCE(item->>'quest_id',item->>'id'),item::text)
           t.turn_id,t.session_id,s.installation_id,s.playthrough_id,t.accepted_at,item,
           COALESCE(item->>'quest_id',item->>'id') AS journal_id
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(t.context#>'{journal,items}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object' AND COALESCE(item->>'quest_id',item->>'id') IS NOT NULL
    ORDER BY journal_id,item::text,t.accepted_at,t.turn_id
), numbered AS (
    SELECT journal_changes.*,row_number() OVER (ORDER BY accepted_at,journal_id)::integer AS compat_rowid FROM journal_changes
)
INSERT INTO herika_compat.questlog_metadata (rowid,installation_id,playthrough_id,session_id,journal_id,source_turn_id)
SELECT compat_rowid,installation_id,playthrough_id,session_id,journal_id,turn_id FROM numbered;
SELECT setval('herika_compat.questlog_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.questlog),1),EXISTS(SELECT 1 FROM herika_compat.questlog));

INSERT INTO herika_compat.currentmission (rowid,sess,description,localts,gamets,ts)
SELECT row_number() OVER (ORDER BY q.rowid),q.sess,q.briefing,q.localts,q.gamets,
       CASE WHEN q.ts~'^[0-9]+$' THEN q.ts::bigint ELSE q.localts*1000 END
FROM herika_compat.quests q;
SELECT setval('herika_compat.currentmission_rowid_seq',COALESCE((SELECT max(rowid) FROM herika_compat.currentmission),1),EXISTS(SELECT 1 FROM herika_compat.currentmission));

WITH cells AS (
    SELECT DISTINCT ON (NULLIF(t.context#>>'{world,cell}',''))
           t.turn_id,s.installation_id,t.accepted_at,NULLIF(t.context#>>'{world,cell}','') AS cell_key
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    WHERE NULLIF(t.context#>>'{world,cell}','') IS NOT NULL
    ORDER BY cell_key,t.accepted_at DESC,t.turn_id
), mapped AS (
    SELECT cells.*,(('x'||substr(md5(cell_key),1,16))::bit(64)::bigint) AS compat_formid FROM cells
)
INSERT INTO herika_compat.locations (name,formid,region,hold,tags,factions,is_interior,vanilla_location,updated_at,world)
SELECT cell_key,compat_formid,NULL,NULL,'openmw',NULL,CASE WHEN cell_key LIKE 'exterior:%' THEN 0 ELSE 1 END,
       NULL,accepted_at AT TIME ZONE 'UTC','Morrowind/OpenMW'
FROM mapped;
WITH cells AS (
    SELECT DISTINCT ON (NULLIF(t.context#>>'{world,cell}',''))
           t.turn_id,s.installation_id,NULLIF(t.context#>>'{world,cell}','') AS cell_key
    FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    WHERE NULLIF(t.context#>>'{world,cell}','') IS NOT NULL
    ORDER BY cell_key,t.accepted_at DESC,t.turn_id
)
INSERT INTO herika_compat.location_metadata (formid,cell_key,installation_id,source_turn_id)
SELECT (('x'||substr(md5(cell_key),1,16))::bit(64)::bigint),cell_key,installation_id,turn_id FROM cells;

WITH latest AS (
    SELECT t.*,s.installation_id FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT 1
), faction_rows AS (
    SELECT latest.turn_id,latest.installation_id,latest.accepted_at,latest.target AS actor,item
    FROM latest CROSS JOIN LATERAL jsonb_array_elements(COALESCE(latest.context#>'{targetState,factions}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object'
    UNION ALL
    SELECT latest.turn_id,latest.installation_id,latest.accepted_at,latest.speaker,item
    FROM latest CROSS JOIN LATERAL jsonb_array_elements(COALESCE(latest.context#>'{playerState,factions}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object'
), unique_factions AS (
    SELECT DISTINCT ON (item->>'id') * FROM faction_rows WHERE NULLIF(item->>'id','') IS NOT NULL ORDER BY item->>'id'
)
INSERT INTO herika_compat.factions (name,formid,player_rank,localts)
SELECT item->>'id',item->>'id',CASE WHEN item->>'rank'~'^-?[0-9]+(\.[0-9]+)?$' THEN (item->>'rank')::numeric END,
       extract(epoch FROM accepted_at)::bigint
FROM unique_factions;
WITH latest AS (
    SELECT t.*,s.installation_id FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT 1
), faction_rows AS (
    SELECT latest.turn_id,latest.installation_id,latest.target AS actor,item
    FROM latest CROSS JOIN LATERAL jsonb_array_elements(COALESCE(latest.context#>'{targetState,factions}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object'
    UNION ALL
    SELECT latest.turn_id,latest.installation_id,latest.speaker,item
    FROM latest CROSS JOIN LATERAL jsonb_array_elements(COALESCE(latest.context#>'{playerState,factions}','[]'::jsonb)) item
    WHERE jsonb_typeof(item)='object'
), unique_factions AS (
    SELECT DISTINCT ON (item->>'id') * FROM faction_rows WHERE NULLIF(item->>'id','') IS NOT NULL ORDER BY item->>'id'
)
INSERT INTO herika_compat.faction_metadata (formid,installation_id,source_turn_id,source_actor)
SELECT item->>'id',installation_id,turn_id,actor FROM unique_factions;

WITH latest AS (
    SELECT t.*,s.installation_id,s.content_fingerprint FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT 1
), files AS (
    SELECT latest.turn_id,latest.installation_id,latest.content_fingerprint,latest.accepted_at,file_name,ordinality
    FROM latest CROSS JOIN LATERAL jsonb_array_elements_text(COALESCE(latest.context#>'{contentFiles,items}','[]'::jsonb)) WITH ORDINALITY f(file_name,ordinality)
)
INSERT INTO herika_compat.game_plugins (plugin_name,compile_index,updated_at)
SELECT file_name,(ordinality-1)::integer,accepted_at AT TIME ZONE 'UTC' FROM files;
WITH latest AS (
    SELECT t.*,s.installation_id,s.content_fingerprint FROM public.turns t JOIN public.sessions s ON s.session_id=t.session_id
    ORDER BY t.accepted_at DESC,t.turn_id DESC LIMIT 1
), files AS (
    SELECT latest.turn_id,latest.installation_id,latest.content_fingerprint,file_name
    FROM latest CROSS JOIN LATERAL jsonb_array_elements_text(COALESCE(latest.context#>'{contentFiles,items}','[]'::jsonb)) AS f(file_name)
)
INSERT INTO herika_compat.game_plugin_metadata (plugin_name,installation_id,content_fingerprint,source_turn_id)
SELECT file_name,installation_id,content_fingerprint,turn_id FROM files;

INSERT INTO herika_compat.descriptions_custom (plugin,baseid,name,description)
SELECT content_file,record_id,display_name,description FROM public.item_descriptions WHERE deleted_at IS NULL;
INSERT INTO herika_compat.description_metadata (plugin,baseid,description_id,installation_id)
SELECT content_file,record_id,description_id,installation_id FROM public.item_descriptions WHERE deleted_at IS NULL;
