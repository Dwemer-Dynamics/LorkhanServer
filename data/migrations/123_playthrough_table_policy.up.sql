-- Labels are generated from the reviewed source policy, never used as an export allowlist.
CREATE FUNCTION lorkhan_internal.sync_playthrough_table_policy(policy jsonb)
RETURNS void LANGUAGE plpgsql AS $function$
DECLARE item record;
DECLARE entry jsonb;
DECLARE preserved text;
DECLARE label text;
BEGIN
    FOR item IN SELECT n.nspname,c.relname,obj_description(c.oid,'pg_class') AS comment
        FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname IN ('public','lorkhan_internal') AND c.relkind IN ('r','p')
    LOOP
        entry:=policy->(item.nspname||'.'||item.relname);
        preserved:=regexp_replace(COALESCE(item.comment,''),'(^|\n)LORKHAN Playthrough Policy: [^\n]*','','g');
        IF COALESCE((entry->>'portable')::boolean,false) THEN
            label:='Playthrough Manager Backed Up (selected playthrough rows only)';
        ELSE
            label:='Not in portable backup ('||COALESCE(entry->>'category','unclassified')||')';
        END IF;
        label:=CASE WHEN preserved='' THEN '' ELSE preserved||E'\n' END||'LORKHAN Playthrough Policy: '||label;
        IF item.comment IS DISTINCT FROM label THEN
            EXECUTE format('COMMENT ON TABLE %I.%I IS %L',item.nspname,item.relname,label);
        END IF;
    END LOOP;
END;
$function$;
