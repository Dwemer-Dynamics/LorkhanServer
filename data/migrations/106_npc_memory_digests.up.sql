CREATE TABLE lorkhan_internal.npc_memory_digests (
    digest_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision>0),
    previous_digest_id uuid,
    job_id uuid REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE SET NULL,
    cleared boolean NOT NULL DEFAULT false,
    content text NOT NULL CHECK (octet_length(content)<=16384 AND (CASE WHEN cleared THEN content='' ELSE octet_length(content)>0 END)),
    cursor_occurred_at timestamptz NOT NULL,
    cursor_memory_id uuid NOT NULL,
    source_revisions jsonb NOT NULL CHECK (jsonb_typeof(source_revisions)='array' AND jsonb_array_length(source_revisions)<=100 AND octet_length(source_revisions::text)<=131072),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (installation_id,playthrough_id,profile_id,revision),
    UNIQUE (job_id),
    UNIQUE (installation_id,playthrough_id,profile_id,digest_id),
    FOREIGN KEY (installation_id,playthrough_id,profile_id,previous_digest_id)
        REFERENCES lorkhan_internal.npc_memory_digests(installation_id,playthrough_id,profile_id,digest_id)
);
CREATE INDEX npc_memory_digests_scope ON lorkhan_internal.npc_memory_digests(installation_id,playthrough_id,profile_id,revision DESC);
ALTER TABLE lorkhan_internal.prompt_trace_sources DROP CONSTRAINT prompt_trace_sources_source_kind_check;
ALTER TABLE lorkhan_internal.prompt_trace_sources ADD CONSTRAINT prompt_trace_sources_source_kind_check CHECK (source_kind IN (
    'profile','core_profile','prompt','history','turn','memory','memory_digest','relationship','knowledge','narrative','action_result','action_catalog'
));
