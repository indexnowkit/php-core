<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use IndexNowKit\Http\Exception\TransportException;

interface TransportInterface
{
    /**
     * POST a JSON document. Must throw TransportException on network failure/timeout, never on HTTP status.
     *
     * @param array<string, string> $headers
     *
     * @throws TransportException
     */
    public function post(string $url, string $json, array $headers = []): Response;

    /**
     * @throws TransportException
     */
    public function get(string $url): Response;
}
