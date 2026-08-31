WITH converted AS (
    SELECT r.configuration_id,
           r.revision,
           jsonb_object_agg(
               entry.key,
               CASE
                   WHEN jsonb_typeof(entry.value) = 'object' AND entry.value ? 'code_name' THEN
                       jsonb_set(
                           jsonb_set(
                               entry.value,
                               '{metadata,custom_config}',
                               COALESCE(entry.value->'metadata'->'custom_config', '{}'::jsonb) - 'followup_prompt',
                               true
                           ),
                           '{metadata,followup}',
                           COALESCE(entry.value->'metadata'->'followup', '{}'::jsonb) - 'arg_name' - 'prompt',
                           true
                       )
                   ELSE entry.value
               END
           ) AS actions
    FROM lorkhan_internal.configuration_revisions r
    JOIN lorkhan_internal.configuration_sets c
      ON c.configuration_id = r.configuration_id
     AND c.kind = 'action_policy'
    CROSS JOIN LATERAL jsonb_each(
        CASE WHEN jsonb_typeof(r.content->'actions') = 'object' THEN r.content->'actions' ELSE '{}'::jsonb END
    ) entry
    GROUP BY r.configuration_id, r.revision
)
UPDATE lorkhan_internal.configuration_revisions r
SET content = jsonb_set(r.content, '{actions}', converted.actions, true)
FROM converted
WHERE r.configuration_id = converted.configuration_id
  AND r.revision = converted.revision;

ALTER TABLE lorkhan_internal.action_intents
    DROP COLUMN IF EXISTS followup_prompt;
