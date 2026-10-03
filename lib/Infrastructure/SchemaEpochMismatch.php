<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use RuntimeException;

/** Typed incompatibility; the message is a stable machine code such as schema_epoch_legacy. */
final class SchemaEpochMismatch extends RuntimeException
{
    public function __construct(public readonly string $state)
    {
        parent::__construct('schema_epoch_' . $state);
    }
}
