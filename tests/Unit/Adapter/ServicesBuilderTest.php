<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Adapter;

use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\CheckInterface;
use IndexNowKit\Check\CheckItem;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Dispatch\DispatcherInterface;
use IndexNowKit\Dispatch\NullDispatcher;
use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\LazyTransport;
use IndexNowKit\Http\Response;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Result;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\RecordingDispatcher;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\ResolvedUrl;
use IndexNowKit\Url\RouteUrlResolverInterface;
use IndexNowKit\Url\UrlResolverInterface;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use stdClass;

final class ServicesBuilderPost
{
    public function __construct(public string $slug) {}

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }
}

/**
 * `Adapter\ServicesBuilder` / `Adapter\Services` (docs/spec/16 §3.2): the lazy graph of a runtime-assembled adapter.
 */
final class ServicesBuilderTest extends TestCase
{
    #[TestDox('build() does no IO and builds nothing; a closure node is called once, with the Services, on first use')]
    public function testLazy(): void
    {
        $calls = 0;
        $transport = new FakeTransport();
        $services = (new ServicesBuilder(Factory::config()))
            ->transport(static function (Services $s) use (&$calls, $transport): TransportInterface {
                ++$calls;

                return $transport;
            })
            ->build();

        self::assertSame(0, $calls, 'build() calls no closure');
        self::assertFalse($services->hasCollected(), 'no collector was built');
        self::assertSame($transport, $services->transport());
        self::assertSame($transport, $services->transport());
        self::assertSame($transport, $services->kit()->transport, 'the facade gets the same transport');
        self::assertSame(1, $calls, 'memoized');
        self::assertInstanceOf(LazyTransport::class, (new ServicesBuilder(Factory::config()))->build()->transport(), 'the default transport discovers on first use only');
    }

    #[TestDox('an overridden node is what every dependent node uses')]
    public function testOverridesPropagate(): void
    {
        $transport = new FakeTransport();
        $logger = new ArrayLogger();
        $dispatcher = new RecordingDispatcher();
        $services = (new ServicesBuilder(Factory::config(['dispatch' => 'queue']), $logger))
            ->transport($transport)
            ->debounceStore(new NullDebounceStore())
            ->dispatcher($dispatcher)
            ->build();

        $services->kit()->submit(['/a']);
        self::assertCount(1, $transport->posts, 'the client submits through the given transport');
        $services->submitterFactory()->create(false, false)->submit(['/a']);
        self::assertCount(2, $transport->posts, 'the console submitters too, and the NullDebounceStore lets the URL through twice');
        $services->kit()->collect(['/b']);
        self::assertTrue($services->hasCollected());
        $services->kit()->flush();
        self::assertSame(['https://www.example.com/b'], $dispatcher->urls(), 'the given dispatcher replaces the factory, dispatch mode included');
        $services->checker()->run();
        self::assertNotEmpty($transport->gets, 'the checker fetches the key file through the given transport');
    }

    #[TestDox('build() throws for what is known before the first request: a store id without a store, http.client without a locator, a queue mode without a queue')]
    public function testStaticErrors(): void
    {
        try {
            (new ServicesBuilder(Factory::config(['debounce' => ['store' => 'cache.app']])))->build();
            self::fail();
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"debounce.store" "cache.app" needs a cache locator', $e->getMessage());
        }
        self::assertInstanceOf(MemoryDebounceStore::class, (new ServicesBuilder(Factory::config(['debounce' => ['store' => 'cache.app']])))->debounceStore(new MemoryDebounceStore())->build()->debounceStore(), 'debounceStore() is the way to resolve an id');

        try {
            (new ServicesBuilder(Factory::config(['http' => ['client' => 'app.client']])))->build();
            self::fail();
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"http.client" is "app.client" but this adapter has no way to resolve it', $e->getMessage());
        }
        $located = (new ServicesBuilder(Factory::config(['http' => ['client' => 'app.client']])))->httpClientLocator(static fn(string $id): stdClass => new stdClass())->build();
        try {
            $located->transport()->post('https://www.example.com/', '{}');
            self::fail();
        } catch (TransportException $e) {
            self::assertStringContainsString('"http.client" "app.client" resolves to stdClass, which is not a PSR-18 client', $e->getMessage(), 'the locator result is checked on first use');
            self::assertInstanceOf(ConfigurationException::class, $e->getPrevious());
        }
        self::assertInstanceOf(FakeTransport::class, (new ServicesBuilder(Factory::config(['http' => ['client' => 'app.client']])))->transport(new FakeTransport())->build()->transport(), 'a given transport makes the option irrelevant');

        try {
            (new ServicesBuilder(Factory::config(['dispatch' => 'queue'])))->build();
            self::fail();
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"dispatch" "queue" needs a queue dispatcher', $e->getMessage());
        }
        self::assertInstanceOf(NullDispatcher::class, (new ServicesBuilder(Factory::config(['dispatch' => 'queue', 'enabled' => false])))->build()->dispatcher(), 'disabled: no queue is needed, nothing is dispatched');

        $queue = new RecordingDispatcher();
        $seen = null;
        $services = (new ServicesBuilder(Factory::config(['dispatch' => 'queue'])))->queueFactory(static function (Services $s) use (&$seen, $queue): DispatcherInterface {
            $seen = $s;

            return $queue;
        })->build();
        self::assertNull($seen, 'the queue is built on first dispatch');
        self::assertSame($queue, $services->dispatcher());
        self::assertSame($services, $seen);
        self::assertInstanceOf(SyncDispatcher::class, (new ServicesBuilder(Factory::config()))->queueFactory(static fn(): DispatcherInterface => throw new RuntimeException('not for sync'))->build()->dispatcher());
    }

    #[TestDox('a closure returning the wrong type is a programming error, named after the node')]
    public function testTypedNodes(): void
    {
        $services = (new ServicesBuilder(Factory::config()))->keys(static fn(): stdClass => new stdClass())->build();
        try {
            $services->keys();
            self::fail();
        } catch (LogicException $e) {
            self::assertSame('ServicesBuilder::keys(): the closure must return IndexNowKit\Key\KeyProviderInterface, got stdClass.', $e->getMessage());
        }
        $services = (new ServicesBuilder(Factory::config(['dispatch' => 'queue'])))->queueFactory(static fn(): stdClass => new stdClass())->build();
        try {
            $services->dispatcher();
            self::fail();
        } catch (LogicException $e) {
            self::assertStringContainsString('ServicesBuilder::queueFactory(): the closure must return IndexNowKit\Dispatch\DispatcherInterface', $e->getMessage());
        }
        $services = (new ServicesBuilder(Factory::config()))->checks(static fn(): stdClass => new stdClass())->build();
        try {
            $services->checker();
            self::fail();
        } catch (LogicException $e) {
            self::assertStringContainsString('ServicesBuilder::checks(): the closure must return an iterable of CheckInterface', $e->getMessage());
        }
    }

    #[TestDox('rules() is the given RuleRegistry, or decorates any other reader; the resolver, facade and change handler read through it')]
    public function testRules(): void
    {
        $registry = new RuleRegistry(new AttributeReader());
        $services = (new ServicesBuilder(Factory::config()))->reader($registry)->build();
        self::assertSame($registry, $services->rules());
        self::assertSame($registry, $services->reader());

        $plain = new AttributeReader();
        $services = (new ServicesBuilder(Factory::config()))->transport(new FakeTransport())->reader($plain)->build();
        self::assertSame($plain, $services->reader());
        self::assertNotSame($plain, $services->rules());
        $services->rules()->register(ServicesBuilderPost::class, [new IndexNow(url: 'url')]);
        self::assertSame(['/posts/hello'], $services->kit()->urlsFor(new ServicesBuilderPost('hello')), 'a rule registered on rules() reaches the facade');
        self::assertSame(['/posts/hello'], ResolvedUrl::urls($services->changes()->updated(new ServicesBuilderPost('hello'), ['slug'])), 'and the change handler');
        self::assertSame($services->kit()->changes(), $services->changes(), 'the facade\'s own change handler');
        self::assertSame($services->kit()->resolver(), $services->guardedResolver());
    }

    #[TestDox('router and resolver locator are optional and reach the attribute resolver; urlResolver() replaces it entirely')]
    public function testResolverNodes(): void
    {
        $services = (new ServicesBuilder(Factory::config()))->build();
        self::assertNull($services->router());
        self::assertNull($services->resolverLocator());
        self::assertInstanceOf(AttributeUrlResolver::class, $services->urlResolver());

        $router = new class implements RouteUrlResolverInterface {
            public function locales(array|string $locales): array
            {
                return [null];
            }

            public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
            {
                return 'https://www.example.com/r/' . $route;
            }
        };
        $locator = new ArrayResolverLocator([]);
        $services = (new ServicesBuilder(Factory::config()))->router($router)->resolverLocator(static fn(): ArrayResolverLocator => $locator)->build();
        self::assertSame($router, $services->router());
        self::assertSame($locator, $services->resolverLocator());
        $services->rules()->register(ServicesBuilderPost::class, [new IndexNow(route: 'post_show')]);
        self::assertSame(['https://www.example.com/r/post_show'], $services->kit()->urlsFor(new ServicesBuilderPost('x')), 'the router bridge is wired into the attribute resolver');

        $custom = new CallableUrlResolver(static fn(object $subject): array => ['https://www.example.com/custom']);
        $services = (new ServicesBuilder(Factory::config()))->urlResolver($custom)->build();
        self::assertSame($custom, $services->urlResolver());
        self::assertInstanceOf(UrlResolverInterface::class, $services->guardedResolver());
        $services->rules()->register(ServicesBuilderPost::class, [new IndexNow(url: 'url')]);
        self::assertSame(['https://www.example.com/custom'], $services->kit()->urlsFor(new ServicesBuilderPost('x')));
    }

    #[TestDox('checks() adds the adapter\'s lines to the checker; events() reaches the submitter and the console submitters')]
    public function testChecksAndEvents(): void
    {
        $transport = new FakeTransport();
        $transport->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $events = new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };
        $check = new class implements CheckInterface {
            public function check(CheckReport $report): void
            {
                $report->ok('myfw: hooked');
            }
        };
        $seen = null;
        $services = (new ServicesBuilder(Factory::config(['environment' => 'dev'])))
            ->transport($transport)
            ->events($events)
            ->checks(static function (Services $s) use (&$seen, $check): iterable {
                $seen = $s;

                return [$check];
            })
            ->build();

        $messages = array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->message, $services->checker()->run()->items());
        self::assertContains('ok myfw: hooked', $messages);
        self::assertSame($services, $seen);

        $services->kit()->submit(['/a']);
        $services->submitterFactory()->create(true, false)->submit(['/a']);
        self::assertCount(2, $events->events);
        self::assertContainsOnlyInstancesOf(Result::class, $events->events);
        $messages = array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->message, (new ServicesBuilder(Factory::config(['environment' => 'dev'])))->transport($transport)->checks([$check])->build()->checker()->run()->items());
        self::assertContains('ok myfw: hooked', $messages, 'an iterable is accepted as is');
    }

    #[TestDox('flushIfCollected() is the request-end hook: nothing built when nothing was collected, an error log line instead of an exception')]
    public function testFlushIfCollected(): void
    {
        $logger = new ArrayLogger();
        $transport = new FakeTransport();
        $services = (new ServicesBuilder(Factory::config(), $logger))->transport($transport)->build();
        $services->flushIfCollected();
        self::assertFalse($services->hasCollected());

        $services->kit()->collect(['/a']);
        self::assertTrue($services->hasCollected());
        $services->flushIfCollected();
        self::assertFalse($services->hasCollected());
        self::assertCount(1, $transport->posts);

        $broken = (new ServicesBuilder(Factory::config(), $logger))->transport($transport)->dispatcher(new class implements DispatcherInterface {
            public function dispatch(array $urls): void
            {
                throw new RuntimeException('queue down');
            }
        })->build();
        $broken->kit()->collect(['/b']);
        $broken->flushIfCollected();
        self::assertSame(['indexnow: flush failed: queue down'], $logger->messages('error'));
        self::assertSame(StaticKeyProvider::class, $services->keys()::class);
    }
}
