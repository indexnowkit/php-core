<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Http\Response;
use IndexNowKit\Reason;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FilterRecentThrowsDebounceStore implements DebounceStoreInterface
{
    public function filterRecent(array $urls, int $ttlSeconds): array
    {
        throw new RuntimeException('store down');
    }

    public function markSubmitted(array $urls, int $ttlSeconds): void {}
}

final class MarkSubmittedThrowsDebounceStore implements DebounceStoreInterface
{
    public function filterRecent(array $urls, int $ttlSeconds): array
    {
        return [];
    }

    public function markSubmitted(array $urls, int $ttlSeconds): void
    {
        throw new RuntimeException('store down');
    }
}

final class SubmitterTest extends TestCase
{
    public function testListenerReceivesEveryResultIncludingSkipped(): void
    {
        $t = new FakeTransport();
        $config = Factory::config(['key' => null, 'hosts' => ['www.example.com' => Factory::KEY]]);
        $submitter = Factory::submitter($t, $config);
        $seen = [];
        $submitter->addListener(function (Result $r) use (&$seen): void {
            $seen[] = $r;
        });

        $submitter->submit(['https://www.example.com/a', 'https://other.example.com/b']);

        self::assertCount(2, $seen);
        $statuses = array_map(static fn(Result $r): ResultStatus => $r->status, $seen);
        self::assertContains(ResultStatus::Ok, $statuses);
        self::assertContains(ResultStatus::Skipped, $statuses);
    }

    public function testThrowingListenerIsLoggedAndDoesNotStopOthersOrSubmission(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $submitter = Factory::submitter($t, null, $logger);
        $calledSecond = false;
        $submitter->addListener(static function (Result $r): void {
            throw new RuntimeException('boom');
        });
        $submitter->addListener(function (Result $r) use (&$calledSecond): void {
            $calledSecond = true;
        });

        $results = $submitter->submit(['/a']);

        self::assertTrue($calledSecond, 'second listener still runs');
        self::assertCount(1, $results);
        self::assertCount(1, $logger->messages('error'));
    }

    public function testDebounceStoreThrowingInFilterRecentFailsOpen(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $config = Factory::config(['debounce' => ['per_url' => 600]]);
        $submitter = Factory::submitter($t, $config, $logger, new FilterRecentThrowsDebounceStore());

        $results = $submitter->submit(['/a']);

        self::assertCount(1, $t->posts, 'submission proceeds despite the debounce store failing');
        self::assertCount(1, $results);
        self::assertCount(1, $logger->messages('warning'));
    }

    public function testDebounceStoreThrowingInMarkSubmittedWarnsButKeepsResults(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $config = Factory::config(['debounce' => ['per_url' => 600]]);
        $submitter = Factory::submitter($t, $config, $logger, new MarkSubmittedThrowsDebounceStore());

        $results = $submitter->submit(['/a']);

        self::assertCount(1, $results);
        self::assertSame(ResultStatus::Ok, $results[0]->status);
        self::assertCount(1, $logger->messages('warning'));
    }

    public function testDebounceMarksOnlySuccessfulResults(): void
    {
        $t = (new FakeTransport())->willRespond(new Response(200), new Response(403));
        $clock = new FrozenClock();
        $config = Factory::config(['debounce' => ['per_url' => 600]]);
        $submitter = Factory::submitter($t, $config, null, new MemoryDebounceStore($clock));

        $submitter->submit(['https://ok.example.com/a', 'https://blocked.example.com/b']);
        self::assertCount(2, $t->posts);

        $submitter->submit(['https://ok.example.com/a', 'https://blocked.example.com/b']);
        // only the failed URL is re-sent; the successful one is debounced
        self::assertCount(3, $t->posts);
        self::assertSame(['https://blocked.example.com/b'], $t->posts[2]['body']['urlList']);
    }

    public function testDisabledConfigReturnsSkippedResultsAndLogsInfo(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $submitter = Factory::submitter($t, Factory::config(['enabled' => false]), $logger);

        $results = $submitter->submit(['/a']);
        self::assertCount(1, $results);
        self::assertSame(ResultStatus::Skipped, $results[0]->status);
        self::assertSame(Reason::Disabled, $results[0]->reason);
        self::assertSame(['https://www.example.com/a'], $results[0]->urls);
        self::assertCount(0, $t->posts);
        self::assertCount(1, $logger->messages('info'));
    }

    public function testInvalidUrlsAreDroppedWithWarning(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $submitter = Factory::submitter($t, null, $logger);

        $prepared = $submitter->prepare(['https://user:pass@evil.example.com/x', 'https://www.example.com/ok', '']);

        self::assertSame(['https://www.example.com/ok'], $prepared);
        self::assertCount(2, $logger->messages('warning'));
    }

    public function testPrepareDedupesAndNormalizes(): void
    {
        $submitter = Factory::submitter(new FakeTransport());

        $prepared = $submitter->prepare(['/a', 'https://www.example.com/a', '/a#frag']);

        self::assertSame(['https://www.example.com/a'], $prepared);
    }
}
