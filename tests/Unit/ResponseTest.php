<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testHeadersAreLowerCasedAndReadByName(): void
    {
        $response = new Response(200, 'k', headers: ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Cache' => 'HIT']);

        self::assertSame(['content-type' => 'text/plain; charset=UTF-8', 'x-cache' => 'HIT'], $response->headers);
        self::assertSame('HIT', $response->header('x-CACHE'));
        self::assertNull($response->header('age'));
        self::assertSame([], (new Response(200))->headers, 'a transport that exposes no headers');
    }

    public function testContentTypeIsTheMediaTypeOnly(): void
    {
        self::assertSame('text/plain', (new Response(200, headers: ['Content-Type' => 'Text/Plain; charset=UTF-8']))->contentType());
        self::assertSame('text/html', (new Response(200, headers: ['content-type' => ' text/html ']))->contentType());
        self::assertNull((new Response(200))->contentType());
        self::assertNull((new Response(200, headers: ['Content-Type' => '']))->contentType());
    }

    public function testCacheMaxAgeAndAge(): void
    {
        self::assertNull((new Response(200))->cacheMaxAge());
        self::assertSame(300, (new Response(200, headers: ['Cache-Control' => 'public, max-age=300']))->cacheMaxAge());
        self::assertSame(3600, (new Response(200, headers: ['Cache-Control' => 'max-age=60, s-maxage=3600']))->cacheMaxAge(), 's-maxage is what a CDN honours');
        self::assertSame(0, (new Response(200, headers: ['Cache-Control' => 'no-store']))->cacheMaxAge());
        self::assertSame(0, (new Response(200, headers: ['Cache-Control' => 'no-cache, max-age=600']))->cacheMaxAge());
        self::assertNull((new Response(200, headers: ['Cache-Control' => 'public']))->cacheMaxAge(), 'no lifetime named');
        self::assertNull((new Response(200, headers: ['Cache-Control' => 'max-age=soon']))->cacheMaxAge());
        self::assertSame(42, (new Response(200, headers: ['Age' => ' 42']))->age());
        self::assertNull((new Response(200, headers: ['Age' => 'old']))->age());
        self::assertNull((new Response(200))->age());
    }

    public function testParseRetryAfterReadsDeltaSeconds(): void
    {
        self::assertSame(120, Response::parseRetryAfter('120'));
    }

    public function testParseRetryAfterReadsAnHttpDateRelativeToNow(): void
    {
        $now = 1_700_000_000;
        $future = gmdate(Response::HTTP_DATE, $now + 50);

        self::assertSame(50, Response::parseRetryAfter($future, now: $now));
    }

    public function testParseRetryAfterHttpDateInThePastClampsToZero(): void
    {
        $now = 1_700_000_000;
        $past = gmdate(Response::HTTP_DATE, $now - 500);

        self::assertSame(0, Response::parseRetryAfter($past, now: $now));
    }

    public function testParseRetryAfterGarbageReturnsNull(): void
    {
        self::assertNull(Response::parseRetryAfter('not a retry value at all!!'));
    }

    public function testParseRetryAfterNullOrEmptyHeaderReturnsNull(): void
    {
        self::assertNull(Response::parseRetryAfter(null));
        self::assertNull(Response::parseRetryAfter(''));
        self::assertNull(Response::parseRetryAfter('   '));
    }

    public function testParseRetryAfterClampsDeltaSecondsToTheMaximum(): void
    {
        self::assertSame(10, Response::parseRetryAfter('999999', max: 10));
    }

    public function testParseRetryAfterClampsAFarFutureHttpDateToTheMaximum(): void
    {
        $now = 1_700_000_000;
        $farFuture = gmdate(Response::HTTP_DATE, $now + 1_000_000);

        self::assertSame(100, Response::parseRetryAfter($farFuture, max: 100, now: $now));
    }

    public function testDefaultMaxClampsToOneDay(): void
    {
        self::assertSame(Response::MAX_RETRY_AFTER, Response::parseRetryAfter((string) (Response::MAX_RETRY_AFTER + 100)));
    }

    public function testConstructorStoresGivenValues(): void
    {
        $response = new Response(200, 'body', 30);

        self::assertSame(200, $response->status);
        self::assertSame('body', $response->body);
        self::assertSame(30, $response->retryAfter);
    }

    public function testConstructorDefaultsBodyAndRetryAfter(): void
    {
        $response = new Response(404);

        self::assertSame('', $response->body);
        self::assertNull($response->retryAfter);
    }
}
