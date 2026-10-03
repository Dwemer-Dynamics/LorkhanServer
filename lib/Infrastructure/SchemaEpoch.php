<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use PDO;
use RuntimeException;

/**
 * Read-only schema-epoch preflight for the clean-build database boundary.
 *
 * The clean epoch marker lives in its own schema so that probing it never touches the legacy
 * lorkhan_internal.schema_migrations ledger. Classification only reads the system catalogs;
 * every rejection happens before DDL, ledger relocation or import staging.
 *
 * Staged boundary: no production entry point installs the clean marker yet. The current
 * migrations (001+) are the legacy compatibility baseline, not the clean schema, so marking a
 * database clean before the clean baseline exists would be false. The active guard is
 * assertLegacySchemaPath(): legacy runners/imports refuse any database carrying an epoch marker.
 */
final class SchemaEpoch
{
    public const CLEAN = 'lorkhan-clean-v2';
    public const MARKER_SCHEMA = 'lorkhan_meta';
    public const MARKER = 'lorkhan_meta.schema_epoch';

    public const EMPTY = 'empty';
    public const CLEAN_STATE = 'clean';
    public const LEGACY = 'legacy';
    public const UNRECOGNIZED = 'unrecognized';
    public const UNSUPPORTED_EPOCH = 'unsupported_epoch';
    public const CONFLICTING = 'conflicting';

    private const LOCK_ID = 7_514_202;
    private const SYSTEM_SCHEMAS = "n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg\\_toast%' AND n.nspname NOT LIKE 'pg\\_temp\\_%'";

    /**
     * Classify without mutation. Extension-owned objects (for example pgvector) do not make a
     * database nonempty; any other relation, routine or standalone type does.
     *
     * @return array{state:string,epoch:?string,legacy_ledger:?string}
     */
    public static function inspect(PDO $db): array
    {
        $legacy = [];
        foreach (['lorkhan_internal.schema_migrations', 'public.schema_migrations'] as $ledger) {
            if (self::exists($db, $ledger)) $legacy[] = $ledger;
        }
        $markerSchema = $db->query("SELECT to_regnamespace('" . self::MARKER_SCHEMA . "') IS NOT NULL")->fetchColumn();
        if (filter_var($markerSchema, FILTER_VALIDATE_BOOL)) {
            $epoch = self::markerEpoch($db);
            if ($legacy !== []) return ['state' => self::CONFLICTING, 'epoch' => $epoch, 'legacy_ledger' => $legacy[0]];
            return ['state' => $epoch === self::CLEAN ? self::CLEAN_STATE : self::UNSUPPORTED_EPOCH, 'epoch' => $epoch, 'legacy_ledger' => null];
        }
        if (count($legacy) > 1) return ['state' => self::CONFLICTING, 'epoch' => null, 'legacy_ledger' => $legacy[0]];
        if ($legacy !== []) return ['state' => self::LEGACY, 'epoch' => null, 'legacy_ledger' => $legacy[0]];
        return ['state' => self::userObjectCount($db) === 0 ? self::EMPTY : self::UNRECOGNIZED, 'epoch' => null, 'legacy_ledger' => null];
    }

    /** Clean-runtime/import preflight. Throws before any caller mutation unless the marker is exactly clean. */
    public static function requireClean(PDO $db): void
    {
        $inspection = self::inspect($db);
        if ($inspection['state'] !== self::CLEAN_STATE) throw new SchemaEpochMismatch($inspection['state']);
    }

    /** Active guard for the legacy runner, replay, restore, import and factory paths. */
    public static function assertLegacySchemaPath(PDO $db): void
    {
        $inspection = self::inspect($db);
        if (in_array($inspection['state'], [self::CLEAN_STATE, self::UNSUPPORTED_EPOCH, self::CONFLICTING], true)) {
            throw new SchemaEpochMismatch($inspection['state']);
        }
    }

    /**
     * Install the clean marker on an empty disposable database, or confirm an existing clean one.
     * Returns true only when this call created the marker. Staged: callers must install the clean
     * baseline in the same checkpoint; no production entry point invokes this yet.
     */
    public static function initializeEmpty(PDO $db): bool
    {
        if ($db->inTransaction()) throw new RuntimeException('schema_epoch_transaction_required');
        $db->beginTransaction();
        try {
            $db->query('SELECT pg_advisory_xact_lock(' . self::LOCK_ID . ')');
            $state = self::inspect($db)['state'];
            if ($state === self::CLEAN_STATE) {
                $db->commit();
                return false;
            }
            if ($state !== self::EMPTY) throw new SchemaEpochMismatch($state);
            $db->exec('CREATE SCHEMA ' . self::MARKER_SCHEMA);
            $db->exec('CREATE TABLE ' . self::MARKER . ' (singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),'
                . " epoch text NOT NULL CHECK (epoch ~ '^[a-z0-9][a-z0-9.-]{0,63}\$'),"
                . ' initialized_at timestamptz NOT NULL DEFAULT clock_timestamp())');
            $insert = $db->prepare('INSERT INTO ' . self::MARKER . ' (epoch) VALUES (:epoch)');
            $insert->execute(['epoch' => self::CLEAN]);
            $db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    private static function markerEpoch(PDO $db): ?string
    {
        if (!self::exists($db, self::MARKER)) return null;
        $columns = $db->query("SELECT count(*) FROM pg_attribute WHERE attrelid=to_regclass('" . self::MARKER . "') AND attname IN ('singleton','epoch') AND NOT attisdropped")->fetchColumn();
        if ((int) $columns !== 2) return null;
        $rows = $db->query('SELECT epoch FROM ' . self::MARKER . ' LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        return count($rows) === 1 && is_string($rows[0]) ? $rows[0] : null;
    }

    private static function exists(PDO $db, string $relation): bool
    {
        $statement = $db->prepare('SELECT to_regclass(:relation) IS NOT NULL');
        $statement->execute(['relation' => $relation]);
        return filter_var($statement->fetchColumn(), FILTER_VALIDATE_BOOL);
    }

    private static function userObjectCount(PDO $db): int
    {
        $notExtension = static fn(string $catalog, string $alias): string => "NOT EXISTS(SELECT 1 FROM pg_depend d WHERE d.classid='{$catalog}'::regclass AND d.objid={$alias}.oid AND d.deptype='e')";
        $sql = 'SELECT (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE ' . self::SYSTEM_SCHEMAS
            . " AND c.relkind IN ('r','p','v','m','S','f') AND " . $notExtension('pg_class', 'c') . ')'
            . ' + (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE ' . self::SYSTEM_SCHEMAS
            . ' AND ' . $notExtension('pg_proc', 'p') . ')'
            . ' + (SELECT count(*) FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace WHERE ' . self::SYSTEM_SCHEMAS
            . " AND t.typtype IN ('d','e','r','m') AND " . $notExtension('pg_type', 't') . ')';
        return (int) $db->query($sql)->fetchColumn();
    }
}
