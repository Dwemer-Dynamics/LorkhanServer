#!/usr/bin/env php
<?php

declare(strict_types=1);

use LORKHANserver\Infrastructure\Connection;
use LORKHANserver\Infrastructure\MigrationRunner;

require dirname(__DIR__) . '/src/Autoload.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage: php scripts/migrate.php <status|up|fresh|rerun|down> [--target=N|--steps=N] [--force]\n");
    exit(2);
};

try {
    $command = $argv[1] ?? null;
    if (!in_array($command, ['status', 'up', 'fresh', 'rerun', 'down'], true)) {
        $usage();
    }
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--force') {
            $options['force'] = true;
        } elseif (preg_match('/^--(target|steps)=([0-9]+)$/D', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        } else {
            $usage();
        }
    }
    $configFile = getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/config/server.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
    }
    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('Server configuration is invalid.');
    }
    $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $runner = new MigrationRunner(Connection::open($config), dirname(__DIR__) . '/database/migrations');

    if (in_array($command, ['fresh', 'rerun', 'down'], true) && !array_key_exists('force', $options)) {
        throw new RuntimeException("{$command} is destructive; repeat with --force.");
    }
    switch ($command) {
        case 'status':
            foreach ($runner->status() as $row) {
                printf("%03d %-32s %-7s %s\n", $row['version'], $row['name'], $row['applied'] ? 'applied' : 'pending', $row['checksum']);
            }
            break;
        case 'up':
            $target = isset($options['target']) ? filter_var($options['target'], FILTER_VALIDATE_INT) : null;
            if (isset($options['target']) && $target === false) {
                $usage();
            }
            fwrite(STDOUT, 'Applied: ' . implode(', ', $runner->up($target === null ? null : (int) $target)) . "\n");
            break;
        case 'fresh':
            fwrite(STDOUT, 'Fresh schema applied: ' . implode(', ', $runner->fresh()) . "\n");
            break;
        case 'rerun':
            fwrite(STDOUT, 'Reran: ' . $runner->rerun() . "\n");
            break;
        case 'down':
            $steps = isset($options['steps']) ? filter_var($options['steps'], FILTER_VALIDATE_INT) : 1;
            if ($steps === false) {
                $usage();
            }
            fwrite(STDOUT, 'Reverted: ' . implode(', ', $runner->down((int) $steps)) . "\n");
            break;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
