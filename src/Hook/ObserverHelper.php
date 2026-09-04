<?php

declare(strict_types=1);

namespace IndexNowKit\Hook;

use IndexNowKit\IndexNowKit;
use IndexNowKit\Url\ObjectChangeHandler;
use IndexNowKit\Url\ResolvedUrl;
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

    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->deletions = new WeakMap();
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
            $resolved = $resolve($this->indexNow->changes());
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
            $this->indexNow->collect($urls);
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
