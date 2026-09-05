<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Adapter\SubmitterFactoryInterface;
use IndexNowKit\IndexNowKit;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:submit <urls...>`: submits the URLs synchronously (bypassing the queue) and renders the results.
 */
final class SubmitRunner
{
    public function __construct(
        private readonly IndexNowKit $indexNow,
        private readonly SubmitterFactoryInterface $submitters,
        private readonly ResultFormatterInterface $formatter = new ResultRenderer(),
    ) {}

    /**
     * @param list<string> $urls absolute URLs or paths relative to base_url
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, array $urls, bool $force, bool $dryRun, bool $json): int
    {
        $submitter = SubmitterFactory::choose($this->submitters, $this->indexNow, $force, $dryRun);

        return $this->formatter->results($io, $submitter->submit($urls), $json);
    }
}
