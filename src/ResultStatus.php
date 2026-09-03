<?php

declare(strict_types=1);

namespace IndexNowKit;

enum ResultStatus: string
{
    /** HTTP 200: accepted. */
    case Ok = 'ok';
    /** HTTP 202: accepted, key verification pending. Treated as success. */
    case Pending = 'pending';
    /** Rejected (4xx/5xx) or not delivered (network). See Result::$retryable. */
    case Failed = 'failed';
    /** Nothing was sent: dry_run, or the host has no key. See Result::$error. */
    case Skipped = 'skipped';

    public function isSuccess(): bool
    {
        return $this === self::Ok || $this === self::Pending;
    }
}
