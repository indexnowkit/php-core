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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->values[$key] ?? $default;
        }
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->values[$key] = $value;
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->values[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }
}
