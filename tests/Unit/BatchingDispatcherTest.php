<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Dispatch\BatchingDispatcher;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The shape every queue dispatcher of the family shares (spec 19 §4.5): chunks of `batch.max_urls`, one id per
 * chunk in the push and in both log lines, a failing push logged as lost with the URL sample and never rethrown.
 */
final class BatchingDispatcherTest extends TestCase
{
    #[TestDox('the URLs are pushed in chunks of batch.max_urls, each under a fresh id the debug line carries')]
    public function testChunksAndIds(): void
    {
        $pushed = [];
        $logger = new ArrayLogger();
        $dispatcher = new BatchingDispatcher(static function (array $urls, string $id) use (&$pushed): void {
            $pushed[] = [$id, $urls];
        }, Factory::config(['batch' => ['max_urls' => 2]]), $logger, 'message');

        $dispatcher->dispatch(['/a', '/b', '/c']);

        self::assertCount(2, $pushed);
        self::assertSame(['/a', '/b'], $pushed[0][1]);
        self::assertSame(['/c'], $pushed[1][1]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $pushed[0][0]);
        self::assertNotSame($pushed[0][0], $pushed[1][0], 'one id per unit');
        self::assertSame([
            \sprintf('indexnow: 2 URL(s) queued as message %s', $pushed[0][0]),
            \sprintf('indexnow: 1 URL(s) queued as message %s', $pushed[1][0]),
        ], $logger->messages('debug'));
        self::assertSame(['/a', '/b'], $logger->records[0]['context']['urls']);
    }

    #[TestDox('a push that throws is one error line with the id, the reason and the URL sample of logging.max_urls; the rest of the batches still go')]
    public function testFailureIsLoggedAsLost(): void
    {
        $logger = new ArrayLogger();
        $calls = 0;
        $dispatcher = new BatchingDispatcher(static function (array $urls, string $id) use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('queue is down');
            }
        }, Factory::config(['batch' => ['max_urls' => 1], 'logging' => ['max_urls' => 1]]), $logger);

        $dispatcher->dispatch(['/a', '/b']);

        self::assertSame(2, $calls);
        $errors = $logger->messages('error');
        self::assertCount(1, $errors);
        self::assertMatchesRegularExpression('/^indexnow: cannot queue 1 URL\(s\) \(job [0-9a-f]{12}\), they are lost: queue is down$/', $errors[0]);
        self::assertSame(['/a'], $logger->records[0]['context']['urls'], 'the sample is Config::logSample()');
        self::assertInstanceOf(RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertCount(1, $logger->messages('debug'), 'the second batch went through');
    }

    #[TestDox('newJobId() is twelve hex characters, the same generator the worker uses when it re-queues a partial batch')]
    public function testNewJobId(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', BatchingDispatcher::newJobId());
        self::assertNotSame(BatchingDispatcher::newJobId(), BatchingDispatcher::newJobId());
    }
}
