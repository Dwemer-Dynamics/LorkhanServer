UPDATE lorkhan_internal.profile_evolution_progress SET manual_requested=false;
ALTER TABLE lorkhan_internal.profile_evolution_progress
    DROP COLUMN manual_request_id, DROP COLUMN manual_requested_at, DROP COLUMN manual_attempts;
