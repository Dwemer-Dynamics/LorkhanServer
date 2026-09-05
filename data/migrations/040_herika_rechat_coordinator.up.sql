ALTER TABLE lorkhan_internal.rechat_chains
    ADD COLUMN configured_mode text NOT NULL DEFAULT 'random'
        CHECK (configured_mode IN ('tight','conversational','group','random')),
    ADD COLUMN probability_percent smallint NOT NULL DEFAULT 50
        CHECK (probability_percent BETWEEN 0 AND 100),
    ADD COLUMN strict_targeting boolean NOT NULL DEFAULT false,
    ADD COLUMN round_budget smallint
        CHECK (round_budget BETWEEN 1 AND 32),
    ADD COLUMN previous_listener jsonb
        CHECK (previous_listener IS NULL OR jsonb_typeof(previous_listener) = 'object'),
    ADD COLUMN origin_line text;

UPDATE lorkhan_internal.rechat_chains
SET configured_mode=mode,
    round_budget=max_depth
WHERE round_budget IS NULL;

ALTER TABLE lorkhan_internal.rechat_chains ALTER COLUMN round_budget SET NOT NULL;

WITH current_settings AS (
    SELECT configuration.configuration_id,
           configuration.current_revision,
           revision.content
    FROM lorkhan_internal.configuration_sets configuration
    JOIN lorkhan_internal.configuration_revisions revision
      ON revision.configuration_id=configuration.configuration_id
     AND revision.revision=configuration.current_revision
    WHERE configuration.kind='global_settings'
      AND configuration.deleted_at IS NULL
), inserted AS (
    INSERT INTO lorkhan_internal.configuration_revisions (
        configuration_id,revision,content,change_reason,created_at
    )
    SELECT configuration_id,
           current_revision+1,
           jsonb_set(
               content,
               '{behavior}',
               jsonb_build_object(
                   'rechat_probability_percent',50,
                   'rechat_mode','random',
                   'rechat_strict_targeting',false,
                   'open_rechat',true,
                   'rechat_allow_actions',false,
                   'end_conversation_cooldown_seconds',60
               ) || COALESCE(content->'behavior','{}'::jsonb),
               true
           ),
           'Herika-compatible rechat settings added',
           clock_timestamp()
    FROM current_settings
    RETURNING configuration_id,revision
)
UPDATE lorkhan_internal.configuration_sets configuration
SET current_revision=inserted.revision
FROM inserted
WHERE configuration.configuration_id=inserted.configuration_id;
