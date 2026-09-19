<?php

declare(strict_types=1);

use LorkhanServer\Infrastructure\Connection;

require_once dirname(__DIR__) . '/lib/Autoload.php';

// Probe the real table constraints without touching conversation rows or calling providers.
try {
    $testDsn = getenv('LORKHAN_TEST_DSN');
    if ($testDsn) {
        $config = ['database_dsn' => $testDsn,
            'database_user' => getenv('LORKHAN_TEST_DB_USER') ?: '',
            'database_password' => getenv('LORKHAN_TEST_DB_PASSWORD') ?: ''];
    } else {
        $config = require (getenv('LORKHAN_CONFIG') ?: dirname(__DIR__) . '/conf/server.php');
        $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: ($config['database_password'] ?? '');
    }
    $probe = Connection::open($config);
    $probe->beginTransaction();
    try {
        $probe->exec("SET LOCAL lock_timeout = '2s'; SET LOCAL statement_timeout = '5s'");
        // LIKE copies CHECK/NOT NULL rules, but no foreign keys or production rows.
        $probe->exec('CREATE TEMP TABLE prompt_label_probe (LIKE lorkhan_internal.prompt_traces INCLUDING CONSTRAINTS) ON COMMIT DROP');
        $insert = $probe->prepare("INSERT INTO prompt_label_probe
            (prompt_trace_id,installation_id,profile_id,playthrough_id,algorithm,input_sha256,input_bytes,truncated,created_at)
            VALUES (:id,:installation,'probe',:playthrough,:label,:hash,1,false,clock_timestamp())");
        foreach (['lorkhan-markdown', 'future-prompt-' . bin2hex(random_bytes(8))] as $label) {
            $insert->execute(['id' => '00000000-0000-4000-8000-000000000001',
                'installation' => '00000000-0000-4000-8000-000000000002',
                'playthrough' => '00000000-0000-4000-8000-000000000003',
                'label' => $label, 'hash' => str_repeat('a', 64)]);
        }
    } finally {
        $probe->rollBack();
    }
    fwrite(STDOUT, "Prompt trace labels: current and unseen future labels accepted; no persistent changes.\n");
} catch (Throwable $error) {
    // Avoid printing database configuration or connection diagnostics containing credentials.
    fwrite(STDERR, "Prompt trace label check failed (" . get_class($error) . "). Do not deploy a version allowlist.\n");
    exit(1);
}
