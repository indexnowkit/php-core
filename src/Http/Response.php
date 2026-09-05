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

    /** IMF-fixdate of RFC 9110 (the former DateTimeInterface::RFC7231, deprecated in PHP 8.5). */
    public const HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /** @var array<string, string> header name (lower-case) => value (several values joined with ", "), when the transport exposes them */
    public array $headers;

    /**
     * @param int|null              $retryAfter seconds, already clamped to [0, MAX_RETRY_AFTER]; null when the header is absent or unparseable
     * @param array<string, string> $headers    response headers, name => value; names are lower-cased here. Empty when the
     *                                          transport does not expose headers (a custom `TransportInterface`), which
     *                                          `check` tells apart from "the header is absent" only by this array being empty
     */
    public function __construct(public int $status, public string $body = '', public ?int $retryAfter = null, array $headers = [])
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }
        $this->headers = $lower;
    }

    /** One header by name (case-insensitive), null when absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The media type of the body (`text/plain` for `Content-Type: text/plain; charset=utf-8`), lower-cased; null when
     * the header is absent.
     */
    public function contentType(): ?string
    {
        $header = $this->header('content-type');
        if ($header === null) {
            return null;
        }
        $type = strtolower(trim(explode(';', $header, 2)[0]));

        return $type === '' ? null : $type;
    }

    /**
     * How long a shared cache may keep this response, from `Cache-Control` (`s-maxage` wins over `max-age`; RFC 9111);
     * null when the header is absent or names no lifetime (`no-store` and `no-cache` are 0).
     */
    public function cacheMaxAge(): ?int
    {
        $header = $this->header('cache-control');
        if ($header === null) {
            return null;
        }
        $directives = [];
        foreach (explode(',', strtolower($header)) as $directive) {
            [$name, $value] = array_pad(explode('=', trim($directive), 2), 2, null);
            $directives[trim((string) $name)] = $value === null ? null : trim($value, " \t\"");
        }
        if (\array_key_exists('no-store', $directives) || \array_key_exists('no-cache', $directives)) {
            return 0;
        }
        foreach (['s-maxage', 'max-age'] as $name) {
            $value = $directives[$name] ?? null;
            if ($value !== null && preg_match('/^\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        return null;
    }

    /** The `Age` header (seconds this response spent in a cache), null when absent or malformed. */
    public function age(): ?int
    {
        $age = $this->header('age');

        return $age !== null && preg_match('/^\d+$/', trim($age)) === 1 ? (int) trim($age) : null;
    }

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
        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $header);
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
