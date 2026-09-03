<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

/**
 * Maps hosts to IndexNow keys. Implement it to load keys from a database or a multi-tenant registry.
 *
 * Every method is called on the submission path; implementations should be cheap (cache per request) and
 * must not throw for unknown hosts (return null).
 */
interface KeyProviderInterface
{
    /**
     * Key for the given (lower-cased) host, or null if the host is not managed: its URLs are skipped
     * with a warning and never sent under another host's key.
     */
    public function keyFor(string $host): ?string;

    /**
     * Absolute URL of the key file when it is not served at https://{host}/{key}.txt.
     */
    public function keyLocationFor(string $host): ?string;

    /**
     * Whether GET /{key}.txt should be answered with this key.
     *
     * @param string|null $host host the request arrived at; providers managing several hosts should only confirm
     *                          keys belonging to that host so tenant A's key file is not served on tenant B's host.
     *                          Null = any managed host (single-site adapters, CLI diagnostics).
     */
    public function isKnownKey(string $key, ?string $host = null): bool;

    /**
     * Hosts this provider has keys for, when enumerable (diagnostics). Empty when unknown.
     *
     * @return list<string>
     */
    public function managedHosts(): array;
}
