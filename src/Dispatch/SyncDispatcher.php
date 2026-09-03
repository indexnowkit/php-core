<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

use IndexNowKit\SubmitterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Sends inline. Any exception is logged, never rethrown into the caller.
 */
final class SyncDispatcher implements DispatcherInterface
{
    public function __construct(private readonly SubmitterInterface $submitter, private readonly LoggerInterface $logger = new NullLogger()) {}

    public function dispatch(array $urls): void
    {
        try {
            $this->submitter->submit($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: sync dispatch failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
