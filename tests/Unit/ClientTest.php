<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Reason;
use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\ResultStatus;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Throttle\ThrottleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CountingThrottle implements ThrottleInterface
{
    public int $calls = 0;

    public function acquire(): void
    {
        ++$this->calls;
    }
}

final class ClientTest extends TestCase
{
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';
    private const HOST = 'www.example.com';
    private const URL = 'https://www.example.com/a';

    private function client(FakeTransport $t, ?Config $config = null, ?ArrayLogger $logger = null, ?ThrottleInterface $throttle = null): Client
    {
        $config ??= Factory::config();

        return new Client($t, StaticKeyProvider::fromConfig($config), $config, $logger ?? new ArrayLogger(), $throttle ?? new NullThrottle());
    }

    public function testHttp400IsFailedNotRetryableAndMentions400(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(400, 'bad'));
        $result = $this->client($t)->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);

        self::assertSame(ResultStatus::Failed, $result->status);
        self::assertFalse($result->retryable);
        self::assertStringContainsString('400', (string) $result->error);
    }

    /**
     * @return iterable<string, array{0: int, 1: ?int, 2: ?int}>
     */
    public static function serverErrorProvider(): iterable
    {
        yield '500 with Retry-After' => [500, 120, 120];
        yield '503 with Retry-After' => [503, 30, 30];
        yield '503 without Retry-After' => [503, null, null];
    }

    #[DataProvider('serverErrorProvider')]
    public function testServerErrorsAreFailedRetryableWithRetryAfter(int $status, ?int $retryAfterHeader, ?int $expectedRetryAfter): void
    {
        $t = (new FakeTransport())->willRespond(new Response($status, 'oops', $retryAfterHeader));
        $result = $this->client($t)->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);

        self::assertSame(ResultStatus::Failed, $result->status);
        self::assertTrue($result->retryable);
        self::assertSame($expectedRetryAfter, $result->retryAfter);
    }

    public function testUnknownStatusIsFailedNotRetryable(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(418, "I'm a teapot"));
        $result = $this->client($t)->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);

        self::assertSame(ResultStatus::Failed, $result->status);
        self::assertFalse($result->retryable);
        self::assertStringContainsString('418', (string) $result->error);
    }

    public function testConsecutive403sEscalateOnceThenWarnAndResetAfterSuccess(): void
    {
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $client = $this->client($t, null, $logger);
        $levelCount = static fn(string $level): int => \count(array_filter($logger->records, static fn(array $r): bool => $r['level'] === $level));

        for ($i = 1; $i <= 4; ++$i) {
            $t->willRespond(new Response(403));
            $client->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);
        }
        self::assertSame(4, $levelCount('error'), 'consecutive count 1-4 stays at error level');
        self::assertSame(0, $levelCount('critical'));

        $t->willRespond(new Response(403));
        $client->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);
        self::assertSame(1, $levelCount('critical'), 'escalates exactly once on the 5th consecutive 403');

        $t->willRespond(new Response(403));
        $client->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);
        self::assertSame(1, $levelCount('critical'), '6th does not escalate again');
        self::assertSame(1, $levelCount('warning'), '6th logs warning instead');

        $t->willRespond(new Response(200));
        $client->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);

        $t->willRespond(new Response(403));
        $client->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);
        self::assertSame(5, $levelCount('error'), 'counter reset after a 200, back to error level');
    }

    public function testNonTransportExceptionFromTheHttpClientBecomesAMaskedResult(): void
    {
        $t = (new FakeTransport())->willRespond(new RuntimeException('client exploded while sending ' . Factory::KEY));
        $logger = new ArrayLogger();
        $config = Factory::config();
        $client = new Client($t, StaticKeyProvider::fromConfig($config), $config, $logger, new NullThrottle());

        $results = $client->submitAll(['https://www.example.com/a']);

        self::assertCount(1, $results);
        self::assertSame(ResultStatus::Failed, $results[0]->status);
        self::assertTrue($results[0]->retryable);
        self::assertStringContainsString('RuntimeException', (string) $results[0]->error);
        self::assertStringNotContainsString(Factory::KEY, (string) $results[0]->error);
        self::assertStringNotContainsString(Factory::KEY, implode("\n", $logger->messages()));
        self::assertCount(1, $logger->messages('error'));
    }

    public function testJsonEncodingFailureIsFailedResultNotException(): void
    {
        $t = new FakeTransport();
        $results = $this->client($t)->submitAll(["https://www.example.com/\xB1\x31"]);

        self::assertCount(1, $results);
        self::assertSame(ResultStatus::Failed, $results[0]->status);
        self::assertStringContainsString('JSON', (string) $results[0]->error);
        self::assertCount(0, $t->posts);
    }

    public function testUnmanagedHostIsSkippedWithNoneEngine(): void
    {
        $config = Factory::config(['key' => null, 'hosts' => ['other.example.com' => Factory::KEY]]);
        $t = new FakeTransport();
        $results = $this->client($t, $config)->submitAll([self::URL]);

        self::assertCount(1, $results);
        self::assertSame(ResultStatus::Skipped, $results[0]->status);
        self::assertSame('none', $results[0]->engine);
        self::assertNotNull($results[0]->error);
        self::assertCount(0, $t->posts);
    }

    public function testDryRunResultHasDryRunErrorAndEndpoint(): void
    {
        $config = Factory::config(['dry_run' => true]);
        $t = new FakeTransport();
        $result = $this->client($t, $config)->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);

        self::assertSame(ResultStatus::Skipped, $result->status);
        self::assertSame(Reason::DryRun, $result->reason);
        self::assertSame(self::ENDPOINT, $result->endpoint);
        self::assertCount(0, $t->posts);
    }

    public function testResultEndpointMatchesRequestedEndpoint(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(200));
        $result = $this->client($t)->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);

        self::assertSame(self::ENDPOINT, $result->endpoint);
    }

    public function testThrottleAcquiredOncePerPostAndSkippedInDryRun(): void
    {
        $throttle = new CountingThrottle();
        $t = (new FakeTransport())->willRespond(new Response(200), new Response(200));
        $client = $this->client($t, null, null, $throttle);

        $client->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, [self::URL]);
        $client->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, ['https://www.example.com/b']);
        self::assertSame(2, $throttle->calls);

        $dryClient = $this->client(new FakeTransport(), Factory::config(['dry_run' => true]), null, $throttle);
        $dryClient->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, ['https://www.example.com/c']);
        self::assertSame(2, $throttle->calls, 'dry-run must not acquire the throttle');
    }

    public function testEmptyBatchThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->client(new FakeTransport())->submitBatch(self::ENDPOINT, self::HOST, Factory::KEY, []);
    }
}
