<?php

declare(strict_types=1);

namespace IndexNowKit\Dispatch;

final class NullDispatcher implements DispatcherInterface
{
    public function dispatch(array $urls): void {}
}
