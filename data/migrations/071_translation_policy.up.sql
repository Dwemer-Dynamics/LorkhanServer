ALTER TABLE lorkhan_internal.configuration_sets
    DROP CONSTRAINT configuration_sets_kind_check,
    ADD CONSTRAINT configuration_sets_kind_check CHECK (kind IN (
        'prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy',
        'memory_embedding_policy','translation_policy'
    ));

CREATE UNIQUE INDEX one_translation_policy_per_installation
    ON lorkhan_internal.configuration_sets(installation_id)
    WHERE kind='translation_policy' AND deleted_at IS NULL;

ALTER TABLE lorkhan_internal.provider_attempts
    DROP CONSTRAINT provider_attempts_provider_kind_check,
    ADD CONSTRAINT provider_attempts_provider_kind_check
        CHECK (provider_kind IN ('llm','stt','tts','embedding','translation'));
