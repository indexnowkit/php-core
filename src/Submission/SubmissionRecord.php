<?php

declare(strict_types=1);

namespace IndexNowKit\Submission;

use DateTimeImmutable;
use IndexNowKit\Result;

/**
 * One remembered submission: the URLs of the batch, the Result the engine (or the pipeline) gave, and when.
 * `$urls` repeats `$result->urls` so a store may index them without unpacking the Result.
 */
final readonly class SubmissionRecord
{
    /**
     * @param list<string> $urls
     */
    public function __construct(public array $urls, public Result $result, public DateTimeImmutable $at) {}

    public static function of(Result $result, DateTimeImmutable $at): self
    {
        return new self($result->urls, $result, $at);
    }
}
