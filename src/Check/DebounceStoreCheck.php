<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreFactory;
use Throwable;

/**
 * The `debounce` line of `check`: the window lives in a store, and a misconfigured store fails open (URLs are
 * still sent) while silently disabling the window. The adapter supplies a probe that writes and reads a test key in
 * its cache and returns a short signature of the store (`cache store "redis" (RedisStore)`), or throws.
 */
final class DebounceStoreCheck implements CheckInterface
{
    /**
     * @param (Closure(string): string)|null $probe   given the store id, uses the store and returns what to print, or throws
     * @param string                         $default the store when `debounce.store` is unset ({@see DebounceStoreFactory::fromConfig()})
     */
    public function __construct(
        private readonly Config $config,
        private readonly ?Closure $probe = null,
        private readonly string $default = DebounceStoreFactory::MEMORY,
    ) {}

    public function check(CheckReport $report): void
    {
        $window = $this->config->debouncePerUrl;
        if ($window <= 0) {
            $report->ok('debounce: off (debounce.per_url = 0): every URL is submitted every time');

            return;
        }
        $store = $this->config->debounceStore ?? $this->default;
        if ($store === DebounceStoreFactory::NONE) {
            $report->ok('debounce: no store (debounce.store = none): every URL is submitted every time, the engines de-duplicate');

            return;
        }
        if ($store === DebounceStoreFactory::MEMORY) {
            $report->warning(\sprintf('debounce: store "memory" is per-process only; web requests and workers do not share the %ds window. Set debounce.store to a shared cache in production.', $window));

            return;
        }
        if ($this->probe === null) {
            $report->ok(\sprintf('debounce: %ds per URL, shared through store "%s"', $window, $store));

            return;
        }
        try {
            $report->ok(\sprintf('debounce: %ds per URL, shared through %s', $window, ($this->probe)($store)));
        } catch (Throwable $e) {
            $report->error(\sprintf('debounce: store "%s" is not usable (%s); URLs are still sent, the window is not applied.', $store, $e->getMessage()));
        }
    }
}
