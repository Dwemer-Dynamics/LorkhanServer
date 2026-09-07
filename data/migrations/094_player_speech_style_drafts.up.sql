CREATE TABLE lorkhan_internal.player_speech_style_drafts (
    job_id uuid PRIMARY KEY REFERENCES lorkhan_internal.durable_jobs(job_id) ON DELETE CASCADE,
    profile_id uuid NOT NULL REFERENCES lorkhan_internal.profiles(profile_id) ON DELETE CASCADE,
    base_revision integer NOT NULL CHECK (base_revision > 0),
    speech_style text CHECK (octet_length(speech_style) BETWEEN 1 AND 8192),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX player_speech_style_drafts_profile ON lorkhan_internal.player_speech_style_drafts(profile_id);
