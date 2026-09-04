<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Adapter;

use IndexNowKit\Adapter\ConfigFactory;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Hook\ObserverHelper;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\KeyFileAssertions;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\RouteUrlResolverInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * The "20-minute adapter" of docs/adapters.md §2, verbatim but for the two container stubs and the injected transport.
 */
final class IndexNowIntegration
{
    public readonly Services $services;
    private readonly ObserverHelper $hooks;

    /** @param array<string, mixed> $frameworkConfig the raw config array, your own blocks included */
    public function __construct(array $frameworkConfig, ?string $environment, LoggerInterface $logger, ?RouteUrlResolverInterface $router = null, ?FakeTransport $transport = null)
    {
        // Never throws: an invalid value is one critical log line and a disabled Config until it is fixed.
        $config = (new ConfigFactory(ownedOptions: ['myfw.route_prefix'], checkCommand: 'myfw indexnow:check'))->load($frameworkConfig, $environment, $logger);
        $builder = (new ServicesBuilder($config, $logger))
            ->httpClientLocator(fn(string $id): object => $this->service($id) ?? throw new RuntimeException($id))
            ->debounceStore(fn(Services $s): DebounceStoreInterface => DebounceStoreFactory::fromConfig($s->config, fn(string $id) => $this->cache($id)))
            ->resolverLocator(new ArrayResolverLocator([], locate: fn(string $id) => $this->service($id), hint: 'a service id'));
        if ($router !== null) {
            $builder->router($router);
        }
        if ($transport !== null) {
            $builder->transport($transport);
        }
        $this->services = $builder->build();                      // no IO: nothing is built before it is used
        $this->hooks = new ObserverHelper($this->services->kit(), $logger);
    }

    /**
     * Model save hook.
     *
     * @param list<string> $changedFields
     */
    public function onSaved(object $model, array $changedFields): void
    {
        $urls = $this->hooks->guard($model, static fn(ObjectChangeHandler $changes): array => $changes->updated($model, $changedFields));
        $this->hooks->deliver($urls ?? []);
    }

    /** Model delete hook, before the row disappears: resolve now, deliver once it is gone. */
    public function onDeleting(object $model): void
    {
        $urls = $this->hooks->guard($model, static fn(ObjectChangeHandler $changes): array => $changes->deleted($model));
        $this->hooks->rememberDeletion($model, $urls ?? []);
    }

    public function onDeleted(object $model): void
    {
        $this->hooks->deliver($this->hooks->takeDeletion($model) ?? []);
    }

    /** End of the unit of work: after the response was sent, if the platform allows it. Never throws. */
    public function onShutdown(): void
    {
        $this->services->flushIfCollected();
    }

    /**
     * GET /{key}.txt
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public function keyFileResponse(string $path, string $host): ?array
    {
        $body = $this->services->keyFileResponder()->bodyForPath($path, $host);

        return $body === null ? null : [$body, $this->services->config->keyFileHeaders()];
    }

    /** How your framework resolves `debounce.store` to a PSR-16 cache and `#[IndexNow(resolver: ...)]` / `http.client` ids to services. */
    private function cache(string $id): CacheInterface
    {
        return new Psr16Cache(new ArrayAdapter());
    }

    private function service(string $id): ?object
    {
        return null;
    }
}

#[IndexNowAttribute(url: 'url', when: 'published')]
final class TwentyMinutePost
{
    public function __construct(public string $slug, public bool $published = true) {}

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }
}

final class TwentyMinuteAdapterTest extends TestCase
{
    #[TestDox('the documented adapter submits on save and delete at shutdown, serves the key file, and disables itself on an invalid value')]
    public function testDocumentedAdapter(): void
    {
        $transport = new FakeTransport();
        $logger = new ArrayLogger();
        $adapter = new IndexNowIntegration(['key' => Factory::KEY, 'base_url' => 'https://www.example.com', 'myfw' => ['route_prefix' => '/x'], 'debounce' => ['store' => 'app.cache'], 'key_file' => ['cache_max_age' => 60]], 'prod', $logger, transport: $transport);

        $adapter->onSaved(new TwentyMinutePost('hello'), ['title']);
        $bye = new TwentyMinutePost('bye');
        $adapter->onDeleting($bye);
        $adapter->onDeleted($bye);
        self::assertSame([], $transport->posts, 'nothing leaves before the unit of work ends');
        $adapter->onShutdown();
        self::assertCount(1, $transport->posts);
        self::assertSame(['https://www.example.com/posts/hello', 'https://www.example.com/posts/bye'], $transport->posts[0]['body']['urlList']);
        self::assertSame([], $logger->messages('warning'), 'myfw.route_prefix is an owned option');
        self::assertSame([], $logger->messages('error'));

        $response = $adapter->keyFileResponse('/' . Factory::KEY . '.txt', 'www.example.com');
        self::assertNotNull($response);
        KeyFileAssertions::assertKeyFileResponse(200, $response[1], $response[0], Factory::KEY, 60);
        self::assertNull($adapter->keyFileResponse('/nope.txt', 'www.example.com'));

        $disabled = new IndexNowIntegration(['key' => 'short'], 'prod', $logger, transport: $transport);
        $disabled->onSaved(new TwentyMinutePost('x'), []);
        $disabled->onShutdown();
        self::assertCount(1, $transport->posts, 'disabled: nothing more is sent');
        self::assertStringContainsString('run "myfw indexnow:check"', implode("\n", $logger->messages('critical')));
    }
}
