#!/usr/bin/env php
<?php

declare(strict_types=1);

use LorkhanServer\Application\FirstPartyJobHandlerFactory;
use LorkhanServer\Application\Provider;
use LorkhanServer\Application\ProviderFactory;
use LorkhanServer\Application\SpeechProvider;
use LorkhanServer\Application\Worker;
use LorkhanServer\Infrastructure\Connection;
use LorkhanServer\Infrastructure\JobRepository;
use LorkhanServer\Infrastructure\MediaStore;

require dirname(__DIR__) . '/lib/Autoload.php';

try {
    $configFile = getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/conf/server.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('Server configuration is unavailable. Set LORKHAN_CONFIG.');
    }
    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('Server configuration is invalid.');
    }
    $config['credential_storage_path'] ??= '/var/lib/lorkhanserver/credentials/provider-keys.json';
    $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
    $worker = $config['worker'] ?? [];
    if (!is_array($worker)) {
        throw new RuntimeException('Worker configuration is invalid.');
    }
    $types = $worker['types'] ?? null;
    if ($types !== null && !is_array($types)) {
        throw new RuntimeException('Worker job types must be a list.');
    }
    // Supervisors pass a fixed lane argument; manual runs keep the single all-types worker.
    $lane = 'all';
    foreach (array_slice($argv ?? [], 1) as $argument) {
        if (preg_match('/^--lane=([a-z]+)$/D', (string) $argument, $match) !== 1) throw new RuntimeException('Unknown worker argument.');
        $lane = $match[1];
    }
    $filter = Worker::laneFilter($lane, $types);
    $workerId = isset($worker['id']) ? (string) $worker['id'] . ($lane === 'all' ? '' : ':' . $lane)
        : (gethostname() ?: 'localhost') . ($lane === 'all' ? '' : ':' . $lane) . ':' . getmypid();
    if ($filter['types'] === []) {
        // worker.types left this lane empty: idle like an empty queue so supervisors restart it slowly, not as a failure.
        sleep(max(1, min((int) ($worker['idle_exit_seconds'] ?? 30), (int) ($worker['max_runtime_seconds'] ?? 300))));
        fwrite(STDOUT, json_encode(['worker_id' => $workerId, 'lane' => $lane, 'claimed' => 0, 'succeeded' => 0, 'retried' => 0,
            'dead' => 0, 'idle' => 'no_configured_job_types'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        exit(0);
    }
    // Workers acquire the runtime gate per job, not while idle between jobs.
    $database = Connection::open($config,false);
    $media = new MediaStore((string) ($config['media_storage_path'] ?? '/var/lib/lorkhanserver/media'),
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
    $runner = new Worker(
        new JobRepository($database),
        FirstPartyJobHandlerFactory::registry($database, $media, provider: $provider, speechProvider: $speechProvider,
            providerTimeoutMs: (int)($config['provider']['timeout_ms'] ?? 1000),providerConfig:$config),
        $workerId,
        (int) ($worker['lease_seconds'] ?? 30),
        (int) ($worker['batch_size'] ?? 1),
        (int) ($worker['max_jobs'] ?? 100),
        (int) ($worker['idle_exit_seconds'] ?? 30),
        (int) ($worker['max_runtime_seconds'] ?? 300),
        $filter['types'],
        maintenance: $filter['types']===null||in_array('profile.generate',$filter['types'],true)
            ?static fn()=>(new \LorkhanServer\Infrastructure\ProfileEvolutionScheduler($database))->run():null,
        excludedTypes: $filter['excluded_types'],
    );
    $stats = $runner->run();
    fwrite(STDOUT, json_encode(['worker_id' => $workerId, 'lane' => $lane] + $stats, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['code' => 'worker_failed', 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
