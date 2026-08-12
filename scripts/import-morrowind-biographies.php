#!/usr/bin/env php
<?php

declare(strict_types=1);

use ALMSIVIserver\Infrastructure\BiographyCatalogImporter;
use ALMSIVIserver\Infrastructure\Connection;

require dirname(__DIR__) . '/src/Autoload.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n"
        . "  php scripts/import-morrowind-biographies.php dry-run|apply --biographies=PATH --manifest=PATH --catalog-version=VERSION\n"
        . "  php scripts/import-morrowind-biographies.php rollback [--catalog-version=VERSION]\n"
        . "  php scripts/import-morrowind-biographies.php status\n");
    exit(2);
};

try {
    $command = $argv[1] ?? null;
    if (!in_array($command, ['dry-run', 'apply', 'rollback', 'status'], true)) $usage();
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (preg_match('/^--(biographies|manifest|catalog-version)=(.+)$/D', $argument, $match) !== 1) $usage();
        $options[$match[1]] = $match[2];
    }
    $configFile = getenv('ALMSIVI_CONFIG') ?: dirname(__DIR__) . '/config/server.php';
    if (!is_file($configFile)) throw new RuntimeException('Server configuration is unavailable. Set ALMSIVI_CONFIG.');
    $config = require $configFile;
    if (!is_array($config)) throw new RuntimeException('Server configuration is invalid.');
    $config['database_password'] = getenv('ALMSIVI_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $importer = new BiographyCatalogImporter(Connection::open($config));
    if (in_array($command, ['dry-run', 'apply'], true)) {
        foreach (['biographies', 'manifest', 'catalog-version'] as $required) if (!isset($options[$required])) $usage();
        $result = $command === 'dry-run'
            ? $importer->plan($options['biographies'], $options['manifest'], $options['catalog-version'])
            : $importer->apply($options['biographies'], $options['manifest'], $options['catalog-version']);
    } elseif ($command === 'rollback') {
        $result = $importer->rollback($options['catalog-version'] ?? null);
    } else {
        $result = $importer->status();
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Biography catalog import failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
