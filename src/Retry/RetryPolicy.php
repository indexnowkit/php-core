<?php

declare(strict_types=1);

namespace IndexNowKit\Retry;

use IndexNowKit\Result;

/**
 * Backoff for retryable results: honours Retry-After, otherwise exponential (base × multiplier^(attempt-1)),
 * capped. The base is 60 s after a 429 (the engine asked to slow down) and 5 s after 5xx / network failures
 * (docs/spec/01-protocol.md). Shared by queue handlers and RetryingSubmitter so every adapter retries the same way.
 */
final readonly class RetryPolicy
{
    /**
     * @param int $maxAttempts      total attempts including the first one
     * @param int $baseDelay        seconds before the second attempt after a 429 without Retry-After
     * @param int $serverErrorDelay seconds before the second attempt after 5xx / network failures
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelay = 60,
        public float $multiplier = 2.0,
        public int $maxDelay = 3600,
        public int $serverErrorDelay = 5,
    ) {}

    /**
     * Seconds to wait before the next attempt, or null when no retry should happen.
     *
     * @param iterable<Result> $results outcome of the attempt just made
     * @param int              $attempt 1-based number of the attempt just made
     */
    public function delayAfter(iterable $results, int $attempt): ?int
    {
        if ($attempt >= $this->maxAttempts) {
            return null;
        }
        $retryAfter = null;
        $retryable = false;
        $rateLimited = false;
        foreach ($results as $result) {
            if (!$result->retryable) {
                continue;
            }
            $retryable = true;
            $rateLimited = $rateLimited || $result->httpCode === 429;
            if ($result->retryAfter !== null) {
                $retryAfter = max($retryAfter ?? 0, $result->retryAfter);
            }
        }
        if (!$retryable) {
            return null;
        }
        $base = $rateLimited ? $this->baseDelay : $this->serverErrorDelay;
        $delay = $retryAfter ?? (int) round($base * $this->multiplier ** ($attempt - 1));

        return max(0, min($delay, $this->maxDelay));
    }
}
