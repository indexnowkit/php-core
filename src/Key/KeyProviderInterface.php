<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

/**
 * Maps hosts to IndexNow keys. Implement it to load keys from a database or a multi-tenant registry.
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
     * Whether GET /{key}.txt should be answered with this key (any managed host).
     */
    public function isKnownKey(string $key): bool;

    /**
     * Hosts this provider has keys for, when enumerable (diagnostics). Empty when unknown.
     *
     * @return list<string>
     */
    public function managedHosts(): array;
}
