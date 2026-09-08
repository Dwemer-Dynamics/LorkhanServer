<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

interface StreamingProvider extends Provider
{
    /** @param callable $onDialogueDelta Receives text and, when available, an optional normalized speech language. */
    public function completeStreaming(array $turn, CancellationToken $cancellation, callable $onDialogueDelta): array;
}
