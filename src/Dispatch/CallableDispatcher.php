<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

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
    public function __construct(callable $callable, private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->callable = $callable;
    }

    public function dispatch(array $urls): void
    {
        try {
            ($this->callable)($urls);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: dispatch failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
