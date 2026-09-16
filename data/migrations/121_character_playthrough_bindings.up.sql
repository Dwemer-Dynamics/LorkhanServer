-- Stable save identity is separate from session generations and database backups.
-- No legacy character or playthrough is assigned automatically.
CREATE TABLE lorkhan_internal.character_playthrough_bindings (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations ON DELETE CASCADE,
    character_id uuid NOT NULL,
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs ON DELETE RESTRICT,
    binding_mode text NOT NULL CHECK (binding_mode IN ('new','existing')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id,character_id),
    UNIQUE (installation_id,playthrough_id)
);
ALTER TABLE lorkhan_internal.sessions ADD COLUMN character_id uuid;
ALTER TABLE lorkhan_internal.sessions ADD CONSTRAINT sessions_character_binding_fk
    FOREIGN KEY (installation_id,character_id)
    REFERENCES lorkhan_internal.character_playthrough_bindings(installation_id,character_id);
