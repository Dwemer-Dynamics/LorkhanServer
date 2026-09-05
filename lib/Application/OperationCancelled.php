<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use RuntimeException;

final class OperationCancelled extends RuntimeException
{
    public function __construct(string $reason = 'operation_cancelled')
    {
        parent::__construct($reason);
    }
}
