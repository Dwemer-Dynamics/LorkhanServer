<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\DefaultConnectorProvisioner;

require dirname(__DIR__) . '/lib/Autoload.php';

if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
$configFile = getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/conf/server.php';
if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
$config = require $configFile;
if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
$config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
$db = Connection::open($config);
$provisioner = new DefaultConnectorProvisioner(
    $db,
    (string) ($config['voice_storage_path'] ?? '/var/lib/lorkhanserver/voices'),
    (string) (getenv('LORKHAN_DEFAULT_TTS_ENDPOINT') ?: 'http://127.0.0.1:8086'),
);
$installations = $db->query('SELECT installation_id FROM installations WHERE revoked_at IS NULL ORDER BY created_at,installation_id')
    ->fetchAll(PDO::FETCH_COLUMN);
$results = [];
foreach ($installations as $installationId) $results[] = $provisioner->provision((string) $installationId);
echo json_encode(['schema' => 'lorkhan.default-connectors.v1', 'installations' => $results],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
