#!/usr/bin/env php
<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\BiographyCatalogImporter;
use LorkhanServer\Infrastructure\Connection;

require dirname(__DIR__) . '/src/Autoload.php';

try {
    $catalogDirectory = getenv('LORKHAN_BIOGRAPHY_CATALOG_DIR') ?: dirname(__DIR__) . '/resources/biographies/morrowind-official';
    $biographies = $catalogDirectory . '/biographies.json';
    $manifest = $catalogDirectory . '/manifest.json';
    $versionFile = $catalogDirectory . '/catalog-version.txt';
    if (!is_dir($catalogDirectory)) {
        echo json_encode(['schema' => 'lorkhan.default-biographies.v1', 'status' => 'skipped', 'reason' => 'catalog_not_bundled'], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    if (!is_file($biographies) || !is_file($manifest) || !is_file($versionFile)) throw new RuntimeException('Bundled biography catalog is incomplete.');
    $catalogVersion = trim((string) file_get_contents($versionFile));
    $configFile = getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/config/server.php';
    if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
    $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $result = (new BiographyCatalogImporter(Connection::open($config)))->provision($biographies, $manifest, $catalogVersion);
    echo json_encode(['schema' => 'lorkhan.default-biographies.v1', 'status' => 'ready', 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Default biography provisioning failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
