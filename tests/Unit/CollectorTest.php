<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Collector\Collector;
use PHPUnit\Framework\TestCase;

final class CollectorTest extends TestCase
{
    public function testIsEmptyInitially(): void
    {
        $collector = new Collector();

        self::assertTrue($collector->isEmpty());
        self::assertSame(0, $collector->count());
    }

    public function testAddDedupesAndPreservesInsertionOrder(): void
    {
        $collector = new Collector();

        $collector->add(['/a', '/b']);
        $collector->add(['/a', '/c']);

        self::assertFalse($collector->isEmpty());
        self::assertSame(3, $collector->count());
        self::assertSame(['/a', '/b', '/c'], $collector->drain());
    }

    public function testDrainEmptiesTheCollector(): void
    {
        $collector = new Collector();
        $collector->add(['/a']);

        $collector->drain();

        self::assertTrue($collector->isEmpty());
        self::assertSame([], $collector->drain());
    }

    public function testResetEmptiesTheCollectorWithoutReturningAnything(): void
    {
        $collector = new Collector();
        $collector->add(['/a', '/b']);

        $collector->reset();

        self::assertTrue($collector->isEmpty());
        self::assertSame(0, $collector->count());
    }
}
