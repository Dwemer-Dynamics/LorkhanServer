-- Player-authored text stays outside model projections and relationship audit snapshots.
ALTER TABLE almsivi_internal.relationship_records
    ADD COLUMN custom_info text NOT NULL DEFAULT '' CHECK (char_length(custom_info) <= 2000);
