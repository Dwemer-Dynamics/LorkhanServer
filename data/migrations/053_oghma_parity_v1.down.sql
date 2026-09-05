ALTER TABLE lorkhan_internal.oghma_installation_settings
    DROP CONSTRAINT IF EXISTS oghma_extractor_timeout_range,
    DROP CONSTRAINT IF EXISTS oghma_result_limit_range,
    DROP COLUMN IF EXISTS extractor_timeout_ms,
    DROP COLUMN IF EXISTS result_limit,
    DROP COLUMN IF EXISTS enabled;
