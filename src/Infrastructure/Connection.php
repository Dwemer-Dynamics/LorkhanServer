<?php
declare(strict_types=1);

namespace LORKHANserver\Infrastructure;

use PDO;

final class Connection
{
    public static function open(array $config): PDO
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
        return $db;
    }
}
