<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\NullUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Facade (entry point) wiring the default component graph for framework-less usage. Adapters build the same graph
 * through their DI container and expose this object as the application-facing service.
 */
final class IndexNowKit
{
    private readonly GuardedUrlResolver $resolver;
    private readonly ObjectChangeHandler $changes;

    /**
     * @param TransportInterface|null $transport the transport submissions go through, for consumers that read documents
     *                                           over the same client (null when the facade was built around a custom
     *                                           submitter: use `Http\TransportFactory::lazy($config)` then)
     */
    public function __construct(
        public readonly Config $config,
        public readonly SubmitterInterface $submitter,
        public readonly CollectorInterface $collector,
        public readonly DispatcherInterface $dispatcher,
        public readonly KeyProviderInterface $keys,
        public readonly AttributeReaderInterface $attributes = new AttributeReader(),
        ?UrlResolverInterface $resolver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        public readonly ?TransportInterface $transport = null,
    ) {
        $this->resolver = $resolver instanceof GuardedUrlResolver ? $resolver : new GuardedUrlResolver($resolver ?? new NullUrlResolver(), $attributes, $logger);
        $this->changes = new ObjectChangeHandler($attributes, $this->resolver, $logger);
    }

    /**
     * Default graph through the factories every adapter uses: PSR-18 discovery with http.timeout
     * (`Http\TransportFactory::lazy()`), the debounce store of `debounce.store` (memory by default,
     * `Debounce\DebounceStoreFactory`), token-bucket throttle, the dispatcher of `dispatch` (sync or none;
     * `Dispatch\DispatcherFactory`). Every piece can be replaced. A custom $submitter (e.g. RetryingSubmitter)
     * brings its own pipeline, so combining it with $transport/$debounce/$throttle/$normalizer is rejected instead
     * of ignored. Parameter NAMES are part of the BC promise (new ones are only appended): always use named arguments.
     *
     * @throws ConfigurationException when no HTTP client can be discovered, on an incompatible combination, or on a
     *                                `dispatch`/`debounce.store`/`http.client` value that needs a framework to resolve it
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
        ?SubmitterInterface $submitter = null,
        ?CollectorInterface $collector = null,
    ): self {
        $logger ??= new NullLogger();
        $keys ??= StaticKeyProvider::fromConfig($config);
        if ($submitter !== null) {
            $ignored = array_keys(array_filter(['transport' => $transport, 'debounce' => $debounce, 'throttle' => $throttle, 'normalizer' => $normalizer], static fn($v): bool => $v !== null));
            if ($ignored !== []) {
                throw new ConfigurationException(\sprintf('IndexNowKit::create(): $%s cannot be combined with a custom $submitter, which builds its own pipeline. Pass them to your submitter instead.', implode(', $', $ignored)));
            }
        } else {
            $normalizer ??= new UrlNormalizer($config->baseUrl, $config->maxUrlLength);
            $throttle ??= TokenBucket::fromConfig($config, $logger);
            $transport ??= TransportFactory::lazy($config);
            $client = new Client($transport, $keys, $config, $logger, $throttle, $normalizer);
            $submitter = new Submitter($client, $config, $debounce ?? DebounceStoreFactory::fromConfig($config), $logger, $normalizer);
        }
        $dispatcher ??= DispatcherFactory::fromConfig($config, $submitter, $logger);

        return new self($config, $submitter, $collector ?? Collector::fromConfig($config, $logger), $dispatcher, $keys, $attributes ?? new AttributeReader(), $resolver, $logger, $transport);
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
     * Resolve the URLs of many objects and submit them in one call (one request per host and batch): the manual
     * path after a bulk update that fired no hooks. De-duplicated across the whole set, so 100 posts of one
     * category yield the category page once.
     *
     * @param iterable<object> $subjects
     *
     * @return list<Result>
     */
    public function submitAll(iterable $subjects, Event $event = Event::Updated): array
    {
        return $this->submit($this->urlsForAll($subjects, $event));
    }

    /**
     * Queue URLs in the collector; flush() sends them through the dispatcher (adapters call it at request end).
     *
     * @param iterable<string> $urls
     */
    public function collect(iterable $urls): void
    {
        $this->collector->add($this->submitter->prepare($urls));
        if ($this->config->collectorMaxUrls > 0 && $this->collector->count() >= $this->config->collectorMaxUrls) {
            $this->logger->info('indexnow: collector reached {max} URL(s) (collector.max_urls), flushing early', ['max' => $this->config->collectorMaxUrls]);
            $this->flush();
        }
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
    public function urlsFor(object $subject, Event $event = Event::Updated): array
    {
        return $this->resolver->resolve($subject, $event);
    }

    /**
     * URLs the rules of many objects yield, de-duplicated across the set. Never throws.
     *
     * @param iterable<object> $subjects
     *
     * @return list<string>
     */
    public function urlsForAll(iterable $subjects, Event $event = Event::Updated): array
    {
        $resolved = [];
        foreach ($subjects as $subject) {
            $resolved = [...$resolved, ...$this->resolver->explain($subject, $event)];
        }

        return ResolvedUrl::urls($resolved);
    }

    /**
     * Like urlsFor(), with the rule that produced each URL (debugging, `explain` commands). Never throws.
     *
     * @return list<ResolvedUrl>
     */
    public function explain(object $subject, Event $event = Event::Updated): array
    {
        return $this->resolver->explain($subject, $event);
    }

    /**
     * Never-throwing resolver (rules, `when` guard, per-rule resolution) for ORM hooks and commands.
     */
    public function resolver(): GuardedUrlResolver
    {
        return $this->resolver;
    }

    /**
     * ORM-hook building block: classifies created/updated/deleted objects per rule and resolves their URLs.
     */
    public function changes(): ObjectChangeHandler
    {
        return $this->changes;
    }
}
