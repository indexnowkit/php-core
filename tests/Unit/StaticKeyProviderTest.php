<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Key\StaticKeyProvider;
use PHPUnit\Framework\TestCase;

final class StaticKeyProviderTest extends TestCase
{
    public function testHostsMapTakesPrecedenceOverDefaultKey(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['h.example.com' => 'hostkey1234']);

        self::assertSame('hostkey1234', $provider->keyFor('h.example.com'));
    }

    public function testDefaultKeyAppliesToUnmappedHosts(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['h.example.com' => 'hostkey1234']);

        self::assertSame('defaultkey123', $provider->keyFor('other.example.com'));
    }

    public function testNullWhenNoDefaultKeyAndHostNotMapped(): void
    {
        $provider = new StaticKeyProvider(null, ['h.example.com' => 'hostkey1234']);

        self::assertNull($provider->keyFor('other.example.com'));
    }

    public function testKeyLocationForPrefersPerHostOverride(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['h.example.com' => 'hostkey1234'], 'https://global.example.com/key.txt', keyLocations: ['h.example.com' => 'https://h.example.com/key.txt']);

        self::assertSame('https://h.example.com/key.txt', $provider->keyLocationFor('h.example.com'));
    }

    public function testKeyLocationForMappedHostWithoutOverrideIsNullSoTheDefaultLocationApplies(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['h.example.com' => 'hostkey1234'], 'https://global.example.com/key.txt');

        self::assertNull($provider->keyLocationFor('h.example.com'));
    }

    public function testKeyLocationForUnmappedHostUsesGlobalKeyLocation(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['h.example.com' => 'hostkey1234'], 'https://global.example.com/key.txt');

        self::assertSame('https://global.example.com/key.txt', $provider->keyLocationFor('other.example.com'));
    }

    public function testIsKnownKeyForDefaultAndMappedKeys(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['h.example.com' => 'hostkey1234']);

        self::assertTrue($provider->isKnownKey('defaultkey123'));
        self::assertTrue($provider->isKnownKey('hostkey1234'));
        self::assertFalse($provider->isKnownKey('unknownkey12'));
    }

    public function testManagedHostsIncludesHostsMapAndBaseHostWhenDefaultKeyExists(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['h.example.com' => 'hostkey1234'], defaultHost: 'base.example.com');

        self::assertSame(['h.example.com', 'base.example.com'], $provider->managedHosts());
    }

    public function testManagedHostsExcludesBaseHostWhenNoDefaultKey(): void
    {
        $provider = new StaticKeyProvider(null, ['h.example.com' => 'hostkey1234'], defaultHost: 'base.example.com');

        self::assertSame(['h.example.com'], $provider->managedHosts());
    }

    public function testHostLookupsAreCaseInsensitive(): void
    {
        $provider = new StaticKeyProvider('defaultkey123', ['H.Example.COM' => 'hostkey1234']);

        self::assertSame('hostkey1234', $provider->keyFor('h.example.com'));
        self::assertSame('hostkey1234', $provider->keyFor('H.EXAMPLE.COM'));
    }
}
