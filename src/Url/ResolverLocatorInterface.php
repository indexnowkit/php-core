<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * Looks up #[IndexNow(resolver: ...)] values (service ids or class names).
 */
interface ResolverLocatorInterface
{
    /**
     * @throws \IndexNowKit\Exception\ConfigurationException when $id is unknown
     */
    public function get(string $id): UrlResolverInterface;
}
