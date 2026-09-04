<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Http\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class StubClientException extends RuntimeException implements ClientExceptionInterface {}

final class StubPsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(private readonly ResponseInterface|StubClientException $result) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->result instanceof StubClientException) {
            throw $this->result;
        }

        return $this->result;
    }
}

/**
 * Unit tests around Psr18Transport with a stub PSR-18 client (no network). See Psr18TransportTest for
 * the real-HTTP integration coverage against packages/core/tests/Support/mock-server/router.php.
 */
final class Psr18TransportUnitTest extends TestCase
{
    private function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    public function testClientExceptionBecomesTransportExceptionWithHostButNotPath(): void
    {
        $client = new StubPsr18Client(new StubClientException('connection refused'));
        $f = $this->factory();
        $transport = new Psr18Transport($client, $f, $f);

        try {
            $transport->post('https://secret-host.example.com/very/secret/path', '{}');
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('secret-host.example.com', $e->getMessage());
            self::assertStringNotContainsString('/very/secret/path', $e->getMessage());
        }
    }

    public function testPostBodyIsTruncatedWithoutError(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], str_repeat('a', 3000)));
        $transport = new Psr18Transport($client, $f, $f);

        $response = $transport->post('https://h.example.com/indexnow', '{}');

        self::assertSame(Psr18Transport::POST_BODY_LIMIT, \strlen($response->body));
    }

    public function testPostBodyLimitAndMaxRetryAfterAreConstructorArguments(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(429, ['Retry-After' => '600'], str_repeat('b', 100)));
        $transport = new Psr18Transport($client, $f, $f, postBodyLimit: 10, maxRetryAfter: 120);

        $response = $transport->post('https://h.example.com/indexnow', '{}');

        self::assertSame(10, \strlen($response->body));
        self::assertSame(120, $response->retryAfter, 'clamped to maxRetryAfter');
    }

    public function testGetBodyIsReadInFullBeyondPostLimit(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], str_repeat('b', 5000)));
        $transport = new Psr18Transport($client, $f, $f);

        $response = $transport->get('https://h.example.com/large-document.xml');

        self::assertSame(5000, \strlen($response->body));
    }

    public function testDownloadStreamsTheBodyIntoTheSinkWithoutBufferingIt(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, ['Retry-After' => '7'], str_repeat('x', 200000)));
        $transport = new Psr18Transport($client, $f, $f);
        $sink = fopen('php://temp', 'w+');
        self::assertNotFalse($sink);

        $response = $transport->download('https://h.example.com/large-document.xml', $sink);

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body, 'download() never returns the body');
        self::assertSame(7, $response->retryAfter);
        rewind($sink);
        self::assertSame(200000, \strlen((string) stream_get_contents($sink)));
    }

    public function testBodyShorterThanContentLengthIsATruncatedDownload(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, ['Content-Length' => '1000'], str_repeat('x', 100)));
        $transport = new Psr18Transport($client, $f, $f);

        try {
            $transport->get('https://h.example.com/large-document.xml');
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringContainsString('truncated, 100 of 1000 bytes', $e->getMessage());
        }

        $client = new StubPsr18Client(new Psr7Response(200, ['Content-Length' => '1000'], str_repeat('x', 100)));
        $sink = fopen('php://temp', 'w+');
        self::assertNotFalse($sink);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('truncated, 100 of 1000 bytes');
        (new Psr18Transport($client, $f, $f))->download('https://h.example.com/large-document.xml', $sink);
    }

    public function testContentLengthIsIgnoredForPostDiagnosticsAndEncodedBodies(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, ['Content-Length' => '5000'], str_repeat('x', 3000)));
        self::assertSame(Psr18Transport::POST_BODY_LIMIT, \strlen((new Psr18Transport($client, $f, $f))->post('https://h.example.com/indexnow', '{}')->body));

        $client = new StubPsr18Client(new Psr7Response(200, ['Content-Length' => '1000', 'Content-Encoding' => 'gzip'], str_repeat('x', 100)));
        self::assertSame(100, \strlen((new Psr18Transport($client, $f, $f))->get('https://h.example.com/large-document.xml')->body), 'a decoded body is legitimately longer or shorter than Content-Length');
    }

    public function testConnectionLostMidBodyBecomesATransportException(): void
    {
        $f = $this->factory();
        $body = new class implements StreamInterface {
            private int $reads = 0;

            public function __toString(): string
            {
                return '';
            }

            public function close(): void {}

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void {}

            public function rewind(): void {}

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                if (++$this->reads === 1) {
                    return str_repeat('a', 100);
                }

                throw new RuntimeException('Unable to read from stream: End of response with 900 bytes missing');
            }

            public function getContents(): string
            {
                return '';
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };
        $client = new StubPsr18Client(new Psr7Response(200, [], $body));
        $sink = fopen('php://temp', 'w+');
        self::assertNotFalse($sink);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('connection lost after 100 bytes');
        (new Psr18Transport($client, $f, $f))->download('https://h.example.com/large-document.xml', $sink);
    }

    public function testDownloadOverConfiguredLimitThrows(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], str_repeat('x', 5000)));
        $transport = new Psr18Transport($client, $f, $f, getBodyLimit: 4096);
        $sink = fopen('php://temp', 'w+');
        self::assertNotFalse($sink);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('larger than 4096 bytes');
        $transport->download('https://h.example.com/large-document.xml', $sink);
    }

    public function testGetBodyOverConfiguredLimitThrows(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], str_repeat('c', 20)));
        $transport = new Psr18Transport($client, $f, $f, getBodyLimit: 10);

        $this->expectException(TransportException::class);
        $transport->get('https://h.example.com/large-document.xml');
    }

    /**
     * @return iterable<string, array{0: string, 1: ?int}>
     */
    public static function retryAfterProvider(): iterable
    {
        yield 'numeric' => ['120', 120];
        yield 'garbage' => ['not-a-date', null];
        yield 'huge value clamped' => ['999999', Psr18Transport::MAX_RETRY_AFTER];
    }

    #[DataProvider('retryAfterProvider')]
    public function testRetryAfterParsing(string $header, ?int $expected): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(429, ['Retry-After' => $header]));
        $transport = new Psr18Transport($client, $f, $f);

        $response = $transport->post('https://h.example.com/indexnow', '{}');

        self::assertSame($expected, $response->retryAfter);
    }

    public function testRetryAfterHttpDateInTheFutureIsPositive(): void
    {
        $f = $this->factory();
        $future = (new DateTimeImmutable('+2 hours'))->format(Response::HTTP_DATE);
        $client = new StubPsr18Client(new Psr7Response(429, ['Retry-After' => $future]));
        $transport = new Psr18Transport($client, $f, $f);

        $response = $transport->post('https://h.example.com/indexnow', '{}');

        self::assertGreaterThan(0, $response->retryAfter);
    }

    public function testRetryAfterHttpDateInThePastIsZero(): void
    {
        $f = $this->factory();
        $past = (new DateTimeImmutable('-2 hours'))->format(Response::HTTP_DATE);
        $client = new StubPsr18Client(new Psr7Response(429, ['Retry-After' => $past]));
        $transport = new Psr18Transport($client, $f, $f);

        $response = $transport->post('https://h.example.com/indexnow', '{}');

        self::assertSame(0, $response->retryAfter);
    }

    public function testExplicitHeadersOverrideExtraHeaders(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200));
        $transport = new Psr18Transport($client, $f, $f, ['X-Test' => 'from-extra']);

        $transport->post('https://h.example.com/indexnow', '{}', ['X-Test' => 'from-explicit']);

        self::assertSame('from-explicit', $client->requests[0]->getHeaderLine('X-Test'));
    }

    public function testUserAgentHeaderIsSent(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200));
        $transport = new Psr18Transport($client, $f, $f);

        $transport->post('https://h.example.com/indexnow', '{}', ['User-Agent' => 'my-agent/1.0']);

        self::assertSame('my-agent/1.0', $client->requests[0]->getHeaderLine('User-Agent'));
    }

    public function testExtraHeadersAreAppliedToGetRequests(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], 'body'));
        $transport = new Psr18Transport($client, $f, $f, ['X-Mock-Scenario' => 'ok200']);

        $transport->get('https://h.example.com/key.txt');

        self::assertSame('ok200', $client->requests[0]->getHeaderLine('X-Mock-Scenario'));
    }
}
