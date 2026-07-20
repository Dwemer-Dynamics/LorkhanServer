<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

interface CancellationToken
{
    public function isCancellationRequested(): bool;

    /** @throws OperationCancelled */
    public function throwIfCancellationRequested(): void;
}
