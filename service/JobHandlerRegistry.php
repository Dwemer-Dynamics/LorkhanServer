<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use RuntimeException;

final class JobHandlerRegistry
{
    /** @param list<JobHandler> $handlers */
    public function __construct(private readonly array $handlers) {}

    public function for(string $jobType, int $schemaVersion): JobHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($jobType, $schemaVersion)) {
                return $handler;
            }
        }
        throw new RuntimeException('unsupported_job_type');
    }
}
