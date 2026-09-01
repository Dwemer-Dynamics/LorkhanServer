<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

final class CallbackCancellationToken implements CancellationToken
{
    /** @param callable():bool $cancelled */
    public function __construct(private readonly mixed $cancelled) {}

    public function isCancellationRequested(): bool
    {
        return (bool) ($this->cancelled)();
    }

    public function throwIfCancellationRequested(): void
    {
        if ($this->isCancellationRequested()) {
            throw new OperationCancelled();
        }
    }
}
