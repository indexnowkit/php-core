<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class MemoryDebounceStoreTest extends TestCase
{
    public function testFilterRecentHonoursTtlExpiry(): void
    {
        $clock = new FrozenClock();
        $store = new MemoryDebounceStore($clock);

        $store->markSubmitted(['/x'], 100);
        self::assertSame(['/x'], $store->filterRecent(['/x'], 100));

        $clock->advance(101);
        self::assertSame([], $store->filterRecent(['/x'], 100));
    }

    public function testFilterRecentIgnoresUrlsNeverMarked(): void
    {
        $store = new MemoryDebounceStore(new FrozenClock());

        self::assertSame([], $store->filterRecent(['/never-marked'], 600));
    }

    public function testMaxEntriesEvictsOldestFirst(): void
    {
        $clock = new FrozenClock();
        $store = new MemoryDebounceStore($clock, maxEntries: 3);

        $store->markSubmitted(['/a'], 600);
        $store->markSubmitted(['/b'], 600);
        $store->markSubmitted(['/c'], 600);
        $store->markSubmitted(['/d'], 600);

        self::assertSame([], $store->filterRecent(['/a'], 600), 'oldest entry is evicted once maxEntries is exceeded');
        self::assertSame(['/b', '/c', '/d'], $store->filterRecent(['/b', '/c', '/d'], 600));
    }

    public function testRemarkingMovesEntryToNewest(): void
    {
        $clock = new FrozenClock();
        $store = new MemoryDebounceStore($clock, maxEntries: 2);

        $store->markSubmitted(['/a'], 600);
        $store->markSubmitted(['/b'], 600);
        $store->markSubmitted(['/a'], 600); // re-mark moves /a to the newest position: order is now /b, /a
        $store->markSubmitted(['/c'], 600); // exceeds maxEntries by one: the oldest (/b) is evicted, /a survives

        self::assertSame([], $store->filterRecent(['/b'], 600));
        self::assertSame(['/a', '/c'], $store->filterRecent(['/a', '/c'], 600));
    }

    public function testCountReflectsStoredEntries(): void
    {
        $store = new MemoryDebounceStore(new FrozenClock());

        $store->markSubmitted(['/a', '/b'], 600);

        self::assertSame(2, $store->count());
    }
}
