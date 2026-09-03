<?php

declare(strict_types=1);

namespace IndexNowKit\Retry;

use IndexNowKit\Result;
use IndexNowKit\SubmitterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decorator that re-submits retryable URLs in-process (CLI, workers, cron). In web requests prefer a
 * queue: the delay blocks.
 */
final class RetryingSubmitter implements SubmitterInterface
{
    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param (callable(int): void)|null $sleeper seconds sleeper, injectable for tests
     */
    public function __construct(
        private readonly SubmitterInterface $inner,
        private readonly RetryPolicy $policy = new RetryPolicy(),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Results of the last attempt for each URL: retried URLs replace their earlier failed result.
     */
    public function submit(iterable $urls): array
    {
        $attempt = 1;
        $results = $this->inner->submit($urls);
        while (($delay = $this->policy->delayAfter($results, $attempt)) !== null) {
            $retry = Result::retryableUrls($results);
            $this->logger->info('indexnow: retrying {count} URL(s) in {delay}s (attempt {attempt} of {max})', ['count' => \count($retry), 'delay' => $delay, 'attempt' => $attempt + 1, 'max' => $this->policy->maxAttempts]);
            if ($delay > 0) {
                ($this->sleeper)($delay);
            }
            ++$attempt;
            $results = [...array_values(array_filter($results, static fn(Result $r): bool => !$r->retryable)), ...$this->inner->submit($retry)];
        }

        return $results;
    }

    public function prepare(iterable $urls): array
    {
        return $this->inner->prepare($urls);
    }

    public function addListener(callable $listener): void
    {
        $this->inner->addListener($listener);
    }
}
