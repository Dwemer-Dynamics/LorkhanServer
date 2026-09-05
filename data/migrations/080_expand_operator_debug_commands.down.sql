DELETE FROM lorkhan_internal.debug_commands
WHERE command_name NOT IN (
    'status.snapshot','god_mode.set','collision.set','ai.set','mwscript.set',
    'render_mode.toggle','shaders.reload','shader_hot_reload.set'
);

ALTER TABLE lorkhan_internal.debug_commands
    DROP CONSTRAINT debug_commands_command_name_check;

ALTER TABLE lorkhan_internal.debug_commands
    ADD CONSTRAINT debug_commands_command_name_check CHECK (command_name IN (
        'status.snapshot','god_mode.set','collision.set','ai.set','mwscript.set',
        'render_mode.toggle','shaders.reload','shader_hot_reload.set'
    ));
