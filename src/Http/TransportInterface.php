<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use IndexNowKit\Http\Exception\TransportException;

/**
 * The only HTTP surface the core needs. Implementations must never throw on HTTP status codes.
 */
interface TransportInterface
{
    /**
     * POST a JSON document (IndexNow submission). The response body may be truncated by the
     * implementation; only its beginning is used for diagnostics.
     *
     * @param array<string, string> $headers
     *
     * @throws TransportException on network failure or timeout
     */
    public function post(string $url, string $json, array $headers = []): Response;

    /**
     * GET a document in full (a key file, any document a consumer reads). Implementations should cap the body size.
     *
     * @throws TransportException on network failure, timeout or when the body exceeds the cap
     */
    public function get(string $url): Response;
}
