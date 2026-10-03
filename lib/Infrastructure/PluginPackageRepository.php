<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\PluginPackageArchive;
use LorkhanServer\Application\PluginPackageException as PackageError;
use LorkhanServer\Protocol\PluginContract;
use PDO;
use PDOException;

/**
 * Installation-scoped .dwpkg lifecycle. Requests only carry opaque IDs; the storage root comes from trusted
 * configuration. Extracted trees are immutable and content-addressed, so a failed update never touches the
 * active version, and removal keeps every tree plus the per-installation mutable data directory.
 */
final class PluginPackageRepository
{
    public const JOB_TYPE = 'plugin_package.apply';
    public const CHUNK_BYTES = 1_048_576;
    private const MAX_UPLOADS = 16;
    private const UPLOAD_TTL_SECONDS = 86_400;
    private const PLUGIN_ID = '/^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]{1,47}$/D';
    private const VERSION = '/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D';

    public function __construct(private readonly PDO $db, private readonly array $config) {}

    /** Create (once) and return a storage subdirectory; a symlinked root or child is never followed. */
    private function directory(string $child): string
    {
        $root = rtrim((string) ($this->config['plugin_package_storage_path'] ?? '/var/lib/lorkhanserver/plugin-packages'), '/');
        $path = $root . '/' . $child;
        foreach ([$root, $path] as $candidate) {
            if (is_link($candidate)) throw new PackageError('package_storage_unavailable');
            if (!is_dir($candidate)) {
                $mask = umask(0007);
                try { $made = @mkdir($candidate, 0770, true); } finally { umask($mask); }
                if (!$made && !is_dir($candidate)) throw new PackageError('package_storage_unavailable');
            }
        }
        return $path;
    }

    public function startUpload(string $installation, array $body): array
    {
        $this->keys($body, ['plugin_id', 'sha256', 'size', 'version']);
        $this->identity($body['plugin_id'], $body['version']);
        if (!is_int($body['size']) || $body['size'] < 22 || !is_string($body['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $body['sha256']) !== 1) {
            throw new PackageError('package_invalid_request');
        }
        if ($body['size'] > PluginPackageArchive::MAX_ARCHIVE_BYTES) throw new PackageError('package_too_large');
        $this->assertInstallation($installation);
        $directory = $this->directory('uploads');
        $lock = $this->lockUploads($directory);
        try {
            // Count and reserve under one lock; unreadable metadata keeps a full reservation until it expires.
            $count = 0; $used = 0;
            foreach (glob($directory . '/*.json') ?: [] as $file) {
                $meta = $this->readMeta($file);
                if ((is_array($meta) ? (int) ($meta['created'] ?? 0) : (int) @filemtime($file)) < time() - self::UPLOAD_TTL_SECONDS) { $this->discard(basename($file, '.json')); continue; }
                ++$count; $used += is_array($meta) ? (int) ($meta['size'] ?? 0) : PluginPackageArchive::MAX_ARCHIVE_BYTES;
            }
            if ($count >= self::MAX_UPLOADS || $used + $body['size'] > 4 * PluginPackageArchive::MAX_ARCHIVE_BYTES) throw new PackageError('package_storage_full');
            $id = Uuid::v4();
            $meta = ['installation_id' => $installation, 'plugin_id' => $body['plugin_id'], 'version' => $body['version'],
                'size' => $body['size'], 'sha256' => $body['sha256'], 'received' => 0, 'next_index' => 0, 'complete' => false, 'created' => time()];
            $mask = umask(0007);
            try {
                if (@file_put_contents($directory . '/' . $id . '.part', '', LOCK_EX) !== 0
                    || @file_put_contents($directory . '/' . $id . '.json', json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                    $this->discard($id);
                    throw new PackageError('package_storage_unavailable');
                }
            } finally { umask($mask); }
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
        return ['upload_id' => $id, 'next_index' => 0, 'chunk_bytes' => self::CHUNK_BYTES, 'complete' => false];
    }

    /**
     * Shared web/worker allocation lock. The file is created once (group-shared via the setgid parent) and never
     * deleted or replaced, so every process locks the same inode; contention and access failure stay distinct.
     * @return resource
     */
    private function lockUploads(string $directory)
    {
        $path = $directory . '/allocation.lock';
        if (is_link($path)) throw new PackageError('package_storage_unavailable');
        $mask = umask(0007);
        try { $lock = @fopen($path, 'c'); } finally { umask($mask); }
        if ($lock === false) throw new PackageError('package_storage_unavailable');
        $deadline = hrtime(true) + 3_000_000_000;
        while (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (!$wouldBlock) { fclose($lock); throw new PackageError('package_storage_unavailable'); }
            if (hrtime(true) >= $deadline) { fclose($lock); throw new PackageError('package_storage_busy'); }
            usleep(20_000);
        }
        return $lock;
    }

    /** Read upload metadata under its own shared lock so a concurrent chunk rewrite is never seen half-written. */
    private function readMeta(string $file): ?array
    {
        $handle = is_file($file) && !is_link($file) ? @fopen($file, 'r') : false;
        if ($handle === false) return null;
        try {
            if (!flock($handle, LOCK_SH)) return null;
            $meta = json_decode((string) stream_get_contents($handle), true);
            flock($handle, LOCK_UN);
            return is_array($meta) ? $meta : null;
        } finally { fclose($handle); }
    }

    /** Chunks arrive once and in order; the final chunk must reproduce the declared size and SHA-256. */
    public function appendChunk(string $installation, string $uploadId, int $index, string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::CHUNK_BYTES) throw new PackageError('package_invalid_request');
        [$handle, $meta] = $this->openUpload($installation, $uploadId);
        $hashMismatch = false;
        try {
            if ($meta['complete'] || $index !== $meta['next_index']) throw new PackageError('package_upload_out_of_order');
            if ($meta['received'] + strlen($bytes) > $meta['size']) throw new PackageError('package_too_large');
            $part = $this->directory('uploads') . '/' . $uploadId . '.part';
            if (is_link($part) || filesize($part) !== $meta['received'] || @file_put_contents($part, $bytes, FILE_APPEND) !== strlen($bytes)) {
                throw new PackageError('package_storage_unavailable');
            }
            clearstatcache(true, $part);
            $meta['received'] += strlen($bytes); $meta['next_index'] = $index + 1;
            if ($meta['received'] === $meta['size']) {
                $hashMismatch = !hash_equals($meta['sha256'], (string) hash_file('sha256', $part));
                $meta['complete'] = !$hashMismatch;
            }
            if (!$hashMismatch) $this->rewrite($handle, $meta);
        } finally {
            flock($handle, LOCK_UN); fclose($handle);
        }
        if ($hashMismatch) { $this->discard($uploadId); throw new PackageError('package_hash_mismatch'); }
        return ['upload_id' => $uploadId, 'next_index' => $meta['next_index'], 'received' => $meta['received'], 'complete' => $meta['complete']];
    }

    /** Persist the operation and its durable job together; extraction happens later in the worker. */
    public function queue(string $installation, string $operation, array $body): array
    {
        $this->keys($body, ['request_id', 'upload_id'], ['expected_manifest_sha256']);
        $expected = $body['expected_manifest_sha256'] ?? null;
        if ($expected !== null && (!is_string($expected) || preg_match('/^[0-9a-f]{64}$/D', $expected) !== 1)) throw new PackageError('package_invalid_request');
        if (!in_array($operation, ['install', 'update'], true) || !is_string($body['request_id']) || !Uuid::isValid($body['request_id'])) {
            throw new PackageError('package_invalid_request');
        }
        $existing = $this->operationRow($installation, $body['request_id']);
        if ($existing !== null) {
            if ($existing['operation'] !== $operation || $existing['upload_id'] !== $body['upload_id'] || ($existing['expected_manifest_sha256'] ?? null) !== $expected) throw new PackageError('duplicate_conflict');
            return $this->publicOperation($existing);
        }
        [$handle, $meta] = $this->openUpload($installation, (string) $body['upload_id']);
        flock($handle, LOCK_UN); fclose($handle);
        if (!$meta['complete']) throw new PackageError('package_upload_incomplete');
        $this->db->beginTransaction();
        try {
            $this->db->exec("SET LOCAL lock_timeout='3s'");
            $current = $this->packageRow($installation, $meta['plugin_id'], true);
            $this->assertTransition($operation, $current, $meta['version'], $meta['sha256']);
            $this->db->prepare('INSERT INTO plugin_package_operations (operation_id,installation_id,plugin_id,operation,version,archive_sha256) VALUES (:id,:installation,:plugin,:operation,:version,:sha)')
                ->execute(['id' => $body['request_id'], 'installation' => $installation, 'plugin' => $meta['plugin_id'], 'operation' => $operation,
                    'version' => $meta['version'], 'sha' => $meta['sha256']]);
            (new JobRepository($this->db))->enqueue($body['request_id'], self::JOB_TYPE, 1, $body['request_id'],
                ['operation_id' => $body['request_id'], 'installation_id' => $installation, 'upload_id' => $body['upload_id'], 'expected_manifest_sha256' => $expected], 3);
            $this->db->commit();
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($error->getCode() === '23505') {
                throw new PackageError(str_contains($error->getMessage(), 'operations_pkey') ? 'duplicate_conflict' : 'package_operation_pending');
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $this->publicOperation($this->operationRow($installation, $body['request_id']) ?? throw new PackageError('package_operation_not_found'));
    }

    /** Worker step: validate/extract outside any transaction, then switch the active row atomically. */
    public function apply(array $payload, string $serverVersion, bool $finalAttempt): void
    {
        $operationId = (string) ($payload['operation_id'] ?? ''); $installation = (string) ($payload['installation_id'] ?? '');
        $op = Uuid::isValid($operationId) && Uuid::isValid($installation) ? $this->operationRow($installation, $operationId) : null;
        if ($op === null) throw new \InvalidArgumentException('invalid_plugin_package_job');
        if ($op['state'] !== 'queued') return;
        $lock = $this->db->prepare('SELECT pg_try_advisory_lock(7515, hashtext(:key))');
        $lock->execute(['key' => $installation . '|' . $op['plugin_id']]);
        if (!filter_var($lock->fetchColumn(), FILTER_VALIDATE_BOOL)) {
            if ($finalAttempt) $this->finish($operationId, 'package_apply_failed');
            throw new \RuntimeException('plugin_package_busy');
        }
        $stage = $this->directory('staging') . '/' . $operationId; $terminal = true;
        try {
            $this->remove($stage, $this->directory('staging'));
            $archive = $this->directory('uploads') . '/' . $op['upload_id'] . '.part';
            if (!is_file($archive) || is_link($archive) || !hash_equals($op['archive_sha256'], (string) hash_file('sha256', $archive))) {
                throw new PackageError('package_hash_mismatch');
            }
            $package = (new PluginPackageArchive($serverVersion))->extract($archive, $stage);
            if ($package['plugin_id'] !== $op['plugin_id'] || $package['version'] !== $op['version']) throw new PackageError('package_identity_mismatch');
            if (($op['expected_manifest_sha256'] ?? null) !== null && !hash_equals($op['expected_manifest_sha256'], $package['manifest_sha256'])) throw new PackageError('package_manifest_mismatch');
            $tree = $this->directory('store') . '/' . $op['archive_sha256'];
            if (is_link($tree)) throw new PackageError('package_storage_unavailable');
            if (is_dir($tree)) $this->remove($stage, $this->directory('staging'));
            elseif (!@rename($stage, $tree) || !$this->seal($tree)) throw new PackageError('package_storage_unavailable');
            $this->seedMutable($tree, $installation, $package['plugin_id'], $package['mutable_paths']);
            $this->db->beginTransaction();
            try {
                $current = $this->packageRow($installation, $op['plugin_id'], true);
                $this->assertTransition($op['operation'], $current, $op['version'], $op['archive_sha256']);
                $this->db->prepare("INSERT INTO plugin_packages AS p (installation_id,plugin_id,state,enabled,version,archive_sha256,manifest_sha256,manifest,mutable_paths)
                    VALUES (:installation,:plugin,'installed',:enabled,:version,:sha,:manifest_sha,CAST(:manifest AS jsonb),CAST(:mutable AS jsonb))
                    ON CONFLICT (installation_id,plugin_id) DO UPDATE SET state='installed',
                        enabled=CASE WHEN p.state='installed' THEN p.enabled ELSE EXCLUDED.enabled END,
                        previous_version=CASE WHEN p.state='installed' THEN p.version ELSE p.previous_version END,
                        previous_archive_sha256=CASE WHEN p.state='installed' THEN p.archive_sha256 ELSE p.previous_archive_sha256 END,
                        version=EXCLUDED.version,archive_sha256=EXCLUDED.archive_sha256,manifest_sha256=EXCLUDED.manifest_sha256,
                        manifest=EXCLUDED.manifest,mutable_paths=EXCLUDED.mutable_paths,revision=p.revision+1,updated_at=clock_timestamp()")
                    ->execute(['installation' => $installation, 'plugin' => $op['plugin_id'], 'enabled' => $package['manifest']['default_enabled'] ? 't' : 'f',
                        'version' => $op['version'], 'sha' => $op['archive_sha256'], 'manifest_sha' => $package['manifest_sha256'],
                        'manifest' => json_encode($package['manifest'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        'mutable' => json_encode($package['mutable_paths'], JSON_THROW_ON_ERROR)]);
                $this->finish($operationId, null);
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $error;
            }
        } catch (PackageError $error) {
            $this->finish($operationId, $error->getMessage());
        } catch (\Throwable $error) {
            // Transient storage/database errors retry; the last attempt records a terminal code so the package unblocks.
            $terminal = $finalAttempt;
            if ($finalAttempt) $this->finish($operationId, 'package_apply_failed');
            throw $error;
        } finally {
            if (is_dir($stage)) $this->remove($stage, $this->directory('staging'));
            if ($terminal) $this->discard($op['upload_id']);
            $this->db->prepare('SELECT pg_advisory_unlock(7515, hashtext(:key))')->execute(['key' => $installation . '|' . $op['plugin_id']]);
        }
    }

    /** Enable, disable or remove synchronously; removal keeps package trees, mutable data and NPC plugin namespaces. */
    public function change(string $installation, string $pluginId, string $action): array
    {
        if (!in_array($action, ['enable', 'disable', 'remove'], true) || preg_match(self::PLUGIN_ID, $pluginId) !== 1) throw new PackageError('package_invalid_request');
        $this->db->beginTransaction();
        try {
            $this->db->exec("SET LOCAL lock_timeout='3s'");
            $row = $this->packageRow($installation, $pluginId, true);
            if ($row === null || $row['state'] !== 'installed') throw new PackageError('package_not_installed');
            $pending = $this->db->prepare("SELECT 1 FROM plugin_package_operations WHERE installation_id=:installation AND plugin_id=:plugin AND state='queued'");
            $pending->execute(['installation' => $installation, 'plugin' => $pluginId]);
            if ($pending->fetchColumn()) throw new PackageError('package_operation_pending');
            $set = match ($action) { 'enable' => 'enabled=true', 'disable' => 'enabled=false', 'remove' => "state='removed',enabled=false" };
            if (($action === 'enable') !== $row['enabled'] || $action === 'remove') {
                $this->db->prepare("UPDATE plugin_packages SET $set,revision=revision+1,updated_at=clock_timestamp() WHERE installation_id=:installation AND plugin_id=:plugin")
                    ->execute(['installation' => $installation, 'plugin' => $pluginId]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $this->publicPackage($this->packageRow($installation, $pluginId, false));
    }

    /** Compare a candidate with the installed state without changing anything (used by later native sync). */
    public function probe(string $installation, array $body): array
    {
        $this->keys($body, ['plugin_id', 'version'], ['sha256']);
        $this->identity($body['plugin_id'], $body['version']);
        $row = $this->packageRow($installation, $body['plugin_id'], false);
        $pending = $this->db->prepare("SELECT 1 FROM plugin_package_operations WHERE installation_id=:installation AND plugin_id=:plugin AND state='queued'");
        $pending->execute(['installation' => $installation, 'plugin' => $body['plugin_id']]);
        $action = 'install';
        if ($row !== null && $row['state'] === 'installed') {
            $order = PluginContract::compareVersions($body['version'], $row['version']);
            $action = $order > 0 ? 'update' : ($order < 0 ? 'older' : (isset($body['sha256']) && $body['sha256'] !== $row['archive_sha256'] ? 'conflict' : 'current'));
        }
        return ['plugin_id' => $body['plugin_id'], 'action' => $action, 'pending' => (bool) $pending->fetchColumn(),
            'installed' => $row === null ? null : $this->publicPackage($row)];
    }

    public function packages(string $installation): array
    {
        $q = $this->db->prepare('SELECT * FROM plugin_packages WHERE installation_id=:installation ORDER BY plugin_id');
        $q->execute(['installation' => $installation]);
        return array_map(fn(array $row): array => $this->publicPackage($row), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Queued operations plus the latest finished one per addon (last 30 days), newest first, for the manager UI. */
    public function recentOperations(string $installation): array
    {
        $q = $this->db->prepare("SELECT * FROM (SELECT DISTINCT ON (plugin_id) * FROM plugin_package_operations
            WHERE installation_id=:installation AND state<>'queued' AND created_at > clock_timestamp() - interval '30 days'
            ORDER BY plugin_id, created_at DESC) latest
            UNION ALL SELECT * FROM plugin_package_operations WHERE installation_id=:installation AND state='queued'
            ORDER BY created_at DESC LIMIT 50");
        $q->execute(['installation' => $installation]);
        return array_map(fn(array $row): array => $this->publicOperation($row), $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function operation(string $installation, string $operationId): array
    {
        $row = Uuid::isValid($operationId) ? $this->operationRow($installation, $operationId) : null;
        return $row === null ? throw new PackageError('package_operation_not_found') : $this->publicOperation($row);
    }

    /** Inputs for PluginRegistry::apply(): installed manifests with their packaged-byte SHA-256 and admin policy. */
    public function registryInputs(string $installation): array
    {
        $q = $this->db->prepare("SELECT plugin_id,manifest,manifest_sha256,enabled FROM plugin_packages WHERE installation_id=:installation AND state='installed'");
        $q->execute(['installation' => $installation]);
        $installed = []; $policy = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $installed[$row['plugin_id']] = ['manifest' => json_decode($row['manifest'], true, 64, JSON_THROW_ON_ERROR), 'sha256' => $row['manifest_sha256']];
            $policy[$row['plugin_id']] = filter_var($row['enabled'], FILTER_VALIDATE_BOOL);
        }
        return [$installed, $policy];
    }

    private function assertTransition(string $operation, ?array $current, string $version, string $sha): void
    {
        $installed = $current !== null && $current['state'] === 'installed';
        if ($operation === 'install' && $installed) throw new PackageError('package_already_installed');
        if ($operation === 'update' && !$installed) throw new PackageError('package_not_installed');
        if ($operation === 'update' && PluginContract::compareVersions($version, $current['version']) <= 0) throw new PackageError('package_version_not_newer');
    }

    /**
     * Copy packaged data/config defaults into installation data only where nothing exists yet; never overwrite or
     * delete. Every destination component is created or checked without following links, so retained data cannot
     * redirect a write outside the installation's data directory. The immutable tree is only read.
     */
    private function seedMutable(string $tree, string $installation, string $pluginId, array $paths): void
    {
        $data = $this->directory('data');
        $mask = umask(0007);
        try {
            foreach ($paths as $path) {
                $relative = $installation . '/' . $pluginId . '/' . substr($path, strlen('server/'));
                $source = $tree . '/' . $path;
                if (is_link($source) || !file_exists($source)) continue;
                $items = [[$source, $relative]];
                if (is_dir($source)) {
                    $this->dataDirectory($data, $relative);
                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
                    $items = [];
                    foreach ($iterator as $item) $items[] = [$item->getPathname(), $relative . substr($item->getPathname(), strlen($source))];
                }
                foreach ($items as [$from, $to]) {
                    if (is_link($from)) throw new PackageError('package_link_rejected');
                    if (is_dir($from)) { $this->dataDirectory($data, $to); continue; }
                    $this->dataDirectory($data, dirname($to));
                    // 'x' is O_CREAT|O_EXCL: an existing file or link (even dangling) is left untouched.
                    $output = @fopen($data . '/' . $to, 'xb');
                    if ($output === false) {
                        if (file_exists($data . '/' . $to) || is_link($data . '/' . $to)) continue;
                        throw new PackageError('package_storage_unavailable');
                    }
                    $input = @fopen($from, 'rb');
                    $copied = $input !== false && stream_copy_to_stream($input, $output) === filesize($from) && fflush($output);
                    if ($input !== false) fclose($input);
                    fclose($output);
                    // Only the file this call created exclusively is withdrawn, so a retry can seed it again.
                    if (!$copied) { @unlink($data . '/' . $to); throw new PackageError('package_storage_unavailable'); }
                }
            }
        } finally { umask($mask); }
    }

    /** Create each component of a data-relative directory in turn, rejecting links and non-directories. */
    private function dataDirectory(string $data, string $relative): void
    {
        $path = $data;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/' . $segment;
            if (is_link($path)) throw new PackageError('package_link_rejected');
            if (is_dir($path)) continue;
            if (file_exists($path) || (!@mkdir($path, 0770) && !is_dir($path)) || is_link($path)) throw new PackageError('package_storage_unavailable');
        }
    }

    /** Make an activated content-addressed tree read-only: files 0440, directories 0550 (group read for the web account). */
    private function seal(string $tree): bool
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isLink() || !@chmod($item->getPathname(), $item->isDir() ? 0550 : 0440)) return false;
        }
        return @chmod($tree, 0550);
    }

    private function finish(string $operationId, ?string $error): void
    {
        $this->db->prepare("UPDATE plugin_package_operations SET state=:state,error_code=:error,finished_at=clock_timestamp() WHERE operation_id=:id AND state='queued'")
            ->execute(['state' => $error === null ? 'succeeded' : 'failed', 'error' => $error, 'id' => $operationId]);
    }

    private function packageRow(string $installation, string $pluginId, bool $lock): ?array
    {
        $q = $this->db->prepare('SELECT * FROM plugin_packages WHERE installation_id=:installation AND plugin_id=:plugin' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute(['installation' => $installation, 'plugin' => $pluginId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        $row['enabled'] = filter_var($row['enabled'], FILTER_VALIDATE_BOOL);
        return $row;
    }

    private function operationRow(string $installation, string $operationId): ?array
    {
        $q = $this->db->prepare("SELECT o.*,j.payload->>'upload_id' AS upload_id,j.payload->>'expected_manifest_sha256' AS expected_manifest_sha256 FROM plugin_package_operations o
            LEFT JOIN durable_jobs j ON j.job_id=o.operation_id AND j.job_type=:type WHERE o.operation_id=:id AND o.installation_id=:installation");
        $q->execute(['id' => $operationId, 'installation' => $installation, 'type' => self::JOB_TYPE]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function publicPackage(array $row): array
    {
        $manifest = is_array($row['manifest']) ? $row['manifest'] : json_decode((string) $row['manifest'], true, 64, JSON_THROW_ON_ERROR);
        return ['plugin_id' => $row['plugin_id'], 'display_name' => $manifest['display_name'], 'version' => $row['version'],
            'state' => $row['state'], 'enabled' => filter_var($row['enabled'], FILTER_VALIDATE_BOOL), 'archive_sha256' => $row['archive_sha256'],
            'manifest_sha256' => $row['manifest_sha256'], 'previous_version' => $row['previous_version'], 'revision' => (int) $row['revision'],
            'installed_at' => $row['installed_at'], 'updated_at' => $row['updated_at']];
    }

    private function publicOperation(array $row): array
    {
        return ['operation_id' => $row['operation_id'], 'plugin_id' => $row['plugin_id'], 'operation' => $row['operation'],
            'version' => $row['version'], 'archive_sha256' => $row['archive_sha256'], 'state' => $row['state'],
            'error_code' => $row['error_code'], 'created_at' => $row['created_at'], 'finished_at' => $row['finished_at']];
    }

    /** @return array{0:resource,1:array} Locked metadata handle owned by the caller's installation. */
    private function openUpload(string $installation, string $uploadId): array
    {
        if (!Uuid::isValid($uploadId)) throw new PackageError('package_upload_not_found');
        $file = $this->directory('uploads') . '/' . $uploadId . '.json';
        $handle = is_file($file) && !is_link($file) ? @fopen($file, 'r+') : false;
        if ($handle === false) throw new PackageError('package_upload_not_found');
        if (!flock($handle, LOCK_EX)) { fclose($handle); throw new PackageError('package_storage_unavailable'); }
        $meta = json_decode((string) stream_get_contents($handle), true);
        if (!is_array($meta) || !hash_equals((string) ($meta['installation_id'] ?? ''), $installation)) {
            flock($handle, LOCK_UN); fclose($handle);
            throw new PackageError('package_upload_not_found');
        }
        return [$handle, $meta];
    }

    private function rewrite($handle, array $meta): void
    {
        $json = json_encode($meta, JSON_THROW_ON_ERROR);
        if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new PackageError('package_storage_unavailable');
    }

    private function discard(string $uploadId): void
    {
        if (!Uuid::isValid($uploadId)) return;
        foreach (['.part', '.json'] as $suffix) {
            $file = $this->directory('uploads') . '/' . $uploadId . $suffix;
            if (is_file($file) && !is_link($file)) @unlink($file);
        }
    }

    /** Remove a staging tree without following links or leaving the staging root. */
    private function remove(string $path, string $root): void
    {
        if (!str_starts_with($path, $root . '/') || !file_exists($path)) return;
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** Browser management may only act for a known, unrevoked installation. */
    public function assertInstallation(string $installation): void
    {
        $q = $this->db->prepare('SELECT 1 FROM installations WHERE installation_id=:id AND revoked_at IS NULL');
        $q->execute(['id' => $installation]);
        if (!$q->fetchColumn()) throw new PackageError('package_invalid_request');
    }

    private function identity(mixed $pluginId, mixed $version): void
    {
        if (!is_string($pluginId) || preg_match(self::PLUGIN_ID, $pluginId) !== 1 || !is_string($version) || preg_match(self::VERSION, $version) !== 1) {
            throw new PackageError('package_invalid_request');
        }
    }

    private function keys(array $body, array $required, array $optional = []): void
    {
        $keys = array_keys($body);
        if (array_diff($required, $keys) !== [] || array_diff($keys, $required, $optional) !== []) throw new PackageError('package_invalid_request');
    }
}
