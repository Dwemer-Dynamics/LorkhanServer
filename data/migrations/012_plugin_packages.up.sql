-- Installed third-party .dwpkg packages are owned by one installation. Extracted trees are immutable, content-addressed
-- files outside the web root; these rows choose the active tree and keep the previous good version for diagnosis.
CREATE TABLE IF NOT EXISTS lorkhan_internal.plugin_packages (
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    plugin_id text NOT NULL,
    state text NOT NULL,
    enabled boolean NOT NULL,
    version text NOT NULL,
    archive_sha256 character(64) NOT NULL,
    manifest_sha256 character(64) NOT NULL,
    manifest jsonb NOT NULL,
    mutable_paths jsonb NOT NULL DEFAULT '[]'::jsonb,
    previous_version text,
    previous_archive_sha256 character(64),
    revision integer NOT NULL DEFAULT 1,
    installed_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT plugin_packages_pkey PRIMARY KEY (installation_id, plugin_id),
    CONSTRAINT plugin_packages_plugin_id_check CHECK (plugin_id ~ '^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]{1,47}$'),
    CONSTRAINT plugin_packages_state_check CHECK (state IN ('installed','removed')),
    CONSTRAINT plugin_packages_removed_disabled_check CHECK (state = 'installed' OR NOT enabled),
    CONSTRAINT plugin_packages_sha_check CHECK (archive_sha256 ~ '^[0-9a-f]{64}$' AND manifest_sha256 ~ '^[0-9a-f]{64}$'),
    CONSTRAINT plugin_packages_revision_check CHECK (revision > 0)
);
-- One row per requested lifecycle operation; the partial index serializes work on the same package.
CREATE TABLE IF NOT EXISTS lorkhan_internal.plugin_package_operations (
    operation_id uuid NOT NULL,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    plugin_id text NOT NULL,
    operation text NOT NULL,
    version text NOT NULL,
    archive_sha256 character(64) NOT NULL,
    state text NOT NULL DEFAULT 'queued',
    error_code text,
    created_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(),
    finished_at timestamp with time zone,
    CONSTRAINT plugin_package_operations_pkey PRIMARY KEY (operation_id),
    CONSTRAINT plugin_package_operations_operation_check CHECK (operation IN ('install','update')),
    CONSTRAINT plugin_package_operations_state_check CHECK (state IN ('queued','succeeded','failed')),
    CONSTRAINT plugin_package_operations_error_check CHECK ((state = 'failed') = (error_code IS NOT NULL) AND (error_code IS NULL OR error_code ~ '^[a-z][a-z0-9_]{0,63}$')),
    CONSTRAINT plugin_package_operations_finished_check CHECK ((state = 'queued') = (finished_at IS NULL))
);
CREATE UNIQUE INDEX IF NOT EXISTS plugin_package_operations_pending_idx
    ON lorkhan_internal.plugin_package_operations (installation_id, plugin_id) WHERE state = 'queued';
CREATE INDEX IF NOT EXISTS plugin_package_operations_installation_idx
    ON lorkhan_internal.plugin_package_operations (installation_id, created_at DESC);
