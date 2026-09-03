<?php

declare(strict_types=1);

namespace IndexNowKit\Debounce;

use IndexNowKit\Clock\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * Per-process store. Fine for CLI, tests and single long-running workers; use a cache-backed store in web apps.
 */
final class MemoryDebounceStore implements DebounceStoreInterface
{
    /** @var array<string, int> url => expires-at unix timestamp */
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
        if (\count($this->entries) > $this->maxEntries) {
            $this->entries = array_filter($this->entries, static fn(int $exp) => $exp > $now);
        }
        foreach ($urls as $url) {
            $this->entries[$url] = $now + $ttlSeconds;
        }
    }
}
