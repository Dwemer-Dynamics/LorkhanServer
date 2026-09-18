-- Reference-key profiles cannot be converted back to UUIDs without losing their identity.
-- Rollback is safe only before any reference profiles have been created.
DO $guard$
BEGIN
    IF EXISTS(SELECT 1 FROM lorkhan_internal.profiles WHERE profile_id !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') THEN
        RAISE EXCEPTION 'reference_profiles_require_backup_restore';
    END IF;
    IF EXISTS(SELECT 1 FROM lorkhan_internal.profiles WHERE deleted_at IS NULL GROUP BY installation_id,playthrough_id,name HAVING count(*)>1) THEN
        RAISE EXCEPTION 'duplicate_profile_names_require_backup_restore';
    END IF;
END
$guard$;

CREATE TEMP TABLE reference_profile_columns ON COMMIT DROP AS
SELECT c.oid AS relation,a.attnum,a.attname,n.nspname,c.relname
FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname='lorkhan_internal' AND c.relkind IN ('r','p') AND a.atttypid='text'::regtype
  AND a.attname IN ('profile_id','selected_profile_id','source_profile_id','owner_profile_id')
  AND a.attnum>0 AND NOT a.attisdropped;
CREATE TEMP TABLE reference_profile_foreign_keys ON COMMIT DROP AS
SELECT con.conrelid AS relation,con.conname,pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint con WHERE con.contype='f' AND EXISTS (
    SELECT 1 FROM reference_profile_columns col
    WHERE (con.conrelid=col.relation AND col.attnum=ANY(con.conkey)) OR (con.confrelid=col.relation AND col.attnum=ANY(con.confkey))
);
CREATE TEMP TABLE reference_profile_functions ON COMMIT DROP AS
SELECT p.oid::regprocedure AS signature,pg_get_functiondef(p.oid) AS definition
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='lorkhan_internal' AND p.proname IN ('sync_profile_projection','remove_npc_projection','refresh_npc_relationships')
  AND p.proargtypes='25'::oidvector;
DROP INDEX lorkhan_internal.knowledge_custom_topic_uq;
DO $migration$
DECLARE item record;
BEGIN
    FOR item IN SELECT * FROM reference_profile_foreign_keys LOOP
        EXECUTE format('ALTER TABLE %s DROP CONSTRAINT %I',item.relation::regclass,item.conname);
    END LOOP;
    FOR item IN SELECT * FROM reference_profile_columns LOOP
        EXECUTE format('ALTER TABLE %I.%I ALTER COLUMN %I TYPE uuid USING %I::uuid',item.nspname,item.relname,item.attname,item.attname);
    END LOOP;
    FOR item IN SELECT * FROM reference_profile_foreign_keys LOOP
        EXECUTE format('ALTER TABLE %s ADD CONSTRAINT %I %s',item.relation::regclass,item.conname,item.definition);
    END LOOP;
    FOR item IN SELECT * FROM reference_profile_functions LOOP
        EXECUTE replace(item.definition,'(source_profile text)','(source_profile uuid)');
        EXECUTE format('DROP FUNCTION %s',item.signature);
    END LOOP;
END
$migration$;
CREATE UNIQUE INDEX profiles_installation_id_name_active_key ON lorkhan_internal.profiles
    (installation_id,playthrough_id,name) NULLS NOT DISTINCT WHERE deleted_at IS NULL;
CREATE UNIQUE INDEX knowledge_custom_topic_uq ON lorkhan_internal.knowledge_documents (
    installation_id,COALESCE(profile_id,'00000000-0000-0000-0000-000000000000'::uuid),
    COALESCE(playthrough_id,'00000000-0000-0000-0000-000000000000'::uuid),lower(topic)
) WHERE deleted_at IS NULL AND provenance->>'source' IS DISTINCT FROM 'factory-oghma';
