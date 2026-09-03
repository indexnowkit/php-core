<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Exception\InvalidArgumentException;

/**
 * The HTTP-facing half of the pipeline: normalized URLs in, one {@see Result} per engine × host × batch out.
 * {@see Client} is the shipped implementation; decorate it for per-host policy (engines, throttling, metrics).
 */
interface ClientInterface
{
    /**
     * @param list<string> $normalizedUrls already normalized absolute URLs
     *
     * @return list<Result>
     */
    public function submitAll(array $normalizedUrls): array;

    /**
     * One POST of a batch that belongs to $host, under $key, to $endpoint.
     *
     * @param list<string> $urls count <= batch.max_urls
     *
     * @throws InvalidArgumentException on an empty list
     */
    public function submitBatch(string $endpoint, string $host, string $key, array $urls): Result;
}
