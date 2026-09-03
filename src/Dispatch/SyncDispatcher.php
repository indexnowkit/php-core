<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

use IndexNowKit\Config;
use IndexNowKit\SubmitterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends inline. Any exception is logged, never rethrown into the caller.
 */
final class SyncDispatcher implements DispatcherInterface
{
    /**
     * @param int $logUrls URLs listed in the failure log line ({@see Config::$logUrls})
     */
    public function __construct(private readonly SubmitterInterface $submitter, private readonly LoggerInterface $logger = new NullLogger(), private readonly int $logUrls = Config::DEFAULT_LOG_URLS) {}

    public function dispatch(array $urls): void
    {
        try {
            $this->submitter->submit($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: sync dispatch of {count} URL(s) failed, they are lost: {error}', ['count' => \count($urls), 'error' => $e->getMessage(), 'exception' => $e, 'urls' => \array_slice($urls, 0, $this->logUrls)]);
        }
    }
}
