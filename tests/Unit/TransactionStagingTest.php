<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Transaction\TransactionStaging;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TransactionStagingTest extends TestCase
{
    public function testStageAccumulatesAndPendingCountReflectsItDeduplicated(): void
    {
        $staging = new TransactionStaging();
        $scope = new stdClass();

        $staging->stage($scope, ['/a', '/b']);
        $staging->stage($scope, ['/b', '/c']);

        self::assertTrue($staging->hasPending($scope));
        self::assertSame(3, $staging->pendingCount($scope));
    }

    public function testHasPendingIsFalseForAnUnknownScope(): void
    {
        $staging = new TransactionStaging();

        self::assertFalse($staging->hasPending(new stdClass()));
        self::assertSame(0, $staging->pendingCount(new stdClass()));
    }

    public function testCommitHandsStagedUrlsToTheSinkAndClearsThem(): void
    {
        $received = null;
        $staging = new TransactionStaging(static function (array $urls) use (&$received): void {
            $received = $urls;
        });
        $scope = new stdClass();
        $staging->stage($scope, ['/a']);

        $staging->commit($scope);

        self::assertSame(['/a'], $received);
        self::assertSame(0, $staging->pendingCount($scope));
    }

    public function testCommitWithoutPendingUrlsDoesNotCallTheSink(): void
    {
        $called = false;
        $staging = new TransactionStaging(static function () use (&$called): void {
            $called = true;
        });

        $staging->commit(new stdClass());

        self::assertFalse($called);
    }

    public function testSetSinkBindsTheSinkAfterConstruction(): void
    {
        $received = null;
        $staging = new TransactionStaging();
        $staging->setSink(static function (array $urls) use (&$received): void {
            $received = $urls;
        });
        $scope = new stdClass();
        $staging->stage($scope, ['/a']);

        $staging->commit($scope);

        self::assertSame(['/a'], $received);
    }

    public function testDiscardLogsDebugAndClearsThePendingUrls(): void
    {
        $logger = new ArrayLogger();
        $staging = new TransactionStaging(null, $logger);
        $scope = new stdClass();
        $staging->stage($scope, ['/a', '/b']);

        $staging->discard($scope);

        self::assertSame(0, $staging->pendingCount($scope));
        $debug = $logger->messages('debug');
        self::assertCount(1, $debug);
        self::assertStringContainsString('discarding 2 staged URL(s)', $debug[0]);
    }

    public function testDiscardOfAnEmptyScopeLogsNothing(): void
    {
        $logger = new ArrayLogger();
        $staging = new TransactionStaging(null, $logger);

        $staging->discard(new stdClass());

        self::assertSame([], $logger->messages('debug'));
    }
}
