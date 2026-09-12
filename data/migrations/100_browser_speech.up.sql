ALTER TABLE lorkhan_internal.debug_commands
    DROP CONSTRAINT debug_commands_command_name_check;

ALTER TABLE lorkhan_internal.debug_commands
    ADD CONSTRAINT debug_commands_command_name_check CHECK (command_name IN (
        'status.snapshot','god_mode.set','collision.set','ai.set','mwscript.set',
        'render_mode.toggle','shaders.reload','shader_hot_reload.set',
        'player.inventory.add','player.inventory.remove',
        'player.spell.add','player.spell.remove','player.vitals.restore','player.stat.set',
        'player.attribute.set','player.skill.set','player.level.set','player.bounty.set',
        'player.teleport','player.scale.set','world.time.advance','world.timescale.set',
        'world.weather.set','target.actor.kill','target.actor.restore',
        'target.teleport.to_player','target.scale.set','player.dialogue.submit'
    ));
