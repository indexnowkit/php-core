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
}
