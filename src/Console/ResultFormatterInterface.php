<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Result;
use IndexNowKit\Submission\ResultSummary;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console output of submission results. {@see ResultRenderer} prints a table or JSON; an application replaces it to
 * match the JSON envelope or the table style of its own commands.
 */
interface ResultFormatterInterface
{
    /**
     * @param list<Result> $results
     *
     * @return int exit code ({@see ExitCode})
     */
    public function results(SymfonyStyle $io, array $results, bool $json): int;

    /**
     * Aggregated results of a run that submits in batches (a large subject set, a streamed source).
     *
     * @return int exit code ({@see ExitCode})
     */
    public function summary(SymfonyStyle $io, ResultSummary $summary, bool $json): int;
}
