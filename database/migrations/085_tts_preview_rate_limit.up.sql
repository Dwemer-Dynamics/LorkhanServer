ALTER TABLE lorkhan_internal.browser_sessions
    ADD COLUMN tts_preview_window_started_at timestamptz,
    ADD COLUMN tts_preview_count integer NOT NULL DEFAULT 0 CHECK (tts_preview_count >= 0);
