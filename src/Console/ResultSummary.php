<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Result;
use IndexNowKit\ResultStatus;

/**
 * Aggregated results of a run that submits in batches (a large subject set, a streamed source): results are folded
 * into (engine, host, status, http, reason) rows with URL counts as they arrive, so a million-URL run keeps a handful
 * of rows in memory instead of every Result with its URL list.
 */
final class ResultSummary
{
    /** @var array<string, array{engine: string, host: string, status: string, http: ?int, reason: ?string, retryable: bool, error: ?string, url_count: int, batches: int}> */
    private array $rows = [];

    private bool $failed = false;

    private int $urls = 0;

    /**
     * @param list<Result> $results
     */
    public function add(array $results): void
    {
        foreach ($results as $r) {
            $this->failed = $this->failed || $r->status === ResultStatus::Failed;
            $this->urls += $r->urlCount();
            $key = implode('|', [$r->engine, $r->host, $r->status->value, (string) $r->httpCode, $r->reason !== null ? $r->reason->value : '']);
            $row = $this->rows[$key] ?? ['engine' => $r->engine, 'host' => $r->host, 'status' => $r->status->value, 'http' => $r->httpCode, 'reason' => $r->reason?->value, 'retryable' => $r->retryable, 'error' => $r->error, 'url_count' => 0, 'batches' => 0];
            $row['url_count'] += $r->urlCount();
            ++$row['batches'];
            $row['retryable'] = $row['retryable'] || $r->retryable;
            $row['error'] ??= $r->error;
            $this->rows[$key] = $row;
        }
    }

    public function urlCount(): int
    {
        return $this->urls;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return list<array{engine: string, host: string, status: string, http: ?int, reason: ?string, retryable: bool, error: ?string, url_count: int, batches: int}>
     */
    public function rows(): array
    {
        return array_values($this->rows);
    }

    public function failed(): bool
    {
        return $this->failed;
    }
}
