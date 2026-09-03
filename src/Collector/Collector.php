<?php

declare(strict_types=1);

namespace IndexNowKit\Collector;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WeakReference;

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

    private bool $drained = false;

    /**
     * @param bool $detectLeaks log a warning at process shutdown when URLs were collected but never drained
     *                          (a PHP-FPM request that died before the terminate hook): the only trace such a loss leaves
     */
    public function __construct(private readonly LoggerInterface $logger = new NullLogger(), bool $detectLeaks = true)
    {
        if ($detectLeaks) {
            $weak = WeakReference::create($this);
            register_shutdown_function(static function () use ($weak): void {
                $weak->get()?->reportLeak();
            });
        }
    }

    /**
     * @internal shutdown hook
     */
    public function reportLeak(): void
    {
        if ($this->urls !== [] && !$this->drained) {
            $this->logger->warning('indexnow: {count} collected URL(s) never flushed before the process ended (fatal error or early exit before the request-end hook?)', ['count' => \count($this->urls), 'urls' => \array_slice(array_keys($this->urls), 0, 20)]);
        }
    }

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

    public function all(): array
    {
        return array_keys($this->urls);
    }

    public function count(): int
    {
        return \count($this->urls);
    }

    public function drain(): array
    {
        $urls = array_keys($this->urls);
        $this->urls = [];
        $this->drained = true;

        return $urls;
    }

    public function reset(): void
    {
        if ($this->urls !== []) {
            $this->logger->warning('indexnow: {count} collected URL(s) discarded: the unit of work ended without flush() (request end hook not run?)', ['count' => \count($this->urls), 'urls' => \array_slice(array_keys($this->urls), 0, 20)]);
        }
        $this->urls = [];
    }
}
