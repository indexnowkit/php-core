<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Url\Event;
use IndexNowKit\Url\PublishGuard;
use IndexNowKit\Url\UrlResolverInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Facade wiring the default component graph. Framework adapters build the same graph through DI.
 */
final class IndexNow
{
    public function __construct(
        public readonly Config $config,
        public readonly Submitter $submitter,
        public readonly Collector $collector,
        public readonly DispatcherInterface $dispatcher,
        public readonly KeyProviderInterface $keys,
        public readonly AttributeReader $attributes = new AttributeReader(),
        private readonly ?UrlResolverInterface $resolver = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function create(
        Config $config,
        ?TransportInterface $transport = null,
        ?LoggerInterface $logger = null,
        ?DebounceStoreInterface $debounce = null,
        ?UrlResolverInterface $resolver = null,
        ?DispatcherInterface $dispatcher = null,
    ): self {
        $logger ??= new NullLogger();
        $keys = StaticKeyProvider::fromConfig($config);
        $client = new Client($transport ?? Psr18Transport::discover(), $keys, $config, $logger);
        $submitter = new Submitter($client, $config, $debounce ?? new MemoryDebounceStore(), $logger);

        return new self($config, $submitter, new Collector(), $dispatcher ?? new SyncDispatcher($submitter, $logger), $keys, new AttributeReader(), $resolver, $logger);
    }

    /**
     * Submit immediately (bypasses collector/dispatcher). Returns one Result per engine+host+chunk.
     *
     * @param iterable<string> $urls
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
     * @return list<string>
     */
    public function urlsFor(object $subject, Event $event): array
    {
        $attribute = $this->attributes->read($subject);
        if ($attribute === null || !$attribute->listensTo($event)) {
            return [];
        }
        if ($event !== Event::Deleted && !PublishGuard::isPublished($subject, $attribute)) {
            return [];
        }
        if ($this->resolver === null) {
            $this->logger->error('indexnow: no UrlResolver configured, cannot resolve {class}', ['class' => $subject::class]);

            return [];
        }
        $urls = [];
        foreach ($this->resolver->resolve($subject, $event) as $url) {
            $urls[] = $url;
        }

        return $urls;
    }
}
