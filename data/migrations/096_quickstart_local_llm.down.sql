DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.quickstart_local_llm) THEN
        RAISE EXCEPTION 'Cannot remove Local LLM setup while managed connector assignments exist';
    END IF;
END $$;
DROP TABLE lorkhan_internal.quickstart_local_llm;
