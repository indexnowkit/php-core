<?php

declare(strict_types=1);

namespace IndexNowKit\Collector;

use Countable;

/**
 * Per-unit-of-work buffer (HTTP request, console command, queue message) of normalized URLs, flushed once
 * at the end of the unit of work. Replace it for a durable outbox or a per-tenant buffer.
 */
interface CollectorInterface extends Countable
{
    /**
     * @param iterable<string> $urls already normalized (see SubmitterInterface::prepare())
     */
    public function add(iterable $urls): void;

    public function isEmpty(): bool;

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
