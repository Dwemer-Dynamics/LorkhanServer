ALTER TABLE lorkhan_internal.browser_sessions
    DROP COLUMN IF EXISTS tts_preview_count,
    DROP COLUMN IF EXISTS tts_preview_window_started_at;
