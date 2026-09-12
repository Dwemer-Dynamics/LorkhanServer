<?php

declare(strict_types=1);

use LorkhanServer\Tests\Support\ControllableProvider;

require_once dirname(__DIR__) . '/tests/Support/ControllableProvider.php';

$controlDirectory = getenv('LORKHAN_TEST_PROVIDER_CONTROL') ?: '';
if ($controlDirectory === '' || !is_dir($controlDirectory)) {
    throw new RuntimeException('LORKHAN_TEST_PROVIDER_CONTROL must name an existing directory.');
}
$dsn = getenv('LORKHAN_TEST_DSN') ?: '';
if ($dsn === '') {
    throw new RuntimeException('LORKHAN_TEST_DSN is required.');
}

return [
    'environment' => 'test',
    'factory_storage_path' => getenv('LORKHAN_TEST_FACTORY_DIR') ?: $controlDirectory.'/factory-unavailable',
    'database_admin_url' => is_file($controlDirectory.'/database-admin-url')?file_get_contents($controlDirectory.'/database-admin-url'):'',
    'base_path' => '/LorkhanServer/api/v1',
    'database_dsn' => $dsn,
    'database_user' => getenv('LORKHAN_TEST_DB_USER') ?: '',
    'database_password' => getenv('LORKHAN_TEST_DB_PASSWORD') ?: '',
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
