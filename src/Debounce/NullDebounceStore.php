<?php

declare(strict_types=1);

namespace IndexNowKit\Debounce;

/**
 * Disables debounce explicitly (every URL is sent every time). Equivalent to debounce.per_url = 0; useful when the
 * store is injected by a container and the TTL is not under your control.
 */
final class NullDebounceStore implements DebounceStoreInterface
{
    public function filterRecent(array $urls, int $ttlSeconds): array
    {
        return [];
    }

    public function markSubmitted(array $urls, int $ttlSeconds): void {}
}
