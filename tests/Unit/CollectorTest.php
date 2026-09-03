<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Collector\Collector;
use IndexNowKit\Testing\ArrayLogger;
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

    public function testResetOfANonEmptyBufferLogsAWarningWithTheCount(): void
    {
        $logger = new ArrayLogger();
        $collector = new Collector($logger);
        $collector->add(['/a', '/b']);

        $collector->reset();

        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('2 collected URL(s) discarded', $warnings[0]);
    }

    public function testResetOfAnEmptyBufferLogsNothing(): void
    {
        $logger = new ArrayLogger();
        $collector = new Collector($logger);

        $collector->reset();

        self::assertSame([], $logger->messages('warning'));
    }
}
