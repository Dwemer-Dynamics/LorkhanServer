#!/usr/bin/env php
<?php

declare(strict_types=1);

use ALMSIVIserver\Application\FirstPartyJobHandlerFactory;
use ALMSIVIserver\Application\Provider;
use ALMSIVIserver\Application\ProviderFactory;
use ALMSIVIserver\Application\SpeechProvider;
use ALMSIVIserver\Application\SpeechToTextProvider;
use ALMSIVIserver\Application\Worker;
use ALMSIVIserver\Infrastructure\Connection;
use ALMSIVIserver\Infrastructure\JobRepository;
use ALMSIVIserver\Infrastructure\MediaStore;

require dirname(__DIR__) . '/src/Autoload.php';

try {
    $configFile = getenv('ALMSIVI_CONFIG') ?: dirname(__DIR__) . '/config/server.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Server configuration is unavailable. Set ALMSIVI_CONFIG.');
    }
    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('Server configuration is invalid.');
    }
    $config['credential_storage_path'] ??= '/var/lib/almsiviserver/credentials/provider-keys.json';
    $config['database_password'] = getenv('ALMSIVI_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $worker = $config['worker'] ?? [];
    if (!is_array($worker)) {
        throw new RuntimeException('Worker configuration is invalid.');
    }
    $workerId = (string) ($worker['id'] ?? (gethostname() ?: 'localhost') . ':' . getmypid());
    $types = $worker['types'] ?? null;
    if ($types !== null && !is_array($types)) {
        throw new RuntimeException('Worker job types must be a list.');
    }
    $database = Connection::open($config);
    $media = new MediaStore((string) ($config['media_storage_path'] ?? dirname(__DIR__) . '/storage/media'),
        (int) ($config['media_max_bytes'] ?? 33_554_432), (int) ($config['media_quota_bytes'] ?? 268_435_456));
    $provider = ProviderFactory::dialogue($config);
    if (isset($config['provider_factory'])) {
        if (($config['environment'] ?? 'production') !== 'test' || !is_callable($config['provider_factory'])) throw new RuntimeException('Provider factory is test-only.');
        $provider = ($config['provider_factory'])();
        if (!$provider instanceof Provider) throw new RuntimeException('Provider factory did not return a Provider.');
    }
    $speechProvider = ProviderFactory::speech($config);
    if (isset($config['speech_provider_factory'])) {
        if (($config['environment'] ?? 'production') !== 'test' || !is_callable($config['speech_provider_factory'])) throw new RuntimeException('Speech provider factory is test-only.');
        $speechProvider = ($config['speech_provider_factory'])();
        if (!$speechProvider instanceof SpeechProvider) throw new RuntimeException('Speech provider factory did not return a SpeechProvider.');
    }
    $sttProvider = ProviderFactory::speechToText($config);
    if (isset($config['stt_provider_factory'])) {
        if (($config['environment'] ?? 'production') !== 'test' || !is_callable($config['stt_provider_factory'])) throw new RuntimeException('STT provider factory is test-only.');
        $sttProvider = ($config['stt_provider_factory'])();
        if (!$sttProvider instanceof SpeechToTextProvider) throw new RuntimeException('STT provider factory did not return a SpeechToTextProvider.');
    }
    $runner = new Worker(
        new JobRepository($database),
        FirstPartyJobHandlerFactory::registry($database, $media, provider: $provider, speechProvider: $speechProvider,
            providerTimeoutMs: (int)($config['provider']['timeout_ms'] ?? 1000),sttProvider:$sttProvider,providerConfig:$config),
        $workerId,
        (int) ($worker['lease_seconds'] ?? 30),
        (int) ($worker['batch_size'] ?? 1),
        (int) ($worker['max_jobs'] ?? 100),
        (int) ($worker['idle_exit_seconds'] ?? 30),
        (int) ($worker['max_runtime_seconds'] ?? 300),
        $types,
    );
    $stats = $runner->run();
    fwrite(STDOUT, json_encode(['worker_id' => $workerId] + $stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['code' => 'worker_failed', 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
