ALTER TABLE lorkhan_internal.action_intents
    ADD COLUMN IF NOT EXISTS followup_prompt text NOT NULL DEFAULT ''
        CHECK (char_length(followup_prompt) <= 2048);

-- Complete rows already contain Herika-style metadata. Add the two follow-up
-- keys used by the typed LORKHAN result path without disturbing custom values.
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
                               '{metadata,followup}',
                               COALESCE(entry.value->'metadata'->'followup', '{}'::jsonb)
                                   || jsonb_build_object(
                                       'arg_name', COALESCE(entry.value->'metadata'->'followup'->>'arg_name', 'result'),
                                       'prompt', COALESCE(
                                           entry.value->'metadata'->'followup'->>'prompt',
                                           'Respond briefly to the completed action result. Acknowledge the observed outcome without proposing or performing another action.'
                                       )
                                   ),
                               true
                           ),
                           '{metadata,custom_config}',
                           COALESCE(entry.value->'metadata'->'custom_config', '{}'::jsonb)
                               || jsonb_build_object(
                                   'followup_prompt', COALESCE(
                                       entry.value->'metadata'->'custom_config'->>'followup_prompt',
                                       entry.value->'metadata'->'followup'->>'prompt',
                                       'Respond briefly to the completed action result. Acknowledge the observed outcome without proposing or performing another action.'
                                   )
                               ),
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
