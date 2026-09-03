<?php

declare(strict_types=1);

namespace IndexNowKit\Throttle;

use IndexNowKit\Clock\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Per-process token bucket: at most N requests per minute. Blocks (usleep) when exhausted.
 * With 0 = unlimited. Cross-process throttling is the queue's job.
 */
final class TokenBucket
{
    private float $tokens;
    private float $lastRefill;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper microseconds sleeper, injectable for tests
     */
    public function __construct(
        private readonly int $perMinute,
        private readonly ClockInterface $clock = new SystemClock(),
        ?callable $sleeper = null,
    ) {
        $this->tokens = (float) $perMinute;
        $this->lastRefill = $this->nowMicro();
        $this->sleeper = $sleeper ?? static fn(int $us) => usleep($us);
    }

    public function acquire(): void
    {
        if ($this->perMinute <= 0) {
            return;
        }
        $this->refill();
        if ($this->tokens < 1.0) {
            $deficit = 1.0 - $this->tokens;
            $waitMicro = (int) ceil($deficit * (60_000_000 / $this->perMinute));
            ($this->sleeper)($waitMicro);
            $this->refill($waitMicro / 1_000_000);
        }
        $this->tokens = max(0.0, $this->tokens - 1.0);
    }

    private function refill(float $extraSeconds = 0.0): void
    {
        $now = $this->nowMicro() + $extraSeconds;
        $elapsed = max(0.0, $now - $this->lastRefill);
        $this->tokens = min((float) $this->perMinute, $this->tokens + $elapsed * ($this->perMinute / 60));
        $this->lastRefill = $now;
    }

    private function nowMicro(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
