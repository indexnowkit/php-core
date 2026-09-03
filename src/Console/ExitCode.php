<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

/**
 * Exit codes of the shared command bodies; the same values as Symfony's `Command::SUCCESS/FAILURE/INVALID`, so a
 * framework command returns them as they are.
 */
final class ExitCode
{
    public const SUCCESS = 0;

    /** the run happened, an engine or the source failed */
    public const FAILURE = 1;

    /** bad arguments or options: nothing was attempted */
    public const INVALID = 2;

    private function __construct() {}
}
