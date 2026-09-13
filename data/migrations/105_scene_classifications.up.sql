CREATE TABLE lorkhan_internal.scene_classifications (
    job_id uuid PRIMARY KEY REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    playthrough_id uuid NOT NULL REFERENCES lorkhan_internal.playthroughs(playthrough_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE,
    turn_id uuid NOT NULL UNIQUE REFERENCES lorkhan_internal.turns(turn_id) ON DELETE CASCADE,
    history jsonb NOT NULL CHECK (jsonb_typeof(history)='array' AND jsonb_array_length(history) BETWEEN 1 AND 10 AND octet_length(history::text)<=32768),
    genre text CHECK (genre IN ('default','horror','action','thriller','mystery','romance','comedy','drama','nsfw')),
    observed_at timestamptz NOT NULL,
    classified_at timestamptz
);
CREATE INDEX scene_classifications_scope ON lorkhan_internal.scene_classifications(installation_id,playthrough_id,profile_id,observed_at DESC);
