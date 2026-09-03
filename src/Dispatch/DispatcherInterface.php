<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

/**
 * Hands a batch of URLs to the delivery mechanism (inline, queue, ...). Must never throw into user code.
 */
interface DispatcherInterface
{
    /**
     * @param list<string> $urls
     */
    public function dispatch(array $urls): void;
}
