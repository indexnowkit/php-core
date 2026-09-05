<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Check\CheckerInterface;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Exception\ConfigurationException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Body of `indexnow:check`: validates the configuration, runs the {@see CheckerInterface} (key files, engines, live
 * probe, adapter checks) and prints one line per finding. Adapter-specific wiring (is the ORM hook active? is the
 * queue routed?) is a `Check\CheckInterface` the adapter registers, not a special case here.
 */
final class CheckRunner
{
    public function __construct(private readonly CheckerInterface $checker, private readonly Vocabulary $words = new Vocabulary()) {}

    /**
     * @param callable(): mixed $validateConfig builds the Config from the raw adapter configuration; throws
     *                                          ConfigurationException when it is invalid
     * @param bool              $live           send a real probe request to every configured engine
     * @param string|null       $host           check only this host (multi-domain setups)
     * @param string|null       $probeUrl       page to send with $live (default https://<host>/)
     *
     * @return int exit code ({@see ExitCode})
     */
    public function run(SymfonyStyle $io, callable $validateConfig, bool $live = false, ?string $host = null, ?string $probeUrl = null): int
    {
        $io->title('IndexNow check');

        try {
            $validateConfig();
        } catch (ConfigurationException $e) {
            $io->writeln('  <fg=red>✘</> configuration: ' . $e->getMessage());
            $io->newLine();
            $io->error(\sprintf('IndexNow is disabled until the configuration is fixed (see %s).', $this->words->configLocation));

            return ExitCode::FAILURE;
        }

        $report = $this->checker->run(liveProbe: $live, onlyHost: $host !== null && $host !== '' ? $host : null, probeUrl: $probeUrl !== null && $probeUrl !== '' ? $probeUrl : null);
        self::printReport($io, $report);
        $io->newLine();
        if ($report->hasErrors()) {
            $io->error('IndexNow is not ready. Fix the errors above.');

            return ExitCode::FAILURE;
        }
        $io->success('IndexNow is ready.');
        $io->text(\sprintf('Next: annotate a class with #[IndexNow(...)], or send one URL now: %s %s https://…', $this->words->cli, $this->words->submit));

        return ExitCode::SUCCESS;
    }

    public static function printReport(SymfonyStyle $io, CheckReport $report): void
    {
        foreach ($report->items() as $item) {
            $io->writeln(match ($item->level) {
                CheckLevel::Ok => '  <fg=green>✔</> ' . $item->message,
                CheckLevel::Warning => '  <fg=yellow>!</> ' . $item->message,
                CheckLevel::Error => '  <fg=red>✘</> ' . $item->message,
            });
        }
    }
}
