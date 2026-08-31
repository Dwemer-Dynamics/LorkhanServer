ALTER TABLE lorkhan_internal.action_catalog
    ADD COLUMN display_name text,
    ADD COLUMN category text,
    ADD COLUMN sort_order integer NOT NULL DEFAULT 100,
    ADD COLUMN confirmation_mode text NOT NULL DEFAULT 'optional'
        CHECK (confirmation_mode IN ('none', 'optional', 'required')),
    ADD COLUMN followup_default boolean NOT NULL DEFAULT false,
    ADD COLUMN followup_actions_supported boolean NOT NULL DEFAULT false,
    ADD COLUMN cooldown_seconds integer NOT NULL DEFAULT 0 CHECK (cooldown_seconds BETWEEN 0 AND 86400);

UPDATE lorkhan_internal.action_catalog
SET display_name = CASE action_name
        WHEN 'inspect.report' THEN 'Inspect'
        WHEN 'inventory.inspect' THEN 'Check Inventory'
        WHEN 'ai.approach' THEN 'Come Closer'
        WHEN 'ai.escort' THEN 'Lead the Way'
        WHEN 'ai.face' THEN 'Face Target'
        WHEN 'ai.follow' THEN 'Follow'
        WHEN 'ai.stop' THEN 'Stop Moving'
        WHEN 'ai.travel' THEN 'Travel To'
        WHEN 'ai.wait' THEN 'Wait Here'
        WHEN 'ai.wander' THEN 'Wander'
        WHEN 'combat.start' THEN 'Attack'
        WHEN 'combat.stop' THEN 'Stop Combat'
        WHEN 'item.use' THEN 'Use Item'
        WHEN 'item.equip' THEN 'Equip Item'
        WHEN 'item.unequip' THEN 'Unequip Item'
        WHEN 'animation.play' THEN 'Perform Gesture'
        ELSE action_name
    END,
    category = CASE
        WHEN action_name IN ('inspect.report', 'inventory.inspect') THEN 'Information'
        WHEN action_name LIKE 'ai.%' THEN 'Movement'
        WHEN action_name LIKE 'combat.%' THEN 'Combat'
        WHEN action_name LIKE 'item.%' THEN 'Items'
        WHEN action_name = 'animation.play' THEN 'Animation'
        ELSE 'Other'
    END,
    sort_order = CASE action_name
        WHEN 'inspect.report' THEN 10 WHEN 'inventory.inspect' THEN 20
        WHEN 'ai.approach' THEN 110 WHEN 'ai.escort' THEN 120 WHEN 'ai.face' THEN 130
        WHEN 'ai.follow' THEN 140 WHEN 'ai.stop' THEN 150 WHEN 'ai.travel' THEN 160
        WHEN 'ai.wait' THEN 170 WHEN 'ai.wander' THEN 180
        WHEN 'combat.start' THEN 210 WHEN 'combat.stop' THEN 220
        WHEN 'item.use' THEN 310 WHEN 'item.equip' THEN 320 WHEN 'item.unequip' THEN 330
        WHEN 'animation.play' THEN 410 ELSE 900
    END,
    confirmation_mode = CASE WHEN tier >= 2 THEN 'required' WHEN tier = 0 THEN 'none' ELSE 'optional' END,
    continuation_capable = action_name IN (
        'inspect.report','inventory.inspect','ai.approach','ai.escort','ai.face','ai.follow','ai.stop','ai.travel',
        'ai.wait','ai.wander','combat.start','combat.stop','item.use','item.equip','item.unequip','animation.play'
    ),
    followup_actions_supported = action_name IN (
        'inspect.report','inventory.inspect','ai.approach','ai.escort','ai.face','ai.follow','ai.stop','ai.travel',
        'ai.wait','ai.wander','combat.start','combat.stop','item.use','item.equip','item.unequip','animation.play'
    );

ALTER TABLE lorkhan_internal.action_catalog
    ALTER COLUMN display_name SET NOT NULL,
    ALTER COLUMN category SET NOT NULL;

ALTER TABLE lorkhan_internal.action_intents
    ADD COLUMN followup_actions_allowed boolean NOT NULL DEFAULT false,
    ADD COLUMN followup_depth smallint NOT NULL DEFAULT 0 CHECK (followup_depth BETWEEN 0 AND 1),
    ADD COLUMN cooldown_seconds integer NOT NULL DEFAULT 0 CHECK (cooldown_seconds BETWEEN 0 AND 86400);

-- Keep the newest legacy policy at each scope. The editor now owns one installation policy
-- plus one optional NPC override instead of selecting a policy by its display name.
WITH ranked AS (
    SELECT configuration_id,
           row_number() OVER (
               PARTITION BY installation_id, profile_id
               ORDER BY current_revision DESC, created_at DESC, configuration_id DESC
           ) AS position
    FROM lorkhan_internal.configuration_sets
    WHERE kind = 'action_policy' AND deleted_at IS NULL
)
UPDATE lorkhan_internal.configuration_sets c
SET deleted_at = clock_timestamp()
FROM ranked r
WHERE c.configuration_id = r.configuration_id AND r.position > 1;

CREATE UNIQUE INDEX configuration_sets_one_installation_action_policy
    ON lorkhan_internal.configuration_sets (installation_id)
    WHERE kind = 'action_policy' AND profile_id IS NULL AND deleted_at IS NULL;

CREATE UNIQUE INDEX configuration_sets_one_profile_action_policy
    ON lorkhan_internal.configuration_sets (installation_id, profile_id)
    WHERE kind = 'action_policy' AND profile_id IS NOT NULL AND deleted_at IS NULL;
