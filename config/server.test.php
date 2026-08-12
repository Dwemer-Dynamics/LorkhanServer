<?php

declare(strict_types=1);

use ALMSIVIserver\Tests\Support\ControllableProvider;

require_once dirname(__DIR__) . '/tests/Support/ControllableProvider.php';

$controlDirectory = getenv('ALMSIVI_TEST_PROVIDER_CONTROL') ?: '';
if ($controlDirectory === '' || !is_dir($controlDirectory)) {
    throw new RuntimeException('ALMSIVI_TEST_PROVIDER_CONTROL must name an existing directory.');
}
$dsn = getenv('ALMSIVI_TEST_DSN') ?: '';
if ($dsn === '') {
    throw new RuntimeException('ALMSIVI_TEST_DSN is required.');
}

return [
    'environment' => 'test',
    'base_path' => '/ALMSIVIserver/api/v1',
    'database_dsn' => $dsn,
    'database_user' => getenv('ALMSIVI_TEST_DB_USER') ?: '',
    'database_password' => getenv('ALMSIVI_TEST_DB_PASSWORD') ?: '',
    'pairing_token_hash' => '',
    'max_json_bytes' => 2 * 1024 * 1024,
    'events_page_size' => 100,
    'event_replay_limit' => 256,
    'events_max_wait_seconds' => 15,
    'rate_limit_requests' => 1000,
    'rate_limit_window_seconds' => 60,
    'media_storage_path' => $controlDirectory . '/media',
    'voice_storage_path' => $controlDirectory . '/voices',
    'portrait_storage_path' => $controlDirectory . '/profile-portraits',
    'backup_storage_path' => $controlDirectory . '/backups',
    'credential_storage_path' => $controlDirectory . '/credentials/provider-keys.json',
    'media_max_bytes' => 32 * 1024 * 1024,
    'media_quota_bytes' => 64 * 1024 * 1024,
    'provider_factory' => static fn(): ControllableProvider => new ControllableProvider($controlDirectory),
];
