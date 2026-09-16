DO $migration$
DECLARE item record;
DECLARE preserved text;
BEGIN
    FOR item IN SELECT n.nspname,c.relname,obj_description(c.oid,'pg_class') AS comment
        FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p')
    LOOP
        preserved:=regexp_replace(COALESCE(item.comment,''),'(^|\n)LORKHAN Playthrough Policy: [^\n]*','','g');
        IF item.comment IS DISTINCT FROM NULLIF(preserved,'') THEN
            EXECUTE format('COMMENT ON TABLE %I.%I IS %L',item.nspname,item.relname,NULLIF(preserved,''));
        END IF;
    END LOOP;
END;
$migration$;
DROP FUNCTION lorkhan_internal.sync_playthrough_table_policy(jsonb);
