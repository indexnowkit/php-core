<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Minimal HTTP response as seen by the protocol layer.
 */
final readonly class Response
{
    /** Upper bound applied to Retry-After values (one day). */
    public const MAX_RETRY_AFTER = 86400;

    /**
     * @param int|null $retryAfter seconds, already clamped to [0, MAX_RETRY_AFTER]; null when the header is absent or unparseable
     */
    public function __construct(public int $status, public string $body = '', public ?int $retryAfter = null) {}

    /**
     * Parse a Retry-After header value (RFC 9110: delta-seconds or HTTP-date) into clamped delay seconds.
     * Custom transports use it so every adapter interprets the header the same way.
     *
     * @param int|null $now Unix timestamp used for HTTP-date values (default: time())
     */
    public static function parseRetryAfter(?string $header, int $max = self::MAX_RETRY_AFTER, ?int $now = null): ?int
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $header) === 1) {
            return min((int) $header, $max);
        }
        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $header);
        if ($date === false) {
            $timestamp = strtotime($header);
            if ($timestamp === false) {
                return null;
            }
            $date = new DateTimeImmutable('@' . $timestamp);
        }

        return max(0, min($date->getTimestamp() - ($now ?? time()), $max));
    }
}
