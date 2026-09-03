<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Debounce\Psr16DebounceStore;
use IndexNowKit\Tests\Support\GeneratorCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class Psr16DebounceStoreTest extends TestCase
{
    public function testFilterRecentAndMarkSubmittedRoundTrip(): void
    {
        $store = new Psr16DebounceStore(new Psr16Cache(new ArrayAdapter()));

        $store->markSubmitted(['https://example.com/a'], 600);

        self::assertSame(['https://example.com/a'], $store->filterRecent(['https://example.com/a', 'https://example.com/b'], 600));
    }

    public function testFilterRecentWithEmptyUrlsReturnsEmpty(): void
    {
        $store = new Psr16DebounceStore(new Psr16Cache(new ArrayAdapter()));

        self::assertSame([], $store->filterRecent([], 600));
    }

    public function testMarkSubmittedWithZeroTtlDoesNothing(): void
    {
        $store = new Psr16DebounceStore(new Psr16Cache(new ArrayAdapter()));

        $store->markSubmitted(['https://example.com/a'], 0);

        self::assertSame([], $store->filterRecent(['https://example.com/a'], 600));
    }

    public function testMarkSubmittedWithEmptyUrlsDoesNothing(): void
    {
        $store = new Psr16DebounceStore(new Psr16Cache(new ArrayAdapter()));

        $store->markSubmitted([], 600);
        $this->addToAssertionCount(1);
    }

    public function testKeysAreNamespacedByPrefixSoDifferentStoresDoNotShareEntries(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $storeA = new Psr16DebounceStore($cache, 'a_');
        $storeB = new Psr16DebounceStore($cache, 'b_');

        $storeA->markSubmitted(['https://example.com/a'], 600);

        self::assertSame([], $storeB->filterRecent(['https://example.com/a'], 600));
    }

    public function testHandlesTraversableResultFromGetMultiple(): void
    {
        $store = new Psr16DebounceStore(new GeneratorCache());

        $store->markSubmitted(['https://example.com/a'], 600);

        self::assertSame(['https://example.com/a'], $store->filterRecent(['https://example.com/a', 'https://example.com/b'], 600));
    }
}
