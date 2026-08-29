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
               COALESCE(content->'behavior','{}'::jsonb)
                   - 'rechat_probability_percent'
                   - 'rechat_mode'
                   - 'rechat_strict_targeting'
                   - 'open_rechat'
                   - 'rechat_allow_actions'
                   - 'end_conversation_cooldown_seconds',
               true
           ),
           'Herika-compatible rechat settings removed',
           clock_timestamp()
    FROM current_settings
    RETURNING configuration_id,revision
)
UPDATE lorkhan_internal.configuration_sets configuration
SET current_revision=inserted.revision
FROM inserted
WHERE configuration.configuration_id=inserted.configuration_id;

ALTER TABLE lorkhan_internal.rechat_chains
    DROP COLUMN origin_line,
    DROP COLUMN previous_listener,
    DROP COLUMN round_budget,
    DROP COLUMN strict_targeting,
    DROP COLUMN probability_percent,
    DROP COLUMN configured_mode;
