<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use IndexNowKit\Http\Exception\TransportException;

/**
 * Optional extension of {@see TransportInterface}: GET a document without holding its body in memory.
 *
 * Consumers that stream large documents use it when available (a 50 MB document then costs a temp file and a few
 * KiB of buffers instead of 100+ MB of strings) and fall back to {@see TransportInterface::get()} otherwise.
 */
interface StreamingTransportInterface extends TransportInterface
{
    /**
     * GET a document and write its body to $sink chunk by chunk.
     *
     * @param resource $sink writable stream; the implementation neither rewinds nor closes it
     *
     * @return Response with an empty body: only status and Retry-After are reported
     *
     * @throws TransportException on network failure, timeout or when the body exceeds the implementation's cap
     */
    public function download(string $url, $sink): Response;
}
