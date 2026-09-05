ALTER TABLE action_delivery DROP CONSTRAINT IF EXISTS action_delivery_single_continuation;
ALTER TABLE action_catalog DROP COLUMN IF EXISTS continuation_capable;
ALTER TABLE action_catalog DROP COLUMN IF EXISTS terminal_result_required;
ALTER TABLE action_catalog DROP COLUMN IF EXISTS max_parameter_bytes;
ALTER TABLE action_catalog DROP COLUMN IF EXISTS result_schema;
DROP INDEX IF EXISTS narrative_records_derivation_key_unique;
ALTER TABLE narrative_records DROP COLUMN IF EXISTS derivation_key;
DROP INDEX IF EXISTS memory_records_derivation_key_unique;
ALTER TABLE memory_records DROP COLUMN IF EXISTS derivation_key;
DROP TABLE IF EXISTS prompt_trace_sources;
DROP TABLE IF EXISTS prompt_traces;
ALTER TABLE sessions DROP CONSTRAINT IF EXISTS sessions_playthrough_installation_profile_fk;
ALTER TABLE sessions DROP CONSTRAINT IF EXISTS sessions_profile_installation_fk;
ALTER TABLE playthroughs DROP CONSTRAINT IF EXISTS playthroughs_profile_installation_fk;
ALTER TABLE playthroughs DROP CONSTRAINT IF EXISTS playthroughs_id_installation_profile_unique;
ALTER TABLE profiles DROP CONSTRAINT IF EXISTS profiles_id_installation_unique;
-- Backfilled profiles, playthroughs, and revisions are intentionally retained as server-owned data.
