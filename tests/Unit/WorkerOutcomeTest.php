<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Reason;
use IndexNowKit\Result;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\Retry\WorkerOutcome;
use IndexNowKit\Testing\ArrayLogger;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `Retry\WorkerOutcome` (docs/spec/16 §4.3): what every queue worker computes after one submission.
 */
final class WorkerOutcomeTest extends TestCase
{
    #[TestDox('of() splits retryable and final failures, keeps the largest Retry-After, and lists unique reasons')]
    public function testOf(): void
    {
        $outcome = WorkerOutcome::of([
            Result::ok('api', 'www.example.com', ['https://www.example.com/ok'], 200, 'https://api.indexnow.org/indexnow'),
            Result::failed('api', 'www.example.com', ['https://www.example.com/r1'], Reason::RateLimited, httpCode: 429, retryable: true, retryAfter: 30),
            Result::failed('yandex', 'www.example.com', ['https://www.example.com/r1', 'https://www.example.com/r2'], Reason::ServerError, httpCode: 503, retryable: true, retryAfter: 7),
            Result::failed('api', 'www.example.com', ['https://www.example.com/f1'], Reason::InvalidKey, httpCode: 403),
            Result::failed('bing', 'www.example.com', ['https://www.example.com/f1'], Reason::InvalidKey, httpCode: 403),
            Result::failed('yandex', 'www.example.com', ['https://www.example.com/f2'], Reason::Unprocessable, httpCode: 422),
            Result::skipped('www.example.com', ['https://www.example.com/s'], Reason::Debounced),
        ]);

        self::assertSame(['https://www.example.com/r1', 'https://www.example.com/r2'], $outcome->retryUrls);
        self::assertSame(['https://www.example.com/f1', 'https://www.example.com/f2'], $outcome->finalUrls);
        self::assertSame(['api 403', 'bing 403', 'yandex 422'], $outcome->finalReasons);
        self::assertSame(30, $outcome->retryAfter);
        self::assertTrue($outcome->hasRetryable());
        self::assertTrue($outcome->hasFinalFailures());

        $clean = WorkerOutcome::of([Result::ok('api', 'www.example.com', ['https://www.example.com/ok'], 200, '')]);
        self::assertFalse($clean->hasRetryable());
        self::assertFalse($clean->hasFinalFailures());
        self::assertNull($clean->retryAfter);
        self::assertSame(['api transport'], WorkerOutcome::of([Result::failed('api', 'www.example.com', ['https://www.example.com/n'], Reason::Transport)])->finalReasons, 'no HTTP code: the reason');
    }

    #[TestDox('delay() is the policy over the results: Retry-After wins, null once the attempts are used up')]
    public function testDelay(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelay: 5, multiplier: 2.0, maxDelay: 300, serverErrorDelay: 60);
        $rateLimited = WorkerOutcome::of([Result::failed('api', 'h', ['https://h/a'], Reason::RateLimited, httpCode: 429, retryable: true, retryAfter: 42)]);
        self::assertSame(42, $rateLimited->delay($policy, 1));
        self::assertNull($rateLimited->delay($policy, 3));
        $serverError = WorkerOutcome::of([Result::failed('api', 'h', ['https://h/a'], Reason::ServerError, httpCode: 502, retryable: true)]);
        self::assertSame(120, $serverError->delay($policy, 2));
        self::assertNull(WorkerOutcome::of([])->delay($policy, 1));
    }

    #[TestDox('the log lines carry count, job id, delay and attempt when known, and the framework\'s check command')]
    public function testLogs(): void
    {
        $outcome = WorkerOutcome::of([
            Result::failed('api', 'h', ['https://h/r1', 'https://h/r2'], Reason::ServerError, httpCode: 500, retryable: true),
            Result::failed('api', 'h', ['https://h/f'], Reason::InvalidKey, httpCode: 403),
        ]);
        $logger = new ArrayLogger();
        $logger->info(...$outcome->retryLog('j1', 30, 2));
        $logger->info(...$outcome->retryLog('j2'));
        $logger->error(...$outcome->gaveUpLog('j3', 3));
        $logger->error(...$outcome->finalLog('j4', 'php artisan indexnow:check'));

        self::assertSame(['indexnow: 2 URL(s) of job j1 will be retried in 30s (attempt 2)', 'indexnow: 2 URL(s) of job j2 will be retried'], $logger->messages('info'));
        self::assertSame(['indexnow: giving up on 2 URL(s) of job j3 after 3 attempt(s)', 'indexnow: 1 URL(s) of job j4 rejected permanently (api 403); run "php artisan indexnow:check"'], $logger->messages('error'));
    }
}
