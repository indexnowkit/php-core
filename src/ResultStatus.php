<?php

declare(strict_types=1);

namespace IndexNowKit;

enum ResultStatus: string
{
    case Ok = 'ok';
    /** HTTP 202: accepted, key verification pending. Treated as success. */
    case Pending = 'pending';
    case Failed = 'failed';
    /** dry_run or disabled: nothing was sent. */
    case Skipped = 'skipped';

    public function isSuccess(): bool
    {
        return $this === self::Ok || $this === self::Pending;
    }
}
