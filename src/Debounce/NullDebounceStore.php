<?php

declare(strict_types=1);

namespace IndexNowKit\Debounce;

/**
 * Disables debounce (every URL is sent every time).
 */
final class NullDebounceStore implements DebounceStoreInterface
{
    public function filterRecent(array $urls, int $ttlSeconds): array
    {
        return [];
    }

    public function markSubmitted(array $urls, int $ttlSeconds): void {}
}
