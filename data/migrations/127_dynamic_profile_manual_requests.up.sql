ALTER TABLE lorkhan_internal.profile_evolution_progress
    ADD COLUMN manual_request_id uuid,
    ADD COLUMN manual_requested_at timestamptz,
    ADD COLUMN manual_attempts integer NOT NULL DEFAULT 0;
