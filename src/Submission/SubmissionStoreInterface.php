<?php

declare(strict_types=1);

namespace IndexNowKit\Submission;

use DateTimeImmutable;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;

/**
 * Where submissions are remembered: one record per {@see Result} (a batch × an endpoint, skipped results included,
 * `Result::NO_ENGINE` for dry-run and other skips), written by `Submitter` after every `submit()`. The core ships
 * {@see NullSubmissionStore} only; the `indexnowkit/history` package brings PSR-16 and PDO stores and the `history`
 * command. Implement tier ({@see docs/bc.md}): the core calls you, methods are not added in a minor.
 *
 * One URL sent to `engines: ['api', 'yandex']` gives two records; {@see lastFor()} returns the later one. A store must
 * never throw out of {@see record()}: `Submitter` logs and goes on, delivery is not affected.
 */
interface SubmissionStoreInterface
{
    /** One record for one Result, with the time the Submitter's clock gave. */
    public function record(Result $result, DateTimeImmutable $at): void;

    /**
     * The newest records first, optionally of one host and/or one status.
     *
     * @return iterable<SubmissionRecord>
     */
    public function recent(int $limit = 100, ?string $host = null, ?ResultStatus $status = null): iterable;

    /** The latest record whose URLs contain $url, whatever its status; null when the URL was never submitted. */
    public function lastFor(string $url): ?SubmissionRecord;
}
