<?php

declare(strict_types=1);

namespace IndexNowKit\Testing;

use IndexNowKit\Dispatch\DispatcherInterface;

/**
 * Test double: records every batch handed to the dispatcher instead of delivering it.
 */
final class RecordingDispatcher implements DispatcherInterface
{
    /** @var list<list<string>> */
    public array $batches = [];

    public function dispatch(array $urls): void
    {
        $this->batches[] = $urls;
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_values(array_unique(array_merge(...$this->batches, ...[[]])));
    }
}
