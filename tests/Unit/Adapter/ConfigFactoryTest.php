<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Adapter;

use IndexNowKit\Adapter\ConfigFactory;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\ArrayLogger;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ConfigFactoryTest extends TestCase
{
    private const KEY = 'abcdef1234567890abcdef1234567890';

    private static function laravelLike(bool $queueExists = true): ConfigFactory
    {
        return new ConfigFactory(
            ownedOptions: ['queue.connection', 'queue.delay', 'key_file.path', 'eloquent.enabled', 'logging.channel'],
            dispatchModes: ['queue', 'sync', 'none'],
            autoDispatch: static fn(): string => $queueExists ? 'queue' : 'sync',
            needBaseUrl: ['queue'],
            defaults: ['dispatch' => 'queue', 'debounce' => ['store' => 'cache', 'per_url' => 60], 'http' => ['timeout' => 4.0], 'queue' => ['delay' => 0]],
            validate: static fn(Config $c): ?string => $c->dispatch === 'queue' && !$queueExists ? 'the queue component "queue" is not configured' : null,
            checkCommand: 'php artisan indexnow:check',
        );
    }

    #[TestDox('defaults sit under raw: a raw scalar replaces, a known block merges by key, a list from raw is kept as given')]
    public function testMerge(): void
    {
        $config = self::laravelLike()->build([
            'key' => self::KEY, 'base_url' => 'https://www.example.com',
            'debounce' => ['per_url' => 5],
            'engines' => ['yandex', 'bing'],
            'hosts' => ['www.example.com' => self::KEY],
            'production_environments' => ['live'],
        ], 'live');

        self::assertSame('queue', $config->dispatch, 'adapter default');
        self::assertSame('cache', $config->debounceStore, 'default of the block survives a raw sibling key');
        self::assertSame(5, $config->debouncePerUrl, 'raw wins inside the block');
        self::assertSame(4.0, $config->httpTimeout);
        self::assertSame(['yandex', 'bing'], $config->engines, 'lists come from raw untouched');
        self::assertSame(['www.example.com' => self::KEY], $config->hosts);
        self::assertSame(['live'], $config->productionEnvironments);
        self::assertSame('live', $config->environment);
        self::assertTrue($config->isProduction());
    }

    #[TestDox('a top-level scalar in raw replaces the default; an explicit environment in raw wins over the framework one')]
    public function testRawScalarsReplaceDefaults(): void
    {
        $config = self::laravelLike()->build(['key' => self::KEY, 'dispatch' => 'none', 'environment' => 'staging'], 'production');

        self::assertSame('none', $config->dispatch);
        self::assertSame('staging', $config->environment);
    }

    #[TestDox('dispatch auto resolves through the closure; without one it is an error listing the modes')]
    public function testAuto(): void
    {
        self::assertSame('queue', self::laravelLike(true)->build(['key' => self::KEY, 'base_url' => 'https://www.example.com', 'dispatch' => 'auto'], null)->dispatch);
        self::assertSame('sync', self::laravelLike(false)->build(['key' => self::KEY, 'dispatch' => 'auto'], null)->dispatch);

        try {
            (new ConfigFactory())->build(['key' => self::KEY, 'dispatch' => 'auto'], null);
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertSame('"dispatch" is "auto", which this adapter does not support; use one of: sync, none.', $e->getMessage());
        }
    }

    #[TestDox('a dispatch mode the adapter cannot deliver is rejected with the list of modes')]
    public function testUnknownDispatch(): void
    {
        try {
            self::laravelLike()->build(['key' => self::KEY, 'dispatch' => 'messenger'], null);
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertSame('"dispatch" must be one of auto, queue, sync, none, got "messenger".', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"dispatch" must be one of sync, none, got "queue".');
        (new ConfigFactory())->build(['key' => self::KEY, 'dispatch' => 'queue'], null);
    }

    #[TestDox('a worker mode without base_url is an error; the validate closure turns a string into the exception')]
    public function testNeedBaseUrlAndValidate(): void
    {
        try {
            self::laravelLike()->build(['key' => self::KEY], null);
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertSame('"dispatch" is "queue" but "base_url" is not set: a worker has no request to take the host from.', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('the queue component "queue" is not configured');
        self::laravelLike(false)->build(['key' => self::KEY, 'base_url' => 'https://www.example.com', 'dispatch' => 'queue'], null);
    }

    #[TestDox('load() never throws: unknown keys (inside owned blocks too) are a warning, an invalid value a critical line and a disabled Config')]
    public function testLoad(): void
    {
        $logger = new ArrayLogger();
        $factory = self::laravelLike();

        $config = $factory->load(['key' => self::KEY, 'base_url' => 'https://www.example.com', 'typo' => 1, 'key_file' => ['enabld' => false], 'queue' => ['connection' => 'redis']], 'production', $logger);
        self::assertTrue($config->enabled);
        self::assertSame(['typo', 'key_file.enabld'], $factory->unknownOptions(['typo' => 1, 'key_file' => ['enabld' => false], 'queue' => ['connection' => 'redis']]));
        self::assertCount(1, $logger->messages('warning'));
        self::assertStringContainsString('unknown option(s) in the indexnow configuration: typo, key_file.enabld', $logger->messages('warning')[0]);

        $disabled = $factory->load(['key' => 'short'], 'production', $logger);
        self::assertFalse($disabled->enabled);
        self::assertTrue($disabled->dryRun);
        self::assertSame('production', $disabled->environment);
        self::assertCount(1, $logger->messages('critical'));
        self::assertStringContainsString('IndexNow is disabled until it is fixed', $logger->messages('critical')[0]);
        self::assertStringContainsString('(run "php artisan indexnow:check")', $logger->messages('critical')[0]);
        self::assertEquals(ConfigFactory::disabled('production'), $disabled);
    }

    #[TestDox('lists and nested blocks in defaults are refused at construction')]
    public function testListsInDefaultsAreRefused(): void
    {
        try {
            new ConfigFactory(defaults: ['engines' => ['yandex']]);
            self::fail('expected an exception');
        } catch (LogicException $e) {
            self::assertStringContainsString('"engines"', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        new ConfigFactory(defaults: ['debounce' => ['store' => ['nested' => true]]]);
    }

    #[TestDox('a block named only by the owned options merges too; a block that is not in defaults comes from raw as is')]
    public function testOwnedBlocksMerge(): void
    {
        $factory = new ConfigFactory(ownedOptions: ['queue.connection', 'queue.delay'], dispatchModes: ['queue', 'sync'], needBaseUrl: [], defaults: ['queue' => ['delay' => 30]]);
        $config = $factory->build(['key' => self::KEY, 'dispatch' => 'queue', 'queue' => ['connection' => 'redis'], 'logging' => ['max_urls' => 3]], null);

        self::assertSame(3, $config->logUrls);
        self::assertSame('queue', $config->dispatch);
    }
}
