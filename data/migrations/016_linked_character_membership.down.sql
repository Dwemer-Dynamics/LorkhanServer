-- Earlier code resolves only canonical/alias references and name rules; dormant member profiles and history rows remain.
DROP TABLE IF EXISTS lorkhan_internal.npc_reference_group_optouts;
DROP TABLE IF EXISTS lorkhan_internal.npc_reference_group_members;
ALTER TABLE lorkhan_internal.npc_reference_groups DROP COLUMN IF EXISTS revision;
DROP SEQUENCE IF EXISTS lorkhan_internal.npc_reference_group_revision_seq;
