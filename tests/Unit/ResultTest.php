<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

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

    public function testUrlsOfDefaultsToRetryableResults(): void
    {
        $retryable = new Result('api', 'h.example.com', ['/a', '/b'], ResultStatus::Failed, retryable: true);
        $notRetryable = new Result('api', 'h.example.com', ['/c'], ResultStatus::Failed);

        self::assertSame(['/a', '/b'], Result::urlsOf([$retryable, $notRetryable]));
    }

    public function testUrlsOfDeduplicatesAcrossResults(): void
    {
        $a = new Result('api', 'h1.example.com', ['/a', '/shared'], ResultStatus::Failed, retryable: true);
        $b = new Result('api', 'h2.example.com', ['/shared', '/b'], ResultStatus::Failed, retryable: true);

        self::assertSame(['/a', '/shared', '/b'], Result::urlsOf([$a, $b]));
    }

    public function testUrlsOfAcceptsCustomFilter(): void
    {
        $ok = new Result('api', 'h.example.com', ['/a'], ResultStatus::Ok);
        $failed = new Result('api', 'h.example.com', ['/b'], ResultStatus::Failed);

        $urls = Result::urlsOf([$ok, $failed], static fn(Result $r): bool => $r->isSuccess());

        self::assertSame(['/a'], $urls);
    }
}
