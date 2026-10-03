<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

use LorkhanServer\Protocol\PluginContract;
use LorkhanServer\Protocol\ValidationException;
use LorkhanServer\Protocol\Validator;
use ZipArchive;

/**
 * Validate and extract one schema-4 .dwpkg carrying a server-only LORKHAN addon. Nothing extracted is executed.
 * Every bound is checked against the central directory first and then against the bytes actually inflated.
 */
final class PluginPackageArchive
{
    public const SCHEMA_VERSION = 4;
    public const CONTRACT_PATH = 'server/lorkhan-plugin.json';
    public const MAX_ARCHIVE_BYTES = 67_108_864;
    public const MAX_UNCOMPRESSED_BYTES = 268_435_456;
    public const MAX_ENTRIES = 2000;
    private const MAX_RATIO = 100;
    private const MAX_DEPTH = 16;
    private const MAX_MUTABLE_PATHS = 32;
    private const MANIFEST_KEYS = ['author', 'description', 'display_name', 'name', 'schema_version', 'server', 'version'];
    private const SEGMENT = '/^[A-Za-z0-9_+-](?:[A-Za-z0-9 ._+-]{0,126}[A-Za-z0-9_+-])?$/D';
    // Server-only: no native executables/libraries or game plugins/content archives, by name or by leading bytes.
    private const BINARY_NAME = '/\.(?:exe|dll|sys|com|scr|msi|asi|ocx|cpl|drv|efi|dylib|so(?:\.[0-9]+)*|esm|esp|esl|omwaddon|omwgame|bsa|ba2)$/iD';
    private const BINARY_MAGIC = ['MZ', "\x7fELF", "\xfe\xed\xfa\xce", "\xfe\xed\xfa\xcf", "\xce\xfa\xed\xfe", "\xcf\xfa\xed\xfe", "\xca\xfe\xba\xbe", 'TES3', 'TES4', "BSA\x00"];
    // Installation-owned copies may hold only data/config documents, never hooks or the contract manifest.
    private const MUTABLE_ROOT = '#^server/(?:data|config)(?:/|$)#D';
    private const MUTABLE_FILE = '/\.(?:json|txt|csv|md|yaml|yml|toml)$/iD';

    public function __construct(private readonly string $serverVersion, private readonly Validator $validator = new Validator())
    {
    }

    /**
     * Extract into a directory that must not exist yet.
     * @return array{plugin_id:string,version:string,manifest:array,manifest_sha256:string,mutable_paths:list<string>,files:int}
     */
    public function extract(string $archive, string $stage): array
    {
        if (!class_exists(ZipArchive::class)) throw new PluginPackageException('package_storage_unavailable');
        if (!is_file($archive) || is_link($archive) || filesize($archive) < 22) throw new PluginPackageException('package_archive_invalid');
        if (filesize($archive) > self::MAX_ARCHIVE_BYTES) throw new PluginPackageException('package_too_large');
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new PluginPackageException('package_archive_invalid');
        try {
            $entries = $this->entries($zip);
            $manifest = $this->json($zip, $entries, 'manifest.json', 65_536, 'package_manifest_invalid');
            $contractBytes = $this->read($zip, $entries, self::CONTRACT_PATH, PluginContract::MAX_MANIFEST_BYTES, 'package_contract_invalid');
            try {
                $contract = $this->validator->decode($contractBytes, PluginContract::MAX_MANIFEST_BYTES);
                (new PluginContract($this->validator))->validate($contract, PluginContract::MANIFEST);
            } catch (ValidationException) {
                throw new PluginPackageException('package_contract_invalid');
            }
            $mutable = $this->manifest($manifest, $entries);
            if ($contract['plugin_id'] !== $manifest['name'] || $contract['version'] !== $manifest['version']) {
                throw new PluginPackageException('package_identity_mismatch');
            }
            if (PluginContract::compareVersions($contract['compatibility']['min_server_version'], $this->serverVersion) > 0) {
                throw new PluginPackageException('package_incompatible');
            }
            $expected = $this->checksums($this->read($zip, $entries, 'checksums.sha256', 1_048_576, 'package_checksums_invalid'), $entries);
            $this->write($zip, $entries, $expected, $stage);
            return ['plugin_id' => $manifest['name'], 'version' => $manifest['version'], 'manifest' => $contract,
                'manifest_sha256' => hash('sha256', $contractBytes), 'mutable_paths' => $mutable,
                'files' => count(array_filter($entries, static fn(array $e): bool => !$e['dir']))];
        } finally {
            $zip->close();
        }
    }

    /** Normalize a package-relative path: ASCII segments only, so case/Unicode aliases cannot collide on disk. */
    public static function path(string $name): string
    {
        $trimmed = str_ends_with($name, '/') ? substr($name, 0, -1) : $name;
        $segments = explode('/', $trimmed);
        if ($trimmed === '' || strlen($trimmed) > 240 || count($segments) > self::MAX_DEPTH) throw new PluginPackageException('package_path_unsafe');
        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT, $segment) !== 1 || $segment === '..' || str_ends_with($segment, '.')) {
                throw new PluginPackageException('package_path_unsafe');
            }
        }
        return $trimmed;
    }

    /** @return array<string,array{index:int,dir:bool,size:int}> */
    private function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 3 || $zip->numFiles > self::MAX_ENTRIES) throw new PluginPackageException('package_archive_invalid');
        $entries = []; $folded = []; $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
            if (!is_array($stat) || !is_string($stat['name'] ?? null)) throw new PluginPackageException('package_archive_invalid');
            $dir = str_ends_with($stat['name'], '/');
            $path = self::path($stat['name']);
            if (($stat['encryption_method'] ?? 0) !== 0 || !in_array($stat['comp_method'] ?? -1, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new PluginPackageException('package_archive_invalid');
            }
            $opsys = 0; $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                $type = ($attributes >> 16) & 0xF000;
                if (!in_array($type, [0, $dir ? 0x4000 : 0x8000], true)) throw new PluginPackageException('package_link_rejected');
            }
            $size = (int) $stat['size'];
            $total += $size;
            if ($dir && $size !== 0) throw new PluginPackageException('package_archive_invalid');
            if ($total > self::MAX_UNCOMPRESSED_BYTES || ($size > 1_048_576 && $size > self::MAX_RATIO * max(1, (int) $stat['comp_size']))) {
                throw new PluginPackageException('package_bomb_rejected');
            }
            if (isset($entries[$path])) throw new PluginPackageException('package_duplicate_entry');
            if (isset($folded[strtolower($path)])) throw new PluginPackageException('package_path_collision');
            $entries[$path] = ['index' => $i, 'dir' => $dir, 'size' => $size];
            $folded[strtolower($path)] = true;
        }
        // A file may not also be the parent of another entry; that would let one entry redirect another.
        foreach ($entries as $path => $_) {
            for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($entries[$parent]) && !$entries[$parent]['dir']) throw new PluginPackageException('package_path_collision');
            }
        }
        return $entries;
    }

    private function read(ZipArchive $zip, array $entries, string $path, int $limit, string $code): string
    {
        $entry = $entries[$path] ?? null;
        if ($entry === null || $entry['dir'] || $entry['size'] < 2 || $entry['size'] > $limit) throw new PluginPackageException($code);
        $bytes = $zip->getFromIndex($entry['index'], $limit + 1);
        if (!is_string($bytes) || strlen($bytes) !== $entry['size']) throw new PluginPackageException($code);
        return $bytes;
    }

    private function json(ZipArchive $zip, array $entries, string $path, int $limit, string $code): array
    {
        try {
            $value = json_decode($this->read($zip, $entries, $path, $limit, $code), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginPackageException($code);
        }
        if (!is_array($value) || array_is_list($value)) throw new PluginPackageException($code);
        return $value;
    }

    /** @return list<string> */
    private function manifest(array $manifest, array $entries): array
    {
        $keys = array_keys($manifest); sort($keys);
        $server = $manifest['server'] ?? null;
        if (array_diff($keys, self::MANIFEST_KEYS) !== [] || ($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_string($manifest['name'] ?? null) || !is_string($manifest['version'] ?? null)
            || !is_array($server) || array_diff(array_keys($server), ['mutable_paths']) !== []) {
            throw new PluginPackageException('package_manifest_invalid');
        }
        foreach (['display_name', 'description', 'author'] as $text) {
            if (isset($manifest[$text]) && (!is_string($manifest[$text]) || mb_strlen($manifest[$text]) > 512 || preg_match('/[\x00-\x1f\x7f]/', $manifest[$text]))) {
                throw new PluginPackageException('package_manifest_invalid');
            }
        }
        $mutable = $server['mutable_paths'] ?? [];
        if (!is_array($mutable) || !array_is_list($mutable) || count($mutable) > self::MAX_MUTABLE_PATHS) throw new PluginPackageException('package_manifest_invalid');
        $normalized = [];
        foreach ($mutable as $path) {
            $path = is_string($path) ? self::path($path) : '';
            if (preg_match(self::MUTABLE_ROOT, $path) !== 1 || isset($normalized[$path])) throw new PluginPackageException('package_manifest_invalid');
            $normalized[$path] = true;
        }
        foreach ($entries as $path => $entry) {
            if (!in_array($path, ['manifest.json', 'checksums.sha256', 'server'], true) && !str_starts_with($path, 'server/')) {
                throw new PluginPackageException('package_payload_unsupported');
            }
            if (!$entry['dir'] && preg_match(self::BINARY_NAME, $path) === 1) throw new PluginPackageException('package_payload_unsupported');
            if ($entry['dir'] || preg_match(self::MUTABLE_FILE, $path) === 1) continue;
            foreach ($normalized as $root => $_) {
                if ($path === $root || str_starts_with($path, $root . '/')) throw new PluginPackageException('package_manifest_invalid');
            }
        }
        return array_keys($normalized);
    }

    /** @return array<string,string> Every non-directory entry except checksums.sha256 must be listed exactly once. */
    private function checksums(string $bytes, array $entries): array
    {
        $expected = [];
        foreach (preg_split('/\r?\n/', rtrim($bytes, "\r\n")) as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/D', $line, $m) !== 1) throw new PluginPackageException('package_checksums_invalid');
            $path = self::path($m[2]);
            if (isset($expected[$path]) || $path === 'checksums.sha256') throw new PluginPackageException('package_checksums_invalid');
            $expected[$path] = $m[1];
        }
        $files = array_keys(array_filter($entries, static fn(array $e): bool => !$e['dir']));
        $files = array_values(array_diff($files, ['checksums.sha256']));
        sort($files); $listed = array_keys($expected); sort($listed);
        if ($files !== $listed) throw new PluginPackageException('package_checksums_invalid');
        return $expected;
    }

    private function write(ZipArchive $zip, array $entries, array $expected, string $stage): void
    {
        $mask = umask(0027);
        try {
            if (file_exists($stage) || !mkdir($stage, 0750, true)) throw new PluginPackageException('package_storage_unavailable');
            foreach ($entries as $path => $entry) {
                $target = $stage . '/' . $path;
                if ($entry['dir']) {
                    if (!is_dir($target) && !mkdir($target, 0750, true)) throw new PluginPackageException('package_storage_unavailable');
                    continue;
                }
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0750, true)) throw new PluginPackageException('package_storage_unavailable');
                $input = $zip->getStreamIndex($entry['index']);
                $output = @fopen($target, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new PluginPackageException(is_resource($input) ? 'package_storage_unavailable' : 'package_archive_invalid');
                }
                $hash = hash_init('sha256'); $written = 0;
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 65_536);
                        if ($chunk === false) throw new PluginPackageException('package_archive_invalid');
                        if ($written === 0) {
                            foreach (self::BINARY_MAGIC as $magic) {
                                if (str_starts_with($chunk, $magic)) throw new PluginPackageException('package_payload_unsupported');
                            }
                        }
                        $written += strlen($chunk);
                        if ($written > $entry['size']) throw new PluginPackageException('package_bomb_rejected');
                        hash_update($hash, $chunk);
                        if (fwrite($output, $chunk) !== strlen($chunk)) throw new PluginPackageException('package_storage_unavailable');
                    }
                } finally {
                    fclose($input); fclose($output);
                }
                if ($written !== $entry['size']) throw new PluginPackageException('package_archive_invalid');
                if ($path !== 'checksums.sha256' && !hash_equals($expected[$path], hash_final($hash))) {
                    throw new PluginPackageException('package_checksum_mismatch');
                }
            }
        } finally {
            umask($mask);
        }
    }
}
