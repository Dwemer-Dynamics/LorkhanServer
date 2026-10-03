-- Linked-character membership for NPC Reference Groups. Additive only: existing groups, profiles, bindings and history rows
-- are not rewritten. A group-wide revision sequence changes on every membership edit, so queued jobs can freeze it; a
-- deleted and recreated group never repeats an earlier revision.
CREATE SEQUENCE lorkhan_internal.npc_reference_group_revision_seq;
ALTER TABLE lorkhan_internal.npc_reference_groups
    ADD COLUMN revision bigint DEFAULT nextval('lorkhan_internal.npc_reference_group_revision_seq') NOT NULL;
ALTER SEQUENCE lorkhan_internal.npc_reference_group_revision_seq OWNED BY lorkhan_internal.npc_reference_groups.revision;

-- Observed placed references currently sharing a group's keeper profile. One physical reference has at most one owner.
-- actor_identity is the durable key (kind, record ID, content file, local RefNum index) seen in game, never a guess.
CREATE TABLE lorkhan_internal.npc_reference_group_members (
    installation_id uuid NOT NULL,
    member_ref text NOT NULL,
    group_key text NOT NULL,
    source text NOT NULL,
    actor_identity jsonb NOT NULL,
    observed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT npc_reference_group_members_pkey PRIMARY KEY (installation_id, member_ref),
    CONSTRAINT npc_reference_group_members_group_fkey FOREIGN KEY (installation_id, group_key)
        REFERENCES lorkhan_internal.npc_reference_groups(installation_id, group_key) ON DELETE CASCADE,
    CONSTRAINT npc_reference_group_members_source_check CHECK (source = ANY (ARRAY['canonical'::text, 'alias'::text, 'name_rule'::text])),
    CONSTRAINT npc_reference_group_members_ref_check CHECK (member_ref ~ '^[^|]{1,200}\|(0|[1-9][0-9]{0,9})$'),
    CONSTRAINT npc_reference_group_members_identity_check CHECK (jsonb_typeof(actor_identity) = 'object'::text
        AND octet_length(actor_identity::text) <= 2048)
);
CREATE INDEX npc_reference_group_members_group ON lorkhan_internal.npc_reference_group_members (installation_id, group_key);

-- Durable individual unlinks. They survive rediscovery and name rules until an explicit relink removes only that row.
CREATE TABLE lorkhan_internal.npc_reference_group_optouts (
    installation_id uuid NOT NULL,
    group_key text NOT NULL,
    member_ref text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT npc_reference_group_optouts_pkey PRIMARY KEY (installation_id, group_key, member_ref),
    CONSTRAINT npc_reference_group_optouts_group_fkey FOREIGN KEY (installation_id, group_key)
        REFERENCES lorkhan_internal.npc_reference_groups(installation_id, group_key) ON DELETE CASCADE,
    CONSTRAINT npc_reference_group_optouts_ref_check CHECK (member_ref ~ '^[^|]{1,200}\|(0|[1-9][0-9]{0,9})$')
);
