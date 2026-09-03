<?php

declare(strict_types=1);

namespace IndexNowKit;

/**
 * Outcome of one submission attempt: one endpoint, one host, up to batch.max_urls URLs.
 * Skipped results (dry-run, unmanaged host) carry no HTTP code; $error explains why.
 */
final readonly class Result
{
    /**
     * @param string       $engine     engine label ("api", "yandex", custom host) or "none" when nothing was sent
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
    ) {}

    public function urlCount(): int
    {
        return \count($this->urls);
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    /**
     * Flatten the URLs of results matching a predicate (e.g. retryable ones), de-duplicated.
     *
     * @param iterable<Result>              $results
     * @param (callable(Result): bool)|null $filter  default: retryable results
     *
     * @return list<string>
     */
    public static function urlsOf(iterable $results, ?callable $filter = null): array
    {
        $filter ??= static fn(Result $r): bool => $r->retryable;
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
}
