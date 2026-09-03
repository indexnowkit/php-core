<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $c = Config::fromArray(['key' => 'abcdefgh']);
        self::assertTrue($c->enabled);
        self::assertSame(['https://api.indexnow.org/indexnow'], $c->endpoints);
        self::assertSame('sync', $c->dispatch);
        self::assertSame(10000, $c->batchMaxUrls);
        self::assertSame(600, $c->debouncePerUrl);
        self::assertStringStartsWith('indexnowkit-php/', $c->userAgent());
    }

    public function testMissingKeyThrowsUnlessDryRunOrDisabled(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray([]);
    }

    public function testDryRunWithoutKeyIsAllowed(): void
    {
        self::assertTrue(Config::fromArray(['dry_run' => true])->dryRun);
        self::assertFalse(Config::fromArray(['enabled' => false])->enabled);
    }

    public function testCustomEndpointAndUnknownEngine(): void
    {
        $c = Config::fromArray(['key' => 'abcdefgh', 'engines' => ['yandex', 'https://mock.local/indexnow']]);
        self::assertSame(['https://yandex.com/indexnow', 'https://mock.local/indexnow'], $c->endpoints);

        $this->expectException(ConfigurationException::class);
        Config::fromArray(['key' => 'abcdefgh', 'engines' => ['google']]);
    }

    public function testFromEnv(): void
    {
        $c = Config::fromEnv(['INDEXNOW_KEY' => 'abcdefgh', 'INDEXNOW_BASE_URL' => 'https://x.test', 'INDEXNOW_ENGINES' => 'yandex, bing', 'INDEXNOW_DRY_RUN' => '1', 'INDEXNOW_DEBOUNCE_PER_URL' => '5']);
        self::assertSame('https://x.test', $c->baseUrl);
        self::assertCount(2, $c->endpoints);
        self::assertTrue($c->dryRun);
        self::assertSame(5, $c->debouncePerUrl);
    }

    public function testInvalidBaseUrl(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'example.com']);
    }

    public function testBatchBounds(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray(['key' => 'abcdefgh', 'batch' => ['max_urls' => 20000]]);
    }

    public function testStrictHostsRequiresABaseUrlOrAHostsMap(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray(['key' => 'abcdefgh', 'strict_hosts' => true]);
    }

    public function testStrictHostsWithABaseUrlIsAccepted(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'https://www.example.com', 'strict_hosts' => true]);

        self::assertTrue($config->strictHosts);
    }

    public function testStrictHostsWithOnlyAHostsMapIsAccepted(): void
    {
        $config = Config::fromArray(['hosts' => ['h.example.com' => 'abcdefgh12'], 'strict_hosts' => true]);

        self::assertTrue($config->strictHosts);
    }

    public function testHostsBaseUrlMustBeOnItsOwnHost(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray(['key' => 'abcdefgh', 'hosts' => ['h.example.com' => ['key' => 'abcdefgh12', 'base_url' => 'https://other.example.com/']]]);
    }

    public function testHostsBaseUrlMustBeAnAbsoluteHttpUrl(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray(['key' => 'abcdefgh', 'hosts' => ['h.example.com' => ['key' => 'abcdefgh12', 'base_url' => '/relative']]]);
    }

    public function testBaseUrlForReturnsThePerHostOverrideWhenSet(): void
    {
        $config = Config::fromArray([
            'key' => 'abcdefgh',
            'base_url' => 'https://www.example.com',
            'hosts' => ['blog.example.com' => ['key' => 'abcdefgh12', 'base_url' => 'https://blog.example.com/root']],
        ]);

        self::assertSame('https://blog.example.com/root', $config->baseUrlFor('blog.example.com'));
    }

    public function testBaseUrlForFallsBackToTheMainBaseUrlOnItsOwnHost(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'https://www.example.com']);

        self::assertSame('https://www.example.com', $config->baseUrlFor('www.example.com'));
    }

    public function testBaseUrlForIsNullForAnUnrelatedHost(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'https://www.example.com']);

        self::assertNull($config->baseUrlFor('unrelated.example.com'));
    }

    public function testUnknownOptionsFindsTopLevelAndNestedUnknownKeys(): void
    {
        $unknown = Config::unknownOptions(['key' => 'x', 'debounce' => ['per_urls' => 1], 'bogus' => true]);

        self::assertSame(['debounce.per_urls', 'bogus'], $unknown);
    }

    public function testUnknownOptionsIgnoresTheHostsMapEntirely(): void
    {
        $unknown = Config::unknownOptions(['hosts' => ['h.example.com' => 'abcdefgh12']]);

        self::assertSame([], $unknown);
    }

    public function testUnknownOptionsHonoursAnAllowedPrefix(): void
    {
        $unknown = Config::unknownOptions(['messenger' => ['transport' => 'async']], ['messenger.transport']);

        self::assertSame([], $unknown);
    }

    public function testIsProductionMatchesTheConfiguredProductionEnvironmentsCaseInsensitively(): void
    {
        self::assertTrue(Config::fromArray(['key' => 'abcdefgh', 'environment' => 'production'])->isProduction());
        self::assertTrue(Config::fromArray(['key' => 'abcdefgh', 'environment' => 'PROD'])->isProduction());
        self::assertFalse(Config::fromArray(['key' => 'abcdefgh', 'environment' => 'staging'])->isProduction());
        self::assertFalse(Config::fromArray(['key' => 'abcdefgh'])->isProduction());
    }

    public function testWithUnknownOptionMessageListsTheKnownOptionNames(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh']);

        try {
            $config->with(bogus: 1);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('Unknown Config option "bogus"', $e->getMessage());
            self::assertStringContainsString('strictHosts', $e->getMessage());
        }
    }

    public function testFromEnvReadsStrictHosts(): void
    {
        $config = Config::fromEnv(['INDEXNOW_KEY' => 'abcdefgh', 'INDEXNOW_BASE_URL' => 'https://x.test', 'INDEXNOW_STRICT_HOSTS' => 'true']);

        self::assertTrue($config->strictHosts);
    }
}
