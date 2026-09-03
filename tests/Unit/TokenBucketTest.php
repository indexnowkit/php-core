<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Throttle\TokenBucket;
use PHPUnit\Framework\TestCase;

final class SleepRecorder
{
    /** @var list<int> */
    public array $microseconds = [];

    public function __invoke(int $us): void
    {
        $this->microseconds[] = $us;
    }
}

final class TokenBucketTest extends TestCase
{
    public function testUnlimitedNeverSleeps(): void
    {
        $sleeper = new SleepRecorder();
        $bucket = new TokenBucket(0, new FrozenClock(), $sleeper);

        for ($i = 0; $i < 100; ++$i) {
            $bucket->acquire();
        }

        self::assertSame([], $sleeper->microseconds);
    }

    public function testBurstUpToCapacityDoesNotSleep(): void
    {
        $sleeper = new SleepRecorder();
        $bucket = new TokenBucket(2, new FrozenClock(), $sleeper);

        $bucket->acquire();
        $bucket->acquire();

        self::assertSame([], $sleeper->microseconds);
    }

    public function testAcquireBeyondCapacitySleepsForTheDeficit(): void
    {
        $sleeper = new SleepRecorder();
        $bucket = new TokenBucket(2, new FrozenClock(), $sleeper);

        $bucket->acquire();
        $bucket->acquire();
        $bucket->acquire();

        self::assertCount(1, $sleeper->microseconds);
        self::assertSame(30_000_000, $sleeper->microseconds[0]);
    }

    public function testDebugLoggedWhenSleeping(): void
    {
        $logger = new ArrayLogger();
        $bucket = new TokenBucket(1, new FrozenClock(), new SleepRecorder(), $logger);

        $bucket->acquire();
        $bucket->acquire();

        self::assertCount(1, $logger->messages('debug'));
    }

    public function testRefillsOverElapsedTime(): void
    {
        $clock = new FrozenClock();
        $sleeper = new SleepRecorder();
        $bucket = new TokenBucket(60, $clock, $sleeper);

        for ($i = 0; $i < 60; ++$i) {
            $bucket->acquire();
        }
        self::assertSame([], $sleeper->microseconds, 'exactly 60 acquires drain the bucket without sleeping');

        $clock->advance(5);
        $bucket->acquire();
        self::assertSame([], $sleeper->microseconds, '5 elapsed seconds refill 5 tokens at 60/min');
    }

    public function testRefillNeverExceedsCapacity(): void
    {
        $clock = new FrozenClock();
        $sleeper = new SleepRecorder();
        $bucket = new TokenBucket(2, $clock, $sleeper);

        $clock->advance(3600);
        $bucket->acquire();
        $bucket->acquire();
        $bucket->acquire();

        self::assertCount(1, $sleeper->microseconds, 'refill is capped at perMinute tokens, so a third acquire still waits');
    }
}
