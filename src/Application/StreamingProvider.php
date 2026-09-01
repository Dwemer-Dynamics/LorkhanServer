<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

interface StreamingProvider extends Provider
{
    /** @param callable(string):void $onDialogueDelta */
    public function completeStreaming(array $turn, CancellationToken $cancellation, callable $onDialogueDelta): array;
}
