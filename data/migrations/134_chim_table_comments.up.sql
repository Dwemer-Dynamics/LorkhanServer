-- Match CHIM's pgAdmin labels. The source policy, never the comment, controls capture.
CREATE OR REPLACE FUNCTION lorkhan_internal.sync_playthrough_table_policy(policy jsonb)
RETURNS void LANGUAGE plpgsql SET lock_timeout = '10s' AS $function$
DECLARE item record;
DECLARE expected text;
BEGIN
    FOR item IN SELECT n.nspname,c.relname,obj_description(c.oid,'pg_class') AS comment
        FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p')
    LOOP
        expected:=CASE WHEN COALESCE((policy->(item.nspname||'.'||item.relname)->>'portable')::boolean,false)
            THEN 'Playthrough Manager Backed Up' ELSE NULL END;
        IF item.comment IS DISTINCT FROM expected THEN
            EXECUTE format('COMMENT ON TABLE %I.%I IS %L',item.nspname,item.relname,expected);
        END IF;
    END LOOP;
END;
$function$;
