-- Never discard generated summaries or policy revisions during a downgrade.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM almsivi_internal.memory_model_summaries)
        OR EXISTS (SELECT 1 FROM almsivi_internal.configuration_sets WHERE kind='memory_policy') THEN
        RAISE EXCEPTION 'Cannot remove model memory support while summaries or policy history exist.';
    END IF;
END $$;
DROP TABLE almsivi_internal.memory_model_summaries;
DROP INDEX almsivi_internal.one_memory_policy_per_installation;
ALTER TABLE almsivi_internal.configuration_sets
    DROP CONSTRAINT configuration_sets_kind_check,
    ADD CONSTRAINT configuration_sets_kind_check CHECK (kind IN (
        'prompt','provider','tts_provider','stt_provider','action_policy','global_settings'
    ));
