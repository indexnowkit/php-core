<?php

declare(strict_types=1);

namespace IndexNowKit;

/**
 * Machine-readable reason of a Result that is not a success. Stable identifiers for metrics, dashboards
 * and alerts; {@see Result::$error} carries the human sentence.
 */
enum Reason: string
{
    // Skipped: nothing was sent.
    /** Config `enabled: false`. */
    case Disabled = 'disabled';
    /** Config `dry_run: true` (or the non-production safety net). */
    case DryRun = 'dry_run';
    /** The same URL was submitted less than `debounce.per_url` seconds ago. */
    case Debounced = 'debounced';
    /** No key configured for the URL's host (`hosts` map / `key`). */
    case NoKey = 'no_key';
    /** The URL failed normalization (unsupported scheme, credentials, relative without base_url, ...). */
    case InvalidUrl = 'invalid_url';

    // Failed: the engine answered or the request could not be delivered.
    /** HTTP 400. */
    case InvalidRequest = 'invalid_request';
    /** HTTP 403: key file not found or does not match. */
    case InvalidKey = 'invalid_key';
    /** HTTP 422: URLs do not belong to the host or keyLocation is invalid. */
    case Unprocessable = 'unprocessable';
    /** HTTP 429. Retryable. */
    case RateLimited = 'rate_limited';
    /** HTTP 5xx. Retryable. */
    case ServerError = 'server_error';
    /** Network failure or timeout. Retryable. */
    case Transport = 'transport';
    /** Anything else: unexpected status, JSON encoding failure, a throwing HTTP client. */
    case Unexpected = 'unexpected';

    public function isSkip(): bool
    {
        return match ($this) {
            self::Disabled, self::DryRun, self::Debounced, self::NoKey, self::InvalidUrl => true,
            default => false,
        };
    }

    /**
     * Short human sentence for logs and CLI output.
     */
    public function message(): string
    {
        return match ($this) {
            self::Disabled => 'IndexNow is disabled (enabled: false).',
            self::DryRun => 'dry_run is on: request logged, not sent.',
            self::Debounced => 'Submitted recently (debounce.per_url).',
            self::NoKey => 'No IndexNow key configured for this host.',
            self::InvalidUrl => 'URL cannot be submitted.',
            self::InvalidRequest => 'Invalid request format (400).',
            self::InvalidKey => 'Invalid key (403): key file not found or does not match.',
            self::Unprocessable => 'Unprocessable URLs (422).',
            self::RateLimited => 'Rate limited (429).',
            self::ServerError => 'Engine server error (5xx).',
            self::Transport => 'Network failure or timeout.',
            self::Unexpected => 'Unexpected failure.',
        };
    }
}
