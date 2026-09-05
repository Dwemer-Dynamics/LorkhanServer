-- Rewrite legacy sparse action overrides as complete HerikaServer-compatible action rows.
WITH converted AS (
    SELECT r.configuration_id,
           r.revision,
           jsonb_object_agg(
               entry.key,
               CASE
                   WHEN jsonb_typeof(entry.value) = 'object' AND entry.value ? 'code_name' THEN entry.value
                   ELSE jsonb_build_object(
                       'code_name', a.action_name,
                       'action_name', COALESCE(NULLIF(entry.value->>'display_name', ''), a.display_name),
                       'description', COALESCE(entry.value->>'description', a.description),
                       'return_message', COALESCE(entry.value->>'return_message', ''),
                       'available_to_npc', true,
                       'available_to_followers', false,
                       'available_to_narrator', false,
                       'is_activated', CASE
                           WHEN jsonb_typeof(entry.value) = 'boolean' THEN (entry.value::text)::boolean
                           ELSE COALESCE((entry.value->>'enabled')::boolean, a.enabled)
                       END,
                       'parameters_json', a.parameter_schema,
                       'metadata', jsonb_build_object(
                           'tier', a.tier,
                           'client_capability', a.client_capability,
                           'result_schema', a.result_schema,
                           'server_owned', a.server_owned,
                           'terminal_result_required', a.terminal_result_required,
                           'continuation_capable', a.continuation_capable,
                           'confirmation_mode', a.confirmation_mode,
                           'followup', jsonb_build_object(
                               'enabled', a.followup_default,
                               'use_functions_again', false
                           ),
                           'custom_config', jsonb_build_object(
                               'confirmation_required', COALESCE(
                                   (entry.value->>'confirmation_required')::boolean,
                                   a.confirmation_mode = 'required'
                               ),
                               'followup_enabled', COALESCE(
                                   (entry.value->>'followup_enabled')::boolean,
                                   a.followup_default
                               ),
                               'followup_use_functions_again', COALESCE(
                                   (entry.value->>'allow_followup_action')::boolean,
                                   false
                               )
                           ),
                           'followup_actions_supported', a.followup_actions_supported,
                           'cooldown_seconds', COALESCE((entry.value->>'cooldown_seconds')::integer, a.cooldown_seconds)
                       ),
                       'game_function', true,
                       'import_version', 1,
                       'script_proxy_program', NULL
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
    JOIN lorkhan_internal.action_catalog a ON a.action_name = entry.key
    GROUP BY r.configuration_id, r.revision
)
UPDATE lorkhan_internal.configuration_revisions r
SET content = jsonb_set(r.content, '{actions}', converted.actions, true)
FROM converted
WHERE r.configuration_id = converted.configuration_id
  AND r.revision = converted.revision;
