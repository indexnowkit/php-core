<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\SubmitterInterface;

/**
 * Builds the submitter console commands use for `--force` (no debounce) and `--dry-run`. Decorate it to wrap what
 * those commands submit through; the application's own submitter is untouched.
 */
interface SubmitterFactoryInterface
{
    public function create(bool $force, bool $dryRun): SubmitterInterface;
}
