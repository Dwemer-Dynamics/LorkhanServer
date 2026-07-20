<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

final class NeverCancelledToken implements CancellationToken
{
    public function isCancellationRequested(): bool
    {
        return false;
    }

    public function throwIfCancellationRequested(): void {}
}
