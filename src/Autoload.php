<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'LorkhanServer\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
        return;
    }
    if (str_starts_with($relative, 'Application\\') && in_array(substr($relative, strlen('Application\\')), [
        'FirstPartyJobHandler', 'MemoryDeriveJobHandler', 'MemoryConsolidateJobHandler', 'MemoryRebuildJobHandler', 'NarrativeJobHandler',
        'MediaCleanupJobHandler', 'RetentionJobHandler', 'ProviderReconciliationJobHandler',
    ], true)) {
        require __DIR__ . '/Application/FirstPartyJobHandlers.php';
    }
});
