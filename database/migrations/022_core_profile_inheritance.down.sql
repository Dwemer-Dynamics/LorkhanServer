ALTER TABLE prompt_trace_sources DROP CONSTRAINT prompt_trace_sources_source_kind_check;
ALTER TABLE prompt_trace_sources ADD CONSTRAINT prompt_trace_sources_source_kind_check
    CHECK (source_kind IN (
        'profile','prompt','turn','memory','relationship','knowledge','narrative','action_result','action_catalog'
    ));

ALTER TABLE prompt_traces DROP CONSTRAINT IF EXISTS prompt_traces_core_profile_fk;
ALTER TABLE prompt_traces
    DROP COLUMN IF EXISTS settings_sources,
    DROP COLUMN IF EXISTS effective_settings_sha256,
    DROP COLUMN IF EXISTS core_profile_revision,
    DROP COLUMN IF EXISTS core_profile_id;

DROP INDEX IF EXISTS profiles_core_profile;
ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_core_profile_installation_fk;
ALTER TABLE profiles DROP COLUMN IF EXISTS core_profile_id;

DROP TABLE core_profile_revisions;
DROP TABLE core_profiles;
