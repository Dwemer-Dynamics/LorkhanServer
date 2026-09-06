DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.relationship_records
        WHERE COALESCE(details->>'relation','')<>'' OR COALESCE(details->>'note','')<>''
           OR COALESCE(details->>'best','')<>'' OR COALESCE(details->>'worst','')<>'') THEN
        RAISE EXCEPTION 'Cannot remove saved relationship details.';
    END IF;
END $$;
ALTER TABLE lorkhan_internal.relationship_records DROP COLUMN details;
