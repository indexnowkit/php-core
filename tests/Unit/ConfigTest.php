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

    public function testKeyFileDebounceStoreAndHttpClientOptions(): void
    {
        $c = Config::fromArray(['key' => 'abcdefgh']);
        self::assertTrue($c->serveKeyFile);
        self::assertSame(300, $c->keyFileMaxAge);
        self::assertNull($c->debounceStore);
        self::assertNull($c->httpClient);
        self::assertSame(['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=300'], $c->keyFileHeaders());

        $c = Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'https://a.example.com', 'hosts' => ['a.example.com' => 'abcdefgh'], 'key_file' => ['enabled' => 'false', 'cache_max_age' => '60'], 'debounce' => ['store' => 'cache.app'], 'http' => ['client' => 'app.client']]);
        self::assertFalse($c->serveKeyFile, 'key_file.enabled is the new name of serve_key_file');
        self::assertSame(60, $c->keyFileMaxAge);
        self::assertSame('cache.app', $c->debounceStore);
        self::assertSame('app.client', $c->httpClient);
        self::assertSame('Host', $c->keyFileHeaders()['Vary'], 'a hosts map makes the key file body depend on the host');
        self::assertSame('public, max-age=60', $c->keyFileHeaders()['Cache-Control']);

        self::assertTrue(Config::fromArray(['key' => 'abcdefgh', 'serve_key_file' => true, 'key_file' => ['enabled' => false]])->serveKeyFile, 'an explicit serve_key_file wins, as every adapter did');
        self::assertFalse(Config::fromArray(['key' => 'abcdefgh', 'serve_key_file' => 'false'])->serveKeyFile, 'the string "false" is false');
        self::assertFalse(Config::fromArray(['key' => 'abcdefgh', 'serve_key_file' => false, 'key_file' => ['enabled' => true]])->serveKeyFile);
        self::assertTrue(Config::serveKeyFileFrom([]), 'serveKeyFileFrom(): the default');
        self::assertTrue(Config::serveKeyFileFrom(['serve_key_file' => true, 'key_file' => ['enabled' => false]]), 'serveKeyFileFrom(): the explicit serve_key_file wins');
        self::assertFalse(Config::serveKeyFileFrom(['serve_key_file' => '0', 'key_file' => ['enabled' => true]]), 'serveKeyFileFrom(): the same string parsing as fromArray()');
        self::assertFalse(Config::serveKeyFileFrom(['key_file' => ['enabled' => 'false']]), 'serveKeyFileFrom(): key_file.enabled when serve_key_file is unset');
        self::assertTrue(Config::serveKeyFileFrom(['serve_key_file' => '', 'key_file' => ['enabled' => 1]]), 'serveKeyFileFrom(): an empty string (unset env var) is unset');
        try {
            Config::serveKeyFileFrom(['key_file' => ['enabled' => []]]);
            self::fail('a non-scalar key_file.enabled is rejected');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('"key_file.enabled" must be a boolean', $e->getMessage());
        }
        self::assertNull(Config::fromArray(['key' => 'abcdefgh', 'debounce' => ['store' => ''], 'http' => ['client' => '']])->debounceStore, 'empty strings (unset env vars) are the default');
        self::assertContains('key_file.enabled', Config::OPTIONS);
        self::assertNotContains('key_file', Config::OPTIONS);
        self::assertSame(['key_file.enabld'], Config::unknownOptions(['key_file' => ['enabld' => true, 'cache_max_age' => 1]]));
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
