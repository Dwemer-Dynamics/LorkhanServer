DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM almsivi_internal.relationship_records WHERE custom_info <> '') THEN
        RAISE EXCEPTION 'Cannot remove player-authored relationship Custom Info.';
    END IF;
END $$;
ALTER TABLE almsivi_internal.relationship_records DROP COLUMN custom_info;
