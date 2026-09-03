<?php

declare(strict_types=1);

namespace IndexNowKit;

/**
 * Outcome of one POST (one engine, one host, up to batch.max_urls URLs).
 */
final readonly class Result
{
    /**
     * @param list<string> $urls
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
    ) {}

    public function urlCount(): int
    {
        return \count($this->urls);
    }

    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }
}
