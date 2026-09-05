DROP INDEX IF EXISTS lorkhan_internal.configuration_sets_one_profile_action_policy;
DROP INDEX IF EXISTS lorkhan_internal.configuration_sets_one_installation_action_policy;

ALTER TABLE lorkhan_internal.action_intents
    DROP COLUMN IF EXISTS cooldown_seconds,
    DROP COLUMN IF EXISTS followup_depth,
    DROP COLUMN IF EXISTS followup_actions_allowed;

ALTER TABLE lorkhan_internal.action_catalog
    DROP COLUMN IF EXISTS cooldown_seconds,
    DROP COLUMN IF EXISTS followup_actions_supported,
    DROP COLUMN IF EXISTS followup_default,
    DROP COLUMN IF EXISTS confirmation_mode,
    DROP COLUMN IF EXISTS sort_order,
    DROP COLUMN IF EXISTS category,
    DROP COLUMN IF EXISTS display_name;
