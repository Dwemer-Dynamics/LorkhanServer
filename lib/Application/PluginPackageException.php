<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

/** Stable, path-free package lifecycle failure code; the message is the only public detail. */
final class PluginPackageException extends \RuntimeException
{
    private const STATUS = [
        'package_invalid_request' => 422, 'package_upload_not_found' => 404, 'package_operation_not_found' => 404,
        'package_not_installed' => 404, 'package_route_not_found' => 404,'package_too_large' => 413, 'package_storage_full' => 507,
        'package_storage_unavailable' => 503, 'package_storage_busy' => 503,'package_upload_out_of_order' => 409, 'package_upload_incomplete' => 409,
        'package_operation_pending' => 409, 'package_already_installed' => 409, 'package_version_not_newer' => 409,
        'duplicate_conflict' => 409,
    ];

    public function __construct(string $code)
    {
        parent::__construct(preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) === 1 ? $code : 'package_archive_invalid');
    }

    /** Validation failures are deterministic client errors; storage failures are retriable service errors. */
    public function status(): int
    {
        return self::STATUS[$this->getMessage()] ?? 422;
    }
}
