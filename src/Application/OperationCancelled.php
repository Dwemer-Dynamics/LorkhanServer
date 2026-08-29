<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use RuntimeException;

final class OperationCancelled extends RuntimeException
{
    public function __construct(string $reason = 'operation_cancelled')
    {
        parent::__construct($reason);
    }
}
