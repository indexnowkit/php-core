<?php

declare(strict_types=1);

namespace IndexNowKit\Collector;

use Countable;

/**
 * Per-unit-of-work buffer (HTTP request, console command, queue message) of normalized URLs, flushed once
 * at the end of the unit of work. Decorate {@see Collector} for a durable outbox or a per-tenant buffer.
 *
 * "May grow" interface (docs/bc.md): a method may be appended in a minor. Pin `^0.2.0` if you implement it
 * from scratch; decorating the shipped implementation is safe.
 */
interface CollectorInterface extends Countable
{
    /**
     * @param iterable<string> $urls already normalized (see SubmitterInterface::prepare())
     */
    public function add(iterable $urls): void;

    public function isEmpty(): bool;

    /**
     * Buffered URLs without draining (profilers, diagnostics).
     *
     * @return list<string>
     */
    public function all(): array;

    /**
     * Returns the buffered URLs (de-duplicated, insertion order) and empties the buffer.
     *
     * @return list<string>
     */
    public function drain(): array;

    /**
     * Empties the buffer without delivering (between requests in long-running runtimes).
     */
    public function reset(): void;
}
