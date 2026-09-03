<?php

declare(strict_types=1);

namespace IndexNowKit\Transaction;

use IndexNowKit\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WeakMap;

/**
 * URLs collected inside an open database transaction, held back until the real COMMIT and dropped on
 * ROLLBACK. Keyed by a scope object whose identity outlives the transaction: the native (PDO/mysqli/...)
 * connection for Doctrine, the connection or transaction object of any other data layer.
 *
 * Framework-agnostic: every adapter that promises commit-safety (conformance A02/A05) builds on it.
 */
final class TransactionStaging
{
    /** @var WeakMap<object, array<string, true>> */
    private WeakMap $pending;

    /** @var (callable(list<string>): void)|null */
    private $sink;

    /**
     * @param (callable(list<string>): void)|null $sink receives URLs once the real COMMIT happened
     */
    /**
     * @param int $logUrls URLs listed in the rollback log line ({@see Config::$logUrls})
     */
    public function __construct(?callable $sink = null, private readonly LoggerInterface $logger = new NullLogger(), private readonly int $logUrls = Config::DEFAULT_LOG_URLS)
    {
        $this->sink = $sink;
        $this->pending = new WeakMap();
    }

    /**
     * @param callable(list<string>): void $sink
     */
    public function setSink(callable $sink): void
    {
        $this->sink = $sink;
    }

    /**
     * @param list<string> $urls
     */
    public function stage(object $scope, array $urls): void
    {
        $current = $this->pending[$scope] ?? [];
        foreach ($urls as $url) {
            $current[$url] = true;
        }
        $this->pending[$scope] = $current;
    }

    /**
     * The outermost transaction committed: hand the staged URLs to the sink.
     */
    public function commit(object $scope): void
    {
        $urls = $this->take($scope);
        if ($urls !== [] && $this->sink !== null) {
            ($this->sink)($urls);
        }
    }

    /**
     * The transaction rolled back (or the commit failed): the staged URLs are dropped.
     */
    public function discard(object $scope): void
    {
        $urls = $this->take($scope);
        if ($urls !== []) {
            $this->logger->debug('indexnow: discarding {count} staged URL(s), transaction rolled back', ['count' => \count($urls), 'urls' => \array_slice($urls, 0, $this->logUrls)]);
        }
    }

    public function hasPending(object $scope): bool
    {
        return isset($this->pending[$scope]) && $this->pending[$scope] !== [];
    }

    public function pendingCount(object $scope): int
    {
        return \count($this->pending[$scope] ?? []);
    }

    /**
     * @return list<string>
     */
    private function take(object $scope): array
    {
        $urls = array_keys($this->pending[$scope] ?? []);
        unset($this->pending[$scope]);

        return $urls;
    }
}
