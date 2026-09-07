DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM lorkhan_internal.player_speech_style_drafts) THEN
        RAISE EXCEPTION 'Review and remove player speech-style drafts before reverting migration 094';
    END IF;
END $$;
DROP TABLE lorkhan_internal.player_speech_style_drafts;
