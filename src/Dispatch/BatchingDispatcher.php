<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

use Closure;
use IndexNowKit\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The shape of every queue dispatcher of the family: one unit of work per `batch.max_urls` URLs (a bulk import of
 * 500 000 rows is many jobs the queue accepts, not one payload it rejects), a correlation id per unit that the
 * dispatch log line and the worker's log line share, and a push that may throw — logged as lost, never rethrown into
 * the request. What varies between Messenger, the Laravel bus and yii2-queue is the push itself, so an adapter's
 * dispatcher is a thin factory of {@see $enqueue} around this class.
 */
final class BatchingDispatcher implements DispatcherInterface
{
    /**
     * @param Closure(list<string>, string): void $enqueue the framework's push of one batch under its id; throws on failure
     * @param string                              $noun    what the log calls one unit: `job`, `message`
     */
    public function __construct(
        private readonly Closure $enqueue,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $noun = 'job',
    ) {}

    /** A fresh correlation id (12 hex characters): the dispatcher's, and the worker's when it re-queues a partial batch. */
    public static function newJobId(): string
    {
        return bin2hex(random_bytes(6));
    }

    public function dispatch(array $urls): void
    {
        foreach (array_chunk($urls, max(1, $this->config->batchMaxUrls)) as $chunk) {
            $id = self::newJobId();
            try {
                ($this->enqueue)($chunk, $id);
                $this->logger->debug('indexnow: {count} URL(s) queued as {noun} {id}', ['count' => \count($chunk), 'noun' => $this->noun, 'id' => $id, 'urls' => $this->config->logSample($chunk)]);
            } catch (Throwable $e) {
                $this->logger->error('indexnow: cannot queue {count} URL(s) ({noun} {id}), they are lost: {error}', ['count' => \count($chunk), 'noun' => $this->noun, 'id' => $id, 'error' => $e->getMessage(), 'exception' => $e, 'urls' => $this->config->logSample($chunk)]);
            }
        }
    }
}
