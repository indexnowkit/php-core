<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

interface KeyProviderInterface
{
    /** Key for the given host, or null if the host is not managed (its URLs are skipped). */
    public function keyFor(string $host): ?string;

    public function keyLocationFor(string $host): ?string;

    /** All keys this provider knows about (used to answer GET /{key}.txt). */
    public function isKnownKey(string $key): bool;
}
