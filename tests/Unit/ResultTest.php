<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Reason;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testUrlCountReflectsUrlsArray(): void
    {
        $result = new Result('api', 'h.example.com', ['/a', '/b'], ResultStatus::Ok);

        self::assertSame(2, $result->urlCount());
    }

    public function testIsSuccessDelegatesToStatus(): void
    {
        self::assertTrue((new Result('api', 'h.example.com', [], ResultStatus::Ok))->isSuccess());
        self::assertFalse((new Result('api', 'h.example.com', [], ResultStatus::Failed))->isSuccess());
    }

    public function testRetryableUrlsKeepsRetryableResultsOnly(): void
    {
        $retryable = new Result('api', 'h.example.com', ['/a', '/b'], ResultStatus::Failed, retryable: true);
        $notRetryable = new Result('api', 'h.example.com', ['/c'], ResultStatus::Failed);

        self::assertSame(['/a', '/b'], Result::retryableUrls([$retryable, $notRetryable]));
    }

    public function testRetryableUrlsDeduplicatesAcrossResults(): void
    {
        $a = new Result('api', 'h1.example.com', ['/a', '/shared'], ResultStatus::Failed, retryable: true);
        $b = new Result('api', 'h2.example.com', ['/shared', '/b'], ResultStatus::Failed, retryable: true);

        self::assertSame(['/a', '/shared', '/b'], Result::retryableUrls([$a, $b]));
    }

    public function testUrlsWhereAppliesThePredicate(): void
    {
        $ok = new Result('api', 'h.example.com', ['/a'], ResultStatus::Ok);
        $failed = new Result('api', 'h.example.com', ['/b'], ResultStatus::Failed);

        $urls = Result::urlsWhere([$ok, $failed], static fn(Result $r): bool => $r->isSuccess());

        self::assertSame(['/a'], $urls);
    }

    public function testOkSetsStatusFromHttpCodeAndNoReason(): void
    {
        $ok = Result::ok('api', 'h.example.com', ['/a'], 200, 'https://api.indexnow.org/indexnow');
        self::assertSame(ResultStatus::Ok, $ok->status);
        self::assertNull($ok->reason);
        self::assertNull($ok->error);

        $pending = Result::ok('api', 'h.example.com', ['/a'], 202, 'https://api.indexnow.org/indexnow');
        self::assertSame(ResultStatus::Pending, $pending->status);
    }

    public function testSkippedSetsStatusReasonAndDefaultErrorMessage(): void
    {
        $result = Result::skipped('h.example.com', ['/a'], Reason::Disabled);

        self::assertSame(ResultStatus::Skipped, $result->status);
        self::assertSame(Reason::Disabled, $result->reason);
        self::assertSame(Reason::Disabled->message(), $result->error);
        self::assertNull($result->httpCode);
        self::assertFalse($result->retryable);
    }

    public function testSkippedAcceptsACustomErrorMessage(): void
    {
        $result = Result::skipped('h.example.com', ['/a'], Reason::NoKey, 'custom message');

        self::assertSame('custom message', $result->error);
    }

    public function testFailedSetsStatusReasonRetryableAndDefaultErrorMessage(): void
    {
        $result = Result::failed('api', 'h.example.com', ['/a'], Reason::ServerError, httpCode: 500, retryable: true, retryAfter: 30);

        self::assertSame(ResultStatus::Failed, $result->status);
        self::assertSame(Reason::ServerError, $result->reason);
        self::assertSame(Reason::ServerError->message(), $result->error);
        self::assertSame(500, $result->httpCode);
        self::assertTrue($result->retryable);
        self::assertSame(30, $result->retryAfter);
    }

    public function testMetricLabelsReportsLowCardinalityFields(): void
    {
        $result = Result::failed('api', 'h.example.com', ['/a'], Reason::RateLimited, httpCode: 429, retryable: true);

        self::assertSame([
            'status' => 'failed',
            'engine' => 'api',
            'reason' => 'rate_limited',
            'http_code' => '429',
            'retryable' => 'true',
        ], $result->metricLabels());
    }

    public function testMetricLabelsUseEmptyStringsWhenReasonAndHttpCodeAreAbsent(): void
    {
        $result = new Result('api', 'h.example.com', ['/a'], ResultStatus::Ok);

        self::assertSame('', $result->metricLabels()['reason']);
        self::assertSame('', $result->metricLabels()['http_code']);
        self::assertSame('false', $result->metricLabels()['retryable']);
    }

    public function testRetryableUrlsOnlyIncludesRetryableResults(): void
    {
        $retryable = new Result('api', 'h.example.com', ['/a', '/b'], ResultStatus::Failed, retryable: true);
        $notRetryable = new Result('api', 'h.example.com', ['/c'], ResultStatus::Failed);

        self::assertSame(['/a', '/b'], Result::retryableUrls([$retryable, $notRetryable]));
    }

    public function testAllUrlsIncludesEveryResultRegardlessOfStatus(): void
    {
        $ok = new Result('api', 'h.example.com', ['/a'], ResultStatus::Ok);
        $skipped = new Result('none', 'h.example.com', ['/b'], ResultStatus::Skipped);
        $failed = new Result('api', 'h.example.com', ['/a', '/c'], ResultStatus::Failed);

        self::assertSame(['/a', '/b', '/c'], Result::allUrls([$ok, $skipped, $failed]));
    }
}
