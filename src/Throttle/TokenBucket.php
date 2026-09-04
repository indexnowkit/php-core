<?php

declare(strict_types=1);

namespace IndexNowKit\Throttle;

use IndexNowKit\Clock\SystemClock;
use IndexNowKit\Config;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Per-process token bucket: at most N requests per minute, blocking (usleep) when exhausted.
 *
 * 0 means unlimited. Cross-process throttling is the queue's job. The wait is a blocking usleep() without an
 * upper bound: in a web request it only kicks in when one request sends more than N batches (N × 10 000 URLs),
 * so keep max_requests_per_minute well above the number of batches a request can produce, or use NullThrottle
 * there and throttle in the queue worker instead.
 */
final class TokenBucket implements ThrottleInterface
{
    private const MICROSECONDS_PER_MINUTE = 60_000_000;

    private readonly ClockInterface $clock;
    private float $tokens;
    private float $lastRefill;

    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper receives microseconds; injectable for tests
     */
    public function __construct(
        private readonly int $perMinute,
        ?ClockInterface $clock = null,
        ?callable $sleeper = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->tokens = (float) $perMinute;
        $this->lastRefill = $this->nowMicro();
        $this->sleeper = $sleeper ?? static fn(int $us) => usleep($us);
    }

    /**
     * The throttle an adapter wires: `throttle.max_requests_per_minute`, system clock, real sleep.
     */
    public static function fromConfig(Config $config, LoggerInterface $logger = new NullLogger()): self
    {
        return new self($config->throttleMaxRequestsPerMinute, logger: $logger);
    }

    public function acquire(): void
    {
        if ($this->perMinute <= 0) {
            return;
        }
        $this->refill();
        if ($this->tokens < 1.0) {
            $deficit = 1.0 - $this->tokens;
            $waitMicro = (int) ceil($deficit * (self::MICROSECONDS_PER_MINUTE / $this->perMinute));
            $this->logger->debug('indexnow: throttle limit of {per_minute} requests/min reached, waiting {wait_ms} ms', ['per_minute' => $this->perMinute, 'wait_ms' => intdiv($waitMicro, 1000)]);
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
