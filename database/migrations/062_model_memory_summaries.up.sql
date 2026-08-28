ALTER TABLE almsivi_internal.configuration_sets
    DROP CONSTRAINT configuration_sets_kind_check,
    ADD CONSTRAINT configuration_sets_kind_check CHECK (kind IN (
        'prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy'
    ));
CREATE UNIQUE INDEX one_memory_policy_per_installation
    ON almsivi_internal.configuration_sets(installation_id)
    WHERE kind='memory_policy' AND deleted_at IS NULL;

-- Model text is an optional projection of one exact memory revision, never a replacement for it.
CREATE TABLE almsivi_internal.memory_model_summaries (
    memory_id uuid NOT NULL REFERENCES almsivi_internal.memory_records(memory_id) ON DELETE CASCADE,
    memory_revision integer NOT NULL CHECK (memory_revision > 0),
    policy_configuration_id uuid NOT NULL,
    policy_revision integer NOT NULL,
    provider_configuration_id uuid NOT NULL,
    provider_revision integer NOT NULL,
    content text NOT NULL CHECK (octet_length(content) BETWEEN 1 AND 4096),
    input_sha256 text NOT NULL CHECK (input_sha256 ~ '^[0-9a-f]{64}$'),
    created_at timestamptz NOT NULL,
    PRIMARY KEY(memory_id,memory_revision),
    FOREIGN KEY(memory_id,memory_revision)
        REFERENCES almsivi_internal.memory_record_revisions(memory_id,revision) ON DELETE CASCADE,
    FOREIGN KEY(policy_configuration_id,policy_revision)
        REFERENCES almsivi_internal.configuration_revisions(configuration_id,revision),
    FOREIGN KEY(provider_configuration_id,provider_revision)
        REFERENCES almsivi_internal.configuration_revisions(configuration_id,revision)
);
