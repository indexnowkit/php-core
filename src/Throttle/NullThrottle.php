<?php

declare(strict_types=1);

namespace IndexNowKit\Throttle;

final class NullThrottle implements ThrottleInterface
{
    public function acquire(): void {}
}
