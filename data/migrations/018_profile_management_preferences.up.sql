CREATE TABLE installation_profile_preferences (
    installation_id uuid PRIMARY KEY REFERENCES installations(installation_id) ON DELETE CASCADE,
    auto_lock_on_edit boolean NOT NULL DEFAULT true,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
