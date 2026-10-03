<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

use Closure;
use LorkhanServer\Infrastructure\PluginPackageRepository;
use LorkhanServer\Infrastructure\Uuid;
use LorkhanServer\Protocol\PluginContract;
use LorkhanServer\Security\OutboundUrlPolicy;

/**
 * The bundled, curated LORKHAN server plugin catalog (schema 1). The file ships with the server and is reviewed in
 * source; requests may only name an entry ID, never a URL. Each entry pins product, game, API, version, size and
 * SHA-256, and its HTTPS URL must use a host the same file allowlists. Downloads never follow redirects, pin the
 * checked DNS answers, refuse private addresses and stop at the declared size. Nothing downloaded is executed here:
 * verified bytes go through the normal upload and install job.
 */
final class PluginCatalog
{
    public const SCHEMA_VERSION = 1;
    private const KEYS = ['api_version', 'download_hosts', 'entries', 'game', 'product', 'schema_version'];
    private const ENTRY_KEYS = ['author', 'compatibility', 'description', 'display_name', 'id', 'plugin_id', 'sha256', 'size', 'url', 'version'];
    private const COMPATIBILITY_KEYS = ['api_version', 'game', 'min_server_version', 'product'];
    private const MAX_ENTRIES = 200;
    private const PLUGIN_ID = '/^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]{1,47}$/D';
    private const VERSION = '/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D';

    /** @var list<array>|null */
    private ?array $entries = null;
    private array $hosts = [];

    /** @param Closure(string $url, array $hosts, int $maxBytes, resource $sink): int|null $fetch returns the HTTP status */
    public function __construct(private readonly string $file, private readonly string $serverVersion, private readonly ?Closure $fetch = null)
    {
    }

    /** Public entry metadata; the whole catalog is refused when any part of it is invalid. */
    public function entries(): array
    {
        return array_map(fn(array $entry): array => [
            'id' => $entry['id'], 'plugin_id' => $entry['plugin_id'], 'version' => $entry['version'],
            'display_name' => $entry['display_name'], 'description' => $entry['description'], 'author' => $entry['author'],
            'size' => $entry['size'], 'sha256' => $entry['sha256'],
            'compatible' => PluginContract::compareVersions($entry['compatibility']['min_server_version'], $this->serverVersion) <= 0,
        ], $this->load());
    }

    /** Raw validated entry for one ID. */
    public function entry(string $id): array
    {
        foreach ($this->load() as $entry) if ($entry['id'] === $id) return $entry;
        throw new PluginPackageException('catalog_entry_not_found');
    }

    /**
     * Install or update from one catalog entry through the normal chunked upload and install job. A replayed
     * request_id returns its existing operation without downloading again. Older, same-version and conflicting
     * entries are refused before any download.
     */
    public function install(PluginPackageRepository $packages, string $installation, array $body): array
    {
        if (!$this->closed($body, ['entry_id', 'request_id']) || !is_string($body['entry_id']) || !is_string($body['request_id']) || !Uuid::isValid($body['request_id'])) throw new PluginPackageException('package_invalid_request');
        $packages->assertInstallation($installation);
        $entry = $this->entry($body['entry_id']);
        try {
            $existing = $packages->operation($installation, $body['request_id']);
        } catch (PluginPackageException $error) {
            if ($error->getMessage() !== 'package_operation_not_found') throw $error;
            $existing = null;
        }
        if ($existing !== null) {
            if ($existing['plugin_id'] !== $entry['plugin_id'] || $existing['version'] !== $entry['version'] || $existing['archive_sha256'] !== $entry['sha256']) {
                throw new PluginPackageException('duplicate_conflict');
            }
            return $existing;
        }
        $probe = $packages->probe($installation, ['plugin_id' => $entry['plugin_id'], 'version' => $entry['version'], 'sha256' => $entry['sha256']]);
        if ($probe['pending']) throw new PluginPackageException('package_operation_pending');
        $operation = match ($probe['action']) {
            'install', 'update' => $probe['action'],
            'older' => throw new PluginPackageException('package_version_not_newer'),
            default => throw new PluginPackageException('package_already_installed'),
        };
        $stream = $this->download($entry);
        try {
            $upload = $packages->startUpload($installation, ['plugin_id' => $entry['plugin_id'], 'version' => $entry['version'],
                'size' => $entry['size'], 'sha256' => $entry['sha256']]);
            for ($index = 0; !feof($stream); ++$index) {
                $chunk = (string) fread($stream, PluginPackageRepository::CHUNK_BYTES);
                if ($chunk === '') break;
                $packages->appendChunk($installation, $upload['upload_id'], $index, $chunk);
            }
        } finally {
            fclose($stream);
        }
        return $packages->queue($installation, $operation, ['request_id' => $body['request_id'], 'upload_id' => $upload['upload_id']]);
    }

    /**
     * Download one entry into a bounded temporary stream and verify its declared size and SHA-256.
     * @return resource rewound stream holding exactly the verified bytes
     */
    public function download(array $entry)
    {
        if (PluginContract::compareVersions($entry['compatibility']['min_server_version'], $this->serverVersion) > 0) {
            throw new PluginPackageException('package_incompatible');
        }
        $sink = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($sink === false) throw new PluginPackageException('package_storage_unavailable');
        try {
            try {
                $status = ($this->fetch ?? Closure::fromCallable([self::class, 'curl']))($entry['url'], $this->hosts, $entry['size'], $sink);
            } catch (\InvalidArgumentException) {
                throw new PluginPackageException('catalog_download_rejected');
            }
            if ($status >= 300 && $status < 400) throw new PluginPackageException('catalog_redirect_rejected');
            if ($status !== 200) throw new PluginPackageException('catalog_download_failed');
            $size = (fstat($sink) ?: [])['size'] ?? -1;
            if ($size > $entry['size']) throw new PluginPackageException('package_too_large');
            if ($size !== $entry['size'] || !rewind($sink)) throw new PluginPackageException('catalog_download_failed');
            $hash = hash_init('sha256');
            hash_update_stream($hash, $sink);
            if (!hash_equals($entry['sha256'], hash_final($hash))) throw new PluginPackageException('package_hash_mismatch');
            rewind($sink);
            return $sink;
        } catch (\Throwable $error) {
            fclose($sink);
            throw $error;
        }
    }

    /** HTTPS GET with checked/pinned DNS, no redirects, no proxy and a hard byte ceiling. */
    private static function curl(string $url, array $hosts, int $maxBytes, $sink): int
    {
        $options = OutboundUrlPolicy::curlOptions($url, $hosts, false, true);
        $handle = curl_init($url);
        if ($handle === false) throw new PluginPackageException('catalog_download_failed');
        $received = 0;
        curl_setopt_array($handle, $options + [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'], CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($sink, $maxBytes, &$received): int {
                $received += strlen($chunk);
                // A short count aborts the transfer as soon as the declared size is exceeded.
                if ($received > $maxBytes) return 0;
                return fwrite($sink, $chunk) === strlen($chunk) ? strlen($chunk) : 0;
            },
        ]);
        try {
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($handle);
        } finally {
            curl_close($handle);
        }
        if ($received > $maxBytes || $errno === CURLE_FILESIZE_EXCEEDED) throw new PluginPackageException('package_too_large');
        return $ok === false ? 0 : $status;
    }

    /** @return list<array> */
    private function load(): array
    {
        if ($this->entries !== null) return $this->entries;
        $bytes = is_file($this->file) && !is_link($this->file) ? @file_get_contents($this->file, false, null, 0, 262_145) : false;
        try {
            $catalog = is_string($bytes) && strlen($bytes) <= 262_144 ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $catalog = null;
        }
        if (!is_array($catalog) || !$this->closed($catalog, self::KEYS) || $catalog['schema_version'] !== self::SCHEMA_VERSION
            || $catalog['product'] !== 'lorkhan' || $catalog['game'] !== 'tes3' || $catalog['api_version'] !== 1
            || !is_array($catalog['download_hosts']) || !array_is_list($catalog['download_hosts'])
            || !is_array($catalog['entries']) || !array_is_list($catalog['entries']) || count($catalog['entries']) > self::MAX_ENTRIES) {
            throw new PluginPackageException('catalog_unavailable');
        }
        foreach ($catalog['download_hosts'] as $host) {
            if (!is_string($host) || preg_match('/^(?=.{4,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host) !== 1) {
                throw new PluginPackageException('catalog_unavailable');
            }
        }
        $ids = [];
        foreach ($catalog['entries'] as $entry) {
            if (!$this->validEntry($entry, $catalog['download_hosts']) || isset($ids[$entry['id']])) throw new PluginPackageException('catalog_unavailable');
            $ids[$entry['id']] = true;
        }
        $this->hosts = $catalog['download_hosts'];
        return $this->entries = $catalog['entries'];
    }

    private function validEntry(mixed $entry, array $hosts): bool
    {
        if (!is_array($entry) || !$this->closed($entry, self::ENTRY_KEYS) || !is_array($entry['compatibility'])
            || !$this->closed($entry['compatibility'], self::COMPATIBILITY_KEYS)) return false;
        $compatibility = $entry['compatibility'];
        $url = is_string($entry['url']) ? parse_url($entry['url']) : false;
        return is_string($entry['id']) && preg_match('/^[a-z0-9][a-z0-9.-]{2,79}$/D', $entry['id']) === 1
            && is_string($entry['plugin_id']) && preg_match(self::PLUGIN_ID, $entry['plugin_id']) === 1 && !str_starts_with($entry['plugin_id'], 'lorkhan.')
            && is_string($entry['version']) && preg_match(self::VERSION, $entry['version']) === 1
            && $this->text($entry['display_name'], 1, 80) && $this->text($entry['description'], 0, 280) && $this->text($entry['author'], 1, 80)
            && is_int($entry['size']) && $entry['size'] >= 22 && $entry['size'] <= PluginPackageArchive::MAX_ARCHIVE_BYTES
            && is_string($entry['sha256']) && preg_match('/^[0-9a-f]{64}$/D', $entry['sha256']) === 1
            && is_array($url) && ($url['scheme'] ?? '') === 'https' && in_array($url['host'] ?? '', $hosts, true)
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['port']) && !isset($url['fragment']) && strlen($entry['url']) <= 512
            && $compatibility['product'] === 'lorkhan' && $compatibility['game'] === 'tes3' && $compatibility['api_version'] === 1
            && is_string($compatibility['min_server_version']) && preg_match(self::VERSION, $compatibility['min_server_version']) === 1;
    }

    private function closed(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        return $actual === $keys;
    }

    private function text(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && mb_strlen($value) >= $min && mb_strlen($value) <= $max && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
