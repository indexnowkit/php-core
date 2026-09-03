<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

/**
 * One line of a {@see CheckReport}.
 */
final readonly class CheckItem
{
    public function __construct(public CheckLevel $level, public string $message) {}
}
