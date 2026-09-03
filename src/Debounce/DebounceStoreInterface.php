<?php

declare(strict_types=1);

namespace IndexNowKit\Debounce;

/**
 * Remembers when a URL was last submitted so the same URL is not re-sent within debounce.per_url seconds.
 */
interface DebounceStoreInterface
{
    /**
     * @param list<string> $urls
     * @return list<string> subset of $urls that were submitted less than $ttlSeconds ago
     */
    public function filterRecent(array $urls, int $ttlSeconds): array;

    /**
     * @param list<string> $urls
     */
    public function markSubmitted(array $urls, int $ttlSeconds): void;
}
