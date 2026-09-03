<?php

declare(strict_types=1);

namespace IndexNowKit\Retry;

use IndexNowKit\Result;

/**
 * Backoff for retryable results (429, 5xx, network): honours Retry-After, otherwise exponential
 * (base × multiplier^(attempt-1)), capped. Shared by queue handlers and RetryingSubmitter so every
 * adapter retries the same way.
 */
final readonly class RetryPolicy
{
    /**
     * @param int $maxAttempts total attempts including the first one
     * @param int $baseDelay   seconds before the second attempt
     */
    public function __construct(
        public int $maxAttempts = 3,
            public int $baseDelay = 60,
                public float $multiplier = 2.0,
                    public int $maxDelay = 3600,
                        ) {}

    /**
     * Seconds to wait before the next attempt, or null when no retry should happen.
     *
     * @param iterable<Result> $results outcome of the attempt just made
     * @param int              $attempt 1-based number of the attempt just made
     */
    public function delayAfter(iterable $results, int $attempt) : ? int
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }
        $retryAfter = null;
        $retryable = false;
        foreach ($results as $result) {
            if (!$result->retryable) {
                continue;
            }
            $retryable = true;
            if ($result->retryAfter !== null) {
                $retryAfter = max($retryAfter ?? 0, $result->retryAfter);
            }
        }
        if (!$retryable) {
            return null;
        }
        $delay = $retryAfter ?? (int) round($this->baseDelay * $this->multiplier ** ($attempt - 1));

        return max(0, min($delay, $this->maxDelay));
    }
}
