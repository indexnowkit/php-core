<?php

declare(strict_types=1);

namespace IndexNowKit;

/**
 * Entry point for sending URLs. Never throws for remote failures; see Result::$status.
 */
interface SubmitterInterface
{
    /**
     * Normalize, de-duplicate, debounce and send. Invalid URLs are dropped with a warning.
     *
     * @param iterable<string> $urls absolute or base_url-relative
     *
     * @return list<Result> one per endpoint × host × batch; empty when nothing was sent
     */
    public function submit(iterable $urls): array;

    /**
     * Normalize and de-duplicate without sending (what a collector stores).
     *
     * @param iterable<string> $urls
     *
     * @return list<string>
     */
    public function prepare(iterable $urls): array;

    /**
     * Called with every Result (also skipped ones) right after it is produced. Exceptions thrown by a listener are
     * logged and swallowed. A decorator MUST forward addListener() to the decorated submitter; otherwise every
     * listener registered on the outer object (profilers, metrics) is silently dropped.
     *
     * @param callable(Result): void $listener
     */
    public function addListener(callable $listener): void;
}
