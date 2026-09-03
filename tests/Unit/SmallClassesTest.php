<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Clock\SystemClock;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Exception\IndexNowException;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Version;
use PHPUnit\Framework\TestCase;

final class SmallClassesTest extends TestCase
{
    public function testNullThrottleNeverBlocks(): void
    {
        $throttle = new NullThrottle();
        $start = microtime(true);
        for ($i = 0; $i < 1000; ++$i) {
            $throttle->acquire();
        }
        self::assertLessThan(0.5, microtime(true) - $start);
    }

    public function testSystemClockFollowsWallTime(): void
    {
        $now = (new SystemClock())->now();
        self::assertEqualsWithDelta(time(), $now->getTimestamp(), 2);
    }

    public function testVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+/', Version::get());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::VERSION);
    }

    public function testArgumentExceptionsShareTheKitHierarchy(): void
    {
        foreach ([new ConfigurationException('x'), new InvalidUrlException('x')] as $exception) {
            self::assertInstanceOf(InvalidArgumentException::class, $exception);
            self::assertInstanceOf(\InvalidArgumentException::class, $exception);
            self::assertInstanceOf(IndexNowException::class, $exception);
        }
    }
}
