-- Restore the migration-037 function body when explicitly rolling back this fix.
DO $restore_world_projection$
DECLARE
    definition text;
BEGIN
    SELECT pg_get_functiondef('almsivi_internal.project_turn_world(uuid)'::regprocedure)
    INTO definition;

    IF position('DECLARE entry_hash char(64);' IN definition) > 0 THEN
        RETURN;
    END IF;
    IF position('DECLARE computed_entry_hash char(64);' IN definition) = 0
        OR position('computed_entry_hash := encode(sha256(convert_to(item::text,''UTF8'')),''hex'');' IN definition) = 0
        OR position('metadata.entry_hash=computed_entry_hash;' IN definition) = 0
        OR position('source_row.session_id,record_key,source_turn,computed_entry_hash' IN definition) = 0
    THEN
        RAISE EXCEPTION 'Unexpected project_turn_world definition; refusing an unsafe rewrite.';
    END IF;

    definition := replace(definition,
        'DECLARE computed_entry_hash char(64);',
        'DECLARE entry_hash char(64);');
    definition := replace(definition,
        'computed_entry_hash := encode(sha256(convert_to(item::text,''UTF8'')),''hex'');',
        'entry_hash := encode(sha256(convert_to(item::text,''UTF8'')),''hex'');');
    definition := replace(definition,
        'metadata.entry_hash=computed_entry_hash;',
        'metadata.entry_hash=entry_hash;');
    definition := replace(definition,
        'source_row.session_id,record_key,source_turn,computed_entry_hash',
        'source_row.session_id,record_key,source_turn,entry_hash');
    EXECUTE definition;
END
$restore_world_projection$;
