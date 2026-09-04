<?php

declare(strict_types=1);

namespace IndexNowKit\Retry;

use IndexNowKit\Result;
use IndexNowKit\ResultStatus;

/**
 * What a queue worker decides after one submission: which URLs to retry, which were rejected for good, and the
 * log lines for both. The worker keeps only its framework's action: Laravel releases the job with {@see delay()},
 * Messenger throws its recoverable exception with {@see $retryAfter}, yii2-queue retries without a delay.
 */
final readonly class WorkerOutcome
{
    /**
     * @param list<string> $retryUrls    URLs of retryable failures (429, 5xx, network)
     * @param list<string> $finalUrls    URLs of final failures (400, 403, 422): a retry would not help
     * @param list<string> $finalReasons unique, `<engine> <http code or reason>`: "api 403", "yandex unprocessable"
     * @param int|null     $retryAfter   the largest Retry-After among the retryable results, in seconds
     * @param list<Result> $results
     */
    private function __construct(
        public array $retryUrls,
        public array $finalUrls,
        public array $finalReasons,
        public ?int $retryAfter,
        private array $results,
    ) {}

    /**
     * @param list<Result> $results
     */
    public static function of(array $results): self
    {
        $retryAfter = null;
        $reasons = [];
        foreach ($results as $result) {
            if ($result->status !== ResultStatus::Failed) {
                continue;
            }
            if ($result->retryable) {
                if ($result->retryAfter !== null) {
                    $retryAfter = max($retryAfter ?? 0, $result->retryAfter);
                }

                continue;
            }
            $reasons[] = \sprintf('%s %s', $result->engine, $result->httpCode !== null ? (string) $result->httpCode : ($result->reason->value ?? 'failed'));
        }

        return new self(
            Result::retryableUrls($results),
            Result::urlsWhere($results, static fn(Result $r): bool => $r->status === ResultStatus::Failed && !$r->retryable),
            array_values(array_unique($reasons)),
            $retryAfter,
            $results,
        );
    }

    public function hasRetryable(): bool
    {
        return $this->retryUrls !== [];
    }

    public function hasFinalFailures(): bool
    {
        return $this->finalUrls !== [];
    }

    /**
     * For frameworks that take the delay from the job (Laravel): seconds before the next attempt per the policy
     * (Retry-After wins), null when the attempts are used up and the job should give up.
     *
     * @param int $attempt 1-based number of the attempt just made
     */
    public function delay(RetryPolicy $policy, int $attempt): ?int
    {
        return $policy->delayAfter($this->results, $attempt);
    }

    /**
     * `indexnow: {count} URL(s) of job {id} will be retried{delay}{attempt}` at info.
     *
     * @param int|null $delay   seconds, when the framework takes it from the job
     * @param int|null $attempt the attempt just made, when the framework tells the job
     *
     * @return array{0: string, 1: array<string, mixed>} PSR-3 message and context
     */
    public function retryLog(string $jobId, ?int $delay = null, ?int $attempt = null): array
    {
        return ['indexnow: {count} URL(s) of job {id} will be retried{delay}{attempt}', [
            'count' => \count($this->retryUrls),
            'id' => $jobId,
            'delay' => $delay === null ? '' : \sprintf(' in %ds', $delay),
            'attempt' => $attempt === null ? '' : \sprintf(' (attempt %d)', $attempt),
        ]];
    }

    /**
     * `indexnow: giving up on {count} URL(s) of job {id} after {attempt} attempt(s)` at error.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function gaveUpLog(string $jobId, int $attempt): array
    {
        return ['indexnow: giving up on {count} URL(s) of job {id} after {attempt} attempt(s)', ['count' => \count($this->retryUrls), 'id' => $jobId, 'attempt' => $attempt]];
    }

    /**
     * `indexnow: {count} URL(s) of job {id} rejected permanently ({reasons}); run "{check}"` at error.
     *
     * @param string $checkCommand the check command as typed in this framework ("php artisan indexnow:check")
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function finalLog(string $jobId, string $checkCommand): array
    {
        return ['indexnow: {count} URL(s) of job {id} rejected permanently ({reasons}); run "{check}"', ['count' => \count($this->finalUrls), 'id' => $jobId, 'reasons' => implode(', ', $this->finalReasons), 'check' => $checkCommand]];
    }
}
