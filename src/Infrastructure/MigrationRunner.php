<?php

declare(strict_types=1);

namespace LORKHANserver\Infrastructure;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const LOCK_ID = 6_525_393_698_659_162;
    private const LEDGER = 'lorkhan_internal.schema_migrations';

    public function __construct(
        private readonly PDO $db,
        private readonly string $directory,
    ) {}

    /** @return list<array{version:int,name:string,checksum:string,applied:bool,applied_at:?string}> */
    public function status(): array
    {
        return $this->locked(function (): array {
            $migrations = $this->discover();
            $applied = $this->applied();
            $this->assertNoDrift($migrations, $applied);
            return array_map(static fn(array $migration): array => [
                'version' => $migration['version'],
                'name' => $migration['name'],
                'checksum' => $migration['checksum'],
                'applied' => isset($applied[$migration['version']]),
                'applied_at' => $applied[$migration['version']]['applied_at'] ?? null,
            ], $migrations);
        });
    }

    /** @return list<int> */
    public function up(?int $target = null): array
    {
        return $this->locked(function () use ($target): array {
            $migrations = $this->discover();
            $applied = $this->applied();
            $this->assertNoDrift($migrations, $applied);
            $latest = $migrations === [] ? 0 : $migrations[array_key_last($migrations)]['version'];
            $target ??= $latest;
            if ($target < 0 || $target > $latest) {
                throw new RuntimeException("Migration target {$target} is outside the source-controlled range 0..{$latest}.");
            }
            if ($applied !== [] && max(array_keys($applied)) > $target) {
                throw new RuntimeException('The requested target is behind the database; use the explicit down command.');
            }

            $ran = [];
            foreach ($migrations as $migration) {
                if ($migration['version'] > $target || isset($applied[$migration['version']])) {
                    continue;
                }
                $this->apply($migration);
                $ran[] = $migration['version'];
            }
            return $ran;
        });
    }

    /** @return list<int> */
    public function down(int $steps = 1): array
    {
        if ($steps < 1) {
            throw new RuntimeException('Down steps must be at least one.');
        }
        return $this->locked(function () use ($steps): array {
            $migrations = $this->discover();
            $applied = $this->applied();
            $this->assertNoDrift($migrations, $applied);
            $byVersion = [];
            foreach ($migrations as $migration) {
                $byVersion[$migration['version']] = $migration;
            }
            $versions = array_keys($applied);
            rsort($versions, SORT_NUMERIC);
            $ran = [];
            foreach (array_slice($versions, 0, $steps) as $version) {
                $this->revert($byVersion[$version]);
                $ran[] = $version;
            }
            return $ran;
        });
    }

    /** @return list<int> */
    public function fresh(): array
    {
        return $this->locked(function (): array {
            $migrations = $this->discover();
            $applied = $this->applied();
            $this->assertNoDrift($migrations, $applied);
            $byVersion = [];
            foreach ($migrations as $migration) {
                $byVersion[$migration['version']] = $migration;
            }
            $versions = array_keys($applied);
            rsort($versions, SORT_NUMERIC);
            foreach ($versions as $version) {
                $this->revert($byVersion[$version]);
            }
            $ran = [];
            foreach ($migrations as $migration) {
                $this->apply($migration);
                $ran[] = $migration['version'];
            }
            return $ran;
        });
    }

    public function rerun(): int
    {
        return $this->locked(function (): int {
            $migrations = $this->discover();
            $applied = $this->applied();
            $this->assertNoDrift($migrations, $applied);
            if ($applied === []) {
                throw new RuntimeException('No applied migration can be rerun.');
            }
            $version = max(array_keys($applied));
            $migration = null;
            foreach ($migrations as $candidate) {
                if ($candidate['version'] === $version) {
                    $migration = $candidate;
                    break;
                }
            }
            if ($migration === null) {
                throw new RuntimeException('Applied migration is absent from source control.');
            }
            $this->revert($migration);
            $this->apply($migration);
            return $version;
        });
    }

    /** @return list<array{version:int,name:string,up:string,down:string,checksum:string,crlf_checksum:string}> */
    private function discover(): array
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Migration directory does not exist.');
        }
        $upFiles = glob(rtrim($this->directory, '/') . '/*.up.sql') ?: [];
        sort($upFiles, SORT_STRING);
        $migrations = [];
        $expected = 1;
        foreach ($upFiles as $up) {
            $file = basename($up);
            if (preg_match('/^(\d{3})_([a-z0-9_]+)\.up\.sql$/D', $file, $match) !== 1) {
                throw new RuntimeException("Invalid migration filename: {$file}");
            }
            $version = (int) $match[1];
            if ($version !== $expected) {
                throw new RuntimeException("Migration versions must be contiguous; expected {$expected}, found {$version}.");
            }
            $down = substr($up, 0, -7) . '.down.sql';
            if (!is_file($down)) {
                throw new RuntimeException("Migration {$file} has no reversible down file.");
            }
            $upSql = file_get_contents($up);
            $downSql = file_get_contents($down);
            if ($upSql === false || $downSql === false || trim($upSql) === '' || trim($downSql) === '') {
                throw new RuntimeException("Migration {$file} is unreadable or empty.");
            }
            // PostgreSQL function and DO bodies can contain PL/pgSQL BEGIN blocks; only
            // reject transaction control that appears in the migration's top-level SQL.
            $transactionScan = preg_replace(
                '/\$(?<tag>[A-Za-z_][A-Za-z0-9_]*)\$.*?\$\k<tag>\$/s',
                '',
                $upSql . "\n" . $downSql
            );
            if ($transactionScan === null) {
                throw new RuntimeException("Migration {$file} cannot be checked for transaction control.");
            }
            if (preg_match('/(^|;)\s*(BEGIN|COMMIT|ROLLBACK)\b/i', $transactionScan) === 1) {
                throw new RuntimeException("Migration {$file} controls transactions; the runner must own the transaction.");
            }
            $migrations[] = [
                'version' => $version,
                'name' => $match[2],
                'up' => $upSql,
                'down' => $downSql,
                'checksum' => self::checksum(
                    self::canonicalizeLineEndings($upSql),
                    self::canonicalizeLineEndings($downSql),
                ),
                'crlf_checksum' => self::checksum(
                    str_replace("\n", "\r\n", self::canonicalizeLineEndings($upSql)),
                    str_replace("\n", "\r\n", self::canonicalizeLineEndings($downSql)),
                ),
            ];
            ++$expected;
        }
        return $migrations;
    }

    private function ensureTable(): void
    {
        $this->db->exec('CREATE SCHEMA IF NOT EXISTS lorkhan_internal');
        if ($this->db->query("SELECT to_regclass('public.schema_migrations')")->fetchColumn() !== null
            && $this->db->query("SELECT to_regclass('lorkhan_internal.schema_migrations')")->fetchColumn() === null) {
            $this->db->exec('ALTER TABLE public.schema_migrations SET SCHEMA lorkhan_internal');
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . self::LEDGER . ' ('
            . 'version bigint PRIMARY KEY, name text, checksum char(64), applied_at timestamptz NOT NULL DEFAULT clock_timestamp())');
        // Upgrade the initial independently-authored metadata table without interpreting application data.
        $this->db->exec('ALTER TABLE ' . self::LEDGER . ' ADD COLUMN IF NOT EXISTS name text');
        $this->db->exec('ALTER TABLE ' . self::LEDGER . ' ADD COLUMN IF NOT EXISTS checksum char(64)');
    }

    /** @return array<int,array{name:?string,checksum:?string,applied_at:string}> */
    private function applied(): array
    {
        $rows = $this->db->query('SELECT version, name, checksum, applied_at FROM ' . self::LEDGER . ' ORDER BY version')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['version']] = [
                'name' => $row['name'] === null ? null : (string) $row['name'],
                'checksum' => $row['checksum'] === null ? null : rtrim((string) $row['checksum']),
                'applied_at' => (string) $row['applied_at'],
            ];
        }
        return $result;
    }

    private function assertNoDrift(array $migrations, array $applied): void
    {
        $byVersion = [];
        foreach ($migrations as $migration) {
            $byVersion[$migration['version']] = $migration;
        }
        $expectedApplied = 1;
        foreach ($applied as $version => $row) {
            if ($version !== $expectedApplied) {
                throw new RuntimeException("Applied migration history has a gap before version {$version}.");
            }
            if (!isset($byVersion[$version])) {
                throw new RuntimeException("Applied migration {$version} is absent from source control.");
            }
            if ($row['checksum'] === null || $row['name'] === null) {
                throw new RuntimeException("Applied migration {$version} lacks checksum metadata; explicit reconciliation is required.");
            }
            $checksumMatches = hash_equals($byVersion[$version]['checksum'], $row['checksum'])
                || hash_equals($byVersion[$version]['crlf_checksum'], $row['checksum']);
            if (!$checksumMatches || $row['name'] !== $byVersion[$version]['name']) {
                throw new RuntimeException("Migration drift detected at version {$version}.");
            }
            ++$expectedApplied;
        }
    }

    private function apply(array $migration): void
    {
        $this->transaction(function () use ($migration): void {
            $this->db->exec($migration['up']);
            $statement = $this->db->prepare('INSERT INTO ' . self::LEDGER . ' (version, name, checksum) VALUES (:version, :name, :checksum)');
            $statement->execute(['version' => $migration['version'], 'name' => $migration['name'], 'checksum' => $migration['checksum']]);
        });
    }

    private function revert(array $migration): void
    {
        $this->transaction(function () use ($migration): void {
            $this->db->exec($migration['down']);
            $statement = $this->db->prepare('DELETE FROM ' . self::LEDGER . ' WHERE version = :version');
            $statement->execute(['version' => $migration['version']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException("Migration {$migration['version']} metadata changed during rollback.");
            }
        });
    }

    private function transaction(callable $callback): void
    {
        $this->db->beginTransaction();
        try {
            // Historical migrations intentionally build their source tables in public;
            // the final cutover moves LORKHAN-only state behind the internal schema.
            $this->db->exec('SET LOCAL search_path TO public, pg_temp');
            $callback();
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    // Git and deployment tools may materialize identical SQL with LF or CRLF bytes.
    private static function canonicalizeLineEndings(string $sql): string
    {
        return str_replace(["\r\n", "\r"], "\n", $sql);
    }

    private static function checksum(string $upSql, string $downSql): string
    {
        return hash('sha256', "up\0" . $upSql . "\0down\0" . $downSql);
    }

    private function locked(callable $callback): mixed
    {
        $this->ensureTable();
        $statement = $this->db->prepare('SELECT pg_advisory_lock(:lock)');
        $statement->execute(['lock' => self::LOCK_ID]);
        try {
            return $callback();
        } finally {
            $unlock = $this->db->prepare('SELECT pg_advisory_unlock(:lock)');
            $unlock->execute(['lock' => self::LOCK_ID]);
            $this->db->exec('SET search_path TO lorkhan_internal, public, pg_temp');
        }
    }
}
