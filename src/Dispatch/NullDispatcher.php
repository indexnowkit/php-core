<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

/**
 * Drops everything (dispatch: none). Useful to keep collection hooks active while delivery is off.
 */
final class NullDispatcher implements DispatcherInterface
{
    public function dispatch(array $urls): void {}
}
