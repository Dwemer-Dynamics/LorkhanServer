<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;

final class Connection
{
    public static function open(array $config, bool $runtimeGate = true): PDO
    {
        $dsn = (string) ($config['database_dsn'] ?? '');
        $user = (string) ($config['database_user'] ?? '');
        $password = (string) ($config['database_password'] ?? '');
        if (!str_starts_with($dsn, 'pgsql:')) {
            throw new \RuntimeException('PostgreSQL is required for server runtime persistence.');
        }

        $db = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec('SET search_path TO lorkhan_internal, public, pg_temp');
        if($runtimeGate&&!self::enterRuntime($db))throw new \RuntimeException('database_restore_in_progress');
        return $db;
    }

    /** Hold for the connection/request lifetime so a restore cannot replace tables under active work. */
    public static function enterRuntime(PDO $db): bool
    {
        return filter_var($db->query('SELECT pg_try_advisory_lock_shared(7514,114)')->fetchColumn(),FILTER_VALIDATE_BOOL);
    }

    public static function leaveRuntime(PDO $db): void
    {
        $db->query('SELECT pg_advisory_unlock_shared(7514,114)');
    }
}
