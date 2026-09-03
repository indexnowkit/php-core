<?php

declare(strict_types=1);

namespace IndexNowKit\Debounce;

use IndexNowKit\Clock\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Per-process store, bounded to $maxEntries (expired entries are purged first, then the oldest).
 * Right for CLI, tests and single long-running workers; web apps should use Psr16DebounceStore so
 * the debounce survives across requests and processes.
 */
final class MemoryDebounceStore implements DebounceStoreInterface
{
    /** @var array<string, int> url => expires-at unix timestamp, insertion ordered */
    private array $entries = [];

    public function __construct(private readonly ClockInterface $clock = new SystemClock(), private readonly int $maxEntries = 50000) {}

    public function filterRecent(array $urls, int $ttlSeconds): array
    {
        $now = $this->clock->now()->getTimestamp();
        $recent = [];
        foreach ($urls as $url) {
            if (isset($this->entries[$url]) && $this->entries[$url] > $now) {
                $recent[] = $url;
            }
        }

        return $recent;
    }

    public function markSubmitted(array $urls, int $ttlSeconds): void
    {
        $now = $this->clock->now()->getTimestamp();
        foreach ($urls as $url) {
            unset($this->entries[$url]);
            $this->entries[$url] = $now + $ttlSeconds;
        }
        if (\count($this->entries) > $this->maxEntries) {
            $this->entries = array_filter($this->entries, static fn(int $exp): bool => $exp > $now);
            $excess = \count($this->entries) - $this->maxEntries;
            if ($excess > 0) {
                $this->entries = \array_slice($this->entries, $excess, null, true);
            }
        }
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
