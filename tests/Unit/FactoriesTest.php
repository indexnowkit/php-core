<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\LazyTransport;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\RuleAwareUrlResolverInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * The static factories every adapter wires the graph with (docs/spec/16 §3.1): one source of the error texts.
 */
final class FactoriesTest extends TestCase
{
    #[TestDox('TransportFactory::lazy discovers without http.client, resolves it through the locator otherwise, and refuses an id without a locator')]
    public function testTransportFactory(): void
    {
        $lazy = TransportFactory::lazy(Factory::config());
        self::assertInstanceOf(LazyTransport::class, $lazy);
        self::assertInstanceOf(Psr18Transport::class, $lazy->transport(), 'symfony/http-client + nyholm/psr7 are discovered in the test suite');

        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('not called');
            }
        };
        $located = [];
        $lazy = TransportFactory::lazy(Factory::config(['http' => ['client' => 'app.client']]), static function (string $id) use ($client, &$located): object {
            $located[] = $id;

            return $client;
        });
        self::assertSame([], $located, 'nothing is resolved until the first request');
        self::assertInstanceOf(Psr18Transport::class, $lazy->transport());
        self::assertSame(['app.client'], $located);

        try {
            TransportFactory::lazy(Factory::config(['http' => ['client' => 'app.client']]));
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"http.client" is "app.client" but this adapter has no way to resolve it', $e->getMessage());
        }

        $wrong = TransportFactory::lazy(Factory::config(['http' => ['client' => 'app.client']]), static fn(string $id): object => new stdClass());
        try {
            $wrong->transport();
            self::fail('expected an exception');
        } catch (TransportException $e) {
            self::assertStringContainsString('"http.client" "app.client" resolves to stdClass, which is not a PSR-18 client.', $e->getMessage());
        }
        self::assertSame($client, TransportFactory::psr18($client, 'x'));
    }

    #[TestDox('DebounceStoreFactory maps memory/none, wraps a located PSR-16 cache with the key prefix, accepts a ready store, refuses an id without a locator')]
    public function testDebounceStoreFactory(): void
    {
        self::assertInstanceOf(MemoryDebounceStore::class, DebounceStoreFactory::fromConfig(Factory::config()));
        self::assertInstanceOf(NullDebounceStore::class, DebounceStoreFactory::fromConfig(Factory::config(['debounce' => ['store' => 'none']])));
        self::assertInstanceOf(NullDebounceStore::class, DebounceStoreFactory::fromConfig(Factory::config(), default: 'none'), 'the adapter default applies when the option is unset');

        $cache = new Psr16Cache(new ArrayAdapter());
        $config = Factory::config(['debounce' => ['store' => 'cache.app', 'key_prefix' => 'pfx_']]);
        $store = DebounceStoreFactory::fromConfig($config, static fn(string $id): CacheInterface => $cache);
        self::assertInstanceOf(Psr16DebounceStore::class, $store);
        $store->markSubmitted(['https://www.example.com/a'], 60);
        self::assertTrue($cache->has('pfx_' . sha1('https://www.example.com/a')), 'debounce.key_prefix reaches the store');

        $ready = new NullDebounceStore();
        self::assertSame($ready, DebounceStoreFactory::fromConfig($config, static fn(string $id): DebounceStoreInterface => $ready), 'a framework whose cache is not PSR-16 returns its own store');

        try {
            DebounceStoreFactory::fromConfig($config);
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"debounce.store" "cache.app" needs a cache locator', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"debounce.store" "cache.app" resolves to stdClass, which is not a PSR-16 cache.');
        DebounceStoreFactory::fromConfig($config, static fn(string $id): object => new stdClass());
    }

    #[TestDox('DispatcherFactory: disabled or none is NullDispatcher, sync is SyncDispatcher, anything else needs the queue closure')]
    public function testDispatcherFactory(): void
    {
        $logger = new ArrayLogger();
        $submitter = Factory::submitter(new FakeTransport());
        self::assertInstanceOf(NullDispatcher::class, DispatcherFactory::fromConfig(Factory::config(['enabled' => false]), $submitter, $logger));
        self::assertInstanceOf(NullDispatcher::class, DispatcherFactory::fromConfig(Factory::config(['dispatch' => 'none']), $submitter, $logger));
        self::assertInstanceOf(SyncDispatcher::class, DispatcherFactory::fromConfig(Factory::config(), $submitter, $logger));

        $queue = new NullDispatcher();
        self::assertSame($queue, DispatcherFactory::fromConfig(Factory::config(['dispatch' => 'queue']), $submitter, $logger, static fn(): DispatcherInterface => $queue));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"dispatch" "queue" needs a queue dispatcher, which this adapter does not provide; use "sync" or "none".');
        DispatcherFactory::fromConfig(Factory::config(['dispatch' => 'queue']), $submitter, $logger);
    }

    #[TestDox('Collector, TokenBucket, AttributeUrlResolver and KeyFileResponder read their knobs from the Config')]
    public function testFromConfigFactories(): void
    {
        $config = Factory::config(['collector' => ['detect_leaks' => false], 'logging' => ['max_urls' => 2], 'throttle' => ['max_requests_per_minute' => 0], 'resolver' => ['max_via_depth' => 1, 'max_via_fanout' => 2], 'locale_hosts' => ['de' => 'example.de'], 'key_file' => ['enabled' => false]]);
        $logger = new ArrayLogger();

        $collector = Collector::fromConfig($config, $logger);
        $collector->add(['https://www.example.com/a', 'https://www.example.com/a', 'https://www.example.com/b']);
        self::assertSame(2, $collector->count());
        $collector->reset();
        self::assertCount(1, $logger->messages('warning'), 'a reset of a non-empty buffer is the leak warning');

        $bucket = TokenBucket::fromConfig($config, $logger);
        $bucket->acquire();
        $bucket->acquire();
        self::assertInstanceOf(TokenBucket::class, $bucket, 'throttle 0 = unlimited, acquire() returns at once');

        $resolver = AttributeUrlResolver::fromConfig($config, new AttributeReader(), logger: $logger);
        self::assertInstanceOf(RuleAwareUrlResolverInterface::class, $resolver);

        $responder = KeyFileResponder::fromConfig($config, StaticKeyProvider::fromConfig($config));
        self::assertNull($responder->bodyForKey(Factory::KEY, 'www.example.com'), 'key_file.enabled: false serves nothing');
        self::assertSame(Factory::KEY, KeyFileResponder::fromConfig(Factory::config(), StaticKeyProvider::fromConfig($config))->bodyForKey(Factory::KEY, 'www.example.com'));
    }

    #[TestDox('IndexNowKit::create() goes through the factories: debounce.store and dispatch from the Config, the incompatible-combination check kept')]
    public function testCreateThroughFactories(): void
    {
        $kit = IndexNowKit::create(Factory::config(['debounce' => ['store' => 'none'], 'dispatch' => 'none']), new FakeTransport());
        self::assertInstanceOf(NullDispatcher::class, $kit->dispatcher);
        $kit->submit(['https://www.example.com/a']);
        $kit->submit(['https://www.example.com/a']);
        self::assertCount(2, $kit->transport instanceof FakeTransport ? $kit->transport->posts : [], 'debounce.store none: nothing is debounced');

        try {
            IndexNowKit::create(Factory::config(['dispatch' => 'queue']), new FakeTransport());
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('needs a queue dispatcher', $e->getMessage());
        }
        try {
            IndexNowKit::create(Factory::config(), new FakeTransport(), submitter: Factory::submitter(new FakeTransport()), debounce: new NullDebounceStore());
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('$transport, $debounce cannot be combined with a custom $submitter', $e->getMessage());
        }
    }
}
