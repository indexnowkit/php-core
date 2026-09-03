<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Dispatch\SyncDispatcher;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Tests\Support\ArrayLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ThrowingSubmitter implements SubmitterInterface
{
    public function submit(iterable $urls): array
    {
        throw new RuntimeException('submitter exploded');
    }

    public function prepare(iterable $urls): array
    {
        return [];
    }
}

final class RecordingSubmitter implements SubmitterInterface
{
    /** @var list<string> */
    public array $submitted = [];

    public function submit(iterable $urls): array
    {
        foreach ($urls as $url) {
            $this->submitted[] = $url;
        }

        return [];
    }

    public function prepare(iterable $urls): array
    {
        return [];
    }
}

final class SyncDispatcherTest extends TestCase
{
    public function testDispatchDelegatesToSubmitter(): void
    {
        $submitter = new RecordingSubmitter();
        $dispatcher = new SyncDispatcher($submitter);

        $dispatcher->dispatch(['/a', '/b']);

        self::assertSame(['/a', '/b'], $submitter->submitted);
    }

    public function testExceptionsAreLoggedAndNeverRethrown(): void
    {
        $logger = new ArrayLogger();
        $dispatcher = new SyncDispatcher(new ThrowingSubmitter(), $logger);

        $dispatcher->dispatch(['/a']);

        self::assertCount(1, $logger->messages('error'));
    }
}
