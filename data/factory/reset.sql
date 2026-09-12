-- Remove application relations/routines, retaining schemas and extension-owned objects.
-- The worker has captured control state in temporary tables and owns the surrounding transaction.
DO $factory$
DECLARE entry record;
BEGIN
    -- Identity sequences must be removed with their owning table before any remaining sequences.
    FOR entry IN SELECT n.nspname,c.relname,c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p','v','m','S')
        AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_class'::regclass AND d.objid=c.oid AND d.deptype='e')
        ORDER BY CASE c.relkind WHEN 'S' THEN 1 ELSE 0 END, n.nspname,c.relname
    LOOP
        EXECUTE format('DROP %s IF EXISTS %I.%I CASCADE',CASE entry.relkind WHEN 'v' THEN 'VIEW' WHEN 'm' THEN 'MATERIALIZED VIEW' WHEN 'S' THEN 'SEQUENCE' ELSE 'TABLE' END,entry.nspname,entry.relname);
    END LOOP;
    FOR entry IN SELECT p.oid::regprocedure AS name,p.prokind FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname IN ('public','lorkhan_internal') AND p.prokind IN ('f','p')
        AND NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='pg_proc'::regclass AND d.objid=p.oid AND d.deptype='e')
    LOOP
        EXECUTE format('DROP %s IF EXISTS %s CASCADE',CASE entry.prokind WHEN 'p' THEN 'PROCEDURE' ELSE 'FUNCTION' END,entry.name);
    END LOOP;
END
$factory$;
