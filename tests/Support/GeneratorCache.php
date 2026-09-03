<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Minimal PSR-16 cache whose getMultiple() yields a Traversable instead of an array, to exercise
 * the iterator_to_array() fallback in Psr16DebounceStore.
 */
final class GeneratorCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @param string $key */
    public function get($key, $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /** @param string $key @param null|int|DateInterval $ttl */
    public function set($key, $value, $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    /** @param string $key */
    public function delete($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    /** @param iterable<string> $keys */
    public function getMultiple($keys, $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->values[$key] ?? $default;
        }
    }

    /**
     * @param iterable<string, mixed> $values
     */
    /** @param iterable<string, mixed> $values @param null|int|DateInterval $ttl */
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->values[$key] = $value;
        }

        return true;
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }

        return true;
    }

    /** @param string $key */
    public function has($key): bool
    {
        return isset($this->values[$key]);
    }
}
