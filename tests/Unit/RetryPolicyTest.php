<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Retry\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    private static function retryable(?int $retryAfter = null): Result
    {
        return new Result('api', 'h.example.com', ['/a'], ResultStatus::Failed, 429, 'rate limited', retryable: true, retryAfter: $retryAfter);
    }

    private static function notRetryable(): Result
    {
        return new Result('api', 'h.example.com', ['/a'], ResultStatus::Failed, 400, 'bad request');
    }

    public function testNullWhenNothingRetryable(): void
    {
        $policy = new RetryPolicy();
        self::assertNull($policy->delayAfter([], 1));
        self::assertNull($policy->delayAfter([self::notRetryable()], 1));
    }

    public function testHonoursMaxRetryAfterAcrossResults(): void
    {
        $policy = new RetryPolicy();
        $delay = $policy->delayAfter([self::retryable(5), self::retryable(30), self::retryable(null)], 1);

        self::assertSame(30, $delay);
    }

    public function testExponentialFallbackWithoutRetryAfter(): void
    {
        $policy = new RetryPolicy();
        self::assertSame(60, $policy->delayAfter([self::retryable()], 1));
        self::assertSame(120, $policy->delayAfter([self::retryable()], 2));
    }

    public function testNullOnceMaxAttemptsReached(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);
        self::assertNull($policy->delayAfter([self::retryable(999)], 3));
        self::assertNull($policy->delayAfter([self::retryable(999)], 4));
    }

    public function testDelayClampedToMaxDelay(): void
    {
        $policy = new RetryPolicy(maxDelay: 50);
        self::assertSame(50, $policy->delayAfter([self::retryable(1000)], 1));

        $exponential = new RetryPolicy(baseDelay: 1000, multiplier: 10.0, maxDelay: 500);
        self::assertSame(500, $exponential->delayAfter([self::retryable()], 1));
    }
}
