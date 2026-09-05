ALTER TABLE lorkhan_internal.action_intents
    ADD COLUMN policy_configuration_id uuid REFERENCES lorkhan_internal.configuration_sets(configuration_id),
    ADD COLUMN policy_revision integer CHECK (policy_revision IS NULL OR policy_revision > 0),
    ADD COLUMN display_name text CHECK (display_name IS NULL OR (length(display_name) BETWEEN 1 AND 128)),
    ADD COLUMN confirmation_required boolean NOT NULL DEFAULT false,
    ADD COLUMN followup_enabled boolean NOT NULL DEFAULT false,
    ADD CONSTRAINT action_intents_policy_revision_pair CHECK (
        (policy_configuration_id IS NULL AND policy_revision IS NULL)
        OR (policy_configuration_id IS NOT NULL AND policy_revision IS NOT NULL)
    );

UPDATE lorkhan_internal.action_catalog
SET continuation_capable=true
WHERE enabled AND terminal_result_required;
