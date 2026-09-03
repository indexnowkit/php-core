<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

/**
 * Minimal HTTP response as seen by the protocol layer.
 */
final readonly class Response
{
    /**
     * @param int|null $retryAfter seconds, already clamped to [0, 86400]; null when the header is absent or unparseable
     */
    public function __construct(public int $status, public string $body = '', public ?int $retryAfter = null) {}
}
