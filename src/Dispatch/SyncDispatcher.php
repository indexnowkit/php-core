<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

use IndexNowKit\Submitter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class SyncDispatcher implements DispatcherInterface
{
    public function __construct(private readonly Submitter $submitter, private readonly LoggerInterface $logger = new NullLogger()) {}

    public function dispatch(array $urls): void
    {
        try {
            $this->submitter->submit($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: sync dispatch failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
