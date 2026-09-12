-- Temporary copies survive pg_dump's clean/recreate statements within the same transaction.
SET lock_timeout = '3s';
SET statement_timeout = '600s';
CREATE TEMP TABLE restore_schema AS SELECT version,checksum FROM lorkhan_internal.schema_migrations;
CREATE TEMP TABLE restore_installations AS SELECT installation_id,token_fingerprint,revoked_at FROM lorkhan_internal.installations;
CREATE TEMP TABLE restore_pairing AS SELECT * FROM lorkhan_internal.pairing_tokens;
CREATE TEMP TABLE restore_nonces AS SELECT * FROM lorkhan_internal.request_mac_nonces;
CREATE TEMP TABLE restore_browser AS SELECT * FROM lorkhan_internal.browser_sessions;
CREATE TEMP TABLE restore_backups AS SELECT * FROM lorkhan_internal.backup_records;
CREATE TEMP TABLE restore_backup_settings AS SELECT * FROM lorkhan_internal.database_backup_settings;
CREATE TEMP TABLE restore_job AS SELECT * FROM lorkhan_internal.durable_jobs WHERE job_id=:'job_id';
CREATE TEMP TABLE restore_attempt AS SELECT * FROM lorkhan_internal.durable_job_attempts WHERE job_id=:'job_id';
