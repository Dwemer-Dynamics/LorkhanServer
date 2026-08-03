DROP TABLE IF EXISTS installation_provider_selections;

ALTER TABLE configuration_sets
    DROP CONSTRAINT IF EXISTS configuration_sets_kind_check;

ALTER TABLE configuration_sets
    ADD CONSTRAINT configuration_sets_kind_check
    CHECK (kind IN ('prompt', 'provider', 'action_policy'));
