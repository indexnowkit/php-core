<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Submission;

use DateTimeImmutable;
use IndexNowKit\Client;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Reason;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use IndexNowKit\Submission\NullSubmissionStore;
use IndexNowKit\Submission\SubmissionRecord;
use IndexNowKit\Submission\SubmissionStoreInterface;
use IndexNowKit\Submitter;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** An in-memory store that keeps everything, the shape the history package fills in. */
final class ArraySubmissionStore implements SubmissionStoreInterface
{
    /** @var list<SubmissionRecord> */
    public array $records = [];

    public function record(Result $result, DateTimeImmutable $at): void
    {
        $this->records[] = SubmissionRecord::of($result, $at);
    }

    public function recent(int $limit = 100, ?string $host = null, ?ResultStatus $status = null): iterable
    {
        $matching = array_filter($this->records, static fn(SubmissionRecord $r): bool => ($host === null || $r->result->host === $host) && ($status === null || $r->result->status === $status));

        return \array_slice(array_reverse($matching), 0, $limit);
    }

    public function lastFor(string $url): ?SubmissionRecord
    {
        foreach (array_reverse($this->records) as $record) {
            if (\in_array($url, $record->urls, true)) {
                return $record;
            }
        }

        return null;
    }
}

final class ThrowingSubmissionStore implements SubmissionStoreInterface
{
    public function record(Result $result, DateTimeImmutable $at): void
    {
        throw new RuntimeException('disk full');
    }

    public function recent(int $limit = 100, ?string $host = null, ?ResultStatus $status = null): iterable
    {
        return [];
    }

    public function lastFor(string $url): ?SubmissionRecord
    {
        return null;
    }
}

final class SubmissionStoreTest extends TestCase
{
    #[TestDox('the submitter records one entry per Result at the clock time: two engines give two records, skipped results are recorded too, lastFor() is the later one')]
    public function testEveryResultIsRecorded(): void
    {
        $t = new FakeTransport();
        $t->willRespond(new Response(200), new Response(429, '', 30));
        $config = Factory::config(['engines' => ['api', 'yandex'], 'debounce' => ['per_url' => 0]]);
        $store = new ArraySubmissionStore();
        $clock = new FrozenClock('2026-09-06 10:00:00');
        $submitter = new Submitter(new Client($t, StaticKeyProvider::fromConfig($config), $config), $config, logger: new ArrayLogger(), store: $store, clock: $clock);

        $results = $submitter->submit(['/a', 'ftp://bad']);

        self::assertCount(3, $results, 'invalid URL skip + api + yandex');
        self::assertCount(3, $store->records);
        self::assertSame([Reason::InvalidUrl, null, Reason::RateLimited], array_map(static fn(SubmissionRecord $r): ?Reason => $r->result->reason, $store->records));
        self::assertSame(['api', 'yandex'], [$store->records[1]->result->engine, $store->records[2]->result->engine]);
        self::assertSame(['https://www.example.com/a'], $store->records[1]->urls);
        self::assertSame('2026-09-06 10:00:00', $store->records[1]->at->format('Y-m-d H:i:s'));
        self::assertSame('yandex', $store->lastFor('https://www.example.com/a')?->result->engine, 'the later record wins, whatever its status');
        self::assertSame(['ftp://bad'], $store->lastFor('ftp://bad')?->urls, 'a skipped URL is remembered under the URL as given');
        self::assertNull($store->lastFor('https://www.example.com/never'));
        self::assertCount(1, [...$store->recent(status: ResultStatus::Failed)]);
        self::assertCount(2, [...$store->recent(host: 'www.example.com')]);

        $clock->advance(60);
        $dryConfig = $config->with(dryRun: true);
        $dry = new Submitter(new Client($t, StaticKeyProvider::fromConfig($dryConfig), $dryConfig), $dryConfig, store: $store, clock: $clock);
        $dry->submit(['/a']);
        $last = $store->lastFor('https://www.example.com/a');
        self::assertSame(Reason::DryRun, $last?->result->reason, 'a dry-run skip is a record too');
        self::assertSame('yandex', $last?->result->engine, 'a dry-run result carries the engine it would have reached; the last of the two engines wins');
        self::assertSame('2026-09-06 10:01:00', $last?->at->format('Y-m-d H:i:s'));
    }

    #[TestDox('a throwing store is one error log line per submit(); the results and the listeners are not affected; the null store keeps nothing')]
    public function testThrowingStoreDoesNotAffectDelivery(): void
    {
        $t = new FakeTransport();
        $logger = new ArrayLogger();
        $config = Factory::config();
        $submitter = new Submitter(new Client($t, StaticKeyProvider::fromConfig($config), $config), $config, logger: $logger, store: new ThrowingSubmissionStore());
        $seen = 0;
        $submitter->addListener(static function () use (&$seen): void {
            ++$seen;
        });

        $results = $submitter->submit(['/a', '/b']);

        self::assertCount(1, $results);
        self::assertTrue($results[0]->isSuccess());
        self::assertSame(1, $seen);
        self::assertCount(1, $logger->messages('error'));
        self::assertStringContainsString('submission store failed, 1 result(s) not recorded: disk full', $logger->messages('error')[0]);

        $null = new NullSubmissionStore();
        $null->record($results[0], new DateTimeImmutable());
        self::assertSame([], [...$null->recent()]);
        self::assertNull($null->lastFor('https://www.example.com/a'));
    }
}
