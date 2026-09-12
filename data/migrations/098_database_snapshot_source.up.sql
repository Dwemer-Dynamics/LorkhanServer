-- No foreign key: deleting a stored copy must not change the provenance of the live database.
CREATE TABLE lorkhan_internal.database_snapshot_source (
    singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
    backup_id uuid,
    name text,
    copied_at timestamptz
);
INSERT INTO lorkhan_internal.database_snapshot_source(singleton) VALUES (true);
