-- Keep historical revisions and custom providers intact while replacing the retired default.
WITH classifiers AS (
    SELECT configuration.configuration_id, configuration.current_revision, revision.content
    FROM lorkhan_internal.configuration_sets configuration
    JOIN lorkhan_internal.configuration_revisions revision
      ON revision.configuration_id = configuration.configuration_id
     AND revision.revision = configuration.current_revision
    WHERE configuration.kind = 'provider'
      AND configuration.deleted_at IS NULL
      AND LOWER(configuration.name) IN (
          'gemma 3n e4b', 'scene classifier (gemma 3n e4b)',
          'scene classifier (gemini 2.5 flash lite)'
      )
      AND revision.content->>'model' = 'google/gemma-3n-e4b-it'
      AND revision.content->>'driver' = 'openai-compatible'
      AND revision.content->>'endpoint' = 'https://openrouter.ai/api/v1/chat/completions'
    FOR UPDATE OF configuration
), inserted AS (
    INSERT INTO lorkhan_internal.configuration_revisions (
        configuration_id, revision, content, change_reason, created_at
    )
    SELECT configuration_id, current_revision + 1,
           jsonb_set(content, '{model}', '"google/gemma-3-4b-it"'::jsonb),
           'Replace retired OpenRouter scene classifier model', clock_timestamp()
    FROM classifiers
    RETURNING configuration_id, revision
)
UPDATE lorkhan_internal.configuration_sets configuration
-- Names are installation-unique and may already coexist with a newer connector.
SET current_revision = inserted.revision
FROM inserted
WHERE configuration.configuration_id = inserted.configuration_id;
