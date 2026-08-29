<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

interface CancellationToken
{
    public function isCancellationRequested(): bool;

    /** @throws OperationCancelled */
    public function throwIfCancellationRequested(): void;
}
