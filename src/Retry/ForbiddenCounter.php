<?php

declare(strict_types=1);

namespace IndexNowKit\Retry;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Consecutive 403s per host, and the one crossing of the escalation threshold per streak. `Client` counts here on
 * every 403 and resets on every other answer; `indexnow:status` reads the counters back.
 *
 * With a PSR-16 cache the counter and the escalation flag live under `<prefix>403.<host>` and `…_escalated` with a
 * TTL, shared by every process of the application, so a fleet of workers escalates once. Without a cache, or while it
 * fails (logged once, the process counts on), the counter lives in the process.
 */
final class ForbiddenCounter
{
    /** Default TTL: a 403 streak older than an hour without a new 403 is forgotten. */
    public const TTL = 3600;

    /** @var array<string, int> host => consecutive 403 count (without a cache, or while it is unavailable) */
    private array $counts = [];
    private bool $warned = false;

    /**
     * @param CacheInterface|null $cache     PSR-16 cache shared by every process (the adapters pass the `debounce.store`
     *                                       cache); null keeps the counter in the process. A cache with an `increment()`
     *                                       method (Laravel's repository, a Redis client) counts atomically, any other
     *                                       one approximately (get + set)
     * @param string              $keyPrefix `debounce.key_prefix`: no PSR-6 reserved character (`{}()/\@:`)
     * @param int                 $threshold `logging.forbidden_escalation`: the count that escalates once per streak
     * @param int                 $ttl       seconds the counter and the flag live without a new 403
     */
    public function __construct(
        private readonly ?CacheInterface $cache,
        private readonly string $keyPrefix,
        private readonly int $threshold,
        private readonly int $ttl = self::TTL,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * One more consecutive 403 for $host: the new count, and whether this one crosses the threshold (once per
     * streak). In the shared cache the crossing is a flag next to the counter.
     *
     * @return array{0: int, 1: bool}
     */
    public function hit(string $host): array
    {
        if ($this->cache !== null) {
            try {
                $count = $this->increment($host);
                $escalate = $count >= $this->threshold && !(bool) $this->cache->get($this->key($host, true), false);
                if ($escalate) {
                    $this->cache->set($this->key($host, true), true, $this->ttl);
                }

                return [$count, $escalate];
            } catch (Throwable $e) {
                $this->warn($e);
            }
        }
        $count = $this->counts[$host] = ($this->counts[$host] ?? 0) + 1;

        return [$count, $count === $this->threshold];
    }

    /** A non-403 answer ends the streak: the shared counter is deleted only when it is set (no write per success). */
    public function reset(string $host): void
    {
        unset($this->counts[$host]);
        if ($this->cache === null) {
            return;
        }
        try {
            if ($this->stored($host) > 0) {
                $this->cache->deleteMultiple([$this->key($host, false), $this->key($host, true)]);
            }
        } catch (Throwable $e) {
            $this->warn($e);
        }
    }

    /** The current streak of $host: from the shared cache when there is one (0 when it fails), else the process count. */
    public function count(string $host): int
    {
        if ($this->cache !== null) {
            try {
                return $this->stored($host);
            } catch (Throwable $e) {
                $this->warn($e);

                return 0;
            }
        }

        return $this->counts[$host] ?? 0;
    }

    /** Whether the current streak of $host already crossed the threshold (the critical line was written). */
    public function isEscalated(string $host): bool
    {
        if ($this->cache !== null) {
            try {
                return (bool) $this->cache->get($this->key($host, true), false);
            } catch (Throwable $e) {
                $this->warn($e);

                return false;
            }
        }

        return ($this->counts[$host] ?? 0) >= $this->threshold;
    }

    public function threshold(): int
    {
        return $this->threshold;
    }

    /** `<prefix>403.<host>` and `<prefix>403.<host>_escalated`. */
    public function key(string $host, bool $escalated = false): string
    {
        return $this->keyPrefix . '403.' . $host . ($escalated ? '_escalated' : '');
    }

    /**
     * @throws Throwable from the cache
     */
    private function increment(string $host): int
    {
        $cache = $this->cache;
        \assert($cache !== null);
        $key = $this->key($host, false);
        if (method_exists($cache, 'increment')) {
            /** @var mixed $count */
            $count = $cache->increment($key);
            if (\is_int($count) && $count > 0) {
                if ($count === 1) {
                    $cache->set($key, 1, $this->ttl); // a fresh counter gets the TTL; increment() alone would keep it forever on some stores
                }

                return $count;
            }
        }
        $count = $this->stored($host) + 1;
        $cache->set($key, $count, $this->ttl);

        return $count;
    }

    /**
     * @throws Throwable from the cache
     */
    private function stored(string $host): int
    {
        \assert($this->cache !== null);
        $stored = $this->cache->get($this->key($host, false), 0);

        return is_numeric($stored) ? (int) $stored : 0;
    }

    private function warn(Throwable $e): void
    {
        if ($this->warned) {
            return;
        }
        $this->warned = true;
        $this->logger->warning('indexnow: failure cache unavailable, counting 403s per process: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
    }
}
