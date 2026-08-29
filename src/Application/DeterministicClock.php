<?php

declare(strict_types=1);

namespace LORKHANserver\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class DeterministicClock
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        if ($seconds < 0 || $seconds > 31_536_000) {
            throw new InvalidArgumentException('Invalid clock advance.');
        }
        $this->now = $this->now->modify('+' . $seconds . ' seconds');
    }

    public function iso(): string
    {
        return $this->now->format('Y-m-d\TH:i:s\Z');
    }
}
