#!/usr/bin/env php
<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\DescriptionCatalogImporter;

require dirname(__DIR__) . '/lib/Autoload.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n"
        . "  php scripts/import-morrowind-item-descriptions.php dry-run|apply --csv=PATH --manifest=PATH --catalog-version=VERSION\n"
        . "  php scripts/import-morrowind-item-descriptions.php rollback [--catalog-version=VERSION]\n"
        . "  php scripts/import-morrowind-item-descriptions.php status\n");
    exit(2);
};

try {
    $command = $argv[1] ?? null;
    if (!in_array($command, ['dry-run', 'apply', 'rollback', 'status'], true)) $usage();
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (preg_match('/^--(csv|manifest|catalog-version)=(.+)$/D', $argument, $match) !== 1) $usage();
        $options[$match[1]] = $match[2];
    }
    $configFile = getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/conf/server.php';
    if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
    $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $importer = new DescriptionCatalogImporter(Connection::open($config));
    if (in_array($command, ['dry-run', 'apply'], true)) {
        if (!isset($options['csv'], $options['manifest'], $options['catalog-version'])) $usage();
        $result = $command === 'dry-run'
            ? $importer->plan($options['csv'], $options['manifest'], $options['catalog-version'])
            : $importer->apply($options['csv'], $options['manifest'], $options['catalog-version']);
    } elseif ($command === 'rollback') {
        $result = $importer->rollback($options['catalog-version'] ?? null);
    } else {
        $result = $importer->status();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (($result['valid'] ?? true) !== true) exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Description catalog command failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
