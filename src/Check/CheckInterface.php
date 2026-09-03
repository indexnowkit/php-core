<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

/**
 * An extra check an application or adapter adds to `check` (a CDN purge of the key file, a queue that must be
 * routed, a tenant table that must hold keys). Add lines to the report; never throw: a failing check is an error line.
 */
interface CheckInterface
{
    public function check(CheckReport $report): void;
}
