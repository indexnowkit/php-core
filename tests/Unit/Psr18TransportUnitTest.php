<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use DateTimeInterface;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\Psr18Transport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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

    public function testGetBodyIsReadInFullBeyondPostLimit(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], str_repeat('b', 5000)));
        $transport = new Psr18Transport($client, $f, $f);

        $response = $transport->get('https://h.example.com/sitemap.xml');

        self::assertSame(5000, \strlen($response->body));
    }

    public function testGetBodyOverConfiguredLimitThrows(): void
    {
        $f = $this->factory();
        $client = new StubPsr18Client(new Psr7Response(200, [], str_repeat('c', 20)));
        $transport = new Psr18Transport($client, $f, $f, getBodyLimit: 10);

        $this->expectException(TransportException::class);
        $transport->get('https://h.example.com/sitemap.xml');
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
        $future = (new DateTimeImmutable('+2 hours'))->format(DateTimeInterface::RFC7231);
        $client = new StubPsr18Client(new Psr7Response(429, ['Retry-After' => $future]));
        $transport = new Psr18Transport($client, $f, $f);

        $response = $transport->post('https://h.example.com/indexnow', '{}');

        self::assertGreaterThan(0, $response->retryAfter);
    }

    public function testRetryAfterHttpDateInThePastIsZero(): void
    {
        $f = $this->factory();
        $past = (new DateTimeImmutable('-2 hours'))->format(DateTimeInterface::RFC7231);
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
