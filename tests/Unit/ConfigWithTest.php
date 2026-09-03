<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigWithTest extends TestCase
{
    public function testWithReplacesNamedValues(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh', 'engines' => ['yandex', 'bing']]);
        $copy = $config->with(dryRun: true, engines: ['api']);

        self::assertTrue($copy->dryRun);
        self::assertSame(['api'], $copy->engines);
        self::assertSame(['https://api.indexnow.org/indexnow'], $copy->endpoints);
        self::assertFalse($config->dryRun, 'original is untouched');
    }

    public function testWithRejectsUnknownOptionName(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh']);

        $this->expectException(ConfigurationException::class);
        $config->with(bogus: 1);
    }

    public function testWithPreservesHostsKeyLocationsAndEngines(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh', 'hosts' => ['h.example.com' => ['key' => 'abcdefgh12', 'key_location' => 'https://h.example.com/k.txt']]]);
        $copy = $config->with(dryRun: true);

        self::assertSame(['h.example.com' => 'abcdefgh12'], $copy->hosts);
        self::assertSame(['h.example.com' => 'https://h.example.com/k.txt'], $copy->keyLocations);
        self::assertSame($config->engines, $copy->engines);
        self::assertSame($config->endpoints, $copy->endpoints);
    }

    public function testWithDryRunShortcut(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh']);

        self::assertTrue($config->withDryRun(true)->dryRun);
        self::assertFalse($config->withDryRun(false)->dryRun);
    }

    public function testBaseHostLowerCasesAndReturnsNullWithoutBaseUrl(): void
    {
        $withBaseUrl = Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'https://Example.COM/blog']);
        self::assertSame('example.com', $withBaseUrl->baseHost());

        $withoutBaseUrl = Config::fromArray(['key' => 'abcdefgh']);
        self::assertNull($withoutBaseUrl->baseHost());
    }

    public function testUserAgentDefaultContainsVersionAndCanBeOverridden(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh']);

        self::assertStringStartsWith('indexnowkit-php/', $config->userAgent());
        self::assertSame('custom/1', $config->with(userAgent: 'custom/1')->userAgent());
    }
}
