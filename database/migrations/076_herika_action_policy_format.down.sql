-- Restore the legacy sparse field names while retaining every effective edited value.
WITH converted AS (
    SELECT r.configuration_id,
           r.revision,
           jsonb_object_agg(
               entry.key,
               CASE
                   WHEN jsonb_typeof(entry.value) <> 'object' OR NOT (entry.value ? 'code_name') THEN entry.value
                   ELSE jsonb_build_object(
                       'enabled', (entry.value->>'is_activated')::boolean,
                       'display_name', entry.value->>'action_name',
                       'description', entry.value->>'description',
                       'confirmation_required', COALESCE(
                           (entry.value->'metadata'->'custom_config'->>'confirmation_required')::boolean,
                           false
                       ),
                       'followup_enabled', COALESCE(
                           (entry.value->'metadata'->'custom_config'->>'followup_enabled')::boolean,
                           false
                       ),
                       'allow_followup_action', COALESCE(
                           (entry.value->'metadata'->'custom_config'->>'followup_use_functions_again')::boolean,
                           false
                       ),
                       'cooldown_seconds', COALESCE(
                           (entry.value->'metadata'->>'cooldown_seconds')::integer,
                           0
                       )
                   )
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
