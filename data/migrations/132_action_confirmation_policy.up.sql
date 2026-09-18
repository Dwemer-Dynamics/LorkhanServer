-- Keep existing approval defaults, but let Action Editor overrides control them.
ALTER TABLE lorkhan_internal.action_catalog
    ADD COLUMN confirmation_default boolean NOT NULL DEFAULT false;
UPDATE lorkhan_internal.action_catalog
SET confirmation_default = true, confirmation_mode = 'optional'
WHERE confirmation_mode = 'required';
UPDATE lorkhan_internal.action_catalog
SET description = replace(description, 'Explicit Cheat/Narrator request and confirmation required.', 'Explicit Cheat/Narrator request required.')
WHERE confirmation_default;
