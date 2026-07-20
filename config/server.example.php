<?php
declare(strict_types=1);

return [
    'environment' => 'production',
    'base_path' => '/ALMSIVIserver/api/v1',
    'database_dsn' => 'pgsql:host=127.0.0.1;port=5432;dbname=almsivi',
    'database_user' => 'almsivi_runtime',
    // public/index.php reads ALMSIVI_DATABASE_PASSWORD, ALMSIVI_PAIRING_TOKEN_HASH, and
    // ALMSIVI_MANAGEMENT_SECRET_HASH from a restrictive service EnvironmentFile. Only hashes are accepted.
    'database_password' => '',
    'pairing_token_hash' => '',
    'management_base_path' => '/ALMSIVIserver/manage',
    'management_secret_hash' => '',
    'browser_session_ttl_seconds' => 3600,
    'max_json_bytes' => 2 * 1024 * 1024,
    'max_context_bytes' => 128 * 1024,
    'events_page_size' => 100,
    'event_replay_limit' => 256,
    'events_max_wait_seconds' => 15,
    'rate_limit_requests' => 120,
    'rate_limit_window_seconds' => 60,
    // Provider configs are non-secret and server-owned. No live provider or billing path is enabled.
    'provider' => [
        'driver' => 'mock',
        'model' => 'deterministic-mock-v1',
        'mock_prefix' => '',
        'timeout_ms' => 1000,
    ],
    // Must resolve outside public/. Production default: /var/lib/almsiviserver/media.
    'media_storage_path' => '/var/lib/almsiviserver/media',
    'media_max_bytes' => 32 * 1024 * 1024,
    'media_quota_bytes' => 256 * 1024 * 1024,
    'worker' => [
        // The worker exits after bounded work/runtime and is restarted by its systemd timer.
        'lease_seconds' => 30,
        'batch_size' => 1,
        'max_jobs' => 100,
        'idle_exit_seconds' => 30,
        'max_runtime_seconds' => 300,
        // First-party handlers are source-controlled and registered by default; optionally narrow claims.
        'types' => null,
    ],
];
