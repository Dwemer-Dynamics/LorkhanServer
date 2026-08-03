CREATE TABLE core_profiles (
    core_profile_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES installations(installation_id) ON DELETE CASCADE,
    label text NOT NULL CHECK (octet_length(label) BETWEEN 1 AND 256),
    default_npc boolean NOT NULL DEFAULT false,
    slot smallint CHECK (slot IS NULL OR slot BETWEEN 1 AND 4),
    current_revision integer NOT NULL DEFAULT 1 CHECK (current_revision > 0),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    deleted_at timestamptz,
    UNIQUE (core_profile_id, installation_id)
);

CREATE UNIQUE INDEX core_profiles_live_label_unique
    ON core_profiles (installation_id, lower(label))
    WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX core_profiles_live_slot_unique
    ON core_profiles (installation_id, slot)
    WHERE deleted_at IS NULL AND slot IS NOT NULL;
CREATE UNIQUE INDEX core_profiles_one_default_npc
    ON core_profiles (installation_id)
    WHERE deleted_at IS NULL AND default_npc;

CREATE TABLE core_profile_revisions (
    core_profile_id uuid NOT NULL REFERENCES core_profiles(core_profile_id) ON DELETE CASCADE,
    revision integer NOT NULL CHECK (revision > 0),
    content jsonb NOT NULL CHECK (jsonb_typeof(content) = 'object'),
    change_reason text NOT NULL CHECK (octet_length(change_reason) BETWEEN 1 AND 512),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (core_profile_id, revision)
);

INSERT INTO core_profiles (
    core_profile_id, installation_id, label, default_npc, slot, current_revision, created_at
)
SELECT md5('almsivi:core-profile:default:v1:' || installation_id::text)::uuid,
    installation_id, 'Default', true, 1, 1, created_at
FROM installations;

INSERT INTO core_profile_revisions (core_profile_id, revision, content, change_reason, created_at)
SELECT md5('almsivi:core-profile:default:v1:' || installation_id::text)::uuid,
    1,
    '{"schema":"almsivi.core-profile.v1","prompt":"","routing":{},"settings_overrides":{}}'::jsonb,
    '022 default core profile backfill',
    created_at
FROM installations;

ALTER TABLE profiles ADD COLUMN core_profile_id uuid;
UPDATE profiles p
SET core_profile_id = c.core_profile_id
FROM core_profiles c
WHERE c.installation_id = p.installation_id AND c.default_npc AND c.deleted_at IS NULL;
ALTER TABLE profiles ADD CONSTRAINT profiles_core_profile_installation_fk
    FOREIGN KEY (core_profile_id, installation_id)
    REFERENCES core_profiles(core_profile_id, installation_id);
CREATE INDEX profiles_core_profile
    ON profiles (installation_id, core_profile_id)
    WHERE deleted_at IS NULL;

ALTER TABLE prompt_traces
    ADD COLUMN core_profile_id uuid,
    ADD COLUMN core_profile_revision integer CHECK (core_profile_revision IS NULL OR core_profile_revision > 0),
    ADD COLUMN effective_settings_sha256 char(64)
        CHECK (effective_settings_sha256 IS NULL OR effective_settings_sha256 ~ '^[0-9a-f]{64}$'),
    ADD COLUMN settings_sources jsonb
        CHECK (settings_sources IS NULL OR jsonb_typeof(settings_sources) = 'object');
ALTER TABLE prompt_traces ADD CONSTRAINT prompt_traces_core_profile_fk
    FOREIGN KEY (core_profile_id, installation_id)
    REFERENCES core_profiles(core_profile_id, installation_id);

ALTER TABLE prompt_trace_sources DROP CONSTRAINT prompt_trace_sources_source_kind_check;
ALTER TABLE prompt_trace_sources ADD CONSTRAINT prompt_trace_sources_source_kind_check
    CHECK (source_kind IN (
        'profile','core_profile','prompt','turn','memory','relationship','knowledge','narrative','action_result','action_catalog'
    ));
