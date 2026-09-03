<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * Looks up #[IndexNow(resolver: ...)] values (service ids or class names).
 */
interface ResolverLocatorInterface
{
    public function get(string $id): UrlResolverInterface;
}
