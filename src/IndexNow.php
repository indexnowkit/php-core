<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\NullUrlResolver;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Facade wiring the default component graph for framework-less usage. Adapters build the same graph
 * through their DI container and expose this object as the application-facing service.
 */
final class IndexNow
{
    private readonly GuardedUrlResolver $resolver;

    public function __construct(
        public readonly Config $config,
        public readonly SubmitterInterface $submitter,
        public readonly Collector $collector,
        public readonly DispatcherInterface $dispatcher,
        public readonly KeyProviderInterface $keys,
        public readonly AttributeReaderInterface $attributes = new AttributeReader(),
        ?UrlResolverInterface $resolver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->resolver = new GuardedUrlResolver($resolver ?? new NullUrlResolver(), $attributes, $logger);
    }

    /**
     * Default graph: PSR-18 discovery (with http.timeout), in-memory debounce, token-bucket throttle,
     * synchronous dispatch. Every piece can be replaced.
     *
     * @throws ConfigurationException when no HTTP client can be discovered
     */
    public static function create(
        Config $config,
        ?TransportInterface $transport = null,
        ?LoggerInterface $logger = null,
        ?DebounceStoreInterface $debounce = null,
        ?UrlResolverInterface $resolver = null,
        ?DispatcherInterface $dispatcher = null,
        ?KeyProviderInterface $keys = null,
        ?ThrottleInterface $throttle = null,
        ?UrlNormalizerInterface $normalizer = null,
        ?AttributeReaderInterface $attributes = null,
    ): self {
        $logger ??= new NullLogger();
        $keys ??= StaticKeyProvider::fromConfig($config);
        $normalizer ??= new UrlNormalizer($config->baseUrl);
        $throttle ??= new TokenBucket($config->throttleMaxRequestsPerMinute, logger: $logger);
        $client = new Client($transport ?? Psr18Transport::discover(timeout: $config->httpTimeout), $keys, $config, $logger, $throttle, $normalizer);
        $submitter = new Submitter($client, $config, $debounce ?? new MemoryDebounceStore(), $logger, $normalizer);

        return new self($config, $submitter, new Collector(), $dispatcher ?? new SyncDispatcher($submitter, $logger), $keys, $attributes ?? new AttributeReader(), $resolver, $logger);
    }

    /**
     * Submit immediately (bypasses collector/dispatcher). Returns one Result per endpoint × host × batch.
     *
     * @param iterable<string> $urls absolute or base_url-relative
     *
     * @return list<Result>
     */
    public function submit(iterable $urls): array
    {
        return $this->submitter->submit($urls);
    }

    /**
     * Resolve URLs for an object (via #[IndexNow] + resolver) and submit immediately.
     *
     * @return list<Result>
     */
    public function submitEntity(object $subject, Event $event = Event::Updated): array
    {
        return $this->submit($this->urlsFor($subject, $event));
    }

    /**
     * Queue URLs in the collector; flush() sends them through the dispatcher (adapters call it at request end).
     *
     * @param iterable<string> $urls
     */
    public function collect(iterable $urls): void
    {
        $this->collector->add($this->submitter->prepare($urls));
    }

    public function flush(): void
    {
        if ($this->collector->isEmpty()) {
            return;
        }
        $this->dispatcher->dispatch($this->collector->drain());
    }

    /**
     * URLs an object change should trigger: attribute subscription + `when` guard + resolver. Never throws.
     *
     * @return list<string>
     */
    public function urlsFor(object $subject, Event $event): array
    {
        return $this->resolver->resolve($subject, $event);
    }

    /**
     * Resolver with attribute subscription and `when` guard applied (adapters use it in ORM hooks).
     */
    public function resolver(): GuardedUrlResolver
    {
        return $this->resolver;
    }
}
