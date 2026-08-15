-- Removed NPC marker tokens are intentionally not recreated during rollback.
ALTER TABLE almsivi_internal.oghma_installation_settings
    ALTER COLUMN knowledge_tags SET DEFAULT 'common';
