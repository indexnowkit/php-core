<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Conformance;

use IndexNowKit\Config;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\KeyGenerator;
use IndexNowKit\ResultStatus;
use IndexNowKit\Retry\RetryingSubmitter;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\Tests\Support\ArrayLogger;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\FakeTransport;
use IndexNowKit\Tests\Support\FrozenClock;
use IndexNowKit\Throttle\TokenBucket;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Scenarios C01-C22 from docs/spec/03-conformance.md.
 */
final class CoreConformanceTest extends TestCase
{
    #[TestDox('C01 submit one URL -> one POST with host, key, urlList and no keyLocation')]
    public function testC01SingleUrl(): void
    {
        $t = new FakeTransport();
        $results = Factory::submitter($t)->submit(['https://www.example.com/a']);

        self::assertCount(1, $t->posts);
        self::assertSame('https://api.indexnow.org/indexnow', $t->posts[0]['url']);
        self::assertSame(['host' => 'www.example.com', 'key' => Factory::KEY, 'urlList' => ['https://www.example.com/a']], $t->posts[0]['body']);
        self::assertSame('application/json; charset=utf-8', $t->posts[0]['headers']['Content-Type'] ?? 'application/json; charset=utf-8');
        self::assertStringStartsWith('indexnowkit-php/', $t->posts[0]['headers']['User-Agent']);
        self::assertSame(ResultStatus::Ok, $results[0]->status);
    }

    #[TestDox('C02 keyLocation configured -> present in body')]
    public function testC02KeyLocation(): void
    {
        $t = new FakeTransport();
        Factory::submitter($t, Factory::config(['key_location' => 'https://www.example.com/keys/' . Factory::KEY . '.txt']))->submit(['/a']);

        self::assertSame('https://www.example.com/keys/' . Factory::KEY . '.txt', $t->posts[0]['body']['keyLocation']);
    }

    #[TestDox('C03 10 001 URLs of one host -> two POSTs (10 000 + 1)')]
    public function testC03Chunking(): void
    {
        $t = new FakeTransport();
        $urls = [];
        for ($i = 0; $i < 10001; ++$i) {
            $urls[] = '/p/' . $i;
        }
        Factory::submitter($t)->submit($urls);

        self::assertCount(2, $t->posts);
        self::assertCount(10000, $t->posts[0]['body']['urlList']);
        self::assertCount(1, $t->posts[1]['body']['urlList']);
    }

    #[TestDox('C04 URLs of two hosts -> one POST per host')]
    public function testC04GroupByHost(): void
    {
        $t = new FakeTransport();
        Factory::submitter($t)->submit(['https://www.example.com/a', 'https://blog.example.com/b', 'https://www.example.com/c']);

        self::assertCount(2, $t->posts);
        $hosts = array_map(static fn($p) => $p['body']['host'], $t->posts);
        sort($hosts);
        self::assertSame(['blog.example.com', 'www.example.com'], $hosts);
    }

    #[TestDox('C05 host missing from hosts map -> dropped with warning, no POST')]
    public function testC05UnmanagedHost(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $config = Factory::config(['key' => null, 'hosts' => ['www.example.com' => Factory::KEY]]);
        Factory::submitter($t, $config, $logger)->submit(['https://other.example.org/x']);

        self::assertCount(0, $t->posts);
        self::assertStringContainsString('unmanaged host other.example.org', implode("\n", $logger->messages('warning')));
    }

    #[TestDox('C06 duplicates in one call -> one URL in body')]
    public function testC06Dedupe(): void
    {
        $t = new FakeTransport();
        Factory::submitter($t)->submit(['/a', 'https://www.example.com/a', '/a#frag', '/A']);

        self::assertSame(['https://www.example.com/a', 'https://www.example.com/A'], $t->posts[0]['body']['urlList']);
    }

    #[TestDox('C07/C08 same URL within debounce -> no second POST; after TTL -> POST again')]
    public function testC07C08Debounce(): void
    {
        $t = new FakeTransport();
        $clock = new FrozenClock();
        $config = Factory::config(['debounce' => ['per_url' => 600]]);
        $submitter = Factory::submitter($t, $config, null, new MemoryDebounceStore($clock));

        $submitter->submit(['/a']);
        $clock->advance(100);
        self::assertSame([], $submitter->submit(['/a']), 'C07: debounced');
        self::assertCount(1, $t->posts);

        $clock->advance(600);
        $submitter->submit(['/a']);
        self::assertCount(2, $t->posts, 'C08: resubmitted after TTL');
    }

    #[TestDox('C09 202 -> pending, treated as success and debounced')]
    public function testC09Pending(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(202));
        $submitter = Factory::submitter($t, Factory::config(['debounce' => ['per_url' => 600]]));
        $results = $submitter->submit(['/a']);

        self::assertSame(ResultStatus::Pending, $results[0]->status);
        self::assertTrue($results[0]->isSuccess());
        $submitter->submit(['/a']);
        self::assertCount(1, $t->posts);
    }

    #[TestDox('C10 403 -> failed, not retryable, error log mentions key file')]
    public function testC10Forbidden(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(403));
        $logger = new ArrayLogger();
        $results = Factory::submitter($t, null, $logger)->submit(['/a']);

        self::assertSame(ResultStatus::Failed, $results[0]->status);
        self::assertFalse($results[0]->retryable);
        self::assertSame(403, $results[0]->httpCode);
        self::assertStringContainsString('.txt', implode("\n", $logger->messages('error')));
        self::assertStringNotContainsString(Factory::KEY, implode("\n", $logger->messages()), 'key must be masked in logs');
    }

    #[TestDox('C11 422 -> failed, not retryable')]
    public function testC11Unprocessable(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(422));
        $results = Factory::submitter($t)->submit(['/a']);

        self::assertSame(ResultStatus::Failed, $results[0]->status);
        self::assertFalse($results[0]->retryable);
    }

    #[TestDox('C12 429 in sync mode -> failed, retryable, Retry-After captured, no retry, no exception')]
    public function testC12RateLimited(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(429, 'slow', 30));
        $results = Factory::submitter($t)->submit(['/a']);

        self::assertCount(1, $t->posts);
        self::assertTrue($results[0]->retryable);
        self::assertSame(30, $results[0]->retryAfter);
    }

    #[TestDox('C13 429 with Retry-After, retried in-process -> two POSTs, ends ok, sleeper called with the retry delay')]
    public function testC13RetryingSubmitterBacksOffThenSucceeds(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(429, 'slow', 2), new Response(200));
        $sleeps = [];
        $submitter = new RetryingSubmitter(
            Factory::submitter($t),
            new RetryPolicy(),
            new ArrayLogger(),
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        );

        $results = $submitter->submit(['/a']);

        self::assertCount(2, $t->posts);
        self::assertSame([2], $sleeps);
        self::assertCount(1, $results);
        self::assertSame(ResultStatus::Ok, $results[0]->status);
        foreach ($results as $result) {
            self::assertFalse($result->retryable);
        }
    }

    #[TestDox('C14 transport failure -> failed, retryable, no exception')]
    public function testC14Timeout(): void
    {
        $t = (new FakeTransport())->willRespond(FakeTransport::failing('timeout'));
        $results = Factory::submitter($t)->submit(['/a']);

        self::assertSame(ResultStatus::Failed, $results[0]->status);
        self::assertTrue($results[0]->retryable);
        self::assertNull($results[0]->httpCode);
    }

    #[TestDox('C15 invalid key -> ConfigurationException at construction')]
    public function testC15InvalidKey(): void
    {
        $this->expectException(ConfigurationException::class);
        Factory::config(['key' => 'abc']);
    }

    #[TestDox('C16 enabled: false -> no POST')]
    public function testC16Disabled(): void
    {
        $t = new FakeTransport();
        $results = Factory::submitter($t, Factory::config(['enabled' => false]))->submit(['/a']);

        self::assertSame([], $results);
        self::assertCount(0, $t->posts);
    }

    #[TestDox('C17 dry_run -> no POST, info log with body, key masked')]
    public function testC17DryRun(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $results = Factory::submitter($t, Factory::config(['dry_run' => true]), $logger)->submit(['/a']);

        self::assertCount(0, $t->posts);
        self::assertSame(ResultStatus::Skipped, $results[0]->status);
        $info = implode("\n", $logger->messages('info'));
        self::assertStringContainsString('dry-run', $info);
        self::assertStringContainsString('https://www.example.com/a', $info);
        self::assertStringNotContainsString(Factory::KEY, $info);
    }

    #[TestDox('C18 engines [yandex, bing] -> two POSTs with identical body')]
    public function testC18MultipleEngines(): void
    {
        $t = new FakeTransport();
        Factory::submitter($t, Factory::config(['engines' => ['yandex', 'bing']]))->submit(['/a']);

        self::assertCount(2, $t->posts);
        self::assertSame('https://yandex.com/indexnow', $t->posts[0]['url']);
        self::assertSame('https://www.bing.com/indexnow', $t->posts[1]['url']);
        self::assertSame($t->posts[0]['json'], $t->posts[1]['json']);
    }

    #[TestDox('C19 fragment stripped, IDN host to punycode, scheme/host lower-cased')]
    public function testC19Normalization(): void
    {
        $t = new FakeTransport();
        $config = Factory::config(['key' => null, 'hosts' => ['xn--80aswg.xn--p1ai' => Factory::KEY]]);
        Factory::submitter($t, $config)->submit(['HTTPS://Сайт.рф/Путь?q=1#top']);

        self::assertSame('xn--80aswg.xn--p1ai', $t->posts[0]['body']['host']);
        self::assertSame('https://xn--80aswg.xn--p1ai/Путь?q=1', $t->posts[0]['body']['urlList'][0]);
    }

    #[TestDox('C20 empty list -> nothing happens')]
    public function testC20Empty(): void
    {
        $t = new FakeTransport();
        self::assertSame([], Factory::submitter($t)->submit([]));
        self::assertCount(0, $t->posts);
    }

    #[TestDox('C21 key generation -> 32 hex chars, unique')]
    public function testC21KeyGeneration(): void
    {
        $a = KeyGenerator::generate();
        $b = KeyGenerator::generate();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $a);
        self::assertNotSame($a, $b);
        self::assertSame(64, \strlen(KeyGenerator::generate(64)));
    }

    #[TestDox('C22 throttle 2 req/min, 3 batches -> third waits for the next token')]
    public function testC22Throttle(): void
    {
        $t = new FakeTransport();
        $clock = new FrozenClock();
        $sleeps = [];
        $throttle = new TokenBucket(2, $clock, static function (int $us) use (&$sleeps, $clock): void {
            $sleeps[] = $us;
            $clock->advance((int) ceil($us / 1_000_000));
        });
        $config = Factory::config(['engines' => ['api'], 'batch' => ['max_urls' => 1]]);
        Factory::submitter($t, $config, null, null, $throttle)->submit(['/a', '/b', '/c']);

        self::assertCount(3, $t->posts);
        self::assertCount(1, $sleeps);
        self::assertGreaterThanOrEqual(29_000_000, $sleeps[0]);
    }

    public function testInvalidUrlsAreDroppedWithWarningNotException(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $config = Config::fromArray(['key' => Factory::KEY, 'debounce' => ['per_url' => 0]]); // no base_url
        Factory::submitter($t, $config, $logger)->submit(['/relative', 'https://www.example.com/ok', '']);

        self::assertCount(1, $t->posts);
        self::assertCount(2, $logger->messages('warning'));
    }
}
