<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Retry\RetryingSubmitter;
use IndexNowKit\Retry\RetryPolicy;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;

final class RetryingSubmitterTest extends TestCase
{
    /** @var list<list<string>> */
    private array $calls = [];

    /** @var list<int> */
    private array $sleeps = [];

    public function testPrepareAndListenersAreDelegated(): void
    {
        $inner = $this->inner([]);
        $retrying = new RetryingSubmitter($inner);
        $listener = static fn(Result $r) => null;

        self::assertSame(['prepared'], $retrying->prepare(['x']));
        $retrying->addListener($listener);
        self::assertSame([$listener], $inner->listeners);
    }

    public function testStopsAfterMaxAttemptsAndKeepsTheLastFailure(): void
    {
        $always429 = static fn(array $urls): array => [self::outcome($urls, 429, retryable: true, retryAfter: 1)];
        $retrying = new RetryingSubmitter($this->inner($always429), new RetryPolicy(maxAttempts: 3), new ArrayLogger(), $this->sleeper());

        $results = $retrying->submit(['https://h.example.com/a']);

        self::assertCount(3, $this->calls, 'first attempt plus two retries');
        self::assertSame([1, 1], $this->sleeps);
        self::assertCount(1, $results);
        self::assertTrue($results[0]->retryable);
        self::assertSame(429, $results[0]->httpCode);
    }

    public function testOnlyRetryableUrlsAreResentAndResultsAreMerged(): void
    {
        $attempt = 0;
        $handler = function (array $urls) use (&$attempt): array {
            ++$attempt;
            if ($attempt === 1) {
                return [
                    self::outcome(['https://h.example.com/ok'], 200),
                    self::outcome(['https://h.example.com/slow'], 503, retryable: true),
                    self::outcome(['https://h.example.com/bad'], 422),
                ];
            }

            return [self::outcome($urls, 200)];
        };
        $logger = new ArrayLogger();
        $retrying = new RetryingSubmitter($this->inner($handler), new RetryPolicy(serverErrorDelay: 5), $logger, $this->sleeper());

        $results = $retrying->submit(['https://h.example.com/ok', 'https://h.example.com/slow', 'https://h.example.com/bad']);

        self::assertSame([['https://h.example.com/slow']], \array_slice($this->calls, 1), 'only the retryable URL is resent');
        self::assertSame([5], $this->sleeps);
        $byUrl = [];
        foreach ($results as $result) {
            $byUrl[$result->urls[0]] = $result->status;
        }
        self::assertSame(['https://h.example.com/ok' => ResultStatus::Ok, 'https://h.example.com/bad' => ResultStatus::Failed, 'https://h.example.com/slow' => ResultStatus::Ok], $byUrl);
        self::assertStringContainsString('retrying 1 URL(s) in 5s', implode("\n", $logger->messages('info')));
    }

    public function testNoRetryWhenNothingIsRetryable(): void
    {
        $retrying = new RetryingSubmitter($this->inner(static fn(array $urls): array => [self::outcome($urls, 200)]), new RetryPolicy(), new ArrayLogger(), $this->sleeper());

        $retrying->submit(['https://h.example.com/a']);

        self::assertCount(1, $this->calls);
        self::assertSame([], $this->sleeps);
    }

    /**
     * @param list<string> $urls
     */
    private static function outcome(array $urls, int $code, bool $retryable = false, ?int $retryAfter = null): Result
    {
        return new Result('api', 'h.example.com', $urls, $code === 200 ? ResultStatus::Ok : ResultStatus::Failed, $code, null, $retryable, $retryAfter);
    }

    /**
     * @param (callable(list<string>): list<Result>)|array<mixed> $handler
     */
    private function inner(callable|array $handler): SubmitterInterface&RecordingInnerSubmitter
    {
        $calls = &$this->calls;

        return new RecordingInnerSubmitter(function (array $urls) use (&$calls, $handler): array {
            $calls[] = $urls;

            return \is_callable($handler) ? $handler($urls) : [];
        });
    }

    /**
     * @return callable(int): void
     */
    private function sleeper(): callable
    {
        $sleeps = &$this->sleeps;

        return static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        };
    }
}

final class RecordingInnerSubmitter implements SubmitterInterface
{
    /** @var list<callable> */
    public array $listeners = [];

    /** @var callable(list<string>): list<Result> */
    private $handler;

    /**
     * @param callable(list<string>): list<Result> $handler
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function submit(iterable $urls): array
    {
        return ($this->handler)(\is_array($urls) ? array_values($urls) : iterator_to_array($urls, false));
    }

    public function prepare(iterable $urls): array
    {
        return ['prepared'];
    }

    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }
}
