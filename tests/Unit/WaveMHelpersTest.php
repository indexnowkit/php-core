<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Check\DispatchLine;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\TransportFactory;
use IndexNowKit\Tests\Support\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** The options provider of a package, the way `*Services::options()` of the family answers. */
final class FakePackageOptions
{
    /** @return list<string> */
    public static function options(): array
    {
        return ['fake.url', 'fake.enabled'];
    }

    public static function notAList(): string
    {
        return 'fake.url';
    }
}

/**
 * The one-line helpers of wave M (spec 19 §4.10, §4.7, §4.8): the shared-store predicate, the probe key, the
 * dispatch sentence, the options of an optional package, the PSR-17 factories of an application handed to the
 * transport without discovery.
 */
final class WaveMHelpersTest extends TestCase
{
    #[TestDox('isShared(): an id is shared, memory, none and unset are not')]
    public function testIsShared(): void
    {
        self::assertTrue(DebounceStoreFactory::isShared('cache'));
        self::assertTrue(DebounceStoreFactory::isShared('redis'));
        self::assertFalse(DebounceStoreFactory::isShared(DebounceStoreFactory::MEMORY));
        self::assertFalse(DebounceStoreFactory::isShared(DebounceStoreFactory::NONE));
        self::assertFalse(DebounceStoreFactory::isShared(null));
    }

    #[TestDox('the probe key carries none of the characters PSR-16 reserves')]
    public function testProbeKey(): void
    {
        self::assertSame('indexnowkit_check', DebounceStoreCheck::PROBE_KEY);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', DebounceStoreCheck::PROBE_KEY);
    }

    #[TestDox('DispatchLine::describe(): the sync sentence for anything but none')]
    public function testDispatchLine(): void
    {
        self::assertSame('dispatch "sync": URLs are sent synchronously when the unit of work ends; 429/5xx are not retried', DispatchLine::describe('sync'));
        self::assertSame('dispatch "none": URLs are collected but never sent (drain the collector yourself)', DispatchLine::describe('none'));
        self::assertStringStartsWith('dispatch "queue": URLs are sent synchronously', DispatchLine::describe('queue'));
    }

    #[TestDox('options(): the provider answers only for an installed package; ownedOptions() and ignoredBlocks() fold a list of predicates')]
    public function testPackageOptions(): void
    {
        $installed = new OptionalPackage('indexnowkit/fake', self::class, 'fake', null, FakePackageOptions::class . '::options');
        $absent = new OptionalPackage('indexnowkit/fake', 'IndexNowKit\\Nope\\Reader', 'fake', null, FakePackageOptions::class . '::options');
        $silent = new OptionalPackage('indexnowkit/fake', self::class, 'fake');

        self::assertSame(['fake.url', 'fake.enabled'], $installed->options());
        self::assertSame([], $absent->options(), 'the provider is not called for an absent package');
        self::assertSame([], $silent->options(), 'no provider: no options');
        self::assertSame(['fake.url', 'fake.enabled', 'fake.url', 'fake.enabled'], OptionalPackage::ownedOptions([$installed, $absent, $installed]));
        self::assertSame(['fake'], OptionalPackage::ignoredBlocks([$installed, $absent]));

        self::assertSame([], OptionalPackage::sitemap()->options(), 'the core does not require the packages: nothing is loaded');
        self::assertSame(['sitemap', 'verify', 'history'], OptionalPackage::ignoredBlocks([OptionalPackage::sitemap(), OptionalPackage::verify(), OptionalPackage::history()]));

        try {
            (new OptionalPackage('indexnowkit/fake', self::class, 'fake', null, FakePackageOptions::class . '::notAList'))->options();
            self::fail('a provider that returns no list is a configuration error');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('must return the option keys of indexnowkit/fake as a list of strings, got string', $e->getMessage());
        }
        try {
            (new OptionalPackage('indexnowkit/fake', self::class, 'fake', null, 'IndexNowKit\\Nope::options'))->options();
            self::fail('a provider that is not callable is a configuration error');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('is installed but IndexNowKit\\Nope::options is not callable', $e->getMessage());
        }
    }

    #[TestDox('discover() and TransportFactory::lazy() take the PSR-17 factories of the application and use them for every request')]
    public function testExplicitPsr17Factories(): void
    {
        $factory = new Psr17Factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], 'ok'));

        $transport = Psr18Transport::discover($client, requestFactory: $factory, streamFactory: $factory);
        self::assertSame(200, $transport->get('https://www.example.com/key.txt')->status);
        self::assertCount(1, $client->requests);

        $lazy = TransportFactory::lazy(Factory::config(['http' => ['client' => 'app.client']]), static fn(string $id): object => $client, requestFactory: $factory, streamFactory: $factory);
        self::assertSame(200, $lazy->post('https://api.indexnow.org/indexnow', '{}')->status);
        self::assertCount(2, $client->requests);
        self::assertSame('application/json; charset=utf-8', $client->requests[1]->getHeaderLine('Content-Type'));
    }
}
