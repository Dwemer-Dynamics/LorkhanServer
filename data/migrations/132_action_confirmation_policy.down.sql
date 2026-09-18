-- Restores the pre132 catalogue contract; saved policy overrides remain untouched.
UPDATE lorkhan_internal.action_catalog
SET confirmation_mode = 'required'
WHERE confirmation_default;
UPDATE lorkhan_internal.action_catalog
SET description = replace(description, 'Explicit Cheat/Narrator request required.', 'Explicit Cheat/Narrator request and confirmation required.')
WHERE confirmation_default;
ALTER TABLE lorkhan_internal.action_catalog DROP COLUMN confirmation_default;
