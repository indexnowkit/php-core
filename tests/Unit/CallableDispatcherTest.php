<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Dispatch\CallableDispatcher;
use IndexNowKit\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CallableDispatcherTest extends TestCase
{
    public function testDispatchInvokesTheCallableWithTheUrls(): void
    {
        $received = null;
        $dispatcher = new CallableDispatcher(function (array $urls) use (&$received): void {
            $received = $urls;
        });

        $dispatcher->dispatch(['/a', '/b']);

        self::assertSame(['/a', '/b'], $received);
    }

    public function testExceptionsAreLoggedAndNeverRethrown(): void
    {
        $logger = new ArrayLogger();
        $dispatcher = new CallableDispatcher(static function (array $urls): void {
            throw new RuntimeException('queue is down');
        }, $logger);

        $dispatcher->dispatch(['/a']);

        self::assertCount(1, $logger->messages('error'));
    }
}
