<?php

declare(strict_types=1);

namespace IndexNowKit\Testing\Conformance;

use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Reason;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Testing\FakeTransport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Conformance scenarios of docs/spec/03 that every adapter runs against the facade its container built
 * (C01, C03, C04, C06, C09, C10, C11, C12, C14, C19, C20). Extend it in the adapter's test suite, return the
 * container's facade and the FakeTransport it is wired to. Scenarios that need special configuration (dry_run,
 * enabled: false, engines, debounce windows, throttle) stay in the core suite: they test the core, not the wiring.
 *
 * The facade must have a key and a base_url; every scenario uses fresh URLs, so the debounce window is irrelevant.
 */
abstract class CoreConformanceTestCase extends TestCase
{
    /**
     * The facade as the adapter wires it, with {@see transport()} as its transport.
     */
    abstract protected function kit(): IndexNowKit;

    abstract protected function transport(): FakeTransport;

    /**
     * A second host the adapter configured in `hosts` with its own key, or null to skip C04.
     */
    protected function secondHost(): ?string
    {
        return null;
    }

    #[TestDox('C01 submit one URL -> one POST with {host, key, urlList}, no keyLocation')]
    public function testC01SingleUrl(): void
    {
        $url = $this->url('c01');
        $results = $this->kit()->submit([$url]);

        $post = $this->lastPost();
        self::assertSame($this->host(), $post['body']['host']);
        self::assertSame($this->kit()->keys->keyFor($this->host()), $post['body']['key']);
        self::assertSame([$url], $post['body']['urlList']);
        self::assertArrayNotHasKey('keyLocation', $post['body'], 'no keyLocation unless configured');
        self::assertCount(1, $results);
        self::assertSame(ResultStatus::Ok, $results[0]->status);
    }

    #[TestDox('C03 10 001 URLs of one host -> two POSTs, 10 000 + 1')]
    public function testC03Batching(): void
    {
        $before = \count($this->transport()->posts);
        $urls = [];
        for ($i = 0; $i < 10001; ++$i) {
            $urls[] = $this->url('c03/' . $i);
        }
        $this->kit()->submit($urls);

        $posts = \array_slice($this->transport()->posts, $before);
        self::assertCount(2, $posts);
        self::assertCount(10000, (array) $posts[0]['body']['urlList']);
        self::assertCount(1, (array) $posts[1]['body']['urlList']);
    }

    #[TestDox('C04 URLs of two hosts -> one POST per host under its own key')]
    public function testC04TwoHosts(): void
    {
        $second = $this->secondHost();
        if ($second === null) {
            self::markTestSkipped('secondHost() is null: no second host configured');
        }
        $before = \count($this->transport()->posts);
        $this->kit()->submit([$this->url('c04'), 'https://' . $second . '/c04']);

        $posts = \array_slice($this->transport()->posts, $before);
        self::assertCount(2, $posts);
        self::assertSame([$this->host(), $second], array_column(array_column($posts, 'body'), 'host'));
        self::assertNotSame($posts[0]['body']['key'], $posts[1]['body']['key']);
    }

    #[TestDox('C06 duplicates in one call are sent once')]
    public function testC06Dedupe(): void
    {
        $url = $this->url('c06');
        $this->kit()->submit([$url, $url, $url . '#fragment']);

        self::assertSame([$url], $this->lastPost()['body']['urlList']);
    }

    #[TestDox('C09 202 -> pending; C10 403 -> failed, not retryable; C11 422 -> failed, not retryable; C12 429 -> failed, retryable, no exception')]
    public function testC09ToC12StatusMapping(): void
    {
        $this->transport()->willRespond(new Response(202), new Response(403), new Response(422), new Response(429, '', 30));

        $pending = $this->submitOne('c09');
        self::assertSame(ResultStatus::Pending, $pending->status);

        $forbidden = $this->submitOne('c10');
        self::assertSame(ResultStatus::Failed, $forbidden->status);
        self::assertFalse($forbidden->retryable);
        self::assertSame(Reason::InvalidKey, $forbidden->reason);

        $unprocessable = $this->submitOne('c11');
        self::assertSame(ResultStatus::Failed, $unprocessable->status);
        self::assertFalse($unprocessable->retryable);

        $limited = $this->submitOne('c12');
        self::assertSame(ResultStatus::Failed, $limited->status);
        self::assertTrue($limited->retryable);
        self::assertSame(30, $limited->retryAfter);
    }

    #[TestDox('C14 network failure -> failed, retryable, no exception')]
    public function testC14Timeout(): void
    {
        $this->transport()->willRespond(new TransportException('timeout'));

        $result = $this->submitOne('c14');

        self::assertSame(ResultStatus::Failed, $result->status);
        self::assertTrue($result->retryable);
        self::assertSame(Reason::Transport, $result->reason);
    }

    #[TestDox('C19 fragment removed, relative path resolved against base_url, non-ASCII host in punycode')]
    public function testC19Normalization(): void
    {
        $this->kit()->submit(['/conformance/c19/relative#top']);
        self::assertSame([rtrim((string) $this->kit()->config->baseUrl, '/') . '/conformance/c19/relative'], $this->lastPost()['body']['urlList']);

        $results = $this->kit()->submit(['https://пример.' . $this->host() . '/c19']);
        self::assertCount(1, $results);
        self::assertSame('xn--e1afmkfd.' . $this->host(), $results[0]->host, 'punycode host (sent or skipped depending on the key map)');
    }

    #[TestDox('C20 submit([]) does nothing and returns no result')]
    public function testC20Empty(): void
    {
        $before = \count($this->transport()->posts);

        self::assertSame([], $this->kit()->submit([]));
        self::assertCount($before, $this->transport()->posts);
    }

    protected function host(): string
    {
        return (string) $this->kit()->config->baseHost();
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->kit()->config->baseUrl, '/') . '/conformance/' . $path . '-' . bin2hex(random_bytes(3));
    }

    private function submitOne(string $path): Result
    {
        $results = $this->kit()->submit([$this->url($path)]);
        self::assertCount(1, $results);

        return $results[0];
    }

    /**
     * @return array{url: string, json: string, headers: array<string, string>, body: array<string, mixed>}
     */
    private function lastPost(): array
    {
        $posts = $this->transport()->posts;
        self::assertNotEmpty($posts, 'expected a POST');

        return $posts[\count($posts) - 1];
    }
}
