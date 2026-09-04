<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Result;
use IndexNowKit\ResultStatus;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared output of the submit / submit-<subject> commands and of batched runs: a table, or JSON with --json.
 */
final class ResultRenderer implements ResultFormatterInterface
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const ALL_SKIPPED = 'Nothing was sent. The "reason" column says why (dry_run, disabled, debounced, no_key, invalid_url); use --force to bypass the debounce store.';

    public function results(SymfonyStyle $io, array $results, bool $json): int
    {
        $failed = false;
        foreach ($results as $r) {
            $failed = $failed || $r->status === ResultStatus::Failed;
        }
        if ($json) {
            $io->writeln((string) json_encode(array_map($this->row(...), $results), self::JSON_FLAGS));

            return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS;
        }
        if ($results === []) {
            $io->warning('Nothing submitted: no URL was given.');

            return ExitCode::SUCCESS;
        }
        $rows = [];
        foreach ($results as $r) {
            $rows[] = [$r->engine, $r->host, $r->urlCount(), $r->status->value, $r->httpCode ?? '-', $r->reason !== null ? $r->reason->value : '', $r->error ?? ''];
        }
        $io->table(['engine', 'host', 'urls', 'status', 'http', 'reason', 'detail'], $rows);
        $this->allSkippedNote($io, array_map(static fn(Result $r): string => $r->status->value, $results));

        return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    public function summary(SymfonyStyle $io, ResultSummary $summary, bool $json): int
    {
        $rows = $summary->rows();
        if ($json) {
            $io->writeln((string) json_encode($rows, self::JSON_FLAGS));

            return $summary->failed() ? ExitCode::FAILURE : ExitCode::SUCCESS;
        }
        if ($rows === []) {
            $io->warning('Nothing submitted: the source yielded no URL.');

            return ExitCode::SUCCESS;
        }
        $table = [];
        foreach ($rows as $row) {
            $table[] = [$row['engine'], $row['host'], $row['url_count'], $row['batches'], $row['status'], $row['http'] ?? '-', $row['reason'] ?? '', $row['error'] ?? ''];
        }
        $io->table(['engine', 'host', 'urls', 'batches', 'status', 'http', 'reason', 'detail'], $table);
        $this->allSkippedNote($io, array_column($rows, 'status'));

        return $summary->failed() ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    /**
     * @param list<string> $statuses
     */
    private function allSkippedNote(SymfonyStyle $io, array $statuses): void
    {
        $skipped = array_filter($statuses, static fn(string $status): bool => $status === ResultStatus::Skipped->value);
        if ($skipped !== [] && \count($skipped) === \count($statuses)) {
            $io->note(self::ALL_SKIPPED);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Result $r): array
    {
        return ['engine' => $r->engine, 'host' => $r->host, 'status' => $r->status->value, 'reason' => $r->reason?->value, 'http' => $r->httpCode, 'retryable' => $r->retryable, 'error' => $r->error, 'urls' => $r->urls];
    }
}
