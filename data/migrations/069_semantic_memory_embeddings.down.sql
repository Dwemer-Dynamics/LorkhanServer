-- Never strand embedding work or discard saved policy/vector provenance during downgrade.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.memory_embeddings)
        OR EXISTS (SELECT 1 FROM lorkhan_internal.configuration_sets WHERE kind='memory_embedding_policy')
        OR EXISTS (SELECT 1 FROM lorkhan_internal.durable_jobs WHERE job_type='memory.embed') THEN
        RAISE EXCEPTION 'Cannot remove semantic memory support while embeddings, policy history or jobs exist.';
    END IF;
END $$;

DROP INDEX lorkhan_internal.memory_embeddings_policy_revision;
DROP TABLE lorkhan_internal.memory_embeddings;

ALTER TABLE lorkhan_internal.provider_attempts
    DROP CONSTRAINT provider_attempts_provider_kind_check,
    ADD CONSTRAINT provider_attempts_provider_kind_check
        CHECK (provider_kind IN ('llm','stt','tts'));

DROP INDEX lorkhan_internal.one_memory_embedding_policy_per_installation;
ALTER TABLE lorkhan_internal.configuration_sets
    DROP CONSTRAINT configuration_sets_kind_check,
    ADD CONSTRAINT configuration_sets_kind_check CHECK (kind IN (
        'prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy'
    ));
