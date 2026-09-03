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
 * Savepoints are frames on top of the transaction: {@see savepoint()} opens one, {@see release()} folds it into
 * the frame below, {@see rollbackTo()} drops what was staged since it was created. A data layer that nests
 * transactions with savepoints (Doctrine DBAL) reports them so a rolled-back inner transaction never leaks its
 * URLs into the outer COMMIT (conformance A05).
 *
 * Framework-agnostic: every adapter that promises commit-safety (conformance A02/A05) builds on it.
 */
final class TransactionStaging
{
    /** @var WeakMap<object, list<StagingFrame>> */
    private WeakMap $frames;

    /** @var (callable(list<string>): void)|null */
    private $sink;

    /**
     * @param (callable(list<string>): void)|null $sink    receives URLs once the real COMMIT happened
     * @param int                                 $logUrls URLs listed in the rollback log line ({@see Config::$logUrls})
     */
    public function __construct(?callable $sink = null, private readonly LoggerInterface $logger = new NullLogger(), private readonly int $logUrls = Config::DEFAULT_LOG_URLS)
    {
        $this->sink = $sink;
        $this->frames = new WeakMap();
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
        $frames = $this->frames[$scope] ?? [new StagingFrame(null)];
        $top = $frames[\count($frames) - 1];
        foreach ($urls as $url) {
            $top->urls[$url] = true;
        }
        $this->frames[$scope] = $frames;
    }

    /**
     * A savepoint was created: URLs staged from now on belong to it until it is released or rolled back.
     */
    public function savepoint(object $scope, string $name): void
    {
        $frames = $this->frames[$scope] ?? [new StagingFrame(null)];
        $frames[] = new StagingFrame($name);
        $this->frames[$scope] = $frames;
    }

    /**
     * A savepoint was released: what it staged now belongs to the enclosing transaction (or savepoint).
     * Unknown names are ignored.
     */
    public function release(object $scope, string $name): void
    {
        $frames = $this->frames[$scope] ?? [];
        $index = self::indexOf($frames, $name);
        if ($index === null) {
            return;
        }
        $released = array_splice($frames, $index);
        $below = $frames[\count($frames) - 1];
        foreach ($released as $frame) {
            $below->urls += $frame->urls;
        }
        $this->frames[$scope] = $frames;
    }

    /**
     * ROLLBACK TO SAVEPOINT: everything staged since the savepoint was created is dropped; the savepoint
     * itself stays open, as it does in the database. Unknown names are ignored.
     */
    public function rollbackTo(object $scope, string $name): void
    {
        $frames = $this->frames[$scope] ?? [];
        $index = self::indexOf($frames, $name);
        if ($index === null) {
            return;
        }
        $dropped = [];
        foreach (array_splice($frames, $index) as $frame) {
            $dropped += $frame->urls;
        }
        $frames[] = new StagingFrame($name);
        $this->frames[$scope] = $frames;
        $this->logDiscard(array_keys($dropped), 'savepoint rolled back');
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
        $this->logDiscard($this->take($scope), 'transaction rolled back');
    }

    public function hasPending(object $scope): bool
    {
        return $this->pendingCount($scope) > 0;
    }

    public function pendingCount(object $scope): int
    {
        return \count($this->pendingUrls($scope));
    }

    /**
     * @return list<string>
     */
    private function take(object $scope): array
    {
        $urls = array_keys($this->pendingUrls($scope));
        unset($this->frames[$scope]);

        return $urls;
    }

    /**
     * @return array<string, true>
     */
    private function pendingUrls(object $scope): array
    {
        $urls = [];
        foreach ($this->frames[$scope] ?? [] as $frame) {
            $urls += $frame->urls;
        }

        return $urls;
    }

    /**
     * @param list<string> $urls
     */
    private function logDiscard(array $urls, string $why): void
    {
        if ($urls !== []) {
            $this->logger->debug('indexnow: discarding {count} staged URL(s), ' . $why, ['count' => \count($urls), 'urls' => \array_slice($urls, 0, $this->logUrls)]);
        }
    }

    /**
     * Topmost frame with that name: a re-created savepoint shadows the older one, as in the database.
     *
     * @param list<StagingFrame> $frames
     */
    private static function indexOf(array $frames, string $name): ?int
    {
        for ($i = \count($frames) - 1; $i >= 0; --$i) {
            if ($frames[$i]->name === $name) {
                return $i;
            }
        }

        return null;
    }
}
