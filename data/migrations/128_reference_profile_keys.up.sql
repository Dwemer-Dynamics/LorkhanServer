-- NPC keys are scoped placed references. Existing persona IDs remain valid text keys.
-- Keep all current rows; clearing prototype gameplay is an explicit deployment operation.
CREATE TEMP TABLE reference_profile_columns ON COMMIT DROP AS
SELECT c.oid AS relation,a.attnum,a.attname,n.nspname,c.relname
FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='lorkhan_internal' AND c.relkind IN ('r','p') AND a.atttypid='uuid'::regtype
  AND a.attname IN ('profile_id','selected_profile_id','source_profile_id','owner_profile_id')
  AND a.attnum>0 AND NOT a.attisdropped;

CREATE TEMP TABLE reference_profile_foreign_keys ON COMMIT DROP AS
SELECT con.conrelid AS relation,con.conname,pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint con WHERE con.contype='f' AND EXISTS (
    SELECT 1 FROM reference_profile_columns col
    WHERE (con.conrelid=col.relation AND col.attnum=ANY(con.conkey))
       OR (con.confrelid=col.relation AND col.attnum=ANY(con.confkey))
);

CREATE TEMP TABLE reference_profile_functions ON COMMIT DROP AS
SELECT p.oid::regprocedure AS signature,pg_get_functiondef(p.oid) AS definition
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='lorkhan_internal' AND p.proname IN
    ('sync_profile_projection','remove_npc_projection','refresh_npc_relationships')
  AND p.proargtypes='2950'::oidvector;

DROP INDEX lorkhan_internal.knowledge_custom_topic_uq;
DROP INDEX lorkhan_internal.profiles_installation_id_name_active_key;

DO $migration$
DECLARE item record;
BEGIN
    IF (SELECT count(*) FROM reference_profile_columns)<>27 THEN RAISE EXCEPTION 'unexpected_profile_column_inventory'; END IF;
    IF (SELECT count(*) FROM reference_profile_functions)<>3 THEN RAISE EXCEPTION 'unexpected_profile_function_inventory'; END IF;
    FOR item IN SELECT * FROM reference_profile_foreign_keys LOOP
        EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I',item.relation::regclass,item.conname);
    END LOOP;
    FOR item IN SELECT * FROM reference_profile_columns LOOP
        EXECUTE format('ALTER TABLE %I.%I ALTER COLUMN %I TYPE text USING %I::text',item.nspname,item.relname,item.attname,item.attname);
    END LOOP;
    FOR item IN SELECT * FROM reference_profile_foreign_keys LOOP
        EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s',item.relation::regclass,item.conname,item.definition);
    END LOOP;
    FOR item IN SELECT * FROM reference_profile_functions LOOP
        EXECUTE replace(item.definition,'(source_profile uuid)','(source_profile text)');
        EXECUTE format('DROP FUNCTION %s',item.signature);
    END LOOP;
END
$migration$;

CREATE UNIQUE INDEX knowledge_custom_topic_uq ON lorkhan_internal.knowledge_documents (
    installation_id,
    COALESCE(profile_id,'00000000-0000-0000-0000-000000000000'::text),
    COALESCE(playthrough_id,'00000000-0000-0000-0000-000000000000'::uuid),
    lower(topic)
) WHERE deleted_at IS NULL AND provenance->>'source' IS DISTINCT FROM 'factory-oghma';
