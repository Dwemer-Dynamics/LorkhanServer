-- Keep saved policy revisions and their provider audit trail intact on downgrade.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.configuration_sets WHERE kind='translation_policy')
        OR EXISTS (SELECT 1 FROM lorkhan_internal.provider_attempts WHERE provider_kind='translation') THEN
        RAISE EXCEPTION 'Cannot remove translation support while policy history or provider attempts exist.';
    END IF;
END $$;

ALTER TABLE lorkhan_internal.provider_attempts
    DROP CONSTRAINT provider_attempts_provider_kind_check,
    ADD CONSTRAINT provider_attempts_provider_kind_check
        CHECK (provider_kind IN ('llm','stt','tts','embedding'));

DROP INDEX lorkhan_internal.one_translation_policy_per_installation;
ALTER TABLE lorkhan_internal.configuration_sets
    DROP CONSTRAINT configuration_sets_kind_check,
    ADD CONSTRAINT configuration_sets_kind_check CHECK (kind IN (
        'prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy',
        'memory_embedding_policy'
    ));
