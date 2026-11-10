-- Restore the previous Use Item and Equip Item catalog parameters, including content_file.
CREATE TEMPORARY TABLE item_action_projection_snapshot AS
SELECT id, code_name, parameters_json, description, is_activated, metadata
FROM public.core_action_custom WHERE code_name IN ('item.use', 'item.equip');

UPDATE lorkhan_internal.action_catalog SET
    parameter_schema = jsonb_set(jsonb_set(parameter_schema, '{properties,content_file}',
        '{"type": "string", "maxLength": 256, "minLength": 1}'::jsonb), '{required}',
        CASE WHEN action_name = 'item.equip' THEN '["record_id", "content_file", "slot"]'::jsonb
            ELSE '["record_id", "content_file"]'::jsonb END)
WHERE action_name IN ('item.use', 'item.equip')
    AND parameter_schema #> '{properties,content_file}' IS NULL;

-- Restore unrelated custom settings rewritten by the catalog projection trigger.
UPDATE public.core_action_custom c SET
    parameters_json = CASE WHEN s.parameters_json #> '{properties,content_file}' IS NOT NULL THEN s.parameters_json
        ELSE jsonb_set(jsonb_set(s.parameters_json, '{properties,content_file}',
            '{"type": "string", "maxLength": 256, "minLength": 1}'::jsonb), '{required}',
            CASE WHEN s.code_name = 'item.equip' THEN '["record_id", "content_file", "slot"]'::jsonb
                ELSE '["record_id", "content_file"]'::jsonb END) END,
    description = s.description, is_activated = s.is_activated, metadata = s.metadata
FROM item_action_projection_snapshot s
WHERE c.id = s.id;

-- Restore content_file in complete saved rows while preserving every other override and history.
WITH candidates AS (
    SELECT s.configuration_id, s.current_revision, r.content
    FROM lorkhan_internal.configuration_sets s
    JOIN lorkhan_internal.configuration_revisions r
        ON r.configuration_id = s.configuration_id AND r.revision = s.current_revision
    WHERE s.kind = 'action_policy' AND s.deleted_at IS NULL
        AND ((r.content #> '{actions,item.use,parameters_json,properties}' IS NOT NULL
                AND r.content #> '{actions,item.use,parameters_json,properties,content_file}' IS NULL)
            OR (r.content #> '{actions,item.equip,parameters_json,properties}' IS NOT NULL
                AND r.content #> '{actions,item.equip,parameters_json,properties,content_file}' IS NULL))
), restored AS (
    SELECT c.configuration_id, c.current_revision,
        jsonb_set(c.content, '{actions}', (
            SELECT jsonb_object_agg(a.key, CASE
                WHEN a.key IN ('item.use', 'item.equip') AND a.value #> '{parameters_json,properties}' IS NOT NULL
                    AND a.value #> '{parameters_json,properties,content_file}' IS NULL
                THEN jsonb_set(jsonb_set(a.value, '{parameters_json,properties,content_file}',
                    '{"type": "string", "maxLength": 256, "minLength": 1}'::jsonb), '{parameters_json,required}',
                    CASE WHEN a.key = 'item.equip' THEN '["record_id", "content_file", "slot"]'::jsonb
                        ELSE '["record_id", "content_file"]'::jsonb END)
                ELSE a.value END)
            FROM jsonb_each(c.content->'actions') a)) AS content
    FROM candidates c
), revised AS (
    INSERT INTO lorkhan_internal.configuration_revisions (configuration_id, revision, content, change_reason)
    SELECT configuration_id, current_revision + 1, content, 'Restore prior item action parameters'
    FROM restored
    RETURNING configuration_id, revision
)
UPDATE lorkhan_internal.configuration_sets s SET current_revision = r.revision
FROM revised r WHERE r.configuration_id = s.configuration_id;

DROP TABLE item_action_projection_snapshot;
