<?php

declare(strict_types=1);

namespace IndexNowKit\Throttle;

/**
 * Rate limiter consulted once per outgoing HTTP request.
 */
interface ThrottleInterface
{
    /**
     * Blocks (or returns immediately) until one request may be sent. Implementations SHOULD NOT throw;
     * Client logs an exception as an error and sends without rate limiting.
     */
    public function acquire(): void;
}
