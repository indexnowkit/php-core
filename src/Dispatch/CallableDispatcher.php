<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

use IndexNowKit\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Adapter for any queue: the callable receives the URL list and enqueues it.
 */
final class CallableDispatcher implements DispatcherInterface
{
    /** @var callable(list<string>): void */
    private $callable;

    /**
     * @param callable(list<string>): void $callable
     */
    /**
     * @param int $logUrls URLs listed in the failure log line ({@see Config::$logUrls})
     */
    public function __construct(callable $callable, private readonly LoggerInterface $logger = new NullLogger(), private readonly int $logUrls = Config::DEFAULT_LOG_URLS)
    {
        $this->callable = $callable;
    }

    public function dispatch(array $urls): void
    {
        try {
            ($this->callable)($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: dispatch of {count} URL(s) failed, they are lost: {error}', ['count' => \count($urls), 'error' => $e->getMessage(), 'exception' => $e, 'urls' => \array_slice($urls, 0, $this->logUrls)]);
        }
    }
}
