-- Assign a Core Profile once, from bounded OpenMW metadata, when an NPC is first discovered.
CREATE TABLE lorkhan_internal.profile_assignment_rules (
    rule_id uuid PRIMARY KEY,
    installation_id uuid NOT NULL REFERENCES lorkhan_internal.installations(installation_id) ON DELETE CASCADE,
    core_profile_id uuid NOT NULL,
    description text NOT NULL CHECK (octet_length(description) BETWEEN 1 AND 200),
    priority integer NOT NULL DEFAULT 0 CHECK (priority BETWEEN -100000 AND 100000),
    enabled boolean NOT NULL DEFAULT true,
    matchers jsonb NOT NULL CHECK (
        jsonb_typeof(matchers)='object' AND octet_length(matchers::text) BETWEEN 2 AND 32768
    ),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY (core_profile_id,installation_id)
        REFERENCES lorkhan_internal.core_profiles(core_profile_id,installation_id)
);

CREATE INDEX profile_assignment_rules_runtime
    ON lorkhan_internal.profile_assignment_rules
    (installation_id,priority DESC,created_at,rule_id)
    WHERE enabled;
