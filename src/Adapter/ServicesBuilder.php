<?php

declare(strict_types=1);

namespace IndexNowKit\Adapter;

use Closure;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Describes the graph of an adapter that assembles at runtime (Yii, plain PHP, a CMS without a service container):
 * every node is optional and given either as a ready object or as a `Closure(Services): object` that is called once,
 * on first use. What is not given comes from the factories of the core (`Http\TransportFactory`,
 * `Debounce\DebounceStoreFactory`, `Dispatch\DispatcherFactory`, the `fromConfig()` constructors), so the result is
 * the graph `IndexNowKit::create()` builds, with every piece replaceable and every dependent piece derived from the
 * replacement (give a transport and the client, the checker and the console submitters use it).
 *
 * `build()` does no IO: the transport is lazy, the queue is a closure, nothing is discovered. It throws
 * `ConfigurationException` for what is known to be wrong before the first request: a `debounce.store` id with no
 * store to resolve it, an `http.client` id with no locator, a `dispatch` mode that needs a queue nobody provides.
 *
 * Adapters with a real container (Symfony, Laravel) describe their services with the factories directly: their
 * service ids and bindings are public API, and a builder would hide them. See docs/adapters.md §2.
 */
final class ServicesBuilder
{
    /** @var array<string, object|Closure|null> */
    private array $nodes = [];
    private ?Closure $httpClientLocator = null;
    private ?Closure $queueFactory = null;
    private ?EventDispatcherInterface $events = null;
    /** @var iterable<CheckInterface>|Closure|null */
    private iterable|Closure|null $checks = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** The transport submissions, the key file check and the console submitters go through; default `Http\TransportFactory::lazy()`. */
    public function transport(TransportInterface|Closure $transport): self
    {
        return $this->node(Services::TRANSPORT, $transport);
    }

    /**
     * How the adapter resolves the `http.client` id to a PSR-18 client (a container binding, a component id, a class
     * name); needed only when the option is set and no transport is given.
     *
     * @param Closure(string): mixed $locator
     */
    public function httpClientLocator(Closure $locator): self
    {
        $this->httpClientLocator = $locator;

        return $this;
    }

    /** Default `Key\StaticKeyProvider::fromConfig()`. */
    public function keys(KeyProviderInterface|Closure $keys): self
    {
        return $this->node(Services::KEYS, $keys);
    }

    /** Default `Url\UrlNormalizer` over `base_url` and `max_url_length`. */
    public function normalizer(UrlNormalizerInterface|Closure $normalizer): self
    {
        return $this->node(Services::NORMALIZER, $normalizer);
    }

    /** Default `Throttle\TokenBucket::fromConfig()`. */
    public function throttle(ThrottleInterface|Closure $throttle): self
    {
        return $this->node(Services::THROTTLE, $throttle);
    }

    /**
     * The only way to give a store other than `memory`/`none`: the adapter resolves the `debounce.store` id itself
     * (`Debounce\DebounceStoreFactory::fromConfig($services->config, $locator, $default)` with its cache locator and
     * its default store id, or a store class of its own).
     */
    public function debounceStore(DebounceStoreInterface|Closure $store): self
    {
        return $this->node(Services::DEBOUNCE_STORE, $store);
    }

    /** Default `Client` over the transport, keys, throttle and normalizer. */
    public function client(ClientInterface|Closure $client): self
    {
        return $this->node(Services::CLIENT, $client);
    }

    /** Default `Submitter` over the client and the debounce store. */
    public function submitter(SubmitterInterface|Closure $submitter): self
    {
        return $this->node(Services::SUBMITTER, $submitter);
    }

    /** PSR-14 dispatcher the submitter and the console submitters publish `Result` events to. */
    public function events(EventDispatcherInterface $events): self
    {
        $this->events = $events;

        return $this;
    }

    /** Default `Collector\Collector::fromConfig()`. */
    public function collector(CollectorInterface|Closure $collector): self
    {
        return $this->node(Services::COLLECTOR, $collector);
    }

    /** Replaces `Dispatch\DispatcherFactory::fromConfig()` entirely, `dispatch` mode included. */
    public function dispatcher(DispatcherInterface|Closure $dispatcher): self
    {
        return $this->node(Services::DISPATCHER, $dispatcher);
    }

    /**
     * The adapter's queue dispatcher for a `dispatch` mode that is neither `sync` nor `none`; called once, on the
     * first dispatch.
     *
     * @param Closure(Services): DispatcherInterface $factory
     */
    public function queueFactory(Closure $factory): self
    {
        $this->queueFactory = $factory;

        return $this;
    }

    /** Default `Attribute\RuleRegistry` over `Attribute\AttributeReader`; see {@see Services::rules()}. */
    public function reader(AttributeReaderInterface|Closure $reader): self
    {
        return $this->node(Services::READER, $reader);
    }

    /** The framework's router bridge for `#[IndexNow(route: ...)]`; none by default. */
    public function router(RouteUrlResolverInterface|Closure $router): self
    {
        return $this->node(Services::ROUTER, $router);
    }

    /** How `#[IndexNow(resolver: ...)]` ids are found (`Url\ArrayResolverLocator(locate:, hint:)` over the container); none by default. */
    public function resolverLocator(ResolverLocatorInterface|Closure $locator): self
    {
        return $this->node(Services::RESOLVER_LOCATOR, $locator);
    }

    /** Replaces the attribute resolver entirely (the router and the locator are then unused). */
    public function urlResolver(UrlResolverInterface|Closure $resolver): self
    {
        return $this->node(Services::URL_RESOLVER, $resolver);
    }

    /**
     * The adapter's checks for the `check` command, on top of the core's own (`Check\DebounceStoreCheck` with the
     * adapter's probe, a queue check, a route check).
     *
     * @param iterable<CheckInterface>|Closure(Services): iterable<CheckInterface> $checks
     */
    public function checks(iterable|Closure $checks): self
    {
        $this->checks = $checks;

        return $this;
    }

    /**
     * @throws ConfigurationException for what is statically wrong: a `debounce.store` id without `debounceStore()`,
     *                                an `http.client` id without `httpClientLocator()` or `transport()`, a `dispatch`
     *                                mode outside sync/none without `queueFactory()` or `dispatcher()`
     */
    public function build(): Services
    {
        $config = $this->config;
        if (!isset($this->nodes[Services::TRANSPORT])) {
            TransportFactory::lazy($config, $this->httpClientLocator); // validation only: throws on an id without a locator, no IO otherwise
        }
        if (!isset($this->nodes[Services::DEBOUNCE_STORE])) {
            DebounceStoreFactory::fromConfig($config); // validation only: memory and none build, any other id throws
        }
        if (!isset($this->nodes[Services::DISPATCHER]) && $this->queueFactory === null && $config->enabled && !\in_array($config->dispatch, [DispatcherFactory::SYNC, DispatcherFactory::NONE], true)) {
            throw new ConfigurationException(\sprintf('"dispatch" "%s" needs a queue dispatcher, which this adapter does not provide; use "sync" or "none".', $config->dispatch));
        }

        return new Services($config, $this->logger, $this->nodes, $this->httpClientLocator, $this->queueFactory, $this->events, $this->checks);
    }

    private function node(string $name, object $value): self
    {
        $this->nodes[$name] = $value;

        return $this;
    }
}
