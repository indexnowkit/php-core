<?php

declare(strict_types=1);

namespace IndexNowKit;

/**
 * Outcome of one submission attempt: one endpoint, one host, up to batch.max_urls URLs.
 *
 * Successful results (200 / 202) have `reason === null`. Skipped results (dry-run, disabled, debounced,
 * unmanaged host, invalid URL) carry no HTTP code and `engine === 'none'`; failed ones carry the HTTP code
 * when the engine answered. `$reason` is the stable identifier, `$error` the human sentence.
 */
final readonly class Result
{
    /** Engine label of results that never reached an engine (skipped). */
    public const NO_ENGINE = 'none';

    /**
     * @param string       $engine     engine label ("api", "yandex", custom host) or {@see NO_ENGINE}
     * @param list<string> $urls       URLs of this batch
     * @param int|null     $retryAfter seconds suggested by the engine (429/5xx), when given
     */
    public function __construct(
        public string $engine,
        public string $host,
        public array $urls,
        public ResultStatus $status,
        public ?int $httpCode = null,
        public ?string $error = null,
        public bool $retryable = false,
        public ?int $retryAfter = null,
        public string $endpoint = '',
        public ?Reason $reason = null,
    ) {}

    /**
     * @param list<string> $urls
     */
    public static function ok(string $engine, string $host, array $urls, int $httpCode, string $endpoint): self
    {
        return new self($engine, $host, $urls, $httpCode === 202 ? ResultStatus::Pending : ResultStatus::Ok, $httpCode, endpoint: $endpoint);
    }

    /**
     * Nothing was sent. `$error` defaults to the reason's standard sentence.
     *
     * @param list<string> $urls
     */
    public static function skipped(string $host, array $urls, Reason $reason, ?string $error = null, string $engine = self::NO_ENGINE, string $endpoint = ''): self
    {
        return new self($engine, $host, $urls, ResultStatus::Skipped, null, $error ?? $reason->message(), false, null, $endpoint, $reason);
    }

    /**
     * Rejected by the engine or not delivered.
     *
     * @param list<string> $urls
     */
    public static function failed(string $engine, string $host, array $urls, Reason $reason, ?string $error = null, ?int $httpCode = null, bool $retryable = false, ?int $retryAfter = null, string $endpoint = ''): self
    {
        return new self($engine, $host, $urls, ResultStatus::Failed, $httpCode, $error ?? $reason->message(), $retryable, $retryAfter, $endpoint, $reason);
    }

    public function urlCount(): int
    {
        return \count($this->urls);
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    /**
     * Low-cardinality string labels for counters (Prometheus, StatsD): status, engine, reason, http_code, retryable.
     * The host is deliberately absent (unbounded in multi-tenant setups); add `$result->host` yourself if needed.
     *
     * @return array<string, string>
     */
    public function metricLabels(): array
    {
        return [
            'status' => $this->status->value,
            'engine' => $this->engine,
            'reason' => $this->reason !== null ? $this->reason->value : '',
            'http_code' => $this->httpCode !== null ? (string) $this->httpCode : '',
            'retryable' => $this->retryable ? 'true' : 'false',
        ];
    }

    /**
     * URLs of the results that may be retried later (429, 5xx, network), de-duplicated.
     *
     * @param iterable<Result> $results
     *
     * @return list<string>
     */
    public static function retryableUrls(iterable $results): array
    {
        return self::urlsWhere($results, static fn(Result $r): bool => $r->retryable);
    }

    /**
     * Every URL of every result, de-duplicated.
     *
     * @param iterable<Result> $results
     *
     * @return list<string>
     */
    public static function allUrls(iterable $results): array
    {
        return self::urlsWhere($results, static fn(Result $r): bool => true);
    }

    /**
     * URLs of the results matching a predicate, de-duplicated.
     *
     * @param iterable<Result>       $results
     * @param callable(Result): bool $filter
     *
     * @return list<string>
     */
    public static function urlsWhere(iterable $results, callable $filter): array
    {
        $urls = [];
        foreach ($results as $result) {
            if ($filter($result)) {
                foreach ($result->urls as $url) {
                    $urls[$url] = true;
                }
            }
        }

        return array_keys($urls);
    }

    /**
     * @deprecated since 0.2.0, use {@see retryableUrls()} or {@see urlsWhere()}; the default filter (retryable only) contradicts the name
     *
     * @param iterable<Result>              $results
     * @param (callable(Result): bool)|null $filter
     *
     * @return list<string>
     */
    public static function urlsOf(iterable $results, ?callable $filter = null): array
    {
        return self::urlsWhere($results, $filter ?? static fn(Result $r): bool => $r->retryable);
    }
}
