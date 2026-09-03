<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * Framework router bridge: turns #[IndexNow(route:, params:)] into absolute URLs. One per framework adapter.
 *
 * Locale expansion is separate from generation so the core can re-extract params per locale (a localized
 * slug lives in a per-locale translation) and so a rule can pin a host.
 *
 * "May grow" interface (docs/bc.md): a method may be appended in a minor. There is no shipped implementation to
 * decorate, so adapters pin `^0.2.0` and read the changelog before upgrading.
 */
interface RouteUrlResolverInterface
{
    /**
     * Locales to generate one URL for each.
     *
     * @param list<string>|string $locales 'current' | 'all' | explicit list
     *
     * @return list<string|null> [null] when the route has no locale dimension
     */
    public function locales(array|string $locales): array;

    /**
     * @param array<string, mixed> $params already extracted for this locale
     * @param string|null          $locale locale of this URL (adapters pass it as the route's locale parameter)
     * @param string|null          $host   host the URL must be generated on (multi-domain); null = request / base_url
     *
     * @return string absolute URL
     *
     * @throws \IndexNowKit\Exception\ConfigurationException when the route or its parameters are invalid
     */
    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string;
}
