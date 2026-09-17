CREATE TABLE lorkhan_internal.playthrough_associations (
    association_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL,
    character_id uuid NOT NULL,
    from_playthrough_id uuid NOT NULL,
    to_playthrough_id uuid NOT NULL,
    state text NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','applied','cancelled')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    completed_at timestamptz,
    CHECK(from_playthrough_id<>to_playthrough_id),
    FOREIGN KEY(installation_id,character_id) REFERENCES lorkhan_internal.character_playthrough_bindings(installation_id,character_id),
    FOREIGN KEY(from_playthrough_id,installation_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id,installation_id),
    FOREIGN KEY(to_playthrough_id,installation_id) REFERENCES lorkhan_internal.playthroughs(playthrough_id,installation_id)
);
CREATE UNIQUE INDEX playthrough_associations_pending_character ON lorkhan_internal.playthrough_associations(installation_id,character_id) WHERE state='pending';
CREATE UNIQUE INDEX playthrough_associations_pending_target ON lorkhan_internal.playthrough_associations(installation_id,to_playthrough_id) WHERE state='pending';
