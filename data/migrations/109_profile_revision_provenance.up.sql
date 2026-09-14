-- Legacy/manual revisions remain an explicit boundary; never infer provenance from reason text.
ALTER TABLE lorkhan_internal.profile_revisions ADD COLUMN provenance jsonb NOT NULL DEFAULT '{}'::jsonb
    CHECK (jsonb_typeof(provenance)='object' AND octet_length(provenance::text)<=32768);
