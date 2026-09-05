ALTER TABLE lorkhan_internal.oghma_installation_settings
    DROP CONSTRAINT IF EXISTS oghma_topic_count_range,
    DROP COLUMN IF EXISTS extractor_enabled,
    DROP COLUMN IF EXISTS topic_count,
    DROP COLUMN IF EXISTS location_context_enabled,
    DROP COLUMN IF EXISTS racial_context_enabled;
