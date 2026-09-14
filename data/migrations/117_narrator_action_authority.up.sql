ALTER TABLE lorkhan_internal.action_catalog ADD COLUMN available_to_narrator boolean NOT NULL DEFAULT false;
UPDATE lorkhan_internal.action_catalog SET available_to_narrator=true WHERE action_name IN (
'inspect.report','inventory.inspect','ai.follow','ai.stop','ai.approach','ai.wait','ai.travel','ai.escort','ai.face','ai.wander',
'combat.start','combat.stop','weapon.sheathe','spell.cast','animation.play','item.equip','item.unequip','item.use',
'item.give','item.take','item.pickup','gold.give','gold.take',
'service.barter','service.training','service.spells','service.travel','service.spellmaking','service.enchanting','service.repair');
