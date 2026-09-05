<?php

declare(strict_types=1);

namespace IndexNowKit\Adapter;

use Closure;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Client;
use IndexNowKit\ClientInterface;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use LogicException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The lazy, memoized graph {@see ServicesBuilder} describes: every accessor is one factory call or one constructor
 * of the core over the other accessors, nothing more, so a graph built here and a graph built by hand from the
 * factories are the same graph (`ServicesParityTest` keeps it so). Nothing is built until asked for; a request that
 * submits nothing builds nothing.
 *
 * Feature packages do not know this class and it does not know them: a sitemap reader is
 * `SitemapReader::fromConfig($sitemapConfig, $services->transport(), $services->logger)`. Staging, observers and the
 * key file route stay in the adapter.
 */
final class Services
{
    public const TRANSPORT = 'transport';
    public const KEYS = 'keys';
    public const NORMALIZER = 'normalizer';
    public const THROTTLE = 'throttle';
    public const DEBOUNCE_STORE = 'debounceStore';
    public const CLIENT = 'client';
    public const SUBMITTER = 'submitter';
    public const COLLECTOR = 'collector';
    public const DISPATCHER = 'dispatcher';
    public const READER = 'reader';
    public const ROUTER = 'router';
    public const RESOLVER_LOCATOR = 'resolverLocator';
    public const URL_RESOLVER = 'urlResolver';

    /** @var array<string, object|null> */
    private array $built = [];
    private ?RuleRegistry $rules = null;
    private ?GuardedUrlResolver $guardedResolver = null;
    private ?IndexNowKit $kit = null;
    private ?KeyFileResponder $keyFileResponder = null;
    private ?CheckerInterface $checker = null;
    private ?SubmitterFactoryInterface $submitterFactory = null;

    /**
     * @internal built by {@see ServicesBuilder::build()}
     *
     * @param array<string, object|Closure|null>                             $nodes
     * @param (Closure(string): mixed)|null                                  $httpClientLocator
     * @param (Closure(self): DispatcherInterface)|null                      $queueFactory
     * @param iterable<CheckInterface>|(Closure(self): iterable<CheckInterface>)|null $checks
     */
    public function __construct(
        public readonly Config $config,
        public readonly LoggerInterface $logger,
        private readonly array $nodes = [],
        private readonly ?Closure $httpClientLocator = null,
        private readonly ?Closure $queueFactory = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly iterable|Closure|null $checks = null,
    ) {}

    public function transport(): TransportInterface
    {
        return $this->memo(self::TRANSPORT, TransportInterface::class, fn(): TransportInterface => TransportFactory::lazy($this->config, $this->httpClientLocator));
    }

    public function keys(): KeyProviderInterface
    {
        return $this->memo(self::KEYS, KeyProviderInterface::class, fn(): KeyProviderInterface => StaticKeyProvider::fromConfig($this->config));
    }

    public function normalizer(): UrlNormalizerInterface
    {
        return $this->memo(self::NORMALIZER, UrlNormalizerInterface::class, fn(): UrlNormalizerInterface => new UrlNormalizer($this->config->baseUrl, $this->config->maxUrlLength));
    }

    public function throttle(): ThrottleInterface
    {
        return $this->memo(self::THROTTLE, ThrottleInterface::class, fn(): ThrottleInterface => TokenBucket::fromConfig($this->config, $this->logger));
    }

    public function debounceStore(): DebounceStoreInterface
    {
        return $this->memo(self::DEBOUNCE_STORE, DebounceStoreInterface::class, fn(): DebounceStoreInterface => DebounceStoreFactory::fromConfig($this->config));
    }

    public function client(): ClientInterface
    {
        return $this->memo(self::CLIENT, ClientInterface::class, fn(): ClientInterface => new Client($this->transport(), $this->keys(), $this->config, $this->logger, $this->throttle(), $this->normalizer()));
    }

    public function submitter(): SubmitterInterface
    {
        return $this->memo(self::SUBMITTER, SubmitterInterface::class, fn(): SubmitterInterface => new Submitter($this->client(), $this->config, $this->debounceStore(), $this->logger, $this->normalizer(), $this->events));
    }

    public function collector(): CollectorInterface
    {
        return $this->memo(self::COLLECTOR, CollectorInterface::class, fn(): CollectorInterface => Collector::fromConfig($this->config, $this->logger));
    }

    public function dispatcher(): DispatcherInterface
    {
        return $this->memo(self::DISPATCHER, DispatcherInterface::class, fn(): DispatcherInterface => DispatcherFactory::fromConfig(
            $this->config,
            $this->submitter(),
            $this->logger,
            $this->queueFactory === null ? null : fn(): DispatcherInterface => $this->typed(($this->queueFactory)($this), DispatcherInterface::class, 'queueFactory'),
        ));
    }

    /** The reader as given (default: a `RuleRegistry` over `AttributeReader`); the graph reads through {@see rules()}. */
    public function reader(): AttributeReaderInterface
    {
        return $this->memo(self::READER, AttributeReaderInterface::class, static fn(): AttributeReaderInterface => new RuleRegistry(new AttributeReader()));
    }

    /**
     * The reader when it is a `RuleRegistry`, else a `RuleRegistry` decorating it: what the resolver, the facade and
     * the change handler read, so `rules()->register()` reaches every consumer whatever reader was given.
     */
    public function rules(): RuleRegistry
    {
        if ($this->rules === null) {
            $reader = $this->reader();
            $this->rules = $reader instanceof RuleRegistry ? $reader : new RuleRegistry($reader);
        }

        return $this->rules;
    }

    /** The router bridge as given; none by default (`#[IndexNow(route: ...)]` then fails with a clear message). */
    public function router(): ?RouteUrlResolverInterface
    {
        return $this->optional(self::ROUTER, RouteUrlResolverInterface::class);
    }

    /** The resolver locator as given; none by default (`#[IndexNow(resolver: ...)]` then accepts class names only). */
    public function resolverLocator(): ?ResolverLocatorInterface
    {
        return $this->optional(self::RESOLVER_LOCATOR, ResolverLocatorInterface::class);
    }

    public function urlResolver(): UrlResolverInterface
    {
        return $this->memo(self::URL_RESOLVER, UrlResolverInterface::class, fn(): UrlResolverInterface => AttributeUrlResolver::fromConfig($this->config, $this->rules(), $this->router(), $this->resolverLocator(), $this->logger));
    }

    public function guardedResolver(): GuardedUrlResolver
    {
        return $this->guardedResolver ??= new GuardedUrlResolver($this->urlResolver(), $this->rules(), $this->logger);
    }

    /** The facade's own change handler ({@see IndexNowKit::changes()}). */
    public function changes(): ObjectChangeHandler
    {
        return $this->kit()->changes();
    }

    public function kit(): IndexNowKit
    {
        return $this->kit ??= new IndexNowKit(
            config: $this->config,
            submitter: $this->submitter(),
            collector: $this->collector(),
            dispatcher: $this->dispatcher(),
            keys: $this->keys(),
            attributes: $this->rules(),
            resolver: $this->guardedResolver(),
            logger: $this->logger,
            transport: $this->transport(),
        );
    }

    public function keyFileResponder(): KeyFileResponder
    {
        return $this->keyFileResponder ??= KeyFileResponder::fromConfig($this->config, $this->keys());
    }

    public function checker(): CheckerInterface
    {
        if ($this->checker === null) {
            $checks = $this->checks instanceof Closure ? ($this->checks)($this) : ($this->checks ?? []);
            if (!is_iterable($checks)) {
                throw new LogicException(\sprintf('ServicesBuilder::checks(): the closure must return an iterable of CheckInterface, got %s.', get_debug_type($checks)));
            }
            $this->checker = new Checker($this->config, $this->keys(), $this->transport(), $checks);
        }

        return $this->checker;
    }

    /** `Adapter\SubmitterFactory` over the nodes: `--force` / `--dry-run` submitters for the commands. */
    public function submitterFactory(): SubmitterFactoryInterface
    {
        return $this->submitterFactory ??= new SubmitterFactory($this->transport(), $this->keys(), $this->config, $this->debounceStore(), $this->throttle(), $this->normalizer(), $this->logger, $this->events);
    }

    /** False when the collector was never built (nothing was collected) or is empty; builds nothing. */
    public function hasCollected(): bool
    {
        $collector = $this->built[self::COLLECTOR] ?? null;

        return $collector instanceof CollectorInterface && !$collector->isEmpty();
    }

    /** The request-end hook: `kit()->flush()` when something was collected, an error log line instead of an exception. */
    public function flushIfCollected(): void
    {
        if (!$this->hasCollected()) {
            return;
        }
        try {
            $this->kit()->flush();
        } catch (Throwable $e) {
            $this->logger->error('indexnow: flush failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * The node as given (an object, or a closure called once), else the default; built once.
     *
     * @template T of object
     *
     * @param class-string<T> $type
     * @param Closure(): T    $default
     *
     * @return T
     */
    private function memo(string $name, string $type, Closure $default): object
    {
        $built = $this->optional($name, $type) ?? $default();
        $this->built[$name] = $built;

        return $built;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T|null null when the node was not given
     */
    private function optional(string $name, string $type): ?object
    {
        if (!\array_key_exists($name, $this->built)) {
            $node = $this->nodes[$name] ?? null;
            $this->built[$name] = $node === null ? null : $this->typed($node instanceof Closure ? $node($this) : $node, $type, $name);
        }
        $built = $this->built[$name];
        \assert($built === null || $built instanceof $type);

        return $built;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    private function typed(mixed $value, string $type, string $name): object
    {
        if (!$value instanceof $type) {
            throw new LogicException(\sprintf('ServicesBuilder::%s(): the closure must return %s, got %s.', $name, $type, get_debug_type($value)));
        }

        return $value;
    }
}
