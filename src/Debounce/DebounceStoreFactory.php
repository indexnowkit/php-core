<?php

declare(strict_types=1);

namespace IndexNowKit\Debounce;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\SimpleCache\CacheInterface;

/**
 * The debounce store an adapter wires from `debounce.store`: `memory` (per process), `none` (off), or an id the
 * adapter resolves to a PSR-16 cache shared by every process.
 */
final class DebounceStoreFactory
{
    public const MEMORY = 'memory';
    public const NONE = 'none';

    private function __construct() {}

    /**
     * @param (Closure(string): mixed)|null $cacheLocator how the adapter resolves a store id: to a PSR-16 cache (wrapped in
     *                                                    Psr16DebounceStore with `debounce.key_prefix`), or to a ready
     *                                                    DebounceStoreInterface when the framework's cache is not PSR-16 (Yii2);
     *                                                    required for any id but memory/none
     * @param string                        $default      the store when `debounce.store` is unset (Laravel `cache`, plain PHP `memory`)
     *
     * @throws ConfigurationException when the id needs a locator there is none, or resolves to neither a cache nor a store
     */
    public static function fromConfig(Config $config, ?Closure $cacheLocator = null, string $default = self::MEMORY): DebounceStoreInterface
    {
        $id = $config->debounceStore ?? $default;
        if ($id === self::MEMORY) {
            return new MemoryDebounceStore();
        }
        if ($id === self::NONE) {
            return new NullDebounceStore();
        }
        if ($cacheLocator === null) {
            throw new ConfigurationException(\sprintf('"debounce.store" "%s" needs a cache locator: this adapter cannot resolve a store id, use "memory" or "none".', $id));
        }
        $cache = $cacheLocator($id);
        if ($cache instanceof DebounceStoreInterface) {
            return $cache;
        }
        if (!$cache instanceof CacheInterface) {
            throw new ConfigurationException(\sprintf('"debounce.store" "%s" resolves to %s, which is not a PSR-16 cache.', $id, get_debug_type($cache)));
        }

        return new Psr16DebounceStore($cache, $config->debounceKeyPrefix);
    }
}
