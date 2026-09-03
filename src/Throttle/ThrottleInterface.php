<?php

declare(strict_types=1);

namespace IndexNowKit\Throttle;

/**
 * Rate limiter consulted once per outgoing HTTP request.
 */
interface ThrottleInterface
{
    /**
     * Blocks (or returns immediately) until one request may be sent.
     */
    public function acquire(): void;
}
