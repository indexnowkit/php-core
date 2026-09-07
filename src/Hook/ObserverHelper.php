<?php

declare(strict_types=1);

namespace IndexNowKit\Hook;

use Closure;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolvedUrl;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use WeakMap;

/**
 * What every ORM observer does around the change handler, once: resolve without ever throwing into the
 * application, log what was resolved, hand URLs to the collector, and keep the URLs of a row that is about to
 * disappear until the deletion went through.
 *
 * What stays in the adapter: the change set (`getRawOriginal()` in Laravel, `changedAttributes` in Yii2), the
 * previous state for renamed pages, and the commit boundary (`Connection::afterCommit()`, a staging with the
 * connection's commit events). See docs/adapters.md §2.
 */
final class ObserverHelper
{
    /** @var WeakMap<object, list<string>> */
    private WeakMap $deletions;

    /** @var Closure(): ObjectChangeHandler */
    private readonly Closure $changes;
    /** @var Closure(list<string>): void */
    private readonly Closure $sink;

    /**
     * Over the facade (the change handler and `collect()` of $source), or over a change handler alone with $sink receiving
     * the URLs: an adapter that builds its graph lazily (`Adapter\Services`) resolves URLs in the hook without building the
     * client, and hands them to `fn(array $urls) => $services->kit()->collect($urls)`.
     *
     * @param (Closure(list<string>): void)|null $sink required with an ObjectChangeHandler, unused with the facade
     */
    public function __construct(
        IndexNowKit|ObjectChangeHandler $source,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?Closure $sink = null,
    ) {
        $this->deletions = new WeakMap();
        if ($source instanceof IndexNowKit) {
            $this->changes = static fn(): ObjectChangeHandler => $source->changes();
            $this->sink = $sink ?? static function (array $urls) use ($source): void {
                $source->collect($urls);
            };

            return;
        }
        $this->changes = static fn(): ObjectChangeHandler => $source;
        $this->sink = $sink ?? throw new LogicException('ObserverHelper over an ObjectChangeHandler needs the $sink the URLs go to.');
    }

    /** Over the facade: the change handler and `collect()` of $kit. Builds the whole graph on the first hook (see {@see forChanges()}). */
    public static function forKit(IndexNowKit $kit, LoggerInterface $logger = new NullLogger()): self
    {
        return new self($kit, $logger);
    }

    /**
     * @param Closure(list<string>): void $sink
     */
    public static function forChanges(ObjectChangeHandler $changes, Closure $sink, LoggerInterface $logger = new NullLogger()): self
    {
        return new self($changes, $logger, $sink);
    }

    /**
     * The URLs a hook should hand over, or null when resolving failed (logged at error, the application goes on).
     * An empty list means no rule matched or nothing relevant changed. Every URL is logged at debug with the rule
     * that produced it.
     *
     * @param callable(ObjectChangeHandler): list<ResolvedUrl> $resolve
     *
     * @return list<string>|null de-duplicated
     */
    public function guard(object $subject, callable $resolve): ?array
    {
        try {
            $resolved = $resolve(($this->changes)());
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve the URLs of {class}: {error}', ['class' => $subject::class, 'error' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
        $this->logResolved($resolved);

        return ResolvedUrl::urls($resolved);
    }

    /**
     * One debug line per resolved URL: `indexnow: {source} ({event}) -> {url}`.
     *
     * @param list<ResolvedUrl> $resolved
     */
    public function logResolved(array $resolved): void
    {
        foreach ($resolved as $item) {
            $this->logger->debug('indexnow: {source} ({event}) -> {url}', ['source' => $item->source(), 'event' => $item->event->value, 'url' => $item->url]);
        }
    }

    /**
     * Hands URLs to the collector; a failure is one error line, never an exception in the hook.
     *
     * @param list<string> $urls
     */
    public function deliver(array $urls): void
    {
        if ($urls === []) {
            return;
        }
        try {
            ($this->sink)($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot collect {count} URL(s): {error}', ['count' => \count($urls), 'error' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * A deletion resolves before the row disappears and delivers after: keep the URLs in between, by object.
     *
     * @param list<string> $urls
     */
    public function rememberDeletion(object $subject, array $urls): void
    {
        $this->deletions[$subject] = $urls;
    }

    /**
     * The URLs remembered for the object, once; null when the "before" hook was not seen.
     *
     * @return list<string>|null
     */
    public function takeDeletion(object $subject): ?array
    {
        $urls = $this->deletions[$subject] ?? null;
        unset($this->deletions[$subject]);

        return $urls;
    }
}
