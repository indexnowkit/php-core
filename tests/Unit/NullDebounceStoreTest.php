<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Debounce\NullDebounceStore;
use PHPUnit\Framework\TestCase;

final class NullDebounceStoreTest extends TestCase
{
    public function testFilterRecentAlwaysReturnsEmpty(): void
    {
        $store = new NullDebounceStore();

        self::assertSame([], $store->filterRecent(['/a', '/b'], 600));
    }

    public function testMarkSubmittedIsANoop(): void
    {
        $store = new NullDebounceStore();

        $store->markSubmitted(['/a'], 600);

        self::assertSame([], $store->filterRecent(['/a'], 600), 'nothing is ever remembered');
    }
}
