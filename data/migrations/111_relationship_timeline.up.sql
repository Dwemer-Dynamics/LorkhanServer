-- Unknown pre-migration history stays a boundary. Never infer automatic work from editable reason text.
CREATE TABLE lorkhan_internal.relationship_revisions (
    relationship_id uuid NOT NULL REFERENCES lorkhan_internal.relationship_records(relationship_id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision > 0),
    content jsonb NOT NULL CHECK (jsonb_typeof(content)='object'),
    provenance jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(provenance)='object' AND octet_length(provenance::text)<=32768),
    PRIMARY KEY (relationship_id,revision)
);
