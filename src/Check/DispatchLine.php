<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use IndexNowKit\Dispatch\DispatcherFactory;

/**
 * The one sentence of `check` about a dispatch mode the core itself delivers (`sync`, `none`): what happens to a
 * collected URL. The adapters' queue checks print it for the modes that are not their queue, so the three of them
 * used to carry three spellings of it.
 */
final class DispatchLine
{
    private function __construct() {}

    /**
     * `dispatch "sync": URLs are sent synchronously when the unit of work ends; 429/5xx are not retried`, or the
     * `none` line; any other mode (a queue the adapter did not resolve) gets the `sync` sentence, which is what the
     * core's `Dispatch\DispatcherFactory` falls back to.
     */
    public static function describe(string $dispatch): string
    {
        return \sprintf('dispatch "%s": URLs are %s', $dispatch, $dispatch === DispatcherFactory::NONE
            ? 'collected but never sent (drain the collector yourself)'
            : 'sent synchronously when the unit of work ends; 429/5xx are not retried');
    }
}
