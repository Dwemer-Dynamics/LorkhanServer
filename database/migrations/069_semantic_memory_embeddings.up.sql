ALTER TABLE almsivi_internal.configuration_sets
    DROP CONSTRAINT configuration_sets_kind_check,
    ADD CONSTRAINT configuration_sets_kind_check CHECK (kind IN (
        'prompt','provider','tts_provider','stt_provider','action_policy','global_settings','memory_policy',
        'memory_embedding_policy'
    ));

CREATE UNIQUE INDEX one_memory_embedding_policy_per_installation
    ON almsivi_internal.configuration_sets(installation_id)
    WHERE kind='memory_embedding_policy' AND deleted_at IS NULL;

ALTER TABLE almsivi_internal.provider_attempts
    DROP CONSTRAINT provider_attempts_provider_kind_check,
    ADD CONSTRAINT provider_attempts_provider_kind_check
        CHECK (provider_kind IN ('llm','stt','tts','embedding'));

-- Semantic vectors are optional projections of exact memory and policy revisions.
CREATE TABLE almsivi_internal.memory_embeddings (
    memory_id uuid NOT NULL,
    memory_revision integer NOT NULL CHECK (memory_revision > 0),
    policy_configuration_id uuid NOT NULL,
    policy_revision integer NOT NULL CHECK (policy_revision > 0),
    dimensions integer NOT NULL CHECK (dimensions BETWEEN 8 AND 1536),
    embedding jsonb NOT NULL CHECK (
        jsonb_typeof(embedding)='array' AND jsonb_array_length(embedding)=dimensions
    ),
    input_sha256 text NOT NULL CHECK (input_sha256 ~ '^[0-9a-f]{64}$'),
    model text NOT NULL CHECK (octet_length(model) BETWEEN 1 AND 256),
    created_at timestamptz NOT NULL,
    PRIMARY KEY(memory_id,memory_revision,policy_configuration_id,policy_revision),
    FOREIGN KEY(memory_id,memory_revision)
        REFERENCES almsivi_internal.memory_record_revisions(memory_id,revision) ON DELETE CASCADE,
    FOREIGN KEY(policy_configuration_id,policy_revision)
        REFERENCES almsivi_internal.configuration_revisions(configuration_id,revision)
);

CREATE INDEX memory_embeddings_policy_revision
    ON almsivi_internal.memory_embeddings(policy_configuration_id,policy_revision,memory_id);
