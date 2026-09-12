CREATE TABLE lorkhan_internal.database_backup_settings (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    enabled boolean NOT NULL DEFAULT false,
    max_count integer NOT NULL DEFAULT 5 CHECK (max_count BETWEEN 1 AND 10),
    last_queued_at timestamptz
);
INSERT INTO lorkhan_internal.database_backup_settings (singleton) VALUES (true);
