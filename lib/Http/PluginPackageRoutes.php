<?php
declare(strict_types=1);

namespace LorkhanServer\Http;

use LorkhanServer\Application\PluginPackageException;
use LorkhanServer\Infrastructure\PluginPackageRepository;

/**
 * Package lifecycle routes shared by the paired native API and the session+CSRF management API.
 * Callers authenticate first and pass the installation that owns every package, upload and operation.
 */
final class PluginPackageRoutes
{
    private const MAX_JSON_BYTES = 16_384;

    public function __construct(private readonly PluginPackageRepository $packages) {}

    public static function matches(string $path): bool
    {
        return $path === '/plugin-packages' || str_starts_with($path, '/plugin-packages/');
    }

    /** Collapse opaque IDs so rate limits apply per route shape. */
    public static function rateLimitRoute(string $path): string
    {
        return preg_replace(['#/uploads/[0-9a-f-]{36}/chunks/[0-9]+$#D', '#/operations/[0-9a-f-]{36}$#D', '#^/plugin-packages/[a-z0-9_]+\.[a-z0-9_]+/#'],
            ['/uploads/{id}/chunks/{index}', '/operations/{id}', '/plugin-packages/{plugin_id}/'], $path) ?? $path;
    }

    /** @return array{0:int,1:array} */
    public function dispatch(Request $r, string $path, string $installation, ?string $idempotencyKey = null): array
    {
        if ($r->method === 'GET' && $path === '/plugin-packages') return [200, ['packages' => $this->packages->packages($installation)]];
        if ($r->method === 'GET' && preg_match('#^/plugin-packages/operations/([0-9a-f-]{36})$#D', $path, $m)) {
            return [200, ['operation' => $this->packages->operation($installation, $m[1])]];
        }
        if ($r->method === 'POST' && $path === '/plugin-packages/probe') return [200, $this->packages->probe($installation, $this->json($r))];
        if ($r->method === 'POST' && $path === '/plugin-packages/uploads') return [201, $this->packages->startUpload($installation, $this->json($r))];
        if ($r->method === 'PUT' && preg_match('#^/plugin-packages/uploads/([0-9a-f-]{36})/chunks/(0|[1-9][0-9]{0,4})$#D', $path, $m)) {
            if (strtolower(trim((string) $r->header('Content-Type'))) !== 'application/octet-stream') throw new PluginPackageException('package_invalid_request');
            return [200, $this->packages->appendChunk($installation, $m[1], (int) $m[2], $r->body)];
        }
        if ($r->method === 'POST' && preg_match('#^/plugin-packages/(install|update)$#D', $path, $m)) {
            $body = $this->json($r);
            if ($idempotencyKey !== null && ($body['request_id'] ?? null) !== $idempotencyKey) throw new PluginPackageException('package_invalid_request');
            return [202, ['operation' => $this->packages->queue($installation, $m[1], $body)]];
        }
        if ($r->method === 'POST' && preg_match('#^/plugin-packages/([a-z0-9_.]{4,80})/(enable|disable|remove)$#D', $path, $m)) {
            if ($r->body !== '' && $this->json($r) !== []) throw new PluginPackageException('package_invalid_request');
            return [200, ['package' => $this->packages->change($installation, $m[1], $m[2])]];
        }
        throw new PluginPackageException('package_route_not_found');
    }

    private function json(Request $r): array
    {
        $type = (string) $r->header('Content-Type');
        if (preg_match('#^application/json(?:\s*;\s*charset=utf-8)?$#iD', trim($type)) !== 1 || strlen($r->body) > self::MAX_JSON_BYTES) {
            throw new PluginPackageException('package_invalid_request');
        }
        try {
            $value = json_decode($r->body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginPackageException('package_invalid_request');
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || preg_match('/^\s*\{/', $r->body) !== 1) {
            throw new PluginPackageException('package_invalid_request');
        }
        return $value;
    }
}
