<?php

declare(strict_types=1);

namespace IndexNowKit\Submission;

use DateTimeImmutable;
use IndexNowKit\Result;
use IndexNowKit\ResultStatus;

/**
 * Remembers nothing: the default of every adapter until a store (the `indexnowkit/history` package, or your own) is
 * wired in its place.
 */
final class NullSubmissionStore implements SubmissionStoreInterface
{
    public function record(Result $result, DateTimeImmutable $at): void {}

    public function recent(int $limit = 100, ?string $host = null, ?ResultStatus $status = null): iterable
    {
        return [];
    }

    public function lastFor(string $url): ?SubmissionRecord
    {
        return null;
    }
}
