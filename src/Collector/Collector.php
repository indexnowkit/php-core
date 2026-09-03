<?php

declare(strict_types=1);

namespace IndexNowKit\Collector;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * In-memory collector. Deduplicates, preserves insertion order, flushes once.
 *
 * A reset() of a non-empty buffer is logged as a warning: it means the unit of work ended without a flush
 * (no kernel.terminate, worker runtime that resets services, early exit), which is otherwise invisible.
 */
final class Collector implements CollectorInterface
{
    /** @var array<string, true> */
    private array $urls = [];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger()) {}

    public function add(iterable $urls): void
    {
        foreach ($urls as $url) {
            $this->urls[$url] = true;
        }
    }

    public function isEmpty(): bool
    {
        return $this->urls === [];
    }

    public function count(): int
    {
        return \count($this->urls);
    }

    public function drain(): array
    {
        $urls = array_keys($this->urls);
        $this->urls = [];

        return $urls;
    }

    public function reset(): void
    {
        if ($this->urls !== []) {
            $this->logger->warning('indexnow: {count} collected URL(s) discarded: the unit of work ended without flush() (no kernel.terminate?)', ['count' => \count($this->urls), 'urls' => \array_slice(array_keys($this->urls), 0, 20)]);
        }
        $this->urls = [];
    }
}
