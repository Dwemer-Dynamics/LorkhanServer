-- Align Use Item and Equip Item with the lorkhan.action-intent.v1 wire shape, which carries
-- record_id (and slot for equip) but never content_file.
CREATE TEMPORARY TABLE item_action_projection_snapshot AS
SELECT id, parameters_json #- '{properties,content_file}' AS parameters_json, description, is_activated, metadata
FROM public.core_action_custom WHERE code_name IN ('item.use', 'item.equip');

UPDATE lorkhan_internal.action_catalog SET
    parameter_schema = jsonb_set(parameter_schema #- '{properties,content_file}', '{required}',
        CASE WHEN action_name = 'item.equip' THEN '["record_id", "slot"]'::jsonb ELSE '["record_id"]'::jsonb END)
WHERE action_name IN ('item.use', 'item.equip')
    AND parameter_schema #> '{properties,content_file}' IS NOT NULL;

-- The catalog projection trigger rewrites the whole projected row; restore unrelated custom
-- settings and apply only the parameter change to the public Herika-compatible row.
UPDATE public.core_action_custom c SET
    parameters_json = jsonb_set(s.parameters_json, '{required}', COALESCE((
        SELECT jsonb_agg(value ORDER BY ordinality) FROM jsonb_array_elements(s.parameters_json->'required') WITH ORDINALITY
        WHERE value <> '"content_file"'::jsonb), '[]'::jsonb)),
    description = s.description, is_activated = s.is_activated, metadata = s.metadata
FROM item_action_projection_snapshot s
WHERE c.id = s.id AND jsonb_typeof(s.parameters_json->'required') = 'array';

-- Complete saved action rows copy the catalog parameters; revise only those copies and keep
-- every other override and the policy history intact.
WITH candidates AS (
    SELECT s.configuration_id, s.current_revision, r.content
    FROM lorkhan_internal.configuration_sets s
    JOIN lorkhan_internal.configuration_revisions r
        ON r.configuration_id = s.configuration_id AND r.revision = s.current_revision
    WHERE s.kind = 'action_policy' AND s.deleted_at IS NULL
        AND (r.content #> '{actions,item.use,parameters_json,properties,content_file}' IS NOT NULL
            OR r.content #> '{actions,item.equip,parameters_json,properties,content_file}' IS NOT NULL)
), stripped AS (
    SELECT c.configuration_id, c.current_revision,
        jsonb_set(c.content, '{actions}', (
            SELECT jsonb_object_agg(a.key, CASE
                WHEN a.key IN ('item.use', 'item.equip') AND a.value #> '{parameters_json,properties,content_file}' IS NOT NULL
                    AND jsonb_typeof(a.value #> '{parameters_json,required}') = 'array'
                THEN jsonb_set(a.value #- '{parameters_json,properties,content_file}', '{parameters_json,required}', COALESCE((
                    SELECT jsonb_agg(value ORDER BY ordinality) FROM jsonb_array_elements(a.value #> '{parameters_json,required}') WITH ORDINALITY
                    WHERE value <> '"content_file"'::jsonb), '[]'::jsonb))
                WHEN a.key IN ('item.use', 'item.equip') THEN a.value #- '{parameters_json,properties,content_file}'
                ELSE a.value END)
            FROM jsonb_each(c.content->'actions') a)) AS content
    FROM candidates c
), revised AS (
    INSERT INTO lorkhan_internal.configuration_revisions (configuration_id, revision, content, change_reason)
    SELECT configuration_id, current_revision + 1, content, 'Align item action parameters with client protocol'
    FROM stripped
    RETURNING configuration_id, revision
)
UPDATE lorkhan_internal.configuration_sets s SET current_revision = r.revision
FROM revised r WHERE r.configuration_id = s.configuration_id;

DROP TABLE item_action_projection_snapshot;
