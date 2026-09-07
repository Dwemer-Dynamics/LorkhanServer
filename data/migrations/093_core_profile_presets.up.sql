CREATE TABLE lorkhan_internal.core_profile_presets (
    preset_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id),
    name text NOT NULL CHECK (octet_length(name) BETWEEN 1 AND 128),
    revision integer NOT NULL DEFAULT 1 CHECK (revision > 0),
    payload jsonb NOT NULL CHECK (jsonb_typeof(payload) = 'object' AND octet_length(payload::text) <= 262144),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE UNIQUE INDEX core_profile_presets_name ON lorkhan_internal.core_profile_presets (installation_id, lower(name));
