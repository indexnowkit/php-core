<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use Closure;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use ReflectionClass;

/**
 * Registry of resolvers by id for `#[IndexNow(resolver: ...)]`: the ones registered here, then whatever the
 * adapter's container knows under that id (the `locate` closure), then a UrlResolverInterface class without
 * constructor dependencies, instantiated on demand. Plain PHP uses it with the array only; a framework adapter
 * gives it its container:
 *
 *   new ArrayResolverLocator([], locate: fn(string $id): ?object => $container->has($id) ? $container->get($id) : null, hint: 'a service id')
 */
final class ArrayResolverLocator implements ResolverLocatorInterface
{
    /** @var array<string, UrlResolverInterface> */
    private array $resolvers = [];

    /**
     * @param array<string, UrlResolverInterface|callable(object, Event): (iterable<string>|string|null)> $resolvers
     * @param (Closure(string): ?object)|null                                                             $locate    the adapter's container lookup; null = unknown id
     * @param string|null                                                                                 $hint      what the adapter's ids are ("a service id", "a container binding"), for the error
     */
    public function __construct(array $resolvers = [], private readonly ?Closure $locate = null, private readonly ?string $hint = null)
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

    /**
     * @throws ConfigurationException when nothing is registered or located under $id and it is not a UrlResolverInterface class
     */
    public function get(string $id): UrlResolverInterface
    {
        if (isset($this->resolvers[$id])) {
            return $this->resolvers[$id];
        }
        $located = $this->locate === null ? null : ($this->locate)($id);
        if ($located !== null) {
            if (!$located instanceof UrlResolverInterface) {
                throw new ConfigurationException(\sprintf('IndexNow URL resolver "%s" resolves to %s, which does not implement %s.', $id, get_debug_type($located), UrlResolverInterface::class));
            }

            return $this->resolvers[$id] = $located;
        }
        if (class_exists($id) && is_subclass_of($id, UrlResolverInterface::class)) {
            if (((new ReflectionClass($id))->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
                throw new ConfigurationException($this->hint === null
                    ? \sprintf('IndexNow URL resolver %s has constructor dependencies; register an instance with set().', $id)
                    : \sprintf('IndexNow URL resolver %s has constructor dependencies but is not known to the container under that id; register it as %s and reference the id in #[IndexNow(resolver: ...)].', $id, $this->hint));
            }

            return $this->resolvers[$id] = new $id();
        }

        throw new ConfigurationException($this->hint === null
            ? \sprintf('No IndexNow URL resolver registered for "%s".', $id)
            : \sprintf('IndexNow URL resolver "%s" is neither %s nor an instantiable class. Implement %s and reference the class or its id.', $id, $this->hint, UrlResolverInterface::class));
    }
}
