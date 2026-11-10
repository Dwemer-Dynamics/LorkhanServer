-- Follow is available to ordinary NPCs and actors already following the player.
UPDATE public.core_action SET available_to_followers = true WHERE code_name = 'ai.follow';
UPDATE public.core_action_custom SET available_to_followers = true WHERE code_name = 'ai.follow';

-- Preserve policy history and all other overrides when upgrading complete action rows.
WITH revised AS (
    INSERT INTO lorkhan_internal.configuration_revisions (configuration_id, revision, content, change_reason)
    SELECT s.configuration_id, s.current_revision + 1,
        jsonb_set(r.content, '{actions,ai.follow,available_to_followers}', 'true'::jsonb),
        'Enable Follow for existing followers'
    FROM lorkhan_internal.configuration_sets s
    JOIN lorkhan_internal.configuration_revisions r
        ON r.configuration_id = s.configuration_id AND r.revision = s.current_revision
    WHERE s.kind = 'action_policy' AND s.deleted_at IS NULL
        AND r.content #> '{actions,ai.follow,available_to_followers}' = 'false'::jsonb
    RETURNING configuration_id, revision
)
UPDATE lorkhan_internal.configuration_sets s SET current_revision = r.revision
FROM revised r WHERE r.configuration_id = s.configuration_id;
