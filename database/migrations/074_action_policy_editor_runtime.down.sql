UPDATE lorkhan_internal.action_catalog
SET continuation_capable=false
WHERE enabled AND terminal_result_required;

ALTER TABLE lorkhan_internal.action_intents
    DROP CONSTRAINT IF EXISTS action_intents_policy_revision_pair,
    DROP COLUMN IF EXISTS followup_enabled,
    DROP COLUMN IF EXISTS confirmation_required,
    DROP COLUMN IF EXISTS display_name,
    DROP COLUMN IF EXISTS policy_revision,
    DROP COLUMN IF EXISTS policy_configuration_id;
