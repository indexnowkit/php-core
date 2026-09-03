<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

final class ArrayResolverLocator implements ResolverLocatorInterface
{
    /** @var array<string, UrlResolverInterface> */
    private array $resolvers = [];

    /**
     * @param array<string, UrlResolverInterface|callable(object, Event): (iterable<string>|string|null)> $resolvers
     */
    public function __construct(array $resolvers = [])
    {
        foreach ($resolvers as $id => $resolver) {
            $this->set($id, $resolver);
        }
    }

    /**
     * @param UrlResolverInterface|callable(object, Event): (iterable<string>|string|null) $resolver
     */
    public function set(string $id, UrlResolverInterface|callable $resolver): void
    {
        $this->resolvers[$id] = $resolver instanceof UrlResolverInterface ? $resolver : new CallableUrlResolver($resolver);
    }

    public function get(string $id): UrlResolverInterface
    {
        if (isset($this->resolvers[$id])) {
            return $this->resolvers[$id];
        }
        if (class_exists($id) && is_subclass_of($id, UrlResolverInterface::class)) {
            return $this->resolvers[$id] = new $id();
        }

        throw new ConfigurationException(\sprintf('No IndexNow URL resolver registered for "%s".', $id));
    }
}
