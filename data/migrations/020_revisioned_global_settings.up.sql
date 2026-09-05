ALTER TABLE configuration_sets DROP CONSTRAINT IF EXISTS configuration_sets_kind_check;
ALTER TABLE configuration_sets ADD CONSTRAINT configuration_sets_kind_check
    CHECK (kind IN ('prompt', 'provider', 'tts_provider', 'stt_provider', 'action_policy', 'global_settings'));

CREATE UNIQUE INDEX IF NOT EXISTS configuration_sets_one_global_settings_per_installation
    ON configuration_sets (installation_id)
    WHERE kind = 'global_settings' AND deleted_at IS NULL;
