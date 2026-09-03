<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * Looks up #[IndexNow(resolver: ...)] values (service ids or class names). One per framework adapter;
 * {@see ArrayResolverLocator} serves plain PHP.
 *
 * "May grow" interface (docs/bc.md): a method may be appended in a minor; adapters pin `^0.2.0`.
 */
interface ResolverLocatorInterface
{
    /**
     * @throws \IndexNowKit\Exception\ConfigurationException when $id is unknown
     */
    public function get(string $id): UrlResolverInterface;
}
