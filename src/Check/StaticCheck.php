<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

/**
 * One fixed line of `check`, decided when the adapter is wired rather than when the check runs: an optional package
 * that is not installed (`sitemap: not installed (composer require indexnowkit/sitemap)`), a feature switched off
 * by the configuration. Ok by default: the absence of an optional piece is a fact to print, not a problem.
 */
final class StaticCheck implements CheckInterface
{
    public function __construct(private readonly CheckLevel $level, private readonly string $line) {}

    public function check(CheckReport $report): void
    {
        match ($this->level) {
            CheckLevel::Ok => $report->ok($this->line),
            CheckLevel::Warning => $report->warning($this->line),
            CheckLevel::Error => $report->error($this->line),
        };
    }
}
