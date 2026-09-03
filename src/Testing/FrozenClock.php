<?php

declare(strict_types=1);

namespace IndexNowKit\Testing;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Test double: a clock that only moves when advance() is called.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $at = '2026-09-03 12:00:00')
    {
        $this->now = new DateTimeImmutable($at);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(\sprintf('+%d seconds', $seconds));
    }
}
