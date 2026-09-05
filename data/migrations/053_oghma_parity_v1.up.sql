ALTER TABLE lorkhan_internal.oghma_installation_settings
    ADD COLUMN enabled boolean NOT NULL DEFAULT true,
    ADD COLUMN result_limit smallint NOT NULL DEFAULT 3,
    ADD COLUMN extractor_timeout_ms integer NOT NULL DEFAULT 1500,
    ADD CONSTRAINT oghma_result_limit_range CHECK (result_limit BETWEEN 1 AND 5),
    ADD CONSTRAINT oghma_extractor_timeout_range CHECK (extractor_timeout_ms BETWEEN 250 AND 3000);
