CREATE TABLE lorkhan_internal.profile_evolution_clocks (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs ON DELETE CASCADE,
    epoch uuid NOT NULL,
    game_minute bigint NOT NULL,
    started_minute bigint NOT NULL,
    started_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (installation_id,playthrough_id)
);
CREATE TABLE lorkhan_internal.profile_evolution_progress (
    profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs ON DELETE CASCADE,
    epoch uuid NOT NULL,
    last_game_minute bigint NOT NULL,
    consumed_events bigint NOT NULL DEFAULT 0,
    attempted_at timestamptz,
    checked_at timestamptz NOT NULL DEFAULT '-infinity',
    manual_requested boolean NOT NULL DEFAULT false,
    PRIMARY KEY (profile_id,playthrough_id)
);
-- No eventlog foreign key: history retention must not erase already counted progress.
CREATE TABLE lorkhan_internal.profile_evolution_events (
    profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs ON DELETE CASCADE,
    epoch uuid NOT NULL,
    rowid bigint NOT NULL,
    PRIMARY KEY (profile_id,playthrough_id,epoch,rowid)
);
