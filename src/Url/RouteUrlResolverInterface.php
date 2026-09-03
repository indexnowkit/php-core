<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * Framework router bridge: turns #[IndexNow(route:, params:)] into absolute URLs. One per framework adapter.
 */
interface RouteUrlResolverInterface
{
    /**
     * @param array<string, mixed>  $params  already extracted route parameters
     * @param list<string>|string   $locales 'current' | 'all' | explicit list
     * @return iterable<string>
     */
    public function generate(string $route, array $params, array|string $locales): iterable;
}
