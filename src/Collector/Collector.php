<?php

declare(strict_types=1);

namespace IndexNowKit\Collector;

use Countable;

/**
 * Per-unit-of-work buffer (HTTP request, console command, queue message) of normalized URLs.
 * Deduplicates, preserves insertion order, flushes once.
 */
final class Collector implements Countable
{
    /** @var array<string, true> */
    private array $urls = [];

    /**
     * @param iterable<string> $urls already normalized (see SubmitterInterface::prepare())
     */
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

    /**
     * Returns the buffered URLs and empties the buffer.
     *
     * @return list<string>
     */
    public function drain(): array
    {
        $urls = array_keys($this->urls);
        $this->urls = [];

        return $urls;
    }

    public function reset(): void
    {
        $this->urls = [];
    }
}
