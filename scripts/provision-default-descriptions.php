#!/usr/bin/env php
<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\DescriptionCatalogImporter;

require dirname(__DIR__) . '/lib/Autoload.php';

if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
$catalogDirectory = getenv('LORKHAN_DESCRIPTION_CATALOG_DIR') ?: dirname(__DIR__) . '/data/descriptions/morrowind-official';
$csv = $catalogDirectory . '/descriptions.csv';
$manifest = $catalogDirectory . '/manifest.json';
$versionFile = $catalogDirectory . '/catalog-version.txt';
if (!is_file($csv) && !is_file($manifest) && !is_file($versionFile)) {
    echo json_encode(['schema' => 'lorkhan.default-descriptions.v1', 'status' => 'skipped', 'reason' => 'catalog_not_bundled'], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}
if (!is_file($csv) || !is_file($manifest) || !is_file($versionFile)) throw new RuntimeException('Bundled description catalog is incomplete.');
$catalogVersion = trim((string) file_get_contents($versionFile));
$configFile = getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/conf/server.php';
if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
$config = require $configFile;
if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
$config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
$result = (new DescriptionCatalogImporter(Connection::open($config)))->provision($csv, $manifest, $catalogVersion);
echo json_encode(['schema' => 'lorkhan.default-descriptions.v1', 'status' => 'ready', 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
