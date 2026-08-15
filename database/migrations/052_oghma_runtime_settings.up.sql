ALTER TABLE almsivi_internal.oghma_installation_settings
    ADD COLUMN racial_context_enabled boolean NOT NULL DEFAULT true,
    ADD COLUMN location_context_enabled boolean NOT NULL DEFAULT true,
    ADD COLUMN topic_count smallint NOT NULL DEFAULT 1,
    ADD COLUMN extractor_enabled boolean NOT NULL DEFAULT false,
    ADD CONSTRAINT oghma_topic_count_range CHECK (topic_count BETWEEN 1 AND 3);
