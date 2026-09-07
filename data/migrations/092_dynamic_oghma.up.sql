-- Quest-stage patches are reusable installation rules; applied knowledge belongs to a playthrough.
CREATE TABLE lorkhan_internal.oghma_dynamic (
    id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    revision integer NOT NULL DEFAULT 1 CHECK (revision>0),
    id_quest varchar(256) NOT NULL CHECK (id_quest=lower(btrim(id_quest)) AND length(id_quest)>0),
    stage integer NOT NULL CHECK (stage>=0),
    topic varchar(256) NOT NULL CHECK (topic=lower(btrim(topic)) AND length(topic)>0),
    topic_desc text NOT NULL DEFAULT '' CHECK (octet_length(topic_desc)<=131072),
    knowledge_class text NOT NULL DEFAULT '' CHECK (octet_length(knowledge_class)<=4096),
    topic_desc_basic text NOT NULL DEFAULT '' CHECK (octet_length(topic_desc_basic)<=131072),
    knowledge_class_basic text NOT NULL DEFAULT '' CHECK (octet_length(knowledge_class_basic)<=4096),
    tags text NOT NULL DEFAULT '' CHECK (octet_length(tags)<=4096),
    category varchar(128) NOT NULL DEFAULT '',
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz
);
CREATE UNIQUE INDEX oghma_dynamic_active_key ON lorkhan_internal.oghma_dynamic (installation_id,id_quest,stage,topic) WHERE deleted_at IS NULL;

CREATE TABLE lorkhan_internal.oghma_dynamic_applications (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id),
    rule_id uuid NOT NULL REFERENCES lorkhan_internal.oghma_dynamic(id),
    revision integer NOT NULL CHECK (revision>0),
    source_event_id uuid NOT NULL REFERENCES lorkhan_internal.source_events(source_event_id),
    document_id uuid NOT NULL REFERENCES lorkhan_internal.knowledge_documents(document_id),
    patch jsonb NOT NULL CHECK (jsonb_typeof(patch)='object'),
    applied_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id,playthrough_id,rule_id,revision)
);

-- Dynamic patches can explicitly clear advanced text; ordinary editor validation stays unchanged.
ALTER TABLE lorkhan_internal.knowledge_documents
    DROP CONSTRAINT knowledge_documents_content_check,
    ADD CONSTRAINT knowledge_documents_content_check CHECK (octet_length(content) BETWEEN 0 AND 131072);
