<?php

declare(strict_types=1);

namespace IndexNowKit\Debounce;

use Psr\SimpleCache\CacheInterface;

final class Psr16DebounceStore implements DebounceStoreInterface
{
    public function __construct(private readonly CacheInterface $cache, private readonly string $prefix = 'indexnow_') {}

    public function filterRecent(array $urls, int $ttlSeconds): array
    {
        if ($urls === []) {
            return [];
        }
        $keys = array_map($this->key(...), $urls);
        $found = $this->cache->getMultiple($keys, false);
        $found = \is_array($found) ? $found : iterator_to_array($found);
        $recent = [];
        foreach ($urls as $i => $url) {
            if (($found[$keys[$i]] ?? false) !== false) {
                $recent[] = $url;
            }
        }

        return $recent;
    }

    public function markSubmitted(array $urls, int $ttlSeconds): void
    {
        if ($urls === [] || $ttlSeconds <= 0) {
            return;
        }
        $values = [];
        foreach ($urls as $url) {
            $values[$this->key($url)] = 1;
        }
        $this->cache->setMultiple($values, $ttlSeconds);
    }

    private function key(string $url): string
    {
        return $this->prefix . sha1($url);
    }
}
